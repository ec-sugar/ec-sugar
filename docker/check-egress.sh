#!/usr/bin/env bash
# 外への通信の出口の代理（ADR 0102）を、本番の形の compose（docker-compose.prod.yml に Cloudflare Tunnel の形と止める形を重ねたもの）で
# 実際に立てて確かめる。外の本物には頼らない（偽の宛先は手元のコンテナ）
#
#   docker/check-egress.sh
#
# 使うイメージ: EC_CHECK_EGRESS_IMAGE（CI の image の仕事では作ったばかりのアプリのイメージ）。無ければ docker/Dockerfile の php-base の段
# （本番と同じ PHP と拡張。pcntl・curl がある）を作って使う。代理の台本（docker/egress）は compose が置き場から差し込むので、イメージの中身は問わない。
# 立てるのは compose の egress・app・queue・scheduler だけ（-p は使い捨ての名前。--no-deps で db・redis・web・caddy・cloudflared は起こさない）。
# app・queue・scheduler は、ここで作る重ね書きでコマンドを「眠るだけ」にする（網・環境変数・extra_hosts・差し込みは compose のまま）。
# 偽の宛先は compose の外へ出る網（egress。代理だけが居る）に置く:
#   - dest.egress-check.test と other.egress-check.test: Caddy（docker/caddy/Dockerfile の土台の公式のイメージ）が 443 番で https（自前の証明書）で egress-ok を返す
#   - inside.egress-check.test: 同じ Caddy の別名。許す一覧には載せるが、内向きを許す名前には入れない（名前が内向きの住所へ引ける場合）
#   - smtp.egress-check.test: PHP の小さな受け手が 2525 番で「220 egress-check」を返す
# 許す一覧に dest を足し（EC_SUGAR_EGRESS_ALLOW）、偽の宛先は内向きの住所なので dest と other を内向きを許す名前（EC_SUGAR_EGRESS_INTERNAL_OK）にする。
# MAIL_HOST=smtp.egress-check.test・MAIL_PORT=2525 の env ファイルで解く。
#
# 確かめること:
#   止める形（EC_SUGAR_EGRESS_MODE=enforce と docker-compose.egress.yml）
#   1. 許した名前へは届く: app から HTTPS_PROXY で（curl も PHP の curl も。Guzzle と Stripe の部品が使う libcurl）https://dest.egress-check.test/ が egress-ok
#   2. 許していない名前（other）・住所の直書き（偽の宛先の住所・1.1.1.1）・443 以外のポート・平文の http は代理が断る（403・405）。
#      許した名前でも内向きの住所へ引けるもの（inside）は断る（理由 internal-address）。CONNECT の名前と違う名前（SNI）で握手したら切る（理由 sni-mismatch）。
#      TLS でないものは止める形では切る（理由 not-tls）
#   3. 代理を通らない道が無い: app・queue・scheduler に外への経路（既定の経路）が無く、偽の宛先の住所・外の住所へ直につながらない。
#      DNS で外の名前（example.com）・偽の宛先の名前を引けない（Docker の中の DNS が外へ問い合わせない）。引けるのは代理の名前（egress）だけ。
#      egress_in の網の gateway（ホストの側）の 22・53・25 番と、ホストで待ち受ける試しの口に届かない（gateway_mode_ipv4: isolated）。
#      internal の網の gateway からも届かない（ホストの防火壁の「コンテナからホストへ」の決まり。deploy/lib/common.sh の ec_host_guard_apply を
#      本物の Docker のホストの iptables に、この確かめの網だけ・別の鎖の名前と印で当てる。終われば外す。ホストの iptables は、権限を持った
#      使い捨てのコンテナ〔Caddy の土台のイメージの nsenter〕からホストの名前空間に入って流す）
#   4. メールの送り口: MAIL_HOST:MAIL_PORT へつなぐと代理の中継で偽の受け手の「220」が届く。偽の受け手の住所へ直には届かない
#   5. 数と記録: 代理の状態のファイル（アプリからは読み取り専用）に、通した・断った・メールの中継の数が出る。記録は名前・ポート・決めたことだけ
#   5b. 代理を止める合図の後も、途中の接続（TLS の中の要求）は最後まで流れ、終われば代理がすぐ止まる（更新のたびの作り直しで Stripe への要求を切らない）
#   見張りの形（代理を watch で作り直す）
#   6. 許していない名前（other）も通して「一覧に無い」に数える。内向きの住所の直書きと、内向きへ引ける名前（inside）と、違う名前の握手は断る
# 終われば コンテナ・網・置き場・仮の置き場を消す。重いので、ほかの立てる確かめと並べない
set -euo pipefail

cd "$(dirname "$0")/.."
fails=0
ng() { echo "NG: $*"; fails=$((fails + 1)); }
ok() { echo "ok: $*"; }

caddy_image="$(sed -n 's/^FROM \(caddy:[^ ]*-alpine@sha256:[0-9a-f]\{64\}\)$/\1/p' docker/caddy/Dockerfile)"
[ -n "$caddy_image" ] || { echo "NG: docker/caddy/Dockerfile の動かす段の土台（caddy:<版>-alpine@sha256:…）が読めない"; exit 1; }

image="${EC_CHECK_EGRESS_IMAGE:-}"
built=0
if [ -z "$image" ]; then
    # 作った札は終わりに外す（組み立ての控えは Docker に残るので、次の回もすぐ作れる）
    image="ec-sugar-egress-check:php-base"
    built=1
    echo "アプリと同じ PHP（docker/Dockerfile の php-base の段）を作ります"
    docker build --quiet --file docker/Dockerfile --target php-base --tag "$image" . > /dev/null
fi
docker run --rm --entrypoint php "$image" -r 'exit(extension_loaded("pcntl") && extension_loaded("curl") ? 0 : 1);' ||
    { echo "NG: $image に PHP の pcntl と curl が無い"; exit 1; }

project="ec-sugar-egress-check-$$"
tag="$project"
work="$(mktemp -d)"
files=(-f docker-compose.prod.yml -f docker-compose.tunnel.yml -f docker-compose.egress.yml -f "$work/check.yml")
dc() {
    env EC_SUGAR_ENV_FILE="$work/check.env" EC_SUGAR_IMAGE="$image" EC_SUGAR_BIND=127.0.0.1 EC_SUGAR_TUNNEL_DIR="$work/tunnel" \
        DB_PASSWORD=check-only REDIS_PASSWORD=check-only EC_SUGAR_DB_SUPERUSER_PASSWORD=check-only \
        docker compose -p "$project" --project-directory "$PWD" "${files[@]}" --env-file "$work/check.env" "$@"
}
# ホストの防火壁（コンテナからホストへ）を、本物の関数で当てる。鎖の名前と印はこの確かめだけのもの（本物の EC-SUGAR の決まりに触らない）
EC_HOST_CHAIN="EC-EGCHK-$$"
EC_HOST_COMMENT="ec-sugar-egress-check-$$"
# shellcheck source=../deploy/lib/common.sh
. deploy/lib/common.sh
EC_PROJECT="$project"
EC_ENV_FILE="$work/check.env"
host_ipt() { docker run --rm --privileged --pid=host --network host --entrypoint nsenter "$caddy_image" -t 1 -m -n "$@" 2> /dev/null; }
iptables() { host_ipt iptables "$@"; }
ip6tables() { host_ipt ip6tables "$@"; }
host_guard_remove() {
    local tool line
    for tool in iptables ip6tables; do
        while IFS= read -r line; do
            # shellcheck disable=SC2086
            "$tool" -D ${line#-A } > /dev/null 2>&1 || true
        done < <("$tool" -S INPUT 2> /dev/null | grep -- "--comment $EC_HOST_COMMENT" || true)
        "$tool" -F "$EC_HOST_CHAIN" > /dev/null 2>&1 || true
        "$tool" -X "$EC_HOST_CHAIN" > /dev/null 2>&1 || true
    done
}
cleanup() {
    host_guard_remove
    docker rm -f "$tag-dest" "$tag-smtp" "$tag-hostl" > /dev/null 2>&1 || true
    dc down -v -t 5 --remove-orphans > /dev/null 2>&1 || true
    [ "$built" -eq 0 ] || docker rmi "$image" > /dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT

mkdir -p "$work/tunnel" "$work/caddy"
# env ファイル: 見本に、メールの送り口と許す一覧・形を足す（秘密は無い。app は眠るだけ）
{
    grep -vE '^(MAIL_HOST|MAIL_PORT|EC_SUGAR_EGRESS_[A-Z_]+)=' deploy/production.env.example
    printf 'MAIL_HOST=smtp.egress-check.test\nMAIL_PORT=2525\n'
    printf 'EC_SUGAR_EGRESS_MODE=enforce\nEC_SUGAR_EGRESS_ALLOW="dest.egress-check.test inside.egress-check.test"\n'
    printf 'EC_SUGAR_EGRESS_INTERNAL_OK="dest.egress-check.test other.egress-check.test"\n'
} > "$work/check.env"
# 確かめのための重ね書き: app・queue・scheduler は眠るだけ（網・環境変数・extra_hosts・差し込みは compose のまま。ヘルスチェックはすぐ通るもの）
cat > "$work/check.yml" << 'EOF'
x-sleep: &sleep
  command: ["sh", "-c", "trap 'exit 0' TERM INT QUIT; while :; do sleep 3600 & wait $$!; done"]
  stop_grace_period: 5s
  # 眠るだけなので、ヘルスチェックはすぐ通るものに替える（無くすと、新しい compose は up --wait の対象にヘルスチェックの無いサービスがあると断る）
  healthcheck:
    test: ["CMD", "true"]
    interval: 2s
    timeout: 2s
    retries: 3
    start_period: 0s
services:
  app: *sleep
  queue: *sleep
  scheduler: *sleep
EOF
cat > "$work/caddy/Caddyfile" << 'EOF'
{
	admin off
	skip_install_trust
	auto_https disable_redirects
}
https://dest.egress-check.test, https://other.egress-check.test, https://inside.egress-check.test {
	tls internal
	respond "egress-ok" 200
}
EOF
chmod -R a+rX "$work"

# 止める形で立てる（網を作ってから、偽の宛先を外へ出る網に置く）
# 先に app だけを作る（空の名前つきの置き場〔storage〕にイメージの中身を写すのを 1 つのコンテナで。いくつも同時に作ると、Docker が同じ置き場に
# 同時に写して「file exists」で作れないことがある）
if ! { dc up --no-start --no-deps app > "$work/up.log" 2>&1 && dc up -d --wait --wait-timeout 120 --no-deps egress app queue scheduler >> "$work/up.log" 2>&1; }; then
    cat "$work/up.log"
    dc logs egress 2>&1 | tail -20
    echo "NG: compose の egress・app・queue・scheduler が起きない"
    exit 1
fi
out_net="${project}_egress"
docker run -d --name "$tag-dest" --network "$out_net" --network-alias dest.egress-check.test --network-alias other.egress-check.test --network-alias inside.egress-check.test \
    -v "$work/caddy/Caddyfile:/etc/caddy/Caddyfile:ro" "$caddy_image" > /dev/null
docker run -d --name "$tag-smtp" --network "$out_net" --network-alias smtp.egress-check.test --user www-data --entrypoint php "$image" \
    -r '$s = stream_socket_server("tcp://0.0.0.0:2525"); while ($c = stream_socket_accept($s, -1)) { fwrite($c, "220 egress-check ESMTP\r\n"); fclose($c); }' > /dev/null
# ホストで待ち受ける試しの口（ホストの網。egress_in の gateway から届くかを見る）
host_port=$((40000 + $$ % 20000))
docker run -d --name "$tag-hostl" --network host --entrypoint php "$image" \
    -r '$s = stream_socket_server("tcp://0.0.0.0:'"$host_port"'"); while ($c = stream_socket_accept($s, -1)) { fwrite($c, "host\n"); fclose($c); }' > /dev/null
# ホストの防火壁（コンテナからホストへ）を、この確かめの網に当てる
host_tools="$(ec_fw_tools)"
if [ -z "$host_tools" ]; then
    echo "NG: Docker のホストの iptables を使えない（権限を持ったコンテナからホストの名前空間に入れない）"
    exit 1
fi
ec_host_guard_apply "$host_tools" "$(ec_bridges)" || { echo "NG: ホストの防火壁（コンテナからホストへ）を当てられない"; exit 1; }
dest_ip="$(docker inspect -f "{{(index .NetworkSettings.Networks \"$out_net\").IPAddress}}" "$tag-dest")"
smtp_ip="$(docker inspect -f "{{(index .NetworkSettings.Networks \"$out_net\").IPAddress}}" "$tag-smtp")"
[ -n "$dest_ip" ] && [ -n "$smtp_ip" ] || { echo "NG: 偽の宛先の住所が読めない"; exit 1; }

# app の中で流す（$1 サービス、残りはシェルの文）
in_svc() {
    local svc="$1"
    shift
    dc exec -T "$svc" sh -c "$*" 2> /dev/null
}
# Caddy が自前の証明書を作るまで待つ（代理を通して 200 が返るまで。最大 30 秒）
for _ in $(seq 1 30); do
    [ "$(in_svc app 'curl -sk --max-time 5 https://dest.egress-check.test/' || true)" = egress-ok ] && break
    sleep 1
done

# 1. 許した名前へは届く
got="$(in_svc app 'curl -sk --max-time 10 https://dest.egress-check.test/' || true)"
[ "$got" = egress-ok ] && ok "1. 許した名前（dest）へ、app から HTTPS_PROXY で（curl）届く" || ng "1. 許した名前へ curl で届かない（${got:-空}）"
got="$(in_svc queue 'php -r '"'"'$c = curl_init("https://dest.egress-check.test/"); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_TIMEOUT => 10]); echo curl_exec($c);'"'" || true)"
[ "$got" = egress-ok ] && ok "1. 許した名前へ、queue から PHP の curl（Guzzle・Stripe の部品の libcurl）で、環境変数の代理を通って届く" || ng "1. 許した名前へ PHP の curl で届かない（${got:-空}）"

# 2. 許していない名前・住所の直書き・443 以外のポート・平文の http は代理が断る
connect_code() { in_svc app "curl -sk -o /dev/null --max-time 10 -w '%{http_connect}' $1" || true; }
[ "$(connect_code https://other.egress-check.test/)" = 403 ] && ok "2. 許していない名前（other）は代理が 403 で断る" || ng "2. 許していない名前が断られない"
[ "$(connect_code "https://$dest_ip/")" = 403 ] && ok "2. 内向きの住所の直書き（${dest_ip}）は代理が断る" || ng "2. 内向きの住所の直書きが断られない"
[ "$(connect_code https://1.1.1.1/)" = 403 ] && ok "2. 外の住所の直書き（1.1.1.1）も止める形では断る" || ng "2. 外の住所の直書きが断られない"
[ "$(connect_code https://dest.egress-check.test:8443/)" = 403 ] && ok "2. 許した名前でも 443 以外のポートは断る" || ng "2. 443 以外のポートが断られない"
got="$(in_svc app "curl -s -o /dev/null --max-time 10 -w '%{http_code}' -x http://egress:3128 http://dest.egress-check.test/" || true)"
[ "$got" = 405 ] && ok "2. 平文の http の代理は受けない（405）" || ng "2. 平文の http が代理を通る（${got:-空}）"
[ "$(connect_code https://inside.egress-check.test/)" = 403 ] && ok "2. 許した名前でも内向きの住所へ引けるもの（inside）は断る" || ng "2. 内向きの住所へ引ける名前が断られない"
# CONNECT は許した名前（dest）、握手の名前（SNI）は別の名前（other）。PHP の TLS で SNI を決めて握手する
sni_probe() {
    in_svc app 'php -r '"'"'$s = @fsockopen("egress", 3128, $e, $m, 5); fwrite($s, "CONNECT dest.egress-check.test:443 HTTP/1.1\r\nHost: dest.egress-check.test:443\r\n\r\n"); $head = (string) fgets($s); fgets($s); stream_context_set_option($s, "ssl", "peer_name", "'"$1"'"); stream_context_set_option($s, "ssl", "verify_peer", false); stream_context_set_option($s, "ssl", "verify_peer_name", false); stream_set_timeout($s, 10); echo trim($head), " ", @stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) ? "tls-ok" : "tls-cut";'"'" || true
}
[ "$(sni_probe dest.egress-check.test)" = "HTTP/1.1 200 Connection established tls-ok" ] && ok "2. CONNECT と同じ名前の握手は通る" || ng "2. CONNECT と同じ名前の握手が通らない（$(sni_probe dest.egress-check.test)）"
[ "$(sni_probe other.egress-check.test)" = "HTTP/1.1 200 Connection established tls-cut" ] && ok "2. CONNECT と違う名前（SNI）で握手したら切る" || ng "2. 違う名前の握手が通る（$(sni_probe other.egress-check.test)）"
got="$(in_svc app 'php -r '"'"'$s = @fsockopen("egress", 3128, $e, $m, 5); fwrite($s, "CONNECT dest.egress-check.test:443 HTTP/1.1\r\n\r\n"); fgets($s); fgets($s); fwrite($s, "GET / HTTP/1.1\r\nHost: x\r\n\r\n"); stream_set_timeout($s, 15); echo fread($s, 100) === "" ? "cut" : "passed";'"'" || true)"
[ "$got" = cut ] && ok "2. 止める形では TLS でないものを切る" || ng "2. 止める形で TLS でないものが通る（${got:-空}）"

# 3. 代理を通らない道が無い（経路・直の接続・DNS）
for svc in app queue scheduler; do
    routes="$(in_svc "$svc" 'cat /proc/net/route' || true)"
    if [ -n "$routes" ] && ! printf '%s\n' "$routes" | awk 'NR > 1 && $2 == "00000000" { found = 1 } END { exit found ? 0 : 1 }'; then
        ok "3. ${svc} に外への経路（既定の経路）が無い"
    else
        ng "3. ${svc} に外への経路がある・経路を読めない"
    fi
done
for target in "https://$dest_ip/" "https://1.1.1.1/" "https://dest.egress-check.test/"; do
    if in_svc app "curl -sk -o /dev/null --noproxy '*' --connect-timeout 3 --max-time 5 $target"; then
        ng "3. 代理を通らずに ${target} へ届く"
    else
        ok "3. 代理を通らずに ${target} へは届かない"
    fi
done
egress_gw="$(docker network inspect -f '{{(index .IPAM.Config 0).Gateway}}' "${project}_egress_in" 2> /dev/null || true)"
internal_gw="$(docker network inspect -f '{{(index .IPAM.Config 0).Gateway}}' "${project}_internal" 2> /dev/null || true)"
got="$(in_svc app "php -r 'foreach ([$host_port, 22, 53, 25] as \$p) { echo @fsockopen(\"$egress_gw\", \$p, \$e, \$m, 3) ? \"reached\" : \"none\", \" \"; }'" || true)"
[ -n "$egress_gw" ] && [ "$got" = "none none none none " ] && ok "3. egress_in の gateway（${egress_gw}。ホストの側）の試しの口・22・53・25 番に届かない" ||
    ng "3. egress_in の gateway（${egress_gw:-読めない}）からホストへ届く（${got:-空}。gateway_mode_ipv4: isolated が効いていない）"
# internal の網（前からある網。設定を変えると動いているサーバーで作り直せないので isolated にしない）は、ホストの防火壁の INPUT の決まりで塞ぐ
got="$(in_svc app "php -r 'foreach ([$host_port, 22, 53, 25] as \$p) { echo @fsockopen(\"$internal_gw\", \$p, \$e, \$m, 3) ? \"reached\" : \"none\", \" \"; }'" || true)"
[ -n "$internal_gw" ] && [ "$got" = "none none none none " ] && ok "3. internal の網の gateway（${internal_gw}）からホストの試しの口・22・53・25 番に届かない（ホストの防火壁）" ||
    ng "3. internal の網の gateway（${internal_gw:-読めない}）からホストへ届く（${got:-空}。ホストの防火壁の「コンテナからホストへ」が効いていない）"
dns="$(in_svc app 'php -r '"'"'echo json_encode([gethostbynamel("example.com"), @dns_get_record("example.com", DNS_A), gethostbynamel("dest.egress-check.test"), gethostbynamel("egress")]);'"'" || true)"
if [ "$(printf '%s' "$dns" | jq -c '.[0:3]' 2> /dev/null)" = '[false,false,false]' ] && [ "$(printf '%s' "$dns" | jq -r '.[3][0] // ""' 2> /dev/null)" = 172.30.255.194 ]; then
    ok "3. app は DNS で外の名前（example.com）・偽の宛先の名前を引けない（外へ問い合わせない）。引けるのは代理の名前だけ"
else
    ng "3. app の DNS が外の名前を引く・代理の名前を引けない（${dns:-空}）"
fi

# 4. メールの送り口は決めた宛先だけ
got="$(in_svc scheduler 'php -r '"'"'$s = @fsockopen("smtp.egress-check.test", 2525, $e, $m, 5); echo $s ? trim((string) fgets($s)) : "none";'"'" || true)"
[ "$got" = "220 egress-check ESMTP" ] && ok "4. MAIL_HOST:MAIL_PORT は代理の中継で偽の受け手に届く" || ng "4. MAIL_HOST へ届かない（${got:-空}）"
got="$(in_svc app "php -r '\$s = @fsockopen(\"$smtp_ip\", 2525, \$e, \$m, 3); echo \$s ? \"reached\" : \"none\";'" || true)"
[ "$got" = none ] && ok "4. 偽の受け手の住所（${smtp_ip}）へは直に届かない" || ng "4. メールの受け手の住所へ直に届く（${got:-空}）"
got="$(in_svc app 'php -r '"'"'$s = @fsockopen("egress", 25, $e, $m, 3); echo $s ? "reached" : "none";'"'" || true)"
[ "$got" = none ] && ok "4. 代理はほかのポート（25）でメールを中継しない" || ng "4. 代理が MAIL_PORT のほかのポートで受ける"

# 5. 数と記録（状態のファイルは 10 秒ごと）
sleep 11
state="$(in_svc app 'cat /run/ec-sugar-egress/state.json' || true)"
if [ "$(printf '%s' "$state" | jq -r '[.mode, (.last24h.allowed >= 2), (.last24h.refused >= 5), (.last24h.smtp >= 1)] | map(tostring) | join(" ")' 2> /dev/null)" = "enforce true true true" ]; then
    ok "5. 代理の数（この 24 時間）がアプリから読める: $(printf '%s' "$state" | jq -c .last24h)"
else
    ng "5. 代理の数が違う・読めない（${state:-空}）"
fi
in_svc app 'touch /run/ec-sugar-egress/x' && ng "5. アプリが代理の数の置き場に書ける" || ok "5. アプリは代理の数の置き場を読むだけ"
logs="$(dc logs --no-log-prefix egress 2>&1 || true)"
if grep -q '"decision":"refuse","reason":"unlisted","host":"other.egress-check.test","port":443' <<< "$logs" &&
    grep -q '"decision":"refuse","reason":"internal-address","host":"inside.egress-check.test","port":443' <<< "$logs" &&
    grep -q '"decision":"refuse","reason":"sni-mismatch","host":"dest.egress-check.test","port":443' <<< "$logs" &&
    grep -q '"decision":"refuse","reason":"not-tls","host":"dest.egress-check.test","port":443' <<< "$logs" &&
    ! grep -qiE 'egress-ok|"path"|authorization|user-agent' <<< "$logs"; then
    ok "5. 記録は名前・ポート・決めたことだけ（中身・道筋・ヘッダーは無い）"
else
    ng "5. 記録に断った名前が無い・中身が出ている"
fi

# 5b. 止める合図の後も途中の接続は流れる: app で TLS の接続を張り、代理を止め始めてから要求を送る
in_svc app 'php -r '"'"'$s = @fsockopen("egress", 3128, $e, $m, 5); fwrite($s, "CONNECT dest.egress-check.test:443 HTTP/1.1\r\n\r\n"); fgets($s); fgets($s); stream_context_set_option($s, "ssl", "peer_name", "dest.egress-check.test"); stream_context_set_option($s, "ssl", "verify_peer", false); stream_context_set_option($s, "ssl", "verify_peer_name", false); stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT); sleep(6); fwrite($s, "GET / HTTP/1.1\r\nHost: dest.egress-check.test\r\nConnection: close\r\n\r\n"); stream_set_timeout($s, 10); echo str_contains((string) stream_get_contents($s), "egress-ok") ? "drained-ok" : "cut";'"'" > "$work/drain.txt" 2>&1 &
drain_pid=$!
sleep 3
stop_started=$(date +%s)
dc stop -t 90 egress > /dev/null 2>&1 || true
stop_took=$(($(date +%s) - stop_started))
wait "$drain_pid" || true
if [ "$(cat "$work/drain.txt")" = drained-ok ] && [ "$stop_took" -lt 60 ]; then
    ok "5b. 止める合図の後も途中の接続は最後まで流れ、終われば代理が止まる（${stop_took} 秒）"
else
    ng "5b. 止める合図で途中の接続が切れた・代理がすぐ止まらない（$(cat "$work/drain.txt")・${stop_took} 秒）"
fi
# 記録は先に受け取ってから探す（grep -q が先に管を閉じると、pipefail で記録の読み出しが失敗に数えられる）
egress_logs() { dc logs --no-log-prefix egress 2>&1 || true; }
logs="$(egress_logs)"
grep -q '"event":"draining"' <<< "$logs" || ng "5b. 代理の記録に「draining」が無い"

# 6. 見張りの形: 代理だけを watch で作り直す（許していない名前も通して数える。内向きの住所の直書きは断る）
if EC_SUGAR_EGRESS_MODE=watch dc up -d --wait --wait-timeout 60 --no-deps --force-recreate egress > "$work/up2.log" 2>&1; then
    got="$(in_svc app 'curl -sk --max-time 10 https://other.egress-check.test/' || true)"
    [ "$got" = egress-ok ] && ok "6. 見張りの形: 許していない名前（other）も通す" || ng "6. 見張りの形で許していない名前が通らない（${got:-空}）"
    [ "$(connect_code "https://$dest_ip/")" = 403 ] && ok "6. 見張りの形でも内向きの住所の直書きは断る" || ng "6. 見張りの形で内向きの住所へ通す"
    [ "$(connect_code https://inside.egress-check.test/)" = 403 ] && ok "6. 見張りの形でも内向きの住所へ引ける名前は断る" || ng "6. 見張りの形で内向きの住所へ引ける名前を通す"
    [ "$(sni_probe other.egress-check.test)" = "HTTP/1.1 200 Connection established tls-cut" ] && ok "6. 見張りの形でも違う名前の握手は切る" || ng "6. 見張りの形で違う名前の握手が通る"
    sleep 11
    state="$(in_svc app 'cat /run/ec-sugar-egress/state.json' || true)"
    [ "$(printf '%s' "$state" | jq -r '[.mode, (.last24h.unlisted >= 1), (.last_unlisted_at != null)] | map(tostring) | join(" ")' 2> /dev/null)" = "watch true true" ] &&
        ok "6. 見張りの形: 一覧に無い名前を通した数が出る" || ng "6. 見張りの形の数が違う（${state:-空}）"
    logs="$(egress_logs)"
    grep -q '"decision":"refuse","reason":"internal-address","host":"inside.egress-check.test"' <<< "$logs" &&
        ok "6. 見張りの形: 内向きへ引ける名前を断った理由（internal-address）が記録に残る" || ng "6. 見張りの形で internal-address の記録が無い"
    grep -q '"decision":"unlisted","reason":"unlisted","host":"other.egress-check.test"' <<< "$logs" &&
        ok "6. 見張りの形: 一覧に無い名前が記録に残る" || ng "6. 見張りの形で一覧に無い名前が記録に無い"
else
    cat "$work/up2.log"
    ng "6. 代理を見張りの形で作り直せない"
fi

[ "$fails" -eq 0 ] || { echo "NG が ${fails} 件"; exit 1; }
echo "すべて ok"
