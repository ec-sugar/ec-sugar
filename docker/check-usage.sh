#!/usr/bin/env bash
# 使用状況の共有（ADR 0113。deploy/lib/common.sh の ec_usage_…）を、本物の curl・systemctl・dpkg の代わりに偽の部品で確認する。
# root は要らず、何も変えない（作るのは一時のディレクトリだけ）。外へは何も送らない。docker/check-deploy.sh が呼ぶ（単体でも実行できる）
#
#   docker/check-usage.sh [common.sh の道筋]   既定は deploy/lib/common.sh
#
# 確認すること:
#   1. ID は UUID v4 の形で、毎回違う・0600・あれば作り直さない・off で消える
#   2. 送る JSON の項目は id・version・event・os・os_version・arch・shops の 7 つだけ。ホスト名・設定ファイルの値・店の名前とドメインは入らない。
#      shops は中央の DB の tenants の行の数だけ（読む SQL は count(*) だけ）。読めなければ null・上限 100000 で切る・形の違う答えは null。
#      形の違う値（引用符・空白・長すぎる）は unknown になり、JSON を壊さない。event は install と weekly だけ
#   3. 共有しない設定（USAGE_SHARE が無い・no）なら curl を呼ばず、何も出さず、ID も state も作らない
#   4. 共有する設定なら curl に https だけ・10 秒・行き先を変える応答を追わない・~/.curlrc を読まないで渡し、本文は標準入力だけ（引数に出さない）
#   5. 送れなくても（curl の失敗・500・応答なし）0 で終わり、1 行だけ出して state に failed を残す
#   6. 送信先を https でない URL に替えると送らない。隔離した複製環境からは送らない
#   7. unit: 共有する設定の時だけ timer を有効にし、ほかは止める。unit が無い道具では timer を止めて消す。中の照らしの自分の unit の一覧にある
#   8. インストーラーと ec-sugar の形（端末も設定も無ければ no・前から入っているサーバーには聞かない・off で ID を消す・status の 1 行）
set -euo pipefail
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

EC_ROOT="$work"
EC_ANSWERS="$work/answers"
EC_STATE="$work/state"
EC_UNIT_DIR="$work/units"
EC_USAGE_OS_RELEASE="$work/os-release"
mkdir -p "$EC_STATE" "$EC_UNIT_DIR" "$work/bin"
: > "$EC_ANSWERS"
printf 'NAME="Ubuntu"\nID=ubuntu\nVERSION_ID="24.04"\nPRETTY_NAME="Ubuntu 24.04.3 LTS"\n' > "$EC_USAGE_OS_RELEASE"
printf '%s\n' 0123456789abcdef0123456789abcdef01234567 > "$EC_STATE/current_commit"
unset EC_SUGAR_USAGE_URL

# 偽の部品: curl は引数（1 行に 1 つ）と標準入力を残し、FAKE_CODE を応答の番号として出して FAKE_RC で終わる
cat > "$work/bin/curl" << 'EOF'
#!/usr/bin/env bash
printf '%s\n' "$@" > "$FAKE_DIR/curl.args"
cat > "$FAKE_DIR/curl.stdin"
printf '%s' "${FAKE_CODE-204}"
exit "${FAKE_RC:-0}"
EOF
cat > "$work/bin/systemctl" << 'EOF'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$FAKE_DIR/systemctl.log"
EOF
cat > "$work/bin/dpkg" << 'EOF'
#!/usr/bin/env bash
[ "${1:-}" = --print-architecture ] && printf '%s\n' "${FAKE_ARCH:-amd64}"
EOF
chmod +x "$work/bin/curl" "$work/bin/systemctl" "$work/bin/dpkg"
export FAKE_DIR="$work"
PATH="$work/bin:$PATH"
# install -o root -g root は root でないと通らないので、持ち主の指定を外して本物を呼ぶ
install() {
    local a=()
    while [ $# -gt 0 ]; do
        case "$1" in
            -o | -g) shift 2 ;;
            *) a+=("$1"); shift ;;
        esac
    done
    command install "${a[@]}"
}
# 偽の ec_dc（docker compose）。中央の DB に送った SQL を残し、FAKE_SHOPS を答える（FAKE_DC_RC が 0 でなければ失敗）
ec_dc() {
    printf '%s\n' "$*" >> "$FAKE_DIR/dc.log"
    [ "${FAKE_DC_RC:-0}" -eq 0 ] || return "$FAKE_DC_RC"
    printf '%s\n' "${FAKE_SHOPS-1}"
}
reset_fakes() { rm -f "$work/curl.args" "$work/curl.stdin" "$work/systemctl.log" "$EC_STATE/usage_last" "$EC_STATE/isolated"; }

# ---------- 1. ID ----------
id1="$(ec_usage_new_id)"
id2="$(ec_usage_new_id)"
ec_usage_valid_id "$id1" && ec_usage_valid_id "$id2" && [ "$id1" != "$id2" ] && [[ "$id1" != *"$(hostname 2> /dev/null || echo none)"* ]] &&
    ok "ID: UUID v4 の形（13 桁目が 4・17 桁目が 8〜b）で、作るたびに違う" || ng "ID: 形が違うか、同じ ID が出た（${id1} ${id2}）"
bad=""
for i in $(seq 1 200); do
    x="$(ec_usage_new_id)"
    ec_usage_valid_id "$x" || bad="$bad $x"
done
[ -z "$bad" ] && ok "ID: 200 回作って全部 UUID v4 の形" || ng "ID: 形の違う ID が出た（${bad}）"
! ec_usage_valid_id 0123456789ab-1234-1234-1234-123456789abc && ! ec_usage_valid_id "${id1^^}" && ! ec_usage_valid_id "$id1 " && ! ec_usage_valid_id 00000000-0000-1000-8000-000000000000 &&
    ok "ID: 形の違う値（区切りの位置・大文字・空白・v1）を ID と読まない" || ng "ID: 形の違う値を ID と読んだ"
ec_usage_id_ensure
first="$(ec_usage_id)"
ec_usage_id_ensure
[ "$(ec_usage_id)" = "$first" ] && [ "$(stat -c %a "$EC_STATE/usage_id" 2> /dev/null || stat -f %Lp "$EC_STATE/usage_id")" = 600 ] &&
    ok "ID: state/usage_id に 0600 で置き、あれば作り直さない" || ng "ID: 作り直したか、権限が 0600 でない"
printf 'not-an-id\n' > "$EC_STATE/usage_id"
ec_usage_id_ensure
ec_usage_valid_id "$(ec_usage_id)" && [ "$(ec_usage_id)" != "$first" ] && ok "ID: 壊れた ID のファイルは新しい ID で置き換える" || ng "ID: 壊れた ID を直さない"
ec_usage_id_drop
[ ! -e "$EC_STATE/usage_id" ] && ok "ID: off で消える" || ng "ID: 消えない"

# ---------- 2. 送る JSON ----------
body="$(ec_usage_payload install "$id1")"
want="{\"id\":\"$id1\",\"version\":\"0123456789ab\",\"event\":\"install\",\"os\":\"ubuntu\",\"os_version\":\"24.04\",\"arch\":\"amd64\",\"shops\":1}"
[ "$body" = "$want" ] && ok "JSON: 7 つの項目だけ（source チャネルはコミットの頭の 12 桁・店の数は引用符なしの数）" || ng "JSON: 中身が違う（${body}）"
if command -v python3 > /dev/null 2>&1; then
    keys="$(printf '%s' "$body" | python3 -c 'import json,sys; print(",".join(sorted(json.load(sys.stdin))))')"
    [ "$keys" = arch,event,id,os,os_version,shops,version ] && ok "JSON: JSON として読めて、項目は決めた 7 つ" || ng "JSON: 項目が違う（${keys}）"
fi
printf 'CHANNEL=release\n' > "$EC_ANSWERS"
printf 'v1.0.0\n' > "$EC_STATE/current_version"
[[ "$(ec_usage_payload weekly "$id1")" == *'"version":"1.0.0","event":"weekly"'* ]] && ok "JSON: release チャネルはタグから頭の v を外した 1.0.0" || ng "JSON: release チャネルのバージョンが違う"
printf 'v1.0.0" ,"x":"y\n' > "$EC_STATE/current_version"
[[ "$(ec_usage_payload weekly "$id1")" == *'"version":"0123456789ab"'* ]] && ok "JSON: タグの形が違えばコミットの短い ID に戻る" || ng "JSON: 形の違うタグをそのまま入れた"
: > "$EC_ANSWERS"
printf 'ID="ubu\\"ntu"\nVERSION_ID=24.04 LTS\n' > "$EC_USAGE_OS_RELEASE"
body="$(FAKE_ARCH='x86 64' ec_usage_payload weekly "$id1")"
[[ "$body" == *'"os":"unknown","os_version":"unknown","arch":"unknown","shops":1}' ]] && ok "JSON: 形の違う OS・バージョン・CPU の種類は unknown（引用符や空白で JSON を壊さない）" || ng "JSON: 形の違う値を入れた（${body}）"
rm -f "$EC_USAGE_OS_RELEASE" "$EC_STATE/current_commit"
[[ "$(ec_usage_payload weekly "$id1")" == *'"version":"unknown","event":"weekly","os":"unknown","os_version":"unknown"'* ]] && ok "JSON: 読めない時も unknown で、止まらない" || ng "JSON: 読めない時の値が違う"
printf 'ID=ubuntu\nVERSION_ID="24.04"\n' > "$EC_USAGE_OS_RELEASE"
printf '%s\n' 0123456789abcdef0123456789abcdef01234567 > "$EC_STATE/current_commit"
! ec_usage_payload uninstall "$id1" > /dev/null && ! ec_usage_payload install 'a"b' > /dev/null && ok "JSON: event は install と weekly だけ・ID に引用符は入れない" || ng "JSON: 決めた event のほかを作った"
# 設定ファイルの値（店のホスト・メール）を読まない
printf 'ADMIN_HOST=admin.shop-secret.example.jp\nOPERATOR_EMAIL=owner@shop-secret.example.jp\nUSAGE_SHARE=yes\n' > "$EC_ANSWERS"
[[ "$(ec_usage_payload weekly "$id1")" != *shop-secret* ]] && ok "JSON: 設定ファイルの店のホスト・メールは入らない" || ng "JSON: 設定ファイルの値が入った"

# 店の数
rm -f "$work/dc.log"
shops_of() { ec_usage_payload weekly "$id1" | sed -n 's/.*"shops":\([^}]*\)}$/\1/p'; }
bad=""
for c in '0:0' '7:7' '0012:12' '100000:100000' '100001:100000' '99999999999:100000' 'demo:null' '1 2:null' '-1:null' ':null' '3"}:null'; do
    got="$(FAKE_SHOPS="${c%%:*}" shops_of)"
    [ "$got" = "${c#*:}" ] || bad="$bad [${c%%:*} → ${got}]"
done
got="$(FAKE_DC_RC=1 shops_of)"
[ "$got" = null ] || bad="$bad [DB に届かない → ${got}]"
[ -z "$bad" ] && ok "店の数: 0 以上の整数（頭の 0 は外す）・上限 100000 で切る・形が違うか DB に届かなければ null（止めない）" || ng "店の数の扱いが違う（${bad}）"
sql="$(sort -u "$work/dc.log")"
[ "$sql" = "exec -T db psql -U ec -d ec_central -Atc SELECT count(*) FROM tenants" ] &&
    ok "店の数: 中央の DB に送る SQL は SELECT count(*) FROM tenants だけ（店の名前・ドメイン・ID は読まない）" || ng "店の数: ほかの SQL を送った（${sql}）"

# ---------- 3. 共有しない設定 ----------
for a in '' 'USAGE_SHARE=no' 'USAGE_SHARE=YES'; do
    reset_fakes
    printf '%s\n' "$a" > "$EC_ANSWERS"
    rm -f "$EC_STATE/usage_id"
    out="$(ec_usage_send weekly 2>&1)"
    { [ -z "$out" ] && [ ! -e "$work/curl.args" ] && [ ! -e "$EC_STATE/usage_id" ] && [ ! -e "$EC_STATE/usage_last" ]; } || ng "共有しない（${a:-空}）: 何かをした（${out}）"
done
ok "共有しない設定（USAGE_SHARE が無い・no・大文字の YES）では curl を呼ばず、何も出さず、ID も state も作らない"

# ---------- 4. 共有する設定 ----------
reset_fakes
printf 'USAGE_SHARE=yes\nADMIN_HOST=admin.shop-secret.example.jp\n' > "$EC_ANSWERS"
rc=0
out="$(ec_usage_send install 2>&1)" || rc=$?
id="$(ec_usage_id || true)"
args="$(cat "$work/curl.args" 2> /dev/null || true)"
sent="$(cat "$work/curl.stdin" 2> /dev/null || true)"
[ "$rc" -eq 0 ] && ec_usage_valid_id "$id" && [ "$sent" = "$(ec_usage_payload install "$id")" ] && [ "$(printf '%s\n' "$args" | tail -n1)" = "$EC_USAGE_URL_DEFAULT" ] &&
    [ "$out" = '使用状況の共有: 送りました（install）' ] && [[ "$(cat "$EC_STATE/usage_last")" == *' install ok' ]] &&
    ok "共有する: ID を作り、決めた JSON を既定の送信先 ${EC_USAGE_URL_DEFAULT} へ送って 1 行出し、state に ok を残す" || ng "共有する: 送り方が違う（rc=${rc} ${out} / ${sent}）"
[ "$(printf '%s\n' "$args" | head -n1)" = -q ] && printf '%s\n' "$args" | q -x -- '--proto' && printf '%s\n' "$args" | q -x -- '=https' &&
    printf '%s\n' "$args" | q -x -- '--max-time' && printf '%s\n' "$args" | q -x -- '10' && printf '%s\n' "$args" | q -x -- '@-' &&
    ! printf '%s\n' "$args" | q -E -- '^(-L|--location)$' && ! printf '%s\n' "$args" | q -F -- "$id" && ! printf '%s\n' "$args" | q -F shop-secret &&
    ok "共有する: curl は -q を先頭に・https だけ・10 秒・転送を追わない。本文（ID）は引数に出さず標準入力から" || ng "curl の引数が違う（${args}）"

# ---------- 5. 送れない時 ----------
for c in '7 000' '0 500' '0 302' '28 '; do
    reset_fakes
    rc=0
    out="$(FAKE_RC="${c%% *}" FAKE_CODE="${c#* }" ec_usage_send weekly 2>&1)" || rc=$?
    { [ "$rc" -eq 0 ] && [ "$(printf '%s\n' "$out" | wc -l | tr -d ' ')" = 1 ] && [[ "$out" == *'送れませんでした（weekly、応答 '* ]] && [[ "$(cat "$EC_STATE/usage_last")" == *' weekly failed '* ]]; } ||
        ng "送れない時（curl の終わり ${c%% *}・応答 ${c#* }）: 0 で終わらないか、出し方が違う（rc=${rc} ${out}）"
done
ok "送れない時（届かない・500・転送・時間切れ）も 0 で終わり、1 行だけ出して state に failed を残す"
# インストーラーと ec-sugar と同じ set -euo pipefail の別のプロセスで（|| の左では set -e が効かないので、ここでは流さない）
reset_fakes
rc=0
FAKE_RC=7 FAKE_CODE=000 bash -c 'set -euo pipefail; . "$1"; EC_ANSWERS="$2"; EC_STATE="$3"; ec_usage_send weekly > /dev/null; echo after' _ "$lib" "$EC_ANSWERS" "$EC_STATE" > "$work/after" 2>&1 || rc=$?
[ "$rc" -eq 0 ] && [ "$(cat "$work/after")" = after ] && ok "送れない時: set -euo pipefail の下でも、呼んだ側（インストーラー・timer）は止まらない" || ng "set -e の下で止まった（rc=${rc} $(cat "$work/after")）"
rm -f "$work/after"

# ---------- 6. 送信先と隔離した複製環境 ----------
for u in http://ec-sugar.jp/usage/v1 'https://ec-sugar.jp/usage/v1?x=1' 'https://user:pass@usage.example.test/v1' 'file:///etc/passwd' 'https://ec-sugar.jp/ -o /tmp/x'; do
    reset_fakes
    out="$(EC_SUGAR_USAGE_URL="$u" ec_usage_send weekly 2>&1)"
    { [ ! -e "$work/curl.args" ] && [[ "$out" == *'https の URL でない'* ]]; } || ng "送信先 ${u} に送った"
done
ok "送信先を https://<名前>[:ポート][/パス] でない URL（http・クエリ・ユーザー情報・file・空白）に替えると送らない"
reset_fakes
out="$(EC_SUGAR_USAGE_URL=https://usage.example.test:8443/v1 ec_usage_send weekly 2>&1)"
[ "$(tail -n1 "$work/curl.args")" = https://usage.example.test:8443/v1 ] && ok "送信先: 確認のための https の送信先には送る" || ng "送信先: https の送信先に送らない（${out}）"
reset_fakes
: > "$EC_STATE/isolated"
out="$(ec_usage_send weekly 2>&1)"
[ -z "$out" ] && [ ! -e "$work/curl.args" ] && ok "隔離した複製環境（restore --isolated）からは送らない" || ng "隔離した複製環境から送った"
rm -f "$EC_STATE/isolated"

# ---------- 7. unit ----------
from="$work/deploy"
mkdir -p "$from/systemd"
cp deploy/systemd/ec-sugar-usage.service deploy/systemd/ec-sugar-usage.timer "$from/systemd/"
reset_fakes
printf 'USAGE_SHARE=yes\n' > "$EC_ANSWERS"
ec_usage_id_drop
ec_install_usage_units "$from"
[ -f "$EC_UNIT_DIR/ec-sugar-usage.service" ] && [ -f "$EC_UNIT_DIR/ec-sugar-usage.timer" ] && q -x 'enable --now ec-sugar-usage.timer' < "$work/systemctl.log" && ec_usage_valid_id "$(ec_usage_id)" &&
    ok "unit: 共有する設定なら unit を入れて timer を有効にし、ID が無ければ作る" || ng "unit: 共有する設定で timer を有効にしない"
for a in '' 'USAGE_SHARE=no'; do
    reset_fakes
    printf '%s\n' "$a" > "$EC_ANSWERS"
    ec_usage_id_drop
    ec_install_usage_units "$from"
    { [ -f "$EC_UNIT_DIR/ec-sugar-usage.timer" ] && ! q enable < "$work/systemctl.log" && q -x 'disable --now ec-sugar-usage.timer' < "$work/systemctl.log" && [ ! -e "$EC_STATE/usage_id" ]; } ||
        ng "unit: 共有しない設定（${a:-設定が無い}）で timer を有効にした・止めない・ID を作った"
done
ok "unit: 共有しない設定（前からあるサーバー・no）では unit を入れても timer は止めたまま、ID も作らない（更新しても勝手に on にしない）"
reset_fakes
mkdir -p "$work/old/systemd"
ec_install_usage_units "$work/old"
[ ! -e "$EC_UNIT_DIR/ec-sugar-usage.timer" ] && [ ! -e "$EC_UNIT_DIR/ec-sugar-usage.service" ] && q -x 'disable --now ec-sugar-usage.timer' < "$work/systemctl.log" &&
    ok "unit: unit の無い前のバージョンの道具では、timer を止めて unit を消す" || ng "unit: 前のバージョンで unit が残った"
[[ " $EC_INTEGRITY_OWN_UNITS " == *" ec-sugar-usage.service "* ]] && [[ " $EC_INTEGRITY_OWN_UNITS " == *" ec-sugar-usage.timer "* ]] &&
    sed -n '/^ec_install_tools() {/,/^}/p' "$lib" | q 'ec_install_usage_units "\$from"' &&
    ok "unit: install-units が入れ、内部の整合性チェックは自分の unit として記録し直す" || ng "unit: 内部の整合性チェックの自分の unit の一覧か、ec_install_tools に無い"
grep -qx 'ExecStart=/usr/local/bin/ec-sugar usage send' deploy/systemd/ec-sugar-usage.service && grep -qx 'OnCalendar=weekly' deploy/systemd/ec-sugar-usage.timer &&
    grep -q '^RandomizedDelaySec=' deploy/systemd/ec-sugar-usage.timer && grep -qx 'Persistent=true' deploy/systemd/ec-sugar-usage.timer && grep -qx 'WantedBy=timers.target' deploy/systemd/ec-sugar-usage.timer &&
    ok "unit: 週 1 回・サーバーごとにばらす・止まっていた回は起動の後に送る" || ng "unit: timer の形が違う"

# ---------- 8. インストーラーと ec-sugar の形 ----------
fn="$(sed -n '/^ask_usage_share() {/,/^}/p' deploy/install.sh)"
[ -n "$fn" ] && printf '%s\n' "$fn" | tr '\n' ' ' | q 'installed && return 0 *if ! has_tty; then *ec_kv_set "\$EC_ANSWERS" USAGE_SHARE no' &&
    printf '%s\n' "$fn" | q '"" | \[Yy\] | \[Yy\]\[Ee\]\[Ss\]) val=yes' && printf '%s\n' "$fn" | q 'sudo ec-sugar usage off' &&
    ok "インストーラー: 前から入っているサーバーには聞かない・端末も設定も無ければ no・既定は Y・質問に止め方を書く" || ng "インストーラー: 聞き方が違う"
grep -q '^EC_ANSWER_KEYS=.* USAGE_SHARE[ "]' deploy/install.sh && sed -n '/^main() {/,/^}/p' deploy/install.sh | tr '\n' ' ' | q 'finish *send_install_usage *news_note' &&
    grep -q '^EC_NEWS_URL=https://official.ec-sugar.jp/p/newsletter$' deploy/install.sh &&
    ok "インストーラー: 設定ファイルの USAGE_SHARE を読み、通った後に install を 1 回送り、最後にメルマガの登録ページを案内する" || ng "インストーラー: 送る所か案内が違う"
off="$(sed -n '/^usage_off() {/,/^}/p' deploy/ec-sugar)"
printf '%s\n' "$off" | q 'USAGE_SHARE no' && printf '%s\n' "$off" | q 'ec_usage_id_drop' && printf '%s\n' "$off" | q 'disable --now ec-sugar-usage.timer' &&
    sed -n '/^usage_on() {/,/^}/p' deploy/ec-sugar | q 'enable --now ec-sugar-usage.timer' && grep -q '^        usage) cmd_usage' deploy/ec-sugar &&
    sed -n '/^cmd_status() {/,/^}/p' deploy/ec-sugar | q '^    status_usage$' && bash deploy/ec-sugar help | q 'sudo ec-sugar usage \[on | off\]' &&
    ok "ec-sugar: usage off は no を書いて timer を止めて ID を消す・usage on は timer を有効にする・status に 1 行・使い方に載る" || ng "ec-sugar: usage の形が違う"

[ "$fails" -eq 0 ] || { echo "NG が ${fails} 件"; exit 1; }
echo "すべて ok"
