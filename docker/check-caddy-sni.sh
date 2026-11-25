#!/usr/bin/env bash
# 入口の Caddy が、TLS の名前（SNI）と Host が違う要求・場所の無い名前・圧縮をどう扱うかを、本物の Caddy で確かめる（ADR 0052）
#
#   docker/check-caddy-sni.sh
#
# 1. SNI と Host: Cloudflare Tunnel の形（EC_SUGAR_FRONT=tunnel）では、cloudflared が同じ行き先への接続を使い回し、ある店の名前で握手した接続に
#    別の店の要求を載せる。Caddy が SNI と Host の違いを断る（strict_sni_host on）と、本物のショップの要求が 421 になる（2026-11-09 に本番で起きた）。
#    そこで:
#      - ふつうの形（caddy）: SNI と Host が違えば 421（前のまま）
#      - Tunnel の形（tunnel）: SNI と Host が違っても 421 にせず、Host の場所へ渡す
#    を、curl で 1 本の TLS 接続（SNI は URL の名前）に別の Host を載せて確かめる。HTTP/1.1（cloudflared が行き先へ使う形）と HTTP/2 の両方。
# 2. 場所の無い名前: 証明書は覆っているが Caddy の場所に合わない名前（1 店舗の形の別の店のホスト・複数の店の形のショップのドメインそのもの・
#    Tunnel の形で ある名前の接続に載った知らない Host）は、空の 200 でなく 404。後ろへは渡さない
# 3. 圧縮と no-transform: 後ろの HTML は入口で圧縮せず（同じ本文に CSRF の合い言葉と攻める人の入力が並ぶため。BREACH）、
#    Cache-Control の元の値の後ろに no-transform が付く（Cloudflare にも書き換え・圧縮をさせない）。JSON と CSS は圧縮するが印は付けない
# どれも、管理画面とショップのホストが今までどおり 200 で届くこととあわせて、入口の形 2 つ（caddy・tunnel）× 店の数の形 2 つ（single・multi）で見る。
# 設定は docker/caddy/etc そのもの、証明書の取り方は manual（手で用意した証明書。取りに行かない）。auto・dns の形は場所の並びと (edge)・受け止めの場所が同じで、
# 違うのは証明書の取り方だけなので、ここでは起こさない（取りに行く・Cloudflare の DNS の部品が要るため）。
#
# Caddy のイメージは docker/caddy/Dockerfile の動かす段の土台（公式の同じバージョン・digest 固定）。確かめる働きは Caddy の核のもので、
# 足した部品（Cloudflare の DNS）とは関係しないので、作る段を通さずに済ませる（EC_CHECK_CADDY_IMAGE で替えられる）。
# 後ろの nginx の代わりに、同じイメージの Caddy を web:8080 に置く（HTML・JSON・CSS を、圧縮せず Cache-Control: no-cache, private で返す。アプリの既定と同じ値）。
# 証明書はここで作る使い捨ての自己署名（example.test）。受け口は 127.0.0.1 の空いた番号だけ。終われば コンテナ・網・仮の置き場を消す。
# 外へは出ない（イメージを取る時のほか）
set -euo pipefail

cd "$(dirname "$0")/.."
fails=0
ng() { echo "NG: $*"; fails=$((fails + 1)); }
ok() { echo "ok: $*"; }

image="${EC_CHECK_CADDY_IMAGE:-$(sed -n 's/^FROM \(caddy:[^ ]*-alpine@sha256:[0-9a-f]\{64\}\)$/\1/p' docker/caddy/Dockerfile)}"
[ -n "$image" ] || { echo "NG: docker/caddy/Dockerfile の動かす段の土台（caddy:<版>-alpine@sha256:…）が読めない"; exit 1; }

admin=admin.example.test
shop_domain=shops.example.test
shop_a=shop-a.$shop_domain
shop_b=shop-b.$shop_domain
tag="ec-sugar-sni-check-$$"
work="$(mktemp -d)"
cleanup() {
    docker rm -f "$tag-caddy" "$tag-web" > /dev/null 2>&1 || true
    docker network rm "$tag" > /dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT

# 管理画面・*.<ショップのドメイン>・ショップのドメインそのものを含む使い捨ての証明書（manual の取り方が読む形）。
# どの形でも、場所の無い名前（1 店舗の形の shop-b・複数の店の形のショップのドメインそのもの）まで覆う（起きる形）
mkdir -p "$work/tls" "$work/web"
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 1 -subj "/CN=ec-sugar-sni-check" \
    -addext "subjectAltName=DNS:$admin,DNS:*.$shop_domain,DNS:$shop_domain" \
    -keyout "$work/tls/key.pem" -out "$work/tls/cert.pem" 2> /dev/null
chmod 0644 "$work/tls/key.pem" "$work/tls/cert.pem"

# 後ろの代わり: /api/ は JSON、/build/ は CSS、ほかは HTML。どれも圧縮の最小の大きさ（512 バイト）を超える本文で、Cache-Control はアプリの既定の値
pad="$(printf 'backend %.0s' $(seq 1 100))"
cat > "$work/web/Caddyfile" << EOF
{
	admin off
}
:8080 {
	header Cache-Control "no-cache, private"
	handle /api/* {
		header Content-Type application/json
		respond \`{"body":"$pad"}\` 200
	}
	handle /build/* {
		header Content-Type "text/css; charset=utf-8"
		respond "p { content: '$pad'; }" 200
	}
	handle {
		header Content-Type "text/html; charset=UTF-8"
		respond "<p>$pad</p>" 200
	}
}
EOF
chmod 0644 "$work/web/Caddyfile"

docker network create "$tag" > /dev/null
docker run -d --rm --name "$tag-web" --network "$tag" --network-alias web -v "$work/web:/etc/caddy:ro" "$image" \
    caddy run --config /etc/caddy/Caddyfile --adapter caddyfile > /dev/null

# $1 入口の形（caddy か tunnel）$2 店の数の形（single か multi）。Caddy を起こし、受け口の番号を port に入れる
start_caddy() {
    local site
    if [ "$2" = multi ]; then site="*.$shop_domain"; else site="$shop_a"; fi
    docker rm -f "$tag-caddy" > /dev/null 2>&1 || true
    docker run -d --name "$tag-caddy" --network "$tag" -p 127.0.0.1::443 \
        -v "$PWD/docker/caddy/etc:/etc/caddy:ro" -v "$work/tls:/run/secrets/ec-sugar/tls:ro" \
        -e EC_SUGAR_FRONT="$1" -e EC_SUGAR_TUNNEL_ADDR=192.0.2.1 \
        -e EC_SUGAR_ADMIN_HOST="$admin" -e EC_SUGAR_SHOPS="$2" -e EC_SUGAR_SITE_SHOP="$site" -e EC_SUGAR_TLS_MODE=manual \
        "$image" caddy run --config /etc/caddy/Caddyfile --adapter caddyfile > /dev/null
    port="$(docker port "$tag-caddy" 443/tcp | sed -n 's/^127\.0\.0\.1:\([0-9]*\)$/\1/p' | head -n 1)"
    [ -n "$port" ] || { docker logs "$tag-caddy" 2>&1 | tail -n 20; echo "NG: Caddy の受け口の番号が分からない（形 ${1}・${2}）"; exit 1; }
}

# $1 HTTP の版（--http1.1 か --http2）$2 SNI（URL の名前）$3 Host。返った状態の番号を出す（握手できなければ 000）
status() {
    curl -sS -o /dev/null -w '%{http_code}' "$1" --max-time 15 --cacert "$work/tls/cert.pem" \
        --retry 20 --retry-delay 1 --retry-connrefused --retry-all-errors \
        --resolve "$2:$port:127.0.0.1" -H "Host: $3" "https://$2:$port/" 2> /dev/null || true
}

# $1 名前（SNI と Host）$2 道筋。gzip を受けると言って送り、「状態|Content-Encoding|Cache-Control|本文の大きさ」を出す
fetch() {
    local code
    code="$(curl -sS -o "$work/body" -D "$work/head" -w '%{http_code}' --http1.1 --max-time 15 --cacert "$work/tls/cert.pem" \
        --retry 20 --retry-delay 1 --retry-connrefused --retry-all-errors -H 'Accept-Encoding: gzip' \
        --resolve "$1:$port:127.0.0.1" "https://$1:$port$2" 2> /dev/null || true)"
    printf '%s|%s|%s|%s' "$code" \
        "$(sed -n 's/^[Cc]ontent-[Ee]ncoding: *//p' "$work/head" 2> /dev/null | tr -d '\r')" \
        "$(sed -n 's/^[Cc]ache-[Cc]ontrol: *//p' "$work/head" 2> /dev/null | tr -d '\r')" \
        "$(wc -c < "$work/body" 2> /dev/null | tr -d ' ')"
}

# 2・3 の確かめ（どの形でも）。$1 入口の形 $2 店の数の形
check_names() {
    local front="$1" shops="$2" got unknown
    # 管理画面とショップのホストは今までどおり届く。HTML は gzip を受けると言っても圧縮せず（BREACH）、元の Cache-Control の後ろに no-transform
    for host in "$admin" "$shop_a"; do
        got="$(fetch "$host" /)"
        [ "$got" = "200||no-cache, private, no-transform|${got##*|}" ] && [ "${got##*|}" -gt 512 ] &&
            ok "形 ${front}・${shops}: ${host} の HTML は 200・圧縮しない・Cache-Control に no-transform（元の値は残る）" ||
            ng "形 ${front}・${shops}: ${host} の HTML が「${got}」（200||no-cache, private, no-transform|<512 より大きい大きさ> のはず。HTML を圧縮すると BREACH の面になる）"
    done
    # CSS などの静的なファイルは今までどおり圧縮する。印は付けない
    got="$(fetch "$shop_a" /build/app.css)"
    [ "$got" = "200|gzip|no-cache, private|${got##*|}" ] && ok "形 ${front}・${shops}: CSS は gzip で、no-transform は付けない" ||
        ng "形 ${front}・${shops}: ショップの CSS が「${got}」（200|gzip|no-cache, private|<大きさ> のはず）"
    # JSON（管理画面の API）は圧縮するが、no-transform は付けない（Cloudflare は JSON を書き換えない。写真なども同じく付けない）
    got="$(fetch "$admin" /api/ping)"
    [ "$got" = "200|gzip|no-cache, private|${got##*|}" ] && ok "形 ${front}・${shops}: JSON は gzip で、no-transform は付けない" ||
        ng "形 ${front}・${shops}: 管理画面の JSON が「${got}」（200|gzip|no-cache, private|<大きさ> のはず）"
    # 場所の無い名前は 404 で、後ろへ渡さない（前は Caddy の既定の空の 200）
    if [ "$shops" = single ]; then unknown="$shop_b"; else unknown="$shop_domain"; fi
    got="$(fetch "$unknown" /)"
    [ "${got%%|*}" = 404 ] && [ "${got##*|}" = 0 ] && ok "形 ${front}・${shops}: 証明書は覆うが場所の無い名前（${unknown}）は 404（空の 200 にしない）" ||
        ng "形 ${front}・${shops}: 場所の無い名前（${unknown}）が「${got}」（404 で本文なしのはず。前は空の 200）"
    if [ "$front" = tunnel ]; then
        # Tunnel の形は SNI と Host の違いを断らないので、管理画面の名前で握手した接続に知らない Host が載っても 404
        for ver in --http1.1 --http2; do
            got="$(status "$ver" "$admin" other.example.test)"
            [ "$got" = 404 ] && ok "形 tunnel・${shops}（${ver}）: 管理画面の名前の接続に知らない Host が載ると 404" ||
                ng "形 tunnel・${shops}（${ver}）: 管理画面の名前の接続に知らない Host（other.example.test）で ${got}（404 のはず）"
        done
    fi
}

for front in caddy tunnel; do
    start_caddy "$front" multi
    for ver in --http1.1 --http2; do
        got="$(status "$ver" "$shop_a" "$shop_a")"
        [ "$got" = 200 ] && ok "形 ${front}（${ver}）: SNI と Host が同じ店の名前なら後ろへ渡す" ||
            { ng "形 ${front}（${ver}）: SNI と Host が同じ店の名前で ${got}（200 のはず）"; docker logs "$tag-caddy" 2>&1 | tail -n 5; }
        got="$(status "$ver" "$shop_a" "$shop_b")"
        if [ "$front" = caddy ]; then
            [ "$got" = 421 ] && ok "形 caddy（${ver}）: SNI と Host が違えば 421（strict_sni_host on）" ||
                ng "形 caddy（${ver}）: SNI（${shop_a}）と Host（${shop_b}）が違うのに ${got}（421 のはず。Host のすり替えを断っていない）"
        else
            [ "$got" = 200 ] && ok "形 tunnel（${ver}）: ある店の名前の接続に別の店の要求が載っても 421 にしない（cloudflared の接続の使い回し）" ||
                ng "形 tunnel（${ver}）: SNI（${shop_a}）と Host（${shop_b}）が違うと ${got}（200 のはず。cloudflared が使い回す接続で本物のショップが 421 になる）"
        fi
    done
    # 本番で起きた形そのもの: 1 本の接続（HTTP/1.1 の keep-alive）で、店 A の要求の後に店 B の要求を送る（cloudflared の使い回しと同じ）。
    # 2 つ目が新しくつながず（num_connects が 0）同じ接続に載ったことも確かめる
    got="$(curl -sS --http1.1 --max-time 15 --cacert "$work/tls/cert.pem" --resolve "$shop_a:$port:127.0.0.1" \
        -o /dev/null -w '%{http_code}:%{num_connects} ' -H "Host: $shop_a" "https://$shop_a:$port/" \
        --next --http1.1 --max-time 15 --cacert "$work/tls/cert.pem" --resolve "$shop_a:$port:127.0.0.1" \
        -o /dev/null -w '%{http_code}:%{num_connects}' -H "Host: $shop_b" "https://$shop_a:$port/" 2> /dev/null || true)"
    if [ "$front" = caddy ]; then want='200:1 421:0'; else want='200:1 200:0'; fi
    [ "$got" = "$want" ] && ok "形 ${front}: 店 A の名前で握手した接続を使い回して店 B の要求を送ると ${got#* }（状態:新しい接続の数）" ||
        ng "形 ${front}: 店 A の接続を使い回した店 B の要求が「${got}」（「${want}」のはず。状態:新しい接続の数）"
    check_names "$front" multi

    start_caddy "$front" single
    check_names "$front" single
done

if [ "$fails" -gt 0 ]; then
    echo "入口の Caddy の確かめ: ${fails} 件 NG"
    exit 1
fi
echo "入口の Caddy の確かめ: すべて ok"
