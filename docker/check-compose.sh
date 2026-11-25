#!/usr/bin/env bash
# 本番とステージングの compose（docker-compose.prod.yml）の守りを確かめる（ADR 0029・0031）
#
#   docker/check-compose.sh [compose のファイル]   既定は docker-compose.prod.yml
#
# deploy/production.env.example を env ファイルにして `docker compose config` で解いた結果（JSON）を見る。コンテナは動かさない。
# 合言葉は見本では空（空なら compose が止まる）なので、ここだけの仮の値を渡す。
# 受け口の住所（EC_SUGAR_BIND）には、どこにも無い印の住所（203.0.113.7、説明用の住所）を渡し、
# 「127.0.0.1 か EC_SUGAR_BIND 以外の住所にポートを出していない」を見分ける。
#
# 確かめること:
#   1. ポート: web・db・redis・app・queue・scheduler は出さない。出すのは入口の caddy（EC_SUGAR_BIND だけ）と mailpit（127.0.0.1 だけ）。
#      住所の無いポート（0.0.0.0 に出る）は無い。EC_SUGAR_BIND が未設定なら compose が止まる（0.0.0.0 に倒れない）
#   2. どのサービスも根が読み取り専用・cap_drop ALL・cap_add 無し・no-new-privileges、privileged やホストの網・pid を使わない
#   3. app・queue・scheduler は同じイメージ（EC_SUGAR_IMAGE）で、ここで作らない（build 無し）、www-data で動く
#   4. イメージはバージョンを固定（タグが「数.数」で始まるバージョンか sha256 の指定。latest や「17-alpine」のような動く札は使わない）
#   5. APP_DEBUG は本番で true にならない: env ファイルに APP_DEBUG=true・APP_ENV=local と書いても、解いた結果は false・production
#   6. DB の管理者の合言葉はアプリに渡らない（前の名前 SUGAR_EC_… の行が env ファイルに残っていても）。db・redis は外に出られない内側の網だけ
#   7. 見本の APP_DEBUG は false、本物の env ファイル（.env.production）は git に入らない
#   8. 入口の 2 段（ADR 0031）:
#      - caddy は front の網だけで、住所は EC_SUGAR_CADDY_ADDR に固定（範囲 EC_SUGAR_FRONT_SUBNET の中で、自動で配る EC_SUGAR_FRONT_RANGE の外）。web は front と edge だけ（内側の網に出ない）
#      - 証明書の置き場（caddy_data）と入口の秘密（EC_SUGAR_CADDY_SECRETS）は caddy にだけ差し込み、秘密は読み取り専用。
#        caddy の環境変数は決めたものだけ（鍵を env で渡さない）
#      - sysctls は caddy の net.ipv4.ip_unprivileged_port_start（80 以上）だけ
#      - caddy のイメージの札は docker/caddy/Dockerfile のバージョン（Caddy と Cloudflare の部品と改訂）とそろい、Dockerfile の FROM は digest で固定
#      - nginx は接続元の見出しを Caddy の住所からだけ信じ、内側の口（8081）も Caddy の住所だけ。外の入口は /internal/ を 404、印を空にする
#      - Caddyfile は管理の API を切り（admin off）、前の代理を信じない（ふつうの形の front-caddy.servers は strict_sni_host on だけ。trusted_proxies・
#        client_ip_headers を書いてよいのは front-tunnel.servers だけで、中身は cloudflared の住所 /32 と CF-Connecting-IP と strict_sni_host insecure_off
#        ちょうど。ADR 0052）。SNI と Host の違いを断る（strict_sni_host on）のはふつうの形で、Tunnel の形だけ断らない（cloudflared が
#        接続を使い回すため）。strict_sni_host はこの 2 つのファイルのほかに書かない。nginx へは
#        X-Forwarded-For {client_ip} だけを渡し、CF-Connecting-IP・True-Client-IP を消す。取り方ごとの設定がそろっている
#      - front の網にいるのは caddy と web だけで、住所を固定するのは caddy だけ。Caddy の住所は網の住所・gateway・ブロードキャストでない
#      - auto の場所は管理画面とショップ（EC_SUGAR_SITE_SHOP）の 2 つだけ。その場で取る（on_demand）のは複数の店の形（issue-multi）だけで、
#        1 店舗の形（issue-single、既定）はふつうの自動の証明書（問い合わせの経路を使わない）。外の名前は ask に届く前に握手で断る
#      - 8081 番は回数の制限（429）と名前の絞り込み（管理画面と *.<ショップのドメイン> 以外は 404）を持つ。外の入口は「.php/ の後ろの道筋」を 404
#      - 本文の上限: ふだんは 1m。管理画面のホストの API だけ 12m、商品の CSV の口だけ 100m（ほかのホストは 404）。開発の nginx も同じ。
#        JSON は PHP の入口（public/index.php の JsonBodyLimit::guard()）が Laravel より前に 2 MB で断る
#      - Caddy の作る段は Go の部品の控え（GOSUMDB）を切らず、go.mod を書き換えない（GOFLAGS=-mod=readonly）。
#        インストーラーの DNS の鍵の確かめは、Dockerfile の Cloudflare の部品のバージョンに合わせたもの
#  10. php-fpm（app）は TCP で受けない（3 回目の見直し）: EC_SUGAR_FPM_LISTEN が Unix ソケット、ソケットの置き場 fpm は
#      メモリの上（tmpfs）で app（書ける）と web（読み取り専用）だけが差し込み、web は gid 82 を足す。nginx の fastcgi_pass はそのソケットだけで、
#      PHP_VALUE・PHP_ADMIN_VALUE を空にする（ホストの root でない利用者から網へ直に届かないようにするのは、compose でなくホストの防火壁。deploy/lib/common.sh の ec_firewall_apply。
#      確かめは docker/check-deploy.sh と使い捨ての Ubuntu）。www.conf の既定もソケットで、イメージはポートを出さない（EXPOSE なし）。
#      9000 番で受けるのは開発の compose（docker-compose.yml）の app だけで、開発の nginx の渡し先（app:9000）と合う
#  11. ステージングの Mailpit の画面は Basic 認証（ハッシュのファイルを読み取り専用で。無ければ起きない。パスワードを環境変数で渡さない）
#  12. Cloudflare Tunnel で出す形（docker-compose.tunnel.yml を重ねたもの。ADR 0052）: 1〜4・6 の決まりは同じ。cloudflared はバージョンと digest まで固定・
#      読み取り専用・cap_drop ALL・no-new-privileges・利用者は root でも Caddy でもない EC_TUNNEL_UID・ポートを出さない・トークンは --token-file で
#      （TUNNEL_TOKEN を環境変数にも引数にも持たない）・置き場（EC_SUGAR_TUNNEL_DIR）は読み取り専用で cloudflared だけ。tunnel の網は内側だけで
#      caddy と cloudflared だけ、tunnel_out は cloudflared だけ。cloudflared の住所は固定（範囲の中・自動で配る範囲の外）で、caddy の
#      EC_SUGAR_TUNNEL_ADDR（Caddy が信じる住所）と同じ。caddy の EC_SUGAR_FRONT は tunnel。
#      ホストの防火壁（deploy/lib/common.sh の ec_bridges）の網の一覧が、どの形の網にも当たる
#  13. 隔離した写しの形（docker-compose.isolated.yml を重ねたもの。ADR 0082）: 1〜4・6・10 の決まりは同じ。app・queue・scheduler は
#      内側の網（internal）だけ（外へ出る edge・egress に居ない）・隔離の印 EC_SUGAR_ISOLATED=true を環境変数で持つ・定期の処理（schedule:）を動かさない
#  14. 中の照らしの様子（ADR 0105）: どの形（ふつう・後ろに置く・Tunnel・隔離）でも、様子の置き場（EC_INTEGRITY_PUBLIC）は web にだけ読み取り専用で、
#      署名の鍵と控えの置き場（/srv/ec-sugar/trust の下）とそれを含む上の置き場はどのコンテナにも差し込まない。取りに来た時刻の記録は web の tmpfs だけ。
#      nginx は integrity.json と .sig を差し込んだ置き場からそのまま返し（PHP を通さない・キャッシュなし・GET だけ）、ほかの /.well-known/ec-sugar/ は 404
#  15. 外への通信の出口の代理（ADR 0102）: 代理（egress）はアプリと同じイメージ・ここで作らない・www-data・env ファイルなし（秘密を持たない）・
#      環境変数は決めたものだけ・差し込むのは docker/egress（読み取り専用）と数の置き場だけ・網は egress と egress_in（住所を固定）。egress_in は内側だけで
#      アプリと代理だけ。アプリは HTTPS_PROXY で代理を通り、数の置き場は読み取り専用。見張りの形（既定）のアプリの網は今までどおり。
#      止める形（docker-compose.egress.yml を重ねたもの。Tunnel の形にも）: アプリは internal と egress_in だけ、MAIL_HOST は代理の住所へ向き、
#      外へ出る網にいるのは web・代理・Mailpit だけ。隔離の形を後ろに重ねるとアプリは internal だけ・代理も外へ出ない。代理を使わない形は HTTPS_PROXY が空。
#      動かして確かめるのは docker/check-egress.sh
#   9. すでにある Web サーバーの後ろに置く形（docker-compose.proxy.yml を重ねたもの。ADR 0031 の 5）:
#      caddy は 0 個、web は TCP のポートを出さず（mailpit の 127.0.0.1 だけは残る）、ホストのソケットの置き場（EC_SUGAR_FRONT_SOCKET_DIR）だけで受ける。
#      nginx の外の口は front-proxy.inc（Unix ソケットで受け、set_real_ip_from unix: だけ、https の印が無ければ https へ送る）。
#      ふつうの形は front-caddy.inc（8080 番、Caddy の住所だけ）。ほかの守り（権限・網・イメージ）は 1〜8 と同じ
#      - 証明書を取る前の問い合わせの印（EC_SUGAR_TLS_ASK）は app・queue・scheduler の環境変数で空
set -euo pipefail
# 管の先で「あるか」を見る grep -q の代わり。grep -q は見つけた時点で読むのをやめるので、前のコマンドが書き込みの途中だと管が切れ、
# pipefail の下では偽の失敗になる（タイミングしだいで、たまにだけ落ちる）。先に全部を受け取ってから探す
q() {
    local in
    in="$(cat)"
    if [ -z "$in" ]; then grep -q "$@" < /dev/null; else grep -q "$@" <<< "$in"; fi
}

# 確かめる木。既定はこのスクリプトのある木。EC_CHECK_TREE を渡すとその木を見る（サーバーの ec-sugar update が、今入っているバージョンの
# この検査で、取り出した新しいバージョンの木を見る。高）
cd "${EC_CHECK_TREE:-$(dirname "$0")/..}"
compose_file="${1:-docker-compose.prod.yml}"
example="deploy/production.env.example"
sentinel="203.0.113.7"
fails=0
ng() { echo "NG: $*"; fails=$((fails + 1)); }
ok() { echo "ok: $*"; }

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# 使う compose を差し替える: EC_CHECK_COMPOSE に compose の単体のバイナリの道筋を渡すと、docker compose の代わりにそれを使う
# （例 Ubuntu の部品の 2.40 系。版によって入れ子の既定値の解き方が違う）。env 越しの呼び出しも向くように、compose だけを差し替える
# 小さな docker を PATH の前に置く
if [ -n "${EC_CHECK_COMPOSE:-}" ]; then
    EC_CHECK_REAL_DOCKER="$(command -v docker)"
    export EC_CHECK_COMPOSE EC_CHECK_REAL_DOCKER
    mkdir -p "$work/compose-bin"
    printf '%s\n' '#!/bin/sh' 'if [ "${1:-}" = compose ]; then shift; exec "$EC_CHECK_COMPOSE" "$@"; fi' 'exec "$EC_CHECK_REAL_DOCKER" "$@"' > "$work/compose-bin/docker"
    chmod +x "$work/compose-bin/docker"
    PATH="$work/compose-bin:$PATH"
fi
ok "使う compose: $(docker compose version --short 2> /dev/null || echo '読めない')"

# 解く。合言葉はここだけの仮の値（本物ではない）。env_file は見本そのもの（$1 で差し替えられる）
resolve() {
    local env_file="$1"
    shift
    env EC_SUGAR_ENV_FILE="$env_file" EC_SUGAR_BIND="$sentinel" EC_SUGAR_IMAGE="ec-sugar:check" \
        DB_PASSWORD=check-only REDIS_PASSWORD=check-only EC_SUGAR_DB_SUPERUSER_PASSWORD=check-only \
        "$@" docker compose -f "$compose_file" --env-file "$example" --profile staging config --format json
}

if ! resolve "$example" > "$work/config.json" 2> "$work/err.txt"; then
    cat "$work/err.txt"
    echo "NG: docker compose config が通らない"
    exit 1
fi
ok "docker compose config が通る（$example で）"

# 1〜4・6 は JSON を jq で見る。NG の文だけを 1 行ずつ出す（Cloudflare Tunnel の形〔12〕でも同じ決まりで見る）
base_rules='
    def php: ["app", "queue", "scheduler"];
    def mayPublish: {"caddy": [$bind], "mailpit": ["127.0.0.1"]};
    .networks as $nets
    | .services | to_entries[] | .key as $name | .value as $s
    | (
        # 1. ポート
        ( ($s.ports // [])[] | . as $p
          | if (mayPublish[$name] // null) == null then "\($name) がポート \($p.published) を出している（出してよいのは caddy と mailpit だけ）"
            elif ($p.host_ip // "") == "" then "\($name) のポート \($p.published) に住所が無い（0.0.0.0 に出る）"
            elif (mayPublish[$name] | index($p.host_ip)) == null then "\($name) のポート \($p.published) の住所が \($p.host_ip)（\(mayPublish[$name] | join("・")) だけにする）"
            else empty end ),
        # 2. 権限
        ( if ($s.read_only // false) != true then "\($name) の根が読み取り専用でない（read_only: true）" else empty end ),
        ( if (($s.cap_drop // []) | index("ALL")) == null then "\($name) が cap_drop: [ALL] でない" else empty end ),
        ( if (($s.cap_add // []) | length) > 0 then "\($name) が権限を足している（cap_add: \($s.cap_add | join(",")))" else empty end ),
        ( if (($s.security_opt // []) | index("no-new-privileges:true")) == null then "\($name) に no-new-privileges:true が無い" else empty end ),
        ( if ($s.privileged // false) then "\($name) が privileged" else empty end ),
        ( ($s.sysctls // {}) | to_entries[]
          | if $name == "caddy" and .key == "net.ipv4.ip_unprivileged_port_start" and ((.value | tostring | tonumber) >= 80) then empty
            # 出口の代理はメールの送り口の中継を MAIL_PORT（25 番から）で受ける
            elif $name == "egress" and .key == "net.ipv4.ip_unprivileged_port_start" and ((.value | tostring | tonumber) >= 25) then empty
            else "\($name) が sysctls を変えている（\(.key)=\(.value)。許すのは caddy と egress の net.ipv4.ip_unprivileged_port_start だけ）" end ),
        ( if (($s.network_mode // "") | startswith("host")) or ($s.pid // "") == "host" or ($s.ipc // "") == "host" then "\($name) がホストの網・pid・ipc を使う" else empty end ),
        ( if (($s.user // "") | test("^(|root|0)(:.*)?$")) then "\($name) の利用者が決まっていない・root（user: \($s.user // "未設定")）" else empty end ),
        # 3. app 系
        ( if (php | index($name)) != null then
            ( if $s.image != "ec-sugar:check" then "\($name) のイメージが EC_SUGAR_IMAGE でない（\($s.image)）" else empty end ),
            ( if $s.build != null then "\($name) がここでイメージを作る（build）。印つきのイメージを外から渡す" else empty end ),
            ( if $s.user != "www-data" then "\($name) が www-data で動かない（\($s.user // "未設定")）" else empty end ),
            ( if (($s.tmpfs // []) | map(startswith("/tmp:") or . == "/tmp") | any) | not then "\($name) に /tmp の tmpfs が無い" else empty end ),
            ( if ($s.volumes // []) | map(select(.target == "/var/www/html/storage" and .type == "volume")) | length != 1 then "\($name) の storage が名前つきの置き場でない" else empty end ),
            ( if ($s.volumes // []) | map(select(.type == "bind")) | length > 0 then "\($name) がホストの道筋を差し込んでいる（コードはイメージの中だけ）" else empty end )
          else empty end ),
        # 4. バージョンの固定（app 系は EC_SUGAR_IMAGE なので 3 で見る。出口の代理〔egress〕も同じイメージで、14 で見る）
        ( if (php | index($name)) == null and $name != "egress" then
            ( ($s.image // "") as $img
              | if ($img | test("@sha256:[0-9a-f]{64}$")) then empty
                elif ($img | test(":[^/:]+$") | not) then "\($name) のイメージにバージョンが無い（\($img)。latest になる）"
                elif ($img | test(":v?[0-9]+\\.[0-9]+[^:/]*$") | not) then "\($name) のイメージのバージョンが固定されていない（\($img)。「数.数」で始まるバージョンにする）"
                else empty end )
          else empty end ),
        # 6. 内側の網
        ( if (["db", "redis"] | index($name)) != null then
            ( ($s.networks // {}) | keys[] | select(($nets[.].internal // false) != true) | "\($name) が外に出られる網 \(.) にいる（内側の網だけにする）" )
          else empty end )
      )
'
problems="$(jq -r --arg bind "$sentinel" "$base_rules" "$work/config.json")"

if [ -n "$problems" ]; then
    while IFS= read -r line; do ng "$line"; done <<< "$problems"
else
    ok "ポート・権限・利用者・イメージのバージョン・網の決まりを満たす（$(jq -r '.services | keys | join("・")' "$work/config.json")）"
fi

# app 系のイメージは、渡した印のまま（compose が latest を足さない）。latest を渡したら上の 3 では通るので、ここで断る決まりを見る
if [ "$(jq -r '[.services.app.image, .services.queue.image, .services.scheduler.image] | unique | length' "$work/config.json")" != 1 ]; then
    ng "app・queue・scheduler のイメージが同じでない"
fi
if grep -Eq '^EC_SUGAR_IMAGE=.*:latest$' "$example"; then ng "見本の EC_SUGAR_IMAGE が latest"; fi

# 5. env ファイルに APP_DEBUG=true・APP_ENV=local と書いても、解いた結果は false・production
sed -e 's/^APP_DEBUG=.*/APP_DEBUG=true/' -e 's/^APP_ENV=.*/APP_ENV=local/' "$example" > "$work/debug.env"
if resolve "$work/debug.env" > "$work/debug.json" 2> "$work/err.txt"; then
    wrong="$(jq -r '.services | to_entries[] | select(.key == "app" or .key == "queue" or .key == "scheduler")
        | select(.value.environment.APP_DEBUG != "false" or .value.environment.APP_ENV != "production")
        | "\(.key)（APP_DEBUG=\(.value.environment.APP_DEBUG) APP_ENV=\(.value.environment.APP_ENV)）"' "$work/debug.json")"
    [ -z "$wrong" ] && ok "env ファイルに APP_DEBUG=true と書いても app・queue・scheduler は false・production" || ng "APP_DEBUG・APP_ENV が env ファイルで変わる: $wrong"

    # 6. DB の管理者の合言葉はアプリに渡らない
    leaked="$(jq -r '.services | to_entries[] | select(.key == "app" or .key == "queue" or .key == "scheduler")
        | select((.value.environment.EC_SUGAR_DB_SUPERUSER_PASSWORD // "") != "" or (.value.environment.SUGAR_EC_DB_SUPERUSER_PASSWORD // "") != ""
            or (.value.environment.POSTGRES_PASSWORD // "") != "") | .key' "$work/debug.json")"
    [ -z "$leaked" ] && ok "DB の管理者の合言葉は app・queue・scheduler に渡らない" || ng "DB の管理者の合言葉が渡る: $leaked"
else
    cat "$work/err.txt"
    ng "APP_DEBUG=true の env ファイルで compose が解けない"
fi
# 合言葉を入れた env ファイルでも、アプリに渡らないこと（上は空の見本なので、値を入れて見る）。
# 前の名前（SUGAR_EC_…）で入れたサーバーの env ファイルには、移した後も前の名前の行が残るので、その形（前の名前の行を足したもの）も見る
sed -e 's/^EC_SUGAR_DB_SUPERUSER_PASSWORD=.*/EC_SUGAR_DB_SUPERUSER_PASSWORD=check-only-superuser/' "$example" > "$work/secret.env"
{ cat "$work/secret.env"; printf 'SUGAR_EC_DB_SUPERUSER_PASSWORD=check-only-superuser\n'; } > "$work/secret-old.env"
for f in secret secret-old; do
    if resolve "$work/$f.env" > "$work/$f.json" 2> /dev/null; then
        grep -q 'check-only-superuser' <(jq -c '.services | {app, queue, scheduler, web, redis}' "$work/$f.json") &&
            ng "env ファイル（${f}）に書いた DB の管理者の合言葉が db 以外に渡る" || ok "env ファイル（${f}）に書いた DB の管理者の合言葉は db にだけ渡る"
    else
        ng "env ファイル（${f}）で compose が解けない"
    fi
done

# 入れ子の既定値の内側に必須の確かめ（:? と ?）を置かない: compose 2.40 系（Ubuntu の部品）は外側に値があっても内側を確かめて止まり、
# 前の名前だけの env のサーバーで更新が壊れる（5.x では止まらないので、手元の版では気づけない。書き方そのものを見る）
nested_req="$(grep -nE '\$\{[A-Z0-9_]+:?-\$\{[A-Z0-9_]+:?\?' "$compose_file" docker-compose.proxy.yml docker-compose.tunnel.yml 2> /dev/null || true)"
[ -z "$nested_req" ] && ok "入れ子の既定値の内側に必須の確かめ（:?）が無い（compose 2.40 系でも前の名前だけの env で解ける）" ||
    ng "入れ子の既定値の内側に必須の確かめ（:?）がある（compose 2.40 系は外側に値があっても止まる。必須は ec-sugar の ec_dc で確かめる）: $(printf '%s' "$nested_req" | cut -c1-120 | tr '\n' ' ')"

# 1. EC_SUGAR_BIND が無いなら止まる（0.0.0.0 に倒れない）
grep -v '^EC_SUGAR_BIND=' "$example" > "$work/nobind.env"
if env -u EC_SUGAR_BIND -u SUGAR_EC_BIND EC_SUGAR_ENV_FILE="$work/nobind.env" EC_SUGAR_IMAGE=ec-sugar:check DB_PASSWORD=x REDIS_PASSWORD=x EC_SUGAR_DB_SUPERUSER_PASSWORD=x \
    docker compose -f "$compose_file" --env-file "$work/nobind.env" config --format json > /dev/null 2>&1; then
    ng "EC_SUGAR_BIND が無くても compose が通る（0.0.0.0 に出てしまう）"
else
    ok "EC_SUGAR_BIND が無ければ compose が止まる"
fi

# 7. 見本の APP_DEBUG と、本物の env ファイルが git に入らないこと
grep -qx 'APP_DEBUG=false' "$example" && ok "見本の APP_DEBUG は false" || ng "見本の APP_DEBUG が false でない"
if git rev-parse --is-inside-work-tree > /dev/null 2>&1; then
    git check-ignore -q .env.production && ok ".env.production は git に入らない" || ng ".env.production が .gitignore で除かれていない"
    git check-ignore -q "$example" && ng "$example が .gitignore で除かれている（見本は入れる）" || ok "$example は git に入る"
fi

# 8. 入口の 2 段（ADR 0031）
# 住所と範囲は解いた結果から読む（既定は compose にあり、env ファイルに書いたときはそれが効く）
caddy_addr="$(jq -r '.services.caddy.networks.front.ipv4_address // ""' "$work/config.json")"
front_subnet="$(jq -r '[(.networks.front.ipam.config // [])[] | .subnet][0] // ""' "$work/config.json")"
front_range="$(jq -r '[(.networks.front.ipam.config // [])[] | .ip_range][0] // ""' "$work/config.json")"
# IPv4 の住所が範囲の中か（$1 住所 $2 範囲）
ip4_num() { local IFS=. a b c d; read -r a b c d <<< "$1"; echo $(((a << 24) | (b << 16) | (c << 8) | d)); }
in_cidr4() {
    local net="${2%/*}" len="${2#*/}" mask
    mask=$(((0xFFFFFFFF << (32 - len)) & 0xFFFFFFFF))
    [ $(($(ip4_num "$1") & mask)) -eq $(($(ip4_num "$net") & mask)) ]
}
cidr_re='^[0-9]+(\.[0-9]+){3}/[0-9]+$'
# 網の住所（先頭）・gateway（Docker の既定は 1 つ目。決めてあればその住所）・ブロードキャスト（末尾）は Caddy の住所にできない
special_addr() {
    local net="${front_subnet%/*}" len="${front_subnet#*/}" n a gw
    n=$(($(ip4_num "$net") & ((0xFFFFFFFF << (32 - len)) & 0xFFFFFFFF)))
    a="$(ip4_num "$caddy_addr")"
    gw="$(jq -r '[(.networks.front.ipam.config // [])[] | .gateway // empty][0] // ""' "$work/config.json")"
    [ "$a" -eq "$n" ] || [ "$a" -eq $((n + 1)) ] || [ "$a" -eq $((n + (1 << (32 - len)) - 1)) ] || { [ -n "$gw" ] && [ "$a" -eq "$(ip4_num "$gw")" ]; }
}
if [[ "$caddy_addr" =~ ^[0-9]+(\.[0-9]+){3}$ ]] && [[ "$front_subnet" =~ $cidr_re ]] && [[ "$front_range" =~ $cidr_re ]] &&
    in_cidr4 "$caddy_addr" "$front_subnet" && in_cidr4 "${front_range%/*}" "$front_subnet" && ! in_cidr4 "$caddy_addr" "$front_range" && ! special_addr; then
    ok "Caddy の住所 ${caddy_addr} は入口の前の網 ${front_subnet} の中で、自動で配る範囲 ${front_range} の外"
else
    # 自動で配る範囲の中だと、先に起きた web がその住所を取り、caddy が起きない（2026-10-29 に使い捨ての Ubuntu で起きた）
    ng "Caddy の住所 EC_SUGAR_CADDY_ADDR（${caddy_addr}）が EC_SUGAR_FRONT_SUBNET（${front_subnet}）の中で EC_SUGAR_FRONT_RANGE（${front_range}）の外になっていない（網の住所・gateway・ブロードキャストも使えない）"
fi

problems="$(jq -r --arg addr "$caddy_addr" --arg subnet "$front_subnet" --arg range "$front_range" '
    def caddyEnv: ["EC_SUGAR_ADMIN_HOST", "EC_SUGAR_SHOP_DOMAIN", "EC_SUGAR_SHOPS", "EC_SUGAR_SITE_SHOP", "EC_SUGAR_TLS_MODE", "EC_SUGAR_ACME_CA", "EC_SUGAR_ACME_CA_ROOT", "EC_SUGAR_RELEASE"];
    .services as $sv | .networks as $nets
    | if ($sv.caddy // null) == null then "入口の caddy が無い" else
      ( if (($sv.caddy.networks // {}) | keys) != ["front"] then "caddy が front の網だけにいない（\(($sv.caddy.networks // {}) | keys | join("・"))）" else empty end ),
      ( if $addr == "" then "caddy の住所が固定されていない（ipv4_address: EC_SUGAR_CADDY_ADDR）" else empty end ),
      ( if ($sv.web.environment.EC_SUGAR_CADDY_ADDR // "") != $addr then "nginx の内側の口が受ける住所（web の EC_SUGAR_CADDY_ADDR）が caddy の住所（\($addr)）と違う" else empty end ),
      ( if ([($sv.web.volumes // [])[] | select(.target == "/etc/nginx/templates/front.inc.template" and ((.source // "") | endswith("/docker/nginx/front-caddy.inc")) and .read_only == true)] | length) != 1
        then "ふつうの形で nginx の外の口が front-caddy.inc（8080 番・Caddy の住所だけを信じる）でない" else empty end ),
      ( if [($sv.web.volumes // [])[] | select(.target == "/run/ec-sugar-front")] | length > 0 then "ふつうの形で web にソケットの置き場が差し込まれている" else empty end ),
      ( if ([($nets.front.ipam.config // [])[] | select(.subnet == $subnet and .ip_range == $range)] | length) != 1 then "front の網の範囲が EC_SUGAR_FRONT_SUBNET（\($subnet)）と自動で配る範囲 EC_SUGAR_FRONT_RANGE（\($range)）でない" else empty end ),
      ( if ($nets.front.internal // false) then "front の網が内側だけ（caddy が証明書を取りに外へ出られない）" else empty end ),
      ( [$sv | to_entries[] | select((.value.networks // {}) | has("front")) | .key] | sort
        | if . != ["caddy", "web"] then "front の網にいるのが caddy と web だけでない（\(join("・"))）" else empty end ),
      ( $sv | to_entries[] | select(.key != "caddy") | select((.value.networks.front.ipv4_address // "") != "")
        | "\(.key) が front の網で住所を固定している（固定してよいのは caddy だけ。nginx が信じる住所を取り合う）" ),
      ( if (($sv.web.networks // {}) | keys | sort) != ["edge", "front"] then "web の網が front と edge でない（\(($sv.web.networks // {}) | keys | join("・"))）" else empty end ),
      ( $sv | to_entries[] | select(.key != "caddy") | .key as $n | (.value.volumes // [])[]
        # 例外は mailpit の自分のパスワードのハッシュの置き場だけ（11 で見る）
        | select(.source == "caddy_data" or (((.target // "") | startswith("/run/secrets")) and ($n != "mailpit" or .target != "/run/secrets/mailpit")))
        | "\($n) に証明書の置き場か入口の秘密（\(.target)）が差し込まれている（caddy だけ）" ),
      ( if ([($sv.caddy.volumes // [])[] | select(.type == "volume" and .source == "caddy_data" and .target == "/data")] | length) != 1 then "caddy の証明書の置き場が名前つきの caddy_data でない" else empty end ),
      ( [($sv.caddy.volumes // [])[] | select(.target == "/run/secrets/ec-sugar")] as $sec
        | if ($sec | length) != 1 then "caddy に入口の秘密の置き場（/run/secrets/ec-sugar）が無い"
          elif ($sec[0].read_only // false) != true then "入口の秘密の置き場が読み取り専用でない" else empty end ),
      ( ($sv.caddy.volumes // [])[] | select(.target == "/etc/caddy") | if (.read_only // false) != true then "Caddy の設定が読み取り専用でない" else empty end ),
      ( ($sv.caddy.environment // {}) | keys[] as $k | select((caddyEnv | index($k)) == null) | "caddy に決めていない環境変数 \($k) がある（鍵は環境変数でなくファイルで渡す）" ),
      ( ["app", "queue", "scheduler"][] as $n | if ($sv[$n].environment.EC_SUGAR_TLS_ASK // "未設定") != "" then "\($n) の環境変数 EC_SUGAR_TLS_ASK が空でない（証明書の問い合わせの印を環境変数で偽れる）" else empty end )
      end
' "$work/config.json")"
if [ -n "$problems" ]; then
    while IFS= read -r line; do ng "$line"; done <<< "$problems"
else
    ok "入口の 2 段: caddy は front の網だけ・住所を固定・証明書と秘密は caddy だけ・環境変数に鍵なし、web は front と edge、問い合わせの印は空"
fi

# caddy のイメージの札と Dockerfile のバージョン、土台の digest
caddy_df="docker/caddy/Dockerfile"
cv="$(sed -n 's/^ARG CADDY_VERSION=//p' "$caddy_df")"
cfv="$(sed -n 's/^ARG CLOUDFLARE_VERSION=//p' "$caddy_df")"
crev="$(sed -n 's/^ARG IMAGE_REVISION=//p' "$caddy_df")"
want_image="ec-sugar-caddy:${cv}-cf${cfv}-r${crev}"
[ "$(jq -r '.services.caddy.image' "$work/config.json")" = "$want_image" ] && ok "caddy のイメージの札は Dockerfile のバージョンとそろう（${want_image}）" ||
    ng "caddy のイメージの札が Dockerfile のバージョン（${want_image}）とそろわない（Dockerfile を変えたら札も変える。そろわないと古いイメージのまま動く）"
[[ "$(jq -r '.services.caddy.build.context // ""' "$work/config.json")" == */docker/caddy ]] || ng "caddy の作り方の置き場が docker/caddy でない"
froms="$(grep -E '^FROM ' "$caddy_df")"
bad_from="$(printf '%s\n' "$froms" | grep -Ev "^FROM caddy:${cv//./\\.}-[a-z-]+@sha256:[0-9a-f]{64}( AS [a-z]+)?$" || true)"
[ -n "$froms" ] && [ -z "$bad_from" ] && ok "caddy の Dockerfile の土台は同じバージョンで digest まで固定" || ng "caddy の Dockerfile の FROM がバージョン $cv と digest で固定されていない: ${bad_from:-FROM が無い}"
# アプリのイメージの土台も版と digest まで固定する（同じコミットから同じ中身を組み直せること。署名して配る前提）
app_froms="$(grep -E '^FROM ' docker/Dockerfile)"
# 前に名前を付けた段を土台にする行（FROM php-base AS app）は外から取らないので通す。外のイメージは版（3 つの番号）と digest まで固定
app_stages="$(printf '%s\n' "$app_froms" | sed -nE 's/^FROM .* AS ([a-z][a-z0-9-]*)$/\1/p' | paste -sd'|' -)"
bad_app_from="$(printf '%s\n' "$app_froms" | grep -Ev '^FROM [a-z0-9./-]+:[0-9]+\.[0-9]+\.[0-9]+[a-z0-9.-]*@sha256:[0-9a-f]{64} AS [a-z][a-z0-9-]*$' | grep -Ev "^FROM (${app_stages:-.^}) AS [a-z][a-z0-9-]*$" || true)"
[ -n "$app_froms" ] && [ -z "$bad_app_from" ] && ok "アプリの Dockerfile の土台は版（3 つの番号）と digest まで固定" || ng "アプリの Dockerfile の FROM が版と digest で固定されていない: ${bad_app_from:-FROM が無い}"

if grep -Eq '^ENV GOFLAGS=-mod=readonly$' "$caddy_df" && grep -q 'go env GOSUMDB' "$caddy_df"; then
    ok "caddy の作る段は Go の部品の控えを確かめ、go.mod を書き換えない"
else
    ng "caddy の Dockerfile の作る段に GOFLAGS=-mod=readonly と GOSUMDB の確かめが無い"
fi
grep -q "部品 v${cfv} の確かめ方" deploy/lib/common.sh || ng "インストーラーの DNS の鍵の確かめ（deploy/lib/common.sh の ec_valid_cf_token）が Cloudflare の部品 v${cfv} に合わせたものでない"

# nginx: 接続元の見出しと内側の口は Caddy の住所からだけ。外の入口は /internal/ を開かず、印を空にする。TLS は受けない
nginx_conf="docker/nginx/prod.conf"
# 外の口と信じる相手は形ごとの読み込み（front-*.inc）。prod.conf に直に書かない
front_caddy="docker/nginx/front-caddy.inc"
front_proxy="docker/nginx/front-proxy.inc"
fc="$(grep -Ev '^\s*#' "$front_caddy" | grep -Ev '^\s*$' || true)"
fp="$(grep -Ev '^\s*#' "$front_proxy" | grep -Ev '^\s*$' || true)"
if grep -Eq '^\s*set_real_ip_from ' "$nginx_conf" || ! grep -Eq '^\s*include /etc/nginx/conf\.d/front\.inc;$' "$nginx_conf"; then
    ng "nginx の外の口の set_real_ip_from が prod.conf に直に書かれているか、front.inc を読み込んでいない"
elif [ "$(printf '%s\n' "$fc" | grep -E '^\s*(listen|set_real_ip_from) ')" != $'listen 8080;\nset_real_ip_from ${EC_SUGAR_CADDY_ADDR};' ]; then
    ng "front-caddy.inc が「8080 番で受け、Caddy の住所だけを信じる」でない"
elif [ "$(printf '%s\n' "$fp" | grep -E '^\s*(listen|set_real_ip_from) ')" != $'listen unix:/run/ec-sugar-front/web.sock;\nset_real_ip_from unix:;' ]; then
    ng "front-proxy.inc が「Unix ソケットだけで受け、ソケットから来た要求だけを信じる」でない（TCP で受けると同じサーバーの別の利用者が接続元を偽れる）"
elif ! printf '%s\n' "$fp" | q -E '^\s*if \(\$http_x_forwarded_proto != "https"\) \{$'; then
    ng "front-proxy.inc が、https の印の無い要求を https へ送っていない"
else
    ok "nginx は接続元の見出しを、ふつうの形は Caddy の住所から、後ろに置く形はソケットからだけ信じる"
fi
grep -Eq '^\s*real_ip_recursive on' "$nginx_conf" && ng "nginx が real_ip_recursive on（見出しの前の方の住所を信じる）" || true
grep -Eq '^\s*real_ip_header X-Forwarded-For;' "$nginx_conf" || ng "nginx の real_ip_header が X-Forwarded-For でない"
grep -Eq '^\s*location \^~ /internal/ \{ return 404; \}' "$nginx_conf" && ok "nginx の外の入口は /internal/ を 404 にする" || ng "nginx の外の入口が /internal/ を断っていない"
grep -Eq '^\s*fastcgi_param EC_SUGAR_TLS_ASK "";' "$nginx_conf" || ng "nginx の外の入口が問い合わせの印 EC_SUGAR_TLS_ASK を空にしていない"
[ "$(grep -Ec '^\s*fastcgi_param EC_SUGAR_TLS_ASK 1;' "$nginx_conf")" = 1 ] || ng "問い合わせの印 EC_SUGAR_TLS_ASK=1 を付ける所が 1 つでない"
inner="$(awk '/^server \{/ {buf = ""; inblk = 1} inblk {buf = buf $0 "\n"} /^\}/ && inblk {if (buf ~ /listen 8081/) printf "%s", buf; inblk = 0}' "$nginx_conf")"
if printf '%s' "$inner" | q -E '^\s*allow \$\{EC_SUGAR_CADDY_ADDR\};' && printf '%s' "$inner" | q -E '^\s*deny all;' &&
    [ -z "$(printf '%s' "$inner" | grep -E '^\s*allow ' | grep -v 'EC_SUGAR_CADDY_ADDR' || true)" ] && printf '%s' "$inner" | q 'EC_SUGAR_TLS_ASK 1;'; then
    ok "nginx の内側の口（8081）は Caddy の住所からだけ、印はここだけで付ける"
else
    ng "nginx の内側の口（8081）が Caddy の住所だけに絞られていない（allow \${EC_SUGAR_CADDY_ADDR}; と deny all;）"
fi
if printf '%s' "$inner" | q -E '^\s*limit_req zone=ec_sugar_tls_ask burst=[0-9]+ nodelay;' && printf '%s' "$inner" | q -E '^\s*limit_req_status 429;' &&
    grep -Eq '^limit_req_zone \$binary_remote_addr zone=ec_sugar_tls_ask:[0-9]+m rate=[0-9]+r/s;' "$nginx_conf"; then
    ok "nginx の内側の口（8081）は回数を絞る（超えたら 429）"
else
    ng "nginx の内側の口（8081）に回数の制限（limit_req と 429）が無い"
fi
askmap="$(awk '/^map \$arg_domain \$ec_sugar_tls_ask_name \{/,/^\}/' "$nginx_conf" | grep -Ev '^\s*#')"
if printf '%s' "$inner" | q -E '^\s*if \(\$ec_sugar_tls_ask_name = 0\) \{ return 404; \}' &&
    [ "$(printf '%s\n' "$askmap" | grep -Ec ' 1;$')" = 2 ] && printf '%s\n' "$askmap" | q -E '^\s*default 0;' &&
    printf '%s\n' "$askmap" | q -E '^\s*\$\{EC_SUGAR_ADMIN_HOST\} 1;' && printf '%s\n' "$askmap" | q -E '^\s*\*\.\$\{EC_SUGAR_SHOP_DOMAIN\} 1;'; then
    ok "nginx の内側の口は、管理画面と *.<ショップのドメイン> 以外の名前を PHP の前で 404 にする"
else
    ng "nginx の内側の口の名前の絞り込み（map の \$ec_sugar_tls_ask_name。管理画面と *.<ショップのドメイン> だけ 1、既定 0）が無い"
fi
grep -Eq '^\s*location ~ \\\.php/ \{ return 404; \}' "$nginx_conf" || ng "nginx の外の入口が「.php/ の後ろの道筋」（/index.php/internal/… など）を断っていない"
grep -Eq '^\s*listen [0-9]+ .*ssl' "$nginx_conf" && ng "nginx が TLS を受けている（https は前の段の Caddy が受ける）" || true
# 本文の上限: ふだんは 1m（ショップ・通知・ログイン）。大きな本文は管理画面のホストの API（12m）と商品の CSV の口（100m）だけで、
# どちらも管理画面のホストでない要求を 404 にする。開発の nginx（default.conf）も同じ値と形。上限の値の並び（server → /api/ → CSV → 8081）で見る
body_sizes() { grep -E '^[[:space:]]*client_max_body_size ' "$1" | sed -E 's/^[[:space:]]*client_max_body_size ([^;]+);.*/\1/' | tr '\n' ' '; }
loc_block() { awk -v head="$2" 'index($0, head) == 1 {inblk = 1} inblk {print} inblk && /^    \}/ {inblk = 0}' "$1"; }
dev_conf="docker/nginx/default.conf"
if [ "$(body_sizes "$nginx_conf")" != "1m 12m 100m 1k " ]; then
    ng "nginx（prod.conf）の本文の上限が「ふだん 1m・管理画面の API 12m・商品の CSV 100m・内側の口 1k」でない（$(body_sizes "$nginx_conf")）"
elif [ "$(body_sizes "$dev_conf")" != "1m 12m 100m " ]; then
    ng "開発の nginx（default.conf）の本文の上限が本番と同じ「1m・12m・100m」でない（$(body_sizes "$dev_conf")）"
elif ! loc_block "$nginx_conf" '    location /api/ {' | q -E '^[[:space:]]*client_max_body_size 12m;' ||
    ! loc_block "$nginx_conf" '    location /api/ {' | q -F 'if ($host != ${EC_SUGAR_ADMIN_HOST}) { return 404; }' ||
    ! loc_block "$nginx_conf" '    location ~ ^/api/tenants/' | q -E '^[[:space:]]*client_max_body_size 100m;' ||
    ! loc_block "$nginx_conf" '    location ~ ^/api/tenants/' | q -F 'if ($host != ${EC_SUGAR_ADMIN_HOST}) { return 404; }'; then
    ng "nginx（prod.conf）の大きな本文の口（/api/ の 12m・商品の CSV の 100m）が、管理画面のホストでない要求を 404 にしていない"
elif ! loc_block "$dev_conf" '    location /api/ {' | q -F 'if ($host != admin.localhost) { return 404; }' ||
    ! loc_block "$dev_conf" '    location ~ ^/api/tenants/' | q -F 'if ($host != admin.localhost) { return 404; }'; then
    ng "開発の nginx（default.conf）の大きな本文の口が、管理画面のホスト（admin.localhost）でない要求を 404 にしていない"
elif ! grep -Eq '^JsonBodyLimit::guard\(\);$' public/index.php; then
    ng "PHP の入口（public/index.php）が JSON の本文の大きさを Request::capture() の前に見ていない（JsonBodyLimit::guard()）"
else
    ok "本文の上限はふだん 1m、管理画面のホストの API だけ 12m・商品の CSV だけ 100m（開発の nginx も同じ）。JSON は PHP の入口で 2 MB まで"
fi

# Caddyfile: 管理の API を切る・前の代理を信じない・Host のすり替えを断る・問い合わせの先・取り方ごとの設定
caddyfile="docker/caddy/etc/Caddyfile"
grep -Eq '^\s*admin off$' "$caddyfile" && ok "Caddy の管理の API は切ってある" || ng "Caddyfile に admin off が無い（管理の API で設定を書き換えられる）"
# 前の代理を信じるか（ADR 0052）と SNI と Host の違いを断るか: Caddyfile の servers が入口の形ごとの front-<形>.servers を読み込む。
# 信じる指示（trusted_proxies・client_ip_headers）を書いてよいのは front-tunnel.servers だけで、中身は「cloudflared の住所 /32 から来た接続のときだけ
# CF-Connecting-IP」と「SNI と Host の違いを断らない（strict_sni_host insecure_off）」ちょうど。ふつうの形（front-caddy.servers）は
# strict_sni_host on だけ。strict_sni_host はこの 2 つのほか（Caddyfile・取り方ごとの設定）に書かない（後に書いた方が勝ち、形ごとの値を消すため）。
# 形の置き場は caddy と tunnel の 2 つだけ（知らない形の値では読み込みに失敗して Caddy が起きない）
strip_conf() { grep -Ev '^\s*#' "$1" | grep -Ev '^\s*$' | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//' || true; }
servers_files="$(cd docker/caddy/etc && ls front-*.servers 2> /dev/null | tr '\n' ' ')"
if ! grep -Eq '^\s*import /etc/caddy/front-\{\$EC_SUGAR_FRONT:caddy\}\.servers$' "$caddyfile"; then
    ng "Caddyfile の servers が入口の形ごとの設定（import /etc/caddy/front-{\$EC_SUGAR_FRONT:caddy}.servers）を読み込んでいない"
elif [ "$servers_files" != "front-caddy.servers front-tunnel.servers " ]; then
    ng "入口の形ごとの設定が front-caddy.servers と front-tunnel.servers の 2 つでない（${servers_files}）"
elif [ "$(strip_conf docker/caddy/etc/front-caddy.servers)" != 'strict_sni_host on' ]; then
    ng "front-caddy.servers が strict_sni_host on だけでない（ふつうの形は前の代理を信じず、SNI と Host が違えば断る）: $(strip_conf docker/caddy/etc/front-caddy.servers | tr '\n' ' ')"
elif [ "$(strip_conf docker/caddy/etc/front-tunnel.servers)" != $'trusted_proxies static {$EC_SUGAR_TUNNEL_ADDR}/32\nclient_ip_headers CF-Connecting-IP\nstrict_sni_host insecure_off' ]; then
    ng "front-tunnel.servers が「cloudflared の住所 /32 から来た接続のときだけ CF-Connecting-IP を信じる・SNI と Host の違いは断らない」ちょうどでない: $(strip_conf docker/caddy/etc/front-tunnel.servers | tr '\n' ' ')"
elif for f in docker/caddy/etc/*; do [ "$f" = docker/caddy/etc/front-tunnel.servers ] || strip_conf "$f"; done | q -E 'trusted_proxies|client_ip_headers'; then
    ng "front-tunnel.servers のほかに、前の代理を信じる指示（trusted_proxies・client_ip_headers）がある"
elif for f in docker/caddy/etc/*; do case "$f" in docker/caddy/etc/front-caddy.servers | docker/caddy/etc/front-tunnel.servers) ;; *) strip_conf "$f" ;; esac; done | q 'strict_sni_host'; then
    ng "入口の形ごとの設定（front-*.servers）のほかに strict_sni_host がある（形ごとの値を上書きする）"
else
    ok "Caddy は前の代理を信じない。信じるのは Cloudflare Tunnel の形で cloudflared の住所 /32 から来た接続の CF-Connecting-IP だけ。SNI と Host が違えば 421（Tunnel の形だけ断らない）"
fi
# nginx へは Caddy が決めた接続元だけを渡す（外から来た X-Forwarded-For・CF-Connecting-IP・True-Client-IP を残さない）
edge_block="$(awk '/^\(edge\) \{/,/^\}/' "$caddyfile" | grep -Ev '^\s*#')"
if printf '%s\n' "$edge_block" | q -E '^\s*header_up X-Forwarded-For \{client_ip\}$' &&
    printf '%s\n' "$edge_block" | q -E '^\s*header_up -CF-Connecting-IP$' && printf '%s\n' "$edge_block" | q -E '^\s*header_up -True-Client-IP$'; then
    ok "Caddy は nginx へ X-Forwarded-For を自分が決めた接続元（{client_ip}）で渡し、CF-Connecting-IP・True-Client-IP を消す"
else
    ng "Caddyfile の (edge) に header_up X-Forwarded-For {client_ip}・header_up -CF-Connecting-IP・header_up -True-Client-IP が無い（外から来た接続元の見出しが nginx に届く）"
fi
grep -Eq '^\s*ask http://web:8081/internal/tls/allowed$' "$caddyfile" || ng "Caddyfile の on_demand_tls の ask が内側の口でない"
for mode in auto dns manual; do
    [ -f "docker/caddy/etc/tls-$mode.caddy" ] || ng "取り方 $mode の設定（docker/caddy/etc/tls-$mode.caddy）が無い"
done
grep -q 'on_demand' docker/caddy/etc/tls-dns.caddy docker/caddy/etc/tls-manual.caddy && ng "dns・manual の取り方でその場で取っている（on_demand は auto だけ）" || true
# auto の場所は管理画面とショップの 2 つだけ（ほかの名前は ask に届く前に断る）。どちらも issue-<店の数の形> を読み込み、
# その場で取る（on_demand）のは issue-multi の 1 か所だけ（1 店舗の形は起動のときに 2 つを取る。問い合わせの経路を使わない）。
# 複数の店の形で管理画面もその場で取るのは、ショップのドメインの下の管理画面が、ワイルドカードの場所に覆われて起動のときに取られないため
auto_conf="$(grep -Ev '^\s*#' docker/caddy/etc/tls-auto.caddy)"
sites="$(printf '%s\n' "$auto_conf" | grep -E '^[^[:space:](].*\{$' | sed 's/ *{$//')"
single_block="$(printf '%s\n' "$auto_conf" | awk '/^\(issue-single\) \{/,/^\}/')"
multi_block="$(printf '%s\n' "$auto_conf" | awk '/^\(issue-multi\) \{/,/^\}/')"
if [ "$sites" = $'{$EC_SUGAR_ADMIN_HOST}\n{$EC_SUGAR_SITE_SHOP}' ] && [ "$(printf '%s\n' "$auto_conf" | grep -c 'on_demand')" = 1 ] &&
    printf '%s\n' "$multi_block" | q 'on_demand' && [ -n "$single_block" ] && ! printf '%s\n' "$single_block" | q -E 'on_demand|tls' &&
    [ "$(printf '%s\n' "$auto_conf" | grep -cE '^\s*import issue-\{\$EC_SUGAR_SHOPS:single\}$')" = 2 ]; then
    ok "auto の場所は管理画面とショップだけ。その場で取るのは複数の店の形だけ（1 店舗の形は問い合わせを使わない。外の名前は握手で断る）"
else
    ng "auto の場所が管理画面とショップの 2 つだけでない、または on_demand が issue-multi の外にある・1 店舗の形でその場で取る（場所: $(printf '%s' "$sites" | tr '\n' ' ')）"
fi
case "$(jq -r '.services.caddy.environment.EC_SUGAR_SHOPS' "$work/config.json")" in
    single | multi) ;;
    *) ng "見本の EC_SUGAR_SHOPS が single・multi のどちらでもない" ;;
esac
grep -Eq '^\s*server_name \$\{EC_SUGAR_SERVER_NAMES\};$' "$nginx_conf" || ng "nginx の外の入口の server_name が EC_SUGAR_SERVER_NAMES でない（1 店舗は 2 つの名前ちょうど）"
case "$(jq -r '.services.caddy.environment.EC_SUGAR_TLS_MODE' "$work/config.json")" in
    auto | dns | manual) ;;
    *) ng "見本の EC_SUGAR_TLS_MODE が auto・dns・manual のどれでもない" ;;
esac

# 9. すでにある Web サーバーの後ろに置く形
proxy_file="docker-compose.proxy.yml"
if [ ! -f "$proxy_file" ]; then
    ng "$proxy_file が無い"
elif ! env EC_SUGAR_ENV_FILE="$example" EC_SUGAR_BIND="$sentinel" EC_SUGAR_IMAGE="ec-sugar:check" DB_PASSWORD=check-only REDIS_PASSWORD=check-only EC_SUGAR_FRONT_SOCKET_GID=990 \
    EC_SUGAR_DB_SUPERUSER_PASSWORD=check-only docker compose -f "$compose_file" -f "$proxy_file" --env-file "$example" --profile staging config --format json \
    > "$work/proxy.json" 2> "$work/err.txt"; then
    cat "$work/err.txt"
    ng "後ろに置く形（$proxy_file を重ねたもの）で docker compose config が通らない"
else
    problems="$(jq -r '
        .services as $sv
        | ( if ($sv.caddy.scale // 1) != 0 then "後ろに置く形で caddy が 0 個でない（80・443 番を取り合う）" else empty end ),
          ( $sv | to_entries[] | .key as $n | (.value.ports // [])[] | . as $p
            | if $n == "mailpit" then ( if ($p.host_ip // "") != "127.0.0.1" then "mailpit のポートの住所が 127.0.0.1 でない" else empty end )
              elif $n == "caddy" then empty
              else "後ろに置く形で \($n) がポート \($p.published) を出している（TCP で受けると、同じサーバーの別の利用者が接続元を偽れる。ソケットだけで受ける）" end ),
          ( [($sv.web.volumes // [])[] | select(.target == "/run/ec-sugar-front")] as $sock
            | if ($sock | length) != 1 then "後ろに置く形で web にソケットの置き場（/run/ec-sugar-front）が差し込まれていない"
              elif ($sock[0].type // "") != "bind" then "後ろに置く形のソケットの置き場がホストの置き場（bind）でない" else empty end ),
          ( if ([($sv.web.volumes // [])[] | select(.target == "/etc/nginx/templates/front.inc.template" and ((.source // "") | endswith("/docker/nginx/front-proxy.inc")) and .read_only == true)] | length) != 1
            then "後ろに置く形で nginx の外の口が front-proxy.inc（ソケットだけ）でない" else empty end ),
          ( if (($sv.web.group_add // []) | map(tostring) | index("990")) == null then "後ろに置く形で web がソケットの置き場のグループ（EC_SUGAR_FRONT_SOCKET_GID）を持たない" else empty end ),
          ( if (($sv.web.command // []) | join(" ") | test("umask 0002 && rm -f /run/ec-sugar-front/web.sock && exec /docker-entrypoint.sh nginx")) | not
            then "後ろに置く形で web の起こし方が、ソケットをグループで書ける形（umask 0002）・残ったソケットを消す形でない" else empty end )
    ' "$work/proxy.json")"
    if [ -n "$problems" ]; then
        while IFS= read -r line; do ng "$line"; done <<< "$problems"
    else
        ok "後ろに置く形: caddy は 0 個、TCP のポートを出さず、ホストのソケットの置き場だけで受ける（front-proxy.inc）"
    fi
    # 守り（読み取り専用・権限・利用者）は重ねた後も同じ
    if [ "$(jq -r '.services.web | [.read_only, (.cap_drop | index("ALL") != null), ((.security_opt // []) | index("no-new-privileges:true") != null)] | all' "$work/proxy.json")" != true ]; then
        ng "後ろに置く形で web の守り（読み取り専用・cap_drop ALL・no-new-privileges）が外れている"
    fi
fi

# 10. php-fpm を TCP で受けない・内側の網をホストから切る（ふつうの形と後ろに置く形の両方で）
check_fpm() {
    local json="$1" form="$2" problems
    problems="$(jq -r --arg form "$form" '
        .services as $sv | .volumes as $vols | .networks as $nets
        | ( if ($sv.app.environment.EC_SUGAR_FPM_LISTEN // "") != "/run/php-fpm/fpm.sock" then "\($form): app の php-fpm が Unix ソケットで受けていない（EC_SUGAR_FPM_LISTEN）" else empty end ),
          ( if (($sv.app.healthcheck.test // []) | join(" ") | test("9000")) then "\($form): app の確かめが 9000 番を見ている" else empty end ),
          ( $sv | to_entries[] | .key as $n | (.value.volumes // [])[] | select(.source == "fpm")
            | if $n == "app" then (if (.read_only // false) then "\($form): app の fpm の置き場が書けない" else empty end)
              elif $n == "web" then (if (.read_only // false) != true then "\($form): web の fpm の置き場が読み取り専用でない" else empty end)
              else "\($form): \($n) に php-fpm のソケットの置き場が差し込まれている（app と web だけ）" end ),
          ( if ([($sv.app.volumes // [])[] | select(.source == "fpm" and .target == "/run/php-fpm")] | length) != 1 then "\($form): app に php-fpm のソケットの置き場が無い" else empty end ),
          ( if ([($sv.web.volumes // [])[] | select(.source == "fpm" and .target == "/run/php-fpm")] | length) != 1 then "\($form): web に php-fpm のソケットの置き場が無い" else empty end ),
          ( if (($sv.web.group_add // []) | map(tostring) | index("82")) == null then "\($form): web が php-fpm のソケットのグループ（82）を持たない" else empty end ),
          ( ($vols.fpm.driver_opts // {}) as $o
            | if ($o.type // "") != "tmpfs" or (($o.o // "") | test("(^|,)mode=0?750(,|$)") | not) or (($o.o // "") | test("(^|,)uid=82(,|$)") | not)
              then "\($form): php-fpm のソケットの置き場 fpm が tmpfs・uid 82・0750 でない" else empty end ),
          ( if ($nets.internal.driver_opts // {}) != {} then "\($form): 内側の網の作り方の値（driver_opts）を変えている（動いているサーバーで網を作り直せず、更新が止まる）" else empty end )
    ' "$json")"
    if [ -n "$problems" ]; then
        while IFS= read -r line; do ng "$line"; done <<< "$problems"
    else
        ok "$form: php-fpm は TCP で受けず、app と web だけが分け合うソケットだけ"
    fi
}
check_fpm "$work/config.json" "ふつうの形"
[ -s "$work/proxy.json" ] && check_fpm "$work/proxy.json" "後ろに置く形"
passes="$(grep -Ec '^\s*fastcgi_pass ' "$nginx_conf")"
if grep -Eq '^\s*fastcgi_pass ' "$nginx_conf" && [ -z "$(grep -E '^\s*fastcgi_pass ' "$nginx_conf" | grep -Ev '^\s*fastcgi_pass unix:/run/php-fpm/fpm\.sock;$' || true)" ] &&
    [ "$(grep -Ec '^\s*fastcgi_param PHP_VALUE "";$' "$nginx_conf")" = "$passes" ] && [ "$(grep -Ec '^\s*fastcgi_param PHP_ADMIN_VALUE "";$' "$nginx_conf")" = "$passes" ]; then
    ok "nginx は php-fpm へソケットだけで渡し、PHP_VALUE・PHP_ADMIN_VALUE を空にする"
else
    ng "nginx の fastcgi_pass がソケット（unix:/run/php-fpm/fpm.sock）だけでないか、PHP_VALUE・PHP_ADMIN_VALUE を空にしていない"
fi
# php-fpm の既定もソケット（イメージをほかの形で動かしても TCP で受けない）。9000 番は開発の compose だけが渡す
if grep -Eq '^listen = \$\{EC_SUGAR_FPM_LISTEN:-/run/php-fpm/fpm\.sock\}$' docker/php/www.conf && grep -Eq '^listen\.mode = 0660$' docker/php/www.conf &&
    [ "$(grep -Ec '^listen ?=' docker/php/www.conf)" = 1 ]; then
    ok "php-fpm の既定の受け口は Unix ソケット（/run/php-fpm/fpm.sock、0660）"
else
    ng "docker/php/www.conf の listen の既定が Unix ソケット /run/php-fpm/fpm.sock でない（EC_SUGAR_FPM_LISTEN で選べる形・ソケットは 0660）"
fi
app_df="docker/Dockerfile"
if grep -Eiq '^\s*EXPOSE\s' "$app_df"; then
    ng "$app_df がポートを出している（EXPOSE。php-fpm はソケットだけで受ける）"
elif ! grep -Eq 'install -d -m 0750 -o www-data -g www-data /run/php-fpm' "$app_df"; then
    ng "$app_df が php-fpm のソケットの置き場 /run/php-fpm を www-data の 0750 で作っていない（既定のソケットを作れない）"
else
    ok "イメージはポートを出さず（EXPOSE なし）、php-fpm のソケットの置き場を www-data の 0750 で作る"
fi
# 開発の compose: nginx が app:9000 へ渡すなら、app だけが EC_SUGAR_FPM_LISTEN=9000 を持つ（既定がソケットなので、無いと開発の画面が開かない）
if dev_json="$(docker compose -f docker-compose.yml config --format json 2> "$work/err.txt")"; then
    dev_pass="$(grep -E '^[[:space:]]*fastcgi_pass ' docker/nginx/default.conf | sed -E 's/^[[:space:]]*fastcgi_pass ([^;]+);.*/\1/' | sort -u)"
    dev_listen="$(printf '%s' "$dev_json" | jq -r '.services.app.environment.EC_SUGAR_FPM_LISTEN // ""')"
    others="$(printf '%s' "$dev_json" | jq -r '.services | to_entries[] | select(.key != "app") | select((.value.environment.EC_SUGAR_FPM_LISTEN // "") != "") | .key')"
    if [ "$dev_pass" != "app:9000" ]; then
        ng "開発の nginx（docker/nginx/default.conf）の fastcgi_pass が app:9000 だけでない（${dev_pass}）。開発の compose の EC_SUGAR_FPM_LISTEN と合わせる"
    elif [ "$dev_listen" != 9000 ]; then
        ng "開発の compose の app が EC_SUGAR_FPM_LISTEN=9000 を渡していない（開発の nginx は app:9000 へ渡す。イメージの既定はソケット）"
    elif [ -n "$others" ]; then
        ng "開発の compose で app のほかにも EC_SUGAR_FPM_LISTEN を渡している（${others}）"
    else
        ok "開発の compose だけが php-fpm を 9000 番で受けさせ、開発の nginx の渡し先（app:9000）と合う"
    fi
else
    cat "$work/err.txt"
    ng "開発の compose（docker-compose.yml）で docker compose config が通らない"
fi

# 11. ステージングの Mailpit の画面は Basic 認証: 127.0.0.1 にはホストのどの利用者からも届くので、パスワードを求める。
#     渡すのはハッシュのファイルだけ（パスワードを環境変数で渡さない）、読み取り専用、ファイルが無ければ起きない（ホストに置き場を作らせない）、Mailpit にだけ差し込む
problems="$(jq -r '
    .services as $sv
    | if ($sv.mailpit // null) == null then "mailpit が無い（ステージングのメールの受け手）" else
      ( ($sv.mailpit.environment.MP_UI_AUTH_FILE // "") as $f
        | if $f != "/run/secrets/mailpit/ui.htpasswd" then "mailpit の画面に Basic 認証が無い（MP_UI_AUTH_FILE=/run/secrets/mailpit/ui.htpasswd）" else empty end ),
      ( ($sv.mailpit.environment // {}) | keys[] | select(. == "MP_UI_AUTH" or . == "MP_SEND_API_AUTH" or . == "MP_SMTP_AUTH")
        | "mailpit に認証の値を環境変数（\(.)）で渡している（docker inspect に出る。ファイルで渡す）" ),
      ( [($sv.mailpit.volumes // [])[] | select(.target == "/run/secrets/mailpit")] as $m
        | if ($m | length) != 1 then "mailpit にパスワードのハッシュの置き場（/run/secrets/mailpit）が差し込まれていない"
          elif ($m[0].type // "") != "bind" or ($m[0].read_only // false) != true then "mailpit のパスワードのハッシュの置き場が読み取り専用のホストの置き場（bind）でない"
          # 長い書き方の bind は create_host_path を書かなければ作らない（Compose の仕様）。docker compose の版によっては false を出さず
          # "bind": {} になるので、true のときだけ断る（ファイルには false を書いておく）
          elif ($m[0].bind.create_host_path // false) == true then "mailpit のパスワードのハッシュの置き場が、無ければホストに作られる（create_host_path: false にして、無ければ起きないようにする）"
          elif (($m[0].source // "") | test("secrets/caddy")) then "mailpit に入口の Caddy の秘密の置き場が差し込まれている"
          else empty end ),
      ( $sv | to_entries[] | select(.key != "mailpit") | .key as $n | (.value.volumes // [])[] | select((.target // "") == "/run/secrets/mailpit" or ((.source // "") | test("secrets/mailpit")))
        | "\($n) に Mailpit のパスワードの置き場が差し込まれている（mailpit だけ）" ),
      ( if (($sv.mailpit.ports // []) | map(.host_ip) | unique) != ["127.0.0.1"] then "mailpit の画面のポートが 127.0.0.1 だけでない" else empty end )
      end
' "$work/config.json")"
if [ -n "$problems" ]; then
    while IFS= read -r line; do ng "$line"; done <<< "$problems"
else
    ok "ステージングの Mailpit の画面は Basic 認証（ハッシュのファイルを読み取り専用で、無ければ起きない。環境変数でパスワードを渡さない）"
fi

# 12. Cloudflare Tunnel で出す形（docker-compose.tunnel.yml を重ねたもの。ADR 0052）
tunnel_file="docker-compose.tunnel.yml"
tunnel_dir_sentinel="/srv/ec-sugar/secrets/tunnel-check"
if [ ! -f "$tunnel_file" ]; then
    ng "$tunnel_file が無い"
elif ! env EC_SUGAR_ENV_FILE="$example" EC_SUGAR_BIND="$sentinel" EC_SUGAR_IMAGE="ec-sugar:check" DB_PASSWORD=check-only REDIS_PASSWORD=check-only \
    EC_SUGAR_DB_SUPERUSER_PASSWORD=check-only EC_SUGAR_TUNNEL_DIR="$tunnel_dir_sentinel" \
    docker compose -f "$compose_file" -f "$tunnel_file" --env-file "$example" --profile staging config --format json > "$work/tunnel.json" 2> "$work/err.txt"; then
    cat "$work/err.txt"
    ng "Cloudflare Tunnel の形（$tunnel_file を重ねたもの）で docker compose config が通らない"
else
    # ポート・権限・利用者・イメージのバージョン・内側の網（1〜4・6）は重ねた後も同じ決まり（cloudflared はポートを出さない・root でない・読み取り専用）
    problems="$(jq -r --arg bind "$sentinel" "$base_rules" "$work/tunnel.json")"
    if [ -n "$problems" ]; then
        while IFS= read -r line; do ng "Tunnel の形: $line"; done <<< "$problems"
    else
        ok "Tunnel の形: ポート・権限・利用者・イメージのバージョン・網の決まりを満たす（$(jq -r '.services | keys | join("・")' "$work/tunnel.json")）"
    fi
    tunnel_uid="$(sed -n 's/^EC_TUNNEL_UID=//p' deploy/lib/common.sh)"
    problems="$(jq -r --arg dir "$tunnel_dir_sentinel" --arg uid "$tunnel_uid" '
        def caddyEnv: ["EC_SUGAR_ADMIN_HOST", "EC_SUGAR_SHOP_DOMAIN", "EC_SUGAR_SHOPS", "EC_SUGAR_SITE_SHOP", "EC_SUGAR_TLS_MODE", "EC_SUGAR_ACME_CA", "EC_SUGAR_ACME_CA_ROOT", "EC_SUGAR_RELEASE", "EC_SUGAR_FRONT", "EC_SUGAR_TUNNEL_ADDR"];
        .services as $sv | .networks as $nets
        | ($sv.cloudflared // null) as $cf
        | if $cf == null then "Tunnel の形に cloudflared が無い" else
          ( if (($cf.image // "") | test("^cloudflare/cloudflared:[0-9]+\\.[0-9]+\\.[0-9]+@sha256:[0-9a-f]{64}$") | not) then "cloudflared のイメージがバージョンと digest まで固定されていない（\($cf.image // "")）" else empty end ),
          ( if ($cf.read_only // false) != true then "cloudflared の根が読み取り専用でない" else empty end ),
          ( if (($cf.cap_drop // []) | index("ALL")) == null then "cloudflared が cap_drop: [ALL] でない" else empty end ),
          ( if (($cf.security_opt // []) | index("no-new-privileges:true")) == null then "cloudflared に no-new-privileges:true が無い" else empty end ),
          # 利用者は root でも Caddy（65532）でもなく、インストーラーが置き場のグループにする番号（deploy/lib/common.sh の EC_TUNNEL_UID）ちょうど
          ( ($cf.user // "") as $u
            | if ($u | test("^(|root|0)(:.*)?$")) then "cloudflared の利用者が決まっていない・root（\($u)）"
              elif ($u | split(":")[0]) == (($sv.caddy.user // "") | split(":")[0]) or ($u | split(":")[0]) == "65532" then "cloudflared の利用者が Caddy と同じ（\($u)。別の利用者にする）"
              elif $u != "\($uid):\($uid)" then "cloudflared の利用者が \($u)（置き場のグループ EC_TUNNEL_UID=\($uid) と同じ \($uid):\($uid) にする）"
              else empty end ),
          ( if (($cf.ports // []) | length) > 0 then "cloudflared がポートを出している" else empty end ),
          # トークンはファイルで（--token-file）。環境変数・引数に出さない（ps・docker inspect に出る）
          ( ($cf.command // []) as $c
            | if ($c | index("--token-file")) == null or ($c | index("/etc/cloudflared/token")) == null then "cloudflared がトークンをファイル（--token-file /etc/cloudflared/token）で読んでいない"
              elif ($c | index("--token")) != null then "cloudflared の引数にトークン（--token）がある"
              elif ($c | index("--no-autoupdate")) == null then "cloudflared が自分で更新する（--no-autoupdate が無い。バージョンは compose で決める）"
              elif ($c | index("/etc/cloudflared/config.yml")) == null then "cloudflared が置き場の設定（/etc/cloudflared/config.yml）を読んでいない"
              else empty end ),
          ( ($cf.environment // {}) | keys[] | select(test("TOKEN"; "i")) | "cloudflared の環境変数に \(.) がある（トークンはファイルで渡す）" ),
          ( ($cf.volumes // []) as $v
            | if ($v | length) != 1 then "cloudflared に差し込むのが置き場 1 つだけでない（\($v | map(.target) | join("・"))）"
              elif $v[0].target != "/etc/cloudflared" or ($v[0].type // "") != "bind" or $v[0].source != $dir then "cloudflared の置き場が EC_SUGAR_TUNNEL_DIR（\($dir)）を /etc/cloudflared に差し込む形でない"
              elif ($v[0].read_only // false) != true then "cloudflared の置き場が読み取り専用でない" else empty end ),
          ( $sv | to_entries[] | select(.key != "cloudflared") | .key as $n | (.value.volumes // [])[]
            | select((.source // "") == $dir or (.target // "") == "/etc/cloudflared") | "\($n) に cloudflared の置き場が差し込まれている（cloudflared だけ）" ),
          ( if ($cf.healthcheck.test // null) == null then "cloudflared にヘルスチェックが無い（Cloudflare につながったかを見られない）" else empty end ),
          # 網: tunnel は内側だけ（外へ出られない）で、いるのは caddy と cloudflared だけ。cloudflared が外へ出るのは tunnel_out だけ
          ( if ($nets.tunnel.internal // false) != true then "tunnel の網が内側だけでない（internal: true）" else empty end ),
          ( [$sv | to_entries[] | select((.value.networks // {}) | has("tunnel")) | .key] | sort
            | if . != ["caddy", "cloudflared"] then "tunnel の網にいるのが caddy と cloudflared だけでない（\(join("・"))）" else empty end ),
          ( [$sv | to_entries[] | select((.value.networks // {}) | has("tunnel_out")) | .key] | sort
            | if . != ["cloudflared"] then "tunnel_out の網にいるのが cloudflared だけでない（\(join("・"))）" else empty end ),
          ( if (($cf.networks // {}) | keys | sort) != ["tunnel", "tunnel_out"] then "cloudflared の網が tunnel と tunnel_out でない（\(($cf.networks // {}) | keys | join("・"))）" else empty end ),
          ( if (($sv.caddy.networks // {}) | keys | sort) != ["front", "tunnel"] then "Tunnel の形で caddy の網が front と tunnel でない（\(($sv.caddy.networks // {}) | keys | join("・"))）" else empty end ),
          ( $sv | to_entries[] | select(.key != "cloudflared") | select((.value.networks.tunnel.ipv4_address // "") != "")
            | "\(.key) が tunnel の網で住所を固定している（固定してよいのは cloudflared だけ。Caddy が信じる住所を取り合う）" ),
          # Caddy が信じる住所は cloudflared の住所ちょうど
          ( if ($cf.networks.tunnel.ipv4_address // "") == "" then "cloudflared の住所が固定されていない（ipv4_address: EC_SUGAR_TUNNEL_ADDR）"
            elif ($sv.caddy.environment.EC_SUGAR_TUNNEL_ADDR // "") != $cf.networks.tunnel.ipv4_address then "Caddy が信じる住所（caddy の EC_SUGAR_TUNNEL_ADDR）が cloudflared の住所（\($cf.networks.tunnel.ipv4_address)）と違う"
            else empty end ),
          ( if ($sv.caddy.environment.EC_SUGAR_FRONT // "") != "tunnel" then "Tunnel の形で caddy の EC_SUGAR_FRONT が tunnel でない（CF-Connecting-IP を信じる設定を読まない）" else empty end ),
          ( ($sv.caddy.environment // {}) | keys[] as $k | select((caddyEnv | index($k)) == null) | "Tunnel の形で caddy に決めていない環境変数 \($k) がある" )
          end
    ' "$work/tunnel.json")"
    if [ -n "$problems" ]; then
        while IFS= read -r line; do ng "$line"; done <<< "$problems"
    else
        ok "Tunnel の形: cloudflared は digest まで固定・読み取り専用・cap_drop ALL・no-new-privileges・利用者 ${tunnel_uid}・ポートなし・トークンはファイルで、置き場は読み取り専用。tunnel の網は内側だけで caddy と cloudflared だけ、Caddy が信じる住所は cloudflared の住所"
    fi
    # cloudflared の住所は tunnel の網の中で、自動で配る範囲の外（先に起きた caddy に取られない）。網の住所・gateway・ブロードキャストでない
    t_addr="$(jq -r '.services.cloudflared.networks.tunnel.ipv4_address // ""' "$work/tunnel.json")"
    t_subnet="$(jq -r '[(.networks.tunnel.ipam.config // [])[] | .subnet][0] // ""' "$work/tunnel.json")"
    t_range="$(jq -r '[(.networks.tunnel.ipam.config // [])[] | .ip_range][0] // ""' "$work/tunnel.json")"
    t_gw="$(jq -r '[(.networks.tunnel.ipam.config // [])[] | .gateway // empty][0] // ""' "$work/tunnel.json")"
    t_special=1
    if [[ "$t_addr" =~ ^[0-9]+(\.[0-9]+){3}$ ]] && [[ "$t_subnet" =~ $cidr_re ]]; then
        t_net=$(($(ip4_num "${t_subnet%/*}") & ((0xFFFFFFFF << (32 - ${t_subnet#*/})) & 0xFFFFFFFF)))
        t_a="$(ip4_num "$t_addr")"
        if [ "$t_a" -ne "$t_net" ] && [ "$t_a" -ne $((t_net + 1)) ] && [ "$t_a" -ne $((t_net + (1 << (32 - ${t_subnet#*/})) - 1)) ] &&
            { [ -z "$t_gw" ] || [ "$t_a" -ne "$(ip4_num "$t_gw")" ]; }; then
            t_special=0
        fi
    fi
    if [ "$t_special" -eq 0 ] && [[ "$t_range" =~ $cidr_re ]] && in_cidr4 "$t_addr" "$t_subnet" && in_cidr4 "${t_range%/*}" "$t_subnet" && ! in_cidr4 "$t_addr" "$t_range"; then
        ok "cloudflared の住所 ${t_addr} は tunnel の網 ${t_subnet} の中で、自動で配る範囲 ${t_range} の外"
    else
        ng "cloudflared の住所 EC_SUGAR_TUNNEL_ADDR（${t_addr}）が EC_SUGAR_TUNNEL_SUBNET（${t_subnet}）の中で EC_SUGAR_TUNNEL_RANGE（${t_range}）の外になっていない（網の住所・gateway・ブロードキャストも使えない）"
    fi
    # Tunnel の形でも php-fpm は TCP で受けない
    check_fpm "$work/tunnel.json" "Tunnel の形"
fi

# 13. 隔離した写し（docker-compose.isolated.yml を重ねたもの。ADR 0082）
isolated_file="docker-compose.isolated.yml"
if [ ! -f "$isolated_file" ]; then
    ng "$isolated_file が無い"
elif ! env EC_SUGAR_ENV_FILE="$example" EC_SUGAR_BIND="$sentinel" EC_SUGAR_IMAGE="ec-sugar:check" DB_PASSWORD=check-only REDIS_PASSWORD=check-only \
        EC_SUGAR_DB_SUPERUSER_PASSWORD=check-only \
        docker compose -f "$compose_file" -f "$isolated_file" --env-file "$example" --profile staging config --format json > "$work/isolated.json" 2> "$work/err.txt"; then
    cat "$work/err.txt"
    ng "隔離した写しの形（$isolated_file を重ねたもの）で docker compose config が通らない"
else
    # ポート・権限・利用者・イメージのバージョン・内側の網（1〜4・6）は重ねた後も同じ決まり
    problems="$(jq -r --arg bind "$sentinel" "$base_rules" "$work/isolated.json")"
    if [ -n "$problems" ]; then
        while IFS= read -r line; do ng "隔離の形: $line"; done <<< "$problems"
    else
        ok "隔離の形: ポート・権限・利用者・イメージのバージョン・網の決まりを満たす"
    fi
    # app・queue・scheduler は内側の網だけ（外へ出られない）・隔離の印を環境変数で持つ・定期の処理を動かさない。内側の網は外へ出られないまま
    problems="$(jq -r '
        .services as $sv | .networks as $nets
        | ( ["app", "queue", "scheduler"][] as $n
            | ( if (($sv[$n].networks // {}) | keys) != ["internal"] then "隔離の形: \($n) の網が内側（internal）だけでない（\(($sv[$n].networks // {}) | keys | join("・"))）" else empty end ),
              ( if ($sv[$n].environment.EC_SUGAR_ISOLATED // "") != "true" then "隔離の形: \($n) に隔離の印（EC_SUGAR_ISOLATED=true）が無い" else empty end ) ),
          ( if (($sv.scheduler.command // []) | join(" ") | test("schedule:")) then "隔離の形: 定期の処理（schedule:…）が動く" else empty end ),
          ( if ($nets.internal.internal // false) != true then "隔離の形: 内側の網が外へ出られる（internal: true でない）" else empty end ),
          ( $sv | to_entries[] | select((.value.networks // {}) | has("egress")) | "隔離の形: \(.key) が外へ出る網 egress にいる" )
    ' "$work/isolated.json")"
    if [ -n "$problems" ]; then
        while IFS= read -r line; do ng "$line"; done <<< "$problems"
    else
        ok "隔離の形: app・queue・scheduler は内側の網だけで外へ出られず、隔離の印を持ち、定期の処理は動かない"
    fi
    check_fpm "$work/isolated.json" "隔離の形"
fi
# 15. 外への通信の出口の代理（ADR 0102）。動かす確かめ（許した名前だけ届く・DNS で抜けない・メールは決めた宛先だけ）は docker/check-egress.sh
egress_file="docker-compose.egress.yml"
# 代理: アプリと同じイメージ・ここで作らない・www-data・env ファイルを渡さない（秘密を持たない）・環境変数は決めたものだけ・差し込むのは台本の置き場（読み取り専用）と
# 数の置き場だけ・網は egress（外へ）と egress_in（住所を固定。範囲の中で自動で配る範囲の外）だけ。egress_in は内側だけで、いるのはアプリと代理だけ。
# アプリ: HTTPS_PROXY は代理・NO_PROXY に * が無い・数の置き場は読み取り専用。見張りの形（既定）はアプリの網が今までどおり（外へ出る網と egress_in）
egress_rules='
    def php: ["app", "queue", "scheduler"];
    def egressEnv: ["EC_SUGAR_EGRESS_MODE", "EC_SUGAR_EGRESS_ALLOW", "EC_SUGAR_EGRESS_INTERNAL_OK", "EC_SUGAR_EGRESS_SMTP_HOST", "EC_SUGAR_EGRESS_SMTP_PORT"];
    .services as $sv | .networks as $nets
    | ($sv.egress // null) as $eg
    | if $eg == null then "出口の代理（egress）が無い" else
      ( if $eg.image != "ec-sugar:check" then "egress のイメージが EC_SUGAR_IMAGE でない（\($eg.image)）" else empty end ),
      ( if $eg.build != null then "egress がここでイメージを作る（build）" else empty end ),
      ( if $eg.user != "www-data" then "egress が www-data で動かない（\($eg.user // "未設定")）" else empty end ),
      ( if ($eg.env_file // null) != null then "egress に env ファイルが渡っている（代理に秘密を持たせない）" else empty end ),
      ( ($eg.environment // {}) | keys[] as $k | select((egressEnv | index($k)) == null) | "egress に決めていない環境変数 \($k) がある" ),
      ( if ($eg.command // []) != ["php", "/etc/ec-sugar-egress/proxy.php"] then "egress が docker/egress/proxy.php を動かしていない（\(($eg.command // []) | join(" "))）" else empty end ),
      ( ($eg.volumes // []) as $v
        | if ([$v[] | select(.type == "bind" and ((.source // "") | endswith("/docker/egress")) and .target == "/etc/ec-sugar-egress" and .read_only == true)] | length) != 1
          then "egress に台本の置き場（docker/egress を /etc/ec-sugar-egress に読み取り専用で）が無い" else empty end,
          ( $v[] | select(.target != "/etc/ec-sugar-egress" and .target != "/run/ec-sugar-egress") | "egress に決めていない置き場 \(.target) が差し込まれている" ),
          ( if ([$v[] | select(.type == "volume" and .source == "egress_state" and .target == "/run/ec-sugar-egress")] | length) != 1 then "egress の数の置き場（egress_state）が無い" else empty end ) ),
      ( if (($eg.networks // {}) | keys | sort) != ["egress", "egress_in"] then "egress の網が egress と egress_in でない（\(($eg.networks // {}) | keys | join("・"))）" else empty end ),
      ( if ($eg.healthcheck.test // null) == null then "egress にヘルスチェックが無い（代理が止まると外への https が全部止まる）" else empty end ),
      ( if ($nets.egress_in.internal // false) != true then "egress_in の網が内側だけでない（internal: true）" else empty end ),
      ( if ($nets.egress_in.driver_opts["com.docker.network.bridge.gateway_mode_ipv4"] // "") != "isolated" then "egress_in の網がホストの側に住所を持つ（gateway_mode_ipv4: isolated でない。ホストの待ち受けへ届く）" else empty end ),
      ( if (($eg.stop_grace_period // "0s") | test("^(9[0-9]|[1-9][0-9]{2,})s$|^1m(3[0-9]|[45][0-9])s$|^([2-9]|[1-9][0-9]+)m")) | not then "egress の stop_grace_period が 90 秒より短い（途中の接続を待たずに切る。\($eg.stop_grace_period // "未設定")）" else empty end ),
      ( php[] as $n | if ($sv[$n].depends_on.egress.condition // "") != "service_healthy" then "\($n) が代理（egress）の healthy を待たずに起きる" else empty end ),
      ( [$sv | to_entries[] | select((.value.networks // {}) | has("egress_in")) | .key] | sort
        | if . != ["app", "egress", "queue", "scheduler"] then "egress_in の網にいるのがアプリと代理だけでない（\(join("・"))）" else empty end ),
      ( $sv | to_entries[] | select(.key != "egress") | select((.value.networks.egress_in.ipv4_address // "") != "") | "\(.key) が egress_in の網で住所を固定している（固定してよいのは代理だけ）" ),
      ( php[] as $n
        | ( if ($sv[$n].environment.HTTPS_PROXY // "") != "http://egress:3128" then "\($n) の HTTPS_PROXY が代理（http://egress:3128）でない（\($sv[$n].environment.HTTPS_PROXY // "未設定")）" else empty end ),
          ( if (($sv[$n].environment.NO_PROXY // "") | test("\\*")) then "\($n) の NO_PROXY に * がある（代理を通らない）" else empty end ),
          ( if ([($sv[$n].volumes // [])[] | select(.source == "egress_state" and .target == "/run/ec-sugar-egress" and .read_only == true)] | length) != 1 then "\($n) に数の置き場（egress_state）が読み取り専用で差し込まれていない" else empty end ) ),
      ( $sv | to_entries[] | .key as $n | select($n != "egress" and ((php | index($n)) == null)) | (.value.volumes // [])[] | select(.source == "egress_state")
        | "\($n) に代理の数の置き場が差し込まれている（代理とアプリだけ）" )
      end
'
problems="$(jq -r "$egress_rules" "$work/config.json")"
watch_nets="$(jq -r '[.services.app, .services.queue, .services.scheduler | (.networks // {}) | keys | sort | join(",")] | join(" ")' "$work/config.json")"
[ "$watch_nets" = "edge,egress_in,internal egress,egress_in,internal egress,egress_in,internal" ] ||
    problems="${problems}${problems:+$'\n'}見張りの形でアプリの網が今までどおりでない（app・queue・scheduler: ${watch_nets}）"
if [ -n "$problems" ]; then
    while IFS= read -r line; do ng "$line"; done <<< "$problems"
else
    ok "出口の代理: アプリと同じイメージ・秘密なし・台本は読み取り専用・網は egress と egress_in（内側だけで、アプリと代理だけ）、アプリは HTTPS_PROXY で代理を通る"
fi
# 代理の住所は egress_in の網の中で、自動で配る範囲の外（網の住所・gateway・ブロードキャストでない）
e_addr="$(jq -r '.services.egress.networks.egress_in.ipv4_address // ""' "$work/config.json")"
e_subnet="$(jq -r '[(.networks.egress_in.ipam.config // [])[] | .subnet][0] // ""' "$work/config.json")"
e_range="$(jq -r '[(.networks.egress_in.ipam.config // [])[] | .ip_range][0] // ""' "$work/config.json")"
e_gw="$(jq -r '[(.networks.egress_in.ipam.config // [])[] | .gateway // empty][0] // ""' "$work/config.json")"
e_ok=0
if [[ "$e_addr" =~ ^[0-9]+(\.[0-9]+){3}$ ]] && [[ "$e_subnet" =~ $cidr_re ]] && [[ "$e_range" =~ $cidr_re ]] && in_cidr4 "$e_addr" "$e_subnet" && in_cidr4 "${e_range%/*}" "$e_subnet" && ! in_cidr4 "$e_addr" "$e_range"; then
    e_net=$(($(ip4_num "${e_subnet%/*}") & ((0xFFFFFFFF << (32 - ${e_subnet#*/})) & 0xFFFFFFFF)))
    e_a="$(ip4_num "$e_addr")"
    if [ "$e_a" -ne "$e_net" ] && [ "$e_a" -ne $((e_net + 1)) ] && [ "$e_a" -ne $((e_net + (1 << (32 - ${e_subnet#*/})) - 1)) ] && { [ -z "$e_gw" ] || [ "$e_a" -ne "$(ip4_num "$e_gw")" ]; }; then
        e_ok=1
    fi
fi
[ "$e_ok" -eq 1 ] && ok "代理の住所 ${e_addr} は egress_in の網 ${e_subnet} の中で、自動で配る範囲 ${e_range} の外" ||
    ng "代理の住所 EC_SUGAR_EGRESS_ADDR（${e_addr}）が EC_SUGAR_EGRESS_SUBNET（${e_subnet}）の中で EC_SUGAR_EGRESS_RANGE（${e_range}）の外になっていない（網の住所・gateway・ブロードキャストも使えない）"
for f in docker/egress/proxy.php docker/egress/allow.txt; do [ -f "$f" ] || ng "$f が無い"; done

# 止める形（docker-compose.egress.yml を重ねたもの）: アプリは内側の網（internal・egress_in）だけ。MAIL_HOST は代理の住所へ向く。
# 外へ出る網（edge・egress）にいるのは web（edge）・代理（egress）・ステージングの Mailpit（edge）だけ。重ねても 1〜4・6 の決まりは同じ
egress_resolve() {
    env EC_SUGAR_ENV_FILE="$example" EC_SUGAR_BIND="$sentinel" EC_SUGAR_IMAGE="ec-sugar:check" DB_PASSWORD=check-only REDIS_PASSWORD=check-only \
        EC_SUGAR_DB_SUPERUSER_PASSWORD=check-only EC_SUGAR_TUNNEL_DIR=/srv/ec-sugar/secrets/tunnel-check EC_SUGAR_EGRESS_SMTP_NAME=smtp.example.jp \
        docker compose -f "$compose_file" "$@" --env-file "$example" --profile staging config --format json
}
enforce_rules='
    .services as $sv | .networks as $nets
    | ( ["app", "queue", "scheduler"][] as $n
        | ( if (($sv[$n].networks // {}) | keys | sort) != ["egress_in", "internal"] then "止める形: \($n) の網が internal と egress_in でない（\(($sv[$n].networks // {}) | keys | join("・"))）" else empty end ),
          ( ($sv[$n].networks // {}) | keys[] | select(($nets[.].internal // false) != true) | "止める形: \($n) が外へ出られる網 \(.) にいる" ),
          ( if ($sv[$n].extra_hosts // []) != ["smtp.example.jp=\($addr)"] and ($sv[$n].extra_hosts // []) != ["smtp.example.jp:\($addr)"]
            then "止める形: \($n) の MAIL_HOST が代理の住所（\($addr)）へ向いていない（\(($sv[$n].extra_hosts // []) | join(" "))）" else empty end ) ),
      ( $sv | to_entries[] | .key as $n | ((.value.networks // {}) | keys[]) as $net | select($net == "edge" or $net == "egress")
        | select(([["web", "edge"], ["mailpit", "edge"], ["egress", "egress"]] | index([[$n, $net]])) == null)
        | "止める形: \($n) が外へ出る網 \($net) にいる" )
'
if [ ! -f "$egress_file" ]; then
    ng "$egress_file が無い"
elif ! egress_resolve -f "$egress_file" > "$work/egress.json" 2> "$work/err.txt"; then
    cat "$work/err.txt"
    ng "止める形（$egress_file を重ねたもの）で docker compose config が通らない"
else
    problems="$(jq -r --arg bind "$sentinel" "$base_rules" "$work/egress.json")"
    problems="${problems}${problems:+$'\n'}$(jq -r --arg addr "$e_addr" "$enforce_rules" "$work/egress.json")"
    problems="$(printf '%s' "$problems" | sed '/^$/d')"
    if [ -n "$problems" ]; then
        while IFS= read -r line; do ng "$line"; done <<< "$problems"
    else
        ok "止める形: アプリは内側の網（internal・egress_in）だけで、MAIL_HOST は代理の住所へ。外へ出る網にいるのは web・代理・Mailpit だけ"
    fi
    check_fpm "$work/egress.json" "止める形"
    # Cloudflare Tunnel の形に重ねても同じ
    if [ -f "$tunnel_file" ] && egress_resolve -f "$tunnel_file" -f "$egress_file" > "$work/egress-tunnel.json" 2> "$work/err.txt"; then
        problems="$(jq -r --arg addr "$e_addr" "$enforce_rules" "$work/egress-tunnel.json")"
        [ -z "$problems" ] && ok "止める形を Cloudflare Tunnel の形に重ねても、アプリは内側の網だけ" || while IFS= read -r line; do ng "Tunnel の形: $line"; done <<< "$problems"
    else
        cat "$work/err.txt"
        ng "止める形を Cloudflare Tunnel の形に重ねて docker compose config が通らない"
    fi
    # 隔離した写しの形を後ろに重ねると、アプリは internal だけ・代理も外へ出る網に居ない（ec_dc の重ねる順）
    if [ -f "$isolated_file" ] && egress_resolve -f "$egress_file" -f "$isolated_file" > "$work/egress-isolated.json" 2> "$work/err.txt"; then
        problems="$(jq -r '.services as $sv
            | ( ["app", "queue", "scheduler"][] as $n | if (($sv[$n].networks // {}) | keys) != ["internal"] then "止める形＋隔離: \($n) の網が internal だけでない" else empty end ),
              ( if (($sv.egress.networks // {}) | keys) != ["egress_in"] then "止める形＋隔離: 代理が外へ出る網にいる" else empty end )' "$work/egress-isolated.json")"
        [ -z "$problems" ] && ok "止める形に隔離の形を重ねると、アプリは internal だけ・代理も外へ出ない" || while IFS= read -r line; do ng "$line"; done <<< "$problems"
    else
        cat "$work/err.txt"
        ng "止める形に隔離の形を重ねて docker compose config が通らない"
    fi
fi
# 代理を使わない形（off）: ec_dc が EC_SUGAR_EGRESS_PROXY_URL を空にして渡すと、アプリの HTTPS_PROXY が空になる
if env EC_SUGAR_EGRESS_PROXY_URL= EC_SUGAR_ENV_FILE="$example" EC_SUGAR_BIND="$sentinel" EC_SUGAR_IMAGE="ec-sugar:check" DB_PASSWORD=x REDIS_PASSWORD=x EC_SUGAR_DB_SUPERUSER_PASSWORD=x \
    docker compose -f "$compose_file" --env-file "$example" config --format json > "$work/egress-off.json" 2> /dev/null &&
    [ "$(jq -r '[.services.app, .services.queue, .services.scheduler | .environment.HTTPS_PROXY // ""] | unique | join(",")' "$work/egress-off.json")" = "" ]; then
    ok "代理を使わない形: アプリの HTTPS_PROXY が空になる"
else
    ng "代理を使わない形（EC_SUGAR_EGRESS_PROXY_URL を空に）でアプリの HTTPS_PROXY が空にならない"
fi
# 隔離の形（13）でも代理は外へ出る網に居ない
[ ! -s "$work/isolated.json" ] || [ "$(jq -r '(.services.egress.networks // {}) | keys | join(",")' "$work/isolated.json")" = egress_in ] || ng "隔離の形: 代理（egress）が外へ出る網にいる"
# 14. 中の照らしの様子（ADR 0105）: どの形でも、web にだけ様子の置き場（deploy/lib/common.sh の EC_INTEGRITY_PUBLIC）を読み取り専用で差し込み、
#     署名の鍵と控えの置き場（/srv/ec-sugar/trust の下）と、それを含む上の置き場はどのコンテナにも差し込まない。取りに来た記録は web の tmpfs だけ。
#     nginx は 2 つのファイルを PHP を通さずにそのまま返し（キャッシュなし）、ほかの /.well-known/ec-sugar/ は 404、時刻だけを記録に書く
integrity_pub="$(sed -n 's/^EC_INTEGRITY_PUBLIC=//p' deploy/lib/common.sh | head -n1)"
integrity_log="$(sed -n 's/^EC_INTEGRITY_FETCH_LOG=//p' deploy/lib/common.sh | head -n1)"
integrity_rules='
    .services as $sv
    | ( [($sv.web.volumes // [])[] | select(.target == "/usr/share/ec-sugar/integrity")] as $m
        | if ($m | length) != 1 then "\($form): web に中の照らしの様子の置き場（/usr/share/ec-sugar/integrity）が 1 つ差し込まれていない"
          elif ($m[0].type // "") != "bind" or ($m[0].source // "") != $pub then "\($form): 中の照らしの様子の置き場が \($pub) の bind でない（\($m[0].source // "")）"
          elif ($m[0].read_only // false) != true then "\($form): 中の照らしの様子の置き場が読み取り専用でない"
          else empty end ),
      ( $sv | to_entries[] | select(.key != "web") | .key as $n | (.value.volumes // [])[]
        | select((.source // "") == $pub or (.target // "") == "/usr/share/ec-sugar/integrity")
        | "\($form): \($n) に中の照らしの様子の置き場が差し込まれている（web だけ）" ),
      ( $sv | to_entries[] | .key as $n | (.value.volumes // [])[] | (.source // "") as $s
        | select($s == "/" or $s == "/srv" or $s == "/srv/ec-sugar" or $s == "/var" or $s == "/var/lib" or $s == "/var/lib/ec-sugar" or ($s | startswith("/srv/ec-sugar/trust")))
        | "\($form): \($n) に署名の鍵か控えの置き場（を含む所）\($s) が差し込まれている（どのコンテナにも差し込まない）" ),
      ( [($sv.web.tmpfs // [])[] | select(startswith($logdir + ":") and test("(^|,)uid=101(,|$)") and test("(^|,)noexec(,|$)"))] as $t
        | if ($t | length) != 1 then "\($form): web に外の見張りの記録の tmpfs（\($logdir)、uid 101・noexec）が無い（ホストへ書かせない）" else empty end ),
      ( $sv | to_entries[] | .key as $n | (.value.volumes // [])[] | select((.target // "") == $logdir)
        | "\($form): \($n) に外の見張りの記録の置き場がホストから差し込まれている（web の tmpfs だけ）" )
'
if [ "$integrity_pub" != /var/lib/ec-sugar/integrity ] || [[ "$integrity_log" != /var/log/ec-sugar-integrity/* ]]; then
    ng "deploy/lib/common.sh の EC_INTEGRITY_PUBLIC（${integrity_pub}）か EC_INTEGRITY_FETCH_LOG（${integrity_log}）が、compose と nginx の決め打ちとそろわない"
fi
integrity_ok=1
for pair in "ふつうの形:config" "後ろに置く形:proxy" "Tunnel の形:tunnel" "隔離の形:isolated"; do
    f="$work/${pair#*:}.json"
    [ -s "$f" ] || { ng "中の照らし: ${pair%%:*} の compose を解いた結果が無い"; integrity_ok=0; continue; }
    problems="$(jq -r --arg form "${pair%%:*}" --arg pub "$integrity_pub" --arg logdir "$(dirname "$integrity_log")" "$integrity_rules" "$f")"
    if [ -n "$problems" ]; then
        while IFS= read -r line; do ng "$line"; done <<< "$problems"
        integrity_ok=0
    fi
done
[ "$integrity_ok" -eq 0 ] || ok "中の照らし: どの形でも web にだけ様子の置き場を読み取り専用で差し込み、鍵と控えの置き場は差し込まない。取りに来た記録は web の tmpfs だけ"
integrity_nginx_ok=1
grep -Eq '^\s*location \^~ /\.well-known/ec-sugar/ \{ return 404; \}' "$nginx_conf" || { ng "nginx がほかの /.well-known/ec-sugar/ の道筋を 404 にしていない"; integrity_nginx_ok=0; }
grep -Eq "^log_format ec_sugar_integrity_fetch '\\\$time_iso8601';$" "$nginx_conf" || { ng "nginx の取りに来た記録の形が時刻だけ（log_format ec_sugar_integrity_fetch '\$time_iso8601';）でない"; integrity_nginx_ok=0; }
for f in integrity.json integrity.json.sig; do
    blk="$(loc_block "$nginx_conf" "    location = /.well-known/ec-sugar/$f {")"
    if [ -z "$blk" ]; then
        ng "nginx に /.well-known/ec-sugar/$f の場所が無い"
        integrity_nginx_ok=0
    elif ! printf '%s\n' "$blk" | q -Fx "        alias /usr/share/ec-sugar/integrity/$f;" ||
        ! printf '%s\n' "$blk" | q -Fx '        add_header Cache-Control "no-store" always;' ||
        ! printf '%s\n' "$blk" | q -Fx '        limit_except GET { deny all; }' ||
        ! printf '%s\n' "$blk" | q -Fx "        access_log $integrity_log ec_sugar_integrity_fetch if=\$ec_sugar_integrity_fetched;" ||
        printf '%s\n' "$blk" | q -E '^\s*(fastcgi_pass|try_files|proxy_pass|root) '; then
        ng "nginx の /.well-known/ec-sugar/$f が「差し込んだ置き場からそのまま返す（PHP を通さない）・キャッシュなし・GET だけ・時刻を記録」でない"
        integrity_nginx_ok=0
    fi
done
[ "$integrity_nginx_ok" -eq 0 ] || ok "中の照らし: nginx は integrity.json と .sig を差し込んだ置き場からそのまま返し（PHP を通さない・キャッシュなし・GET だけ）、ほかは 404、取りに来た時刻だけを記録する"

# ホストの防火壁（deploy/lib/common.sh の ec_bridges）が、どの形の網にも当たる（網を足したのに一覧に足し忘れると、その網のコンテナへホストの利用者から届く）
bridge_nets=" $(sed -n 's/^[[:space:]]*for net in \(.*\); do$/\1/p' deploy/lib/common.sh | head -n1) "
missing_nets=""
for f in "$work/config.json" "$work/proxy.json" "$work/tunnel.json" "$work/isolated.json" "$work/egress.json"; do
    [ -s "$f" ] || continue
    for net in $(jq -r '.networks | keys[]' "$f"); do
        [[ "$bridge_nets" == *" $net "* ]] || [[ " $missing_nets " == *" $net "* ]] || missing_nets="$missing_nets $net"
    done
done
[ -z "$missing_nets" ] && ok "ホストの防火壁（ec_bridges）が、どの形の網にも当たる（${bridge_nets# }）" ||
    ng "ホストの防火壁（deploy/lib/common.sh の ec_bridges）が網${missing_nets} に当たらない（一覧に足す）"

[ "$fails" -eq 0 ] || { echo "NG が ${fails} 件"; exit 1; }
echo "すべて ok"
