#!/usr/bin/env bash
# 公開版の形の更新の確かめ（ADR 0030・0099）を、使い捨ての git のリポジトリとテスト用の SSH の鍵（ここで ssh-keygen で作る。本物の鍵は使わない）と、
# 偽の docker（cosign・置き場の代わり）で確かめる。root も本物の GHCR も要らず、何も変えない（作るのは一時の置き場だけ）。
#
#   docker/check-release.sh [common.sh の道筋]   既定は deploy/lib/common.sh
#
# 確かめること:
#   1. タグの署名（ec_tag_verify）: 信じる鍵のタグを通す（鍵の形では断らない: ed25519 と、Mac の Secure Enclave の形の ecdsa-sha2-nistp256）。
#      署名の無いタグ（軽いタグ・注記だけのタグ）・知らない鍵のタグ・中身を書き換えたタグ・別のタグを新しい名前で出し直したタグを断る
#   2. リポジトリの中の鍵と設定を使わない: .git/config の gpg.ssh.allowedSignersFile（木の中の鍵の一覧）・gpg.ssh.program（いつも通す偽物）・
#      gpg.minTrustLevel・gpg.format があっても、知らない鍵のタグは断ったまま。置き換えの印（refs/replace/）も読まない
#   3. 信じる鍵の一覧の持ち主と権限: 無い・ほかの利用者が書ける・リンク・読めない行があれば、どのタグも通さない
#   4. semver の形と順（試しの版は同じ番号の正式の版より前。数字の区切りは数で比べる）
#   5. 入れるタグの選び方（ec_release_pick）: 署名の通る最新・通らないものは飛ばして知らせる・巻き戻しは --allow-downgrade の手の更新だけ・
#      同じ名前で別のコミットは断る・早送りでないものは断る・自動の更新は試しの版とメジャーバージョンの上がるものを取らない・--to にコミットは書けない
#   6. cosign の引数（ec_cosign_args）: 身元・発行者・リポジトリ・ref・workflow のコミットちょうど（正規表現の形と --insecure-… を使わない）・digest の形だけ
#   7. イメージを得る（ec_release_obtain）を偽の cosign で: 別のリポジトリ・別の workflow・別のタグ・別のコミットの署名を断り、何も取らない・名前も付けない。
#      正しければ digest で取り（タグの名前では取らない）、印のコミットを確かめてから今までの名前を付ける
#   8. 取得元の URL のパスワード・トークン（ec_url_has_secret）、運用の道具を git の中身から取り出す（ec_deploy_from_commit。作業場所の書き換えもリンクも使わない）、
#      リンクを写さない（ec_plain_file）、信じる鍵の一覧の読み書き（ec_trust_lines_from_file・ec_trust_write）
#   9. compose の置き場: 公開版の形では、作業場所を切り替える（ec_src_checkout）たびに、そのコミットの compose の一式（compose のファイルと
#      compose が差し込む docker/ の中身）を git の中身から root だけの置き場へ取り出し、compose（ec_dc）と守りの検査（ec_compose_guard）はそこを読む。
#      作業場所の compose と Caddy の設定を書き換えても、検査の後の起動・再起動・ロールバックで使われるのは確かめたコミットのもの。
#      記録が無ければ今のバージョンの置き場をその場で取り出す・どちらも分からなければ compose を流さない・リンクのあるコミットは取り出さない・
#      置き場は root のほかが書けない・片付けで今と前のバージョンを残す・開発の形は今までどおり作業場所
set -euo pipefail

cd "$(dirname "$0")/.."
lib="${1:-deploy/lib/common.sh}"
fails=0
ng() { echo "NG: $*"; fails=$((fails + 1)); }
ok() { echo "ok: $*"; }

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# shellcheck source=../deploy/lib/common.sh
. "$lib"
# 置き場は一時の所に（本物の /srv/ec-sugar を読まない）
EC_STATE="$work/state"
EC_LOGS="$EC_STATE/logs"
EC_UPDATE_MARK="$EC_STATE/updating"
EC_ANSWERS="$work/answers"
EC_ENV_FILE="$work/env"
EC_HOME="$work/home"
EC_SRC="$work/src"
EC_COMPOSE_ROOT="$work/compose"
EC_TRUST_DIR="$work/trust"
EC_TRUST_FILE="$EC_TRUST_DIR/allowed_signers"
EC_TRUST_UID="$(id -u)"
EC_REGISTRY_AUTH="$work/no-registry-auth"
mkdir -p "$EC_STATE" "$EC_LOGS" "$EC_HOME" "$EC_SRC"
: > "$EC_ANSWERS"
: > "$EC_ENV_FILE"
command -v ssh-keygen > /dev/null || { echo "NG: ssh-keygen がありません"; exit 1; }

# 偽の setpriv: 利用者を替えずに -- の後ろをそのまま流す（ec_git の残り〔env -i・PATH・GIT_NO_REPLACE_OBJECTS〕は本物のまま）
setpriv() {
    while [ $# -gt 0 ] && [ "$1" != -- ]; do shift; done
    shift
    "$@"
}

# ---------- 使い捨てのリポジトリとテスト用の鍵 ----------
tg() { HOME="$EC_HOME" GIT_CONFIG_NOSYSTEM=1 git -C "$EC_SRC" -c user.name=release -c user.email=release@example.com -c gpg.format=ssh -c commit.gpgsign=false "$@"; }
key() { ssh-keygen -q -t "$1" ${3:+-b "$3"} -N '' -C "$2" -f "$work/$2"; }
key ed25519 release
key ecdsa backup 256
key ed25519 stranger
signed_tag() { tg -c user.signingkey="$work/$1" tag -s "$2" -m "$2" "$3"; }
commit_file() {
    printf '%s\n' "$2" > "$EC_SRC/$1"
    tg add "$1"
    tg commit -q -m "$3"
    tg rev-parse HEAD
}
tg init -q
mkdir -p "$EC_SRC/deploy/lib" "$EC_SRC/docker"
printf '#!/usr/bin/env bash\n# ensure-env)\necho v1\n' > "$EC_SRC/deploy/ec-sugar"
printf '# common v1\n' > "$EC_SRC/deploy/lib/common.sh"
printf '#!/usr/bin/env bash\necho check\n' > "$EC_SRC/docker/check-compose.sh"
tg add deploy docker
c1="$(commit_file VERSION 1.0.0 one)"
c2="$(commit_file VERSION 1.1.0 two)"
c3="$(commit_file VERSION 1.2.0 three)"
c4="$(commit_file VERSION 1.2.1-rc.1 four)"
c5="$(commit_file VERSION 1.5.0 five)"
c6="$(commit_file VERSION 2.0.0 six)"
signed_tag release v1.0.0 "$c1"
signed_tag backup v1.1.0 "$c2"
signed_tag release v1.2.0 "$c3"
signed_tag release v1.2.1-rc.1 "$c4"
signed_tag stranger v1.3.0 "$c5"
tg tag -a v1.4.0 -m v1.4.0 "$c5"
tg tag v1.5.0 "$c5"
signed_tag release v2.0.0 "$c6"
# semver でないタグ（選ぶ候補にしない）
tg tag latest "$c6"
tg tag export/v9.0.0 "$c6"
tg tag v9.0 "$c6"
# 信じる鍵: release（ed25519）と backup（Secure Enclave と同じ ecdsa-sha2-nistp256）。stranger は入れない
pub() { cut -d' ' -f1,2 "$work/$1.pub"; }
mkdir -p "$EC_TRUST_DIR"
chmod 0755 "$EC_TRUST_DIR"
printf 'release namespaces="git" %s\nbackup namespaces="git" %s\n' "$(pub release)" "$(pub backup)" | ec_trust_write ||
    { echo "NG: 信じる鍵の一覧を書けない"; exit 1; }

# ---------- 1. タグの署名 ----------
if ec_tag_verify v1.0.0 && [ "$EC_TAG_COMMIT" = "$c1" ] && [ "$EC_TAG_SIGNER" = release ]; then
    ok "信じる鍵（ed25519）で署名したタグを通し、指すコミットと鍵の名前を返す"
else
    ng "信じる鍵のタグを通さない（${EC_TAG_PROBLEM}）"
fi
if ec_tag_verify v1.1.0 && [ "$EC_TAG_COMMIT" = "$c2" ] && [ "$EC_TAG_SIGNER" = backup ]; then
    ok "鍵の形では断らない（Secure Enclave の形の ecdsa-sha2-nistp256 の鍵で署名したタグも通す）"
else
    ng "ecdsa-sha2-nistp256 の鍵のタグを通さない（${EC_TAG_PROBLEM}）"
fi
ec_tag_verify v1.2.1-rc.1 && ok "試しの版のタグ（v1.2.1-rc.1）も同じ確かめで通す" || ng "試しの版のタグを通さない（${EC_TAG_PROBLEM}）"
expect_reject() {
    local tag="$1" want="$2" what="$3"
    if ec_tag_verify "$tag"; then
        ng "${what}を通した"
    elif [[ "$EC_TAG_PROBLEM" == *"$want"* ]] && [ -z "$EC_TAG_COMMIT" ]; then
        ok "${what}を断る（${EC_TAG_PROBLEM}）"
    else
        ng "${what}を断ったが、理由が違う（${EC_TAG_PROBLEM}）"
    fi
}
expect_reject v1.3.0 '信頼する鍵のどれのものでもありません' "知らない鍵で署名したタグ"
expect_reject v1.4.0 '署名がありません' "注記だけで署名の無いタグ"
expect_reject v1.5.0 '軽いタグ' "軽いタグ（署名の形を持たない）"
expect_reject v8.8.8 '取得元にありません' "無いタグ"
expect_reject 'v1.0.0;id' 'の形ではありません' "semver でない名前"
# 中身を書き換えたタグ: v1.2.0 の注記のタグの「指すコミット」だけを c1 に替え、同じ名前で置き直す（署名は元のまま）
orig="$(tg rev-parse refs/tags/v1.2.0)"
forged="$(tg cat-file tag v1.2.0 | sed "s/^object .*/object $c1/" | tg hash-object -t tag -w --stdin)"
tg update-ref refs/tags/v1.2.0 "$forged"
expect_reject v1.2.0 '中身と合いません' "指すコミットを書き換えたタグ"
tg update-ref refs/tags/v1.2.0 "$orig"
# 古いタグを新しい名前で出し直す（v1.0.0 の署名つきの注記を refs/tags/v7.0.0 に置く。署名は本物で通るが、中の名前が違う）
tg update-ref refs/tags/v7.0.0 "$(tg rev-parse refs/tags/v1.0.0)"
expect_reject v7.0.0 '中に書かれた名前が違います' "古いタグを新しい名前で出し直したもの（巻き戻しの攻撃）"
tg update-ref -d refs/tags/v7.0.0

# ---------- 2. リポジトリの中の鍵と設定を使わない ----------
printf 'stranger namespaces="git" %s\n' "$(pub stranger)" > "$EC_SRC/.allowed_signers"
printf '#!/bin/sh\necho "Good \\"git\\" signature for release with ED25519 key SHA256:x"\nexit 0\n' > "$EC_SRC/fake-ssh-keygen"
chmod +x "$EC_SRC/fake-ssh-keygen"
tg config gpg.ssh.allowedSignersFile "$EC_SRC/.allowed_signers"
tg config gpg.ssh.program "$EC_SRC/fake-ssh-keygen"
tg config gpg.minTrustLevel undefined
tg config gpg.format openpgp
expect_reject v1.3.0 '信頼する鍵のどれのものでもありません' "リポジトリの中の鍵の一覧（.git/config の allowedSignersFile）と偽の ssh-keygen がある時の、知らない鍵のタグ"
ec_tag_verify v1.0.0 && ok "リポジトリの設定があっても、信じる鍵のタグは決め打ちの道具と一覧で通る" || ng "リポジトリの設定で、信じる鍵のタグが通らなくなった（${EC_TAG_PROBLEM}）"
for k in gpg.ssh.allowedSignersFile gpg.ssh.program gpg.minTrustLevel gpg.format; do tg config --unset "$k"; done
rm -f "$EC_SRC/.allowed_signers" "$EC_SRC/fake-ssh-keygen"
# 置き換えの印: v1.0.0 の指すコミット c1 を、c6 の中身に見せかける。GIT_NO_REPLACE_OBJECTS で、見える中身は c1 のまま
tg replace "$c1" "$c6"
if [ "$(ec_git show -s --format=%s "$c1")" = one ]; then ok "置き換えの印（refs/replace/）は読まない"; else ng "置き換えの印で、確かめたコミットの中身が差し替わった"; fi
tg replace -d "$c1" > /dev/null

# ---------- 3. 信じる鍵の一覧の持ち主と権限 ----------
cp "$EC_TRUST_FILE" "$work/trust.bak"
chmod 0664 "$EC_TRUST_FILE"
expect_reject v1.0.0 '信頼する鍵の一覧' "ほかの利用者が書ける鍵の一覧"
chmod 0644 "$EC_TRUST_FILE"
chmod 0775 "$EC_TRUST_DIR"
expect_reject v1.0.0 '信頼する鍵の一覧' "ほかの利用者が書ける鍵の一覧の置き場"
chmod 0755 "$EC_TRUST_DIR"
printf 'garbage line\n' >> "$EC_TRUST_FILE"
expect_reject v1.0.0 '信頼する鍵の一覧' "読めない行のある鍵の一覧"
cp "$work/trust.bak" "$EC_TRUST_FILE"
mv "$EC_TRUST_FILE" "$work/trust.real"
ln -s "$work/trust.real" "$EC_TRUST_FILE"
expect_reject v1.0.0 '信頼する鍵の一覧' "リンクの鍵の一覧"
rm -f "$EC_TRUST_FILE"
expect_reject v1.0.0 '信頼する鍵の一覧' "鍵の一覧が無いとき"
mv "$work/trust.real" "$EC_TRUST_FILE"
EC_TRUST_UID=0
[ "$(id -u)" -eq 0 ] || expect_reject v1.0.0 '信頼する鍵の一覧' "root の持ち物でない鍵の一覧"
EC_TRUST_UID="$(id -u)"
ec_tag_verify v1.0.0 || ng "鍵の一覧を戻しても通らない"

# ---------- 4. semver の形と順 ----------
bad=""
for t in v1.0.0 v0.0.1 v10.20.30 v1.0.0-rc.1 v1.0.0-alpha.beta.1 v1.0.0-0.3.7 v1.0.0-x-y; do ec_valid_semver_tag "$t" || bad="$bad $t"; done
for t in 1.0.0 v1.0 v01.0.0 v1.0.0+build v1.0.0-rc.01 v1.0.0- 'v1.0.0 ' latest; do ! ec_valid_semver_tag "$t" || bad="$bad [$t]"; done
[ -z "$bad" ] && ok "semver の形（頭の v・試しの版を通し、v のないもの・頭の 0・build の印・試しの版の頭の 0 を断る）" || ng "semver の形の見分けが違う:${bad}"
cmp_is() {
    if ec_semver_cmp "$1" "$2" && [ "$EC_CMP" = "$3" ]; then return 0; fi
    bad="$bad $1?$2"
}
bad=""
cmp_is v1.10.0 v1.9.0 1
cmp_is v2.0.0 v10.0.0 -1
cmp_is v1.0.0-rc.1 v1.0.0 -1
cmp_is v1.0.0 v1.0.0-rc.1 1
cmp_is v1.0.0-rc.2 v1.0.0-rc.10 -1
cmp_is v1.0.0-alpha v1.0.0-alpha.1 -1
cmp_is v1.0.0-alpha.1 v1.0.0-alpha.beta -1
cmp_is v1.0.0-beta v1.0.0-alpha 1
cmp_is v1.0.0-rc.1 v1.0.0-rc.1 0
cmp_is v1.2.3 v1.2.3 0
cmp_is v1.0.0-1 v1.0.0-a -1
[ -z "$bad" ] && ok "semver の順（数の比べ・試しの版は正式の版より前・区切りごとの比べ・数字は英字より前）" || ng "semver の順が違う:${bad}"

# ---------- 5. 入れるタグの選び方 ----------
pick() {
    local rc=0
    ec_release_pick "$@" || rc=$?
    PICK_RC="$rc"
}
pick "" 1 0 "$c2" v1.1.0
if [ "$PICK_RC" = 0 ] && [ "$EC_PICK_TAG" = v1.2.0 ] && [ "$EC_PICK_COMMIT" = "$c3" ] && [ "$EC_PICK_SKIPPED" = "v1.5.0 v1.4.0 v1.3.0" ] && [ "$EC_PICK_MAJOR" = v2.0.0 ]; then
    ok "自動の更新: 署名の通る最新（v1.2.0）を選び、通らないもの（v1.5.0 v1.4.0 v1.3.0）を飛ばして知らせ、試しの版とメジャーバージョンの上がるもの（v2.0.0）は取らない"
else
    ng "自動の更新の選び方が違う（返り ${PICK_RC}・${EC_PICK_TAG}・飛ばした「${EC_PICK_SKIPPED}」・メジャー「${EC_PICK_MAJOR}」・${EC_PICK_PROBLEM}）"
fi
pick "" 0 0 "$c2" v1.1.0
[ "$PICK_RC" = 0 ] && [ "$EC_PICK_TAG" = v2.0.0 ] && ok "手の更新（--to なし）: 署名の通る最新（メジャーバージョンの上がるものも）" || ng "手の更新の最新が違う（${PICK_RC}・${EC_PICK_TAG}・${EC_PICK_PROBLEM}）"
pick "" 1 0 "$c3" v1.2.0
[ "$PICK_RC" = 3 ] && [ -z "$EC_PICK_TAG" ] && ok "自動の更新は試しの版（v1.2.1-rc.1）を取らない（新しいものは無い）" || ng "自動の更新が試しの版を取った（${PICK_RC}・${EC_PICK_TAG}）"
pick v1.2.1-rc.1 0 0 "$c3" v1.2.0
[ "$PICK_RC" = 0 ] && [ "$EC_PICK_COMMIT" = "$c4" ] && ok "試しの版は --to で名指しした時だけ入れる" || ng "--to の試しの版を入れない（${PICK_RC}・${EC_PICK_PROBLEM}）"
pick "" 1 0 "$c4" v1.2.1-rc.1
[ "$PICK_RC" = 3 ] || [ "$EC_PICK_TAG" != v1.2.0 ] && ok "試しの版から、より古い正式の版（v1.2.0）へは自動で戻らない" || ng "試しの版から古い正式の版へ戻った"
pick v1.0.0 0 0 "$c3" v1.2.0
[ "$PICK_RC" = 1 ] && [[ "$EC_PICK_PROBLEM" == *allow-downgrade* ]] && ok "古いバージョンへの巻き戻しを断る（--allow-downgrade が無い）" || ng "巻き戻しを断らない（${PICK_RC}）"
pick v1.0.0 0 1 "$c3" v1.2.0
[ "$PICK_RC" = 0 ] && [ "$EC_PICK_COMMIT" = "$c1" ] && ok "--allow-downgrade を付けた手の更新なら、署名の通る古いバージョンへ戻せる" || ng "--allow-downgrade で戻せない（${PICK_RC}・${EC_PICK_PROBLEM}）"
pick v1.0.0 1 1 "$c3" v1.2.0
[ "$PICK_RC" = 1 ] && ok "自動の更新は --allow-downgrade でも戻らない" || ng "自動の更新が巻き戻した"
pick v1.2.0 0 0 "$c2" v1.2.0
[ "$PICK_RC" = 1 ] && [[ "$EC_PICK_PROBLEM" == *別のコミット* ]] && ok "同じ名前で別のコミットを指すタグ（出したタグの書き換え）を断る" || ng "同じ名前で別のコミットを断らない（${PICK_RC}）"
pick v1.2.0 0 0 "$c3" v1.2.0
[ "$PICK_RC" = 3 ] && ok "今と同じタグ・同じコミットは「もう最新」" || ng "今と同じタグが最新にならない（${PICK_RC}）"
pick "$c3" 0 0 "$c2" v1.1.0
[ "$PICK_RC" = 1 ] && [[ "$EC_PICK_PROBLEM" == *タグ* ]] && ok "公開版の形では --to にコミットを書けない" || ng "--to のコミットを断らない（${PICK_RC}）"
pick v1.3.0 0 0 "$c2" v1.1.0
[ "$PICK_RC" = 1 ] && [[ "$EC_PICK_PROBLEM" == *信頼する鍵* ]] && ok "--to で名指ししても、署名の通らないタグは断る" || ng "--to の署名の通らないタグを断らない（${PICK_RC}）"
pick "" 0 0 "$c2" ""
[ "$PICK_RC" = 1 ] && ok "今のバージョンが分からなければ断る" || ng "今のバージョンが分からないのに選んだ"
# 早送りでない新しいタグ（c1 から分かれた枝の上。人の鍵の署名は本物）
tg checkout -q -b side "$c1"
side="$(commit_file SIDE side side)"
tg checkout -q --detach "$c6"
signed_tag release v1.9.0 "$side"
pick v1.9.0 0 0 "$c2" v1.1.0
[ "$PICK_RC" = 1 ] && [[ "$EC_PICK_PROBLEM" == *fast-forward* ]] && ok "今のコミットから早送りでつながらない新しいタグを断る（署名は本物でも）" || ng "早送りでないタグを断らない（${PICK_RC}・${EC_PICK_PROBLEM}）"
tg tag -d v1.9.0 > /dev/null
tags="$(ec_release_tags | LC_ALL=C sort | tr '\n' ' ')"
[ "$tags" = "v1.0.0 v1.1.0 v1.2.0 v1.2.1-rc.1 v1.3.0 v1.4.0 v1.5.0 v2.0.0 " ] && ok "候補は semver の形のタグだけ（latest・export/…・v9.0 は外す）" || ng "候補のタグが違う: ${tags}"

# ---------- 6. cosign の引数 ----------
dg=sha256:1111111111111111111111111111111111111111111111111111111111111111
args="$(ec_cosign_args "$EC_RELEASE_IMAGE@$dg" v1.2.0 "$c3" | tr '\n' ' ')"
want="verify --certificate-identity https://github.com/ec-sugar/ec-sugar/.github/workflows/release.yml@refs/tags/v1.2.0 --certificate-oidc-issuer https://token.actions.githubusercontent.com --certificate-github-workflow-repository ec-sugar/ec-sugar --certificate-github-workflow-ref refs/tags/v1.2.0 --certificate-github-workflow-sha $c3 ghcr.io/ec-sugar/ec-sugar@$dg "
[ "$args" = "$want" ] && ok "cosign の引数は約束の身元・発行者と、workflow のコミットがタグの指すコミットちょうど（digest で指す）" || ng "cosign の引数が違う: ${args}"
case "$args" in *regexp* | *insecure* | *--key*) ng "cosign の引数に正規表現の形か確かめを緩めるものがある" ;; *) ok "cosign の引数に正規表現の形・--insecure-…・鍵の指定が無い" ;; esac
bad=""
ec_cosign_args "$EC_RELEASE_IMAGE:v1.2.0" v1.2.0 "$c3" > /dev/null && bad="$bad タグの名前"
ec_cosign_args "ghcr.io/evil/ec-sugar@$dg" v1.2.0 "$c3" > /dev/null && bad="$bad ほかの名前"
ec_cosign_args "$EC_RELEASE_IMAGE@$dg" main "$c3" > /dev/null && bad="$bad 枝"
ec_cosign_args "$EC_RELEASE_IMAGE@$dg" v1.2.0 abc > /dev/null && bad="$bad 短いコミット"
[ -z "$bad" ] && ok "digest の無い名前・ほかの置き場の名前・semver でないタグ・形の違うコミットでは引数を作らない" || ng "引数を作ってしまう:${bad}"

# ---------- 7. イメージを得る（偽の cosign と置き場） ----------
# 置き場: reg/<名前:タグ> に digest、reg/<digest> に「署名したリポジトリ workflow ref コミット 印のコミット」
reg="$work/reg"
mkdir -p "$reg"
dlog="$work/docker.log"
docker() {
    local a ref d i id="" iss="" repo="" wref="" sha="" s_repo s_wf s_ref s_sha s_rev cosign=0
    printf '%s\n' "$*" >> "$dlog"
    case "$1 ${2:-}" in
        "buildx imagetools")
            ref="${*: -1}"
            [ -f "$reg/${ref//\//_}" ] || return 1
            cat "$reg/${ref//\//_}"
            ;;
        "run --rm")
            # cosign: 固定したイメージの後ろが cosign の引数
            set -- "$@"
            while [ $# -gt 0 ]; do
                if [ "$cosign" = 0 ]; then
                    [ "$1" = "$EC_COSIGN_IMAGE" ] && cosign=1
                    shift
                    continue
                fi
                case "$1" in
                    *regexp* | --insecure*) return 1 ;;
                    --certificate-identity) id="$2"; shift 2 ;;
                    --certificate-oidc-issuer) iss="$2"; shift 2 ;;
                    --certificate-github-workflow-repository) repo="$2"; shift 2 ;;
                    --certificate-github-workflow-ref) wref="$2"; shift 2 ;;
                    --certificate-github-workflow-sha) sha="$2"; shift 2 ;;
                    *) ref="$1"; shift ;;
                esac
            done
            [ "$cosign" = 1 ] || return 1
            d="${ref#*@}"
            [ -f "$reg/$d" ] || return 1
            read -r s_repo s_wf s_ref s_sha s_rev < "$reg/$d"
            [ "$id" = "https://github.com/$s_repo/$s_wf@$s_ref" ] && [ "$iss" = https://token.actions.githubusercontent.com ] &&
                [ "$repo" = "$s_repo" ] && [ "$wref" = "$s_ref" ] && [ "$sha" = "$s_sha" ]
            ;;
        "pull --quiet")
            ref="$3"
            # 置き場の一時のタイムアウトの真似: pull-fails に書いた回数だけ失敗する
            if [ -s "$work/pull-fails" ] && [ "$(cat "$work/pull-fails")" -gt 0 ]; then
                echo $(($(cat "$work/pull-fails") - 1)) > "$work/pull-fails"
                return 1
            fi
            [[ "$ref" == *@sha256:* ]] && [ -f "$reg/${ref#*@}" ]
            ;;
        "image inspect")
            ref="${*: -1}"
            read -r s_repo s_wf s_ref s_sha s_rev < "$reg/${ref#*@}"
            printf '%s\n' "$s_rev"
            ;;
        "tag "*) return 0 ;;
        *) return 1 ;;
    esac
}
ec_caddy_name() { printf 'ec-sugar-caddy:2.11.7-check'; }
da=sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
dc=sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
put_image() { printf '%s' "$2" > "$reg/${1//\//_}"; }
sign() { printf '%s %s %s %s %s\n' "$2" "$3" "$4" "$5" "${6:-$5}" > "$reg/$1"; }
put_image "$EC_RELEASE_IMAGE:v1.2.0" "$da"
put_image "$EC_RELEASE_CADDY_IMAGE:v1.2.0" "$dc"
ok_sign() { sign "$1" ec-sugar/ec-sugar .github/workflows/release.yml refs/tags/v1.2.0 "$c3"; }
obtain() {
    : > "$dlog"
    rm -f "$EC_STATE/release-$c3"
    OBTAIN_RC=0
    ec_release_obtain "$c3" v1.2.0 > "$work/obtain.out" 2>&1 || OBTAIN_RC=$?
}
nothing_taken() { ! grep -qE '^(pull|tag) ' "$dlog"; }
ok_sign "$da"
ok_sign "$dc"
obtain
if [ "$OBTAIN_RC" = 0 ] && grep -qx "pull --quiet $EC_RELEASE_IMAGE@$da" "$dlog" && grep -qx "pull --quiet $EC_RELEASE_CADDY_IMAGE@$dc" "$dlog" &&
    ! grep -q '^pull .*:v1\.2\.0' "$dlog" && grep -qx "tag $EC_RELEASE_IMAGE@$da ec-sugar:$c3" "$dlog" &&
    grep -qx "tag $EC_RELEASE_CADDY_IMAGE@$dc ec-sugar-caddy:2.11.7-check" "$dlog" && [ "$(ec_kv_get "$EC_STATE/release-$c3" app)" = "$EC_RELEASE_IMAGE@$da" ]; then
    ok "署名が通れば、2 つのイメージを digest で取り（タグの名前では取らない）、今までの名前を付けて digest を記録する"
else
    ng "正しく署名したイメージを得られない（返り ${OBTAIN_RC}）: $(tr '\n' '|' < "$dlog") $(tr '\n' ' ' < "$work/obtain.out")"
fi
if grep -q -- "run --rm --read-only --cap-drop ALL --security-opt no-new-privileges:true" "$dlog" && grep -qF "$EC_COSIGN_IMAGE verify" "$dlog" &&
    [[ "$EC_COSIGN_IMAGE" =~ ^ghcr\.io/sigstore/cosign/cosign:v3\.[0-9]+\.[0-9]+@sha256:[0-9a-f]{64}$ ]]; then
    ok "cosign は版（3 の系）と digest を固定した公式のイメージを、読み取り専用・権限なしで動かす"
else
    ng "cosign の動かし方が違う: $(grep '^run' "$dlog" | head -n1)"
fi
reject_case() {
    local what="$1"
    obtain
    if [ "$OBTAIN_RC" -ne 0 ] && nothing_taken; then ok "${what}を断り、何も取らず名前も付けない"; else ng "${what}を断らないか、取ってしまった（返り ${OBTAIN_RC}）: $(tr '\n' '|' < "$dlog")"; fi
}
sign "$da" evil/ec-sugar .github/workflows/release.yml refs/tags/v1.2.0 "$c3"
reject_case "別のリポジトリ（evil/ec-sugar）の CI が署名したアプリのイメージ"
sign "$da" ec-sugar/ec-sugar .github/workflows/build.yml refs/tags/v1.2.0 "$c3"
reject_case "同じリポジトリの別の workflow（build.yml）が署名したアプリのイメージ"
sign "$da" ec-sugar/ec-sugar .github/workflows/release.yml refs/tags/v1.1.0 "$c2"
reject_case "別のタグ（v1.1.0）で作ったイメージを v1.2.0 に付け替えたもの"
sign "$da" ec-sugar/ec-sugar .github/workflows/release.yml refs/tags/v1.2.0 "$c2"
reject_case "署名したタグの指すコミットと別のコミットから作ったイメージ（人の署名と CI の署名が結び付かない）"
sign "$da" ec-sugar/ec-sugar .github/workflows/release.yml refs/heads/main "$c3"
reject_case "main の push で作ったイメージ"
ok_sign "$da"
sign "$dc" evil/ec-sugar .github/workflows/release.yml refs/tags/v1.2.0 "$c3"
reject_case "アプリは正しく、入口（Caddy）のイメージだけ別のリポジトリが署名したもの"
ok_sign "$dc"
rm -f "$reg/$dc"
reject_case "入口のイメージに署名が無いもの"
ok_sign "$dc"
sign "$da" ec-sugar/ec-sugar .github/workflows/release.yml refs/tags/v1.2.0 "$c3" "$c2"
obtain
if [ "$OBTAIN_RC" -ne 0 ] && ! grep -q '^tag ' "$dlog"; then ok "取ったイメージの印のコミットがタグの指すコミットと違えば、名前を付けない"; else ng "印のコミットが違うイメージに名前を付けた"; fi
ok_sign "$da"
rm -f "$reg/${EC_RELEASE_IMAGE//\//_}:v1.2.0"
reject_case "置き場に無いタグ"
put_image "$EC_RELEASE_IMAGE:v1.2.0" "$da"
put_image "$EC_RELEASE_IMAGE:v1.2.0" "not-a-digest"
reject_case "置き場が digest でない答えを返したもの"
put_image "$EC_RELEASE_IMAGE:v1.2.0" "$da"
# 置き場の一時のタイムアウト（11-24 のまっさらな VM で GHCR の層が応答を返さず止まった）: 3 回まで取り直す。間は待たない（テスト）
EC_PULL_WAITS="0 0"
echo 2 > "$work/pull-fails"
obtain
if [ "$OBTAIN_RC" = 0 ] && [ "$(grep -cx "pull --quiet $EC_RELEASE_IMAGE@$da" "$dlog")" = 3 ] && grep -qx "tag $EC_RELEASE_IMAGE@$da ec-sugar:$c3" "$dlog"; then
    ok "イメージの取得が 2 回続けて失敗しても、同じ digest で取り直して 3 回目で取る"
else
    ng "取得の取り直しが違う（返り ${OBTAIN_RC}）: $(tr '\n' '|' < "$dlog")"
fi
echo 3 > "$work/pull-fails"
obtain
if [ "$OBTAIN_RC" -ne 0 ] && [ "$(grep -cx "pull --quiet $EC_RELEASE_IMAGE@$da" "$dlog")" = 3 ] && ! grep -q '^tag ' "$dlog" && grep -q '3 回試した' "$work/obtain.out"; then
    ok "3 回とも取れなければ止め、名前を付けない（4 回目は試さない）"
else
    ng "3 回とも失敗した時の扱いが違う（返り ${OBTAIN_RC}）: $(tr '\n' '|' < "$dlog")"
fi
rm -f "$work/pull-fails"
unset EC_PULL_WAITS
unset -f docker ec_caddy_name

# ---------- 8. 取得元の URL・運用の道具の取り出し・リンク・鍵の一覧の読み書き ----------
bad=""
for u in https://user:secret@example.com/a/b.git https://tokenvalue@example.com/a/b.git http://x@example.com/r ssh://git:secret@example.com/a/b.git; do ec_url_has_secret "$u" || bad="$bad $u"; done
for u in https://github.com/a/b.git git@github.com:a/b.git ssh://git@github.com/a/b.git /srv/repo https://github.com/a/b@c; do ! ec_url_has_secret "$u" || bad="$bad [$u]"; done
[ -z "$bad" ] && ok "取得元の URL のパスワード・トークンを見分ける（名前だけの ssh の形と、パスの中の @ は通す）" || ng "取得元の URL の見分けが違う:${bad}"

tg checkout -q --detach "$c3"
printf 'evil\n' >> "$EC_SRC/deploy/ec-sugar"
dir="$(ec_deploy_from_commit "$c3")" || dir=""
if [ -n "$dir" ] && [ "$(cat "$dir/deploy/ec-sugar")" = "$(tg show "$c3:deploy/ec-sugar")" ] && [ -f "$dir/docker/check-compose.sh" ] &&
    [ -z "$(find "$dir" -prune -perm -g+r 2> /dev/null)" ]; then
    ok "運用の道具は、作業場所のファイル（書き換えた）でなく、確かめたコミットの git の中身から自分だけの置き場へ取り出す"
else
    ng "運用の道具の取り出しが違う（${dir}）"
fi
[ -z "$dir" ] || rm -rf "$dir"
tg checkout -q -- deploy/ec-sugar
tg checkout -q --detach "$c6"
rm "$EC_SRC/deploy/ec-sugar"
ln -s /etc/hosts "$EC_SRC/deploy/ec-sugar"
tg add deploy/ec-sugar
tg commit -q -m link
link_commit="$(tg rev-parse HEAD)"
if ec_deploy_from_commit "$link_commit" > /dev/null; then ng "運用の道具がリンク（/etc/hosts）のコミットを取り出した"; else ok "運用の道具がリンクのコミットは取り出さない"; fi
mkdir -p "$work/plain/sub"
printf x > "$work/plain/sub/f"
ln -s /etc/hosts "$work/plain/sub/l"
ln -s "$work/outside" "$work/plain/sub/l2"
printf x > "$work/outside"
bad=""
ec_plain_file "$work/plain/sub/f" "$work/plain" || bad="$bad ふつう"
! ec_plain_file "$work/plain/sub/l" "$work/plain" || bad="$bad 外へのリンク"
! ec_plain_file "$work/plain/sub/l2" "$work/plain" || bad="$bad 外のファイルへのリンク"
! ec_plain_file "$work/outside" "$work/plain" || bad="$bad 置き場の外"
[ -z "$bad" ] && ok "運用の道具として写すのは、置き場の中のリンクでないファイルだけ" || ng "写すファイルの見分けが違う:${bad}"

printf '%s release-pub\n' "$(pub release)" > "$work/k.pub"
lines="$(ec_trust_lines_from_file "$work/k.pub" owner || true)"
[ "$lines" = "owner namespaces=\"git\" $(pub release)" ] && ok "公開鍵（.pub）に名前を付けて、信じる鍵の一覧の行にする" || ng "公開鍵を一覧の行にできない: ${lines}"
ec_trust_lines_from_file "$work/k.pub" "" > /dev/null && ng "名前の無い公開鍵を通した" || ok "名前の無い公開鍵は断る"
printf 'owner %s\n# 説明\n\nbackup namespaces="git" %s backup-key\n' "$(pub release)" "$(pub backup)" > "$work/as"
[ "$(ec_trust_lines_from_file "$work/as" "" | wc -l | tr -d ' ')" = 2 ] && ok "allowed signers の行（決まりの有る無し・説明・空の行）を読む" || ng "allowed signers の行を読めない"
bad=""
for l in "owner cert-authority $(pub release)" "owner valid-before=\"20300101\" $(pub release)" 'owner ssh-ed25519 AAAAnotakey' 'owner; rm -rf / ssh-ed25519 x' "\$(id) $(pub release)"; do
    printf '%s\n' "$l" > "$work/bad"
    ! ec_trust_lines_from_file "$work/bad" "" > /dev/null || bad="$bad [$l]"
done
[ -z "$bad" ] && ok "ほかの決まり（cert-authority・valid-before）・読めない鍵・使えない名前の行は断る" || ng "断るべき行を通した:${bad}"
mode="$(stat -c %a "$EC_TRUST_FILE" 2> /dev/null || stat -f %Lp "$EC_TRUST_FILE")"
fps="$(ec_trust_fingerprints | awk '{print $2 " " $3}' | tr '\n' '|')"
[ "$mode" = 644 ] && [ "$fps" = "release ssh-ed25519|backup ecdsa-sha2-nistp256|" ] && ok "信じる鍵の一覧は 0644 で、指紋・名前・種類を読める" || ng "鍵の一覧の書き方が違う（${mode}・${fps}）"
: | ec_trust_write && ng "空の鍵の一覧を書いた" || ok "空の鍵の一覧は書かない（どのタグも入れられなくなる）"
ec_tag_verify v1.0.0 || ng "空の一覧を書こうとした後に、前の一覧が壊れた"

ec_update_mark "$c1" "$c2"
[ "$(ec_kv_get "$EC_UPDATE_MARK" from)" = "$c1" ] && [ "$(ec_kv_get "$EC_UPDATE_MARK" to)" = "$c2" ] && ok "更新の途中の印に、今と新しいコミットを残す" || ng "更新の途中の印が違う"
ec_update_mark_clear
[ ! -e "$EC_UPDATE_MARK" ] || ng "更新の途中の印を消せない"

# ---------- 9. compose の置き場 ----------
# 偽の docker: compose の引数と、compose が読むはずのファイル（-f の compose と、project directory の Caddy の設定）の中身を書き残す
dlog="$work/dc9.log"
docker() {
    local pd="" f="" prev=""
    for a in "$@"; do
        case "$prev" in --project-directory) pd="$a" ;; -f) [ -n "$f" ] || f="$a" ;; esac
        prev="$a"
    done
    printf 'args=%s\nproject=%s\ncompose=%s\ncaddy=%s\n' "$*" "$pd" "$(cat "$f" 2> /dev/null)" "$(cat "$pd/docker/caddy/etc/Caddyfile" 2> /dev/null)" > "$dlog"
}
tg checkout -q --detach "$c6"
mkdir -p "$EC_SRC/docker/caddy/etc" "$EC_SRC/docker/db/init"
compose_commit() {
    printf 'compose %s\n' "$1" > "$EC_SRC/docker-compose.prod.yml"
    printf 'caddy %s\n' "$1" > "$EC_SRC/docker/caddy/etc/Caddyfile"
    printf 'init %s\n' "$1" > "$EC_SRC/docker/db/init/01.sql"
    tg add docker-compose.prod.yml docker
    tg commit -q -m "compose $1"
    tg rev-parse HEAD
}
c7="$(compose_commit v7)"
c8="$(compose_commit v8)"
printf 'CHANNEL=release\n' > "$EC_ANSWERS"
rm -f "$EC_STATE/compose_commit" "$EC_STATE/current_commit" "$EC_STATE/previous_commit"
dc9() { : > "$dlog"; DC9_RC=0; ec_dc "$@" > /dev/null 2> "$work/dc9.err" || DC9_RC=$?; }
got() { sed -n "s/^$1=//p" "$dlog"; }

if (ec_src_checkout "$c7") && [ "$(ec_state_get compose_commit)" = "$c7" ] && [ -f "$EC_COMPOSE_ROOT/$c7/docker-compose.prod.yml" ] &&
    [ "$(cat "$EC_COMPOSE_ROOT/$c7/docker/caddy/etc/Caddyfile")" = "caddy v7" ] && [ "$(cat "$EC_COMPOSE_ROOT/$c7/deploy/ec-sugar")" = "$(tg show "$c7:deploy/ec-sugar")" ]; then
    ok "公開版の形で作業場所を切り替えると、そのコミットの compose の一式（compose・docker/・deploy/）を git から置き場へ取り出し、向く先に記録する"
else
    ng "作業場所を切り替えても、compose の置き場を取り出さない・記録しない"
fi
writable="$(find "$EC_COMPOSE_ROOT" \( -perm -g+w -o -perm -o+w \) -print 2> /dev/null | head -n 1)"
[ -z "$writable" ] && ok "置き場の中は持ち主のほかが書けない（0644・0755）" || ng "置き場にほかの利用者が書ける所がある: ${writable}"
# 守りの検査の後に、作業場所の compose と入口の Caddy の設定を書き換える（利用者 ec-sugar が書ける所）
printf 'compose evil\n' > "$EC_SRC/docker-compose.prod.yml"
printf 'caddy evil\n' > "$EC_SRC/docker/caddy/etc/Caddyfile"
dc9 up -d
if [ "$DC9_RC" = 0 ] && [ "$(got project)" = "$EC_COMPOSE_ROOT/$c7" ] && [ "$(got compose)" = "compose v7" ] && [ "$(got caddy)" = "caddy v7" ] &&
    [[ "$(got args)" == "compose -p ec-sugar --project-directory $EC_COMPOSE_ROOT/$c7 -f $EC_COMPOSE_ROOT/$c7/docker-compose.prod.yml "* ]]; then
    ok "作業場所の compose と Caddy の設定を書き換えても、起こす compose は確かめたコミットのもの（project directory と -f は root だけの置き場）"
else
    ng "作業場所の書き換えが compose に効いた・置き場を使わない（返り ${DC9_RC}・$(tr '\n' '|' < "$dlog")）"
fi
# 守りの検査も同じ置き場を見る（作業場所の書き換えを見ない）。検査の代わりに、見た木の compose を出すだけの偽物
printf '#!/usr/bin/env bash\ncd "$EC_CHECK_TREE" && cat docker-compose.prod.yml\n' > "$work/fake-check.sh"
EC_LIB_DIR="$work/no-lib"
if command -v jq > /dev/null; then
    grc=0
    ec_compose_guard "$work/fake-check.sh" 2> /dev/null || grc=$?
    [ "$grc" = 0 ] && [ "$(cat "$EC_COMPOSE_GUARD_LOG")" = "compose v7" ] && ok "守りの検査は compose が使う置き場そのものを見る（作業場所の書き換えは見ない）" ||
        ng "守りの検査が置き場を見ない（返り ${grc}・$(cat "$EC_COMPOSE_GUARD_LOG" 2> /dev/null)）"
else
    ok "守りの検査の置き場の確かめは jq が無いので見送る（ec_compose_guard が jq を求める）"
fi
tg checkout -q -- .
# ロールバック: 新しいバージョンへ切り替えてから前のバージョンへ戻すと、compose も前のバージョンの置き場へ戻る
(ec_src_checkout "$c8") && dc9 config
[ "$(got compose)" = "compose v8" ] && [ "$(got caddy)" = "caddy v8" ] && ok "新しいバージョンへ切り替えると、compose もそのバージョンの置き場" || ng "新しいバージョンの置き場を使わない（$(tr '\n' '|' < "$dlog")）"
(ec_src_checkout "$c7") && dc9 config
[ "$(got compose)" = "compose v7" ] && [ "$(got project)" = "$EC_COMPOSE_ROOT/$c7" ] && ok "前のバージョンへロールバックすると、compose も前のバージョンの置き場へ戻る" || ng "ロールバックの後の compose が前のバージョンでない（$(tr '\n' '|' < "$dlog")）"
# 記録が無い（この仕組みの前のバージョンの道具が更新した）: 今のバージョンの置き場を、その場で git から取り出す
rm -f "$EC_STATE/compose_commit"
rm -rf "${EC_COMPOSE_ROOT:?}/$c8"
ec_state_put current_commit "$c8"
printf 'compose evil\n' > "$EC_SRC/docker-compose.prod.yml"
dc9 config
[ "$DC9_RC" = 0 ] && [ "$(got compose)" = "compose v8" ] && [ -f "$EC_COMPOSE_ROOT/$c8/docker-compose.prod.yml" ] &&
    ok "向く先の記録が無ければ、今のバージョン（current_commit）の置き場をその場で git から取り出して使う" || ng "記録が無い時に今のバージョンの置き場を使わない（返り ${DC9_RC}・$(tr '\n' '|' < "$dlog")）"
tg checkout -q -- .
rm -f "$EC_STATE/current_commit"
dc9 config
[ "$DC9_RC" -ne 0 ] && [ ! -s "$dlog" ] && grep -q 'compose のディレクトリを決められません' "$work/dc9.err" &&
    ok "公開版の形で、どのコミットか分からなければ compose を流さない（作業場所へ落とさない）" || ng "コミットが分からないのに compose を流した（返り ${DC9_RC}・$(tr '\n' '|' < "$dlog")）"
# リンクのあるコミット（差し込む元をホストのほかの所へ向ける）は取り出さない
ln -s /etc "$EC_SRC/docker/db/init/host"
tg add docker
tg commit -q -m link9
c9="$(tg rev-parse HEAD)"
if ec_compose_extract "$c9" > /dev/null || [ -e "$EC_COMPOSE_ROOT/$c9" ] || [ -n "$(find "$EC_COMPOSE_ROOT" -name '.partial.*' -print)" ]; then
    ng "リンクのあるコミットの compose を取り出した・半端な置き場を残した"
else
    ok "リンクのあるコミットの compose は取り出さず、半端な置き場も残さない"
fi
# 片付け: 今・前・向く先のほかは消す
ec_state_put current_commit "$c8"
ec_state_put previous_commit "$c7"
ec_state_put compose_commit "$c8"
mkdir -p "$EC_COMPOSE_ROOT/$c1" "$EC_COMPOSE_ROOT/.partial.zzz"
ec_compose_prune
[ -d "$EC_COMPOSE_ROOT/$c7" ] && [ -d "$EC_COMPOSE_ROOT/$c8" ] && [ ! -e "$EC_COMPOSE_ROOT/$c1" ] && [ ! -e "$EC_COMPOSE_ROOT/.partial.zzz" ] &&
    ok "片付けは今と前のバージョンの置き場を残し、ほかと半端な置き場を消す" || ng "置き場の片付けが違う: $(ls -a "$EC_COMPOSE_ROOT" | tr '\n' ' ')"
# 開発の形は今までどおり作業場所
printf 'CHANNEL=source\n' > "$EC_ANSWERS"
dc9 config
[ "$(got project)" = "$EC_SRC" ] && ok "開発の形（CHANNEL=source）の compose は今までどおり作業場所" || ng "開発の形の compose の置き場が違う（$(got project)）"
printf 'CHANNEL=other\n' > "$EC_ANSWERS"
dc9 config
[ "$DC9_RC" -ne 0 ] && [ ! -s "$dlog" ] && ok "更新の形が読めなければ compose を流さない" || ng "更新の形が読めないのに compose を流した"
: > "$EC_ANSWERS"
unset -f docker

[ "$fails" -eq 0 ] || { echo "NG が ${fails} 件"; exit 1; }
echo "すべて ok"
