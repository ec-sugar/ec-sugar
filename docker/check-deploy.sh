#!/usr/bin/env bash
# インストーラーと ec-sugar の部品（deploy/lib/common.sh）のうち、ホストの防火壁と、ソケットに入れるグループの確かめを、
# 本物の iptables・ps・getent の代わりに偽の部品で確かめる。root は要らず、何も変えない（作るのは一時の置き場だけ）。
# 7・8 の env の名前の移しだけは本物の docker compose で compose を解く（config だけ。コンテナは作らない）
#
#   docker/check-deploy.sh [common.sh の道筋]   既定は deploy/lib/common.sh
#
# 実際に送って確かめるのは使い捨ての Ubuntu（docs/install.md の「動作を確認したサーバー」）。ここは、その形が崩れないことの見張り。
#
# 確かめること:
#   1. 防火壁の決まりは、この compose の網の bridge ごとに、IPv4 と IPv6 の両方で「root でない利用者が始めた接続（--ctdir ORIGINAL）のうち、
#      公開したポートへの DNAT でないもの（! --ctstate DNAT）」を断る形。追跡の記録の無い包みもこの形で断る。
#      前の形（向きを見ないので、コンテナから始めた接続の返りも断つ・DNAT を見ないので公開したポートへのホストの中からの接続も断つ）と、
#      無くなった網の決まりは片付ける。2 回当てても増えない。IPv6 の道具が使えないサーバーでは IPv4 だけ
#   2. 決まりが消されたら（Plesk のファイアウォールの適用・iptables の作り直し）、足りない bridge を「道具 bridge」で出す（status の ✗）
#   3. LAN_CIDR が読めなくても、防火壁の当て方は抜けずに 1 を返す（呼ぶ側が || で受けられる。前は ec_die で抜けた）。
#      読めればステージングの受け口の決まり（DOCKER-USER）を 80・443 番に入れ、後ろに置く形には入れない
#   4. ソケットに入れるグループで動くものを、今のプロセス（グループの番号で比べる。長いグループの名前でも）・php-fpm のプールの設定（ondemand の
#      ように今はプロセスが無いもの）・Apache の envvars・そのグループの利用者の crontab から見分ける。前の Web サーバーの働き手は数えない
#   5. Docker に網を聞けないとき（docker network ls が失敗）は、今ある決まりを片付けずに 1 を返し、status は ✗ を出す
#      （前は網が無いと取り違えて、今ある網の決まりまで消した）
#   7. env の名前の移し（前の名前 SUGAR_EC_… の行ごとに、新しい名前 EC_SUGAR_… の行を同じ値で持たせる）: 前の名前の行は残り、
#      新しい行はそのすぐ後ろに引用符も空の値もそのまま。ほかの行・並び・権限（0600）は変えない。2 回流しても同じ。新しい名前だけのファイルには
#      何もしない。前のバージョンの道具が後から前の名前に書いた値に新しい名前をそろえる。書けなければ 1 を返し、ファイルは元のまま。
#      読むときは前の名前の値が先、書くときは前の名前の行があれば両方
#   8. 更新の道（サーバーに入っている前のバージョンの道具が、新しいバージョンの compose を動かす）を本物の docker compose で解く:
#      前の名前だけの env・移した後の env（新しい名前の値は古いまま）で、前のバージョンの道具が環境変数とファイルの前の名前で渡したイメージが
#      app・queue・scheduler・web に効く。新しい名前だけの env（新しく入れたサーバー）でも解ける。前のバージョンの compose（ロールバックの先）が
#      移した後の env で解ける（前のコミットが手元に無い浅い取得〔CI〕では見送る）。DB の管理者のパスワードは前の名前でもアプリに渡らない。
#      必須の項目が無ければ ec_dc が名前と説明を出して止める（compose の入れ子の内側に :? を置けないため）。
#      compose の版で解き方が違う（2.40 系は入れ子の内側の :? を外側に値があっても確かめる）ので、EC_CHECK_COMPOSE=<compose の単体のバイナリ> で差し替えて流せる。
#      公開版の形（CHANNEL=release）では、compose が差し込むファイルが作業場所でなく root だけの置き場から来る（H）
#  13. トンネルの見張り（見直しの中 5）: 偽の curl で、接続役のつなぎ元が合う・よそ・0 件・API の失敗・鍵なしの結果の語。
#      読むだけの鍵は curl の設定として標準入力でだけ渡り、引数・画面・記録に出ない。鍵の答えの確かめと置き場への写し（root だけの 0400）
#  15. 1 店舗から複数の店へ広げる（ADR 0090）: 本番の今の答えから、広げられるかの確かめ・答えと env と cloudflared の行き先の書き換え・
#      最初の店の URL が変わらない・2 回流しても同じ・https で開けなければ 1 店舗へ戻す・1 店舗へ戻す道・DNS に足す行
#  16. 中の照らし（ADR 0105）: docker/check-integrity.sh を流す（控え・照らし・署名・busy・世代・外の見張り・知らせ。中身はそちらの頭）
#  17. APP_KEY の控え: ec-sugar app-key export が設定のファイルを 0600 で同じ中身に写す・もうあるファイルは上書きしない・
#      シンボリックリンク（先の無いものも）を断ってリンクの先に書かない・ほかの人が書ける置き場とバックアップの置き場を断る・
#      説明と写した時の画面に鍵の値（偽の env の値）が 1 文字も出ない・貼り付けの履歴に残る道（cat を pbcopy へ）を勧めない
#  18. サーバーの外のバックアップ（ADR 0110）の形: 置き場を設定していなければ送る回も控えの段も何も作らない・unit が入れる一覧と
#      中の照らしの自分の unit の一覧の両方にある・更新の前と戻す前のバックアップは送らない・答えと鍵の形の確かめ。
#      実際に送って取る確かめは docker/check-offsite.sh（手元の S3 互換の置き場へ）。
#      運営の様子への報告: 置き場を設定したサーバーだけ、state の決まった語と時刻を --offsite… で渡す（鍵のファイルが無ければ
#      state が残っていても 1 つも出さない。形の違う値・バックアップの名前は渡さない）。--offsite… を知らない前のバージョンのアプリには、
#      新しい値から順に外して渡し直す。送る回は、渡す値が変わった時だけ報告し、渡せなければ次の回に渡し直す
#  19. 復元のキット: 持ち主の手元の道具 deploy/recovery-kit の引数の確かめ・作り物の値でキットを作って確かめる（0600・値を画面に出さない・
#      上書きしない・短い合い言葉と違う開ける鍵の合い言葉を断る・check は開ける鍵の合い言葉が違うキットに気づく）。
#      サーバーの側（ec_kit_open）がキットを開いて APP_KEY を読む（restore --kit の読み方）・
#      合い言葉は端末かファイルから・ほかの人が読めるキットと合い言葉のファイルを断る・形の違うキットを中身を出さずに断る・開いた中身を残さない。
#      --kit と、ばらの鍵・入口の引数を同時に渡すと断る。gpg・python3・jq が要る（無ければ NG。黙って飛ばさない）。
#      実際に置き場から取る確かめは docker/check-offsite.sh の 14
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
# 偽の道具を足す前の PATH（16 の別のスクリプトは本物の openssl で流す）
start_path="$PATH"
fails=0
ng() { echo "NG: $*"; fails=$((fails + 1)); }
ok() { echo "ok: $*"; }

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# shellcheck source=../deploy/lib/common.sh
. "$lib"
# 答え・env は一時の置き場に（本物の /srv/ec-sugar を読まない）
EC_ANSWERS="$work/answers"
EC_ENV_FILE="$work/env"
: > "$EC_ANSWERS"
: > "$EC_ENV_FILE"

# ---------- 偽の部品 ----------
# 偽の iptables・ip6tables: 鎖ごとに決まりを 1 行ずつファイルに持つ。-C・-I <鎖> 1・-A・-D・-F・-S・-N だけ
fake_ipt() {
    local tool="$1" op="$2" chain="$3" f
    shift 3
    f="$work/$tool.$chain"
    touch "$f"
    case "$op" in
        -C) grep -qxF -- "$*" "$f" ;;
        -I) shift; { printf '%s\n' "$*"; cat "$f"; } > "$f.new"; mv "$f.new" "$f" ;;
        -A) printf '%s\n' "$*" >> "$f" ;;
        -F) : > "$f" ;;
        -D) grep -qxF -- "$*" "$f" || return 1; grep -vxF -- "$*" "$f" > "$f.new" || true; mv "$f.new" "$f" ;;
        -S) sed "s/^/-A $chain /" "$f" ;;
        -N) return 1 ;;
        *) return 2 ;;
    esac
}
FAKE_IP6=1
iptables() { fake_ipt iptables "$@"; }
ip6tables() { [ "$FAKE_IP6" = 1 ] || return 3; fake_ipt ip6tables "$@"; }
# 偽の docker: 網の一覧（network ls）と ID（network inspect）だけを答える（FAKE_NETS は「網の名前:ID」を空白で区切る）。
# FAKE_DOCKER_FAIL=1 なら Docker に届かない（すべて失敗）。ほかのプロジェクトの網（other_edge）も一覧に混ぜる
FAKE_NETS=""
FAKE_DOCKER_FAIL=0
docker() {
    local item name
    [ "$FAKE_DOCKER_FAIL" = 0 ] || return 1
    if [ "$1 $2" = "network ls" ]; then
        printf 'bridge 0000000000001111\nother_edge 9999999999990000\n'
        for item in $FAKE_NETS; do printf '%s_%s %s\n' "$EC_PROJECT" "${item%%:*}" "${item#*:}"; done
        return 0
    fi
    [ "$1 $2" = "network inspect" ] || return 1
    name="${*: -1}"
    for item in $FAKE_NETS; do
        [ "${EC_PROJECT}_${item%%:*}" != "$name" ] || { printf '%s\n' "${item#*:}"; return 0; }
    done
    return 1
}
rules() { cat "$work/$1.$2" 2> /dev/null || true; }
want_rule() { printf -- '-o %s -m owner --uid-owner 1-4294967294 -m conntrack --ctdir ORIGINAL ! --ctstate DNAT -m comment --comment ec-sugar-bridge-guard -j REJECT' "$1"; }
# 前の形 2 つ（向きを見ない形と、DNAT を見ない形〔b10df77〕）
old_rule() { printf -- '-o %s -m owner --uid-owner 1-4294967294 -m comment --comment ec-sugar-bridge-guard -j REJECT' "$1"; }
old2_rule() { printf -- '-o %s -m owner --uid-owner 1-4294967294 -m conntrack --ctdir ORIGINAL -m comment --comment ec-sugar-bridge-guard -j REJECT' "$1"; }

# ---------- 1. 当てる ----------
ida=aaaaaaaaaaaa1111111111111111111111111111111111111111111111111111
idb=bbbbbbbbbbbb2222222222222222222222222222222222222222222222222222
FAKE_NETS="internal:$ida edge:$idb"
# 前の形 2 つと、無くなった網（br-cccccccccccc）の決まりを先に入れておく
printf '%s\n' "$(old_rule br-aaaaaaaaaaaa)" "$(old2_rule br-bbbbbbbbbbbb)" \
    "$(want_rule br-cccccccccccc)" '-o br-ffffffffffff -j ACCEPT' > "$work/iptables.OUTPUT"
rc=0
ec_bridge_guard_apply > "$work/out" 2>&1 || rc=$?
ec_bridge_guard_apply > "$work/out2" 2>&1 || rc=$?
for tool in iptables ip6tables; do
    expect="$(printf '%s\n%s' "$(want_rule br-bbbbbbbbbbbb)" "$(want_rule br-aaaaaaaaaaaa)" | sort)"
    got="$(rules "$tool" OUTPUT | grep ec-sugar-bridge-guard | sort || true)"
    if [ "$rc" -eq 0 ] && [ "$got" = "$expect" ]; then
        ok "$tool: 網ごとに、root でない利用者が始めた DNAT でない接続（--ctdir ORIGINAL ! --ctstate DNAT）を断る決まりが 1 つずつ（前の形 2 つと無くなった網の決まりは片付き、2 回当てても増えない）"
    else
        ng "$tool の決まりが違う（返り ${rc}）: $(printf '%s' "$got" | tr '\n' '|')"
    fi
done
rules iptables OUTPUT | q -xF -- '-o br-ffffffffffff -j ACCEPT' && ok "ほかの道具の決まりには触らない" || ng "EC-SUGAR のものでない決まりを消した"

# IPv6 の道具が使えないサーバー: IPv4 だけ当てて、失敗にしない
rm -f "$work"/ip*tables.*
FAKE_IP6=0
rc=0
ec_bridge_guard_apply > "$work/out" 2>&1 || rc=$?
[ "$rc" -eq 0 ] && [ "$(rules iptables OUTPUT | grep -c ec-sugar-bridge-guard)" = 2 ] && ok "IPv6 の道具が使えなければ IPv4 だけ当てる" || ng "IPv6 の道具が使えないときに当てられない（返り ${rc}）"
FAKE_IP6=1

# ---------- 2. 消されたら気づく ----------
rm -f "$work"/ip*tables.*
ec_bridge_guard_apply > /dev/null 2>&1 || true
[ -z "$(ec_bridge_guard_missing 2>&1 || echo x)" ] && ok "決まりが全部あれば、足りないものは無し" || ng "決まりが全部あるのに足りないと出す: $(ec_bridge_guard_missing | tr '\n' '|')"
grep -vF br-bbbbbbbbbbbb "$work/iptables.OUTPUT" > "$work/x" || true
mv "$work/x" "$work/iptables.OUTPUT"
: > "$work/ip6tables.OUTPUT"
missing="$(ec_bridge_guard_missing | sort | tr '\n' '|' || true)"
if [ "$missing" = "ip6tables br-aaaaaaaaaaaa|ip6tables br-bbbbbbbbbbbb|iptables br-bbbbbbbbbbbb|" ]; then
    ok "消された決まりを、道具と bridge ごとに見つける"
else
    ng "消された決まりを見つけられない（${missing}）"
fi
# 前の形の決まりだけが残っている（向きを見ない）のも、足りないと数える
printf '%s\n' "$(old_rule br-aaaaaaaaaaaa)" "$(old2_rule br-bbbbbbbbbbbb)" > "$work/iptables.OUTPUT"
missing="$(ec_bridge_guard_missing 2> /dev/null || true)"
grep -qx 'iptables br-aaaaaaaaaaaa' <<< "$missing" && grep -qx 'iptables br-bbbbbbbbbbbb' <<< "$missing" &&
    ok "前の形 2 つ（返りも断つ・公開したポートへのつなぎも断つ）の決まりは、足りないと数える" || ng "前の形の決まりを正しい決まりと取り違える（$(printf '%s' "$missing" | tr '\n' '|')）"

# ---------- 5. Docker に網を聞けないときは片付けない ----------
rm -f "$work"/ip*tables.*
ec_bridge_guard_apply > /dev/null 2>&1 || true
before5="$(rules iptables OUTPUT; rules ip6tables OUTPUT)"
FAKE_DOCKER_FAIL=1
rc=0
ec_bridge_guard_apply > "$work/out" 2>&1 || rc=$?
after5="$(rules iptables OUTPUT; rules ip6tables OUTPUT)"
if [ "$rc" -ne 0 ] && [ -n "$before5" ] && [ "$before5" = "$after5" ]; then
    ok "Docker に網を聞けないときは、今ある決まりを片付けずに 1 を返す"
else
    ng "Docker に網を聞けないときに決まりを消した・失敗を返さない（返り ${rc}、前 $(printf '%s\n' "$before5" | grep -c . ) 本、後 $(printf '%s\n' "$after5" | grep -c . ) 本）"
fi
missing="$(ec_bridge_guard_missing 2> /dev/null || true)"
[ -n "$missing" ] && ok "Docker に網を聞けないときは、status で足りないと出す（${missing}）" || ng "Docker に網を聞けないのに、足りないものは無しと出す"
FAKE_DOCKER_FAIL=0

# ---------- 2b. コンテナからホストへ届かない（INPUT。2026-11-13） ----------
# 網ごとに INPUT の先頭へ鎖 EC-SUGAR-HOST への飛び先。鎖は「返りと DNAT を通す → 決めたポートを INPUT の続きへ → ほかは断る」の順
host_jump() { printf -- '-i %s -m comment --comment ec-sugar-host-guard -j EC-SUGAR-HOST' "$1"; }
host_chain() {
    printf -- '-m conntrack --ctstate RELATED,ESTABLISHED,DNAT -m comment --comment ec-sugar-host-guard -j RETURN\n'
    [ -z "${1:-}" ] || printf -- '-p tcp -m multiport --dports %s -m comment --comment ec-sugar-host-guard -j RETURN\n' "$1"
    printf -- '-m comment --comment ec-sugar-host-guard -j REJECT\n'
}
rm -f "$work"/ip*tables.*
FAKE_NETS="internal:$ida edge:$idb"
# 無くなった網（br-cccccccccccc）の飛び先と、ほかの道具の決まりを先に入れておく
printf '%s\n' "$(host_jump br-cccccccccccc)" '-i lo -j ACCEPT' > "$work/iptables.INPUT"
ec_bridge_guard_apply > /dev/null 2>&1 || true
ec_bridge_guard_apply > /dev/null 2>&1 || true
for tool in iptables ip6tables; do
    got_jumps="$(rules "$tool" INPUT | grep ec-sugar-host-guard | sort || true)"
    want_jumps="$(printf '%s\n%s' "$(host_jump br-aaaaaaaaaaaa)" "$(host_jump br-bbbbbbbbbbbb)" | sort)"
    if [ "$got_jumps" = "$want_jumps" ] && [ "$(rules "$tool" EC-SUGAR-HOST)" = "$(host_chain)" ]; then
        ok "$tool: 網ごとに INPUT から鎖 EC-SUGAR-HOST へ（無くなった網の飛び先は片付き、2 回当てても増えない）。鎖は返りと DNAT だけ通して断る"
    else
        ng "$tool のホストへの決まりが違う: $(printf '%s' "$got_jumps" | tr '\n' '|') / $(rules "$tool" EC-SUGAR-HOST | tr '\n' '|')"
    fi
done
rules iptables INPUT | q -xF -- '-i lo -j ACCEPT' && ok "ホストへの決まりは、ほかの道具の INPUT の決まりに触らない" || ng "ほかの道具の INPUT の決まりを消した"
[ -z "$(ec_bridge_guard_missing)" ] && ok "ホストへの決まりが全部あれば、足りないものは無し" || ng "ホストへの決まりが全部あるのに足りないと出す: $(ec_bridge_guard_missing | tr '\n' '|')"
# 決めたポートだけ INPUT の続き（ufw）へ。鎖は作り直され、断るのは最後のまま
printf 'EC_SUGAR_HOST_ALLOW_PORTS=25,587\n' >> "$EC_ENV_FILE"
ec_bridge_guard_apply > /dev/null 2>&1 || true
[ "$(rules iptables EC-SUGAR-HOST)" = "$(host_chain 25,587)" ] && ok "EC_SUGAR_HOST_ALLOW_PORTS の TCP のポートだけを、断る前に INPUT の続きへ任せる" ||
    ng "決めたポートの決まりが違う: $(rules iptables EC-SUGAR-HOST | tr '\n' '|')"
# 形の違うポートは注意を出して、どのポートも通さない（断る側に倒す）
{ grep -v '^EC_SUGAR_HOST_ALLOW_PORTS=' "$EC_ENV_FILE" || true; } > "$work/x"
mv "$work/x" "$EC_ENV_FILE"
printf 'EC_SUGAR_HOST_ALLOW_PORTS=25;id\n' >> "$EC_ENV_FILE"
ec_bridge_guard_apply > "$work/out" 2>&1 || true
[ "$(rules iptables EC-SUGAR-HOST)" = "$(host_chain)" ] && grep -q 'EC_SUGAR_HOST_ALLOW_PORTS の形が違う' "$work/out" &&
    ok "形の違う EC_SUGAR_HOST_ALLOW_PORTS は注意を出し、どのポートも通さない" || ng "形の違うポートの扱いが違う: $(rules iptables EC-SUGAR-HOST | tr '\n' '|')"
{ grep -v '^EC_SUGAR_HOST_ALLOW_PORTS=' "$EC_ENV_FILE" || true; } > "$work/x"
mv "$work/x" "$EC_ENV_FILE"
# 順を崩された鎖（断るのが先）・断る決まりの無い鎖は作り直す。消された飛び先と鎖は status が見つける
{ printf -- '-m comment --comment ec-sugar-host-guard -j REJECT\n'; printf -- '-m conntrack --ctstate RELATED,ESTABLISHED,DNAT -m comment --comment ec-sugar-host-guard -j RETURN\n'; } > "$work/iptables.EC-SUGAR-HOST"
ec_bridge_guard_apply > /dev/null 2>&1 || true
[ "$(rules iptables EC-SUGAR-HOST)" = "$(host_chain)" ] && ok "順の崩れた鎖を作り直す（断るのは最後）" || ng "順の崩れた鎖を作り直さない: $(rules iptables EC-SUGAR-HOST | tr '\n' '|')"
grep -vF br-aaaaaaaaaaaa "$work/iptables.INPUT" > "$work/x" && mv "$work/x" "$work/iptables.INPUT"
grep -v REJECT "$work/ip6tables.EC-SUGAR-HOST" > "$work/x" && mv "$work/x" "$work/ip6tables.EC-SUGAR-HOST"
missing="$(ec_bridge_guard_missing | sort | tr '\n' '|')"
[ "$missing" = "ip6tables チェーン EC-SUGAR-HOST|iptables INPUT br-aaaaaaaaaaaa|" ] && ok "消されたホストへの飛び先と崩された鎖を見つける" || ng "消されたホストへの決まりを見つけられない（${missing}）"
rm -f "$work"/ip*tables.*

# ---------- 3. 当て方が抜けない ----------
rm -f "$work"/ip*tables.*
printf 'MODE=staging\nLAN_CIDR=not-a-cidr\n' > "$EC_ANSWERS"
out="$( (ec_firewall_apply || echo "returned $?"; echo after) 2>&1 || true)"
if [[ "$out" == *"returned 1"* ]] && [[ "$out" == *after* ]]; then
    ok "LAN_CIDR が読めなくても抜けずに 1 を返す（呼ぶ側の || ec_warn が効く）"
else
    ng "LAN_CIDR が読めないと抜ける・失敗を返さない: $(printf '%s' "$out" | tr '\n' ' ')"
fi
[ "$(rules iptables OUTPUT | grep -c ec-sugar-bridge-guard)" = 2 ] && ok "LAN_CIDR が読めなくても、コンテナへの決まりは当てる" || ng "LAN_CIDR が読めないと、コンテナへの決まりも当てない"
printf 'MODE=staging\nLAN_CIDR=192.168.0.0/24\n' > "$EC_ANSWERS"
rc=0
ec_firewall_apply > /dev/null 2>&1 || rc=$?
lan="$(rules iptables DOCKER-USER | sort | tr '\n' '|')"
if [ "$rc" -eq 0 ] && [ "$lan" = "-p tcp -m conntrack --ctstate DNAT --ctdir ORIGINAL --ctorigdstport 443 ! -s 192.168.0.0/24 -m comment --comment ec-sugar-lan-only -j DROP|-p tcp -m conntrack --ctstate DNAT --ctdir ORIGINAL --ctorigdstport 80 ! -s 192.168.0.0/24 -m comment --comment ec-sugar-lan-only -j DROP|" ] &&
    [ -z "$(ec_lan_only_missing 2>&1 || echo x)" ]; then
    ok "ステージングは受け口の 80・443 番を LAN だけにする決まりを入れ、足りないものは無し"
else
    ng "ステージングの受け口の決まりが違う（返り ${rc}）: $lan"
fi
: > "$work/iptables.DOCKER-USER"
[ "$( { ec_lan_only_missing 2> /dev/null || true; } | tr '\n' ' ')" = "443 80 " ] && ok "受け口の決まりが消されたら、足りないポートを出す" || ng "受け口の決まりが消されたことに気づかない"
printf 'MODE=staging\nFRONT=proxy\nLAN_CIDR=192.168.0.0/24\n' > "$EC_ANSWERS"
ec_firewall_apply > /dev/null 2>&1 || true
[ ! -s "$work/iptables.DOCKER-USER" ] && [ -z "$(ec_lan_only_missing 2>&1 || echo x)" ] && ok "後ろに置く形には受け口の決まりを入れない（80・443 番は前の Web サーバーのもの）" || ng "後ろに置く形に受け口の決まりを入れた"
: > "$EC_ANSWERS"

# ---------- 4. 同じグループで動くもの ----------
# グループ: www-data(33)・ec-front-workers(2001、9 文字より長い名前)・nginx(101)。利用者 www-data（主のグループ 33）・shared（補助で 33）・site1・ngx
getent() {
    case "$1" in
        group)
            case "${2:-}" in
                www-data) echo 'www-data:x:33:shared' ;;
                ec-front-workers) echo 'ec-front-workers:x:2001:' ;;
                nginx) echo 'nginx:x:101:' ;;
                *) return 2 ;;
            esac ;;
        passwd) printf '%s\n' 'www-data:x:33:33::/var/www:/usr/sbin/nologin' 'shared:x:1500:1500::/home/shared:/bin/bash' \
            'site1:x:1600:1600::/home/site1:/bin/bash' 'ngx:x:101:101::/nonexistent:/usr/sbin/nologin' ;;
        *) return 2 ;;
    esac
}
id() {
    [ "$1" = -Gn ] || return 1
    case "${*: -1}" in
        www-data) echo www-data ;;
        shared) echo 'shared www-data' ;;
        site1) echo 'site1 psacln' ;;
        *) return 1 ;;
    esac
}
# 偽の ps: 「pid 実効の gid 補助の gid」（ps -eo pid=,egid=,supgid:…=）と、pid ごとの名前（ps -o comm= -p）
cat > "$work/ps" << 'EOF'
  100    33 33
  101    33 33
  200    33 33
  300  1500 1500,33
  400  2001 2001
  500  1600 1600
EOF
ps() {
    case "$*" in
        *egid*) cat "$work/ps" ;;
        "-o comm= -p 100") echo nginx ;;
        "-o comm= -p 101") echo nginx ;;
        "-o comm= -p 200") echo php-fpm8.3 ;;
        "-o comm= -p 300") echo cron-job ;;
        "-o comm= -p 400") echo php-cgi ;;
        "-o comm= -p 500") echo php-fpm8.3 ;;
        *) return 1 ;;
    esac
}
# 前の Web サーバー（443 番の pid とその子）は nginx の 100・101
ec_front_pids() { printf '100\n101\n'; }
ec_front_comm() { echo nginx; }
mkdir -p "$work/php/8.3/fpm/pool.d" "$work/cron"
printf '[www]\nuser = www-data\ngroup = www-data\npm = dynamic\n' > "$work/php/8.3/fpm/pool.d/www.conf"
printf '; ondemand のプール（今はプロセスが無い）\n[$pool]\n[shop]\nuser = shared\npm = ondemand\n[site1]\nuser = "site1"\ngroup = psacln ; Plesk の形\n' > "$work/php/8.3/fpm/pool.d/z.conf"
printf 'export APACHE_RUN_USER=www-data\nexport APACHE_RUN_GROUP="www-data"\n' > "$work/envvars"
printf '* * * * * php /var/www/job.php\n' > "$work/cron/www-data"
# 読み込んだ common.sh が使う変数を差し替える（shellcheck は読み込み先を追わないので、使われない変数の注意は出さない）
# shellcheck disable=SC2034
EC_PHP_POOL_GLOBS="$work/php/*/fpm/pool.d/*.conf $work/nothing/*.conf"
# shellcheck disable=SC2034
EC_APACHE_ENVVARS="$work/envvars"
# shellcheck disable=SC2034
EC_CRONTAB_DIR="$work/cron"

got="$(ec_shared_group_procs www-data || true)"
for want in php-fpm8.3 cron-job "php-fpm のプール www（$work/php/8.3/fpm/pool.d/www.conf）" "php-fpm のプール shop（$work/php/8.3/fpm/pool.d/z.conf）" \
    "Apache（$work/envvars の APACHE_RUN_GROUP）" "利用者 www-data の crontab"; do
    [[ "、${got}、" == *"、${want}、"* ]] && ok "www-data のグループで動くものに「${want}」を見つける" || ng "www-data のグループで動くものに「${want}」が無い（${got}）"
done
[[ "$got" != *nginx* ]] && ok "前の Web サーバーの働き手（nginx）は数えない" || ng "前の Web サーバーの働き手を数えた（${got}）"
[[ "$got" != *site1* ]] && [[ "$got" != *php-cgi* ]] && ok "ほかのグループのプールとプロセスは数えない" || ng "ほかのグループのものを数えた（${got}）"
got="$(ec_shared_group_procs ec-front-workers || true)"
[ "$got" = php-cgi ] && ok "長いグループの名前（ec-front-workers）でも、番号で見分ける" || ng "長いグループの名前で見分けられない（${got}）"
got="$(ec_shared_group_procs nginx || true)"
[ -z "$got" ] && ok "前の Web サーバーの働き手だけのグループでは、何も出さない" || ng "働き手だけのグループで何かを出した（${got}）"

# ---------- 10. Cloudflare Tunnel で出す形（ADR 0052） ----------
# 答えの確かめ（証明書の自動の取り方・待ち受けるアドレス・トークンの形）、cloudflared の設定（1 店舗・複数の店）、
# トークンを置き場へ写すときに、トークンが外のコマンドの引数・画面・記録に出ないこと
saved_answers10="$EC_ANSWERS"
saved_tunnel_dir="$EC_TUNNEL_DIR"
EC_ANSWERS="$work/answers10"
EC_TUNNEL_DIR="$work/tunnel"
mkdir -p "$work/t10"
# 偽のトークン（本物ではない。形だけ Cloudflare と同じ: base64 の JSON の a・t・s）。ID は大文字で入れ、小文字で出ることも見る
# 秘密の部分は流す時に作る（ファイルに秘密らしい文字列を書かない。コミットの前の秘密の検知に掛からないように）
tok_secret="$(printf 'fake%.0s' 1 2 3 4 5 6 7 8 9 10 11)"
tok_json='{"a":"0123456789abcdef0123456789abcdef","t":"6F1C2D3E-4A5B-4C6D-8E9F-0A1B2C3D4E5F","s":"'"$tok_secret"'"}'
token="$(printf '%s' "$tok_json" | base64 | tr -d '\n')"
want_id=6f1c2d3e-4a5b-4c6d-8e9f-0a1b2c3d4e5f
printf '%s\n' "$token" > "$work/t10/good"
printf '\r\n  %s  \r\n\n' "$token" > "$work/t10/good-crlf"
printf 'cloudflared service install %s\n' "$token" > "$work/t10/command"
printf '%s\n%s\n' "$token" "$token" > "$work/t10/twice"
printf '%s' '{"a":"0123456789abcdef0123456789abcdef","s":"'"$tok_secret"'"}' | base64 | tr -d '\n' > "$work/t10/no-id"
printf '%s' '{"a":"not-an-account","t":"6f1c2d3e-4a5b-4c6d-8e9f-0a1b2c3d4e5f","s":"'"$tok_secret"'"}' | base64 | tr -d '\n' > "$work/t10/bad-account"
printf 'eyJhIjoi-this-is-not-base64!!\n' > "$work/t10/garbage"
: > "$work/t10/empty"
head -c 5000 /dev/zero | tr '\0' 'A' > "$work/t10/huge"
got="$(ec_tunnel_token_id "$work/t10/good" || true)"
[ "$got" = "$want_id" ] && ok "Tunnel: トークンからトンネルの ID を読む（小文字の UUID）" || ng "Tunnel: トークンの ID が違う（${got}）"
got="$(ec_tunnel_token_id "$work/t10/good-crlf" || true)"
[ "$got" = "$want_id" ] && ok "Tunnel: 前後の空白・CR・空の行があっても読む" || ng "Tunnel: 空白や空の行のあるトークンを読めない（${got}）"
for bad in command twice no-id bad-account garbage empty huge; do
    rc=0
    out="$(ec_tunnel_token_id "$work/t10/$bad" 2>&1)" || rc=$?
    [ "$rc" -eq 1 ] && [ -z "$out" ] && ok "Tunnel: 形の悪いトークン（${bad}）を断り、何も出さない" || ng "Tunnel: 形の悪いトークン（${bad}）の返りが ${rc}・出力 ${#out} 文字"
done
rc=0
ec_tunnel_token_id "$work/t10/none" > /dev/null 2>&1 || rc=$?
[ "$rc" -eq 2 ] && ok "Tunnel: 無いファイルは「読めない」（2）" || ng "Tunnel: 無いファイルの返りが ${rc}"

# 答えの確かめ（ec_die で止まるので子のシェルで流す）。$1 名前 $2 期待する文の一部（空なら止まらないこと）
expect_answers() {
    local name="$1" want="$2" rc=0 out
    out="$( (ec_tunnel_check_answers) 2>&1)" || rc=$?
    if [ -z "$want" ]; then
        [ "$rc" -eq 0 ] && ok "Tunnel の答え: ${name}は通る" || ng "Tunnel の答え: ${name}が止まった（$(printf '%s' "$out" | head -2 | tr '\n' ' ')）"
    elif [ "$rc" -ne 0 ] && [[ "$out" == *"$want"* ]]; then
        ok "Tunnel の答え: ${name}を断る"
    else
        ng "Tunnel の答え: ${name}を断らない・文が違う（返り ${rc}: $(printf '%s' "$out" | head -2 | tr '\n' ' ')）"
    fi
    [[ "$out" != *"$token"* ]] && [[ "$out" != *"$tok_secret"* ]] || ng "Tunnel の答え: ${name}の文にトークンが出た"
}
printf 'FRONT=tunnel\nBIND=127.0.0.1\nTUNNEL_TOKEN_FILE=%s\n' "$work/t10/good" > "$EC_ANSWERS"
expect_answers "証明書を自動で取る形（auto）" "証明書を自動で取れません"
printf 'FRONT=tunnel\nBIND=0.0.0.0\nCLOUDFLARE_TOKEN_FILE=/root/cf\nTUNNEL_TOKEN_FILE=%s\n' "$work/t10/good" > "$EC_ANSWERS"
expect_answers "待ち受けるアドレスが 127.0.0.1 でない形" "127.0.0.1 だけです"
printf 'FRONT=tunnel\nCLOUDFLARE_TOKEN_FILE=/root/cf\nTUNNEL_TOKEN_FILE=%s\n' "$work/t10/good" > "$EC_ANSWERS"
expect_answers "待ち受けるアドレスが無い形" "127.0.0.1 だけです"
printf 'FRONT=tunnel\nBIND=127.0.0.1\nCLOUDFLARE_TOKEN_FILE=/root/cf\nTUNNEL_TOKEN_FILE=%s\n' "$work/t10/command" > "$EC_ANSWERS"
expect_answers "形の悪いトークン" "トークンの形が違います"
printf 'FRONT=tunnel\nBIND=127.0.0.1\nCLOUDFLARE_TOKEN_FILE=/root/cf\n' > "$EC_ANSWERS"
expect_answers "トークンのファイルが無い形" "TUNNEL_TOKEN_FILE"
printf 'FRONT=tunnel\nBIND=127.0.0.1\nCLOUDFLARE_TOKEN_FILE=/root/cf\nTUNNEL_TOKEN_FILE=%s\n' "$work/t10/good" > "$EC_ANSWERS"
expect_answers "DNS の確かめ（dns）・127.0.0.1・正しいトークン" ""
printf 'FRONT=tunnel\nBIND=127.0.0.1\nTLS_CERT=/root/c.pem\nTLS_KEY=/root/k.pem\nTUNNEL_TOKEN_FILE=%s\n' "$work/t10/good" > "$EC_ANSWERS"
expect_answers "手で用意した証明書（manual）" ""
# ステージングの受け口を LAN だけにする決まりは、Tunnel の形では要らない（127.0.0.1 だけで受ける）
printf 'MODE=staging\nFRONT=tunnel\nLAN_CIDR=192.168.0.0/24\n' > "$EC_ANSWERS"
! ec_lan_only_wanted && [ -z "$(ec_lan_only_missing)" ] && ok "Tunnel の形には、ステージングの受け口を LAN だけにする決まりを入れない" || ng "Tunnel の形に LAN だけの決まりを入れようとする"
printf 'MODE=staging\nFRONT=caddy\nLAN_CIDR=192.168.0.0/24\n' > "$EC_ANSWERS"
ec_lan_only_wanted && ok "caddy の形のステージングには、受け口を LAN だけにする決まりを入れる（前のまま）" || ng "caddy の形のステージングに LAN だけの決まりを入れない"
# 網の一覧: Tunnel の形の網（tunnel・tunnel_out）にも防火壁を当てる
idt=dddddddddddd3333333333333333333333333333333333333333333333333333
ido=eeeeeeeeeeee4444444444444444444444444444444444444444444444444444
FAKE_NETS="internal:$ida tunnel:$idt tunnel_out:$ido"
got="$(ec_bridges | sort | tr '\n' ' ')"
[ "$got" = "br-aaaaaaaaaaaa br-dddddddddddd br-eeeeeeeeeeee " ] && ok "Tunnel の形の網（tunnel・tunnel_out）の bridge にも防火壁を当てる" || ng "Tunnel の形の網の bridge が一覧に無い（${got}）"
FAKE_NETS=""

# cloudflared の設定（1 店舗・複数の店）。コメントの行を除いた中身がちょうどこの形
cfg_body() { grep -v '^#'; }
want_single='ingress:
  - hostname: "admin.example.jp"
    service: https://caddy:443
    originRequest:
      matchSNItoHost: true
  - hostname: "example.jp"
    service: https://caddy:443
    originRequest:
      matchSNItoHost: true
  - service: http_status:404'
want_multi='ingress:
  - hostname: "admin.example.jp"
    service: https://caddy:443
    originRequest:
      matchSNItoHost: true
  - hostname: "*.shops.example.jp"
    service: https://caddy:443
    originRequest:
      matchSNItoHost: true
  - service: http_status:404'
[ "$(ec_tunnel_config admin.example.jp example.jp | cfg_body)" = "$want_single" ] && ok "Tunnel の設定: 1 店舗は管理画面とショップのホストを Caddy の 443 番へ（SNI は名前どおり）、ほかは 404" || ng "Tunnel の設定（1 店舗）が違う: $(ec_tunnel_config admin.example.jp example.jp | cfg_body | tr '\n' '|')"
[ "$(ec_tunnel_config admin.example.jp '*.shops.example.jp' | cfg_body)" = "$want_multi" ] && ok "Tunnel の設定: 複数の店は管理画面と *.<ショップのドメイン>（引用符つき）" || ng "Tunnel の設定（複数の店）が違う: $(ec_tunnel_config admin.example.jp '*.shops.example.jp' | cfg_body | tr '\n' '|')"
for badhost in 'admin.example.jp"' 'Admin.example.jp' 'admin.example.jp
  - hostname: evil.example' ''; do
    out="$(ec_tunnel_config "$badhost" example.jp 2> /dev/null || echo FAILED)"
    [ "$out" = FAILED ] && ok "Tunnel の設定: おかしなホスト名（$(printf '%s' "$badhost" | head -n1 | cut -c1-24)）では作らない" || ng "Tunnel の設定: おかしなホスト名で作った"
done
[ "$(ec_tunnel_config admin.example.jp '*.bad host' 2> /dev/null || echo FAILED)" = FAILED ] && ok "Tunnel の設定: おかしなショップの名前では作らない" || ng "Tunnel の設定: おかしなショップの名前で作った"

# 置き場へ写す: 外のコマンドを、引数を書き残す偽の道具（中身はそのまま本物へ渡す。chown は書き残すだけ・install -d は mkdir）に替えて流す
shim="$work/shim"
mkdir -p "$shim"
export EC_ARGV_LOG="$work/argv.log" EC_REAL_PATH="$PATH"
cat > "$shim/shim" << 'EOF'
#!/bin/sh
name="$(PATH="$EC_REAL_PATH" basename "$0")"
printf '%s %s\n' "$name" "$*" >> "$EC_ARGV_LOG"
case "$name" in
    chown) exit 0 ;;
    install) for last in "$@"; do :; done; exec env PATH="$EC_REAL_PATH" mkdir -p "$last" ;;
esac
PATH="$EC_REAL_PATH" exec "$name" "$@"
EOF
chmod +x "$shim/shim"
for c in base64 wc grep mktemp cmp mv rm chmod chown install tr head cut sed cat; do ln -sf shim "$shim/$c"; done
: > "$EC_ARGV_LOG"
printf 'FRONT=tunnel\nBIND=127.0.0.1\nCLOUDFLARE_TOKEN_FILE=/root/cf\nSHOPS=single\nADMIN_HOST=admin.example.jp\nSHOP_HOST=example.jp\nTUNNEL_TOKEN_FILE=%s\n' "$work/t10/good" > "$EC_ANSWERS"
EC_TUNNEL_CHANGED=0
rc=0
out="$( { PATH="$shim:$PATH"; ec_tunnel_dir_ensure && ec_tunnel_put_token "$work/t10/good" && ec_tunnel_put_config && echo "changed=$EC_TUNNEL_CHANGED"; } 2>&1 )" || rc=$?
if [ "$rc" -eq 0 ] && [ "$(cat "$EC_TUNNEL_DIR/token")" = "$token" ] && [ "$(cfg_body < "$EC_TUNNEL_DIR/config.yml")" = "$want_single" ]; then
    ok "Tunnel: トークンと設定を置き場へ写す"
else
    ng "Tunnel: トークンか設定を写せない（返り ${rc}: $(printf '%s' "$out" | tail -2 | tr '\n' ' ')）"
fi
[[ "$out" == *changed=1* ]] && ok "Tunnel: 写したら cloudflared を作り直す印が付く" || ng "Tunnel: 写したのに作り直す印が付かない"
[ "$(ls -l "$EC_TUNNEL_DIR/token" | cut -c1-10)" = "-r--r-----" ] && [ "$(ls -l "$EC_TUNNEL_DIR/config.yml" | cut -c1-10)" = "-r--r-----" ] &&
    ok "Tunnel: トークンと設定は 0440" || ng "Tunnel: トークンか設定の権限が 0440 でない（$(ls -l "$EC_TUNNEL_DIR" | tr '\n' ' ')）"
grep -qxF "chown root:65533 $EC_TUNNEL_DIR" "$EC_ARGV_LOG" && grep -qE "^chown root:65533 $EC_TUNNEL_DIR/\.token\." "$EC_ARGV_LOG" && grep -qE "^chown root:65533 $EC_TUNNEL_DIR/\.config\.yml\." "$EC_ARGV_LOG" &&
    grep -qxF "install -d -m 0750 -o root -g 65533 $EC_TUNNEL_DIR" "$EC_ARGV_LOG" && ok "Tunnel: 置き場は root・グループ 65533・0750、ファイルは root・グループ 65533 に" || ng "Tunnel: 持ち主・グループの決め方が違う（$(grep -E '^(chown|install)' "$EC_ARGV_LOG" | tr '\n' '|')）"
if ! grep -qF -- "$token" "$EC_ARGV_LOG" && ! grep -qF -- "$tok_secret" "$EC_ARGV_LOG" && [[ "$out" != *"$token"* ]] && [[ "$out" != *"$tok_secret"* ]] &&
    ! grep -qF -- "$token" "$EC_TUNNEL_DIR/config.yml" && ! grep -qF -- "$tok_secret" "$EC_TUNNEL_DIR/config.yml" && [ -s "$EC_ARGV_LOG" ]; then
    ok "Tunnel: トークンは外のコマンドの引数（$(cut -d' ' -f1 "$EC_ARGV_LOG" | sort -u | tr '\n' ' ')）・画面・設定のファイルに出ない"
else
    ng "Tunnel: トークンが引数か画面か設定のファイルに出た"
fi
[ -z "$(find "$EC_TUNNEL_DIR" -name '.*' -type f)" ] && ok "Tunnel: 仮のファイルを残さない" || ng "Tunnel: 仮のファイルが残った"
# 2 回目（同じ中身）は入れ替えない。元のファイルを消した後は、前に写したものを使う
out="$( { PATH="$shim:$PATH"; EC_TUNNEL_CHANGED=0; ec_tunnel_put_token "$work/t10/good" && ec_tunnel_put_config && echo "changed=$EC_TUNNEL_CHANGED"; } 2>&1 )" || true
[[ "$out" == *changed=0* ]] && ok "Tunnel: 同じ中身なら入れ替えない（cloudflared を作り直さない）" || ng "Tunnel: 同じ中身でも入れ替えた（$(printf '%s' "$out" | tail -1)）"
rc=0
out="$( (ec_tunnel_put_token "$work/t10/gone") 2>&1)" || rc=$?
[ "$rc" -eq 0 ] && [ "$(cat "$EC_TUNNEL_DIR/token")" = "$token" ] && ok "Tunnel: 元のファイルを消した後は、前に写したトークンを使う" || ng "Tunnel: 元のファイルが無いと止まる（返り ${rc}）"
printf 'FRONT=tunnel\nBIND=127.0.0.1\nCLOUDFLARE_TOKEN_FILE=/root/cf\nTUNNEL_TOKEN_FILE=%s\n' "$work/t10/gone" > "$EC_ANSWERS"
expect_answers "元のファイルを消した後（前に写したトークンがある）" ""
# 形の悪いトークンは写さない（置いてあるものはそのまま）。文に中身を出さない
printf 'cloudflared service install %s-other\n' "$token" > "$work/t10/command2"
rc=0
out="$( (PATH="$shim:$PATH"; ec_tunnel_put_token "$work/t10/command2") 2>&1)" || rc=$?
[ "$rc" -ne 0 ] && [ "$(cat "$EC_TUNNEL_DIR/token")" = "$token" ] && [[ "$out" != *"$token"* ]] && ok "Tunnel: 形の悪いトークンは写さず、文にも出さない" || ng "Tunnel: 形の悪いトークンを写した・文に出した（返り ${rc}）"
# 複数の店の設定
printf 'SHOPS=multi\nADMIN_HOST=admin.example.jp\nSHOP_DOMAIN=shops.example.jp\nSHOP_ID=demo\n' > "$EC_ANSWERS"
( PATH="$shim:$PATH"; ec_tunnel_put_config > /dev/null 2>&1 ) || true
[ "$(cfg_body < "$EC_TUNNEL_DIR/config.yml")" = "$want_multi" ] && ok "Tunnel: 答えが複数の店なら、設定は管理画面と *.<ショップのドメイン>" || ng "Tunnel: 複数の店の設定が違う"
# 置き場のトークンからも ID を読める（3/5 の CNAME の案内）
printf 'FRONT=tunnel\n' > "$EC_ANSWERS"
[ "$(ec_tunnel_id || true)" = "$want_id" ] && ok "Tunnel: 答えにトークンのファイルが無くても、置き場のトークンから ID を読む（3/5 の CNAME の案内）" || ng "Tunnel: 置き場のトークンから ID を読めない"
EC_ANSWERS="$saved_answers10"
EC_TUNNEL_DIR="$saved_tunnel_dir"
unset EC_ARGV_LOG EC_REAL_PATH

# ---------- 13. Cloudflare の Origin CA の証明書（Tunnel の形。見直しの中 3） ----------
# 手で渡した証明書が Origin CA のものなら config.yml に caPool を足し、元の証明書を置き場へ写す（root:65533・0440。設定より先に）。
# 公開の認証局のものなら足さない（前に置いた元は片付ける）。Tunnel 以外の形で Origin CA の証明書なら止める。名前だけ似せたものも止める。
# dns から manual へ替えたら DNS の鍵が片付く。証明書はここで作る（試験の元に Origin CA と同じ名前を付ける。本物の元はリポジトリのものを指紋で見る）
saved_answers13="$EC_ANSWERS"
saved_tunnel_dir13="$EC_TUNNEL_DIR"
saved_caddy13="$EC_CADDY_SECRETS"
saved_roots13="$EC_ORIGIN_CA_ROOTS"
EC_ANSWERS="$work/answers13"
EC_TUNNEL_DIR="$work/tunnel13"
EC_CADDY_SECRETS="$work/caddy13"
c13="$work/c13"
mkdir -p "$c13" "$EC_TUNNEL_DIR" "$EC_CADDY_SECRETS/tls"
# $1 名前 $2 主体。自己署名の元（CA）
mk_ca13() {
    openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -keyout "$c13/$1.key" -out "$c13/$1.crt" -days 3650 -subj "$2" \
        -addext basicConstraints=critical,CA:TRUE -addext keyUsage=critical,keyCertSign,cRLSign > /dev/null 2>&1
}
# $1 名前 $2 署名する元の名前 $3 名前（SAN）。Cloudflare の画面で作る形に似せる（主体は CloudFlare Origin Certificate、15 年）
mk_leaf13() {
    openssl req -new -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -keyout "$c13/$1.key" -out "$c13/$1.csr" \
        -subj '/O=CloudFlare, Inc./OU=CloudFlare Origin CA/CN=CloudFlare Origin Certificate' > /dev/null 2>&1
    printf 'subjectAltName=%s\nextendedKeyUsage=serverAuth,clientAuth\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\n' "$3" > "$c13/$1.ext"
    openssl x509 -req -in "$c13/$1.csr" -CA "$c13/$2.crt" -CAkey "$c13/$2.key" -CAcreateserial -days 5475 -out "$c13/$1.crt" -extfile "$c13/$1.ext" > /dev/null 2>&1
}
mk_ca13 oca-ecc '/C=US/ST=California/L=San Francisco/O=CloudFlare, Inc./OU=CloudFlare Origin SSL ECC Certificate Authority'
mk_ca13 oca-rsa '/C=US/O=CloudFlare, Inc./OU=CloudFlare Origin SSL Certificate Authority/L=San Francisco/ST=California'
mk_ca13 pub '/C=JP/O=Example Public Trust/CN=Example Public Root'
sans13='DNS:admin.example.jp,DNS:example.jp,DNS:*.example.jp'
mk_leaf13 origin oca-ecc "$sans13"
mk_leaf13 origin-rsa oca-rsa "$sans13"
mk_leaf13 origin-noshop oca-ecc 'DNS:admin.example.jp'
mk_leaf13 public pub "$sans13"
# 名前だけ似せたもの: 発行者の名前は Origin CA だが、元の証明書で署名されていない（自己署名）
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -keyout "$c13/fake.key" -out "$c13/fake.crt" -days 3650 \
    -subj '/C=US/ST=California/L=San Francisco/O=CloudFlare, Inc./OU=CloudFlare Origin SSL ECC Certificate Authority' \
    -addext "subjectAltName=$sans13" -addext basicConstraints=critical,CA:FALSE > /dev/null 2>&1
# 試験の元のファイル（リポジトリのものと同じく、頭にコメントの行がある）
{ printf '# 試験の元（このコメントの行は置き場へ写さない）\n'; cat "$c13/oca-rsa.crt" "$c13/oca-ecc.crt"; } > "$c13/roots.crt"
EC_ORIGIN_CA_ROOTS="$c13/roots.crt"
if [ -s "$c13/origin.crt" ] && [ -s "$c13/origin-rsa.crt" ] && [ -s "$c13/public.crt" ] && [ -s "$c13/fake.crt" ] && [ -s "$c13/origin-noshop.crt" ]; then
    ok "Origin CA: 試験の証明書を作れた"
else
    ng "Origin CA: 試験の証明書を作れない（openssl）"
fi

# 発行者の名前で見分ける
ec_cert_is_origin_ca "$c13/origin.crt" && ec_cert_is_origin_ca "$c13/origin-rsa.crt" && ok "Origin CA: 発行者が Origin CA（ECC・RSA）の証明書を見分ける" || ng "Origin CA: 発行者が Origin CA の証明書を見分けない"
! ec_cert_is_origin_ca "$c13/public.crt" && ! ec_cert_is_origin_ca "$c13/none.crt" && ok "Origin CA: 公開の認証局の証明書・無いファイルは Origin CA でない" || ng "Origin CA: 公開の認証局の証明書か無いファイルを Origin CA と見た"
ec_cert_is_origin_ca deploy/cloudflare/origin-ca-roots.crt && ok "Origin CA: リポジトリの元の証明書の名前も Origin CA" || ng "Origin CA: リポジトリの元の証明書の名前を見分けない"

# リポジトリの元の証明書: ちょうど 2 つ・指紋がコメントに書いたもの（Cloudflare の公式の文書から取ったもの）・秘密の鍵が無い
real13=deploy/cloudflare/origin-ca-roots.crt
rm -f "$c13"/real*.crt
awk -v d="$c13" '/^-----BEGIN CERTIFICATE-----$/{n++; f=sprintf("%s/real%d.crt", d, n)} f{print > f} /^-----END CERTIFICATE-----$/{close(f); f=""}' "$real13"
fps13="$(for f in "$c13"/real*.crt; do openssl x509 -in "$f" -noout -fingerprint -sha256 2> /dev/null | cut -d= -f2; done | sort | tr '\n' ' ')"
want_fps13='AA:63:69:7A:22:76:4B:67:B2:13:4C:E1:4C:B5:69:0E:A3:36:94:0B:93:98:61:13:F4:95:45:91:78:32:D8:0D D3:C7:E8:5C:91:70:7F:C0:A1:2A:BC:5D:88:26:67:47:AA:4F:A8:E7:B1:62:F6:33:FF:B3:C9:D9:89:94:76:20 '
if [ "$fps13" = "$want_fps13" ] && [ "$(grep -c '^-----BEGIN CERTIFICATE-----$' "$real13")" = 2 ] && ! grep -q 'PRIVATE KEY' "$real13" &&
    grep -qF 'D3:C7:E8:5C:91:70:7F:C0:A1:2A:BC:5D:88:26:67:47:AA:4F:A8:E7:B1:62:F6:33:FF:B3:C9:D9:89:94:76:20' "$real13" &&
    grep -qF 'AA:63:69:7A:22:76:4B:67:B2:13:4C:E1:4C:B5:69:0E:A3:36:94:0B:93:98:61:13:F4:95:45:91:78:32:D8:0D' "$real13"; then
    ok "Origin CA: リポジトリの元の証明書は 2 つ（RSA・ECC）で、指紋が Cloudflare の公式のもの（コメントにも書いてある）"
else
    ng "Origin CA: リポジトリの元の証明書の数か指紋が違う（${fps13}）"
fi

# cloudflared の設定: origin-ca なら行き先ごとに caPool。ほかの値では作らない。確かめを切る書き方は無い
want_single_ca='ingress:
  - hostname: "admin.example.jp"
    service: https://caddy:443
    originRequest:
      matchSNItoHost: true
      caPool: /etc/cloudflared/origin-ca.pem
  - hostname: "example.jp"
    service: https://caddy:443
    originRequest:
      matchSNItoHost: true
      caPool: /etc/cloudflared/origin-ca.pem
  - service: http_status:404'
[ "$(ec_tunnel_config admin.example.jp example.jp origin-ca | cfg_body)" = "$want_single_ca" ] && ok "Tunnel の設定: Origin CA なら行き先ごとに caPool（matchSNItoHost はそのまま）" || ng "Tunnel の設定（caPool）が違う: $(ec_tunnel_config admin.example.jp example.jp origin-ca | cfg_body | tr '\n' '|')"
[ "$(ec_tunnel_config admin.example.jp example.jp 'x
      noTLSVerify: true' 2> /dev/null || echo FAILED)" = FAILED ] && ok "Tunnel の設定: 知らない 3 つ目の値では作らない" || ng "Tunnel の設定: 知らない 3 つ目の値で作った"
if grep -rn 'noTLSVerify' deploy docker | grep -vE ':[0-9]+:[[:space:]]*#' | grep -v 'docker/check-deploy.sh' > /dev/null; then
    ng "noTLSVerify を書いている所がある（コメント以外）"
else
    ok "deploy・docker のどこにも noTLSVerify を書かない（コメントだけ）"
fi

# 置き場へ写す（偽の道具で引数を書き残す。10 と同じ）
export EC_ARGV_LOG="$work/argv13.log" EC_REAL_PATH="$PATH"
: > "$EC_ARGV_LOG"
ans13() { printf 'FRONT=%s\nBIND=127.0.0.1\nSHOPS=single\nADMIN_HOST=admin.example.jp\nSHOP_HOST=example.jp\n%s' "$1" "$2" > "$EC_ANSWERS"; }
manual13="TLS_CERT=$c13/given.crt
TLS_KEY=$c13/given.key
"
ans13 tunnel "$manual13"
cp "$c13/origin.crt" "$EC_CADDY_SECRETS/tls/cert.pem"
rc=0
out="$( { PATH="$shim:$PATH"; EC_TUNNEL_CHANGED=0; ec_tunnel_put_config && echo "changed=$EC_TUNNEL_CHANGED"; } 2>&1 )" || rc=$?
if [ "$rc" -eq 0 ] && [ "$(cfg_body < "$EC_TUNNEL_DIR/config.yml")" = "$want_single_ca" ] && [[ "$out" == *changed=1* ]]; then
    ok "Origin CA: Tunnel の形で手で渡した証明書が Origin CA なら、config.yml に caPool が付き、cloudflared を作り直す印が付く"
else
    ng "Origin CA: caPool が付かない（返り ${rc}: $(printf '%s' "$out" | tail -2 | tr '\n' ' ')）"
fi
if [ "$(cat "$EC_TUNNEL_DIR/origin-ca.pem" 2> /dev/null)" = "$(cat "$c13/oca-rsa.crt" "$c13/oca-ecc.crt")" ]; then
    ok "Origin CA: 元の証明書を置き場の origin-ca.pem へ写す（証明書の部分だけ。コメントの行は写さない）"
else
    ng "Origin CA: 置き場の origin-ca.pem の中身が違う"
fi
[ "$(ls -l "$EC_TUNNEL_DIR/origin-ca.pem" 2> /dev/null | cut -c1-10)" = "-r--r-----" ] && grep -qE "^chown root:65533 $EC_TUNNEL_DIR/\.origin-ca\.pem\." "$EC_ARGV_LOG" &&
    ok "Origin CA: 元の証明書は root・グループ 65533・0440" || ng "Origin CA: 元の証明書の持ち主か権限が違う（$(ls -l "$EC_TUNNEL_DIR" | tr '\n' ' ')）"
ca_line13="$(grep -nE "^chown root:65533 $EC_TUNNEL_DIR/\.origin-ca\.pem\." "$EC_ARGV_LOG" | head -n1 | cut -d: -f1)"
cfg_line13="$(grep -nE "^chown root:65533 $EC_TUNNEL_DIR/\.config\.yml\." "$EC_ARGV_LOG" | head -n1 | cut -d: -f1)"
[ -n "$ca_line13" ] && [ -n "$cfg_line13" ] && [ "$ca_line13" -lt "$cfg_line13" ] && ok "Origin CA: 元の証明書を置いてから、それを指す設定を置く" || ng "Origin CA: 元の証明書より先に設定を置いた（${ca_line13:-無い}・${cfg_line13:-無い}）"
[ -z "$(find "$EC_TUNNEL_DIR" -name '.*' -type f)" ] && ok "Origin CA: 仮のファイルを残さない" || ng "Origin CA: 仮のファイルが残った"
# 2 回目（同じ中身）は入れ替えない
out="$( { PATH="$shim:$PATH"; EC_TUNNEL_CHANGED=0; ec_tunnel_put_config && echo "changed=$EC_TUNNEL_CHANGED"; } 2>&1 )" || true
[[ "$out" == *changed=0* ]] && ok "Origin CA: 同じ中身なら入れ替えない" || ng "Origin CA: 同じ中身でも入れ替えた"
# RSA の Origin CA の証明書でも付く
cp "$c13/origin-rsa.crt" "$EC_CADDY_SECRETS/tls/cert.pem"
( PATH="$shim:$PATH"; ec_tunnel_put_config > /dev/null 2>&1 ) || true
[ "$(cfg_body < "$EC_TUNNEL_DIR/config.yml")" = "$want_single_ca" ] && ok "Origin CA: RSA の Origin CA の証明書でも caPool が付く" || ng "Origin CA: RSA の Origin CA の証明書で caPool が付かない"
# 元の証明書が読めない・証明書が入っていないなら止め、前の設定と元はそのまま
for bad13 in none comments; do
    printf '# 証明書の無いファイル\n' > "$c13/comments.crt"
    rc=0
    out="$( (EC_ORIGIN_CA_ROOTS="$c13/$bad13.crt"; PATH="$shim:$PATH"; ec_tunnel_put_config) 2>&1)" || rc=$?
    if [ "$rc" -ne 0 ] && [[ "$out" == *"Origin CA の元の証明書"* ]] && [ "$(cfg_body < "$EC_TUNNEL_DIR/config.yml")" = "$want_single_ca" ] && [ -s "$EC_TUNNEL_DIR/origin-ca.pem" ]; then
        ok "Origin CA: 元の証明書が${bad13}なら止め、置いてある設定と元は変えない"
    else
        ng "Origin CA: 元の証明書が${bad13}でも止まらないか、置いてあるものを変えた（返り ${rc}）"
    fi
done
# 公開の認証局の証明書に替えたら、caPool を外し、元の証明書を片付ける
cp "$c13/public.crt" "$EC_CADDY_SECRETS/tls/cert.pem"
rc=0
out="$( { PATH="$shim:$PATH"; EC_TUNNEL_CHANGED=0; ec_tunnel_put_config && echo "changed=$EC_TUNNEL_CHANGED"; } 2>&1 )" || rc=$?
if [ "$rc" -eq 0 ] && [ "$(cfg_body < "$EC_TUNNEL_DIR/config.yml")" = "$want_single" ] && [ ! -e "$EC_TUNNEL_DIR/origin-ca.pem" ] && [[ "$out" == *changed=1* ]]; then
    ok "Origin CA: 公開の認証局の証明書なら caPool を付けず、前に置いた元の証明書を片付ける"
else
    ng "Origin CA: 公開の認証局の証明書なのに caPool が残るか、元の証明書が残った（返り ${rc}）"
fi
# DNS の確かめ（dns）なら、前に写した証明書が Origin CA のものでも付けない
ans13 tunnel 'CLOUDFLARE_TOKEN_FILE=/root/cf
'
cp "$c13/origin.crt" "$EC_CADDY_SECRETS/tls/cert.pem"
( PATH="$shim:$PATH"; ec_tunnel_put_config > /dev/null 2>&1 ) || true
[ "$(cfg_body < "$EC_TUNNEL_DIR/config.yml")" = "$want_single" ] && [ ! -e "$EC_TUNNEL_DIR/origin-ca.pem" ] && ok "Origin CA: DNS の確かめの形では caPool を付けない" || ng "Origin CA: DNS の確かめの形で caPool を付けた"

# 手で渡した証明書の確かめ（止まるので子のシェルで）。$1 名前 $2 期待する文の一部（空なら止まらないこと） $3 以降 確かめるコマンド
expect13() {
    local name="$1" want="$2" rc=0 out
    shift 2
    out="$( ("$@") 2>&1)" || rc=$?
    if [ -z "$want" ]; then
        [ "$rc" -eq 0 ] && ok "証明書の確かめ: ${name}は通る" || ng "証明書の確かめ: ${name}が止まった（$(printf '%s' "$out" | head -3 | tr '\n' ' ')）"
    elif [ "$rc" -ne 0 ] && [[ "$out" == *"$want"* ]]; then
        ok "証明書の確かめ: ${name}を断る"
    else
        ng "証明書の確かめ: ${name}を断らない・文が違う（返り ${rc}: $(printf '%s' "$out" | head -3 | tr '\n' ' ')）"
    fi
}
ans13 tunnel "$manual13"
expect13 "Tunnel の形で Origin CA の証明書" "" ec_check_tls "$c13/origin.crt" "$c13/origin.key"
expect13 "Tunnel の形で RSA の Origin CA の証明書" "" ec_check_tls "$c13/origin-rsa.crt" "$c13/origin-rsa.key"
expect13 "Tunnel の形で公開の認証局の証明書" "" ec_check_tls "$c13/public.crt" "$c13/public.key"
expect13 "名前だけ Origin CA に似せた証明書" "確認できません" ec_check_tls "$c13/fake.crt" "$c13/fake.key"
expect13 "ショップの名前を覆わない Origin CA の証明書" "ショップの名前 example.jp" ec_check_tls "$c13/origin-noshop.crt" "$c13/origin-noshop.key"
expect13 "鍵が対でない Origin CA の証明書" "対になっていません" ec_check_tls "$c13/origin.crt" "$c13/public.key"
printf 'FRONT=tunnel\nBIND=127.0.0.1\nSHOPS=multi\nADMIN_HOST=admin.example.jp\nSHOP_DOMAIN=example.jp\nSHOP_ID=demo\n%s' "$manual13" > "$EC_ANSWERS"
expect13 "複数の店（*.example.jp）で Origin CA の証明書" "" ec_check_tls "$c13/origin.crt" "$c13/origin.key"
EC_ORIGIN_CA_ROOTS="$c13/none.crt"
expect13 "元の証明書が読めない時の Origin CA の証明書" "元の証明書（$c13/none.crt）が読めません" ec_check_tls "$c13/origin.crt" "$c13/origin.key"
EC_ORIGIN_CA_ROOTS="$c13/roots.crt"
ans13 caddy "$manual13"
expect13 "Caddy の形で Origin CA の証明書" "Cloudflare Tunnel の構成（FRONT=tunnel）でだけ" ec_check_tls "$c13/origin.crt" "$c13/origin.key"
expect13 "Caddy の形で Origin CA の証明書（2/5 の確かめ）" "でだけ使えます" ec_check_origin_ca "$c13/origin.crt"
expect13 "Caddy の形で公開の認証局の証明書" "" ec_check_tls "$c13/public.crt" "$c13/public.key"
ans13 proxy "$manual13"
expect13 "後ろに置く形で Origin CA の証明書" "でだけ使えます" ec_check_origin_ca "$c13/origin.crt"
grep -qF 'ec_check_origin_ca "$cert"' deploy/install.sh && grep -qF 'ec_check_tls "$cert" "$key"' deploy/install.sh &&
    grep -qxF 'EC_ORIGIN_CA_ROOTS="$EC_DEPLOY_DIR/cloudflare/origin-ca-roots.crt"' deploy/install.sh &&
    ok "install.sh が 2/5 と 4/5 で証明書を確かめ、リポジトリの元の証明書を渡す" || ng "install.sh が Origin CA の確かめか元の証明書の道筋を使っていない"
# 入口の証明書を写してから cloudflared の設定を書く（設定は写した証明書を見て caPool を決める）
l_caddy13="$(grep -nx '    install_caddy_secrets' deploy/install.sh | cut -d: -f1)"
l_tunnel13="$(grep -nx '    install_tunnel_secrets' deploy/install.sh | cut -d: -f1)"
[ -n "$l_caddy13" ] && [ -n "$l_tunnel13" ] && [ "$l_caddy13" -lt "$l_tunnel13" ] && ok "install.sh は入口の証明書を写してから cloudflared の設定を書く" || ng "install.sh の順が違う（${l_caddy13:-無い}・${l_tunnel13:-無い}）"

# dns から manual へ切り替える: 答えの CLOUDFLARE_TOKEN_FILE を消す（コメントにする・空にするでも）と manual になり、
# 新しい形で立って健全になった後の片付けで DNS の鍵が消える（手で渡した証明書は残す）
printf 'CLOUDFLARE_TOKEN_FILE=/root/cf\n' > "$EC_ANSWERS"
m1="$(ec_tls_mode)"
printf '#CLOUDFLARE_TOKEN_FILE=/root/cf\nTLS_CERT=/root/c.pem\nTLS_KEY=/root/k.pem\n' > "$EC_ANSWERS"
m2="$(ec_tls_mode)"
printf 'CLOUDFLARE_TOKEN_FILE=\nTLS_CERT=/root/c.pem\nTLS_KEY=/root/k.pem\n' > "$EC_ANSWERS"
m3="$(ec_tls_mode)"
[ "$m1 $m2 $m3" = "dns manual manual" ] && ok "切り替え: 答えの CLOUDFLARE_TOKEN_FILE を消す・コメントにする・空にすると manual" || ng "切り替えの取り方の読み方が違う（${m1} ${m2} ${m3}）"
rm -rf "$EC_CADDY_SECRETS"
mkdir -p "$EC_CADDY_SECRETS/tls"
printf 'x\n' > "$EC_CADDY_SECRETS/cloudflare_token"
cp "$c13/origin.crt" "$EC_CADDY_SECRETS/tls/cert.pem"
cp "$c13/origin.key" "$EC_CADDY_SECRETS/tls/key.pem"
printf '#CLOUDFLARE_TOKEN_FILE=/root/cf\nTLS_CERT=/root/c.pem\nTLS_KEY=/root/k.pem\n' > "$EC_ANSWERS"
ec_prune_caddy_secrets
[ ! -e "$EC_CADDY_SECRETS/cloudflare_token" ] && [ -s "$EC_CADDY_SECRETS/tls/cert.pem" ] && [ -s "$EC_CADDY_SECRETS/tls/key.pem" ] &&
    ok "切り替え: dns から manual へ替えたら DNS の鍵を消し、手で渡した証明書と鍵は残す" || ng "切り替え: DNS の鍵が残ったか、証明書を消した（$(ls "$EC_CADDY_SECRETS" | tr '\n' ' ')）"
printf 'x\n' > "$EC_CADDY_SECRETS/cloudflare_token"
printf 'CLOUDFLARE_TOKEN_FILE=/root/cf\n' > "$EC_ANSWERS"
ec_prune_caddy_secrets
[ -s "$EC_CADDY_SECRETS/cloudflare_token" ] && [ ! -e "$EC_CADDY_SECRETS/tls" ] && ok "切り替え: dns のままなら DNS の鍵を残し、使わない証明書は消す（前のまま）" || ng "切り替え: dns の形で片付け方が違う"
grep -qx '    ec_prune_caddy_secrets' deploy/install.sh && ok "install.sh が立って健全になった後に入口の秘密を片付ける" || ng "install.sh が ec_prune_caddy_secrets を呼んでいない"

EC_ANSWERS="$saved_answers13"
EC_TUNNEL_DIR="$saved_tunnel_dir13"
EC_CADDY_SECRETS="$saved_caddy13"
EC_ORIGIN_CA_ROOTS="$saved_roots13"
unset EC_ARGV_LOG EC_REAL_PATH
# ---------- 14. トンネルの見張り（漏れたトークンに気づく。見直しの中 5） ----------
# 偽の curl で Cloudflare の API と「このサーバーを外から見た住所」を答え、接続役のつなぎ元が合う・よそ・0 件・API の失敗・鍵なしの
# 結果の語を見る。読むだけの鍵は curl の設定として標準入力でだけ渡り、引数・画面・記録（state）に出ないこと
saved_answers13="$EC_ANSWERS"
saved_tunnel_dir13="$EC_TUNNEL_DIR"
saved_watch_dir13="$EC_TUNNEL_WATCH_DIR"
saved_state13="$EC_STATE"
saved_wait13="$EC_TUNNEL_WATCH_RETRY_WAIT"
EC_ANSWERS="$work/answers13"
EC_TUNNEL_DIR="$work/t13/tunnel"
EC_TUNNEL_WATCH_DIR="$work/t13/watch"
EC_STATE="$work/t13/state"
EC_TUNNEL_WATCH_RETRY_WAIT=0
mkdir -p "$EC_TUNNEL_DIR" "$EC_TUNNEL_WATCH_DIR" "$EC_STATE" "$work/t13/bin" "$work/t13/cf"
printf '%s\n' "$token" > "$EC_TUNNEL_DIR/token"
# 偽の読むだけの鍵（本物ではない。流す時に作る）
watch_key="$(printf 'k%.0s' 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30 31 32 33 34 35 36 37 38 39 40)"
export FAKE_CF_DIR="$work/t13/cf" FAKE_CF_LOG="$work/t13/curl.log" FAKE_OWN4="" FAKE_OWN6=""
cat > "$work/t13/bin/curl" << 'EOF'
#!/bin/sh
# 偽の curl: 引数を書き残す（鍵が出ないかを見る）。--config - は標準入力の設定を残し、呼ばれた回ごとの答え（body.<回>、無ければ body）を返す。
# 答えのファイルが無ければ 22（HTTP の失敗）。-4・-6 は「このサーバーを外から見た住所」（FAKE_OWN4・FAKE_OWN6。空なら届かない）
printf 'curl %s\n' "$*" >> "$FAKE_CF_LOG"
case "$*" in
    *"--config -"*)
        cat > "$FAKE_CF_DIR/config.last"
        n="$(cat "$FAKE_CF_DIR/calls" 2> /dev/null || echo 0)"
        n=$((n + 1))
        echo "$n" > "$FAKE_CF_DIR/calls"
        body="$FAKE_CF_DIR/body.$n"
        [ -f "$body" ] || body="$FAKE_CF_DIR/body"
        [ -f "$body" ] || exit 22
        cat "$body"
        ;;
    *"-4 "*) [ -n "$FAKE_OWN4" ] || exit 7; printf 'fl=1\nh=1.1.1.1\nip=%s\nts=1\n' "$FAKE_OWN4" ;;
    *"-6 "*) [ -n "$FAKE_OWN6" ] || exit 7; printf 'fl=1\nh=[2606:4700:4700::1111]\nip=%s\nts=1\n' "$FAKE_OWN6" ;;
    *) exit 2 ;;
esac
EOF
chmod +x "$work/t13/bin/curl"
: > "$FAKE_CF_LOG"

# IPv6 の頭の /64
for pair in '2001:db8::1=2001:0db8:0000:0000' '2001:DB8:1:2::abcd=2001:0db8:0001:0002' '::1=0000:0000:0000:0000' '1:2:3:4:5:6:7:8=0001:0002:0003:0004' \
    '1:2:3:4:5:6:7::=0001:0002:0003:0004' 'fe80::=fe80:0000:0000:0000'; do
    got="$(ec_ipv6_prefix64 "${pair%%=*}" || echo FAILED)"
    [ "$got" = "${pair#*=}" ] && ok "IPv6 の頭の /64: ${pair%%=*}" || ng "IPv6 の頭の /64: ${pair%%=*} が ${got}"
done
for bad in '1::2::3' '::ffff:198.51.100.7' '12345::1' '1:2:3:4:5:6:7:8:9' '2001:db8::/64' ':1:2:3:4:5:6:7' '1:2:3:4:5:6:7:' '1:2:3:4:5:6:7:8::' 'g::1' ''; do
    ec_ipv6_prefix64 "$bad" > /dev/null 2>&1 && ng "IPv6 の形の違う $bad を受けた" || ok "IPv6 の形の違う $(printf '%q' "$bad") を断る"
done

# $1 名前 $2 期待する語。答え（body.*）を置いてから呼ぶ
expect_watch() {
    local got
    rm -f "$FAKE_CF_DIR/calls"
    got="$( (PATH="$work/t13/bin:$PATH"; ec_tunnel_watch_check) 2> "$work/t13/err")"
    [ "$got" = "$2" ] && ok "見張り: ${1}は ${2}" || ng "見張り: ${1}が ${got:-空}（${2} のはず）"
}
cf_body() { rm -f "$FAKE_CF_DIR"/body*; printf '%s' "$1" > "$FAKE_CF_DIR/body"; }
match_json='{"result":[{"id":"c1","conns":[{"colo_name":"nrt01","origin_ip":"198.51.100.7"},{"colo_name":"kix01","origin_ip":"198.51.100.7"}]},{"id":"c2","conns":[{"origin_ip":"2001:DB8:1:2::99"}]}],"success":true,"errors":[],"messages":[]}'
FAKE_OWN4=198.51.100.7
FAKE_OWN6=2001:db8:1:2::abcd

# 鍵なし（見ていない）は API に聞かない
cf_body "$match_json"
expect_watch "読むだけの鍵が無いとき" unwatched
[ ! -e "$FAKE_CF_DIR/calls" ] && ok "見張り: 鍵が無ければ API に聞かない" || ng "見張り: 鍵が無いのに API に聞いた"
printf '%s\n' "$watch_key" > "$EC_TUNNEL_WATCH_DIR/token"

# 合う（IPv4 はそのまま、IPv6 は同じ /64）
expect_watch "どの接続役もこのサーバーから（IPv4 と IPv6 の同じ /64）" ok
grep -qxF "url = \"https://api.cloudflare.com/client/v4/accounts/0123456789abcdef0123456789abcdef/cfd_tunnel/$want_id/connections\"" "$FAKE_CF_DIR/config.last" &&
    grep -qxF "header = \"Authorization: Bearer $watch_key\"" "$FAKE_CF_DIR/config.last" &&
    ok "見張り: トークンのアカウントとトンネルの ID の接続役の一覧を、鍵を見出しに付けて聞く（設定は標準入力）" || ng "見張り: 聞き方が違う（$(tr '\n' '|' < "$FAKE_CF_DIR/config.last")）"
# よそ（IPv4・IPv6 の別の /64・文字でないつなぎ元）
cf_body '{"success": true, "result": [{"id":"c1","conns":[{"origin_ip":"198.51.100.7"}]},{"id":"c3","conns":[{"origin_ip": "203.0.113.9"}]}]}'
expect_watch "よその IPv4 からの接続役があるとき" foreign
cf_body '{"success":true,"result":[{"conns":[{"origin_ip":"198.51.100.7"},{"origin_ip":"2001:db8:1:3::5"}]}]}'
expect_watch "よその /64 の IPv6 からの接続役があるとき" foreign
cf_body '{"success":true,"result":[{"conns":[{"origin_ip":"198.51.100.7"},{"origin_ip":null}]}]}'
expect_watch "つなぎ元が住所の形でない接続役があるとき" foreign
FAKE_OWN6=""
cf_body "$match_json"
expect_watch "このサーバーに IPv6 が無いのに IPv6 の接続役があるとき" foreign
FAKE_OWN6=2001:db8:1:2::abcd
# 0 件（聞き直しても 0 なら down。1 回目だけ 0 なら聞き直した答えで）
cf_body '{"success":true,"errors":[],"messages":[],"result":[]}'
expect_watch "接続役が 0 のとき" down
[ "$(cat "$FAKE_CF_DIR/calls")" = 2 ] && ok "見張り: 0 件なら聞き直してから決める" || ng "見張り: 0 件で聞き直していない（$(cat "$FAKE_CF_DIR/calls") 回）"
cf_body '{"success":true,"result":[]}'
printf '%s' "$match_json" > "$FAKE_CF_DIR/body.2"
expect_watch "1 回目だけ 0 件（cloudflared を作り直した直後）" ok
cf_body '{"success":true,"result":[{"id":"c1","conns":[]}]}'
expect_watch "接続役はあるが接続が 0 のとき" down
# API の失敗・答えが読めない・このサーバーの住所が分からない・トークンが読めない
rm -f "$FAKE_CF_DIR"/body*
expect_watch "API が失敗したとき（HTTP の失敗・届かない）" unknown
cf_body '{"success":false,"errors":[{"code":10000,"message":"Authentication error"}],"result":null}'
expect_watch "API が断ったとき（success が false）" unknown
cf_body '<html>Bad gateway</html>'
expect_watch "答えが JSON でないとき" unknown
cf_body "$match_json"
FAKE_OWN4="" FAKE_OWN6=""
expect_watch "このサーバーの住所が分からないとき" unknown
FAKE_OWN4=198.51.100.7 FAKE_OWN6=2001:db8:1:2::abcd
printf 'cloudflared service install %s\n' "$watch_key" > "$EC_TUNNEL_WATCH_DIR/token"
expect_watch "読むだけの鍵の形が違うとき" unknown
printf '%s\n' "$watch_key" > "$EC_TUNNEL_WATCH_DIR/token"
mv "$EC_TUNNEL_DIR/token" "$EC_TUNNEL_DIR/token.away"
expect_watch "トンネルのトークンが読めないとき" unknown
mv "$EC_TUNNEL_DIR/token.away" "$EC_TUNNEL_DIR/token"

# 記録（state）と運営の様子へ渡す引数。Tunnel の形でなければ前の結果を消す
printf 'FRONT=tunnel\n' > "$EC_ANSWERS"
cf_body '{"success":true,"result":[{"conns":[{"origin_ip":"203.0.113.9"}]}]}'
rm -f "$FAKE_CF_DIR/calls"
out="$( (PATH="$work/t13/bin:$PATH"; ec_tunnel_watch) 2>&1)"
line="$(ec_state_get tunnel_watch || true)"
[ "${line%% *}" = foreign ] && ec_valid_time "${line#* }" && ok "見張り: 結果を「語 時刻」で state に残す" || ng "見張り: state の形が違う（${line}）"
[[ "$out" == *"トークンが漏れたかもしれません"* ]] && [[ "$out" != *203.0.113.9* ]] && ok "見張り: よそからの接続役は注意を出し、住所は出さない" || ng "見張り: 注意が違うか住所を出した（${out}）"
args="$(ec_report_state_args)"
printf '%s\n' "$args" | q -xF -- '--tunnel=foreign' && printf '%s\n' "$args" | q -xF -- "--tunnel-at=${line#* }" &&
    ok "見張りの結果を --tunnel・--tunnel-at で運営の様子へ渡す" || ng "見張りの結果を渡していない（$(printf '%s' "$args" | tr '\n' ' ')）"
ec_state_put tunnel_watch 'foreign 203.0.113.9'
[ -z "$(ec_report_state_args | grep -- '--tunnel' || true)" ] && ok "見張りの記録の形が違えば渡さない" || ng "形の違う見張りの記録を渡した"
printf 'FRONT=caddy\n' > "$EC_ANSWERS"
(PATH="$work/t13/bin:$PATH"; ec_tunnel_watch) > /dev/null 2>&1
[ ! -e "$EC_STATE/tunnel_watch" ] && ok "Tunnel の形でなければ見張りの記録を消す" || ng "Tunnel の形でないのに見張りの記録が残った"
# 鍵は引数・画面・記録に出ない（標準入力の設定にだけある）
if [ -s "$FAKE_CF_LOG" ] && ! grep -qF -- "$watch_key" "$FAKE_CF_LOG" && ! grep -qrF -- "$watch_key" "$EC_STATE" && [[ "$out" != *"$watch_key"* ]] &&
    ! grep -qF -- "$watch_key" "$work/t13/err" && ! grep -qF -- "$tok_secret" "$FAKE_CF_LOG" && grep -qF -- "$watch_key" "$FAKE_CF_DIR/config.last"; then
    ok "見張り: 鍵は curl の引数・画面・記録に出ず、設定として標準入力でだけ渡る"
else
    ng "見張り: 鍵かトンネルの秘密が引数・画面・記録に出た"
fi

# 答えの確かめ（TUNNEL_WATCH_TOKEN_FILE は任意。あれば形を見る）と置き場への写し（root だけの 0400）
mkdir -p "$work/t13/ans"
printf '%s\n' "$watch_key" > "$work/t13/ans/good"
printf 'Bearer %s\n' "$watch_key" > "$work/t13/ans/bearer"
printf 'FRONT=tunnel\nBIND=127.0.0.1\nCLOUDFLARE_TOKEN_FILE=/root/cf\nTUNNEL_TOKEN_FILE=%s\nTUNNEL_WATCH_TOKEN_FILE=%s\n' "$work/t10/good" "$work/t13/ans/good" > "$EC_ANSWERS"
expect_answers "見張りの鍵の正しい形" ""
printf 'FRONT=tunnel\nBIND=127.0.0.1\nCLOUDFLARE_TOKEN_FILE=/root/cf\nTUNNEL_TOKEN_FILE=%s\nTUNNEL_WATCH_TOKEN_FILE=%s\n' "$work/t10/good" "$work/t13/ans/bearer" > "$EC_ANSWERS"
expect_answers "見張りの鍵の形が違う形（Bearer つき）" "監視のトークンの形が違います"
out="$( (ec_tunnel_check_answers) 2>&1)" || true
[[ "$out" != *"$watch_key"* ]] && ok "見張りの鍵の形が違っても、文に中身を出さない" || ng "見張りの鍵の中身を文に出した"
printf '\r\n  %s \r\n\n' "$watch_key" > "$work/t13/ans/crlf"
ec_tunnel_watch_key_read "$work/t13/ans/crlf" && [ "$EC_TUNNEL_WATCH_KEY" = "$watch_key" ] && ok "見張りの鍵の前後の空白・CR・空の行は落として読む" || ng "見張りの鍵の前後に空白があると読めない"
EC_TUNNEL_WATCH_KEY=""
printf '%s\n%s\n' "$watch_key" "$watch_key" > "$work/t13/ans/twice"
rc=0
ec_tunnel_watch_key_read "$work/t13/ans/twice" || rc=$?
[ "$rc" -eq 1 ] && [ -z "$EC_TUNNEL_WATCH_KEY" ] && ok "見張りの鍵の行が 2 つあれば形が違うとする" || ng "見張りの鍵の行が 2 つでも読んだ（返り ${rc}）"
rm -f "$EC_TUNNEL_WATCH_DIR/token"
printf 'FRONT=tunnel\nBIND=127.0.0.1\nCLOUDFLARE_TOKEN_FILE=/root/cf\nTUNNEL_TOKEN_FILE=%s\nTUNNEL_WATCH_TOKEN_FILE=%s\n' "$work/t10/good" "$work/t13/ans/gone" > "$EC_ANSWERS"
expect_answers "見張りの鍵のファイルが無く、前に写したものも無い形" "監視のトークンのファイル"
export EC_ARGV_LOG="$work/t13/argv.log" EC_REAL_PATH="$PATH"
: > "$EC_ARGV_LOG"
rmdir "$EC_TUNNEL_WATCH_DIR"
rc=0
out="$( (PATH="$shim:$PATH"; ec_tunnel_watch_put_key "$work/t13/ans/good") 2>&1)" || rc=$?
[ "$rc" -eq 0 ] && [ "$(cat "$EC_TUNNEL_WATCH_DIR/token")" = "$watch_key" ] && [ "$(ls -l "$EC_TUNNEL_WATCH_DIR/token" | cut -c1-10)" = "-r--------" ] &&
    [ "$(ls -ld "$EC_TUNNEL_WATCH_DIR" | cut -c1-10)" = "drwx------" ] && ok "見張りの鍵を root だけの置き場（0700）へ 0400 で写す" || ng "見張りの鍵の写し方が違う（返り ${rc}: $(ls -la "$EC_TUNNEL_WATCH_DIR" 2>&1 | tr '\n' ' ')）"
grep -qxF "install -d -m 0700 -o root -g root $EC_TUNNEL_WATCH_DIR" "$EC_ARGV_LOG" && grep -qE "^chown root:root $EC_TUNNEL_WATCH_DIR/\.token\." "$EC_ARGV_LOG" &&
    ok "見張りの鍵の置き場とファイルは root の持ち物（cloudflared のグループにしない）" || ng "見張りの鍵の持ち主の決め方が違う（$(grep -E '^(chown|install)' "$EC_ARGV_LOG" | tr '\n' '|')）"
! grep -qF -- "$watch_key" "$EC_ARGV_LOG" && [[ "$out" != *"$watch_key"* ]] && [ -z "$(find "$EC_TUNNEL_WATCH_DIR" -name '.*' -type f)" ] &&
    ok "見張りの鍵を写すとき、引数・画面に出さず、仮のファイルを残さない" || ng "見張りの鍵が引数か画面に出たか、仮のファイルが残った"
rc=0
out="$( (PATH="$shim:$PATH"; ec_tunnel_watch_put_key "") 2>&1)" || rc=$?
[ "$rc" -eq 0 ] && [[ "$out" == *"前にコピーしたもの"* ]] && ok "見張りの鍵の答えが無ければ、前に写したものを使う" || ng "見張りの鍵の答えが無いと止まる（返り ${rc}）"
rm -f "$EC_TUNNEL_WATCH_DIR/token"
rc=0
out="$( (PATH="$shim:$PATH"; ec_tunnel_watch_put_key "") 2>&1)" || rc=$?
[ "$rc" -eq 0 ] && [[ "$out" == *"気づけません"* ]] && ok "見張りの鍵が無くても止めず、注意だけ出す" || ng "見張りの鍵が無いと止まるか、注意が無い（返り ${rc}）"
unset EC_ARGV_LOG EC_REAL_PATH FAKE_CF_DIR FAKE_CF_LOG FAKE_OWN4 FAKE_OWN6
EC_ANSWERS="$saved_answers13"
EC_TUNNEL_DIR="$saved_tunnel_dir13"
EC_TUNNEL_WATCH_DIR="$saved_watch_dir13"
EC_STATE="$saved_state13"
EC_TUNNEL_WATCH_RETRY_WAIT="$saved_wait13"

# ---------- 7. env の名前の移し ----------
# 権限は ls の頭の 10 文字で見る（stat の書き方は GNU と BSD で違う）
mode_of() { ls -l "$1" | cut -c1-10; }
saved_env_file="$EC_ENV_FILE"
EC_ENV_FILE="$work/mig.env"
# 前の名前で入れたサーバーの env の形: コメント・空の行・引用符・空の値・前の名前でない項目・コメントにした前の名前・最後の行に改行なし
printf '%s\n' '# 設定' 'SUGAR_EC_ENV_FILE=/srv/ec-sugar/env/.env.production' 'SUGAR_EC_IMAGE=ec-sugar:aaaa' '' \
    'SUGAR_EC_SERVER_NAMES="admin.example.com example.com"' '# SUGAR_EC_FRONT_SUBNET=172.30.255.224/28' 'SUGAR_EC_DB_SUPERUSER_PASSWORD=' \
    'APP_KEY=base64:not-a-real-key' 'SUGAR_EC_HSTS_MAX_AGE=300' > "$EC_ENV_FILE"
printf '%s' 'MAIL_FROM_NAME="${APP_NAME}"' >> "$EC_ENV_FILE"
chmod 0600 "$EC_ENV_FILE"
cp -p "$EC_ENV_FILE" "$work/mig.orig"
want="$(printf '%s\n' '# 設定' 'SUGAR_EC_ENV_FILE=/srv/ec-sugar/env/.env.production' 'EC_SUGAR_ENV_FILE=/srv/ec-sugar/env/.env.production' \
    'SUGAR_EC_IMAGE=ec-sugar:aaaa' 'EC_SUGAR_IMAGE=ec-sugar:aaaa' '' \
    'SUGAR_EC_SERVER_NAMES="admin.example.com example.com"' 'EC_SUGAR_SERVER_NAMES="admin.example.com example.com"' \
    '# SUGAR_EC_FRONT_SUBNET=172.30.255.224/28' 'SUGAR_EC_DB_SUPERUSER_PASSWORD=' 'EC_SUGAR_DB_SUPERUSER_PASSWORD=' \
    'APP_KEY=base64:not-a-real-key' 'SUGAR_EC_HSTS_MAX_AGE=300' 'EC_SUGAR_HSTS_MAX_AGE=300' 'MAIL_FROM_NAME="${APP_NAME}"')"
rc=0
ec_env_migrate || rc=$?
if [ "$rc" -eq 0 ] && [ "$(cat "$EC_ENV_FILE")" = "$want" ]; then
    ok "移し: 前の名前の行は残り、そのすぐ後ろに新しい名前の行（引用符・空の値もそのまま）。コメント・空の行・ほかの項目・並びは変えない"
else
    ng "移した後の env が違う（返り ${rc}）: $(diff <(printf '%s\n' "$want") "$EC_ENV_FILE" | tr '\n' ' ' || true)"
fi
[ "$(mode_of "$EC_ENV_FILE")" = "-rw-------" ] && ok "移し: 権限は 0600 のまま" || ng "移した後の権限が $(mode_of "$EC_ENV_FILE")"
cp -p "$EC_ENV_FILE" "$work/mig.once"
rc=0
ec_env_migrate || rc=$?
[ "$rc" -eq 0 ] && cmp -s "$EC_ENV_FILE" "$work/mig.once" && ok "移し: 2 回流しても同じ" || ng "移しを 2 回流すと変わる（返り ${rc}）"
[ -z "$(find "$work" -maxdepth 1 \( -name '.*.migrate.*' -o -name '.*.tmp.*' \))" ] && ok "移し: 仮のファイルを残さない" || ng "仮のファイルが残った"
[ "$(ec_env_get EC_SUGAR_SERVER_NAMES)" = "admin.example.com example.com" ] && ok "読み: 引用符を外した値" || ng "読みの値が違う（$(ec_env_get EC_SUGAR_SERVER_NAMES)）"
# 前のバージョンの道具が、移した後に前の名前だけへ書いた（更新の切り替え）。読みは前の名前が先、移すと新しい名前もそろう（行の数と並びは同じ）
ec_kv_set "$EC_ENV_FILE" SUGAR_EC_IMAGE ec-sugar:bbbb
lines_before="$(wc -l < "$EC_ENV_FILE")"
[ "$(ec_env_get EC_SUGAR_IMAGE)" = ec-sugar:bbbb ] && ok "読み: 前の名前の行に後から書いた値が先" || ng "読みが前の名前の値でない（$(ec_env_get EC_SUGAR_IMAGE)）"
ec_env_migrate
if [ "$(ec_kv_raw "$EC_ENV_FILE" EC_SUGAR_IMAGE)" = ec-sugar:bbbb ] && [ "$(wc -l < "$EC_ENV_FILE")" = "$lines_before" ] &&
    [ "$(sed -n 5p "$EC_ENV_FILE")" = EC_SUGAR_IMAGE=ec-sugar:bbbb ]; then
    ok "移し: 前のバージョンの道具が後から前の名前に書いた値に、新しい名前の行をその場でそろえる"
else
    ng "移しが前の名前の新しい値にそろえない（$(ec_kv_raw "$EC_ENV_FILE" EC_SUGAR_IMAGE)）"
fi
# この道具の書き込み: 前の名前の行があれば両方、無ければ新しい名前だけ（前の名前を新しく足さない）。権限は変えない
ec_env_set EC_SUGAR_IMAGE ec-sugar:cccc
ec_env_set EC_SUGAR_BIND 127.0.0.1
if [ "$(ec_kv_raw "$EC_ENV_FILE" SUGAR_EC_IMAGE)" = ec-sugar:cccc ] && [ "$(ec_kv_raw "$EC_ENV_FILE" EC_SUGAR_IMAGE)" = ec-sugar:cccc ] &&
    [ "$(ec_kv_raw "$EC_ENV_FILE" EC_SUGAR_BIND)" = 127.0.0.1 ] && ! ec_kv_raw "$EC_ENV_FILE" SUGAR_EC_BIND > /dev/null &&
    [ "$(mode_of "$EC_ENV_FILE")" = "-rw-------" ]; then
    ok "書き: 前の名前の行があれば両方に同じ値、無ければ新しい名前だけ。権限は 0600 のまま"
else
    ng "書きが両方にそろわない・前の名前を新しく足した・権限が変わった（$(mode_of "$EC_ENV_FILE")）"
fi
# 新しく入れたサーバー（見本から作る env）は新しい名前だけで、移しは何もしない
cp deploy/production.env.example "$work/new.env"
cp -p "$work/new.env" "$work/new.orig"
ec_env_migrate "$work/new.env"
if cmp -s "$work/new.env" "$work/new.orig" && ! grep -q '^SUGAR_EC_' deploy/production.env.example && grep -q '^EC_SUGAR_IMAGE=' deploy/production.env.example; then
    ok "見本（新しく入れるサーバーの env）は新しい名前だけで、移しは何も変えない"
else
    ng "見本に前の名前の行がある、または移しが新しい名前だけの env を変えた"
fi
# 書けないとき（置き場に書けない）は 1 を返し、ファイルは元のまま（root は権限で止まらないので見ない）
if [ "$(command id -u)" -ne 0 ]; then
    mkdir "$work/ro"
    cp -p "$work/mig.orig" "$work/ro/env"
    chmod 0500 "$work/ro"
    rc=0
    ec_env_migrate "$work/ro/env" 2> /dev/null || rc=$?
    chmod 0700 "$work/ro"
    [ "$rc" -eq 1 ] && cmp -s "$work/ro/env" "$work/mig.orig" && ok "移し: 書けなければ 1 を返し、ファイルは元のまま" || ng "書けないときの移しが違う（返り ${rc}）"
fi
EC_ENV_FILE="$saved_env_file"

# ---------- 8. 更新の道を本物の docker compose で解く ----------
# サーバーの順番: 前のバージョンの道具（/usr/local/bin/ec-sugar）が update を始める → 取り出した新しいバージョンの ec-sugar ensure-env（ここで移す）
# → 前の道具が「SUGAR_EC_IMAGE=<新しいイメージ> ec_dc …」で compose を解き・作り・マイグレーション → 前の道具が env の SUGAR_EC_IMAGE だけを書いて
# 切り替え・ヘルスチェック → 新しい道具を入れ、新しい道具の install-units（ここで新しい名前もそろう）。ロールバックは前の compose を、両方の名前がある env で
unset -f docker
# 使う compose を差し替える: EC_CHECK_COMPOSE に compose の単体のバイナリの道筋を渡すと、docker compose の代わりにそれを使う（例 Ubuntu の部品の
# 2.40 系。版によって入れ子の既定値の解き方が違う）。common.sh の ec_dc と前のコミットの ec_dc も向くように、compose だけを差し替える小さな docker を PATH の前に置く
if [ -n "${EC_CHECK_COMPOSE:-}" ]; then
    EC_CHECK_REAL_DOCKER="$(command -v docker)"
    export EC_CHECK_COMPOSE EC_CHECK_REAL_DOCKER
    mkdir -p "$work/compose-bin"
    printf '%s\n' '#!/bin/sh' 'if [ "${1:-}" = compose ]; then shift; exec "$EC_CHECK_COMPOSE" "$@"; fi' 'exec "$EC_CHECK_REAL_DOCKER" "$@"' > "$work/compose-bin/docker"
    chmod +x "$work/compose-bin/docker"
    PATH="$work/compose-bin:$PATH"
fi
ok "8 で使う compose: $(docker compose version --short 2> /dev/null || echo '読めない')"
old_commit=c1e1fb98c3175b9cbeb1b528dc8191c35c0700d3
saved_answers="$EC_ANSWERS"
EC_ANSWERS="$work/answers8"
printf 'MODE=staging\n' > "$EC_ANSWERS"
EC_SRC="$PWD"
old_lib=""
if git cat-file -e "$old_commit:deploy/lib/common.sh" 2> /dev/null && git cat-file -e "$old_commit:docker-compose.prod.yml" 2> /dev/null; then
    mkdir -p "$work/old"
    git show "$old_commit:deploy/lib/common.sh" > "$work/old/common.sh"
    git show "$old_commit:docker-compose.prod.yml" > "$work/old/docker-compose.prod.yml"
    old_lib="$work/old/common.sh"
fi
# 前のバージョンの道具の compose の流し方（$1 取り出したソースの置き場）。前のコミットが手元にあれば、その common.sh の ec_dc そのもの
# （読み込みは子のシェルの中だけ）。無ければ同じ形の写し
old_dc() {
    local src="$1" env="$EC_ENV_FILE" answers="$EC_ANSWERS"
    shift
    if [ -n "$old_lib" ]; then
        (
            # shellcheck disable=SC1090
            . "$old_lib"
            EC_SRC="$src" EC_ENV_FILE="$env" EC_ANSWERS="$answers"
            ec_dc "$@"
        )
    elif [ "$(ec_front)" = proxy ]; then
        docker compose -p ec-sugar --project-directory "$src" -f "$src/docker-compose.prod.yml" -f "$src/docker-compose.proxy.yml" --env-file "$env" --profile staging "$@"
    else
        docker compose -p ec-sugar --project-directory "$src" -f "$src/docker-compose.prod.yml" --env-file "$env" --profile staging "$@"
    fi
}
# 解いた結果の app・queue・scheduler のイメージと web が借りる public/ のイメージ（同じなら 1 つ）
images_of() { jq -r '[.services.app.image, .services.queue.image, .services.scheduler.image, (.services.web.volumes[] | select(.type == "image") | .source)] | unique | join(",")' "$1"; }
# 前の名前で入れたサーバーの env（見本を前の名前に戻し、仮の値を入れたもの。値は本物ではない）
EC_ENV_FILE="$work/up.env"
sed -e 's/^EC_SUGAR_/SUGAR_EC_/' deploy/production.env.example > "$EC_ENV_FILE"
chmod 0600 "$EC_ENV_FILE"
ec_kv_set "$EC_ENV_FILE" SUGAR_EC_ENV_FILE "$EC_ENV_FILE"
ec_kv_set "$EC_ENV_FILE" SUGAR_EC_IMAGE ec-sugar:old
ec_kv_set "$EC_ENV_FILE" SUGAR_EC_DB_SUPERUSER_PASSWORD check-only-superuser
ec_kv_set "$EC_ENV_FILE" DB_PASSWORD check-only
ec_kv_set "$EC_ENV_FILE" REDIS_PASSWORD check-only
admin_host="$(ec_kv_get "$EC_ENV_FILE" SUGAR_EC_ADMIN_HOST)"
# $1 名前 $2 解いた結果 $3 期待するイメージ
expect_images() {
    local got
    got="$(images_of "$2" 2> /dev/null || true)"
    [ "$got" = "$3" ] && ok "$1: app・queue・scheduler・web のイメージが $3" || ng "$1: イメージが ${got:-（解けない）}（$3 のはず）"
}
# 前のバージョンの道具の確かめ（ec_has_caddy と同じ: config --services に caddy がある）
# （出力を受け取ってから探す。grep -q で管を先に閉じると、compose が書き込みで止められ pipefail で失敗になることがある）
old_has_caddy() {
    local services
    services="$(SUGAR_EC_IMAGE="$1" old_dc "$PWD" config --services 2> /dev/null)" || return 1
    printf '%s\n' "$services" | q -x caddy
}

# A. 移す前（前の名前だけの env）でも、新しい compose は前の道具の環境変数のイメージで解ける
if SUGAR_EC_IMAGE=ec-sugar:new old_dc "$PWD" config --format json > "$work/a.json" 2> "$work/err8"; then
    expect_images "移す前・前の道具の環境変数" "$work/a.json" ec-sugar:new
    [ "$(jq -r '.services.caddy.environment.EC_SUGAR_ADMIN_HOST' "$work/a.json")" = "$admin_host" ] && ok "移す前: 入口の Caddy に前の名前の値が新しい名前で渡る" || ng "移す前: 入口の Caddy の EC_SUGAR_ADMIN_HOST が違う"
else
    ng "移す前（前の名前だけの env）の新しい compose が解けない: $(head -3 "$work/err8" | tr '\n' ' ')"
fi
# B. 取り出した新しい道具の ensure-env で移した後（新しい名前の行は今のイメージ ec-sugar:old のまま）。前の道具は環境変数の前の名前で新しいイメージを渡す
ec_env_migrate
[ "$(ec_kv_raw "$EC_ENV_FILE" EC_SUGAR_IMAGE)" = ec-sugar:old ] || ng "移した後の EC_SUGAR_IMAGE が今のイメージでない"
old_has_caddy ec-sugar:new && ok "移した後: 前の道具の ec_has_caddy が新しい compose に入口（caddy）を見つける" || ng "移した後: 前の道具が新しい compose を解けない・caddy を見つけない"
if SUGAR_EC_IMAGE=ec-sugar:new old_dc "$PWD" config --format json > "$work/b.json" 2> "$work/err8"; then
    expect_images "移した後・前の道具の環境変数（マイグレーションと入口のイメージ）" "$work/b.json" ec-sugar:new
    leaked="$(jq -c '.services | {app, queue, scheduler, web, redis, caddy}' "$work/b.json" | grep -c 'check-only-superuser' || true)"
    [ "$leaked" = 0 ] && [ "$(jq -r '.services.app.environment | [.EC_SUGAR_DB_SUPERUSER_PASSWORD, .SUGAR_EC_DB_SUPERUSER_PASSWORD] | join(",")' "$work/b.json")" = "," ] &&
        ok "移した後: DB の管理者のパスワードは前の名前でも新しい名前でも db にだけ渡る" || ng "移した後: DB の管理者のパスワードが db 以外に渡る"
else
    ng "移した後の新しい compose が前の道具で解けない: $(head -3 "$work/err8" | tr '\n' ' ')"
fi
# C. 前の道具が切り替えで env の前の名前だけを書く（新しい名前の行は ec-sugar:old のまま）
ec_kv_set "$EC_ENV_FILE" SUGAR_EC_IMAGE ec-sugar:new
if old_dc "$PWD" config --format json > "$work/c.json" 2> "$work/err8"; then
    expect_images "切り替え・前の道具が前の名前だけに書いた" "$work/c.json" ec-sugar:new
else
    ng "切り替えの compose が前の道具で解けない: $(head -3 "$work/err8" | tr '\n' ' ')"
fi
# C2. すでにある Web サーバーの後ろに置く形（docker-compose.proxy.yml を重ねる）: 前の名前だけにあるソケットの置き場のグループが効く
printf 'MODE=staging\nFRONT=proxy\n' > "$EC_ANSWERS"
cp -p "$EC_ENV_FILE" "$work/up.before-proxy"
ec_kv_set "$EC_ENV_FILE" SUGAR_EC_FRONT_SOCKET_GID 990
for tool in old_dc ec_dc; do
    if [ "$tool" = old_dc ]; then old_dc "$PWD" config --format json > "$work/p.json" 2> "$work/err8" || true; else ec_dc config --format json > "$work/p.json" 2> "$work/err8" || true; fi
    if [ "$(jq -r '[((.services.web.group_add // []) | map(tostring) | index("990") != null), ((.services.caddy.scale // 1) == 0)] | all' "$work/p.json" 2> /dev/null)" = true ]; then
        ok "後ろに置く形（${tool}）: 前の名前だけにあるソケットの置き場のグループが web に効き、caddy は 0 個"
    else
        ng "後ろに置く形（${tool}）で、前の名前のソケットの置き場のグループが効かない: $(jq -c '{group_add: .services.web.group_add, scale: .services.caddy.scale}' "$work/p.json" 2> /dev/null || true) $(head -3 "$work/err8" | tr '\n' ' ')"
    fi
done
cp -p "$work/up.before-proxy" "$EC_ENV_FILE"
printf 'MODE=staging\n' > "$EC_ANSWERS"
# C3. Cloudflare Tunnel の形（docker-compose.tunnel.yml を重ねる。ADR 0052）: 置き場（EC_SUGAR_TUNNEL_DIR）が env に無ければ ec_dc は名前を出して止め、
#     あれば cloudflared が置き場を読み取り専用で差し込む。その形を知らない前のバージョン（ファイルが無い）には重ねない
printf 'MODE=staging\nFRONT=tunnel\n' > "$EC_ANSWERS"
cp -p "$EC_ENV_FILE" "$work/up.before-tunnel"
rc=0
ec_dc config --format json > /dev/null 2> "$work/err8" || rc=$?
[ "$rc" -ne 0 ] && grep -q 'EC_SUGAR_TUNNEL_DIR（cloudflared の設定とトークンの保存先） がありません' "$work/err8" &&
    ok "Tunnel の形: 置き場（EC_SUGAR_TUNNEL_DIR）が env に無ければ ec_dc は名前を出して止める" || ng "Tunnel の形: 置き場が無いのに ec_dc が止まらない（返り ${rc}: $(head -2 "$work/err8" | tr '\n' ' ')）"
ec_env_set EC_SUGAR_TUNNEL_DIR /srv/ec-sugar/secrets/tunnel
ec_dc config --format json > "$work/t.json" 2> "$work/err8" || true
if [ "$(jq -r '[(.services.cloudflared.volumes // [])[] | select(.source == "/srv/ec-sugar/secrets/tunnel" and .target == "/etc/cloudflared" and .read_only == true)] | length' "$work/t.json" 2> /dev/null)" = 1 ] &&
    [ "$(jq -r '.services.caddy.environment.EC_SUGAR_FRONT' "$work/t.json")" = tunnel ]; then
    ok "Tunnel の形（ec_dc）: 重ね書きが効き、cloudflared が置き場を読み取り専用で差し込み、Caddy は tunnel の形"
else
    ng "Tunnel の形（ec_dc）で重ね書きが効かない: $(head -3 "$work/err8" | tr '\n' ' ')"
fi
if [ -n "$old_lib" ]; then
    EC_SRC="$work/old"
    ec_dc config --services > "$work/t-old.txt" 2> "$work/err8" || true
    EC_SRC="$PWD"
    grep -qx caddy "$work/t-old.txt" && ! grep -qx cloudflared "$work/t-old.txt" && ok "Tunnel の形: その形を知らない前のバージョン（重ね書きのファイルが無い）には重ねない" ||
        ng "Tunnel の形: 前のバージョンの compose を解けない・cloudflared が出る（$(head -2 "$work/err8" | tr '\n' ' ')）"
fi
cp -p "$work/up.before-tunnel" "$EC_ENV_FILE"
printf 'MODE=staging\n' > "$EC_ANSWERS"
# D. 新しい道具の install-units で移すと、新しい名前もそろう。次の更新は新しい道具が両方の名前で渡す・書く
ec_env_migrate
[ "$(ec_kv_raw "$EC_ENV_FILE" EC_SUGAR_IMAGE)" = ec-sugar:new ] && ok "更新の後: 新しい道具の移しで EC_SUGAR_IMAGE も新しいイメージにそろう" || ng "更新の後に EC_SUGAR_IMAGE がそろわない"
ec_with_image ec-sugar:newer ec_dc config --format json > "$work/d.json" 2> "$work/err8" || true
expect_images "次の更新・新しい道具の環境変数" "$work/d.json" ec-sugar:newer
ec_env_set EC_SUGAR_IMAGE ec-sugar:newer
ec_dc config --format json > "$work/d2.json" 2> "$work/err8" || true
expect_images "次の更新・新しい道具が env に書いた" "$work/d2.json" ec-sugar:newer
# E. ロールバック: 新しい道具が前のバージョンへ（前の compose。env には両方の名前、新しい道具は両方に書く）
if [ -n "$old_lib" ]; then
    ec_env_set EC_SUGAR_IMAGE ec-sugar:old
    EC_SRC="$work/old"
    ec_dc config --format json > "$work/e.json" 2> "$work/err8" || true
    expect_images "ロールバック・前の compose を新しい道具が両方の名前の env で" "$work/e.json" ec-sugar:old
    ec_with_image ec-sugar:x ec_dc config --format json > "$work/e2.json" 2> "$work/err8" || true
    expect_images "ロールバック・前の compose に新しい道具が環境変数で渡す" "$work/e2.json" ec-sugar:x
    old_dc "$work/old" config --format json > "$work/e3.json" 2> "$work/err8" || true
    expect_images "ロールバック・前の compose を前の道具の流し方で" "$work/e3.json" ec-sugar:old
    EC_SRC="$PWD"
else
    ok "前のバージョンの compose のロールバックの確かめは見送り（前のコミット ${old_commit:0:12} が手元に無い。浅い取得）"
fi
# F. 新しく入れたサーバー（新しい名前だけの env）
EC_ENV_FILE="$work/fresh.env"
cp deploy/production.env.example "$EC_ENV_FILE"
chmod 0600 "$EC_ENV_FILE"
ec_kv_set "$EC_ENV_FILE" EC_SUGAR_ENV_FILE "$EC_ENV_FILE"
ec_kv_set "$EC_ENV_FILE" EC_SUGAR_IMAGE ec-sugar:fresh
ec_kv_set "$EC_ENV_FILE" EC_SUGAR_DB_SUPERUSER_PASSWORD check-only-superuser
ec_kv_set "$EC_ENV_FILE" DB_PASSWORD check-only
ec_kv_set "$EC_ENV_FILE" REDIS_PASSWORD check-only
ec_dc config --format json > "$work/f.json" 2> "$work/err8" || true
expect_images "新しく入れたサーバー（新しい名前だけ）" "$work/f.json" ec-sugar:fresh
ec_with_image ec-sugar:fresh2 ec_dc config --format json > "$work/f2.json" 2> "$work/err8" || true
expect_images "新しく入れたサーバー・環境変数で渡す" "$work/f2.json" ec-sugar:fresh2
# F2. 外への通信（ADR 0102）: 既定は見張りの形（アプリの網は今までどおり・HTTPS_PROXY は代理）。止める形（EC_SUGAR_EGRESS_MODE=enforce）は
#     ec_dc が docker-compose.egress.yml を重ね、MAIL_HOST が名前なら代理の住所へ向ける。Mailpit・住所（IP）の MAIL_HOST は向けず、住所の MAIL_HOST では
#     止める形へ切り替えられない（メールが黙って止まる）。代理を使わない形（off）はアプリの HTTPS_PROXY が空。知らない値は見張りの形
cp -p "$EC_ENV_FILE" "$work/fresh.before-egress"
[ "$(jq -r '[.services.app.environment.HTTPS_PROXY, (.services.app.networks | keys | sort | join(","))] | join(" ")' "$work/f.json" 2> /dev/null)" = "http://egress:3128 edge,egress_in,internal" ] &&
    ok "外への通信: 既定は見張りの形（アプリは代理を通り、網は今までどおり）" || ng "外への通信: 既定の形でアプリの HTTPS_PROXY か網が違う"
ec_env_set EC_SUGAR_EGRESS_MODE enforce
ec_env_set MAIL_HOST smtp.example.jp
ec_dc config --format json > "$work/eg1.json" 2> "$work/err8" || true
if [ "$(jq -r '[.services.app, .services.queue, .services.scheduler | (.networks | keys | sort | join(","))] | unique | join(" ")' "$work/eg1.json" 2> /dev/null)" = "egress_in,internal" ] &&
    jq -r '.services.queue.extra_hosts[0] // ""' "$work/eg1.json" | q -E '^smtp\.example\.jp[:=]172\.30\.255\.194$' && [ -z "$(ec_egress_enforce_problem)" ]; then
    ok "外への通信: 止める形は ec_dc が重ね、アプリは内側の網だけ・MAIL_HOST（名前）は代理の住所へ"
else
    ng "外への通信: 止める形で ec_dc の重ね・アプリの網・MAIL_HOST の向きが違う（$(jq -c '{n: (.services.app.networks | keys), x: .services.queue.extra_hosts}' "$work/eg1.json" 2> /dev/null || head -2 "$work/err8" | tr '\n' ' ')）"
fi
ec_env_set MAIL_HOST 192.0.2.25
ec_dc config --format json > "$work/eg2.json" 2> "$work/err8" || true
jq -r '.services.app.extra_hosts[0] // ""' "$work/eg2.json" | q -E '^smtp-relay\.invalid[:=]' && [ -n "$(ec_egress_enforce_problem)" ] &&
    ok "外への通信: MAIL_HOST が住所なら代理へ向けず、止める形へは切り替えられない（理由を出す）" || ng "外への通信: 住所の MAIL_HOST を代理へ向けた・止める形を断らない"
ec_env_set MAIL_HOST mailpit
[ -z "$(ec_egress_enforce_problem)" ] && [ "$(ec_egress_smtp_name)" = smtp-relay.invalid ] &&
    ok "外への通信: ステージングの Mailpit は代理へ向けず（内側の網で直に届く）、止める形にできる" || ng "外への通信: Mailpit の MAIL_HOST の扱いが違う"
ec_env_set EC_SUGAR_EGRESS_MODE off
ec_dc config --format json > "$work/eg3.json" 2> "$work/err8" || true
[ "$(jq -r '[.services.app, .services.queue, .services.scheduler | .environment.HTTPS_PROXY // ""] | unique | join(",")' "$work/eg3.json" 2> /dev/null)" = "" ] &&
    [ "$(jq -r '.services.app.networks | keys | sort | join(",")' "$work/eg3.json")" = "edge,egress_in,internal" ] &&
    ok "外への通信: 代理を使わない形（off）はアプリの HTTPS_PROXY が空" || ng "外への通信: off でアプリの HTTPS_PROXY が空にならない"
ec_env_set EC_SUGAR_EGRESS_MODE Enforce-typo
[ "$(ec_egress_mode)" = watch ] && ok "外への通信: 知らない形の値は見張りの形（止めない方）" || ng "外への通信: 知らない形の値が $(ec_egress_mode) になる"
# 代理と同じ読み方（大文字・引用符・空白）で、代理だけが止める形になってアプリの網が開いたまま、を作らない
mode_ng=0
for v in ENFORCE '"enforce"' "'enforce'" ' enforce '; do
    ec_env_set EC_SUGAR_EGRESS_MODE "$v"
    [ "$(ec_egress_mode)" = enforce ] || { ng "外への通信: 形の値 [${v}] が enforce と読めない（$(ec_egress_mode)）"; mode_ng=1; }
done
[ "$mode_ng" -eq 1 ] || ok "外への通信: 形の値は大文字・引用符・空白があっても代理と同じに読む"
cp -p "$work/fresh.before-egress" "$EC_ENV_FILE"
# F3. 出口の代理の見張り（ec_watch から。止める形では代理が止まるとメールも止まるので、ホストから直す）: unhealthy・止まっていれば作り直して記録に残す。
#     healthy・起きている途中なら何もしない。代理の無いバージョンでは何もしない
# ng はサブシェルの中で数えても外に残らないので、NG があればサブシェルを 1 で終え、外で 1 つ数える
(
    before=$fails
    egress_calls="$work/egress-calls"
    : > "$egress_calls"
    FAKE_EGRESS_STATE="running unhealthy"
    ec_dc() {
        printf '%s\n' "$*" >> "$egress_calls"
        case "$*" in
            "config --services") printf 'app\negress\n' ;;
            "ps -a --format {{.State}} {{.Health}} egress") printf '%s\n' "$FAKE_EGRESS_STATE" ;;
        esac
        return 0
    }
    EC_STATE="$work/egress-state"
    mkdir -p "$EC_STATE"
    ec_egress_watch 2> /dev/null
    grep -qx 'up -d --no-deps --force-recreate egress' "$egress_calls" && grep -q 'egress_restart' "$EC_STATE/history.log" &&
        ok "代理の見張り: unhealthy なら作り直してホストの記録に残す" || ng "代理の見張り: unhealthy の代理を作り直さない・記録に残さない"
    : > "$egress_calls"
    FAKE_EGRESS_STATE="exited "
    ec_egress_watch 2> /dev/null
    grep -qx 'up -d --no-deps --force-recreate egress' "$egress_calls" && ok "代理の見張り: 止まっていれば作り直す" || ng "代理の見張り: 止まった代理を作り直さない"
    : > "$egress_calls"
    FAKE_EGRESS_STATE="running healthy"
    ec_egress_watch 2> /dev/null
    ! grep -q 'force-recreate' "$egress_calls" && ok "代理の見張り: healthy なら何もしない" || ng "代理の見張り: healthy の代理を作り直した"
    ec_dc() { printf '%s\n' "$*" >> "$egress_calls"; [ "$*" = "config --services" ] && printf 'app\n'; return 0; }
    : > "$egress_calls"
    ec_egress_watch 2> /dev/null
    ! grep -q 'force-recreate' "$egress_calls" && ok "代理の見張り: 代理の無いバージョンでは何もしない" || ng "代理の見張り: 代理の無いバージョンで作り直そうとした"
    [ "$fails" -eq "$before" ]
) || fails=$((fails + 1))
# G. 必須の項目が無いとき: compose の入れ子の内側に :? を置けない（2.40 系）ので、ec_dc が compose を呼ぶ前に、足りない名前と説明を出して止める（値は出さない）。
#    前の名前の行に値があれば止めない
grep -v '^EC_SUGAR_ADMIN_HOST=' "$work/fresh.env" > "$work/missing.env"
EC_ENV_FILE="$work/missing.env"
rc=0
ec_dc config --format json > /dev/null 2> "$work/err8" || rc=$?
if [ "$rc" -ne 0 ] && grep -q 'EC_SUGAR_ADMIN_HOST（管理画面のホスト） がありません' "$work/err8" && ! grep -q 'check-only' "$work/err8"; then
    ok "必須の項目（EC_SUGAR_ADMIN_HOST）が無ければ、ec_dc は名前と説明を出して止める（値は出さない）"
else
    ng "必須の項目が無いときに ec_dc が止まらない・名前を出さない・値を出した（返り ${rc}）: $(head -2 "$work/err8" | tr '\n' ' ')"
fi
printf 'SUGAR_EC_ADMIN_HOST=admin.example.com\n' >> "$work/missing.env"
ec_dc config --format json > "$work/g.json" 2> "$work/err8" || true
expect_images "必須の項目が前の名前の行だけにある" "$work/g.json" ec-sugar:fresh
# H. 公開版の形（CHANNEL=release）: compose の project directory は root だけの置き場（確かめたコミットから取り出したもの）で、
#    compose が差し込むファイル（入口の Caddy の設定・nginx の設定・DB の初めのスクリプト）は、作業場所でなくその置き場から差し込まれる。
#    置き場の取り出しそのもの（git の中身から・リンクを断る・ロールバック）は docker/check-release.sh の 9 で見る。ここは本物の compose の解き方
EC_ENV_FILE="$work/fresh.env"
saved_state="$EC_STATE"
saved_compose_root="$EC_COMPOSE_ROOT"
EC_STATE="$work/state8"
EC_COMPOSE_ROOT="$work/compose8"
h_commit=0123456789abcdef0123456789abcdef01234567
h_tree="$EC_COMPOSE_ROOT/$h_commit"
mkdir -p "$EC_STATE" "$h_tree"
cp -R docker docker-compose.prod.yml docker-compose.proxy.yml docker-compose.tunnel.yml docker-compose.egress.yml docker-compose.isolated.yml "$h_tree/"
ec_state_put compose_commit "$h_commit"
printf 'MODE=staging\nCHANNEL=release\n' > "$EC_ANSWERS"
ec_dc config --format json > "$work/h.json" 2> "$work/err8" || true
# ホストの root が作る置き場（/srv/ の秘密・/var/lib/ec-sugar/ の中の照らしの様子〔〕）は木のファイルでないので数えない
h_binds="$(jq -r '[.services[] | (.volumes // [])[] | select(.type == "bind") | .source] | map(select((startswith("/srv/") or startswith("/var/lib/ec-sugar/")) | not)) | .[]' "$work/h.json" 2> /dev/null || true)"
h_outside="$(printf '%s\n' "$h_binds" | grep -v "^$h_tree/" | grep -v '^$' || true)"
if [ -n "$h_binds" ] && [ -z "$h_outside" ] && printf '%s\n' "$h_binds" | q -x "$h_tree/docker/caddy/etc" &&
    printf '%s\n' "$h_binds" | q -x "$h_tree/docker/nginx/prod.conf" && printf '%s\n' "$h_binds" | q -x "$h_tree/docker/db/init"; then
    ok "公開版の形（ec_dc）: compose が差し込む入口の Caddy の設定・nginx の設定・DB の初めのスクリプトは、作業場所でなく root だけの置き場から"
else
    ng "公開版の形で、compose が差し込むファイルが root だけの置き場の外から来る: ${h_outside:-（解けない: $(head -2 "$work/err8" | tr '\n' ' ')）}"
fi
printf 'MODE=staging\n' > "$EC_ANSWERS"
ec_dc config --format json > "$work/h2.json" 2> "$work/err8" || true
[ "$(jq -r '[.services.caddy.volumes[] | select(.target == "/etc/caddy")][0].source' "$work/h2.json" 2> /dev/null)" = "$PWD/docker/caddy/etc" ] &&
    ok "開発の形（CHANNEL=source）は今までどおり作業場所から差し込む" || ng "開発の形の差し込む元が作業場所でない"
EC_STATE="$saved_state"
EC_COMPOSE_ROOT="$saved_compose_root"
EC_ENV_FILE="$saved_env_file"
EC_ANSWERS="$saved_answers"

# ---------- 6. サーバーの見張りの報告 ----------
# 証明書の期限（ホスト名ごと）・空き容量・最後の更新・最後のバックアップを、決まった形の引数だけで app の operations:host-report へ渡す。
# 一言（message）・env の秘密は渡さない。app が動いていない・断られたときは 1 を返し、呼ぶ側（更新・バックアップ）を止めない
mkdir -p "$work/bin" "$work/state"
# 偽の openssl: s_client は dark.example.com だけ届かない。x509 は入力があれば決まった期限を答える
cat > "$work/bin/openssl" << 'EOF'
#!/bin/sh
case "$1" in
    s_client) cat > /dev/null; case "$*" in *dark.example.com*) exit 1 ;; esac; echo CERT ;;
    x509) [ -n "$(cat)" ] || exit 1; echo 'notAfter=Dec  1 00:00:00 2026 GMT' ;;
esac
EOF
# 偽の timeout（手元の Mac には無い）。秒数を外してそのまま流す
printf '#!/bin/sh\nshift\nexec "$@"\n' > "$work/bin/timeout"
chmod +x "$work/bin/openssl" "$work/bin/timeout"
PATH="$work/bin:$PATH"
EC_STATE="$work/state"
EC_ROOT="$work"
EC_BACKUP="$work/backup"
printf 'SUGAR_EC_ADMIN_HOST=admin.example.com\nSUGAR_EC_BIND=127.0.0.1\nAPP_KEY=base64:secret-should-not-pass\n' > "$EC_ENV_FILE"
printf 'SHOPS=single\nSHOP_HOST=dark.example.com\n' > "$EC_ANSWERS"
printf 'time=2026-10-31T03:31:02+09:00\nresult=failed\nfrom=%s\nto=%s\nmessage=前のバージョンへロールバックしても通らなかった\n' "$(printf 'a%.0s' $(seq 40))" "0123456789abcdef0123456789abcdef01234567" > "$EC_STATE/last_update"
printf 'time=2026-10-31T04:15:01+09:00\nresult=ok\n' > "$EC_STATE/last_backup"
# 偽の docker: Docker の置き場はこの一時の置き場（空き容量を読む所）
docker() { [ "$1" = info ] && { printf '%s\n' "$work"; return 0; }; return 1; }
# 偽の compose: ps は FAKE_APP が 1 なら app が動いている。exec は引数を書き残し、FAKE_REPORT_RC で終わる
FAKE_APP=1
FAKE_REPORT_RC=0
ec_dc() {
    case "$1" in
        ps) [ "$FAKE_APP" = 1 ] && printf 'app\n'; return 0 ;;
        exec) printf '%s\n' "$@" > "$work/report.args"; return "$FAKE_REPORT_RC" ;;
    esac
    return 1
}
if ec_host_report 2> /dev/null; then ok "報告を渡せたら 0"; else ng "報告を渡せたのに 0 でない"; fi
args="$(cat "$work/report.args" 2> /dev/null || true)"
want_lines="exec -T app php artisan operations:host-report --cert=dark.example.com,unreadable --update-result=failed --update-at=2026-10-31T03:31:02+09:00
--update-version=0123456789abcdef0123456789abcdef01234567 --backup-result=ok --backup-at=2026-10-31T04:15:01+09:00"
for want in $want_lines; do
    printf '%s\n' "$args" | q -xF -- "$want" && ok "報告に ${want} がある" || ng "報告に ${want} が無い（$(printf '%s' "$args" | tr '\n' ' ')）"
done
# 期限の読み方は GNU の date（サーバーと CI）。手元の Mac の date では読めないので「読めない」になる
if date -u -d '@0' > /dev/null 2>&1; then
    printf '%s\n' "$args" | q -xF -- '--cert=admin.example.com,2026-12-01T00:00:00Z' && ok "証明書の期限を UTC の時刻で渡す" || ng "証明書の期限が無い（$(printf '%s' "$args" | tr '\n' ' ')）"
fi
printf '%s\n' "$args" | q -E -- '^--disk=data,([0-9]|[1-9][0-9]|100),[0-9]+$' && ok "空き容量を「data,割合,バイト数」で渡す" || ng "空き容量の形が違う（$(printf '%s' "$args" | tr '\n' ' ')）"
[[ "$args" != *secret-should-not-pass* ]] && [[ "$args" != *ロールバックしても* ]] && [[ "$args" != *CERT* ]] && ok "秘密・一言・証明書の中身は渡さない" || ng "渡してはいけないものを渡した"
FAKE_APP=0
if ec_host_report 2> /dev/null; then ng "app が動いていないのに 0"; else ok "app が動いていなければ 1（呼ぶ側は止めない）"; fi
FAKE_APP=1
FAKE_REPORT_RC=1
if ec_host_report 2> /dev/null; then ng "断られたのに 0"; else ok "app に断られたら 1"; fi


# ---------- 9. 定期の処理の見張り ----------
# app の中で知らせの係（operations:alert）だけを直に走らせる。app が動いていない・失敗したときは 1（unit の失敗として残る）
rm -f "$work/report.args"
FAKE_APP=1
FAKE_REPORT_RC=0
if ec_watch 2> /dev/null; then ok "見張りが知らせの係を走らせたら 0"; else ng "見張りが 0 でない"; fi
[ "$(cat "$work/report.args" 2> /dev/null | tr '\n' ' ')" = "exec -T app php artisan operations:alert " ] && ok "見張りが走らせるのは operations:alert だけ" || ng "見張りの引数が違う（$(cat "$work/report.args" 2> /dev/null | tr '\n' ' ')）"
FAKE_APP=0
rm -f "$work/report.args"
if ec_watch 2> /dev/null; then ng "app が動いていないのに見張りが 0"; else ok "app が動いていなければ見張りは 1"; fi
[ ! -e "$work/report.args" ] && ok "app が動いていなければ何も走らせない" || ng "app が動いていないのに走らせた"
FAKE_APP=1
FAKE_REPORT_RC=1
if ec_watch 2> /dev/null; then ng "知らせの係が失敗したのに見張りが 0"; else ok "知らせの係が失敗したら見張りは 1"; fi
# Cloudflare Tunnel の形: トンネルを確かめ、サーバーの見張りの報告（--tunnel つき）を渡してから知らせの係を走らせる（見直しの中 5）
saved_answers9="$EC_ANSWERS"
saved_watch_dir9="$EC_TUNNEL_WATCH_DIR"
EC_ANSWERS="$work/answers9"
EC_TUNNEL_WATCH_DIR="$work/t9-watch-none"
printf 'FRONT=tunnel\nSHOPS=single\nSHOP_HOST=dark.example.com\n' > "$EC_ANSWERS"
ec_dc() {
    case "$1" in
        ps) printf 'app\n' ;;
        exec) printf '%s\n' "$*" >> "$work/watch9.log" ;;
    esac
    return 0
}
rm -f "$work/watch9.log"
ec_watch > /dev/null 2>&1 && ok "Tunnel の形の見張りは 0" || ng "Tunnel の形の見張りが 0 でない"
[ "$(wc -l < "$work/watch9.log" | tr -d ' ')" = 2 ] && head -n1 "$work/watch9.log" | q 'operations:host-report .*--tunnel=unwatched --tunnel-at=' &&
    tail -n1 "$work/watch9.log" | q -x 'exec -T app php artisan operations:alert' &&
    ok "Tunnel の形の見張りは、--tunnel つきの報告を渡してから知らせの係を走らせる（鍵が無ければ unwatched）" || ng "Tunnel の形の見張りの順番か引数が違う（$(tr '\n' '|' < "$work/watch9.log" 2> /dev/null)）"
# トンネルの見張りを知らない前のバージョンのアプリ（ロールバックの後）は --tunnel を断る。見張りの値を外して渡し直す
ec_dc() {
    case "$1" in
        ps) printf 'app\n' ;;
        exec) printf '%s\n' "$*" >> "$work/watch9.log"; case "$*" in *--tunnel*) return 1 ;; esac ;;
    esac
    return 0
}
rm -f "$work/watch9.log"
ec_host_report > /dev/null 2>&1 && ok "前のバージョンのアプリに --tunnel を断られても、見張りの値を外して渡せたら 0" || ng "前のバージョンのアプリに断られたまま"
[ "$(wc -l < "$work/watch9.log" | tr -d ' ')" = 2 ] && tail -n1 "$work/watch9.log" | q 'operations:host-report .*--backup-result=' && ! tail -n1 "$work/watch9.log" | q -- '--tunnel' &&
    ok "渡し直しは --tunnel・--tunnel-at だけを外す" || ng "渡し直しの引数が違う（$(tr '\n' '|' < "$work/watch9.log" 2> /dev/null)）"
EC_ANSWERS="$saved_answers9"
EC_TUNNEL_WATCH_DIR="$saved_watch_dir9"
rm -f "$EC_STATE/tunnel_watch"
# 入れる unit の一覧に見張りがある（タイマーが無いと、定期の処理が止まった時に知らせられない）
grep -q 'ec-sugar-watch.service ec-sugar-watch.timer' "$lib" && grep -q 'enable --now ec-sugar-watch.timer' "$lib" && ok "見張りの unit を入れて有効にする" || ng "見張りの unit を入れていない"
grep -q '^OnCalendar=\*:0/10$' "$(dirname "$lib")/../systemd/ec-sugar-watch.timer" && ok "見張りは 10 分ごと" || ng "見張りの間隔が違う"


# ---------- 11. 組み立ての控えの片付け ----------
# 1 週間より前を消し、全体を上限までに収める。新しい書き方（--max-used-space）を知らない Docker では前の書き方（--keep-storage）へ。失敗しても 0
docker() { printf '%s\n' "$*" >> "$work/prune.log"; case "$*" in *--max-used-space*) return "${FAKE_NEW_PRUNE_RC:-0}" ;; esac; return 0; }
rm -f "$work/prune.log"
FAKE_NEW_PRUNE_RC=0
ec_prune_build_cache && ok "片付けは 0 を返す" || ng "片付けが 0 でない"
[ "$(cat "$work/prune.log")" = "builder prune -f --filter until=168h
builder prune -f --max-used-space 4gb" ] && ok "1 週間より前を消し、全体を 4gb までに収める" || ng "片付けの呼び方が違う（$(tr '\n' '|' < "$work/prune.log")）"
rm -f "$work/prune.log"
FAKE_NEW_PRUNE_RC=1
ec_prune_build_cache && ok "新しい書き方を知らない Docker でも 0" || ng "新しい書き方を知らない Docker で 0 でない"
grep -qx 'builder prune -f --keep-storage 4gb' "$work/prune.log" && ok "新しい書き方を知らない Docker では --keep-storage に切り替える" || ng "前の書き方に切り替えていない（$(tr '\n' '|' < "$work/prune.log")）"
grep -q 'ec_prune_build_cache' deploy/ec-sugar && ok "更新の後の片付けが組み立ての控えも片付ける" || ng "ec-sugar の片付けが ec_prune_build_cache を呼んでいない"
unset -f docker

# ---------- 12. 管理画面を開ける接続元を替える ----------
# 形を確かめてから答えと env に書き、app を作り直す。形が違えば何も書かない
saved_answers12="$EC_ANSWERS"
EC_ANSWERS="$work/answers12"
printf 'ADMIN_ALLOWED_IPS=192.0.2.1/32\n' > "$EC_ANSWERS"
printf 'ADMIN_ALLOWED_IPS=192.0.2.1/32\nAPP_KEY=base64:keep\n' > "$EC_ENV_FILE"
ec_dc() { printf '%s\n' "$*" > "$work/dc12.args"; return 0; }
rm -f "$work/dc12.args"
if ec_set_admin_ips '198.51.100.7/32,2001:db8:1:2::/64' 2> /dev/null; then ok "正しい形なら 0"; else ng "正しい形なのに 0 でない"; fi
[ "$(ec_kv_get "$EC_ANSWERS" ADMIN_ALLOWED_IPS)" = '198.51.100.7/32,2001:db8:1:2::/64' ] && ok "答えに書く" || ng "答えに書いていない"
[ "$(ec_kv_get "$EC_ENV_FILE" ADMIN_ALLOWED_IPS)" = '198.51.100.7/32,2001:db8:1:2::/64' ] && grep -qx 'APP_KEY=base64:keep' "$EC_ENV_FILE" && ok "env に書き、ほかの行は変えない" || ng "env の書き方が違う"
[ "$(cat "$work/dc12.args" 2> /dev/null)" = "up -d --no-deps --force-recreate app" ] && ok "app だけを作り直す" || ng "作り直し方が違う（$(cat "$work/dc12.args" 2> /dev/null)）"
rm -f "$work/dc12.args"
for bad in '' 'nope' '198.51.100.7/32,nope' $'198.51.100.7/32\nEVIL=1'; do
    if ec_set_admin_ips "$bad" 2> /dev/null; then ng "形の違う値（$(printf '%q' "$bad")）を受けた"; fi
done
[ "$(ec_kv_get "$EC_ENV_FILE" ADMIN_ALLOWED_IPS)" = '198.51.100.7/32,2001:db8:1:2::/64' ] && [ ! -e "$work/dc12.args" ] && ok "形の違う値は書かず、作り直さない" || ng "形の違う値で書いたか作り直した"
grep -q 'admin-ips) cmd_admin_ips' deploy/ec-sugar && ok "ec-sugar admin-ips がある" || ng "ec-sugar に admin-ips が無い"
# 広すぎる・危ない範囲は断る（見直しの軽 2）。私設の範囲は Tunnel の形でだけ断る
ec_front() { printf '%s' "${FAKE_FRONT:-caddy}"; }
FAKE_FRONT=tunnel
for bad in '0.0.0.0/0' '198.51.0.0/15' '::/0' '2001:db8::/32' '2001:db8:1:2::/200' '192.168.0.0/24' '10.0.0.5' '172.30.255.225/32' '127.0.0.1' 'fd00::1/128' 'fe80::1' '198.51.100.7/32,10.1.2.3'; do
    [ -n "$(ec_admin_ips_problem "$bad")" ] && ok "Tunnel の形で $bad を断る" || ng "Tunnel の形で $bad を受けた"
    rm -f "$work/dc12.args"
    if ec_set_admin_ips "$bad" 2> /dev/null; then ng "ec_set_admin_ips が $bad を書いた"; fi
done
for good in '198.51.100.7' '198.51.0.0/16' '2001:db8:1:2::/64' '2001:db8:1::/48' '2001:db8:5:6::/64,203.0.113.9/32'; do
    [ -z "$(ec_admin_ips_problem "$good")" ] && ok "Tunnel の形で $good を受ける" || ng "Tunnel の形で $good を断った（$(ec_admin_ips_problem "$good")）"
done
FAKE_FRONT=caddy
[ -z "$(ec_admin_ips_problem '192.168.0.0/24')" ] && ok "Caddy の形では LAN の範囲（ステージング）を受ける" || ng "Caddy の形で LAN の範囲を断った"
[ -n "$(ec_admin_ips_problem '0.0.0.0/0')" ] && ok "Caddy の形でも 0.0.0.0/0 は断る" || ng "Caddy の形で 0.0.0.0/0 を受けた"
grep -q '\[ \$# -eq 1 \]' deploy/ec-sugar && ok "admin-ips は余分な引数を断る" || ng "admin-ips が余分な引数を見ていない"
unset -f ec_front
EC_ANSWERS="$saved_answers12"
unset -f ec_dc
# 記録の連鎖の先頭と照らす（restore-check --heads。ADR 0110 の 5）: ふつうのファイルだけを、アプリの照らすコマンドへ標準入力で渡す
ec_dc() { printf '%s\n' "$*" > "$work/dcH.args"; cat > "$work/dcH.stdin"; return 0; }
printf 'shop1\t3\t%s\t2026-11-24T03:00:00Z\n' "$(printf 'a%.0s' $(seq 64))" > "$work/heads.tsv"
rm -f "$work/dcH.args" "$work/dcH.stdin"
ec_check_chain_heads_file "$work/heads.tsv" > /dev/null 2>&1 && [ "$(cat "$work/dcH.args")" = "exec -T app php artisan operations:check-chain-heads" ] && cmp -s "$work/heads.tsv" "$work/dcH.stdin" &&
    ok "先頭と照らす: ファイルの中身を、アプリの operations:check-chain-heads へ標準入力で渡す" || ng "先頭と照らす: 渡し方が違う（$(cat "$work/dcH.args" 2> /dev/null)）"
: > "$work/heads-empty.tsv"
ln -sf "$work/heads.tsv" "$work/heads-link.tsv"
for bad in "" "$work/nai.tsv" "$work/heads-empty.tsv" "$work/heads-link.tsv" "$work"; do
    rm -f "$work/dcH.args"
    rc=0; ec_check_chain_heads_file "$bad" > /dev/null 2>&1 || rc=$?
    [ "$rc" = 2 ] && [ ! -e "$work/dcH.args" ] || ng "先頭と照らす: 読めない・空・リンク・ディレクトリ（$(printf '%q' "$bad")）を渡した（返り ${rc}）"
done
ok "先頭と照らす: 読めない・空・リンク・ディレクトリは、アプリに渡さずに 2 で止まる"
grep -q -- '--heads) ' deploy/ec-sugar && grep -q 'ec_check_chain_heads_file "\$heads"' deploy/ec-sugar && ok "ec-sugar restore-check --heads がある" || ng "ec-sugar に restore-check --heads が無い"
unset -f ec_dc

# ---------- 13. 入れたときのサンプルとテーマ（ADR 0053） ----------
# 答えの SAMPLES はセットの名前か none。前の答えの yes は雑貨（zakka）、no は none と読む（前の答えのファイルを壊さない）。知らない値は断る
for pair in yes:zakka no:none zakka:zakka sweets:sweets fashion:fashion botanica:botanica none:none; do
    got="$(ec_sample_set "${pair%%:*}" || echo NG)"
    [ "$got" = "${pair#*:}" ] && ok "答えの ${pair%%:*} は ${pair#*:} と読む" || ng "答えの ${pair%%:*} を ${got} と読んだ"
done
for bad in '' 'Sweets' 'sweets ' 'all' 'yes;id' '--set=zakka' 'kinari'; do
    if ec_sample_set "$bad" > /dev/null; then ng "知らない答え（$(printf '%q' "$bad")）を受けた"; else ok "知らない答え（$(printf '%q' "$bad")）を断る"; fi
done
# 4/5 で呼ぶ shop:sample: 偽の ec_dc は引数を書き残し、FAKE_SAMPLE_RC で終わる（途中の様子も 1 行出す）
EC_STATE="$work/state13"
mkdir -p "$EC_STATE"
FAKE_SAMPLE_RC=0
ec_dc() { printf '%s\n' "$*" > "$work/dc13.args"; echo '写真を取っています'; return "$FAKE_SAMPLE_RC"; }
out="$(ec_add_samples shop sweets 2> /dev/null)" || ng "入れられたのに 0 でない"
[ "$(cat "$work/dc13.args" 2> /dev/null)" = "exec -T app php artisan shop:sample shop --set=sweets" ] && ok "答えのセットで shop:sample <店> --set=<セット> を呼ぶ" || ng "shop:sample の呼び方が違う（$(cat "$work/dc13.args" 2> /dev/null)）"
[ -e "$EC_STATE/samples_added" ] && ok "入れ終えたら印を残す" || ng "入れ終えたのに印が無い"
[[ "$out" == *Unsplash*'   | 写真を取っています'* ]] && ok "雑貨以外は Unsplash から取ると前置きし、途中の様子をそのまま出す" || ng "前置きか途中の様子が無い（${out}）"
rm -f "$work/dc13.args"
ec_add_samples shop sweets > /dev/null 2>&1
[ ! -e "$work/dc13.args" ] && ok "印があれば流し直さない（消した後に戻さない）" || ng "印があるのにまた呼んだ"
rm -f "$EC_STATE/samples_added"
ec_add_samples shop none > /dev/null 2>&1
[ ! -e "$work/dc13.args" ] && [ ! -e "$EC_STATE/samples_added" ] && ok "none なら呼ばない" || ng "none なのに呼んだ"
out="$(ec_add_samples shop zakka 2> /dev/null)"
[ "$(cat "$work/dc13.args" 2> /dev/null)" = "exec -T app php artisan shop:sample shop --set=zakka" ] && [[ "$out" != *Unsplash* ]] && ok "雑貨は --set=zakka で、Unsplash の前置きを出さない" || ng "雑貨の呼び方が違う（$(cat "$work/dc13.args" 2> /dev/null)・${out}）"
rm -f "$EC_STATE/samples_added" "$work/dc13.args"
FAKE_SAMPLE_RC=1
rc=0
(ec_add_samples shop fashion > /dev/null 2> "$work/err13") || rc=$?
[ "$rc" -eq 0 ] && [ ! -e "$EC_STATE/samples_added" ] && grep -q 'ショップは動きます' "$work/err13" && grep -q 'SAMPLES を none' "$work/err13" && ok "番号 1 なら注意と直し方を出して止めない（印は残さない）" || ng "番号 1 の扱いが違う（返り ${rc}）"
FAKE_SAMPLE_RC=2
rc=0
(ec_add_samples shop botanica > /dev/null 2> "$work/err13") || rc=$?
[ "$rc" -ne 0 ] && [ ! -e "$EC_STATE/samples_added" ] && grep -q 'インストーラーの誤り' "$work/err13" && ok "番号 2（引数の誤り）なら止める" || ng "番号 2 で止まらない（返り ${rc}）"
FAKE_SAMPLE_RC=255
rc=0
(ec_add_samples shop sweets > /dev/null 2> /dev/null) || rc=$?
[ "$rc" -eq 0 ] && [ ! -e "$EC_STATE/samples_added" ] && ok "app に届かない（そのほかの番号）ときも止めない" || ng "そのほかの番号で止まった（返り ${rc}）"
unset -f ec_dc
# インストーラーのつなぎ: 2/5 の既定は sweets・答えの確かめは ec_sample_set・入れたサーバーは聞かずに none、4/5 は答えを読み替えて渡す、1/5 は雑貨以外だけ Unsplash を見る
grep -q 'sweets is_samples' deploy/install.sh && grep -q '^is_samples() { ec_sample_set "\$1" > /dev/null; }' deploy/install.sh && ok "2/5 の既定は sweets で、答えはセットの名前か none（前の yes・no も）" || ng "2/5 の問いの既定か確かめが違う"
grep -q 'installed && default_answer SAMPLES none' deploy/install.sh && ok "入れたサーバーで流し直すときは聞かずに入れない" || ng "入れたサーバーで聞かずに入れない決まりが無い"
grep -q 'ec_add_samples "\$(ec_answer SHOP_ID)" "\$sample_set"' deploy/install.sh && ok "4/5 は答えを読み替えたセットで入れる" || ng "4/5 が ec_add_samples を呼んでいない"
grep -q 'if ec_sample_fetches "\$(samples_for_check)"; then' deploy/install.sh && grep -q 'https://images.unsplash.com/' deploy/install.sh && ok "1/5 は写真を取るセットのときだけ Unsplash への道を見る" || ng "1/5 の Unsplash の確かめが無い"

# ---------- 15. 1 店舗から複数の店へ広げる（ADR 0090） ----------
# 本番の今の形（1 店舗・Cloudflare Tunnel・DNS の鍵）の答えから、sudo ec-sugar shops multi --domain=ec-sugar.jp で広げる:
# 広げられるかの確かめ（URL が変わる形・apex・深い名前・管理画面と同じ・SESSION_DOMAIN・証明書がワイルドカードを覆わない）、
# 答えの書き換え（SHOPS と SHOP_DOMAIN だけ。権限 0600 のまま）、最初の店のホストが shop.ec-sugar.jp のまま、env の店の数の項目（前の名前の行も）、
# cloudflared の行き先、nginx が受ける名前、2 回流しても同じ、作り直すコンテナ、https で開けなければ 1 店舗へ戻す、1 店舗へ戻す道、DNS に足す行
saved_answers15="$EC_ANSWERS"
saved_env15="$EC_ENV_FILE"
saved_tunnel15="$EC_TUNNEL_DIR"
saved_caddy15="$EC_CADDY_SECRETS"
saved_state15="$EC_STATE"
# 6. で置いた偽の openssl・timeout を外す（ここでは本物の openssl で証明書を作って確かめる）
saved_path15="$PATH"
PATH="${PATH//"$work/bin:"/}"
# 前の節が偽の部品で上書きし、unset -f で消した道具（ec_front など）を戻す（道具を読み直す。置き場の道筋は下で決め直す）
# shellcheck source=../deploy/lib/common.sh
. "$lib"
EC_ANSWERS="$work/answers15"
EC_ENV_FILE="$work/env15"
EC_TUNNEL_DIR="$work/tunnel15"
EC_CADDY_SECRETS="$work/caddy15"
EC_STATE="$work/state15"
mkdir -p "$EC_TUNNEL_DIR" "$EC_CADDY_SECRETS/tls" "$EC_STATE" "$work/c15"
perm15() { stat -c '%a' "$1" 2> /dev/null || stat -f '%Lp' "$1"; }
prod15='MODE=production
SHOPS=single
FRONT=tunnel
ADMIN_HOST=admin.ec-sugar.jp
SHOP_HOST=shop.ec-sugar.jp
SHOP_ID=shop
BIND=127.0.0.1
AUTO_UPDATE=no
CLOUDFLARE_TOKEN_FILE=/root/cloudflare-token'
printf '%s\n' "$prod15" > "$EC_ANSWERS"
chmod 0600 "$EC_ANSWERS"
# env は前の名前の行（SUGAR_EC_…。compose が先に読む）を 1 つ含む、名前を移した後のサーバーの形
printf 'APP_KEY=base64:keep\nEC_SUGAR_IMAGE=ec-sugar:abc\nSESSION_DOMAIN=null\nSUGAR_EC_SITE_SHOP=shop.ec-sugar.jp\nEC_SUGAR_SITE_SHOP=shop.ec-sugar.jp\n' > "$EC_ENV_FILE"
ec_env_put_shops
envs15() { printf '%s|%s|%s|%s|%s|%s' "$(ec_env_get EC_SUGAR_SHOPS)" "$(ec_env_get EC_SUGAR_SHOP_DOMAIN)" "$(ec_kv_get "$EC_ENV_FILE" EC_SUGAR_SITE_SHOP)" "$(ec_kv_get "$EC_ENV_FILE" SUGAR_EC_SITE_SHOP)" "$(ec_env_get EC_SUGAR_SERVER_NAMES)" "$(ec_env_get CENTRAL_DOMAINS)"; }
[ "$(envs15)" = 'single|shop.ec-sugar.jp|shop.ec-sugar.jp|shop.ec-sugar.jp|admin.ec-sugar.jp shop.ec-sugar.jp|' ] && ok "広げる: 1 店舗の env は管理画面とショップのホストの 2 つ・中央のホストなし" || ng "広げる: 1 店舗の env が違う（$(envs15)）"
cp "$EC_ENV_FILE" "$work/env15.single"
grep -q 'ec_env_put_shops || ec_die' deploy/install.sh && ! grep -q 'ec_env_set CENTRAL_DOMAINS' deploy/install.sh && ok "広げる: インストーラーも同じ ec_env_put_shops で env を書く（流し直しても同じ形）" || ng "広げる: インストーラーが店の数の env を別に書いている"
[ "$(ec_shop_host)" = shop.ec-sugar.jp ] && ok "広げる: 1 店舗のショップは shop.ec-sugar.jp" || ng "広げる: 1 店舗のショップのホストが違う（$(ec_shop_host)）"

# 広げられるかの確かめ（何も変えない）。$1 ドメイン $2 期待する理由の一部（空なら広げられる）
expect15() {
    local got
    got="$(ec_shops_multi_problem "$1")"
    if [ -z "$2" ]; then
        [ -z "$got" ] && ok "広げる: ${3:-$1} なら広げられる" || ng "広げる: ${3:-$1} で止まった（${got}）"
    else
        [[ "$got" == *"$2"* ]] && ok "広げる: ${3:-$1} は断る" || ng "広げる: ${3:-$1} を断らない・理由が違う（${got}）"
    fi
}
expect15 ec-sugar.jp "" "本番の答えと ec-sugar.jp"
expect15 'Ec-sugar.jp' "ドメインの形ではありません" "大文字のドメイン"
expect15 'ec-sugar.jp;id' "ドメインの形ではありません" "記号の入ったドメイン"
expect15 admin.ec-sugar.jp "管理画面のホスト名" "管理画面と同じドメイン"
expect15 example.jp "URL が変わります" "ショップのホストが直下に無いドメイン"
expect15 sugar.jp "URL が変わります" "末尾だけ似たドメイン（shop.ec-sugar.jp と sugar.jp）"
expect15 shop.ec-sugar.jp "URL が変わります" "ショップのホストそのもの"
cmp -s "$EC_ENV_FILE" "$work/env15.single" && [ "$(cat "$EC_ANSWERS")" = "$prod15" ] && ok "広げる: 確かめだけでは答えも env も変えない" || ng "広げる: 確かめで答えか env を変えた"
printf '%s\n' "$prod15" | sed 's/^SHOP_HOST=.*/SHOP_HOST=ec-sugar.jp/' > "$EC_ANSWERS"
expect15 ec-sugar.jp "URL が変わります" "ショップのホストがドメインそのもの（apex）"
printf '%s\n' "$prod15" | sed 's/^SHOP_HOST=.*/SHOP_HOST=a.shop.ec-sugar.jp/' > "$EC_ANSWERS"
expect15 ec-sugar.jp "URL が変わります" "ショップのホストが 2 段下"
printf '%s\n' "$prod15" > "$EC_ANSWERS"
sed -i.bak 's/^SESSION_DOMAIN=.*/SESSION_DOMAIN=.ec-sugar.jp/' "$EC_ENV_FILE"
expect15 ec-sugar.jp "SESSION_DOMAIN" "env の SESSION_DOMAIN がショップのドメイン全体"
cp "$work/env15.single" "$EC_ENV_FILE"
# 手で渡した証明書（Origin CA と同じく手で置く形）: ワイルドカードを覆うか。店の名前（shop）だけを含む証明書は断る
mk15() { openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -keyout "$work/c15/$1.key" -out "$work/c15/$1.crt" -days 400 -subj '/O=Test/CN=test' -addext "subjectAltName=$2" > /dev/null 2>&1; }
mk15 names 'DNS:admin.ec-sugar.jp,DNS:shop.ec-sugar.jp'
mk15 wild 'DNS:ec-sugar.jp,DNS:*.ec-sugar.jp'
printf '%s\n' "$prod15" | sed 's|^CLOUDFLARE_TOKEN_FILE=.*|TLS_CERT=/root/origin-tls/cert.pem|' > "$EC_ANSWERS"
printf 'TLS_KEY=/root/origin-tls/key.pem\n' >> "$EC_ANSWERS"
cp "$work/c15/names.crt" "$EC_CADDY_SECRETS/tls/cert.pem"
expect15 ec-sugar.jp "*.ec-sugar.jp が含まれていません" "1 店舗の名前だけの証明書（shop.ec-sugar.jp を含む）"
cp "$work/c15/wild.crt" "$EC_CADDY_SECRETS/tls/cert.pem"
expect15 ec-sugar.jp "" "*.ec-sugar.jp を含む証明書"
printf '%s\n' "$prod15" | sed "s|^CLOUDFLARE_TOKEN_FILE=.*|TLS_CERT=$work/c15/names.crt|" > "$EC_ANSWERS"
expect15 ec-sugar.jp "sudo ./deploy/install.sh を実行して証明書を替えて" "答えの証明書が入口のものと違う（新しい証明書を置いたが流し直していない）"
# インストーラーの手で渡した証明書の確かめ（ec_check_tls）も、複数の店の形では店の名前で見ない（前は shop.<ドメイン> で見ていて、本番の店の名前と重なった）
printf 'SHOPS=multi\nADMIN_HOST=admin.ec-sugar.jp\nSHOP_DOMAIN=ec-sugar.jp\nSHOP_ID=shop\nSHOP_HOST=shop.ec-sugar.jp\n' > "$EC_ANSWERS"
rc=0
out="$( (ec_check_tls "$work/c15/names.crt" "$work/c15/names.key") 2>&1)" || rc=$?
[ "$rc" -ne 0 ] && [[ "$out" == *'*.ec-sugar.jp'* ]] && ok "インストーラー: 複数の店の形で、shop.ec-sugar.jp だけの証明書をワイルドカードとみなさない" || ng "インストーラー: 店の名前だけの証明書を通した（返り ${rc}）"
rc=0
(ec_check_tls "$work/c15/wild.crt" "$work/c15/wild.key") > /dev/null 2>&1 || rc=$?
[ "$rc" -eq 0 ] && ok "インストーラー: *.ec-sugar.jp の証明書は通す" || ng "インストーラー: ワイルドカードの証明書を断った（返り ${rc}）"

# 答えを書き換えて env を書く（本番の答え。DNS の鍵）
printf '%s\n' "$prod15" > "$EC_ANSWERS"
chmod 0600 "$EC_ANSWERS"
ec_shops_set_answers multi ec-sugar.jp || ng "広げる: 答えを書けない"
want15="$(printf '%s\n' "$prod15" | sed 's/^SHOPS=single$/SHOPS=multi/'; printf 'SHOP_DOMAIN=ec-sugar.jp')"
[ "$(cat "$EC_ANSWERS")" = "$want15" ] && ok "広げる: 答えは SHOPS=multi と SHOP_DOMAIN を足すだけ（SHOP_HOST・SHOP_ID・ほかの行はそのまま）" || ng "広げる: 答えの書き換えが違う（$(tr '\n' '|' < "$EC_ANSWERS")）"
[ "$(perm15 "$EC_ANSWERS")" = 600 ] && [ -z "$(find "$work" -maxdepth 1 -name '.answers15.*')" ] && ok "広げる: 答えは 0600 のまま、仮のファイルを残さない" || ng "広げる: 答えの権限が $(perm15 "$EC_ANSWERS")・仮のファイルが残った"
[ "$(ec_shops)|$(ec_shop_host)|$(ec_shop_site)|$(ec_shop_domain)" = 'multi|shop.ec-sugar.jp|*.ec-sugar.jp|ec-sugar.jp' ] && ok "広げる: 最初の店は shop.ec-sugar.jp のまま（URL を変えない）、店の場所は *.ec-sugar.jp" || ng "広げる: 最初の店のホストか場所が違う（$(ec_shop_host)・$(ec_shop_site)）"
ec_env_put_shops
[ "$(envs15)" = 'multi|ec-sugar.jp|*.ec-sugar.jp|*.ec-sugar.jp|admin.ec-sugar.jp *.ec-sugar.jp|api.ec-sugar.jp' ] && grep -qx 'APP_KEY=base64:keep' "$EC_ENV_FILE" && grep -qx 'SESSION_DOMAIN=null' "$EC_ENV_FILE" &&
    ok "広げる: env は multi・ショップのドメイン・*.ec-sugar.jp（前の名前の行も）・中央のホスト api.ec-sugar.jp。APP_KEY と SESSION_DOMAIN はそのまま" || ng "広げる: env が違う（$(envs15)）"
out="$(ec_tunnel_config admin.ec-sugar.jp "$(ec_shop_site)")"
[ "$(printf '%s\n' "$out" | grep -c 'hostname:')" = 2 ] && printf '%s\n' "$out" | q -x '  - hostname: "\*.ec-sugar.jp"' &&
    [ "$(printf '%s\n' "$out" | tail -n1)" = '  - service: http_status:404' ] && ok "広げる: cloudflared の行き先は管理画面と *.ec-sugar.jp、ほかは 404" || ng "広げる: cloudflared の行き先が違う"
# nginx の受ける名前（docker/nginx/prod.conf のひな形に env の値を入れる。nginx のイメージの起動のスクリプトと同じく EC_SUGAR_ の変数だけ）
render15() {
    local text name val
    text="$(cat docker/nginx/prod.conf)"
    for name in EC_SUGAR_ADMIN_HOST EC_SUGAR_SHOP_DOMAIN EC_SUGAR_SERVER_NAMES; do
        val="$(ec_env_get "$name" 2> /dev/null || true)"
        [ "$name" != EC_SUGAR_ADMIN_HOST ] || val=admin.ec-sugar.jp
        text="${text//\$\{$name\}/$val}"
    done
    printf '%s\n' "$text"
}
out="$(render15)"
printf '%s\n' "$out" | q -x '    server_name admin.ec-sugar.jp \*.ec-sugar.jp;' && printf '%s\n' "$out" | q -x '    \*.ec-sugar.jp 1;' &&
    ok "広げる: nginx は管理画面と *.ec-sugar.jp を受け、証明書の問い合わせも *.ec-sugar.jp" || ng "広げる: nginx の名前が違う"
cp "$EC_ANSWERS" "$work/answers15.multi"
cp "$EC_ENV_FILE" "$work/env15.multi"
ec_shops_set_answers multi ec-sugar.jp && ec_env_put_shops
cmp -s "$EC_ANSWERS" "$work/answers15.multi" && cmp -s "$EC_ENV_FILE" "$work/env15.multi" && ok "広げる: 2 回流しても答えも env も同じ" || ng "広げる: 2 回目で答えか env が変わった"
expect15 ec-sugar.jp "" "もう同じドメインで複数の店の形（流し直し・続きから）"
expect15 example.jp "ショップのドメインは替えません" "複数の店の形で別のドメイン"

# 公開の接尾辞（co.jp・tokyo.jp など）はショップのドメインにしない（見直しの軽 6）
expect15 co.jp "公開の接尾辞" "日本の属性型の接尾辞（co.jp）"
expect15 tokyo.jp "公開の接尾辞" "日本の都道府県型の接尾辞（tokyo.jp）"
expect15 co.uk "公開の接尾辞" "海外の 2 段の接尾辞（co.uk）"
! ec_is_public_suffix example.co.jp && ! ec_is_public_suffix ec-sugar.jp && ok "広げる: example.co.jp・ec-sugar.jp は公開の接尾辞でない" || ng "広げる: 自分のドメインを公開の接尾辞とみなした"
# 応答の番号の形（見直しの軽 1）: curl は届かないと 000 を出してから失敗で終わる。000000 を「000 でなく 500 未満」と取り違えない
for pair in 200:200 404:404 000:000 000000:000 '':000 999:000 2000:000 abc:000 599:599; do
    [ "$(ec_code_norm "${pair%%:*}")" = "${pair#*:}" ] && ok "番号の形: 「${pair%%:*}」は ${pair#*:}" || ng "番号の形: 「${pair%%:*}」を $(ec_code_norm "${pair%%:*}") とした"
done
ec_code_below_500 404 && ec_code_below_500 200 && ! ec_code_below_500 500 && ! ec_code_below_500 000000 && ! ec_code_below_500 000 && ! ec_code_below_500 '' &&
    ok "番号の比べ: 届いて 500 未満だけ（000000・000・空・500 は違う）" || ng "番号の比べが違う"
curl() { printf '000'; return 7; }
[ "$(ec_http_code shop.ec-sugar.jp /)" = 000 ] && ok "入口へ聞く: 届かなければ 000（000000 にしない）" || ng "入口へ聞く: 届かない時の番号が $(ec_http_code shop.ec-sugar.jp /)"
unset -f curl
grep -q '        ec_code_below_500 "\$code"' deploy/install.sh && ! grep -qE '"\$code" -(lt|ge) 500' deploy/install.sh deploy/lib/common.sh &&
    ok "番号の比べ: インストーラーと道具は形を確かめる比べだけを使う" || ng "番号の比べ: 000 と 500 で直に比べる所が残っている"

# 形を替える（偽の ec_dc・ヘルスチェック・入口の証明書・外からの確かめ。cloudflared の置き場は偽の道具で）
printf '%s\n' "$prod15" > "$EC_ANSWERS"
chmod 0600 "$EC_ANSWERS"
cp "$work/env15.single" "$EC_ENV_FILE"
ec_dc() { printf '%s\n' "$*" >> "$work/dc15.log"; [ "$1 $2" != "exec -T" ] || printf '%s\n' admin.ec-sugar.jp shop.ec-sugar.jp demo-sake.ec-sugar.jp 'evil;rm -rf' a.b.ec-sugar.jp; return 0; }
# ヘルスチェックは、https を見るか（EC_HEALTH_TLS）を書き残す
ec_health() { printf '%s\n' "${EC_HEALTH_TLS:-1}" >> "$work/health15.log"; [ "${FAKE_HEALTH15:-0}" = 0 ]; }
# 中から接続元を偽れるかの確かめ（FAKE_TUNNEL15 で終わる）と、外（Cloudflare）からの番号（FAKE_OUTSIDE15: ok・never・multi-fails）
ec_check_tunnel() { return "${FAKE_TUNNEL15:-0}"; }
ec_shops_outside_code() {
    case "${FAKE_OUTSIDE15:-ok}" in
        never) printf '000' ;;
        multi-fails) if [ "$(ec_shops)" = multi ]; then printf '404'; else printf '200'; fi ;;
        *) printf '200' ;;
    esac
}
sleep() { :; }
EC_SHOPS_DNS_TRIES=2
EC_SHOPS_OUTSIDE_TRIES=1
# FAKE_CERT15=1 なら、複数の店の形の時だけ入口の証明書が店の名前を覆わない（ワイルドカードを取れなかった）
ec_front_cert_covers() { [ "${FAKE_CERT15:-0}" = 0 ] || [ "$(ec_shops)" != multi ]; }
ec_http_code() { printf '200'; }
EC_SHOPS_HTTPS_TRIES=1
export EC_ARGV_LOG="$work/argv15.log" EC_REAL_PATH="$PATH"
cfg15() { grep 'hostname:' "$EC_TUNNEL_DIR/config.yml" 2> /dev/null | tr -d ' "' | tr '\n' ' '; }
rm -f "$work/dc15.log"
rc=0
(PATH="$shim:$PATH"; ec_shops_switch multi ec-sugar.jp) > "$work/out15" 2>&1 || rc=$?
[ "$rc" -eq 0 ] && [ "$(ec_shops)" = multi ] && [ "$(ec_env_get EC_SUGAR_SHOPS)" = multi ] && [ "$(cfg15)" = '-hostname:admin.ec-sugar.jp -hostname:*.ec-sugar.jp ' ] &&
    ok "広げる: 答え・env・cloudflared の行き先が複数の店の形になる" || ng "広げる: 形を替えられない（返り ${rc}・$(cfg15)・$(tr '\n' ' ' < "$work/out15")）"
[ "$(head -n1 "$work/dc15.log" 2> /dev/null)" = 'up -d --no-deps --force-recreate web app queue scheduler caddy cloudflared' ] &&
    ok "広げる: 作り直すのは web・app・queue・scheduler・caddy・cloudflared だけ（DB と redis に触らない）" || ng "広げる: 作り直し方が違う（$(head -n1 "$work/dc15.log" 2> /dev/null)）"
grep -q 'shops_multi' "$EC_STATE/history.log" 2> /dev/null && ok "広げる: 記録（history.log）に残す" || ng "広げる: 記録に残していない"
[ "$(head -n1 "$work/health15.log" 2> /dev/null)" = 0 ] && grep -q 'ワイルドカード' "$work/out15" &&
    ok "広げる: dns の取り方では、ヘルスチェックの https を外し、ワイルドカードを待つ確かめで見る（先に 1 分で落ちて戻さない）" || ng "広げる: dns の取り方のヘルスチェックが https を見ている（$(head -n1 "$work/health15.log" 2> /dev/null)）"
# 待つ間に握手が失敗してから通る（ワイルドカードを取り終えるまで、管理画面も店も握手が通らない）
printf '0' > "$work/cnt15"
ec_front_cert_covers() { local n; n="$(cat "$work/cnt15")"; printf '%s' "$((n + 1))" > "$work/cnt15"; [ "$n" -ge 4 ]; }
(ec_shops_https_ok 5) > /dev/null 2>&1 && ok "広げる: 握手が何回か失敗しても、待つ間に通れば当たったとする" || ng "広げる: 待つ間に通ったのに落とした"
printf '0' > "$work/cnt15"
! (ec_shops_https_ok 2) > /dev/null 2>&1 && ok "広げる: 待つ間に通らなければ当たらない" || ng "広げる: 通らないのに当たったとした"
ec_front_cert_covers() { [ "${FAKE_CERT15:-0}" = 0 ] || [ "$(ec_shops)" != multi ]; }
# 最初の店が 404（店が見つからない）・管理画面の /up が 200 でないなら当たらない（見直しの軽 4）
ec_http_code() { if [ "$2" = / ]; then printf '404'; else printf '200'; fi; }
! (ec_shops_https_ok 1) > /dev/null 2>&1 && ok "広げる: 最初の店が 404 なら当たらない" || ng "広げる: 最初の店が 404 でも当たったとした"
ec_http_code() { if [ "$2" = /up ]; then printf '500'; else printf '200'; fi; }
! (ec_shops_https_ok 1) > /dev/null 2>&1 && ok "広げる: 管理画面の /up が 200 でなければ当たらない（管理画面も見る）" || ng "広げる: 管理画面を見ていない"
ec_http_code() { printf '200'; }
# Cloudflare Tunnel の形の終わりに外からも聞く（見直しの軽 5）: 替える前は開けたのに、替えた後に開けなければ前の形へ戻す
printf '%s\n' "$prod15" > "$EC_ANSWERS"
chmod 0600 "$EC_ANSWERS"
cp "$work/env15.single" "$EC_ENV_FILE"
rc=0
(FAKE_OUTSIDE15=multi-fails; PATH="$shim:$PATH"; ec_shops_switch multi ec-sugar.jp) > "$work/out15" 2>&1 || rc=$?
[ "$rc" -eq 2 ] && [ "$(ec_shops)" = single ] && grep -q '替えた後は' "$work/out15" && ok "広げる: 替えた後に外から開けなくなったら 1 店舗へ戻す" || ng "広げる: 外から開けなくなっても戻さない（返り ${rc}）"
# 替える前から外から開けない（DNS がまだなど）なら、戻さずに大きく注意を出す
printf '%s\n' "$prod15" > "$EC_ANSWERS"
cp "$work/env15.single" "$EC_ENV_FILE"
rc=0
(FAKE_OUTSIDE15=never; PATH="$shim:$PATH"; ec_shops_switch multi ec-sugar.jp) > "$work/out15" 2>&1 || rc=$?
[ "$rc" -eq 0 ] && [ "$(ec_shops)" = multi ] && grep -q '大事: 外（Cloudflare）から' "$work/out15" && grep -q 'check-front' "$work/out15" &&
    ok "広げる: 替える前から外から開けないなら、戻さずに大きく注意して check-front を促す" || ng "広げる: 前から外から開けない時の扱いが違う（返り ${rc}）"
# 中から接続元を偽れる（cloudflared の住所の信じ方が崩れた）なら戻す
printf '%s\n' "$prod15" > "$EC_ANSWERS"
cp "$work/env15.single" "$EC_ENV_FILE"
rc=0
(FAKE_TUNNEL15=1; PATH="$shim:$PATH"; ec_shops_switch multi ec-sugar.jp) > /dev/null 2>&1 || rc=$?
[ "$rc" -eq 3 ] && ok "広げる: 接続元を偽れるなら当たらない（1 店舗に戻しても偽れるので 3）" || ng "広げる: 接続元を偽れても当たったとした（返り ${rc}）"
FAKE_TUNNEL15=0
printf '%s\n' "$prod15" > "$EC_ANSWERS"
chmod 0600 "$EC_ANSWERS"
cp "$work/env15.single" "$EC_ENV_FILE"
(PATH="$shim:$PATH"; ec_shops_switch multi ec-sugar.jp) > /dev/null 2>&1 || ng "広げる: 外の確かめの後で、もう一度広げられない"
# https で開けなければ 1 店舗へ戻す（お客さんの店を開けたまま）
printf '%s\n' "$prod15" > "$EC_ANSWERS"
chmod 0600 "$EC_ANSWERS"
cp "$work/env15.single" "$EC_ENV_FILE"
rm -f "$work/dc15.log"
rc=0
(FAKE_CERT15=1; PATH="$shim:$PATH"; ec_shops_switch multi ec-sugar.jp) > "$work/out15" 2>&1 || rc=$?
[ "$rc" -eq 2 ] && [ "$(ec_shops)" = single ] && [ "$(envs15)" = 'single|shop.ec-sugar.jp|shop.ec-sugar.jp|shop.ec-sugar.jp|admin.ec-sugar.jp shop.ec-sugar.jp|' ] &&
    [ "$(cfg15)" = '-hostname:admin.ec-sugar.jp -hostname:shop.ec-sugar.jp ' ] && [ "$(grep -c force-recreate "$work/dc15.log")" = 2 ] &&
    ok "広げる: https で開けなければ 1 店舗の形へ自動で戻す（答え・env・cloudflared の行き先）" || ng "広げる: 開けない時に戻さない（返り ${rc}・$(ec_shops)・$(envs15)・$(cfg15)）"
grep -q 'shops_reverted' "$EC_STATE/history.log" && ok "広げる: 戻したことも記録に残す" || ng "広げる: 戻したことが記録に無い"
# もう複数の店の形で当て直しが落ちたら 3（戻す先の形が無い）
ec_shops_set_answers multi ec-sugar.jp
rc=0
(FAKE_HEALTH15=1; PATH="$shim:$PATH"; ec_shops_switch multi ec-sugar.jp) > /dev/null 2>&1 || rc=$?
[ "$rc" -eq 3 ] && [ "$(ec_shops)" = multi ] && ok "広げる: もう複数の店の形での当て直しが落ちたら、形はそのまま 3" || ng "広げる: 当て直しの失敗の返りが ${rc}"
# 1 店舗へ戻す（広げたサーバー）: SHOP_HOST が残っているので、同じ shop.ec-sugar.jp の 1 店舗に
rc=0
(PATH="$shim:$PATH"; ec_shops_switch single) > /dev/null 2>&1 || rc=$?
[ "$rc" -eq 0 ] && [ "$(ec_shops)|$(ec_shop_host)" = 'single|shop.ec-sugar.jp' ] && [ "$(envs15)" = 'single|shop.ec-sugar.jp|shop.ec-sugar.jp|shop.ec-sugar.jp|admin.ec-sugar.jp shop.ec-sugar.jp|' ] &&
    [ "$(cfg15)" = '-hostname:admin.ec-sugar.jp -hostname:shop.ec-sugar.jp ' ] && ok "戻す: 1 店舗の形へ戻すと、ショップは shop.ec-sugar.jp のまま・中央のホストなし" || ng "戻す: 1 店舗へ戻せない（返り ${rc}・$(envs15)）"
# 初めから複数の店で入れたサーバー（SHOP_HOST が無い）を 1 店舗へ戻すと、今の最初の店のホストを SHOP_HOST に書く
printf 'FRONT=caddy\nSHOPS=multi\nADMIN_HOST=admin.example.jp\nSHOP_DOMAIN=example.jp\nSHOP_ID=demo\n' > "$EC_ANSWERS"
ec_shops_set_answers single && [ "$(ec_shops)|$(ec_shop_host)|$(ec_answer SHOP_HOST)" = 'single|demo.example.jp|demo.example.jp' ] &&
    ok "戻す: 初めから複数の店のサーバーは、最初の店のホスト（demo.example.jp）の 1 店舗にする" || ng "戻す: 初めから複数の店のサーバーの戻し方が違う（$(tr '\n' '|' < "$EC_ANSWERS")）"
# 店の ID と 1 段目が違う 1 店舗（ID shop・ホスト store.example.jp）も、広げた後のホストは store.example.jp のまま
printf 'SHOPS=single\nADMIN_HOST=admin.example.jp\nSHOP_HOST=store.example.jp\nSHOP_ID=shop\n' > "$EC_ANSWERS"
[ -z "$(ec_shops_multi_problem example.jp)" ] && ec_shops_set_answers multi example.jp && [ "$(ec_shop_host)|$(ec_shop_site)" = 'store.example.jp|*.example.jp' ] &&
    ok "広げる: 店の ID と 1 段目が違っても（ID shop・store.example.jp）、最初の店のホストはそのまま" || ng "広げる: ID と違う 1 段目のホストが変わった（$(ec_shop_host)）"
# SHOP_HOST がショップのドメインの直下でない（古い答えの残り）なら、複数の店の形の最初の店は <ID>.<ドメイン>
printf 'SHOPS=multi\nADMIN_HOST=admin.example.jp\nSHOP_DOMAIN=example.jp\nSHOP_ID=demo\nSHOP_HOST=elsewhere.test\n' > "$EC_ANSWERS"
[ "$(ec_shop_host)" = demo.example.jp ] && ok "広げる: SHOP_HOST がドメインの直下でなければ <店の ID>.<ドメイン>" || ng "広げる: ドメインの外の SHOP_HOST を最初の店にした（$(ec_shop_host)）"
# DNS に足す行（Tunnel の形は店ごとの CNAME。ドメインの外・2 段下・管理画面・形の悪い名前は出さない）
printf '%s\n' "$prod15" > "$EC_ANSWERS"
ec_shops_set_answers multi ec-sugar.jp
out="$(ec_shops_dns_note)"
[[ "$out" == *'CNAME   名前 shop.ec-sugar.jp'* ]] && [[ "$out" == *'CNAME   名前 demo-sake.ec-sugar.jp'* ]] && [[ "$out" == *'CNAME   名前 api.ec-sugar.jp'*'Stripe Connect を使うときだけ'* ]] &&
    [[ "$out" != *'名前 admin.ec-sugar.jp'* ]] && [[ "$out" != *'a.b.ec-sugar.jp'* ]] && [[ "$out" != *'evil'* ]] &&
    ok "広げる: DNS に足す行は店ごとの CNAME（中央 DB の店のホストから。形の悪い名前・2 段下は出さない）と、Stripe Connect のときだけ api" || ng "広げる: DNS に足す行が違う（$(printf '%s' "$out" | tr '\n' '|')）"
grep -q 'shops) cmd_shops' deploy/ec-sugar && grep -q 'ec_shops_multi_problem "\$domain"' deploy/ec-sugar && grep -q 'ec_shops_switch "\$form" "\$domain"' deploy/ec-sugar &&
    ok "ec-sugar shops がある（確かめてから鍵を取って替える）" || ng "ec-sugar に shops が無い"
unset -f ec_dc ec_health ec_front_cert_covers ec_http_code perm15 envs15 expect15 mk15 render15 cfg15 ec_check_tunnel ec_shops_outside_code sleep
unset EC_ARGV_LOG EC_REAL_PATH
EC_ANSWERS="$saved_answers15"
EC_ENV_FILE="$saved_env15"
EC_TUNNEL_DIR="$saved_tunnel15"
EC_CADDY_SECRETS="$saved_caddy15"
EC_STATE="$saved_state15"
PATH="$saved_path15"

# ---------- 17. APP_KEY の控え ----------
saved_env17="$EC_ENV_FILE"
saved_backup17="$EC_BACKUP"
key17='base64:not-a-real-key-for-check-17'
pass17='db-password-for-the-check-only'
umask17="$(umask)"
mkdir -p "$work/k17/env" "$work/k17/out" "$work/k17/open" "$work/k17/backup/2026-11-19"
chmod 0700 "$work/k17/out" "$work/k17/backup" "$work/k17/backup/2026-11-19"
chmod 0777 "$work/k17/open"
EC_ENV_FILE="$work/k17/env/.env.production"
EC_BACKUP="$work/k17/backup"
printf 'APP_NAME=EC-SUGAR\nAPP_KEY=%s\nDB_PASSWORD=%s\n' "$key17" "$pass17" > "$EC_ENV_FILE"
chmod 0600 "$EC_ENV_FILE"
perm17() { find "$1" -prune -perm 0600 -print 2> /dev/null | q . && echo 600 || echo other; }
# 説明: 何の鍵か・どこにあるか・取り方。値は出ない
out="$(ec_app_key_explain 2>&1)"
[[ "$out" == *"$EC_ENV_FILE"* ]] && [[ "$out" == *'sudo ec-sugar app-key export'* ]] && [[ "$out" == *'バックアップ（sudo ec-sugar backup）には入りません'* ]] &&
    [[ "$out" == *'バックアップを取りました'* ]] && [[ "$out" == *'--app-key-file'* ]] &&
    ok "APP_KEY の控え: 説明に、何の鍵か・設定のファイルの場所・取り方・管理画面で答えること・戻す時の渡し方がある" || ng "APP_KEY の控え: 説明が足りない（${out}）"
# 写す: 0600・同じ中身・終わりの案内
rc=0
out="$( (umask 022; ec_app_key_export "$work/k17/out/copy") 2>&1)" || rc=$?
[ "$rc" -eq 0 ] && cmp -s "$EC_ENV_FILE" "$work/k17/out/copy" && [ "$(perm17 "$work/k17/out/copy")" = 600 ] &&
    ok "APP_KEY の控え: 設定のファイルを同じ中身で 0600 に写す（流した人の umask が 022 でも）" || ng "APP_KEY の控え: 写せない・権限か中身が違う（rc=${rc}・$(ls -l "$work/k17/out" 2>&1 | tr '\n' '|')）"
[[ "$out" == *'サーバーの外'* ]] && [[ "$out" == *'移したらサーバーから消してください'* ]] && [[ "$out" == *'「バックアップを取りました」を押すと、通知が消えます'* ]] &&
    ok "APP_KEY の控え: 写した後に、外へ移して消すことと、管理画面で答えることを出す" || ng "APP_KEY の控え: 写した後の案内が違う（${out}）"
all17="$out"$'\n'"$(ec_app_key_explain 2>&1)"
# もうあるファイルは上書きしない（中身はそのまま）
printf 'keep\n' > "$work/k17/out/exists"
rc=0
out="$( (ec_app_key_export "$work/k17/out/exists") 2>&1)" || rc=$?
all17="$all17"$'\n'"$out"
[ "$rc" -ne 0 ] && [ "$(cat "$work/k17/out/exists")" = keep ] && [[ "$out" == *'もうあります（上書きしません）'* ]] &&
    ok "APP_KEY の控え: もうあるファイルは上書きせずに断る" || ng "APP_KEY の控え: もうあるファイルを断らない（rc=${rc}・${out}）"
# シンボリックリンクを断る（先のあるもの・無いもの。どちらもリンクの先に書かない）
printf 'target\n' > "$work/k17/out/target"
ln -s "$work/k17/out/target" "$work/k17/out/link"
ln -s "$work/k17/out/nowhere" "$work/k17/out/dangling"
bad17=""
for name in link dangling; do
    rc=0
    out="$( (ec_app_key_export "$work/k17/out/$name") 2>&1)" || rc=$?
    all17="$all17"$'\n'"$out"
    { [ "$rc" -ne 0 ] && [[ "$out" == *'シンボリックリンクです'* ]]; } || bad17="${bad17} ${name}"
done
[ -z "$bad17" ] && [ "$(cat "$work/k17/out/target")" = target ] && [ ! -e "$work/k17/out/nowhere" ] &&
    ok "APP_KEY の控え: シンボリックリンクは断り、リンクの先に書かない（先の無いリンクも）" || ng "APP_KEY の控え: シンボリックリンクを断らない（${bad17}）か、リンクの先に書いた"
# ほかの人が書ける置き場・バックアップの置き場・無い置き場を断る（何も作らない）
rc=0
out="$( (ec_app_key_export "$work/k17/open/copy") 2>&1)" || rc=$?
all17="$all17"$'\n'"$out"
[ "$rc" -ne 0 ] && [ ! -e "$work/k17/open/copy" ] && [[ "$out" == *'ほかの人が書けます'* ]] &&
    ok "APP_KEY の控え: ほかの人が書ける置き場には作らない" || ng "APP_KEY の控え: ほかの人が書ける置き場を断らない（rc=${rc}・${out}）"
rc=0
out="$( (ec_app_key_export "$work/k17/backup/2026-11-19/env") 2>&1)" || rc=$?
all17="$all17"$'\n'"$out"
[ "$rc" -ne 0 ] && [ ! -e "$work/k17/backup/2026-11-19/env" ] && [[ "$out" == *'バックアップの保存先'* ]] &&
    ok "APP_KEY の控え: バックアップの置き場の下には作らない（鍵と中身を同じ所に置かない）" || ng "APP_KEY の控え: バックアップの置き場を断らない（rc=${rc}・${out}）"
rc=0
out="$( (ec_app_key_export "$work/k17/none/copy") 2>&1)" || rc=$?
all17="$all17"$'\n'"$out"
[ "$rc" -ne 0 ] && [ ! -e "$work/k17/none" ] && ok "APP_KEY の控え: 無い置き場は作らずに断る" || ng "APP_KEY の控え: 無い置き場を断らない（rc=${rc}）"
# APP_KEY の無い env（入れている途中）は断る
printf 'APP_NAME=EC-SUGAR\nAPP_KEY=\n' > "$work/k17/env/empty"
rc=0
out="$( (EC_ENV_FILE="$work/k17/env/empty"; ec_app_key_export "$work/k17/out/empty-copy") 2>&1)" || rc=$?
[ "$rc" -ne 0 ] && [ ! -e "$work/k17/out/empty-copy" ] && ok "APP_KEY の控え: APP_KEY の無い設定のファイルは写さない" || ng "APP_KEY の控え: APP_KEY の無い設定のファイルを写した（rc=${rc}）"
# 画面（説明・写した時・断った時の全部）に、鍵の値もパスワードの値も出ない
n17="$(printf '%s\n' "$all17" | grep -c -F -e "$key17" -e "${key17#base64:}" -e "$pass17" || true)"
[ "$n17" -eq 0 ] && ok "APP_KEY の控え: 画面に鍵の値・パスワードの値が出ない（説明・写した時・断った時）" || ng "APP_KEY の控え: 画面に秘密の値が ${n17} 行出た"
# 貼り付けの履歴に残る道を勧めない（説明・台本・インストーラーの一言・文書）
if grep -n 'pbcopy' deploy/lib/common.sh deploy/ec-sugar deploy/install.sh > /dev/null 2>&1 || grep -n 'sudo cat /srv/ec-sugar/env' deploy/install.sh docs/install.md > /dev/null 2>&1; then
    ng "APP_KEY の控え: 鍵を画面や貼り付けの履歴に出す取り方（cat・pbcopy）を勧めている"
else
    ok "APP_KEY の控え: 鍵を画面や貼り付けの履歴に出す取り方を勧めていない"
fi
grep -q 'app-key) cmd_app_key' deploy/ec-sugar && grep -q 'ec_app_key_export "\$1"' deploy/ec-sugar && grep -q 'sudo ec-sugar app-key' deploy/install.sh &&
    ok "ec-sugar app-key があり、インストーラーの最後の一言がそれを指す" || ng "ec-sugar に app-key が無いか、インストーラーの一言が指していない"
umask "$umask17"
unset -f perm17
EC_ENV_FILE="$saved_env17"
EC_BACKUP="$saved_backup17"

# 戻す時の鍵のファイル: 鍵だけの 1 行でも、設定のファイルの写し（app-key export の控え）でも読める。形の違うものは中身を出さずに断る
mkdir -p "$work/t18"
key18="base64:$(printf 'k%.0s' $(seq 1 43))="
(umask 077; printf '%s\n' "$key18" > "$work/t18/one"; printf '# 設定\nAPP_NAME=x\nDB_PASSWORD=not-the-key\nAPP_KEY=%s\nREDIS_PASSWORD=y\n' "$key18" > "$work/t18/env"; printf 'APP_NAME=x\nDB_PASSWORD=not-the-key\n' > "$work/t18/nokey")
EC_RESTORE_KEY=""; ec_restore_key_read "$work/t18/one" && [ "$EC_RESTORE_KEY" = "$key18" ] && ok "戻す時の鍵: 鍵だけの 1 行を読む" || ng "戻す時の鍵: 1 行のファイルを読めない"
EC_RESTORE_KEY=""; ec_restore_key_read "$work/t18/env" && [ "$EC_RESTORE_KEY" = "$key18" ] && ok "戻す時の鍵: 設定のファイルの写しから APP_KEY の行を読む" || ng "戻す時の鍵: 設定のファイルの写しを読めない"
out="$( (ec_restore_key_read "$work/t18/nokey") 2>&1)" && ng "戻す時の鍵: APP_KEY の無いファイルを通した" || { [[ "$out" != *'not-the-key'* ]] && ok "戻す時の鍵: APP_KEY の無いファイルは、中身を出さずに断る" || ng "戻す時の鍵: 断る時に中身を出した"; }
unset EC_RESTORE_KEY key18

# ---------- 16. 中の照らし（ADR 0105） ----------
# 偽の docker と本物の openssl 3 で、控え・照らし・署名・busy・世代・外の見張り・知らせを確かめる（別のスクリプトで。同じ偽の部品の形）。
# サーバーと同じ道具（flock・GNU の date と stat・bash 4 以上）が要る。手元の Mac には無いので見送る（CI では必ず流れる）
if ! PATH="$start_path" bash -c 'command -v flock && date -u -d @0 && stat -c %Y / && [ "${BASH_VERSINFO[0]}" -ge 4 ]' > /dev/null 2>&1; then
    echo "見送り: 中の照らし（docker/check-integrity.sh）は flock・GNU の date と stat・bash 4 以上が要る（CI と Ubuntu で流す）"
elif PATH="$start_path" bash docker/check-integrity.sh "$lib" > "$work/integrity.out" 2>&1; then
    ok "中の照らし（docker/check-integrity.sh）: $(grep -c '^ok:' "$work/integrity.out") 項目"
else
    grep -E '^NG' "$work/integrity.out" | while IFS= read -r line; do echo "$line"; done
    ng "中の照らし（docker/check-integrity.sh）が通らない"
fi

# ---------- 18. サーバーの外のバックアップ（ADR 0110）の形 ----------
# 実際に送って取る確かめは docker/check-offsite.sh。ここは、置き場を設定していないサーバーで何も変わらないことと、形の見張り
saved_state17="$EC_STATE"
saved_answers17="$EC_ANSWERS"
saved_offsite_dir17="$EC_OFFSITE_DIR"
saved_offsite_work17="$EC_OFFSITE_WORK"
EC_STATE="$work/t17/state"
EC_ANSWERS="$work/t17/answers"
EC_OFFSITE_DIR="$work/t17/secrets"
EC_OFFSITE_WORK="$work/t17/work"
mkdir -p "$EC_STATE" "$work/t17/backup/20261119-041500"
: > "$EC_ANSWERS"
: > "$work/t17/backup/20261119-041500/.complete"
rc=0
out="$(ec_offsite_run 2>&1)" || rc=$?
out="$out$(ec_offsite_stage "$work/t17/backup/20261119-041500" 2>&1)" || rc=$?
[ "$rc" -eq 0 ] && [ -z "$out" ] && [ ! -e "$EC_OFFSITE_WORK" ] && [ ! -e "$EC_OFFSITE_DIR" ] && [ -z "$(ls -A "$EC_STATE")" ] && [[ "$(ec_report_state_args)" != *offsite* ]] &&
    ok "外のバックアップ: 置き場を設定していなければ、送る回も控えの段も何も作らず・何も出さず、報告の引数も増えない" || ng "外のバックアップ: 設定が無いのに何かをした（rc=${rc} ${out}）"
for f in ec-sugar-offsite.service ec-sugar-offsite.timer; do
    [ -f "deploy/systemd/$f" ] && [[ " $EC_INTEGRITY_OWN_UNITS " == *" $f "* ]] || ng "外のバックアップ: unit $f が無いか、中の照らしの自分の unit の一覧に無い"
done
grep -q '^ExecStart=/usr/local/bin/ec-sugar offsite run$' deploy/systemd/ec-sugar-offsite.service && grep -q '^OnCalendar=' deploy/systemd/ec-sugar-offsite.timer && grep -q '^WantedBy=timers.target$' deploy/systemd/ec-sugar-offsite.timer &&
    sed -n '/^ec_install_tools() {/,/^}/p' "$lib" | q 'ec_install_offsite_units "\$from"' && grep -q 'enable --now ec-sugar-offsite.timer' "$lib" &&
    ok "外のバックアップ: unit は install-units が入れて有効にし、中の照らしは自分の unit として控え直す" || ng "外のバックアップ: unit を入れていない"
grep -q 'offsite) cmd_offsite' deploy/ec-sugar && sed -n '/^cmd_backup() {/,/^}/p' deploy/ec-sugar | q 'ec_offsite_stage' && ! sed -n '/^ec_backup() {/,/^}/p' "$lib" | q offsite &&
    ok "外のバックアップ: 控えを作るのは ec-sugar backup（毎朝と手で）だけ。更新の前・戻す前のバックアップ（ec_backup を直に呼ぶ）は送らない" || ng "外のバックアップ: 控えを作る所が違う"
keys17="$(sed -n 's/^EC_ANSWER_KEYS="\(.*\)"$/\1/p' deploy/install.sh)"
miss17=""
for f in OFFSITE_ENDPOINT OFFSITE_BUCKET OFFSITE_PREFIX OFFSITE_REGION OFFSITE_KEY_FILE OFFSITE_RECIPIENT_FILE OFFSITE_ENCRYPT OFFSITE_LOCK_CHECK; do
    [[ " $keys17 " == *" $f "* ]] || miss17="$miss17 $f"
done
[ -z "$miss17" ] && grep -q '^    ec_offsite_check_answers$' deploy/install.sh && grep -q '^    install_offsite_secrets$' deploy/install.sh &&
    ok "外のバックアップ: インストーラーが答え（OFFSITE_…）を読み、何も入れる前に確かめ、鍵を置き場へ写す" || ng "外のバックアップ: インストーラーの答えが足りない（${miss17}）"
ec_offsite_valid_endpoint https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com && ec_offsite_valid_endpoint https://s3.example.com:9000 &&
    ! ec_offsite_valid_endpoint http://s3.example.com && ! ec_offsite_valid_endpoint https://s3.example.com/path && ! ec_offsite_valid_endpoint 'https://u:p@s3.example.com' &&
    ! ec_offsite_valid_endpoint 'https://s3.example.com?x=1' && ! ec_offsite_valid_endpoint 'https://s3.example.com" -o /tmp/x "' &&
    ec_offsite_valid_bucket ec-sugar-offsite && ! ec_offsite_valid_bucket 'a/b' && ! ec_offsite_valid_bucket AB && ec_offsite_valid_prefix admin.example.com && ! ec_offsite_valid_prefix 'a/b' && ! ec_offsite_valid_prefix '..' &&
    ok "外のバックアップ: 入口は https://<名前>[:ポート] だけ（http・道筋・利用者・引用符を断る）。バケットとサーバーの名前に / を書けない" || ng "外のバックアップ: 入口・バケット・名前の形の確かめが違う"
printf 'OFFSITE_ENDPOINT=https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com/\nOFFSITE_BUCKET=ec-sugar-offsite\nOFFSITE_PREFIX=admin.example.com\n' > "$EC_ANSWERS"
ec_offsite_conf_load
r2lock17="$EC_OFFSITE_LOCK_CHECK$EC_OFFSITE_ENCRYPT$EC_OFFSITE_REGION"
ec_offsite_conf_check || ng "外のバックアップ: R2 の入口の答えを断った（${EC_OFFSITE_PROBLEM}）"
printf 'OFFSITE_ENDPOINT=https://s3.example.com\nOFFSITE_BUCKET=ec-sugar-offsite\nOFFSITE_PREFIX=admin.example.com\n' > "$EC_ANSWERS"
ec_offsite_conf_load
[ "$r2lock17" = yesyesauto ] && [ "$EC_OFFSITE_LOCK_CHECK" = no ] && ok "外のバックアップ: 既定は暗号化する・リージョン auto。ロックの確かめは R2 の入口なら入れ、ほかの置き場では入れない" || ng "外のバックアップ: 既定が違う（${r2lock17} ${EC_OFFSITE_LOCK_CHECK}）"
id17=abcdefghij0123456789
sec17="$(printf 'S%.0s' 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20 21 22 23 24 25 26 27 28 29 30 31 32)"
cred17() { printf "$@" > "$work/t17/cred"; rc=0; ec_offsite_cred_read "$work/t17/cred" || rc=$?; got17="$EC_OFFSITE_ID:$EC_OFFSITE_SECRET"; EC_OFFSITE_ID=""; EC_OFFSITE_SECRET=""; }
cred17 'access_key_id=%s\nsecret_access_key=%s\n' "$id17" "$sec17"
a17="$rc $got17"
cred17 '  %s  \r\n\n%s\n' "$id17" "$sec17"
b17="$rc $got17"
cred17 'access_key_id=%s\nsecret_access_key="%s"\n' "$id17" "$sec17"
c17="$rc $got17"
cred17 '%s\n%s\nextra\n' "$id17" "$sec17"
d17="$rc $got17"
cred17 'access_key_id=%s\nsecret_access_key=%s\\\n' "$id17" "$sec17"
e17="$rc $got17"
rc=0
ec_offsite_cred_read "$work/t17/none" || rc=$?
[ "$a17" = "0 $id17:$sec17" ] && [ "$b17" = "0 $id17:$sec17" ] && [ "$c17" = "1 :" ] && [ "$d17" = "1 :" ] && [ "$e17" = "1 :" ] && [ "$rc" -eq 2 ] &&
    ok "外のバックアップ: 置き場の鍵は 2 つの書き方で読め、引用符・バックスラッシュ・余分な行は断る（curl の設定の引用符の中に入れるため）" || ng "外のバックアップ: 鍵の読み方が違う（${a17%%:*} ${b17%%:*} ${c17} ${d17} ${e17} ${rc}）"
[ "$(ec_offsite_urlencode 'db/admin.example.com/2026 +=')" = 'db%2Fadmin.example.com%2F2026%20%2B%3D' ] && ok "外のバックアップ: 一覧の問い合わせの値は %XX に直す" || ng "外のバックアップ: URL の直し方が違う"
grep -q '^ConditionPathExists=/srv/ec-sugar/secrets/offsite/credentials$' deploy/systemd/ec-sugar-offsite.service && grep -q '^ConditionPathExists=/srv/ec-sugar/secrets/offsite/credentials$' deploy/systemd/ec-sugar-offsite.timer &&
    grep -q '^TimeoutStartSec=infinity$' deploy/systemd/ec-sugar-offsite.service &&
    ok "外のバックアップ: 置き場を設定していないサーバーでは、条件でタイマーも送る回も動かさない。送る回の待ち時間は決めない（止まった送信は curl が切る）" || ng "外のバックアップ: unit の条件か待ち時間が違う"
sed -n '/^cmd_status() {/,/^}/p' deploy/ec-sugar | grep -A3 'if ec_offsite_configured; then' | q 'サーバーの外へ' &&
    sed -n '/^offsite_test() {/,/^}/p' deploy/ec-sugar | q '! ec_is_isolated || ec_die' &&
    sed -n '/^ec_install_offsite_units() {/,/^}/p' "$lib" | grep -A2 'if ec_offsite_configured; then' | q 'ec_warn' &&
    ok "外のバックアップ: status に 1 行（設定した時だけ）。隔離した写しでは test を断る。unit を外す時は、設定があれば知らせる" || ng "外のバックアップ: status の行・隔離の断り・unit を外す時の知らせのどれかが無い"
# 運営の様子への報告の引数。鍵のファイルが無ければ、state が残っていても 1 つも出さない（設定を外したら札も困りごとも消える）
state18() { printf "$@" > "$EC_STATE/offsite"; }
offsite_args18() { ec_report_state_args | grep -- '--offsite' | tr '\n' ' ' || true; }
rm -rf "$EC_OFFSITE_DIR"
state18 'offsite=failed\noffsite_tried_at=2026-11-20T04:25:00+09:00\noffsite_at=2026-11-19T04:25:00+09:00\nlast_backup=20261119-041500\nreason=put\noffsite_lock=open\noffsite_heads=ok\noffsite_heads_at=2026-11-20T04:25:00+09:00\n'
none18="$(offsite_args18)"
mkdir -p "$EC_OFFSITE_DIR"
: > "$EC_OFFSITE_DIR/credentials"
args18="$(offsite_args18)"
[ -z "$none18" ] && [ "$args18" = "--offsite=failed --offsite-tried-at=2026-11-20T04:25:00+09:00 --offsite-at=2026-11-19T04:25:00+09:00 --offsite-reason=put --offsite-lock=open --offsite-heads=ok --offsite-heads-at=2026-11-20T04:25:00+09:00 " ] &&
    ok "外のバックアップ: 置き場を設定したサーバーだけ、結果を --offsite… で運営の様子へ渡す（鍵のファイルが無ければ出さない。バックアップの名前は渡さない）" || ng "外のバックアップ: 報告の引数が違う（設定なし「${none18}」・設定あり「${args18}」）"
state18 'offsite=ok\noffsite_tried_at=2026-11-20T04:25:00+09:00\noffsite_at=2026-11-20T04:25:00+09:00\nlast_backup=20261120-041500\nreason=\n'
args18="$(offsite_args18)"
[ "$args18" = "--offsite=ok --offsite-tried-at=2026-11-20T04:25:00+09:00 --offsite-at=2026-11-20T04:25:00+09:00 " ] &&
    ok "外のバックアップ: 送れた回は結果と時刻だけ（理由・確かめていないロック・先頭は渡さない）" || ng "外のバックアップ: 送れた回の報告の引数が違う（${args18}）"
state18 'offsite=failed\noffsite_tried_at=2026-11-20T04:25:00+09:00\noffsite_at=yesterday\nreason=put https://s3.example.com/bucket\noffsite_lock=locked\noffsite_heads=skip\noffsite_heads_at=2026-11-20T04:25:00+09:00\n'
args18="$(offsite_args18)"
state18 'offsite=failed\noffsite_tried_at=2026-11-20T04:25:00+09:00\nreason=put gave-up\n'
args18="$args18|$(offsite_args18)"
[ "$args18" = "--offsite=failed --offsite-tried-at=2026-11-20T04:25:00+09:00 |--offsite=failed --offsite-tried-at=2026-11-20T04:25:00+09:00 " ] &&
    ok "外のバックアップ: 形の違う値（時刻でない値・文の付いた理由・語を 2 つ並べた理由・知らないロックと先頭の語）は渡さない" || ng "外のバックアップ: 形の違う値を渡した（${args18}）"
state18 'offsite=sent\noffsite_tried_at=2026-11-20T04:25:00+09:00\n'
args18="$(offsite_args18)"
state18 'offsite=ok\noffsite_tried_at=04:25\noffsite_at=2026-11-20T04:25:00+09:00\n'
args18="$args18$(offsite_args18)"
[ -z "$args18" ] && ok "外のバックアップ: 結果の語か最後に試した時刻の形が違えば、1 つも渡さない" || ng "外のバックアップ: 形の違う結果を渡した（${args18}）"
# --offsite… を知らない前のバージョンのアプリ（ロールバックの後）は報告ごと断る。新しい値から順に外して渡し直す
state18 'offsite=failed\noffsite_tried_at=2026-11-20T04:25:00+09:00\noffsite_at=2026-11-19T04:25:00+09:00\nreason=put\noffsite_lock=open\n'
printf 'time=2026-11-20T04:15:00+09:00\nresult=ok\n' > "$EC_STATE/last_backup"
printf 'ok 2026-11-20T04:20:00+09:00\n' > "$EC_STATE/tunnel_watch"
printf 'ok 2026-11-20T04:20:00Z\n' > "$EC_STATE/integrity"
REFUSE18=""
ec_dc() {
    local word
    case "$1" in
        ps) printf 'app\n' ;;
        exec)
            printf '%s\n' "$*" >> "$work/report18.log"
            for word in $REFUSE18; do
                case "$*" in *"$word"*) return 1 ;; esac
            done
            ;;
    esac
    return 0
}
report18() { REFUSE18="$1"; rm -f "$work/report18.log"; rc=0; out="$(ec_host_report 2>&1)" || rc=$?; lines18="$(wc -l < "$work/report18.log" | tr -d ' ')"; last18="$(tail -n1 "$work/report18.log")"; }
report18 ""
[ "$rc" -eq 0 ] && [ "$lines18" = 1 ] && [[ "$last18" == *"--offsite=failed --offsite-tried-at=2026-11-20T04:25:00+09:00 --offsite-at=2026-11-19T04:25:00+09:00 --offsite-reason=put --offsite-lock=open"* ]] && [[ "$out" != *外して* ]] &&
    ok "外のバックアップ: --offsite… を知るアプリには 1 回で渡す" || ng "外のバックアップ: 報告の渡し方が違う（rc=${rc} ${lines18} 回 ${out}）"
report18 "--offsite"
[ "$rc" -eq 0 ] && [ "$lines18" = 2 ] && [[ "$last18" != *--offsite* ]] && [[ "$last18" == *--integrity=ok* ]] && [[ "$last18" == *--tunnel=ok* ]] && [[ "$last18" == *--backup-result=ok* ]] &&
    [[ "$out" == *"サーバーの外のバックアップの値を外して渡しました"* ]] &&
    ok "外のバックアップ: 前のバージョンのアプリに --offsite… を断られたら、その値だけを外して渡し直す（中の照らしとトンネルは残す）" || ng "外のバックアップ: 渡し直しが違う（rc=${rc} ${lines18} 回 ${last18} ${out}）"
report18 "--offsite --integrity"
[ "$rc" -eq 0 ] && [ "$lines18" = 3 ] && [[ "$last18" != *--offsite* ]] && [[ "$last18" != *--integrity* ]] && [[ "$last18" == *--tunnel=ok* ]] && [[ "$last18" == *--backup-result=ok* ]] &&
    [[ "$out" == *"サーバーの外のバックアップ・内部の整合性チェックと外部監視の値を外して渡しました"* ]] &&
    ok "外のバックアップ: もっと前のバージョンには、中の照らしの値も外して渡し直す" || ng "外のバックアップ: 2 段目の渡し直しが違う（rc=${rc} ${lines18} 回 ${last18} ${out}）"
report18 "--offsite --integrity --tunnel"
[ "$rc" -eq 0 ] && [ "$lines18" = 4 ] && [[ "$last18" != *--offsite* ]] && [[ "$last18" != *--integrity* ]] && [[ "$last18" != *--tunnel* ]] && [[ "$last18" == *--backup-result=ok* ]] &&
    ok "外のバックアップ: さらに前のバージョンには、トンネルの見張りの値も外して渡し直す" || ng "外のバックアップ: 3 段目の渡し直しが違う（rc=${rc} ${lines18} 回 ${last18} ${out}）"
report18 "operations:host-report"
[ "$rc" -ne 0 ] && [ "$lines18" = 4 ] && ok "外のバックアップ: どの形でも断られたら 1（同じ報告を 2 回渡さない）" || ng "外のバックアップ: 断られ続けた時の形が違う（rc=${rc} ${lines18} 回）"
rm -f "$EC_OFFSITE_DIR/credentials"
report18 "--integrity"
[ "$rc" -eq 0 ] && [ "$lines18" = 2 ] && [[ "$last18" == *--tunnel=ok* ]] && [[ "$out" == *"報告を、内部の整合性チェックと外部監視の値を外して渡しました"* ]] &&
    ok "外のバックアップ: 置き場を設定していないサーバーの渡し直しは今までどおり（外すものの無い段は飛ばす）" || ng "外のバックアップ: 設定なしの渡し直しが変わった（rc=${rc} ${lines18} 回 ${out}）"
# 送る回の終わり: 渡す値が前の回と変わった時だけ報告する。渡せなければ印を残して次の回に渡し直す。置き場を設定していなければ何もしない
: > "$EC_OFFSITE_DIR/credentials"
REFUSE18=""
rm -f "$work/report18.log"
before18="$(ec_offsite_report_key)"
ec_offsite_report_if_changed "$before18"
same18="$([ -e "$work/report18.log" ] && echo 報告した || echo しない)"
state18 'offsite=failed\noffsite_tried_at=2026-11-20T04:35:00+09:00\noffsite_at=2026-11-19T04:25:00+09:00\nreason=put\noffsite_lock=open\n'
ec_offsite_report_if_changed "$before18"
tried18="$([ -e "$work/report18.log" ] && echo 報告した || echo しない)"
state18 'offsite=ok\noffsite_tried_at=2026-11-20T04:45:00+09:00\noffsite_at=2026-11-20T04:45:00+09:00\nreason=\noffsite_lock=open\n'
ec_offsite_report_if_changed "$before18"
[ "$same18$tried18" = しないしない ] && [ "$(wc -l < "$work/report18.log" | tr -d ' ')" = 1 ] && [ ! -e "$EC_STATE/offsite_report_pending" ] &&
    ok "外のバックアップ: 送る回は、渡す値が変わった時だけ報告する（最後に試した時刻だけが動いた回は報告しない）" || ng "外のバックアップ: 報告の頃合いが違う（同じ: ${same18}・時刻だけ: ${tried18}）"
REFUSE18="operations:host-report"
before18="$(ec_offsite_report_key)"
state18 'offsite=failed\noffsite_tried_at=2026-11-20T04:55:00+09:00\noffsite_at=2026-11-20T04:45:00+09:00\nreason=gave-up\noffsite_lock=open\n'
rc=0
ec_offsite_report_if_changed "$before18" || rc=$?
pending18="$([ -e "$EC_STATE/offsite_report_pending" ] && echo 残した || echo 無い)"
REFUSE18=""
rm -f "$work/report18.log"
ec_offsite_report_if_changed "$(ec_offsite_report_key)"
[ "$rc" -eq 0 ] && [ "$pending18" = 残した ] && [ "$(wc -l < "$work/report18.log" | tr -d ' ')" = 1 ] && [ ! -e "$EC_STATE/offsite_report_pending" ] &&
    ok "外のバックアップ: 報告を渡せなくても送る回は失敗にせず、印を残して次の回に渡し直す" || ng "外のバックアップ: 渡せなかった報告の渡し直しが違う（rc=${rc} 印: ${pending18}）"
rm -f "$EC_OFFSITE_DIR/credentials" "$work/report18.log"
state18 'offsite=failed\noffsite_tried_at=2026-11-20T05:05:00+09:00\nreason=config\n'
: > "$EC_STATE/offsite_report_pending"
ec_offsite_report_if_changed ""
[ ! -e "$work/report18.log" ] && sed -n '/^cmd_offsite() {/,/^}/p' deploy/ec-sugar | q 'ec_offsite_report_if_changed "\$before" || true' &&
    ok "外のバックアップ: 置き場を設定していなければ報告しない。ec-sugar offsite run が回の終わりに呼ぶ" || ng "外のバックアップ: 設定が無いのに報告したか、送る回が呼んでいない"
rm -f "$EC_STATE/offsite" "$EC_STATE/offsite_report_pending" "$EC_STATE/last_backup" "$EC_STATE/tunnel_watch" "$EC_STATE/integrity"
unset -f cred17 state18 offsite_args18 report18
EC_STATE="$saved_state17"
EC_ANSWERS="$saved_answers17"
EC_OFFSITE_DIR="$saved_offsite_dir17"
EC_OFFSITE_WORK="$saved_offsite_work17"

# ---------- 19. 復元のキット（ADR 0110 の「作って決めたこと」） ----------
# 持ち主の手元の道具（deploy/recovery-kit）で作り物の値のキットを作り、サーバーの側の部品（ec_kit_open）で開いて APP_KEY を読む。
# gpg の鍵束は使い捨て（道筋は短く。長いと gpg-agent の口を作れない）。実際に置き場から取る確かめは docker/check-offsite.sh
if ! command -v gpg > /dev/null 2>&1 || ! command -v python3 > /dev/null 2>&1 || ! command -v jq > /dev/null 2>&1; then
    ng "復元のキット: gpg・python3・jq のどれかが無いので確かめられない（黙って飛ばさない）"
else
    k19="$(mktemp -d /tmp/eck19.XXXXXX)"
    trap 'gpgconf --homedir "$k19/g" --kill all > /dev/null 2>&1 || true; rm -rf "$work" "$k19"' EXIT
    saved_lock19="$EC_LOCK"
    EC_LOCK="$k19/run/lock"
    mkdir -m 700 "$k19/g" "$k19/h"
    kit19() { PATH="$start_path" GNUPGHOME="$k19/g" bash deploy/recovery-kit "$@"; }
    tool19() { PATH="$start_path" bash deploy/ec-sugar "$@"; }
    keypass19='key passphrase check 19'
    kitpass19='kit passphrase check 19'
    id19=kitcheckaccess0019
    secret19="KitCheckSecret$(printf 'Qq%.0s' 1 2 3 4 5 6 7 8)"
    app19="base64:$(printf 'k%.0s' $(seq 1 43))="
    leak19() { printf '%s\n' "$1" | grep -c -F -e "$secret19" -e "$id19" -e "$app19" -e "${app19#base64:}" -e "$kitpass19" -e "$keypass19" || true; }
    gpg --homedir "$k19/g" --batch --quiet --pinentry-mode loopback --passphrase "$keypass19" --quick-generate-key 'kit <kit@check-deploy.test>' ed25519 cert never > /dev/null 2>&1 || true
    fpr19="$(gpg --homedir "$k19/g" --batch --with-colons --list-keys 2> /dev/null | awk -F: '$1 == "fpr" {print $10; exit}')"
    gpg --homedir "$k19/g" --batch --quiet --pinentry-mode loopback --passphrase "$keypass19" --quick-add-key "$fpr19" cv25519 encr never > /dev/null 2>&1 || true
    (umask 077; printf 'access_key_id=%s\nsecret_access_key=%s\n' "$id19" "$secret19" > "$k19/read"; printf 'APP_NAME=EC-SUGAR\nAPP_KEY=%s\nDB_PASSWORD=not-the-key\n' "$app19" > "$k19/env"; printf '%s\n' "$kitpass19" > "$k19/pp")
    args19=(--server admin.check-deploy.test --endpoint https://s3.check-deploy.test --bucket kit-check --gpg-key "$fpr19")

    # 引数の確かめ（何も聞く前に、使い方の誤りの 2 で終わる。何も作らない）
    bad19=""
    for a in "" "bogus" "check" "create" "create $k19/x.gpg --server admin.check-deploy.test --endpoint https://s3.check-deploy.test --gpg-key $fpr19" \
        "create $k19/x.gpg ${args19[*]} --endpoint http://s3.check-deploy.test" "create $k19/x.gpg ${args19[*]} --endpoint https://s3.check-deploy.test/path?x=1" \
        "create $k19/x.gpg ${args19[*]} --endpoint https://user:pw@s3.check-deploy.test" "create $k19/x.gpg ${args19[*]} --bucket Bad_Bucket" \
        "create $k19/x.gpg ${args19[*]} --server a/../b" "create $k19/x.gpg ${args19[*]} --region Bad" "create $k19/x.gpg ${args19[*]} --passphrase x" \
        "create $k19/x.gpg $k19/y.gpg ${args19[*]}" "check $k19/x.gpg --unknown"; do
        rc=0
        # shellcheck disable=SC2086
        kit19 $a < /dev/null > "$k19/out" 2>&1 || rc=$?
        [ "$rc" -eq 2 ] || bad19="${bad19} [${a}]=${rc}"
    done
    [ -z "$bad19" ] && [ ! -e "$k19/x.gpg" ] && [ ! -e "$k19/y.gpg" ] && ok "復元のキット: recovery-kit は、引数の誤り（足りない・知らない・形の違う入口やバケットや名前・合い言葉を引数で渡す）を 2 で断り、何も作らない" ||
        ng "復元のキット: recovery-kit が引数の誤りを断らない（${bad19}）"

    # 作る（読む鍵と APP_KEY はファイルで・合い言葉は標準入力で）→ 0600・値は画面に出ない・ファイルに平文が無い
    rc=0
    out="$(printf '%s\n%s\n%s\n' "$keypass19" "$kitpass19" "$kitpass19" | kit19 create "$k19/kit.gpg" "${args19[@]}" --read-key-file "$k19/read" --app-key-file "$k19/env" 2>&1)" || rc=$?
    [ "$rc" -eq 0 ] && [ -n "$(find "$k19/kit.gpg" -prune -perm 0600 -print 2> /dev/null)" ] && [[ "$out" == *"秘密鍵の指紋    ${fpr19}"* ]] && [[ "$out" == *'置き方:'* ]] && [ "$(leak19 "$out")" = 0 ] &&
        ! grep -a -q -F -e "$id19" -e 'kit-check' -e 'BEGIN PGP' -e 'recovery-kit' "$k19/kit.gpg" &&
        ok "復元のキット: recovery-kit create が、本人だけの 0600 のキットを作る（画面に鍵・合い言葉・APP_KEY が出ない・ファイルに平文が無い・置き方を出す）" ||
        ng "復元のキット: recovery-kit create が違う（rc=${rc}・${out}）"
    rc=0
    out="$(printf '%s\n%s\n%s\n' "$keypass19" "$kitpass19" "$kitpass19" | kit19 create "$k19/kit.gpg" "${args19[@]}" --read-key-file "$k19/read" --app-key-file "$k19/env" 2>&1)" || rc=$?
    [ "$rc" -eq 1 ] && [[ "$out" == *'もうあります（上書きしません）'* ]] && ok "復元のキット: もうあるファイルは上書きしない" || ng "復元のキット: もうあるファイルを断らない（rc=${rc}）"
    rc=0
    out="$(printf '%s\n%s\n%s\n' "$keypass19" 'short' 'short' | kit19 create "$k19/short.gpg" "${args19[@]}" --read-key-file "$k19/read" --app-key-file "$k19/env" 2>&1)" || rc=$?
    [ "$rc" -eq 1 ] && [ ! -e "$k19/short.gpg" ] && [[ "$out" == *'短すぎます'* ]] && ok "復元のキット: 短い合い言葉のキットは作らない" || ng "復元のキット: 短い合い言葉を通した（rc=${rc}）"
    rc=0
    out="$(printf '%s\n%s\n%s\n' 'not the key passphrase' "$kitpass19" "$kitpass19" | kit19 create "$k19/wrong.gpg" "${args19[@]}" --read-key-file "$k19/read" --app-key-file "$k19/env" 2>&1)" || rc=$?
    [ "$rc" -eq 1 ] && [ ! -e "$k19/wrong.gpg" ] && [[ "$out" == *'秘密鍵'* ]] && ok "復元のキット: 開ける鍵の合い言葉が違えば、キットを作らない（戻す時に開けられないキットを作らない）" || ng "復元のキット: 違う開ける鍵の合い言葉を通した（rc=${rc}・${out}）"
    rc=0
    out="$(printf '%s\n' "$kitpass19" | kit19 check "$k19/kit.gpg" 2>&1)" || rc=$?
    [ "$rc" -eq 0 ] && [[ "$out" == *'サーバーの名前  admin.check-deploy.test'* ]] && [[ "$out" == *'https://s3.check-deploy.test（バケット kit-check・リージョン auto）'* ]] && [[ "$out" == *"${fpr19}（パスフレーズ あり）"* ]] && [ "$(leak19 "$out")" = 0 ] &&
        ok "復元のキット: recovery-kit check が、入っているもの・サーバーの名前・入口・バケット・鍵の指紋を見せる（値は出さない）" || ng "復元のキット: recovery-kit check が違う（rc=${rc}・${out}）"
    rc=0
    out="$(printf '%s\n' 'not the kit passphrase' | kit19 check "$k19/kit.gpg" 2>&1)" || rc=$?
    [ "$rc" -eq 1 ] && [[ "$out" == *'キットを復号できません'* ]] && ok "復元のキット: recovery-kit check は、合い言葉が違えば開けない" || ng "復元のキット: 違う合い言葉で開けた（rc=${rc}）"
    [ -z "$(find /tmp -maxdepth 1 -name 'ec-kit.*' -user "$(id -u)" 2> /dev/null)" ] && ok "復元のキット: recovery-kit は gpg の一時の鍵束を残さない" || ng "復元のキット: gpg の一時の鍵束が /tmp に残った"

    # サーバーの側: キットを開いて APP_KEY を読む（restore --kit の読み方）。開いた中身は錠の置き場の下にだけ置き、消した後は残らない
    left19() { find "$k19/run" -mindepth 1 ! -name lock 2> /dev/null | head -n 5 | tr '\n' ' '; }
    EC_RESTORE_KEY=""
    out="$( (trap 'ec_kit_drop' EXIT; ec_kit_open "$k19/kit.gpg" "$k19/pp" && ec_restore_key_read "$EC_KIT_DIR/app-key" && [ "$EC_RESTORE_KEY" = "$app19" ] && [[ "$EC_KIT_DIR" == "$k19/run/kit."* ]] &&
        [ "$(cat "$EC_KIT_DIR/credentials")" = "access_key_id=$id19"$'\n'"secret_access_key=$secret19" ] && [ "$(cat "$EC_KIT_DIR/passphrase")" = "$keypass19" ] &&
        [ "$(head -n1 "$EC_KIT_DIR/decrypt-key.asc")" = '-----BEGIN PGP PRIVATE KEY BLOCK-----' ] && [ ! -e "$EC_KIT_DIR/kit.json" ] && [ -z "$(find "$EC_KIT_DIR" -type f \( -perm -040 -o -perm -004 \))" ] &&
        [ "$EC_KIT_SERVER|$EC_KIT_ENDPOINT|$EC_KIT_BUCKET|$EC_KIT_REGION" = 'admin.check-deploy.test|https://s3.check-deploy.test|kit-check|auto' ] && echo read-ok) 2>&1)" || true
    [[ "$out" == *read-ok ]] && [ "$(leak19 "$out")" = 0 ] && [ -z "$(left19)" ] &&
        ok "復元のキット: サーバーの側がキットを開いて APP_KEY・読む鍵・開ける鍵と合い言葉・入口を読む。平文の JSON は残さず、消した後は何も残らない" || ng "復元のキット: サーバーの側がキットを読めない・残した（${out}・$(left19)）"
    # 端末で聞く道（確かめでは、端末だと答える部品に差し替えて、合い言葉を標準入力で渡す）
    out="$( (trap 'ec_kit_drop' EXIT; ec_kit_tty() { true; }; ec_kit_open "$k19/kit.gpg" "" <<< "$kitpass19" && ec_restore_key_read "$EC_KIT_DIR/app-key" && [ "$EC_RESTORE_KEY" = "$app19" ] && echo read-ok) 2>&1)" || true
    [[ "$out" == *read-ok ]] && [ "$(leak19 "$out")" = 0 ] && [ -z "$(left19)" ] && ok "復元のキット: 合い言葉を端末で聞いて開ける（画面に出さない）" || ng "復元のキット: 端末で聞く道で開けない（${out}）"
    out="$( (trap 'ec_kit_drop' EXIT; ec_kit_open "$k19/kit.gpg" "" < /dev/null) 2>&1)" && ng "復元のキット: 端末が無く合い言葉のファイルも無いのに通した" ||
        { [[ "$out" == *'--kit-passphrase-file'* ]] && [ -z "$(left19)" ] && ok "復元のキット: 端末が無ければ、合い言葉のファイル（--kit-passphrase-file）を求めて止まる" || ng "復元のキット: 端末なしの断り方が違う（${out}）"; }
    # 合い言葉が違う・ほかの人が読めるキットと合い言葉のファイル
    (umask 077; printf 'not the kit passphrase\n' > "$k19/pp-wrong")
    out="$( (trap 'ec_kit_drop' EXIT; ec_kit_open "$k19/kit.gpg" "$k19/pp-wrong") 2>&1)" && ng "復元のキット: 違う合い言葉で開けた" ||
        { [[ "$out" == *'復号できません'* ]] && [ "$(leak19 "$out")" = 0 ] && [ -z "$(left19)" ] && ok "復元のキット: 合い言葉が違えば開けず、何も残さない" || ng "復元のキット: 違う合い言葉の断り方が違う・残した（${out}・$(left19)）"; }
    cp "$k19/kit.gpg" "$k19/loose.gpg"
    chmod 0644 "$k19/loose.gpg"
    cp "$k19/pp" "$k19/pp-loose"
    chmod 0640 "$k19/pp-loose"
    out="$( (trap 'ec_kit_drop' EXIT; ec_kit_open "$k19/loose.gpg" "$k19/pp") 2>&1)" && ng "復元のキット: ほかの人が読めるキットを通した" ||
        { [[ "$out" == *'ほかの利用者から読めます'* ]] && ok "復元のキット: ほかの人が読めるキットのファイルは断る" || ng "復元のキット: 読めるキットの断り方が違う（${out}）"; }
    out="$( (trap 'ec_kit_drop' EXIT; ec_kit_open "$k19/kit.gpg" "$k19/pp-loose") 2>&1)" && ng "復元のキット: ほかの人が読める合い言葉のファイルを通した" ||
        { [[ "$out" == *'パスフレーズのファイル'*'ほかの利用者から読めます'* ]] && ok "復元のキット: ほかの人が読める合い言葉のファイルは断る" || ng "復元のキット: 読める合い言葉のファイルの断り方が違う（${out}）"; }
    # 形の違うキット（同じ合い言葉で包み直したもの）: 知らない欄・format 違い・欄が足りない・値の形・JSON でない・JSON が 2 つ。暗号化していない平文も
    good19="$(gpg --homedir "$k19/h" --batch --no-options --quiet --pinentry-mode loopback --passphrase-file "$k19/pp" --decrypt "$k19/kit.gpg" 2> /dev/null || true)"
    seal19() { (umask 077; gpg --homedir "$k19/h" --batch --no-options --quiet --pinentry-mode loopback --passphrase-file "$k19/pp" --symmetric --cipher-algo AES256 --output "$k19/bad-$1.gpg" 2> /dev/null); }
    printf '%s' "$good19" | jq -c '. + {"note": "x"}' | seal19 extra
    printf '%s' "$good19" | jq -c '.format = 2' | seal19 format
    printf '%s' "$good19" | jq -c '.format = "1"' | seal19 format-text
    printf '%s' "$good19" | jq -c 'del(.app_key)' | seal19 missing
    printf '%s' "$good19" | jq -c '.offsite.session_token = "x"' | seal19 offsite
    printf '%s' "$good19" | jq -c '.app_key = "base64:short"' | seal19 app-key
    printf '%s' "$good19" | jq -c '.offsite.endpoint = "http://s3.check-deploy.test"' | seal19 http
    printf '%s' "$good19" | jq -c '.offsite.secret_access_key = "bad\"quote-0123456789"' | seal19 secret
    printf '%s' "$good19" | jq -c '.server = "a/../b"' | seal19 server
    printf '%s' "$good19" | jq -c '.decrypt_key = "not a key"' | seal19 key
    printf '%s' "$good19" | jq -c '.kind = "something-else"' | seal19 kind
    printf 'not json at all\n' | seal19 nojson
    printf '%s\n%s\n' "$good19" "$good19" | seal19 twice
    (umask 077; printf '%s' "$good19" > "$k19/bad-plain.gpg"; gpg --homedir "$k19/h" --batch --no-options --quiet --compress-algo none --store --output "$k19/bad-store.gpg" "$k19/bad-plain.gpg" 2> /dev/null)
    bad19=""
    for name in extra format format-text missing offsite app-key http secret server key kind nojson twice plain store; do
        [ -s "$k19/bad-$name.gpg" ] || { bad19="${bad19} ${name}（作れない）"; continue; }
        out="$( (trap 'ec_kit_drop' EXIT; ec_kit_open "$k19/bad-$name.gpg" "$k19/pp") 2>&1)" && { bad19="${bad19} ${name}（通した）"; continue; }
        { [[ "$out" == *'止めました'* ]] && [ "$(leak19 "$out")" = 0 ] && [ -z "$(left19)" ]; } || bad19="${bad19} ${name}（中身を出した・残した）"
    done
    [ -n "$good19" ] && [ -z "$bad19" ] && ok "復元のキット: 形の違うキット（知らない欄・format 違い・欄が足りない・値の形・http の入口・JSON でない・2 つ・平文・暗号でない包み）を、中身を出さずに断り、何も残さない" ||
        ng "復元のキット: 形の違うキットを断らない（${bad19}）"
    # 形は合っているが、開ける鍵の合い言葉が違うキット（控えが正しくない）は、recovery-kit check が開ける鍵を試して気づく
    printf '%s' "$good19" | jq -c '.decrypt_key_passphrase = "not the key passphrase"' | seal19 keypass
    rc=0
    out="$(printf '%s\n' "$kitpass19" | kit19 check "$k19/bad-keypass.gpg" 2>&1)" || rc=$?
    [ "$rc" -eq 1 ] && [[ "$out" == *'秘密鍵で、暗号化した印を復号できません'* ]] && [ "$(leak19 "$out")" = 0 ] &&
        ok "復元のキット: recovery-kit check は、開ける鍵の合い言葉が違うキット（戻す時に開けられない控え）に気づく" || ng "復元のキット: 開ける鍵の合い言葉が違うキットを通した（rc=${rc}・${out}）"
    gpgconf --homedir "$k19/h" --kill all > /dev/null 2>&1 || true
    # 同時に渡すと断る（どちらを使うかを曖昧にしない。root でなくても、何も読む前に止まる）
    bad19=""
    for a in "restore 20261120-041500 --kit $k19/kit.gpg --app-key-file $k19/env" "restore 20261120-041500 --app-key-file=$k19/env --kit=$k19/kit.gpg" \
        "offsite pull --latest --kit $k19/kit.gpg --decrypt-key-file $k19/read" \
        "offsite pull --latest --kit $k19/kit.gpg --passphrase-file $k19/pp"; do
        rc=0
        # shellcheck disable=SC2086
        out="$(tool19 $a 2>&1 < /dev/null)" || rc=$?
        { [ "$rc" -ne 0 ] && [[ "$out" == *'同時に使えません'* ]]; } || bad19="${bad19} [${a#* }]"
    done
    # 置き場の入口・バケット・読む鍵・サーバーの名前（--credentials-file・--endpoint・--bucket・--region・--prefix）は、キットに入っているかを開けてから決める
    # （管理画面で作ったキットには入っていないので、一緒に渡す）。入っているキットと一緒に渡すと断ることは check-offsite.sh が確かめる
    [ -z "$bad19" ] && ok "復元のキット: --kit と、ばらの開ける鍵・APP_KEY の引数（--app-key-file・--decrypt-key-file・--passphrase-file）を同時に渡すと、何も読む前に断る" ||
        ng "復元のキット: 同時に渡しても断らない（${bad19}）"
    rc=0
    out="$(tool19 restore 20261120-041500 --kit-passphrase-file "$k19/pp" 2>&1 < /dev/null)" || rc=$?
    [ "$rc" -ne 0 ] && [[ "$out" == *'--kit と一緒に使います'* ]] && ok "復元のキット: --kit-passphrase-file だけを渡すと断る" || ng "復元のキット: --kit-passphrase-file だけを通した（${out}）"
    # 形の見張り: restore は開いた中身を読んだらすぐ消す・pull は終わりの trap で消す・消し残しは次の回が片付ける・使い方に載っている
    sed -n '/^cmd_restore() {/,/^}/p' deploy/ec-sugar | tr '\n' ' ' | q "trap 'ec_kit_drop' EXIT *ec_kit_open \"\$kit\" \"\$kit_pass\" *ec_restore_key_read \"\$EC_KIT_DIR/app-key\" *kit_server=\"\$EC_KIT_SERVER\" *ec_kit_drop" &&
        [ "$(sed -n '/^offsite_pull() {/,/^}/p' deploy/ec-sugar | grep -c "trap 'ec_kit_drop; ec_offsite_gpg_home_drop;")" = 2 ] && sed -n '/^ec_offsite_sweep() {/,/^}/p' "$lib" | q '/kit\.\*' &&
        bash deploy/ec-sugar help | q -- '--kit <復元のキット>' && sed -n '/^offsite_pull() {/,/^}/p' deploy/ec-sugar | q 'restore ${EC_OFFSITE_PULLED} --kit ${kit}' &&
        ok "復元のキット: restore は APP_KEY を読んだらすぐ消す・pull は終わりの trap で消す・消し残しは次の回が片付ける・pull の終わりに --kit の形の戻し方を出す" || ng "復元のキット: ec-sugar の --kit の形が違う"
    gpgconf --homedir "$k19/g" --kill all > /dev/null 2>&1 || true
    rm -rf "$k19"
    trap 'rm -rf "$work"' EXIT
    EC_LOCK="$saved_lock19"
    unset -f kit19 tool19 leak19 left19 seal19
    unset EC_RESTORE_KEY k19 keypass19 kitpass19 id19 secret19 app19 good19 fpr19 args19
fi

[ "$fails" -eq 0 ] || { echo "NG が ${fails} 件"; exit 1; }
echo "すべて ok"
