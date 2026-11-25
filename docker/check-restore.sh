#!/usr/bin/env bash
# バックアップと戻す（ec-sugar backup・restore。ADR 0082）を、手元の Docker の使い捨ての DB と写真の置き場で通しで確かめる。
# root は要らない。compose の名前は ec-sugar-rs-check（本物の ec-sugar とも開発の compose とも分ける）でポートは出さず、
# 終わったら入れ物・ボリューム・網を消す（イメージは消さない。postgres:17.11-alpine と php の alpine〔EC_CHECK_APP_IMAGE〕を使う）。
# 本物の docker-compose.prod.yml は使わない（ec-sugar の compose の呼び方〔ec_dc〕だけを、ここの小さな compose に差し替える）。
#
#   docker/check-restore.sh                     EC_CHECK_VERBOSE=1 で、NG の時に流したものの出力も出す
#
# 確かめること:
#   1. backup: 中央と店の DB の dump・ロール・写真などのファイルの tar（storage の下。framework と logs は入れない）・指紋（SHA256SUMS）・
#      manifest（店の数・ファイル）・.complete。中は 0600。--no-files（0）なら tar を入れない
#   2. backup → 値を変える（注文を書き換える・店の DB とそのロールを消す・写真を消す／書き換える／足す・バックアップの後に店の DB を足す・
#      アプリのロールのパスワードを替える）→ restore → 元に戻っている。消したロールとアプリのロールへの付与も戻る。アプリと管理者のロールの
#      パスワードは戻さない（写しのサーバーのパスワードを本番の値で上書きしない）。framework は触らない。戻す前の状態のバックアップができる。
#      バックアップの後に作った店の DB は消さずに知らせる
#   3. 誤って流さないための確認: 端末でなく --yes も無ければ止まる・打った名前が違えば止まる（どちらも何も変えない）。正しい名前なら戻す
#   4. 壊れた・すり替えたバックアップ: 指紋が合わなければ何も変えずに止まる。強い属性（SUPERUSER）を付けるロールの行は流さずに止まる。
#      tar に storage の外を指す名前（../）・リンクがあれば止まる（日本語と空白の名前は通す）
#   5. 途中で失敗（3 つ目の DB が読めない）: どこまで戻ったか・止まった所・戻す前のバックアップの名前を出して止まり、そのバックアップで戻せる
#   6. 隔離した写し（--isolated）: env の外のサービスの鍵が空・メールは送らない（production）か Mailpit（staging）・本番の Stripe の鍵を置けない・
#      隔離の印。APP_KEY はファイルから入り、画面にも引数にも出ない。ほかの利用者から読める鍵のファイルは断る。Cloudflare Tunnel の形は断る。
#      印があるのに隔離の形のファイルが無いバージョンでは compose を流さない。あれば重ねる
#   7. 世代の掃除: 7 回取ると DB の dump は 7 世代とも残り、写真などのファイルの tar は新しい 3 世代だけ（古い世代は指紋と manifest も直り、
#      そのまま確かめを通る）。写真の無い世代を restore すると、DB だけを戻し（今の写真はそのまま）、確認の文にそう出す
set -euo pipefail

cd "$(dirname "$0")/.."
repo="$(pwd)"
fails=0
ng() {
    echo "NG: $*"
    fails=$((fails + 1))
    # EC_CHECK_VERBOSE=1 なら、直前に流したものの出力も出す
    if [ "${EC_CHECK_VERBOSE:-0}" = 1 ] && [ -s "${work:-}/out" ]; then sed 's/^/    | /' "$work/out"; fi
}
ok() { echo "ok: $*"; }

proj=ec-sugar-rs-check
app_image="${EC_CHECK_APP_IMAGE:-php:8.5-fpm-alpine}"
db_image=postgres:17.11-alpine
# 一時の置き場（EC_CHECK_TMP で場所を決められる。既定は TMPDIR か /tmp）
work="$(mktemp -d "${EC_CHECK_TMP:-${TMPDIR:-/tmp}}/check-restore.XXXXXX")"
cleanup() {
    docker compose -p "$proj" -f "$work/compose.yml" down -v --remove-orphans > /dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT

# ---------- 使い捨ての compose（db と、写真の置き場を差し込んだ app） ----------
cat > "$work/compose.yml" << EOF
name: $proj
services:
  db:
    image: $db_image
    environment:
      POSTGRES_DB: ec_central
      POSTGRES_USER: ec
      POSTGRES_PASSWORD: check-only
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ec -d ec_central"]
      interval: 1s
      retries: 30
  app:
    image: $app_image
    user: www-data
    command: ["sh", "-c", "trap 'exit 0' TERM INT; while :; do sleep 3600 & wait \$\$!; done"]
    volumes:
      - storage:/var/www/html/storage
  # 戻すときに一緒に止める入れ物（本物の queue・scheduler・web の代わり。眠るだけ）
  queue: &idle
    image: $app_image
    user: www-data
    command: ["sh", "-c", "trap 'exit 0' TERM INT; while :; do sleep 3600 & wait \$\$!; done"]
  scheduler: *idle
  web: *idle
volumes:
  storage: {}
EOF

# ---------- ec-sugar の部品を読む（終わりの main だけ外す。lib/common.sh は同じ置き場から読む） ----------
mkdir -p "$work/bin/lib" "$work/src" "$work/state" "$work/backup" "$work/tunnel"
cp deploy/lib/common.sh "$work/bin/lib/common.sh"
sed '$d' deploy/ec-sugar > "$work/bin/ec-sugar"
# shellcheck source=../deploy/ec-sugar
. "$work/bin/ec-sugar"
set +e +o pipefail
set -u
EC_BACKUP="$work/backup"
EC_STATE="$work/state"
EC_LOGS="$work/state/logs"
EC_ENV_FILE="$work/env"
EC_ENV_DIR="$work"
EC_ANSWERS="$work/answers"
EC_SRC="$work/src"
EC_TUNNEL_DIR="$work/tunnel"
EC_PROJECT="$proj-unused"
cp docker-compose.isolated.yml "$work/src/"
printf 'MODE=production\nFRONT=caddy\n' > "$EC_ANSWERS"
cat > "$EC_ENV_FILE" << 'EOF'
EC_SUGAR_ADMIN_HOST=admin.check.example
EC_SUGAR_IMAGE=ec-sugar:check
TENANT_DB_PREFIX=ec_t_
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
MAIL_MAILER=smtp
MAIL_HOST=smtp.mail.example
MAIL_PORT=465
MAIL_USERNAME=noreply@check.example
MAIL_PASSWORD=mail-secret-0001
STRIPE_PLATFORM_SECRET_KEY=sk_live_stripe-secret-0001
STRIPE_CONNECT_WEBHOOK_SECRET=whsec_stripe-secret-0002
SLACK_BOT_USER_OAUTH_TOKEN=xoxb-slack-secret-0003
PAYMENT_LIVE_KEYS_ALLOWED=true
EOF
chmod 600 "$EC_ENV_FILE"
printf '%s\n' 0123456789abcdef0123456789abcdef01234567 > "$EC_STATE/current_commit"

# compose の呼び方をここの compose に差し替え、root・鍵・防火壁・ヘルスチェック・報告・git の所は手元で動く形に
ec_dc() { docker compose -p "$proj" -f "$work/compose.yml" "$@"; }
ec_require_root() { :; }
ec_require_installed() { :; }
ec_lock() { :; }
ec_report_at_exit() { :; }
ec_host_report() { :; }
ec_firewall_apply() { :; }
ec_env_migrate() { :; }
ec_git() { return 1; }
ec_migrate() { return 0; }
ec_health() { ec_dc up -d --wait > /dev/null 2>&1; }
install() {
    local args=() skip=0 a
    for a in "$@"; do
        if [ "$skip" = 1 ]; then skip=0; continue; fi
        case "$a" in -o | -g) skip=1 ;; *) args+=("$a") ;; esac
    done
    command install "${args[@]}"
}
# GNU の date -Is が無い手元の Mac でも
date() { if [ "${1:-}" = -Is ]; then command date -u +%Y-%m-%dT%H:%M:%S+00:00; else command date "$@"; fi; }

psql_c() { ec_dc exec -T db psql -U ec -d "$1" -Atqc "$2"; }
app_sh() { ec_dc exec -T app sh -c "$1"; }
# 写真の置き場の中身（framework と logs を除く。名前と中身の指紋）
files_state() { app_sh 'cd /var/www/html/storage && find . -path ./framework -prune -o -path ./logs -prune -o -type f -print0 | sort -z | xargs -0 -r sha256sum'; }
db_state() {
    printf '%s|' "$(psql_c ec_central "SELECT string_agg(id || ':' || name, ',' ORDER BY id) FROM tenants")" \
        "$(psql_c ec_t_a "SELECT string_agg(id || ':' || amount, ',' ORDER BY id) FROM orders")" \
        "$(psql_c ec_t_b "SELECT string_agg(id || ':' || amount, ',' ORDER BY id) FROM orders" 2> /dev/null || echo 'ec_t_b が無い')"
}
backups() { find "$EC_BACKUP" -mindepth 1 -maxdepth 1 -type d | sort; }
# 失敗で抜ける関数（ec_die・ec_restore_fail）を、この台本を止めずに流す。中は本物と同じく set -e（|| の中に置くと効かないので、返りは後で読む）。
# 出力は $work/out、返りは rc
run() {
    (set -euo pipefail; "$@") > "$work/out" 2>&1 < /dev/null
    rc=$?
}
run_in() {
    local input="$1"
    shift
    (set -euo pipefail; "$@") > "$work/out" 2>&1 <<< "$input"
    rc=$?
}

# ---------- 用意 ----------
echo "使い捨ての DB と写真の置き場を立てています（${proj}）"
if ! ec_dc up -d --wait > "$work/up.log" 2>&1; then
    cat "$work/up.log"
    echo "NG: 使い捨ての compose を立てられない"
    exit 1
fi
ec_dc run --rm --no-deps -T --user root app chown www-data:www-data /var/www/html/storage > /dev/null 2>&1
# 中央の DB・アプリのロール・店の DB 2 つ（持ち主は店ごとのロール。1 つは大文字まじりの名前〔stancl の作り方〕、1 つは小文字だけ）
psql_c ec_central "CREATE ROLE ec_app LOGIN PASSWORD 'app-before' CREATEDB CREATEROLE;
    CREATE ROLE \"AbCdEf0123456789\" LOGIN PASSWORD 'shop-a' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;
    CREATE ROLE shoprole_b LOGIN PASSWORD 'shop-b' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;
    GRANT \"AbCdEf0123456789\" TO ec_app WITH INHERIT FALSE, SET TRUE;
    GRANT shoprole_b TO ec_app WITH INHERIT FALSE, SET TRUE;
    CREATE TABLE tenants (id text PRIMARY KEY, name text);
    INSERT INTO tenants VALUES ('a', '店 A'), ('b', '店 B');" > /dev/null
psql_c postgres 'CREATE DATABASE ec_t_a OWNER "AbCdEf0123456789"' > /dev/null
psql_c postgres 'CREATE DATABASE ec_t_b OWNER shoprole_b' > /dev/null
psql_c ec_t_a 'SET ROLE "AbCdEf0123456789"; CREATE TABLE orders (id int PRIMARY KEY, amount int); INSERT INTO orders VALUES (1, 4900), (2, 1200);' > /dev/null
psql_c ec_t_b 'SET ROLE shoprole_b; CREATE TABLE orders (id int PRIMARY KEY, amount int); INSERT INTO orders VALUES (1, 300);' > /dev/null
app_sh 'cd /var/www/html/storage && mkdir -p app/media/tenants/a/listings/l1 app/private framework/views logs tenanta/app &&
    printf large > app/media/tenants/a/listings/l1/i1-large.webp && printf medium > app/media/tenants/a/listings/l1/i1-medium.webp &&
    printf download > tenanta/app/file.zip && printf manual > "tenanta/app/取扱説明書 v2.pdf" && printf view > framework/views/compiled.php && printf log > logs/laravel.log'
db_before="$(db_state)"
files_before="$(files_state)"
app_hash_before="$(psql_c postgres "SELECT rolpassword FROM pg_authid WHERE rolname = 'ec_app'")"

# ---------- 1. バックアップ ----------
run ec_backup "check" 1
first="$(backups | tail -n1)"
if [ "$rc" -eq 0 ] && [ -e "$first/.complete" ] && [ -s "$first/ec_central.dump" ] && [ -s "$first/ec_t_a.dump" ] && [ -s "$first/ec_t_b.dump" ] &&
    [ -s "$first/globals.sql" ] && [ -s "$first/storage.tar" ] && [ -s "$first/SHA256SUMS" ]; then
    ok "backup: 中央と店の DB・ロール・写真などのファイル・指紋がそろう（$(grep -o '合計.*' "$work/out" | head -n1)"
else
    ng "backup が取れない・そろわない（返り ${rc}）: $(tr '\n' ' ' < "$work/out")"
fi
[ "$(ec_kv_get "$first/manifest" shops)" = 2 ] && [ "$(ec_kv_get "$first/manifest" files)" = storage.tar ] && [ "$(ec_kv_get "$first/manifest" prefix)" = ec_t_ ] &&
    ok "manifest に店の数（2）・ファイル・前置きが載る" || ng "manifest の中身が違う: $(tr '\n' ' ' < "$first/manifest")"
listing="$(tar -tf "$first/storage.tar" | tr '\n' ' ')"
[[ "$listing" == *"app/media/tenants/a/listings/l1/i1-large.webp"* ]] && [[ "$listing" == *"tenanta/app/file.zip"* ]] && [[ "$listing" == *"v2.pdf"* ]] && [[ "$listing" != *framework* ]] && [[ "$listing" != *logs* ]] &&
    ok "写真などのファイルの tar は storage の下（店ごとの置き場・日本語と空白の名前も）で、framework と logs は入れない" || ng "tar の中身が違う: $listing"
loose="$(find "$first" -type f ! -perm 600 | tr '\n' ' ')"
[ -z "$loose" ] && ok "バックアップの中は 0600" || ng "0600 でないファイル: $loose"
sleep 1
run ec_backup "check-db" 0
dbonly="$(backups | tail -n1)"
[ "$rc" -eq 0 ] && [ ! -e "$dbonly/storage.tar" ] && [ "$(ec_kv_get "$dbonly/manifest" files)" = none ] &&
    ok "ファイルを入れないバックアップ（更新の前・--no-files）は DB だけ" || ng "DB だけのバックアップにファイルが入った（返り ${rc}）"
rm -rf "$dbonly"
name="$(basename "$first")"

# ---------- 2. 値を変える ----------
psql_c ec_t_a 'SET ROLE "AbCdEf0123456789"; UPDATE orders SET amount = 1 WHERE id = 1; INSERT INTO orders VALUES (3, 999);' > /dev/null
psql_c postgres 'DROP DATABASE ec_t_b' > /dev/null
psql_c postgres 'DROP ROLE shoprole_b' > /dev/null
psql_c postgres 'CREATE DATABASE ec_t_c' > /dev/null
psql_c postgres "ALTER ROLE ec_app PASSWORD 'app-after'" > /dev/null
app_hash_after="$(psql_c postgres "SELECT rolpassword FROM pg_authid WHERE rolname = 'ec_app'")"
psql_c ec_central "UPDATE tenants SET name = '書き換えた' WHERE id = 'a'" > /dev/null
app_sh 'cd /var/www/html/storage && rm app/media/tenants/a/listings/l1/i1-medium.webp && printf changed > app/media/tenants/a/listings/l1/i1-large.webp &&
    printf new > app/private/new.txt && printf view2 > framework/views/compiled.php'
[ "$(db_state)" != "$db_before" ] && [ "$(files_state)" != "$files_before" ] && ok "値を変えた（DB とファイルが変わった）" || ng "値を変えられていない"

# ---------- 3. 誤って流さないための確認 ----------
count_before="$(backups | wc -l | tr -d ' ')"
run cmd_restore "$name"
[ "$rc" -ne 0 ] && grep -q '確認の入力を受け取れません' "$work/out" && [ "$(backups | wc -l | tr -d ' ')" = "$count_before" ] && [ "$(psql_c ec_central "SELECT name FROM tenants WHERE id = 'a'")" = 書き換えた ] &&
    ok "端末でなく --yes も無ければ、何も変えずに止まる" || ng "端末でないのに戻した・止まり方が違う（返り ${rc}）: $(tail -n3 "$work/out" | tr '\n' ' ')"
grep -q 'このサーバーは本番の構成です' "$work/out" && grep -q '店 2 つ' "$work/out" && ok "確認の文に、本番の形であること・店の数を出す" || ng "確認の文が足りない: $(tr '\n' ' ' < "$work/out")"
stdin_is_tty() { return 0; }
run_in "20000101-000000" cmd_restore "$name"
[ "$rc" -ne 0 ] && grep -q '名前が違うので止めました' "$work/out" && [ "$(backups | wc -l | tr -d ' ')" = "$count_before" ] &&
    ok "打った名前が違えば、何も変えずに止まる" || ng "違う名前で戻した（返り ${rc}）"

# ---------- 2（続き）. 正しい名前を打って戻す ----------
run_in "$name" cmd_restore "$name"
if [ "$rc" -eq 0 ] && [ "$(db_state)" = "$db_before" ]; then
    ok "restore: 中央と店の DB がバックアップの時点に戻る（消した店の DB も）"
else
    ng "DB が戻らない（返り ${rc}）: $(db_state) ≠ $db_before / $(tail -n5 "$work/out" | tr '\n' ' ')"
fi
[ "$(files_state)" = "$files_before" ] && ok "写真などのファイルが戻る（消した・書き換えた・足したものも）" || ng "ファイルが戻らない: $(files_state | tr '\n' ' ')"
[ "$(app_sh 'cat /var/www/html/storage/framework/views/compiled.php')" = view2 ] && ok "framework（画面の下書き）には触らない" || ng "framework を書き換えた"
[ "$(psql_c postgres "SELECT rolcanlogin::text || rolsuper::text FROM pg_roles WHERE rolname = 'shoprole_b'")" = truefalse ] &&
    [ "$(psql_c postgres "SELECT count(*) FROM pg_auth_members m JOIN pg_roles r ON r.oid = m.roleid JOIN pg_roles u ON u.oid = m.member WHERE r.rolname = 'shoprole_b' AND u.rolname = 'ec_app'")" = 1 ] &&
    [ "$(psql_c postgres "SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = 'ec_t_b'")" = shoprole_b ] &&
    ok "消した店のロール（ログインでき superuser でない）と、アプリのロールへの付与と、店の DB の持ち主が戻る" || ng "店のロールが戻らない"
now_hash="$(psql_c postgres "SELECT rolpassword FROM pg_authid WHERE rolname = 'ec_app'")"
[ "$now_hash" = "$app_hash_after" ] && [ "$now_hash" != "$app_hash_before" ] &&
    ok "アプリのロール（ec_app）のパスワードは戻さない（写しのサーバーの値のまま）" || ng "アプリのロールのパスワードをバックアップの値に戻した"
grep -q 'バックアップに無い店の DB が残っています（ec_t_c' "$work/out" && [ "$(psql_c postgres "SELECT count(*) FROM pg_database WHERE datname = 'ec_t_c'")" = 1 ] &&
    ok "バックアップの後に作った店の DB は消さずに知らせる" || ng "バックアップの後の店の DB の扱いが違う"
safety="$(sed -n 's/.*戻す前の状態はバックアップ \([0-9-]*\).*/\1/p' "$work/out" | tail -n1)"
[ -n "$safety" ] && [ -e "$EC_BACKUP/$safety/.complete" ] && [ "$safety" != "$name" ] && ok "戻す前の状態のバックアップを取る（${safety}）" || ng "戻す前のバックアップが無い"

# ---------- 4. 壊れた・すり替えたバックアップ ----------
copy() { rm -rf "$EC_BACKUP/$2"; cp -Rp "$EC_BACKUP/$1" "$EC_BACKUP/$2"; }
resum() { (cd "$EC_BACKUP/$1" && ec_sha256 -- *.dump globals.sql $([ -e storage.tar ] && echo storage.tar)) > "$EC_BACKUP/$1/SHA256SUMS"; }
psql_c ec_central "UPDATE tenants SET name = '戻す前' WHERE id = 'b'" > /dev/null
copy "$name" 20990101-000001
printf 'x' >> "$EC_BACKUP/20990101-000001/ec_t_a.dump"
count_before="$(backups | wc -l | tr -d ' ')"
run cmd_restore 20990101-000001 --yes
[ "$rc" -ne 0 ] && grep -q '取った時の指紋と違います' "$work/out" && [ "$(psql_c ec_central "SELECT name FROM tenants WHERE id = 'b'")" = 戻す前 ] &&
    [ "$(backups | wc -l | tr -d ' ')" = "$count_before" ] && ok "指紋が合わなければ、何も変えずに止まる" || ng "指紋の違うバックアップを戻した（返り ${rc}）"
copy "$name" 20990101-000002
printf 'ALTER ROLE shoprole_b WITH SUPERUSER LOGIN;\n' >> "$EC_BACKUP/20990101-000002/globals.sql"
resum 20990101-000002
run cmd_restore 20990101-000002 --yes
[ "$rc" -ne 0 ] && grep -q '特権の属性（SUPERUSER）' "$work/out" && [ "$(psql_c postgres "SELECT rolsuper::text FROM pg_roles WHERE rolname = 'shoprole_b'")" = false ] &&
    ok "SUPERUSER を付けるロールの行があれば、流さずに止まる（指紋を作り直されても）" || ng "強い属性の行を流した・止まらない（返り ${rc}）"
grep -q 'まだ何も戻していません' "$work/out" && ok "そのときは「まだ何も戻していません」と出す" || ng "止まった時に戻した所を出さない"
copy "$name" 20990101-000003
mkdir -p "$work/evil"
python3 -I -c 'import io, sys, tarfile
t = tarfile.open(sys.argv[1], "w")
data = b"x"
info = tarfile.TarInfo("../../evil.php"); info.size = len(data)
t.addfile(info, io.BytesIO(data)); t.close()' "$EC_BACKUP/20990101-000003/storage.tar"
resum 20990101-000003
count_before="$(backups | wc -l | tr -d ' ')"
run cmd_restore 20990101-000003 --yes
[ "$rc" -ne 0 ] && grep -q 'storage の外を指す' "$work/out" && [ "$(backups | wc -l | tr -d ' ')" = "$count_before" ] &&
    ok "tar に storage の外を指す名前（../）があれば、何も変えずに止まる" || ng "外を指す tar を止めない（返り ${rc}）"
copy "$name" 20990101-000005
python3 -I -c 'import sys, tarfile
t = tarfile.open(sys.argv[1], "w")
info = tarfile.TarInfo("./app/link"); info.type = tarfile.SYMTYPE; info.linkname = "/etc/passwd"
t.addfile(info); t.close()' "$EC_BACKUP/20990101-000005/storage.tar"
resum 20990101-000005
count_before="$(backups | wc -l | tr -d ' ')"
run cmd_restore 20990101-000005 --yes
[ "$rc" -ne 0 ] && grep -q 'リンク（シンボリック・ハード）があります' "$work/out" && [ "$(backups | wc -l | tr -d ' ')" = "$count_before" ] &&
    ok "tar にリンクがあれば、何も変えずに止まる" || ng "リンクのある tar を止めない（返り ${rc}）"

# ---------- 5. 途中で失敗 ----------
psql_c ec_t_a 'SET ROLE "AbCdEf0123456789"; UPDATE orders SET amount = 7 WHERE id = 2;' > /dev/null
mid_before="$(db_state)"
copy "$name" 20990101-000004
head -c 200 "$EC_BACKUP/$name/ec_t_b.dump" > "$EC_BACKUP/20990101-000004/ec_t_b.dump"
resum 20990101-000004
run cmd_restore 20990101-000004 --yes --no-files
fail_safety="$(sed -n 's/.*戻す前の状態へ戻すなら sudo ec-sugar restore \([0-9-]*\).*/\1/p' "$work/out" | tail -n1)"
if [ "$rc" -ne 0 ] && grep -q '戻した所: DB ec_central・DB ec_t_a' "$work/out" && grep -q '止まった所: ec_t_b' "$work/out" && [ -n "$fail_safety" ]; then
    ok "途中で失敗すると、戻した所（ec_central・ec_t_a）・止まった所（ec_t_b）・戻す前のバックアップ（${fail_safety}）を出して止まる"
else
    ng "途中の失敗の出し方が違う（返り ${rc}）: $(tail -n8 "$work/out" | tr '\n' ' ')"
fi
run cmd_restore "$fail_safety" --yes
[ "$rc" -eq 0 ] && [ "$(db_state)" = "$mid_before" ] && ok "出したバックアップで、失敗する前の状態へ戻せる" || ng "失敗する前へ戻せない（返り ${rc}）: $(db_state) ≠ $mid_before"

# ---------- 6. 隔離した写し ----------
key="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
printf 'APP_KEY=%s\n' "$key" > "$work/app-key"
chmod 644 "$work/app-key"
run cmd_restore "$name" --yes --isolated --app-key-file "$work/app-key"
[ "$rc" -ne 0 ] && grep -q 'ほかの利用者から読めます' "$work/out" && [ ! -e "$EC_STATE/isolated" ] && ok "ほかの利用者から読める APP_KEY のファイルは断る" || ng "読める鍵のファイルを使った（返り ${rc}）"
chmod 600 "$work/app-key"
printf 'MODE=production\nFRONT=tunnel\n' > "$EC_ANSWERS"
run cmd_restore "$name" --yes --isolated --app-key-file "$work/app-key"
[ "$rc" -ne 0 ] && grep -q 'Cloudflare Tunnel の構成のサーバーは、隔離した複製環境にできません' "$work/out" && [ ! -e "$EC_STATE/isolated" ] &&
    ok "Cloudflare Tunnel の形（本番の見込み）は隔離した写しにしない" || ng "Tunnel の形を写しにした（返り ${rc}）"
printf 'MODE=production\nFRONT=caddy\n' > "$EC_ANSWERS"
run cmd_restore "$name" --yes --isolated --app-key-file "$work/app-key"
env_now="$(cat "$EC_ENV_FILE")"
if [ "$rc" -eq 0 ] && [ -e "$EC_STATE/isolated" ] && [ "$(ec_env_get EC_SUGAR_ISOLATED)" = true ] && [ "$(ec_env_get MAIL_MAILER)" = array ] &&
    [ "$(ec_env_get MAIL_PASSWORD)" = null ] && [ -z "$(ec_env_get STRIPE_PLATFORM_SECRET_KEY)" ] && [ -z "$(ec_env_get STRIPE_CONNECT_WEBHOOK_SECRET)" ] &&
    [ -z "$(ec_env_get SLACK_BOT_USER_OAUTH_TOKEN)" ] && [ "$(ec_env_get PAYMENT_LIVE_KEYS_ALLOWED)" = false ] && [ "$(ec_env_get APP_KEY)" = "$key" ]; then
    ok "隔離: 印・メールは送らない・外のサービスの鍵は空・本番の Stripe の鍵を置けない・APP_KEY はファイルの鍵"
else
    ng "隔離の env が違う（返り ${rc}）: $(grep -v '^APP_KEY' "$EC_ENV_FILE" | tr '\n' ' ') / $(tail -n4 "$work/out" | tr '\n' ' ')"
fi
[[ "$env_now" != *secret-000* ]] && ok "env に本番の秘密が残らない" || ng "env に秘密が残った"
! grep -qF "${key#base64:}" "$work/out" && ok "APP_KEY は画面に出ない" || ng "APP_KEY が画面に出た"
[ "$(db_state)" = "$db_before" ] && ok "隔離した写しにしても、DB はバックアップの時点に戻る" || ng "隔離した写しで DB が戻らない"
printf 'MODE=staging\nFRONT=caddy\n' > "$EC_ANSWERS"
ec_isolate_env
[ "$(ec_env_get MAIL_MAILER)" = smtp ] && [ "$(ec_env_get MAIL_HOST)" = mailpit ] && [ "$(ec_env_get MAIL_PORT)" = 1025 ] && [ "$(ec_env_get MAIL_USERNAME)" = null ] &&
    ok "隔離: ステージングの形ではメールは Mailpit へ" || ng "ステージングの形のメールが Mailpit でない"

# 印があるのに隔離の形のファイルが無いバージョンでは compose を流さない。あれば重ねる（本物の ec_dc を偽の docker で）
eval "$(sed -n '/^ec_dc() {$/,/^}$/p' "$work/bin/lib/common.sh" | sed 's/^ec_dc() {$/real_ec_dc() {/')"
docker() { printf '%s\n' "$*" > "$work/docker-args"; }
: > "$work/docker-args"
rm -f "$EC_SRC/docker-compose.isolated.yml"
rc=0
real_ec_dc ps > "$work/out" 2>&1 || rc=$?
[ "$rc" -ne 0 ] && [ ! -s "$work/docker-args" ] && grep -q '隔離が外れるので compose を実行せずに止めました' "$work/out" &&
    ok "印があるのに隔離の形のファイルが無ければ、compose を流さない" || ng "隔離の形が無いのに compose を流した（返り ${rc}）"
cp "$repo/docker-compose.isolated.yml" "$EC_SRC/"
touch "$EC_SRC/docker-compose.prod.yml"
real_ec_dc ps > /dev/null 2>&1 || true
grep -q -- "-f $EC_SRC/docker-compose.isolated.yml" "$work/docker-args" && ok "印があれば隔離の形を重ねる" || ng "隔離の形を重ねない: $(cat "$work/docker-args")"
unset -f docker

# ---------- 7. 世代の掃除と、写真の無い世代 ----------
EC_BACKUP="$work/backup-gens"
mkdir -p "$EC_BACKUP"
gen_ok=1
for i in 1 2 3 4 5 6 7; do
    run ec_backup "gen$i" 1
    [ "$rc" -eq 0 ] || gen_ok=0
done
gens="$(find "$EC_BACKUP" -mindepth 2 -maxdepth 2 -name .complete | wc -l | tr -d ' ')"
dumps="$(find "$EC_BACKUP" -mindepth 2 -maxdepth 2 -name ec_central.dump | wc -l | tr -d ' ')"
tars="$(find "$EC_BACKUP" -mindepth 2 -maxdepth 2 -name storage.tar | wc -l | tr -d ' ')"
newest3="$(backups | tail -n3 | while IFS= read -r d; do [ -e "$d/storage.tar" ] && echo y; done | wc -l | tr -d ' ')"
if [ "$gen_ok" = 1 ] && [ "$gens" = 7 ] && [ "$dumps" = 7 ] && [ "$tars" = 3 ] && [ "$newest3" = 3 ]; then
    ok "世代の掃除: DB の dump は 7 世代とも残り、写真などのファイルの tar は新しい 3 世代だけ"
else
    ng "世代の掃除が違う（世代 ${gens}・DB ${dumps}・tar ${tars}・新しい 3 世代の tar ${newest3}）"
fi
oldest="$(backups | head -n1)"
(set -euo pipefail; ec_restore_check_backup "$oldest") > "$work/out" 2>&1
[ $? -eq 0 ] && [ "$(ec_kv_get "$oldest/manifest" files)" = none ] && ! grep -q storage.tar "$oldest/SHA256SUMS" &&
    ok "tar を外した世代は、指紋と manifest も直り、そのまま確かめを通る" || ng "tar を外した世代が確かめを通らない: $(tr '\n' ' ' < "$work/out")"
psql_c ec_central "UPDATE tenants SET name = '世代の後' WHERE id = 'a'" > /dev/null
app_sh 'printf after-gens > /var/www/html/storage/app/media/tenants/a/listings/l1/i1-large.webp'
run cmd_restore "$(basename "$oldest")" --yes
if [ "$rc" -eq 0 ] && grep -q 'このバックアップには写真などのファイルがありません' "$work/out" && grep -q 'DB だけを戻し、今の写真などのファイルはそのまま' "$work/out" &&
    ! grep -q '写真などのファイルを戻す' "$work/out"; then
    ok "写真の無い世代を restore すると、DB だけを戻すと確認の文に出し、ファイルを戻す段を飛ばす"
else
    ng "写真の無い世代の restore の出し方が違う（返り ${rc}）"
fi
[ "$(psql_c ec_central "SELECT name FROM tenants WHERE id = 'a'")" = '店 A' ] && [ "$(app_sh 'cat /var/www/html/storage/app/media/tenants/a/listings/l1/i1-large.webp')" = after-gens ] &&
    ok "写真の無い世代からは DB だけが戻り、今の写真はそのまま" || ng "写真の無い世代で DB が戻らない・写真が変わった"

[ "$fails" -eq 0 ] || { echo "NG が ${fails} 件"; exit 1; }
echo "すべて ok"
