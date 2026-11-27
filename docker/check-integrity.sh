#!/usr/bin/env bash
# 中の照らし（ADR 0105。deploy/lib/common.sh の ec_integrity_…）を、本物の docker の代わりに偽の部品で確かめる。
# root は要らず、何も変えない（作るのは一時の置き場だけ）。署名は本物の openssl 3 で作り、外の見張りと同じ
# openssl pkeyutl -verify -pubin -rawin -sigfile で確かめる。docker/check-deploy.sh が呼ぶ（単体でも流せる）
#
#   docker/check-integrity.sh [common.sh の道筋]   既定は deploy/lib/common.sh
#
# 確かめること:
#   1. 控えて照らすと ok。鍵は 0400・置き場は 0700・控え・塩・合い言葉は 0600・様子は 0644。識別子は 16 桁、合い言葉は 32 桁の 16 進で、作り直さない
#   2. 署名が openssl pkeyutl -verify -pubin -rawin -sigfile で通り、1 バイト変えると通らない。様子は決めた形の 1 行の JSON（末尾に改行なし）で、
#      problems は種類だけ（細かい名前は中の知らせだけ）
#   3. 書き換えに気づく: イメージ ID（image:app）・設定の指紋（config:app。特権・差し込み・ポートなど。差し込みの並びは問わない）・名札を偽った
#      コンテナ（同じイメージでも設定が違えば config、数が増えれば container:extra）・錠の外の一度きりのコンテナ・compose の外のコンテナ
#      （許すのは名前とイメージ ID か設定の指紋の組だけ。名前だけ・* だけは許さない）・サービスの欠け・コードの木（code:app。10 分ごと）・
#      compose・入口の設定・道具と付け替え・lib・unit と drop-in・信じる鍵・env の値（file:env-…）・答えの値（file:answers-…）・Docker・控え
#   4. 錠（root だけの置き場の中）をだれかが持っている間は照らさず、busy を足して署名だけ新しくする。30 分を超えたら lock:held
#   5. 控え直し: 中身が同じなら世代を上げない。always はそのコマンドが作り直した分だけ（足された unit・信じる鍵などは前の控えのまま）。
#      compose は確かめたコミットの git の中身とそろう時だけ。if-ok は照らし直して、ほかに違いがあれば控えない
#   6. JSON に秘密・パス・ハッシュ・イメージ ID・細かい名前が出ない
#   7. 外の見張りと、運営の様子への知らせ（変わった時だけ・渡し直し・前のバージョンのアプリ）。docker exec の答えは大きさの上限つきで読む
set -euo pipefail
# 管の先で「あるか」を見る grep -q の代わり。grep -q は見つけた時点で読むのをやめるので、前のコマンドが書き込みの途中だと管が切れ、
# pipefail の下では偽の失敗になる（タイミングしだいで、たまにだけ落ちる）。先に全部を受け取ってから探す
q() {
    local in
    in="$(cat)"
    if [ -z "$in" ]; then grep -q "$@" < /dev/null; else grep -q "$@" <<< "$in"; fi
}

cd "$(dirname "$0")/.."
lib="${1:-deploy/lib/common.sh}"
fails=0
ng() { echo "NG: $*"; fails=$((fails + 1)); }
ok() { echo "ok: $*"; }

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# shellcheck source=../deploy/lib/common.sh
. "$lib"

# 置き場はすべて一時の置き場に（本物の /srv/ec-sugar・/etc/systemd・/run を読まない）
EC_ROOT="$work"
EC_ANSWERS="$work/answers"
EC_ENV_FILE="$work/env"
EC_STATE="$work/state"
EC_LOCK="$work/run/lock"
EC_TRUST_DIR="$work/trust"
EC_TRUST_FILE="$EC_TRUST_DIR/allowed_signers"
EC_INTEGRITY_DIR="$EC_TRUST_DIR/integrity"
EC_INTEGRITY_PUBLIC="$work/public"
EC_UNIT_DIR="$work/units"
EC_LIB_DIR="$work/libdir"
EC_BIN="$work/bin/ec-sugar"
EC_TUNNEL_DIR="$work/tunnel"
mkdir -p "$EC_STATE" "$EC_UNIT_DIR/ec-sugar-backup.timer.d" "$EC_LIB_DIR/lib" "$work/bin" "$EC_TRUST_DIR"
answers0='CHANNEL=release
FRONT=caddy'
printf '%s\n' "$answers0" > "$EC_ANSWERS"
printf 'EC_SUGAR_BIND=127.0.0.1\nAPP_KEY=base64:secret-should-not-leak\nADMIN_ALLOWED_IPS=203.0.113.5/32\n' > "$EC_ENV_FILE"
commit=0123456789abcdef0123456789abcdef01234567
printf '%s\n' "$commit" > "$EC_STATE/current_commit"

# compose の置き場（公開版の形の root だけの置き場の代わり）と、その git の中身（確かめたコミット。ec_git cat-file が読む）
tree="$work/tree"
mkdir -p "$tree/docker/nginx" "$tree/docker/caddy/etc"
printf 'name: ec-sugar\n' > "$tree/docker-compose.prod.yml"
printf 'server {}\n' > "$tree/docker/nginx/prod.conf"
printf 'listen 8080;\n' > "$tree/docker/nginx/front-caddy.inc"
printf '{\n\tadmin off\n}\n' > "$tree/docker/caddy/etc/Caddyfile"
printf 'strict_sni_host on\n' > "$tree/docker/caddy/etc/front-caddy.servers"
cp -R "$tree" "$work/git"
ec_compose_dir() { printf '%s' "$tree"; }
ec_git() {
    case "$1" in
        cat-file) [ -f "$work/git/${3#*:}" ] && cat "$work/git/${3#*:}" ;;
        *) return 0 ;;
    esac
}
# unit・道具・信じる鍵
for f in ec-sugar.service ec-sugar-update.timer ec-sugar-integrity.service ec-sugar-integrity.timer; do printf '[Unit]\n# %s\n' "$f" > "$EC_UNIT_DIR/$f"; done
printf '[Timer]\nOnCalendar=*-*-* 04:15:00 Asia/Tokyo\n' > "$EC_UNIT_DIR/ec-sugar-backup.timer.d/10-timezone.conf"
cp deploy/ec-sugar "$EC_LIB_DIR/ec-sugar"
cp "$lib" "$EC_LIB_DIR/lib/common.sh"
ln -s "$EC_LIB_DIR/ec-sugar" "$EC_BIN"
signers='owner namespaces="git" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl'
printf '%s\n' "$signers" > "$EC_TRUST_FILE"

# ---------- 偽の docker ----------
# コンテナの表（$work/containers。タブ区切りで ID・動いているか・イメージ ID・プロジェクト・サービス・一度きりか・名前・設定・差し込み）。
# inspect は表をそのまま返す（common.sh の書式と同じ列。設定と差し込みは common.sh が並べ替えて指紋にする）。
# exec は、web の中の記録の時刻（$work/fetch。fake_fetch で置く）か、コードの木の hash（$work/code.<ID>。無ければ決まった値）を返す
img() { printf 'sha256:%064d' "$1"; }
row() { printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$@"; }
mounts() { printf 'volume,/var/lib/docker/volumes/ec-sugar_%s/_data,/data,true;bind,/srv/x,/etc/x,false;' "$1"; }
healthy_table() {
    {
        row c-app true "$(img 1)" ec-sugar app False /ec-sugar-app-1 'false null ["ALL"] cfg-app' "$(mounts app)"
        row c-queue true "$(img 1)" ec-sugar queue False /ec-sugar-queue-1 'false null ["ALL"] cfg-queue' "$(mounts queue)"
        row c-scheduler true "$(img 1)" ec-sugar scheduler False /ec-sugar-scheduler-1 'false null ["ALL"] cfg-scheduler' "$(mounts scheduler)"
        # web はイメージの public/ を差し込む（type: image）。元の道筋（overlayfs）は作り直すたびに変わる
        row c-web true "$(img 2)" ec-sugar web False /ec-sugar-web-1 'false null ["ALL"] cfg-web' "$(mounts web)image,/var/lib/docker/rootfs/overlayfs/aaa,/var/www/html/public,false;"
        row c-caddy true "$(img 3)" ec-sugar caddy False /ec-sugar-caddy-1 'false null ["ALL"] cfg-caddy' "$(mounts caddy)"
        row c-db true "$(img 4)" ec-sugar db False /ec-sugar-db-1 'false null ["ALL"] cfg-db' "$(mounts db)"
        row c-redis true "$(img 5)" ec-sugar redis False /ec-sugar-redis-1 'false null ["ALL"] cfg-redis' "$(mounts redis)"
        # 止まっているほかのコンテナは数えない
        row c-old false "$(img 9)" '<no value>' '<no value>' '<no value>' /old-stopped 'true' ''
    } > "$work/containers"
}
# サービス $1 の（一度きりでない）コンテナの列 $2（3 はイメージ ID・8 は設定・9 は差し込み）を $3 に替える
set_col() {
    awk -F '\t' -v OFS='\t' -v s="$1" -v c="$2" -v v="$3" '$5 == s && $6 == "False" {$c = v} {print}' "$work/containers" > "$work/c.tmp"
    mv "$work/c.tmp" "$work/containers"
}
set_image() { while [ $# -ge 2 ]; do set_col "$1" 3 "$(img "$2")"; shift 2; done; }
fake_fetch() { printf '%s' "$1" > "$work/fetch"; }
FAKE_DOCKER_FAIL=0
docker() {
    local id script
    [ "$FAKE_DOCKER_FAIL" = 0 ] || return 1
    case "$1" in
        ps) cut -f1 "$work/containers" ;;
        inspect)
            # 1 つずつの宣言の問い（イメージを差し込むコンテナだけ。common.sh）は $work/declared.<ID>、無ければ決まった値。
            # まとめての問いは、本物と同じく宣言の鍵を書式に入れていたら断る（宣言の無いコンテナで止まるため）
            if [ "$2" = --format ] && [ "$3" = '{{json .HostConfig.Mounts}}' ]; then
                cat "$work/declared.$4" 2> /dev/null || printf '[{"Type":"image","Source":"ec-sugar:x","Target":"/var/www/html/public"}]'
            elif [[ "$3" == *HostConfig.Mounts* ]]; then
                echo 'map has no entry for key "Mounts"' >&2
                return 1
            else
                cat "$work/containers"
            fi
            ;;
        exec)
            id="$2"
            script="${*: -1}"
            printf '%s\n' "$*" >> "$work/exec.log"
            case "$script" in
                *fetch.log*) if [ -s "$work/fetch" ]; then cat "$work/fetch"; rm -f "$work/fetch"; fi ;;
                *sha256sum*) if [ -f "$work/code.$id" ]; then cat "$work/code.$id"; else printf '%064d  -\n' 7; fi ;;
            esac
            ;;
        *) return 1 ;;
    esac
}
# 運営の様子へ渡す（ec_host_report）の代わり: 呼ばれた回数を数える（本物は 7 の終わりで戻して確かめる）
eval "real_$(declare -f ec_host_report)"
FAKE_REPORT_RC=0
ec_host_report() {
    printf 'x\n' >> "$work/report.calls"
    return "$FAKE_REPORT_RC"
}
reports() { wc -l < "$work/report.calls" 2> /dev/null | tr -d ' ' || echo 0; }

pub="$work/pub.pem"
json_file="$EC_INTEGRITY_PUBLIC/integrity.json"
sig_file="$EC_INTEGRITY_PUBLIC/integrity.json.sig"
# 外の見張りと同じ確かめ方（base64 を戻して、公開の鍵で integrity.json のバイト列そのものを確かめる）。$1 様子のファイル（既定は書いたもの）
verify() {
    local file="${1:-$json_file}"
    openssl base64 -d -A -in "$sig_file" -out "$work/sig.bin" 2> /dev/null &&
        openssl pkeyutl -verify -pubin -inkey "$pub" -rawin -in "$file" -sigfile "$work/sig.bin" > /dev/null 2>&1
}
json() { cat "$json_file"; }
# 公開の JSON の種類（, 区切り）と、中の細かい名前（照らしの出力。, 区切り）
kinds() { sed -n 's/.*"problems":\[\([^]]*\)\].*/\1/p' "$json_file" | tr -d '"'; }
inner() { grep -E '^[a-z][a-z0-9-]*:' "$work/check.out" | paste -sd, - || true; }
check() { ec_integrity_check > "$work/check.out" 2> "$work/check.err" || true; }
mode_of() { stat -c %a "$1" 2> /dev/null || stat -f %Lp "$1"; }
# 照らして、中の名前に $1 があり、公開の種類に $2 があるか（$3 は何をしたか）
expect_problem() {
    local want="$1" kind="$2" what="$3"
    check
    if [[ ",$(inner)," == *",$want,"* ]] && [[ ",$(kinds)," == *",$kind,"* ]] && [[ "$(json)" == *'"ok":false'* ]] && [[ "$(json)" != *"$want"* || "$want" = "$kind" ]] && verify; then
        ok "${what}: 中は ${want}・公開は ${kind}（ok は false・署名は通る）"
    else
        ng "${what}で ${want}（公開 ${kind}）が出ない: 中 $(inner)・公開 $(json)"
    fi
}
expect_ok() {
    check
    [ -z "$(inner)" ] && [[ "$(json)" == *'"ok":true'* ]] && ok "$1" || ng "$1 のはずが困りごとがある: 中 $(inner)"
}

# ---------- 1. 控えて照らすと ok ----------
healthy_table
if ec_integrity_record always > /dev/null 2>&1 && [ -s "$EC_INTEGRITY_DIR/expected" ] && grep -q '^code app ' "$EC_INTEGRITY_DIR/expected" &&
    grep -q '^setting env:EC_SUGAR_BIND ' "$EC_INTEGRITY_DIR/expected" && grep -Eq '^service app sha256:[0-9]{64} [0-9a-f]{64}$' "$EC_INTEGRITY_DIR/expected"; then
    ok "初めは全部を控える（サービスのイメージ ID と設定の指紋・コードの木・ファイル・設定の値）"
else
    ng "あるべき姿を控えられない: $(head -c 400 "$EC_INTEGRITY_DIR/expected" 2> /dev/null | tr '\n' '|')"
fi
ec_integrity_pubkey > "$pub" 2> /dev/null || ng "公開の鍵を出せない"
grep -q '^-----BEGIN PUBLIC KEY-----$' "$pub" && openssl pkey -pubin -in "$pub" -noout -text 2> /dev/null | q -i 'ED25519' && ok "公開の鍵を Ed25519 の PEM で出す" || ng "公開の鍵が Ed25519 の PEM でない"
expect_ok "控えたまま照らすと ok（困りごとなし）"
token1="$(ec_integrity_token)"
[[ "$token1" =~ ^[0-9a-f]{32}$ ]] && [ "$(ec_integrity_token)" = "$token1" ] && ok "外の見張りの合い言葉は 32 桁の 16 進で、一度だけ作る" || ng "合い言葉の形が違うか、作り直された"
[ "$(mode_of "$EC_INTEGRITY_DIR/key")" = 400 ] && [ "$(mode_of "$EC_INTEGRITY_DIR")" = 700 ] && [ "$(mode_of "$EC_INTEGRITY_DIR/expected")" = 600 ] &&
    [ "$(mode_of "$EC_INTEGRITY_DIR/salt")" = 600 ] && [ "$(mode_of "$EC_INTEGRITY_DIR/sentinel_token")" = 600 ] &&
    [ "$(mode_of "$json_file")" = 644 ] && [ "$(mode_of "$sig_file")" = 644 ] && [ "$(mode_of "$(dirname "$EC_LOCK")")" = 700 ] &&
    ok "鍵は 0400・置き場と錠の置き場は 0700・控えと塩と合い言葉は 0600・様子と署名は 0644" ||
    ng "権限が違う（鍵 $(mode_of "$EC_INTEGRITY_DIR/key")・置き場 $(mode_of "$EC_INTEGRITY_DIR")・塩 $(mode_of "$EC_INTEGRITY_DIR/salt")・錠 $(mode_of "$(dirname "$EC_LOCK")")）"
server1="$(ec_integrity_server)"
[[ "$server1" =~ ^[0-9a-f]{16}$ ]] && ok "識別子（server）は 16 桁の 16 進" || ng "識別子の形が違う（${server1}）"
! grep -q 'secret-should-not-leak\|203.0.113.5' "$EC_INTEGRITY_DIR/expected" && ok "控えに設定の値の平文を入れない（塩つきの hash だけ）" || ng "控えに設定の値が平文で入った"

# ---------- 2. 署名と形 ----------
want_re='^\{"format":1,"server":"[0-9a-f]{16}","generation":[0-9]+,"checked_at":"[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z","ok":(true|false),"problems":\[("[a-z]+"(,"[a-z]+")*)?\](,"busy":true)?\}$'
[[ "$(json)" =~ $want_re ]] && [ "$(wc -l < "$json_file" | tr -d ' ')" = 0 ] && ok "様子は決めた形の 1 行の JSON（末尾に改行なし）" || ng "様子の形が違う: $(json)"
[[ "$(json)" == *"\"server\":\"$server1\""* ]] && [[ "$(json)" == *'"generation":1,'* ]] && [[ "$(json)" != *busy* ]] && ok "識別子と世代 1 が載り、busy は無い" || ng "識別子か世代が違う: $(json)"
[ "$(wc -l < "$sig_file" | tr -d ' ')" = 0 ] && grep -Eq '^[A-Za-z0-9+/]+={0,2}$' "$sig_file" && ok "署名は base64 の 1 行" || ng "署名の形が違う"
verify && ok "署名が openssl pkeyutl -verify -pubin -rawin -sigfile で通る" || ng "署名が通らない"
sed 's/"format":1/"format":2/' "$json_file" > "$work/tampered.json"
[ "$(cmp -l "$json_file" "$work/tampered.json" | wc -l | tr -d ' ')" = 1 ] || ng "1 バイトだけ変えられていない（試しの作り方の誤り）"
verify "$work/tampered.json" && ng "1 バイト変えても署名が通る" || ok "様子を 1 バイト変えると署名が通らない"
openssl genpkey -algorithm ed25519 -out "$work/other.key" 2> /dev/null
openssl pkeyutl -sign -inkey "$work/other.key" -rawin -in "$json_file" -out "$work/other.bin" 2> /dev/null
openssl pkeyutl -verify -pubin -inkey "$pub" -rawin -in "$json_file" -sigfile "$work/other.bin" > /dev/null 2>&1 && ng "別の鍵の署名が通る" || ok "別の鍵の署名は通らない"

# ---------- 3. 書き換えに気づく ----------
healthy_table
set_image app 7
expect_problem image:app image "イメージ ID を差し替えた"
[ "$(inner)" = image:app ] && [ "$(kinds)" = image ] && ok "差し替えたサービスの名前だけが中に、種類だけが公開に出る" || ng "ほかの名前も出た: $(inner)・$(kinds)"
healthy_table
set_col app 8 'true null ["ALL"] cfg-app'
expect_problem config:app config "同じイメージで特権を付けて作り直した"
healthy_table
set_col web 9 "$(mounts web)bind,/,/host,true;"
expect_problem config:web config "差し込みを足した"
healthy_table
set_col app 9 "bind,/srv/x,/etc/x,false;volume,/var/lib/docker/volumes/ec-sugar_app/_data,/data,true;"
expect_ok "差し込みの並びが違うだけなら同じ指紋"
healthy_table
set_col web 9 "$(mounts web)image,/var/lib/docker/rootfs/overlayfs/bbb,/var/www/html/public,false;"
expect_ok "web を作り直してイメージの差し込みの元の道筋（overlayfs）が変わっただけなら同じ指紋（起動のたびに web を作り直すため）"
healthy_table
set_col web 9 "$(mounts web)image,/var/lib/docker/rootfs/overlayfs/aaa,/var/www/html,false;"
expect_problem config:web config "イメージの差し込み先を変えた"
healthy_table
set_col web 9 "$(mounts web)image,/var/lib/docker/rootfs/overlayfs/aaa,/var/www/html/public,true;"
expect_problem config:web config "イメージの差し込みを書ける形にした"
healthy_table
printf '%s' '[{"Type":"image","Source":"other:latest","Target":"/var/www/html/public"}]' > "$work/declared.c-web"
expect_problem config:web config "差し込むイメージ（宣言の HostConfig.Mounts）を替えた"
rm -f "$work/declared.c-web"
healthy_table
expect_ok "イメージを差し込まないコンテナ（宣言の無いもの）も、まとめての問いで全部数える（service:missing にならない）"
# 前の照らし方（1.1.1 まで）の道具が書いた控えの読み替え。web の控えの行を、前の照らし方の指紋（一覧の 9 列目）にして試す
legacy_expected() {
    local legacy now
    legacy="$(ec_integrity_containers | awk -F '\t' '$5 == "web" {print $9}')"
    now="$(ec_integrity_containers | awk -F '\t' '$5 == "web" {print $8}')"
    [ -n "$legacy" ] && [ "$legacy" != "$now" ] || ng "前の照らし方の指紋が今の指紋と同じ（試しの作り方の誤り）"
    awk -v l="$legacy" '$1 == "service" && $2 == "web" {$4 = l} {print}' "$EC_INTEGRITY_DIR/expected" > "$work/e.tmp" && cat "$work/e.tmp" > "$EC_INTEGRITY_DIR/expected"
    printf '%s %s' "$legacy" "$now"
}
healthy_table
read -r legacy_fp now_fp <<< "$(legacy_expected)"
gen_before="$(ec_integrity_generation)"
expect_ok "前の照らし方の控えで、今と同じ状態なら ok（更新の直後に鳴らない）"
grep -q "^service web sha256:[0-9]* $now_fp\$" "$EC_INTEGRITY_DIR/expected" && [ "$(ec_integrity_generation)" = "$gen_before" ] &&
    ok "控えを今の照らし方に読み替え、世代は上げない" || ng "読み替えていないか、世代が変わった（世代 $gen_before → $(ec_integrity_generation)）"
set_col web 9 "$(mounts web)image,/var/lib/docker/rootfs/overlayfs/ccc,/var/www/html/public,false;"
expect_ok "読み替えた後は、web を作り直しても ok"
healthy_table
read -r legacy_fp now_fp <<< "$(legacy_expected)"
set_image app 7
expect_problem image:app image "前の照らし方の控えでも、ほかの困りごとは出す"
[[ ",$(inner)," != *",config:web,"* ]] && grep -q "^service web sha256:[0-9]* $legacy_fp\$" "$EC_INTEGRITY_DIR/expected" &&
    ok "ほかに困りごとがある時は読み替えない（違いのある時に黙って控えを書き換えない）" || ng "困りごとがあるのに読み替えたか、config:web を出した: $(inner)"
healthy_table
expect_ok "読み替えずに残った控えも、同じ状態に戻れば ok"
healthy_table
read -r legacy_fp now_fp <<< "$(legacy_expected)"
set_col web 9 "$(mounts web)image,/var/lib/docker/rootfs/overlayfs/ddd,/var/www/html/public,false;"
expect_problem config:web config "前の照らし方の控えで、web が作り直されていれば今までどおり知らせる"
healthy_table
ec_integrity_record always service > /dev/null 2>&1 || ng "控え直せない"
expect_ok "控え直すと ok に戻る"
healthy_table
grep -v '^c-web' "$work/containers" > "$work/c.tmp" && mv "$work/c.tmp" "$work/containers"
row c-web false "$(img 2)" ec-sugar web False /ec-sugar-web-1 'false null ["ALL"] cfg-web' "$(mounts web)" >> "$work/containers"
expect_problem service:missing:web service "web が止まった"
# 名札を偽ったコンテナ（同じイメージ・同じ名札で、設定が違う / 同じ設定で数が増える）
healthy_table
row c-fake true "$(img 1)" ec-sugar app False /ec-sugar-app-2 'true null null host' "bind,/,/host,true;" >> "$work/containers"
expect_problem config:app config "名札を偽った特権のコンテナ（同じイメージ）"
[[ ",$(inner)," == *",container:extra,"* ]] && ok "名札を偽ったコンテナで数が増えたことも出る（container:extra）" || ng "数が増えたのに container:extra が出ない: $(inner)"
healthy_table
row c-run true "$(img 1)" ec-sugar app True /ec-sugar-app-run-1 'false null ["ALL"] cfg-app' "$(mounts app)" >> "$work/containers"
expect_problem container:extra container "錠の外の一度きりのコンテナ（同じイメージ・同じ設定でも）"
# compose の外のコンテナと、許す組
healthy_table
row c-evil true "$(img 8)" '<no value>' '<no value>' '<no value>' /miner 'false null null x' '' >> "$work/containers"
expect_problem container:extra container "compose の外のコンテナが動いている"
grep -q '^miner sha256:' "$EC_INTEGRITY_DIR/extra" && grep -q ' config:[0-9a-f]\{64\}$' "$EC_INTEGRITY_DIR/extra" &&
    ok "許していないコンテナの名前・イメージ ID・設定の指紋を root の記録に残す（integrity check が出す）" || ng "許していないコンテナの記録が無い"
miner_cfg="$(awk '/^miner /{print $3}' "$EC_INTEGRITY_DIR/extra")"
allow() {
    printf '%s\nINTEGRITY_ALLOW_CONTAINERS=%s\n' "$answers0" "$1" > "$EC_ANSWERS"
    # 答えを替えたこと自体も違いなので、その値だけを控え直してから照らす
    ec_integrity_record always answers:INTEGRITY_ALLOW_CONTAINERS > /dev/null 2>&1
}
allow 'miner'
expect_problem container:extra container "名前だけの許し（--name で偽れる）は数えない"
: > "$work/glob-miner"
allow '*'
expect_problem container:extra container "* だけ（形の無い許し）は数えない"
allow "min*@$(img 9)"
expect_problem container:extra container "名前は合うがイメージ ID が違う許しは数えない"
allow "backup-*@sha256:$(printf '%064d' 1),min*@$(img 8)"
expect_ok "名前とイメージ ID の組で許したコンテナは数えない"
allow "miner@$miner_cfg"
expect_ok "名前と設定の指紋の組で許したコンテナは数えない"
printf '%s\n' "$answers0" > "$EC_ANSWERS"
ec_integrity_record always answers:INTEGRITY_ALLOW_CONTAINERS > /dev/null 2>&1
healthy_table
expect_ok "許しを外して元に戻すと ok"
printf '%s\nINTEGRITY_ALLOW_CONTAINERS=miner@%s\n' "$answers0" "$(img 8)" > "$EC_ANSWERS"
expect_problem file:answers-integrity_allow_containers file "答えの INTEGRITY_ALLOW_CONTAINERS を黙って書き換えた"
printf '%s\n' "$answers0" > "$EC_ANSWERS"
# env の値（EC_SUGAR_BIND を 0.0.0.0 に）
sed -i.bak 's/^EC_SUGAR_BIND=.*/EC_SUGAR_BIND=0.0.0.0/' "$EC_ENV_FILE"
expect_problem file:env-ec_sugar_bind file "env の EC_SUGAR_BIND を 0.0.0.0 にした"
mv "$EC_ENV_FILE.bak" "$EC_ENV_FILE"
set_col caddy 8 'false null ["ALL"] cfg-caddy {"443/tcp":[{"HostIp":"0.0.0.0","HostPort":"443"}]}'
expect_problem config:caddy config "ポートを全部の住所に出し直した（PortBindings）"
healthy_table
# コードの木（イメージの中身をその場で書き換える。10 分ごと）
printf '%064d  -\n' 8 > "$work/code.c-app"
check
[[ ",$(inner)," != *",code:app,"* ]] && ok "コードの木は 10 分の間は前の結果を使う（毎分は読まない）" || ng "コードの木を毎分照らした"
EC_INTEGRITY_CODE_EVERY=0
expect_problem code:app code "app のイメージの中のコードを書き換えた"
head -c 1048576 /dev/zero | tr '\0' 'f' > "$work/code.c-app"
expect_problem code:app code "コードの木の答えが大きすぎる（読むのは上限まで）"
rm -f "$work/code.c-app"
expect_ok "コードの木を元に戻すと ok"
EC_INTEGRITY_CODE_EVERY=600
# ファイル
printf '  evil: {}\n' >> "$tree/docker-compose.prod.yml"
expect_problem file:compose file "compose を書き換えた"
cp "$work/git/docker-compose.prod.yml" "$tree/docker-compose.prod.yml"
printf 'evil\n' > "$tree/docker/caddy/etc/extra.caddy"
expect_problem file:caddy-extra.caddy file "Caddy の設定の置き場にファイルが足された"
rm -f "$tree/docker/caddy/etc/extra.caddy"
printf '# evil\n' >> "$EC_LIB_DIR/ec-sugar"
expect_problem file:tool-ec-sugar file "運用の道具を書き換えた"
cp deploy/ec-sugar "$EC_LIB_DIR/ec-sugar"
cp deploy/ec-sugar "$work/evil-ec-sugar"
ln -sfn "$work/evil-ec-sugar" "$EC_BIN"
expect_problem file:tool-ec-sugar file "/usr/local/bin/ec-sugar を同じ中身の別のファイルへ付け替えた"
ln -sfn "$EC_LIB_DIR/ec-sugar" "$EC_BIN"
printf '# evil\n' >> "$EC_LIB_DIR/lib/common.sh"
expect_problem file:tool-common.sh file "lib を書き換えた"
cp "$lib" "$EC_LIB_DIR/lib/common.sh"
printf '[Service]\nExecStart=/tmp/x\n' > "$EC_UNIT_DIR/ec-sugar-evil.service"
expect_problem file:unit-ec-sugar-evil.service file "unit が足された"
rm -f "$EC_UNIT_DIR/ec-sugar-evil.service"
printf 'OnCalendar=*:*\n' >> "$EC_UNIT_DIR/ec-sugar-backup.timer.d/10-timezone.conf"
expect_problem file:unit-ec-sugar-backup.timer.d-10-timezone.conf file "unit の drop-in を書き換えた"
printf '[Timer]\nOnCalendar=*-*-* 04:15:00 Asia/Tokyo\n' > "$EC_UNIT_DIR/ec-sugar-backup.timer.d/10-timezone.conf"
printf 'evil namespaces="git" ssh-ed25519 AAAA\n' >> "$EC_TRUST_FILE"
expect_problem file:trust-signers file "タグの署名で信じる鍵が足された"
printf '%s\n' "$signers" > "$EC_TRUST_FILE"
for i in $(seq 10 34); do printf '[Unit]\n' > "$EC_UNIT_DIR/ec-sugar-many$i.service"; done
check
[ "$(inner | tr ',' '\n' | grep -c .)" = 20 ] && [ "$(kinds)" = file ] && [[ "$(json)" =~ $want_re ]] && verify &&
    ok "困りごとが 25 個でも、中は名前の順に 20 個・公開は種類だけ（形のまま、署名が通る）" || ng "困りごとの上限か種類のまとめが効かない（$(inner | tr ',' '\n' | grep -c .) 個・$(kinds)）"
rm -f "$EC_UNIT_DIR"/ec-sugar-many*.service
FAKE_DOCKER_FAIL=1
expect_problem docker:unreachable docker "Docker に聞けない"
FAKE_DOCKER_FAIL=0
mv "$EC_INTEGRITY_DIR/expected" "$work/expected.saved"
expect_problem expected:missing expected "控えが消された"
mv "$work/expected.saved" "$EC_INTEGRITY_DIR/expected"
expect_ok "元に戻すと ok に戻る"

# ---------- 4. 錠を持っている間は busy ----------
healthy_table
set_image app 7
check
before_busy="$(json)"
exec 9> "$EC_LOCK"
flock -n 9
healthy_table
sleep 1
check
if [[ "$(json)" == *'"ok":false'* ]] && [[ "$(json)" == *'"problems":["image"]'* ]] && [[ "$(json)" == *'"busy":true}' ]] && verify && [ "$(json)" != "$before_busy" ]; then
    ok "錠を持っている処理がある間は照らさず、前の結果に busy を足して署名だけ新しくする（checked_at は新しい）"
else
    ng "錠を持っている間の様子が違う: $(json)"
fi
# 30 分を超えて握られている → lock:held（中の知らせにも）
printf '%s\n' "$(($(date -u +%s) - 1801))" > "$EC_INTEGRITY_DIR/busy_since"
n="$(reports)"
check
if [[ "$(json)" == *'"problems":["image","lock"]'* ]] && [[ "$(json)" == *'"busy":true}' ]] && [[ "$(ec_state_get integrity)" == *lock:held* ]] && [ "$(reports)" = $((n + 1)) ] &&
    printf '%s\n' "$(ec_report_state_args)" | q -x -- '--integrity-problem=lock:held'; then
    ok "錠が 30 分を超えて握られていれば lock:held（公開は lock、運営の様子へも渡す）"
else
    ng "30 分を超えた錠が困りごとにならない: $(json)・$(ec_state_get integrity)"
fi
exec 9>&-
check
[[ "$(json)" != *busy* ]] && [ "$(inner)" = "" ] && [ ! -e "$EC_INTEGRITY_DIR/busy_since" ] && [[ "$(ec_state_get integrity)" != *lock:held* ]] &&
    ok "錠が放されたら照らして busy と lock:held を外す" || ng "錠が放されても busy のまま: $(json)"
# 前の置き場（だれでも開けた /run/ec-sugar.lock）を握られても busy にならない
[ "$(dirname "$EC_LOCK")" != "$(dirname /run/ec-sugar.lock)" ] && grep -q '^EC_LOCK=/run/ec-sugar/lock$' "$lib" && ok "錠は root だけの置き場の中（/run/ec-sugar/lock）" || ng "錠の置き場が root だけの所でない"

# ---------- 5. 控え直し ----------
gen0="$(ec_integrity_generation)"
ec_integrity_record always all > /dev/null 2>&1
[ "$(ec_integrity_generation)" = "$gen0" ] && ok "中身が同じなら控え直しても世代は上がらない" || ng "中身が同じなのに世代が上がった（$(ec_integrity_generation)）"
# 更新: イメージを替え、道具を替え、ついでに足された unit と信じる鍵が残っていても、更新が作り直したものだけを控える
set_image app 7 queue 7 scheduler 7
printf '# 新しいバージョン\n' >> "$EC_LIB_DIR/ec-sugar"
printf '[Service]\nExecStart=/tmp/x\n' > "$EC_UNIT_DIR/ec-sugar-evil.service"
printf 'evil namespaces="git" ssh-ed25519 AAAA\n' >> "$EC_TRUST_FILE"
sed -i.bak 's/^EC_SUGAR_BIND=.*/EC_SUGAR_BIND=0.0.0.0/' "$EC_ENV_FILE"
printf 'EC_SUGAR_IMAGE=ec-sugar:new\n' >> "$EC_ENV_FILE"
ec_integrity_record always service code compose tools units settings-new env:EC_SUGAR_IMAGE env:SUGAR_EC_IMAGE > /dev/null 2>&1
check
if [ "$(ec_integrity_generation)" = $((gen0 + 1)) ] && [ "$(inner)" = "file:env-ec_sugar_bind,file:trust-signers,file:unit-ec-sugar-evil.service" ]; then
    ok "更新の控え直しはイメージ・道具・新しい設定の名前だけ（足された unit・信じる鍵・書き換えた値は前の控えのまま違いに出る）"
else
    ng "更新の控え直しが作り直していないものまで控えた（世代 $(ec_integrity_generation)・中 $(inner)）"
fi
ec_integrity_record always tools units > /dev/null 2>&1
check
[[ ",$(inner)," == *",file:unit-ec-sugar-evil.service,"* ]] && ok "install-units（道具と決まった unit）は足された unit を控えない" || ng "install-units が足された unit を控えた"
rm -f "$EC_UNIT_DIR/ec-sugar-evil.service"
printf '%s\n' "$signers" > "$EC_TRUST_FILE"
mv "$EC_ENV_FILE.bak" "$EC_ENV_FILE"
printf 'EC_SUGAR_IMAGE=ec-sugar:new\n' >> "$EC_ENV_FILE"
expect_ok "元に戻すと ok"
# 前のバージョンの道具からの更新: 更新を走らせるのは前の道具のプロセスで、その「自分の unit」の一覧に、新しいバージョンで足した unit
# （ec-sugar-offsite.*）は無い。新しい道具の install-units（更新の中から。EC_SUGAR_INTEGRITY_RECORD=skip）が自分の入れた unit の分だけを控え、
# その後で前の道具がまとめて控え直す。足した unit を「足された unit」（要対応）にしないこと。よその人が足した unit は、今までどおり違いに出ること
new_units="$EC_INTEGRITY_OWN_UNITS"
old_units="${EC_INTEGRITY_OWN_UNITS/ ec-sugar-offsite.service ec-sugar-offsite.timer/}"
[ "$old_units" != "$new_units" ] && [[ " $old_units " != *offsite* ]] || ng "前の道具の unit の一覧を作れていない（${old_units}）"
old_tool_update() { (
    EC_INTEGRITY_OWN_UNITS="$old_units"
    ec_integrity_record always service code compose tools units settings-new env:EC_SUGAR_IMAGE env:SUGAR_EC_IMAGE
) > /dev/null 2>&1 || true; }
for f in ec-sugar-offsite.service ec-sugar-offsite.timer; do printf '[Unit]\n# %s\n' "$f" > "$EC_UNIT_DIR/$f"; done
# （対照）新しい道具が何も控えなければ、前の道具の控え直しの後に、足した unit が違いに出る＝この段が要る
old_tool_update
check
[ "$(inner)" = "file:unit-ec-sugar-offsite.service,file:unit-ec-sugar-offsite.timer" ] && ok "（対照）前の道具だけが控え直すと、新しいバージョンで足した unit は「足された unit」として違いに出る" ||
    ng "対照が成り立たない（前の道具の控え直しの後の違い: $(inner)）"
printf '[Service]\nExecStart=/tmp/x\n' > "$EC_UNIT_DIR/ec-sugar-evil.service"
ec_integrity_record always units > /dev/null 2>&1
old_tool_update
check
[ "$(inner)" = "file:unit-ec-sugar-evil.service" ] && ok "前の道具からの更新: 新しい道具が入れた unit は違いに出ず、よその人が足した unit は今までどおり違いに出る" ||
    ng "前の道具からの更新の後の違いが違う（$(inner)）"
awk '/^cmd_install_units\(\) \{/ {inblk = 1} inblk && /^    else$/ {branch = 1} inblk && branch && index($0, "ec_integrity_record always units ||") {found = 1} inblk && /^}/ {inblk = 0} END {exit found ? 0 : 1}' deploy/ec-sugar &&
    ok "install-units は、更新の中から呼ばれた時（skip）も、自分が入れた unit の分だけは控え直す" || ng "install-units が、更新の中から呼ばれた時に自分の unit を控えない（前の道具からの更新で誤報になる）"
rm -f "$EC_UNIT_DIR/ec-sugar-evil.service" "$EC_UNIT_DIR/ec-sugar-offsite.service" "$EC_UNIT_DIR/ec-sugar-offsite.timer"
ec_integrity_record always units > /dev/null 2>&1
expect_ok "足した unit を外して控え直すと ok"
# compose は確かめたコミットの git の中身とそろう時だけ（置き場が前から書き換えられていたら控えない）
printf '  evil: {}\n' >> "$tree/docker-compose.prod.yml"
ec_integrity_record always service compose > "$work/rec.out" 2>&1
check
[[ ",$(inner)," == *",file:compose,"* ]] && grep -q 'git の中身とそろわない' "$work/rec.out" && ok "git の中身とそろわない compose は、更新の控え直しでも控えない" || ng "書き換えた compose を控えた（中 $(inner)）"
cp "$work/git/docker-compose.prod.yml" "$tree/docker-compose.prod.yml"
# 開発の形も git status に頼らない（skip-worktree で隠しても、git の中身と比べる）
printf 'CHANNEL=source\nFRONT=caddy\n' > "$EC_ANSWERS"
ec_integrity_record always answers:CHANNEL > /dev/null 2>&1
printf '  hidden: {}\n' >> "$tree/docker-compose.prod.yml"
ec_integrity_record always compose > /dev/null 2>&1
check
[[ ",$(inner)," == *",file:compose,"* ]] && ok "開発の形で作業場所の compose を書き換えて隠しても（git status は空）、控え直さない" || ng "開発の形で書き換えた compose を控えた"
cp "$work/git/docker-compose.prod.yml" "$tree/docker-compose.prod.yml"
printf '%s\n' "$answers0" > "$EC_ANSWERS"
ec_integrity_record always answers:CHANNEL > /dev/null 2>&1
expect_ok "元に戻すと ok"
# 設定のコマンドの後（if-ok）: そのコマンドが書いた値だけなら控え、ほかに違いがあれば控えない
gen1="$(ec_integrity_generation)"
sed -i.bak 's#^ADMIN_ALLOWED_IPS=.*#ADMIN_ALLOWED_IPS=198.51.100.7/32#' "$EC_ENV_FILE" && rm -f "$EC_ENV_FILE.bak"
printf '# evil\n' >> "$EC_LIB_DIR/ec-sugar"
ec_integrity_record if-ok env:ADMIN_ALLOWED_IPS answers:ADMIN_ALLOWED_IPS > "$work/rec.out" 2>&1 && ng "ほかに違いがあるのに設定のコマンドの後で控え直した" ||
    { [ "$(ec_integrity_generation)" = "$gen1" ] && grep -q 'file:tool-ec-sugar' "$work/rec.out" && ok "設定のコマンドの後は照らし直し、書いていない所（道具）に違いがあれば控えない（名前を出す）" || ng "控えなかった時の文か世代が違う"; }
cp deploy/ec-sugar "$EC_LIB_DIR/ec-sugar"
printf '# 新しいバージョン\n' >> "$EC_LIB_DIR/ec-sugar"
if ec_integrity_record if-ok env:ADMIN_ALLOWED_IPS answers:ADMIN_ALLOWED_IPS > /dev/null 2>&1 && [ "$(ec_integrity_generation)" = $((gen1 + 1)) ]; then
    expect_ok "設定のコマンドが書いた値だけの違いなら控え直す（admin-ips）"
else
    ng "書いた値だけなのに控え直さない"
fi
# 外への通信の形を替えた後（ec-sugar egress）も同じ: 書いた形の違いだけなら控え直す
gen2="$(ec_integrity_generation)"
if grep -q '^EC_SUGAR_EGRESS_MODE=' "$EC_ENV_FILE"; then
    sed -i.bak 's#^EC_SUGAR_EGRESS_MODE=.*#EC_SUGAR_EGRESS_MODE=enforce#' "$EC_ENV_FILE" && rm -f "$EC_ENV_FILE.bak"
else
    printf 'EC_SUGAR_EGRESS_MODE=enforce\n' >> "$EC_ENV_FILE"
fi
if ec_integrity_record if-ok env:EC_SUGAR_EGRESS_MODE service-config > /dev/null 2>&1 && [ "$(ec_integrity_generation)" = $((gen2 + 1)) ]; then
    expect_ok "外への通信の形を替えた後も、書いた値だけの違いなら控え直す（egress）"
else
    ng "外への通信の形だけなのに控え直さない"
fi
grep -q 'ec_integrity_record if-ok env:EC_SUGAR_EGRESS_MODE service-config' deploy/ec-sugar && ok "ec-sugar egress が形を替えた後に控え直す" || ng "ec-sugar egress が控え直していない"

# ---------- 6. 秘密・パス・ハッシュ・細かい名前を出さない ----------
printf '  evil: {}\n' >> "$tree/docker-compose.prod.yml"
sed -i.bak 's/^EC_SUGAR_BIND=.*/EC_SUGAR_BIND=0.0.0.0/' "$EC_ENV_FILE"
set_col web 8 'true'
check
all="$(json)"
if [[ "$all" != *"$work"* ]] && [[ "$all" != */* ]] && [[ "$all" != *secret-should-not-leak* ]] && [[ "$all" != *0.0.0.0* ]] && ! grep -Eq '[0-9a-f]{64}|sha256' "$json_file" &&
    [[ "$all" != *compose* ]] && [[ "$all" != *env* ]] && [[ "$all" != *web* ]] && [ "$(kinds)" = config,file ]; then
    ok "様子に秘密・パス・ハッシュ・イメージ ID・細かい名前が出ない（種類だけ: $(kinds)）"
else
    ng "様子に出してはいけないものがある: $(json)"
fi
cp "$work/git/docker-compose.prod.yml" "$tree/docker-compose.prod.yml"
mv "$EC_ENV_FILE.bak" "$EC_ENV_FILE"
healthy_table
set_image app 7 queue 7 scheduler 7

# ---------- 7. 外の見張りと、運営の様子への知らせ ----------
rm -f "$work/report.calls" "$EC_STATE/integrity" "$EC_STATE/integrity_sentinel" "$EC_STATE/integrity_report_pending" "$work/exec.log"
check
[ "$(reports)" = 1 ] && [ "$(cut -d' ' -f1 "$EC_STATE/integrity")" = ok ] && ok "初めの結果を残して 1 回渡す" || ng "初めの結果の渡し方が違う（$(reports) 回・$(cat "$EC_STATE/integrity" 2> /dev/null)）"
check
[ "$(reports)" = 1 ] && ok "変わらなければ渡さない（毎分は渡さない）" || ng "変わらないのに渡した（$(reports) 回）"
grep -q "sh -c f=$EC_INTEGRITY_FETCH_LOG; " "$work/exec.log" && grep -q '^exec c-web ' "$work/exec.log" &&
    ok "外の見張りの記録は web の中で、決まったスクリプトだけで見る" || ng "web の中の見方が違う（$(head -n1 "$work/exec.log" 2> /dev/null)）"
[ ! -e "$EC_STATE/integrity_sentinel" ] && [[ "$(ec_report_state_args)" != *--sentinel* ]] && ok "外の見張りが一度も来ていなければ、困りごとにも報告にもしない" || ng "来ていない見張りを報告した"
set_image app 8
check
args="$(ec_report_state_args)"
[ "$(reports)" = 2 ] && printf '%s\n' "$args" | q -x -- '--integrity=mismatch' && printf '%s\n' "$args" | q -x -- '--integrity-problem=image:app' &&
    ok "違いが出たら細かい名前で渡す（--integrity=mismatch・--integrity-problem=image:app）" || ng "違いの渡し方が違う（$(reports) 回: $(printf '%s' "$args" | tr '\n' ' ')）"
printf 'mismatch 2026-11-13T05:00:00Z image:app /etc/passwd FILE:X\n' > "$EC_STATE/integrity"
args="$(ec_report_state_args)"
printf '%s\n' "$args" | q -x -- '--integrity-problem=image:app' && ! printf '%s\n' "$args" | q 'passwd\|FILE' && ok "形の違う困りごとの名前は渡さない" || ng "形の違う名前を渡した"
set_image app 7
check
n="$(reports)"
fake_fetch "$(date -u +%s)"
check
[ "$(cut -d' ' -f1 "$EC_STATE/integrity_sentinel" 2> /dev/null)" = seen ] && [ "$(reports)" = $((n + 1)) ] && printf '%s\n' "$(ec_report_state_args)" | q -x -- '--sentinel=seen' &&
    ok "外の見張りが来たら seen を残して渡す" || ng "外の見張りが来たのに seen にならない（$(cat "$EC_STATE/integrity_sentinel" 2> /dev/null)）"
before_fetch="$(cat "$EC_INTEGRITY_DIR/last_fetch")"
fake_fetch "$(($(date -u +%s) + 3600))"
check
[ "$(cat "$EC_INTEGRITY_DIR/last_fetch")" = "$before_fetch" ] && ok "時計の先の時刻（1 時間先）は信じない" || ng "先の時刻を残した"
fake_fetch "evil; rm -rf /"
check
[ "$(cat "$EC_INTEGRITY_DIR/last_fetch")" = "$before_fetch" ] && ok "数でない答えは使わない" || ng "数でない答えを残した"
head -c 1048576 /dev/zero | tr '\0' '9' > "$work/fetch"
check
[ "$(cat "$EC_INTEGRITY_DIR/last_fetch")" = "$before_fetch" ] && ok "大きすぎる答えは上限まで読んで使わない" || ng "大きすぎる答えを使った"
rm -f "$work/fetch"
printf '%s\n' "$(($(date -u +%s) - 1801))" > "$EC_INTEGRITY_DIR/last_fetch"
n="$(reports)"
check
[ "$(cut -d' ' -f1 "$EC_STATE/integrity_sentinel")" = silent ] && [ "$(reports)" = $((n + 1)) ] && printf '%s\n' "$(ec_report_state_args)" | q -x -- '--sentinel=silent' &&
    ok "外の見張りが 30 分来ていなければ silent を渡す" || ng "30 分来ていないのに silent にならない（$(cat "$EC_STATE/integrity_sentinel")）"
[[ "$(json)" == *'"ok":true'* ]] && ok "外の見張りが来ていないことは様子（JSON）には載せない" || ng "外の見張りの不在を様子に載せた: $(json)"
FAKE_REPORT_RC=1
set_image app 8
check
[ -e "$EC_STATE/integrity_report_pending" ] && ok "渡せなかったら印を残す" || ng "渡せなかったのに印が無い"
n="$(reports)"
check
[ "$(reports)" = "$n" ] && ok "渡せていない印があっても、10 分経つまでは渡し直さない" || ng "毎分渡し直した"
touch -d '-11 minutes' "$EC_STATE/integrity_report_pending" 2> /dev/null || touch -t "$(date -v-11M +%Y%m%d%H%M 2> /dev/null)" "$EC_STATE/integrity_report_pending"
FAKE_REPORT_RC=0
check
[ "$(reports)" = $((n + 1)) ] && [ ! -e "$EC_STATE/integrity_report_pending" ] && ok "10 分経ったら渡し直し、渡せたら印を消す" || ng "渡し直さない（$(reports) 回）"
grep -v '^c-app' "$work/containers" > "$work/c.tmp" && mv "$work/c.tmp" "$work/containers"
n="$(reports)"
check
[ "$(reports)" = "$n" ] && [ -e "$EC_STATE/integrity_report_pending" ] && ok "app が動いていなければ渡さず、印を残す" || ng "app が動いていないのに渡した"
# 前のバージョンのアプリ（--integrity を知らない）に断られたら、外して渡し直す。--tunnel を知るアプリには --tunnel を残す
eval "$(declare -f real_ec_host_report | sed '1s/^real_//')"
ec_dc() {
    case "$1" in
        ps) printf 'app\n' ;;
        exec) printf '%s\n' "$*" >> "$work/old-app.log"; case "$*" in *--integrity* | *--sentinel*) return 1 ;; esac ;;
    esac
    return 0
}
ec_cert_hosts() { return 0; }
printf 'mismatch 2026-11-13T05:00:00Z image:app\n' > "$EC_STATE/integrity"
printf 'ok 2026-11-13T05:00:00Z\n' > "$EC_STATE/tunnel_watch"
rm -f "$work/old-app.log"
ec_host_report > /dev/null 2>&1 && ok "前のバージョンのアプリに --integrity を断られても、外して渡せたら 0" || ng "前のバージョンのアプリに断られたまま"
[ "$(wc -l < "$work/old-app.log" | tr -d ' ')" = 2 ] && tail -n1 "$work/old-app.log" | q -- '--tunnel=ok' && ! tail -n1 "$work/old-app.log" | q -- '--integrity\|--sentinel' &&
    ok "渡し直しは --integrity…・--sentinel… だけを外す（--tunnel は残す）" || ng "渡し直しの引数が違う（$(tr '\n' '|' < "$work/old-app.log")）"

# ---------- 8. 入れる unit・コマンド・設定の決まり ----------
grep -q '^ExecStart=/usr/local/bin/ec-sugar integrity check$' deploy/systemd/ec-sugar-integrity.service && grep -q '^OnCalendar=\*:\*:00$' deploy/systemd/ec-sugar-integrity.timer &&
    ok "unit は毎分 ec-sugar integrity check を流す" || ng "unit の中身が違う"
grep -q 'ec_install_integrity_units "\$from"' "$lib" && grep -q 'enable --now ec-sugar-integrity.timer' "$lib" && ok "install-units が中の照らしの unit を入れて有効にする" || ng "中の照らしの unit を入れていない"
grep -q 'integrity) cmd_integrity' deploy/ec-sugar && grep -q 'ec_integrity_record always service code compose tools units tunnel settings' deploy/install.sh &&
    ok "ec-sugar integrity があり、インストーラーは作り直したものを控える" || ng "ec-sugar integrity かインストーラーの控えが無い"
want_rec='cmd_update:always service code compose tools units settings-new
cmd_rollback:always service code compose env:EC_SUGAR_IMAGE
cmd_restore:if-ok settings compose service-config
cmd_admin_ips:if-ok env:ADMIN_ALLOWED_IPS answers:ADMIN_ALLOWED_IPS
cmd_shops:if-ok settings tunnel
trust_add:if-ok trust
trust_remove:if-ok trust
cmd_install_units:always tools units
cmd_integrity:always all'
while IFS=: read -r fn want; do
    awk -v f="$fn" -v w="ec_integrity_record $want" '$0 ~ "^"f"\\(\\) \\{" {inblk = 1} inblk && index($0, w) {found = 1} inblk && /^}/ {inblk = 0} END {exit found ? 0 : 1}' deploy/ec-sugar ||
        ng "$fn がそのコマンドの書いた分だけを控え直していない（ec_integrity_record ${want}）"
done <<< "$want_rec"
ok "控え直すコマンドは、それぞれ作り直した分だけを渡す（update・rollback・restore・admin-ips・shops・trust・install-units・record）"
awk '/^cmd_install_units\(\) \{/ {inblk = 1} inblk && /ec_lock/ {found = 1} inblk && /^}/ {inblk = 0} END {exit found ? 0 : 1}' deploy/ec-sugar &&
    grep -q 'EC_SUGAR_LOCK_HELD=1 EC_SUGAR_INTEGRITY_RECORD=skip "\$EC_BIN" install-units' deploy/ec-sugar &&
    ok "install-units を手で流すと錠を取る（更新の中からは錠を持ったまま）" || ng "install-units が錠を取らない"

[ "$fails" -eq 0 ] || { echo "NG が ${fails} 件"; exit 1; }
echo "すべて ok"
