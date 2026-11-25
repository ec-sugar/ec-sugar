#!/usr/bin/env bash
# サーバーの外のバックアップ（ADR 0110）を、手元の S3 互換の置き場（RustFS）へ実際に送って取って確かめる。外の本物には頼らない
#
#   docker/check-offsite.sh
#
# 立てるもの（どれも使い捨ての名前。ポートは公開しない。終われば消す）:
#   - 外へ出られない網（--internal）と、その中の RustFS（版と digest を固定。データは tmpfs）
#   - 送る側の使い捨ての Ubuntu（下の BASE_NEW。curl・gnupg・openssl と、復元のキットのための jq・python3 を足したもの）。リポジトリを読み取り専用で差し込み、この台本の --inside を
#     root で流す。コンテナの中なので、/srv/ec-sugar などの本物の道筋をそのまま使い、deploy/lib/common.sh の部品と deploy/ec-sugar を流す
#   - 古い curl の使い捨ての Ubuntu（BASE_OLD）。--aws-sigv4 の PUT が通らない版では、送る回が理由を残して断ることを見る
# 手元の Mac でも CI でも同じ（どちらも Docker の中で流すので、手元の道具の版に頼らない）。gpg が無ければ止まる（黙って飛ばさない）
#
# 確かめること（--inside）:
#   1. 置き場を設定していないサーバーでは何も変わらない: ec-sugar offsite run は何も出さず 0・state を書かない・置き場を作らない・curl を呼ばない。
#      ec-sugar backup の控えの段（ec_offsite_stage）も何も作らない。運営の様子への報告の引数に --offsite… が 1 つも出ない（設定した時だけ出る。9）
#   2. 送る: 答えの確かめと鍵の置き場（root だけの 0700・0400）。小さな偽のバックアップ（dump 2 つ・globals・storage.tar・SHA256SUMS・manifest）の
#      控えはハードリンク。切る大きさを 8 KB にして複数の切れ端で送る → 置き場に db と files の INDEX と part が並ぶ・控えは消える・state に結果
#   3. 取る: 一覧 → 切れ端の指紋 → 開く → バックアップの置き場に広げる。元と同じ中身（SHA256SUMS が合う）・権限は 0700 と 0600。
#      開ける鍵が違えば置かない・鍵なしでは暗号化された組を取らない
#   4. 送った中身に平文が無い: 置き場の全部のオブジェクトを取って、dump の頭の印（PGDMP）・dump と写真の中の印・APP_KEY と env の値が 0 件。
#      DB の組の tar に入っているのは決まった名前だけ（env は無い）
#   5. 鍵が引数・環境変数・画面・記録に出ない: curl を「引数と環境と標準入力を書き残して本物へ渡す」ものに差し替えて確かめる
#      （秘密の鍵は標準入力の設定にだけ出る）
#   6. 途中で失敗（2 つ目の切れ端の PUT が届かない）→ 回は 1・state は failed・控えは残る → 次の回が新しい名前で送り直す。
#      半端な回は INDEX が無いので一覧に出ない。写真の組だけ失敗した時は、次の回に DB の組を送り直さない
#   7. 写真の無い世代（DB だけのバックアップ）と、写真の組だけ無い・取らない場合（指紋と manifest を「写真なし」に直して置く）
#   8. 連鎖の先頭: アプリにコマンドが無ければ飛ばす（バージョンごとに 1 回だけ聞く）。あれば送る・同じ中身は 2 度送らない・
#      形の違う行（余分な欄・長い値・hash でない値）は送らずに失敗として残す
#   9. state の形（名前と値。運営の様子へ渡す引数と同じ名前）と、報告の引数（--offsite…。決まった語と時刻だけ。鍵・バックアップの名前は出ない）
#  11. 本物のコマンド（ec-sugar offsite test・pull --list・pull・run）が、偽の置き場に対して通しで動く
#  12. 失敗のやり直しは間を倍々に空け、回数の上限で止まる（run --retry で試し直す）。公開鍵で暗号化できない時は置き場の失敗と分ける。
#      強く切られた回の消し残しは次の回が片付ける。控えの段は、送る回が動いている間は溜まった控えを外さない
#  13. 公開鍵の指紋を見せる・別の鍵に黙って入れ替えない・元のファイルは root の持ち物でほかの人が書けないものだけ。隔離した写しでは test も断る
#  14. 復元のキット: 持ち主の手元の道具（deploy/recovery-kit）でキットを作る（読む鍵で置き場に届くことも確かめる。断られる鍵では作らない）→
#      ec-sugar offsite pull --kit で取る → 元と同じ中身（合い言葉つきの開ける鍵を、キットの中の合い言葉で開ける）。終わりの「戻すには」は --kit の形。
#      合い言葉が違えば開けず何も置かない・端末が無ければ合い言葉のファイルを求める・形の違うキット（知らない欄・format 違い・JSON でない・
#      平文の JSON・暗号でない包み）を断る・http の入口のキットを本物の道具は断る・キットとばらの鍵の引数を同時に渡すと断る・
#      ほかの人が読めるキットと合い言葉のファイルを断る・開いた中身が終わった後も途中で止めた後も /run とディスクに残らない・
#      鍵と合い言葉が curl と gpg の引数・環境変数・画面に出ない（gpg も書き残すものに差し替える）
#  10. 置き場の中身は信じない: 同じ名前の組が 2 つあれば黙って選ばず --sent を打たせる・名前の日付を偽った組は選ばない・いちばん新しいは
#      置き場の側の時刻で・一覧の続きをたどる・平文の組と、暗号と名乗る平文の包み（gpg --store）・圧縮だけの包み・膨らむ中身・形の違う INDEX・
#      形の違う tar（直にも、偽の組として置いて取る道でも）・中の指紋と合わない中身・決まっていない行のある指紋・制御文字つきの manifest を断る。
#      置き場のロックの確かめの語（3 つの前置きのどれも断れば ok・1 つでも通れば open）、隔離した写しからは送らない、
#      答えの形（入口・鍵）が違えばインストーラーが何も入れる前に止まる
set -euo pipefail
# 管の先で「あるか」を見る grep -q の代わり。grep -q は見つけた時点で読むのをやめるので、前のコマンドが書き込みの途中だと管が切れ、
# pipefail の下では偽の失敗になる（タイミングしだいで、たまにだけ落ちる）。先に全部を受け取ってから探す
q() {
    local in
    in="$(cat)"
    if [ -z "$in" ]; then grep -q "$@" < /dev/null; else grep -q "$@" <<< "$in"; fi
}

# 使い捨てのイメージ（版と digest を固定。上げる時は、ここの 3 つを一緒に確かめて書き換える）
RUSTFS_IMAGE='rustfs/rustfs:1.0.4@sha256:ed9210d6411d77ffceb70d94b964a3cb7d225983368bc25bebe01e995293dcc2'
BASE_NEW='ubuntu:24.04@sha256:73b999393d07f2bf65a40373778a44dc9a6d54ad7718d29247d8ff9413c4d6a8'
BASE_OLD='ubuntu:22.04@sha256:9abcaffc92a5279fff3a6a41635c0800ea33e528425fb389658806476e7defde'
# 偽の置き場の鍵（本物ではない。この確かめだけの値で、流す時に作る。2 つ目は、画面や記録に出ないことを見る印も兼ねる）
S3_ID=offsitecheckaccess01
S3_SECRET="OffsiteCheck$(printf 'Kk%.0s' 1 2 3 4 5 6 7 8 9 10)"
S3_BUCKET=offsite-check

fails=0
ng() { echo "NG: $*"; fails=$((fails + 1)); }
ok() { echo "ok: $*"; }

# ====================================================================================================================
# 外側: 網・RustFS・使い捨ての Ubuntu を立てて、中で --inside を流す
# ====================================================================================================================
if [ "${1:-}" != --inside ]; then
    cd "$(dirname "$0")/.."
    command -v docker > /dev/null 2>&1 || { echo "NG: docker が無い"; exit 1; }
    tag="ec-sugar-offsite-check-$$"
    cleanup() {
        docker rm -f "$tag-s3" "$tag-new" "$tag-old" > /dev/null 2>&1 || true
        docker network rm "$tag" > /dev/null 2>&1 || true
        docker image rm "$tag:new" "$tag:old" > /dev/null 2>&1 || true
    }
    trap cleanup EXIT
    build() {
        printf 'FROM %s\nRUN apt-get update -q > /dev/null && DEBIAN_FRONTEND=noninteractive apt-get install -y -q --no-install-recommends ca-certificates curl gnupg openssl jq python3 > /dev/null && rm -rf /var/lib/apt/lists/*\n' "$1" |
            docker build --quiet --tag "$2" - > /dev/null
    }
    echo "使い捨ての Ubuntu（送る側）を作ります"
    build "$BASE_NEW" "$tag:new"
    build "$BASE_OLD" "$tag:old"
    docker network create --internal "$tag" > /dev/null
    docker run -d --name "$tag-s3" --network "$tag" --network-alias s3 --tmpfs /data:mode=1777 --tmpfs /logs:mode=1777 \
        -e RUSTFS_ACCESS_KEY="$S3_ID" -e RUSTFS_SECRET_KEY="$S3_SECRET" "$RUSTFS_IMAGE" > /dev/null
    rc=0
    docker run --rm --name "$tag-new" --network "$tag" -v "$PWD:/repo:ro" "$tag:new" bash /repo/docker/check-offsite.sh --inside new || rc=1
    docker run --rm --name "$tag-old" --network "$tag" -v "$PWD:/repo:ro" "$tag:old" bash /repo/docker/check-offsite.sh --inside old || rc=1
    if [ "$rc" -ne 0 ]; then
        echo "NG: サーバーの外のバックアップの確かめが通らない（上の NG の行）"
        exit 1
    fi
    echo "すべて ok"
    exit 0
fi

# ====================================================================================================================
# 内側: 使い捨ての Ubuntu の中（root）。本物の道筋（/srv/ec-sugar）をそのまま使う
# ====================================================================================================================
mode="${2:-new}"
[ "$(id -u)" -eq 0 ] && [ -e /.dockerenv ] || { echo "NG: --inside は使い捨てのコンテナの中の root でだけ流す"; exit 1; }
command -v gpg > /dev/null 2>&1 || { echo "NG: gpg が無い（暗号化を確かめられない）"; exit 1; }
cd /repo
real_curl="$(command -v curl)"
E=http://s3:9000

# shellcheck source=../deploy/lib/common.sh
. deploy/lib/common.sh
# 手元の偽の置き場は http（本物の道具は https だけ。この変数は環境変数では替えられない）
EC_OFFSITE_SCHEMES="https http"
# 失敗のやり直しの間を 0 に（次の回がすぐ送り直す流れを見るため。間と回数の上限は 12 で確かめる）
EC_OFFSITE_RETRY_BASE=0
tool() { bash /repo/deploy/ec-sugar "$@"; }

check=/check
install -d -m 0700 "$check" "$check/bin"
install -d -m 0750 "$EC_ROOT" "$EC_STATE"
install -d -m 0700 "$EC_ENV_DIR" "$EC_SECRETS" "$EC_BACKUP"
: > "$EC_STATE/installed"
: > "$EC_ANSWERS"
# env には APP_KEY と合言葉（送ってはいけないもの。本物ではない印を、流す時に作って入れる）
printf 'EC_SUGAR_ADMIN_HOST=admin.offsite-check.test\nAPP_KEY=base64:%s=\nDB_PASSWORD=%s\nTENANT_DB_PREFIX=ec_t_\n' "$(printf 'APPKEYMARKER%.0s' 1 2 3)" "$(printf 'ENVPASSWORDMARKER%.0s' 1 2)" > "$EC_ENV_FILE"
chmod 0600 "$EC_ENV_FILE"

# 偽の curl: 引数と環境を書き残し、--config - の標準入力（鍵の入った設定）を別に残してから本物へ渡す。
# $check/fail があれば、その中の文字を含む URL への PUT は「届かない」（000 を出して 7）。
# $check/slow があれば、30 秒待ってから（置き場の答えを待っている間に止める確かめ。14）。
# $check/lock409 があれば、印（_probe）への上書き（If-None-Match の無い PUT）と削除に「断った」（409）と答える（ロックの効いた置き場の真似）。
# 中に文字があれば、その文字を含む URL の印にだけ（一部の前置きにだけ決まりが掛かっている置き場の真似）
cat > "$check/bin/curl" << EOF
#!/bin/bash
printf '%s\n' "\$*" >> $check/curl.args
env >> $check/curl.env
[ ! -e $check/slow ] || sleep 30
cfg=""
case " \$* " in *" --config - "*) cfg="\$(cat)"; printf '%s\n' "\$cfg" >> $check/curl.stdin ;; esac
if [ -e $check/fail ] && [[ " \$* " == *" -T "* ]] && [[ "\$*" == *"\$(cat $check/fail)"* ]]; then printf '000'; exit 7; fi
if [ -e $check/lock409 ] && [[ "\$*" == *"/_probe/"* ]] && [[ "\$*" == *"\$(cat $check/lock409)"* ]]; then
    if [[ " \$* " == *" -X DELETE "* ]] || { [[ " \$* " == *" -T "* ]] && [[ "\$*" != *"If-None-Match"* ]]; }; then printf '409'; exit 0; fi
fi
case " \$* " in *" --config - "*) printf '%s\n' "\$cfg" | $real_curl "\$@" ;; *) exec $real_curl "\$@" ;; esac
EOF
chmod +x "$check/bin/curl"
PATH="$check/bin:$PATH"

# 確かめの側が置き場を直に見る道具（本物の curl で。送る側の部品は通さない）
s3() { printf 'user = "%s:%s"\naws-sigv4 = "aws:amz:auto:s3"\n' "$S3_ID" "$S3_SECRET" | "$real_curl" -q -sS --config - "$@"; }
s3_keys() { s3 "$E/$S3_BUCKET?list-type=2&max-keys=1000" | tr -d '\n' | sed 's/<Key>/\n/g' | sed -n 's/^\([^<]*\)<\/Key>.*/\1/p' | sort; }
puts() { grep -c -- ' -T ' "$check/curl.args" 2> /dev/null || true; }
state() { ec_kv_get "$EC_STATE/offsite" "$1" 2> /dev/null || true; }

# ---------- 古い curl（Ubuntu 22.04 の 7.81）: 送れない版では、理由を残して断る ----------
if [ "$mode" = old ]; then
    echo "古い curl の確かめ（$(ec_offsite_curl_version)）"
    # 事実の控え: この版の --aws-sigv4 は、S3 互換の置き場に PUT も GET も通らない（通るようになっていたら、下限 EC_OFFSITE_CURL_MIN を見直せる）
    for _ in 1 2 3 4 5 6 7 8 9 10; do
        code="$(s3 -o /dev/null -w '%{http_code}' -X PUT "$E/$S3_BUCKET-old" 2> /dev/null || true)"
        [ "$code" != 000 ] && break
        sleep 1
    done
    printf 'x' > "$check/x"
    code="$(s3 -o /dev/null -w '%{http_code}' -T "$check/x" -H "x-amz-content-sha256: $(ec_integrity_sha "$check/x")" "$E/$S3_BUCKET-old/x" 2> /dev/null || true)"
    echo "事実: curl $(ec_offsite_curl_version) の --aws-sigv4 の PUT への置き場の答えは ${code}（2xx でなければ、この版では送れない）"
    ! ec_offsite_curl_ok && ok "古い curl: 下限（${EC_OFFSITE_CURL_MIN}）に足りないと分かる" || ng "古い curl を足りるとした"
    install -d -m 0700 "$EC_OFFSITE_DIR"
    printf 'access_key_id=%s\nsecret_access_key=%s\n' "$S3_ID" "$S3_SECRET" > "$EC_OFFSITE_DIR/credentials"
    printf 'OFFSITE_ENDPOINT=%s\nOFFSITE_BUCKET=%s\nOFFSITE_ENCRYPT=no\n' "$E" "$S3_BUCKET" > "$EC_ANSWERS"
    : > "$check/curl.args"
    rc=0
    out="$(ec_offsite_run 2>&1)" || rc=$?
    [ "$rc" -eq 1 ] && [ "$(state offsite)" = failed ] && [ "$(state reason)" = curl-old ] && [[ "$out" == *"では S3 互換のストレージへ送れません"* ]] && [ "$(puts)" = 0 ] &&
        ok "古い curl: 送る回は何も送らず、failed と理由（curl-old）を残して知らせる" || ng "古い curl で送ろうとした・理由を残していない（rc=${rc} $(state offsite) $(state reason) ${out}）"
    rc=0
    out="$(tool offsite status 2>&1)" || rc=$?
    [ "$rc" -eq 0 ] && [[ "$out" == *"では送れません"* ]] && ok "古い curl: status が送れないことを見せる" || ng "status が古い curl を知らせない（${out}）"
    [ "$fails" -eq 0 ] || { echo "NG が ${fails} 件（古い curl）"; exit 1; }
    exit 0
fi

echo "送る・取るの確かめ（curl $(ec_offsite_curl_version)・$(gpg --version | head -n1)）"
# RustFS が起きるのを待って、バケットを作る
code=000
for _ in $(seq 1 30); do
    code="$(s3 -o /dev/null -w '%{http_code}' -X PUT "$E/$S3_BUCKET" 2> /dev/null || true)"
    [[ "$code" =~ ^2 ]] && break
    sleep 1
done
[[ "$code" =~ ^2 ]] || { echo "NG: 偽の置き場（RustFS）にバケットを作れない（${code}）"; exit 1; }

# 暗号の鍵（この確かめだけ）: 公開鍵と開ける鍵をファイルに。別の人の鍵（開けられないことを見る）も
mkkey() {
    local home fpr
    home="$(mktemp -d)"
    chmod 700 "$home"
    gpg --homedir "$home" --batch --quiet --pinentry-mode loopback --passphrase '' --quick-generate-key "$1 <$1@offsite-check.test>" ed25519 cert never > /dev/null 2>&1
    fpr="$(gpg --homedir "$home" --batch --with-colons --list-keys 2> /dev/null | awk -F: '$1 == "fpr" {print $10; exit}')"
    gpg --homedir "$home" --batch --quiet --pinentry-mode loopback --passphrase '' --quick-add-key "$fpr" cv25519 encr never > /dev/null 2>&1
    gpg --homedir "$home" --batch --armor --export > "$check/$1.pub" 2> /dev/null
    gpg --homedir "$home" --batch --pinentry-mode loopback --passphrase '' --armor --export-secret-keys > "$check/$1.key" 2> /dev/null
    gpgconf --homedir "$home" --kill all > /dev/null 2>&1 || true
    rm -rf "$home"
}
mkkey owner
mkkey other
grep -q 'BEGIN PGP PUBLIC KEY BLOCK' "$check/owner.pub" && grep -q 'BEGIN PGP PRIVATE KEY BLOCK' "$check/owner.key" || { echo "NG: 確かめ用の gpg の鍵を作れない"; exit 1; }

# 偽のバックアップを作る。$1 名前 $2 写真などのファイルも入れるか（1・0）
mkbackup() {
    local d="$EC_BACKUP/$1" files="$2"
    install -d -m 0700 "$d"
    { printf 'PGDMP-DUMPMARKER-central-%s\n' "$1"; head -c 20000 /dev/urandom; } > "$d/ec_central.dump"
    { printf 'PGDMP-DUMPMARKER-shop-%s\n' "$1"; head -c 9000 /dev/urandom; } > "$d/ec_t_shop1.dump"
    printf -- '-- globals GLOBALSMARKER %s\nCREATE ROLE ec_t_shop1;\n' "$1" > "$d/globals.sql"
    if [ "$files" = 1 ]; then
        rm -rf "$check/storage"
        install -d "$check/storage/app/media"
        { printf 'PHOTOMARKER %s\n' "$1"; head -c 30000 /dev/urandom; } > "$check/storage/app/media/photo.jpg"
        tar -cf "$d/$EC_BACKUP_FILES_NAME" -C "$check/storage" app
    fi
    (cd "$d" && ec_sha256 -- *.dump globals.sql $([ "$files" = 1 ] && printf '%s' "$EC_BACKUP_FILES_NAME")) > "$d/SHA256SUMS"
    printf 'time=%s\nreason=check\ncommit=\ndatabases=ec_central ec_t_shop1\nprefix=ec_t_\nshops=1\nfiles=%s\nadmin_host=admin.offsite-check.test\n' \
        "$(ec_now)" "$([ "$files" = 1 ] && printf '%s' "$EC_BACKUP_FILES_NAME" || printf none)" > "$d/manifest"
    chmod 0600 "$d"/*
    : > "$d/.complete"
}
# 取る側の支度（読む鍵と、開ける鍵を入れた一時の homedir）。$1 開ける鍵のファイル（空なら鍵なし）
pull_ready() {
    ec_offsite_gpg_home_drop
    EC_OFFSITE_CRED_FILE="$check/read.credentials"
    if [ -n "$1" ]; then
        EC_OFFSITE_GPG_HOME="$(ec_offsite_gpg_home)"
        gpg --homedir "$EC_OFFSITE_GPG_HOME" --batch --no-options --no-tty --quiet --import "$1" > /dev/null 2>&1
    fi
}
pull_done() {
    ec_offsite_gpg_home_drop
    EC_OFFSITE_CRED_FILE=""
}
# 送る回を流して、画面の文を $check/run.out に足す。返りは回の返り
run() {
    local rc=0
    ec_offsite_run "$@" >> "$check/run.out" 2>&1 || rc=$?
    # 送る回の錠を放す（本物は 1 回ごとに別のプロセスで、終われば放れる。ここは同じシェルで何度も流すので、後の本物のコマンドのために）
    exec 8>&-
    return "$rc"
}
# 連鎖の先頭の偽のアプリ（ec_dc の差し替え）: 動いている app・コマンドの一覧・先頭の TSV
ec_dc() {
    case "$*" in
        *"ps --status running"*) echo app ;;
        *"list --raw operations"*)
            # 聞かれた回数（$( ) の中で呼ばれるので、数はファイルに残す）
            echo x >> "$check/dc.lists"
            cat "$check/artisan.list"
            ;;
        *"operations:chain-heads"*) cat "$check/heads.out" ;;
        # 管理画面で作った復元のキットの公開鍵。まだ作っていなければ何も出さない
        *"operations:recovery-recipient --host-fingerprint="*)
            told="$*"
            printf '%s\n' "${told##*--host-fingerprint=}" >> "$check/recipient.told"
            ;;
        *"operations:recovery-recipient"*) [ ! -e "$check/recipient.app" ] || cat "$check/recipient.app" ;;
        *) return 1 ;;
    esac
}
printf 'operations:alert   知らせの係\noperations:host-report   サーバーの見張りの報告\n' > "$check/artisan.list"
printf 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n' > "$EC_STATE/current_commit"

# バックアップの名前（古い順に B1〜B15）。今より前の時刻から作る（取る側は、名前の時刻・送った時刻・置き場の側の時刻が食い違う組を
# 疑うので、決め打ちの日付は使わない）
for b in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do
    printf -v "B$b" '%s' "$(date -u -d "-$((17 - b)) hours" +%Y%m%d-%H%M%S)"
done
# shellcheck disable=SC2154
: "${B1}" "${B2}" "${B3}" "${B4}" "${B5}" "${B6}" "${B7}" "${B8}" "${B9}" "${B10}" "${B11}" "${B12}" "${B13}" "${B14}" "${B15}"

# ---------- 1. 置き場を設定していないサーバーでは何も変わらない ----------
mkbackup ${B1} 1
: > "$check/curl.args"
rc=0
out="$(tool offsite run 2>&1)" || rc=$?
[ "$rc" -eq 0 ] && [ -z "$out" ] && ok "設定なし: ec-sugar offsite run は何も出さずに 0" || ng "設定なし: offsite run が何か出した・失敗した（rc=${rc} ${out}）"
rc=0
ec_offsite_stage "$EC_BACKUP/${B1}" > "$check/out" 2>&1 || rc=$?
[ "$rc" -eq 0 ] && [ ! -s "$check/out" ] && ok "設定なし: バックアップの後の控えの段は何もしない" || ng "設定なし: 控えの段が何か出した・失敗した"
[ ! -e "$EC_STATE/offsite" ] && [ ! -e "$EC_OFFSITE_WORK" ] && [ ! -e "$EC_OFFSITE_DIR" ] && [ -z "$(find "$EC_STATE" -name 'offsite*')" ] &&
    ok "設定なし: state を書かない・置き場（送る分の控え・鍵）を作らない" || ng "設定なし: 何かを書いた（$(ls -A "$EC_STATE" "$EC_ROOT" | tr '\n' ' ')）"
[ ! -s "$check/curl.args" ] && ok "設定なし: 外へつながない（curl を呼ばない）" || ng "設定なし: curl を呼んだ"
[[ "$(ec_report_state_args)" != *offsite* ]] && ok "設定なし: 運営の様子への報告の引数に offsite が出ない" || ng "設定なし: 報告の引数に offsite が出た"
out="$(tool offsite status 2>&1)" && [[ "$out" == *"設定していません"* ]] && ok "設定なし: status は「設定していません」" || ng "設定なし: status が違う（${out}）"
rc=0
out="$(tool offsite test 2>&1)" || rc=$?
[ "$rc" -ne 0 ] && [[ "$out" == *"設定していません"* ]] && ok "設定なし: test は設定が無いと断る" || ng "設定なし: test が通った"

# ---------- 10a. 答えの形が違えば、インストーラーは何も入れる前に止まる ----------
printf 'access_key_id=%s\nsecret_access_key=%s\n' "$S3_ID" "$S3_SECRET" > "$check/write.credentials"
printf '%s\n%s\n' "$S3_ID" "$S3_SECRET" > "$check/read.credentials"
chmod 0600 "$check"/*.credentials
answers() { printf '%s\n' "$@" > "$EC_ANSWERS"; }
dies() { (ec_offsite_check_answers) > "$check/out" 2>&1 && return 1; grep -q '止めました' "$check/out" && ! grep -qF -- "$S3_SECRET" "$check/out"; }
EC_OFFSITE_SCHEMES=https
answers "OFFSITE_ENDPOINT=$E" "OFFSITE_BUCKET=$S3_BUCKET" "OFFSITE_KEY_FILE=$check/write.credentials" "OFFSITE_RECIPIENT_FILE=$check/owner.pub"
dies && ok "答え: http の入口は断る（本物の道具は https だけ）" || ng "答え: http の入口を通した"
EC_OFFSITE_SCHEMES="https http"
answers "OFFSITE_ENDPOINT=$E/path?x=1" "OFFSITE_BUCKET=$S3_BUCKET" "OFFSITE_KEY_FILE=$check/write.credentials" "OFFSITE_RECIPIENT_FILE=$check/owner.pub"
dies && ok "答え: 道筋や問い合わせの付いた入口は断る" || ng "答え: 道筋つきの入口を通した"
answers "OFFSITE_ENDPOINT=https://user:pw@s3.example.test" "OFFSITE_BUCKET=$S3_BUCKET" "OFFSITE_KEY_FILE=$check/write.credentials" "OFFSITE_RECIPIENT_FILE=$check/owner.pub"
dies && ok "答え: 利用者の部分の付いた入口は断る" || ng "答え: 利用者つきの入口を通した"
printf 'access_key_id=%s\nsecret_access_key="%s"\n' "$S3_ID" "$S3_SECRET" > "$check/bad.credentials"
answers "OFFSITE_ENDPOINT=$E" "OFFSITE_BUCKET=$S3_BUCKET" "OFFSITE_KEY_FILE=$check/bad.credentials" "OFFSITE_RECIPIENT_FILE=$check/owner.pub"
dies && ok "答え: 引用符の付いた鍵は断る（中身は出さない）" || ng "答え: 形の違う鍵を通した・中身を出した"
answers "OFFSITE_ENDPOINT=$E" "OFFSITE_BUCKET=$S3_BUCKET" "OFFSITE_KEY_FILE=$check/write.credentials"
# 公開鍵を渡さない答えは通す: 管理画面で復元のキットを作ると、その鍵を採る。それまでは送らない（平文で送るのは OFFSITE_ENCRYPT=no と書いた時だけ）
out="$( (ec_offsite_check_answers) 2>&1)" && [[ "$out" == *"復元のキット"* ]] && [[ "$out" == *"それまでは送りません"* ]] &&
    ok "答え: 暗号の公開鍵を渡さなければ、管理画面の復元のキットの鍵を待つと案内して通す（それまでは送らない）" || ng "答え: 公開鍵なしの答えの扱いが違う（${out}）"
answers "OFFSITE_ENDPOINT=$E" "OFFSITE_BUCKET=$S3_BUCKET" "OFFSITE_KEY_FILE=$check/write.credentials" "OFFSITE_RECIPIENT_FILE=$check/owner.key"
dies && ok "答え: 公開鍵でないファイル（秘密の鍵）は断る" || ng "答え: 秘密の鍵を公開鍵として通した"
[ ! -e "$EC_OFFSITE_DIR" ] && ok "答え: 断った時は何も置いていない" || ng "答え: 断ったのに置き場を作った"

# ---------- 2. 送る ----------
answers "OFFSITE_ENDPOINT=$E" "OFFSITE_BUCKET=$S3_BUCKET" "OFFSITE_KEY_FILE=$check/write.credentials" "OFFSITE_RECIPIENT_FILE=$check/owner.pub"
(ec_offsite_check_answers) > "$check/out" 2>&1 && ok "答え: 正しい答えは通る" || ng "答え: 正しい答えを断った（$(cat "$check/out")）"
(ec_offsite_put_secrets) > "$check/out" 2>&1 || ng "鍵を置き場へ写せない（$(cat "$check/out")）"
[ "$(stat -c '%a %U' "$EC_OFFSITE_DIR")" = "700 root" ] && [ "$(stat -c '%a %U' "$EC_OFFSITE_DIR/credentials")" = "400 root" ] && [ "$(stat -c '%a' "$EC_OFFSITE_DIR/recipient.asc")" = 400 ] &&
    [ "$(stat -c '%a' "$EC_OFFSITE_WORK")" = 700 ] && ! grep -qF -- "$S3_SECRET" "$check/out" &&
    ok "鍵の置き場: root だけの 0700、鍵と公開鍵は 0400（画面に中身を出さない）" || ng "鍵の置き場の権限が違う・中身を出した"
EC_OFFSITE_PART_BYTES=8192
: > "$check/run.out"
: > "$check/curl.args"
: > "$check/curl.env"
: > "$check/curl.stdin"
ec_offsite_stage "$EC_BACKUP/${B1}" || ng "控えを作れない"
st="$EC_OFFSITE_WORK/stage/${B1}"
[ "$(stat -c %i "$st/ec_central.dump")" = "$(stat -c %i "$EC_BACKUP/${B1}/ec_central.dump")" ] && [ "$(stat -c %i "$st/storage.tar")" = "$(stat -c %i "$EC_BACKUP/${B1}/storage.tar")" ] &&
    [ -e "$st/.complete" ] && [ "$(stat -c %a "$st")" = 700 ] && ok "控え: バックアップの置き場の外に、ハードリンクで（容量を食わない）" || ng "控えがハードリンクでない・そろっていない"
# 掃除が元の写真の tar を外しても（指紋と manifest の直しは置き換え）、控えの中身は変わらない
before="$(cat "$st/SHA256SUMS" "$st/manifest" | ec_integrity_sha_in)"
ec_backup_drop_files "$EC_BACKUP/${B1}"
[ "$(cat "$st/SHA256SUMS" "$st/manifest" | ec_integrity_sha_in)" = "$before" ] && [ -s "$st/storage.tar" ] && ok "控え: 掃除が元から写真の tar を外しても、控えは取った時のまま" || ng "控えが、元の直しで変わった"
rm -rf "$EC_BACKUP/${B1}"
mkdir "$check/orig"
cp -a "$st" "$check/orig/${B1}"
rc=0
run || rc=$?
keys="$(s3_keys)"
db1="$(printf '%s\n' "$keys" | grep -c "^db/admin.offsite-check.test/${B1:0:4}/${B1:4:2}/${B1}\.[0-9]\{8\}T[0-9]\{6\}Z/part-[0-9]\{4\}\$" || true)"
files1="$(printf '%s\n' "$keys" | grep -c "^files/admin.offsite-check.test/${B1:0:4}/${B1:4:2}/${B1}\.[0-9]\{8\}T[0-9]\{6\}Z/part-[0-9]\{4\}\$" || true)"
idx1="$(printf '%s\n' "$keys" | grep -c "/${B1}\.[0-9TZ]*/INDEX\$" || true)"
[ "$rc" -eq 0 ] && [ "$db1" -ge 3 ] && [ "$files1" -ge 3 ] && [ "$idx1" -eq 2 ] && ok "送る: 複数の切れ端（db ${db1}・files ${files1}）と INDEX が、決めた名前で置き場に並ぶ" ||
    ng "送る: 置き場の中身が違う（rc=${rc} db=${db1} files=${files1} INDEX=${idx1} $(cat "$check/run.out")）"
[ ! -e "$st" ] && [ ! -e "$EC_OFFSITE_WORK/tmp" ] && ok "送る: 送れた控えと一時の置き場は消える" || ng "送る: 控えか一時の置き場が残った"
[ "$(state offsite)" = ok ] && ec_valid_time "$(state offsite_at)" && ec_valid_time "$(state offsite_tried_at)" && [ "$(state last_backup)" = ${B1} ] && [ -z "$(state reason)" ] && [ -z "$(state offsite_lock)" ] &&
    ok "送る: state に ok・送れた時刻・バックアップの名前（ロックの確かめを入れていなければ offsite_lock は書かない）" || ng "送る: state が違う（$(tr '\n' ' ' < "$EC_STATE/offsite"))"
! grep -q -- '-X DELETE' "$check/curl.args" && ! grep -v -e ' -T ' -e '^--version$' "$check/curl.args" | q . && ok "送る: 送る側が使うのは PUT だけ（読む・一覧・消すを使わない）" || ng "送る: PUT 以外を使った"
grep -q -- 'If-None-Match: \*' "$check/curl.args" && grep -q -- 'Content-MD5: ' "$check/curl.args" && grep -q -- 'x-amz-content-sha256: [0-9a-f]\{64\}' "$check/curl.args" && [ "$(grep -c -- 'If-None-Match' "$check/curl.args")" = "$(puts)" ] &&
    ok "送る: どの PUT にも「まだ無い時だけ」と中身の指紋が付く" || ng "送る: PUT の見出しが足りない"
n="$(puts)"
run && [ "$(puts)" = "$n" ] && ok "送る: 送るものの無い回は、何も送らない" || ng "送る: 控えが無いのに送った"

# ---------- 5. 鍵が引数・環境変数・画面・記録に出ない ----------
! grep -qF -- "$S3_SECRET" "$check/curl.args" && ! grep -qF -- "$S3_SECRET" "$check/curl.env" && ! grep -qF -- "$S3_ID" "$check/curl.args" && ok "鍵: curl の引数と環境変数に出ない" || ng "鍵が curl の引数か環境変数に出た"
grep -q "user = \"$S3_ID:$S3_SECRET\"" "$check/curl.stdin" && grep -q 'aws-sigv4 = "aws:amz:auto:s3"' "$check/curl.stdin" && ok "鍵: 設定として標準入力でだけ渡る" || ng "鍵が標準入力の設定で渡っていない（確かめが空振りしている）"
! grep -qF -- "$S3_SECRET" "$check/run.out" && ! grep -rqF -- "$S3_SECRET" "$EC_STATE" "$EC_OFFSITE_WORK" && ok "鍵: 画面・state・作業用の置き場に出ない" || ng "鍵が画面か記録に出た"

# ---------- 4. 送った中身に平文が無い ----------
mkdir "$check/objects"
i=0
while IFS= read -r key; do
    [ -n "$key" ] || continue
    i=$((i + 1))
    s3 -o "$check/objects/$i" "$E/$S3_BUCKET/$key"
done <<< "$keys"
[ "$i" -ge 8 ] && ! grep -rqa -e PGDMP -e DUMPMARKER -e GLOBALSMARKER -e PHOTOMARKER -e APPKEYMARKER -e ENVPASSWORDMARKER -e APP_KEY "$check/objects" &&
    ok "平文なし: 置き場の全部のオブジェクト（${i} 個）に、dump・ロール・写真の中身と APP_KEY・env の値が無い" || ng "平文が置き場にある（$(grep -rla -e PGDMP -e DUMPMARKER -e GLOBALSMARKER -e PHOTOMARKER -e APPKEYMARKER -e ENVPASSWORDMARKER -e APP_KEY "$check/objects" | tr '\n' ' ')）"
idx="$(grep -l '^kind=db$' "$check/objects"/* | head -n1)"
[ -n "$idx" ] && [ "$(sed -n '1,7p' "$idx" | cut -d= -f1 | tr '\n' ' ')" = "format kind backup sent server encrypted parts " ] && grep -qx 'encrypted=yes' "$idx" &&
    ! grep -v -E '^(format=1|kind=db|backup=[0-9-]+|sent=[0-9TZ]+|server=[A-Za-z0-9._-]+|encrypted=yes|parts=[0-9]+|part-[0-9]{4} [0-9]+ [0-9a-f]{64})$' "$idx" | q . &&
    ok "平文なし: INDEX は形の版・名前・切れ端の大きさと指紋だけ" || ng "INDEX に想定の外の行がある"

# ---------- 3. 取る ----------
pull_ready "$check/other.key"
rc=0
ec_offsite_pull ${B1} 1 1 > "$check/out" 2>&1 || rc=$?
[ "$rc" -ne 0 ] && [ ! -e "$EC_BACKUP/${B1}" ] && grep -q '復号できません' "$check/out" && ok "取る: 開ける鍵が違えば開けられず、何も置かない" || ng "取る: 違う鍵で置いた（$(cat "$check/out")）"
pull_ready ""
rc=0
ec_offsite_pull ${B1} 0 1 > "$check/out" 2>&1 || rc=$?
[ "$rc" -ne 0 ] && [ ! -e "$EC_BACKUP/${B1}" ] && grep -q '暗号化されています' "$check/out" && ok "取る: 鍵なしでは、暗号化された組を取らない" || ng "取る: 鍵なしで置いた"
pull_ready "$check/owner.key"
rc=0
ec_offsite_pull "" 1 1 > "$check/out" 2>&1 || rc=$?
got="$EC_BACKUP/${B1}"
[ "$rc" -eq 0 ] && [ "$EC_OFFSITE_PULLED" = ${B1} ] && diff -r "$check/orig/${B1}" "$got" > /dev/null && (cd "$got" && sha256sum --status -c SHA256SUMS) && grep -q '  storage.tar$' "$got/SHA256SUMS" &&
    ok "取る: いちばん新しい組を取って開くと、元と同じ中身（dump・ロール・写真・manifest・指紋が合う）" || ng "取る: 元と違う（rc=${rc} $(cat "$check/out")）"
[ "$(stat -c '%a %U' "$got")" = "700 root" ] && [ -z "$(find "$got" -type f ! -perm 600)" ] && [ ! -e "$EC_OFFSITE_WORK/tmp" ] && ok "取る: 置いたバックアップは root だけ（0700・0600）。一時の置き場は消える" || ng "取る: 権限が違う・一時の置き場が残った"
rc=0
ec_offsite_pull ${B1} 1 1 > "$check/out" 2>&1 || rc=$?
[ "$rc" -ne 0 ] && grep -q 'もうあります' "$check/out" && ok "取る: 同じ名前のバックアップがあれば上書きしない" || ng "取る: 同じ名前を上書きした"
sets="$(ec_offsite_sets)"
[ "$(printf '%s\n' "$sets" | grep -c .)" = 2 ] && [[ "$sets" =~ db\ ${B1}\ [0-9]{8}T[0-9]{6}Z\ [0-9]{4}-[0-9]{2}-[0-9]{2}T ]] && ok "取る: 一覧は、そろっている組と置き場の側の時刻" || ng "取る: 一覧が違う（${sets}）"
pull_done
rm -rf "$got"

# ---------- 7a. 写真の組を取らない・無い場合（指紋と manifest を「写真なし」に直して置く） ----------
pull_ready "$check/owner.key"
rc=0
ec_offsite_pull ${B1} 1 0 > "$check/out" 2>&1 || rc=$?
[ "$rc" -eq 0 ] && [ ! -e "$got/storage.tar" ] && ! grep -q storage.tar "$got/SHA256SUMS" && [ "$(ec_kv_get "$got/manifest" files)" = none ] && (cd "$got" && sha256sum --status -c SHA256SUMS) &&
    cmp -s "$got/ec_central.dump" "$check/orig/${B1}/ec_central.dump" && ok "取る: 写真を取らない時は DB だけのバックアップとして置く（指紋と manifest を直す）" || ng "取る: 写真なしの形が違う（$(cat "$check/out")）"
pull_done
rm -rf "$got"

# ---------- 6. 途中で失敗 → 控えは残る → 次の回が新しい名前で送り直す ----------
mkbackup ${B2} 1
ec_offsite_stage "$EC_BACKUP/${B2}"
cp -a "$EC_BACKUP/${B2}" "$check/orig/"
rm -rf "$EC_BACKUP/${B2}"
st="$EC_OFFSITE_WORK/stage/${B2}"
# 2 つ目の切れ端から届かない
printf 'part-0002' > "$check/fail"
: > "$check/run.out"
rc=0
run || rc=$?
[ "$rc" -eq 1 ] && [ "$(state offsite)" = failed ] && [ "$(state reason)" = put ] && [ -e "$st/.complete" ] && [ ! -e "$st/.sent-db" ] && [ "$(state last_backup)" = ${B1} ] && grep -q '送れませんでした' "$check/run.out" &&
    ok "失敗: 2 つ目の切れ端で切れると、回は 1・state は failed（理由 put）・控えは残る・最後に送れた名前は前のまま" || ng "失敗: 途中で切れた時の形が違う（rc=${rc} $(tr '\n' ' ' < "$EC_STATE/offsite")）"
half="$(s3_keys | grep -c "/${B2}\." || true)"
pull_ready "$check/owner.key"
sets="$(ec_offsite_sets)"
[ "$half" -eq 1 ] && [[ "$sets" != *${B2}* ]] && ok "失敗: 半端な回（切れ端 1 つ・INDEX なし）は、取る側の一覧に出ない" || ng "失敗: 半端な回が一覧に出た（${half} ${sets}）"
# 次の回: DB は送れるが、写真の組が切れる → DB の組は送り済みの印が付く
sleep 1
printf 'files/admin.offsite-check.test' > "$check/fail"
rc=0
run || rc=$?
[ "$rc" -eq 1 ] && [ -e "$st/.sent-db" ] && [ "$(s3_keys | grep -c "^db/.*/${B2}\.[0-9TZ]*/INDEX\$" || true)" = 1 ] && ok "失敗: 次の回は新しい名前で DB の組を送り直せる（写真の組だけ切れた）" || ng "失敗: 送り直しの形が違う（rc=${rc}）"
# 写真の組が置き場に無いまま取る → DB だけのバックアップとして置ける（指紋と manifest を直す）。送る回が gpg の一時の置き場を片付けるので、支度し直す
pull_ready "$check/owner.key"
rc=0
ec_offsite_pull ${B2} 1 1 > "$check/out" 2>&1 || rc=$?
got="$EC_BACKUP/${B2}"
[ "$rc" -eq 0 ] && [ ! -e "$got/storage.tar" ] && ! grep -q storage.tar "$got/SHA256SUMS" && [ "$(ec_kv_get "$got/manifest" files)" = none ] && (cd "$got" && sha256sum --status -c SHA256SUMS) &&
    ok "写真の組だけ無い: DB だけのバックアップとして置く（指紋と manifest を直す）" || ng "写真の組だけ無い時に取れない（$(cat "$check/out")）"
pull_done
rm -rf "$got"
rm -f "$check/fail"
sleep 1
n="$(s3_keys | grep -c "^db/.*/${B2}\." || true)"
rc=0
run || rc=$?
[ "$rc" -eq 0 ] && [ ! -e "$st" ] && [ "$(state offsite)" = ok ] && [ "$(state last_backup)" = ${B2} ] && [ "$(s3_keys | grep -c "^db/.*/${B2}\." || true)" = "$n" ] &&
    [ "$(s3_keys | grep -c "^files/.*/${B2}\.[0-9TZ]*/INDEX\$" || true)" = 1 ] && ok "失敗: その次の回は写真の組だけを送り（DB の組は送り直さない）、state は ok に戻る" || ng "失敗: 戻りの形が違う（rc=${rc} $(tr '\n' ' ' < "$EC_STATE/offsite")）"

# ---------- 7b. 写真の無い世代（DB だけのバックアップ） ----------
mkbackup ${B3} 0
ec_offsite_stage "$EC_BACKUP/${B3}"
cp -a "$EC_BACKUP/${B3}" "$check/orig/"
rm -rf "$EC_BACKUP/${B3}"
run || ng "写真の無い世代を送れない"
[ "$(s3_keys | grep -c "^files/.*/${B3}\." || true)" = 0 ] && [ "$(s3_keys | grep -c "^db/.*/${B3}\.[0-9TZ]*/INDEX\$" || true)" = 1 ] && ok "写真なし: DB だけのバックアップは db の組だけを送る" || ng "写真なし: 送った組が違う"
pull_ready "$check/owner.key"
rc=0
ec_offsite_pull "" 1 1 > "$check/out" 2>&1 || rc=$?
[ "$rc" -eq 0 ] && [ "$EC_OFFSITE_PULLED" = ${B3} ] && diff -r "$check/orig/${B3}" "$EC_BACKUP/${B3}" > /dev/null && ok "写真なし: 取ると元と同じ（DB だけ）" || ng "写真なし: 取ったものが違う（$(cat "$check/out")）"
pull_done

# ---------- 10b. 置き場の中身は信じない: 後から・先に足された組、名前を偽った組、平文・形の違う組 ----------
# サーバーの書く鍵を取った人（と、置き場の事業者）の真似をして、偽の組を置く。公開鍵は秘密でないので、暗号化もできる
mkdir -m 700 "$check/gh"
# $1 平文のファイル $2 暗号化した先（持ち主の公開鍵で。$3 があれば圧縮する）
enc() {
    rm -f "$2"
    gpg --homedir "$check/gh" --batch --no-options --quiet --trust-model always --compress-algo "${3:-none}" --recipient-file "$check/owner.pub" --encrypt --output "$2" "$1" 2> /dev/null
}
# 偽の組を置く。$1 種類 $2 名前 $3 送った時刻 $4 encrypted の値 $5 切れ端にするファイル $6 INDEX の作り変え（sed の式。無ければそのまま）
fake_set() {
    local kind="$1" name="$2" sent="$3" yn="$4" file="$5" edit="${6:-}" base
    base="$kind/admin.offsite-check.test/${name:0:4}/${name:4:2}/$name.$sent"
    s3 -o /dev/null -T "$file" "$E/$S3_BUCKET/$base/part-0001"
    printf 'format=1\nkind=%s\nbackup=%s\nsent=%s\nserver=admin.offsite-check.test\nencrypted=%s\nparts=1\npart-0001 %s %s\n' \
        "$kind" "$name" "$sent" "$yn" "$(wc -c < "$file" | tr -d ' ')" "$(ec_integrity_sha "$file")" > "$check/fake.index"
    [ -z "$edit" ] || sed -i "$edit" "$check/fake.index"
    s3 -o /dev/null -T "$check/fake.index" "$E/$S3_BUCKET/$base/INDEX"
}
# 取る側が断って何も置かないこと。$1 名前 $2 出るはずの文 $3 送った時刻（無ければ選ばせない）
refused() {
    local rc=0
    ec_offsite_pull "$1" 1 1 "${3:-}" > "$check/out" 2>&1 || rc=$?
    [ "$rc" -ne 0 ] && [ ! -e "$EC_BACKUP/$1" ] && [ ! -e "$EC_OFFSITE_WORK/tmp/out" ] && grep -q -- "$2" "$check/out"
}
# 時刻の食い違わない偽の名前（今より前の時刻。$1 で 1 時間ずつずらす）
fname() { date -u -d "-$((40 + $1)) hours" +%Y%m%d-%H%M%S; }
printf 'FAKE' > "$check/fake.plain"
enc "$check/fake.plain" "$check/fake.enc"
pull_ready "$check/owner.key"
rm -rf "$EC_BACKUP/${B2}"
real_sent="$(ec_offsite_sets | awk -v n="${B2}" '$1 == "db" && $2 == n {print $3}')"
# (1) 同じ名前の組を、送った時刻を本物より「前」に書いて足す（置き場の側の時刻は後。先に置けば前にもできる）→ 黙って選ばない
fake_set db "${B2}" 20000101T000000Z yes "$check/fake.enc"
refused "${B2}" '--sent <送った時刻> で選んでください' && grep -q -- "--sent $real_sent " "$check/out" && grep -q -- '--sent 20000101T000000Z .*疑わしい' "$check/out" &&
    ok "偽の組: 同じ名前の組が 2 つあれば黙って選ばず、置き場の側の時刻つきで並べて --sent を打たせる（時刻の食い違う方は「疑わしい」）" || ng "偽の組: 同じ名前の 2 つから黙って選んだ（$(cat "$check/out")）"
refused "${B2}" 'バックアップの形ではありません' 20000101T000000Z && ok "偽の組: --sent で偽の方を選んでも、中身がバックアップの形でなければ置かない" || ng "偽の組: 偽の方を置いた（$(cat "$check/out")）"
rc=0
ec_offsite_pull "${B2}" 1 1 "$real_sent" > "$check/out" 2>&1 || rc=$?
[ "$rc" -eq 0 ] && diff -r "$check/orig/${B2}" "$EC_BACKUP/${B2}" > /dev/null && [ "$EC_OFFSITE_PULLED_SENT" = "$real_sent" ] && ec_valid_time "$EC_OFFSITE_PULLED_AT" &&
    ok "偽の組: --sent で本物を選べば、元と同じものが取れる（置き場の側の時刻を返す）" || ng "偽の組: --sent で本物を取れない（$(cat "$check/out")）"
# (2) 名前の時刻を先の日付に偽った組（そろっていて、名前は 1 つだけ）→ いちばん新しいとして取らない・名前で頼んでも断る
fake_set db 20991231-235959 "$(ec_offsite_stamp)" yes "$check/fake.enc"
[[ "$(ec_offsite_sets)" == *"db 20991231-235959 "*" suspect"* ]] && refused 20991231-235959 '食い違っています' && ok "偽の組: 名前の時刻を先の日付に偽った組は「疑わしい」になり、名前で頼んでも --sent なしでは取らない" || ng "偽の組: 日付を偽った組を取った（$(cat "$check/out")）"
rm -rf "$EC_BACKUP/${B3}"
rc=0
ec_offsite_pull "" 1 1 > "$check/out" 2>&1 || rc=$?
[ "$rc" -eq 0 ] && [ "$EC_OFFSITE_PULLED" = "${B3}" ] && diff -r "$check/orig/${B3}" "$EC_BACKUP/${B3}" > /dev/null &&
    ok "偽の組: いちばん新しい（--latest）は置き場の側の時刻で選び、名前の時刻がいちばん先の偽の組・時刻の食い違う組を取らない" || ng "偽の組: --latest が違うものを取った（${EC_OFFSITE_PULLED} $(cat "$check/out")）"
# 一覧の続き（置き場は 1 回に 1,000 個まで。印も同じ前置きの下に溜まる）: 1 回の数を小さくしても同じ一覧になる
all="$(ec_offsite_sets)"
: > "$check/curl.args"
EC_OFFSITE_LIST_MAX=3
paged="$(ec_offsite_sets)"
EC_OFFSITE_LIST_MAX=1000
[ "$all" = "$paged" ] && [ "$(printf '%s\n' "$all" | grep -c .)" -ge 6 ] && [ "$(grep -c 'continuation-token=' "$check/curl.args")" -ge 4 ] &&
    ok "一覧: 続き（continuation-token）をたどっても、1 回で取った時と同じ一覧になる" || ng "一覧: 続きをたどると一覧が変わる・たどっていない（$(grep -c 'continuation-token=' "$check/curl.args")）"
# (3) 時刻の食い違わない偽の組（名前は 1 つだけ）。ここから先は、中身の確かめだけが守り
f="$(fname 1)"
tar -cf "$check/fake.tar" -C "$check/orig/${B3}" ec_central.dump ec_t_shop1.dump globals.sql manifest SHA256SUMS .complete
fake_set db "$f" "$(ec_offsite_stamp)" no "$check/fake.tar"
refused "$f" '暗号化されていません' && ok "偽の組: 開ける鍵を渡しているのに平文の組は取らない" || ng "偽の組: 平文の組を置いた（$(cat "$check/out")）"
f="$(fname 2)"
# 圧縮なしで包む（gpg の --store は既定で圧縮する。圧縮したものは下の「圧縮だけの包み」で別に確かめる）
gpg --homedir "$check/gh" --batch --no-options --quiet --compress-algo none --store --output "$check/fake.store" "$check/fake.tar" 2> /dev/null
fake_set db "$f" "$(ec_offsite_stamp)" yes "$check/fake.store"
refused "$f" '復号した跡がありません' && ok "偽の組: 暗号化されていると名乗る平文の包み（gpg --store）は、gpg が 0 で開けても取らない" || ng "偽の組: 平文の包みを置いた（$(cat "$check/out")）"
f="$(fname 3)"
gpg --homedir "$check/gh" --batch --no-options --quiet --compress-algo zlib --store --output "$check/fake.z" "$check/fake.tar" 2> /dev/null
fake_set db "$f" "$(ec_offsite_stamp)" yes "$check/fake.z"
rc=0
ec_offsite_pull "$f" 1 1 > "$check/out" 2>&1 || rc=$?
[ "$rc" -ne 0 ] && grep -q -e '復号した跡がありません' -e '大きすぎます' "$check/out" && [ ! -e "$EC_BACKUP/$f" ] && ok "偽の組: 圧縮だけの包みも取らない" || ng "偽の組: 圧縮だけの包みを置いた（$(cat "$check/out")）"
f="$(fname 4)"
fake_set db "$f" "$(ec_offsite_stamp)" yes "$check/fake.enc" "s/ [0-9a-f]\{64\}\$/ $(printf x | ec_integrity_sha_in)/"
refused "$f" '指紋と違います' && ok "偽の組: 切れ端が INDEX の指紋と違えば開けずに止める" || ng "偽の組: 指紋の違う切れ端を通した（$(cat "$check/out")）"
# 開けた中身が、切れ端の合計より大きい（圧縮して膨らませたもの）
f="$(fname 5)"
head -c 3000000 /dev/zero > "$check/zeros"
enc "$check/zeros" "$check/fake.bomb" zlib
fake_set db "$f" "$(ec_offsite_stamp)" yes "$check/fake.bomb"
[ "$(wc -c < "$check/fake.bomb")" -lt 100000 ] && refused "$f" '大きすぎます' && ok "偽の組: 開けると切れ端の合計より大きくなる中身（圧縮で膨らむもの）は途中で止める" || ng "偽の組: 膨らむ中身を通した（$(cat "$check/out")）"
# INDEX の形（数・番号・種類・大きさ）
i=6
bad_index=0
for edit in 's/^parts=1$/parts=2/' 's/^part-0001 /part-0002 /' 's/^kind=db$/kind=files/' 's/^\(part-0001\) [0-9]* /\1 0 /' 's/^\(part-0001\) [0-9]* /\1 99999999999 /' 's/^format=1$/format=2/' '$a extra line'; do
    f="$(fname "$i")"
    i=$((i + 1))
    fake_set db "$f" "$(ec_offsite_stamp)" yes "$check/fake.enc" "$edit"
    refused "$f" 'INDEX の形が違います' || { bad_index=1; ng "偽の組: 形の違う INDEX を通した（${edit}: $(cat "$check/out")）"; }
done
[ "$bad_index" -eq 0 ] && ok "偽の組: INDEX の形（切れ端の数・番号・種類・大きさ 0 と大きすぎ・形の版・余分な行）が違えば取らない"
# 形の違う tar（外へ出る道筋・決まっていない名前・リンク・同じ名前が 2 つ・穴あきで巨大・tar でないもの）。どれも、ほかの中身は本物のバックアップと同じ
cp -a "$check/orig/${B3}" "$check/evil"
mkdir "$check/evil/sub"
echo x > "$check/evil/authorized_keys"
members=(ec_central.dump ec_t_shop1.dump globals.sql manifest SHA256SUMS .complete)
tar -cf "$check/good.tar" -C "$check/evil" "${members[@]}"
tar -P -cf "$check/evil1.tar" -C "$check/evil/sub" ../ec_central.dump ../ec_t_shop1.dump ../globals.sql ../manifest ../SHA256SUMS ../.complete
tar -cf "$check/evil2.tar" -C "$check/evil" "${members[@]}" authorized_keys
cp "$check/good.tar" "$check/evil4.tar"
tar -rf "$check/evil4.tar" -C "$check/evil" manifest
cp -a "$check/evil" "$check/evil5"
truncate -s 200M "$check/evil5/ec_t_shop1.dump"
tar -S -cf "$check/evil5.tar" -C "$check/evil5" "${members[@]}"
rm "$check/evil/globals.sql"
ln -s /etc/passwd "$check/evil/globals.sql"
tar -cf "$check/evil3.tar" -C "$check/evil" "${members[@]}"
tar -tf "$check/evil1.tar" 2> /dev/null | q '^\.\./manifest$' && tar -tvf "$check/evil3.tar" | q '^l.* globals.sql -> /etc/passwd$' && [ "$(wc -c < "$check/evil5.tar")" -lt 1000000 ] || ng "確かめ用の形の違う tar を作れていない"
ec_offsite_tar_ok "$check/good.tar" && ! ec_offsite_tar_ok "$check/evil1.tar" && ! ec_offsite_tar_ok "$check/evil2.tar" && ! ec_offsite_tar_ok "$check/evil3.tar" && ! ec_offsite_tar_ok "$check/evil4.tar" &&
    ! ec_offsite_tar_ok "$check/evil5.tar" && ! ec_offsite_tar_ok "$check/evil/manifest" &&
    ok "偽の組: 外へ出る道筋・決まっていない名前・リンク・同じ名前が 2 つ・穴あきで巨大な tar は通さない（決まった名前のふつうのファイルだけ）" || ng "偽の組: 形の違う tar を通した"
# 同じものを、偽の組として置いて取る道でも断る（取る側が tar の確かめを通っていること）
bad_tar=0
for t in evil1 evil2 evil3 evil4 evil5; do
    f="$(fname "$i")"
    i=$((i + 1))
    enc "$check/$t.tar" "$check/fake.enc2"
    fake_set db "$f" "$(ec_offsite_stamp)" yes "$check/fake.enc2"
    refused "$f" 'バックアップの形ではありません' || { bad_tar=1; ng "偽の組: 形の違う tar（${t}）を取って置いた（$(cat "$check/out")）"; }
done
[ "$bad_tar" -eq 0 ] && [ ! -e "$EC_OFFSITE_WORK/tmp/out/authorized_keys" ] && ok "偽の組: 形の違う tar を暗号化して置いても、取る側は広げずに断る"
# 開けた後の指紋・manifest の形
mkshape() {
    rm -rf "$check/shape"
    cp -a "$check/orig/${B3}" "$check/shape"
}
shape_set() {
    f="$(fname "$i")"
    i=$((i + 1))
    tar -cf "$check/shape.tar" -C "$check/shape" "${members[@]}"
    enc "$check/shape.tar" "$check/fake.enc2"
    fake_set db "$f" "$(ec_offsite_stamp)" yes "$check/fake.enc2"
}
mkshape
printf 'X' >> "$check/shape/ec_central.dump"
shape_set
refused "$f" '指紋（SHA256SUMS）と違います' && ok "偽の組: 開けた中身が中の指紋（SHA256SUMS）と違えば置かない" || ng "偽の組: 指紋の合わない中身を置いた（$(cat "$check/out")）"
mkshape
printf '%s  /dev/zero\n' "$(printf x | ec_integrity_sha_in)" >> "$check/shape/SHA256SUMS"
shape_set
refused "$f" 'manifest の形が違います' && ok "偽の組: 指紋（SHA256SUMS）に決まっていない名前の行（/dev/zero など）があれば、照らす前に止める" || ng "偽の組: 外の道筋の行を sha256sum に渡した（$(cat "$check/out")）"
mkshape
printf 'time=%s\nreason=ok\033[2K\rall good\ncommit=\ndatabases=ec_central ec_t_shop1\nprefix=ec_t_\nshops=1\nfiles=none\nadmin_host=admin.offsite-check.test\n' "$(ec_now)" > "$check/shape/manifest"
shape_set
refused "$f" 'manifest の形が違います' && ok "偽の組: manifest に端末の制御文字があれば置かない（戻す前の確認の画面を書き換えさせない）" || ng "偽の組: 制御文字つきの manifest を置いた（$(cat "$check/out")）"
mkshape
printf 'evil=1\n' >> "$check/shape/manifest"
shape_set
refused "$f" 'manifest の形が違います' && ok "偽の組: manifest に決まっていない鍵があれば置かない" || ng "偽の組: 知らない鍵つきの manifest を置いた（$(cat "$check/out")）"
pull_done

# ---------- 8. 連鎖の先頭 ----------
: > "$check/run.out"
n="$(puts)"
run && run && [ "$(puts)" = "$n" ] && [ -z "$(state offsite_heads)" ] && [ "$(grep -c x "$check/dc.lists")" = 1 ] && [ "$(ec_state_get offsite_heads_cmd)" = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa no" ] &&
    ok "先頭: アプリにコマンドが無ければ飛ばす（state に書かない。ここまでの全部の回で、聞いたのはバージョンごとの 1 回だけ）" || ng "先頭: コマンドが無い時の形が違う（$(grep -c x "$check/dc.lists" 2> /dev/null) $(state offsite_heads)）"
printf 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\n' > "$EC_STATE/current_commit"
printf 'operations:alert   知らせの係\noperations:chain-heads   連鎖の先頭を出す\n' > "$check/artisan.list"
h1="$(printf a | ec_integrity_sha_in)"
h2="$(printf b | ec_integrity_sha_in)"
printf 'shop1\t120\t%s\t2026-11-19T04:10:00+09:00\ndemo-sake\t7\t%s\t2026-11-19T03:00:00Z\n' "$h1" "$h2" > "$check/heads.out"
run || ng "先頭を送れない（$(cat "$check/run.out")）"
hk="$(s3_keys | grep '^heads/admin.offsite-check.test/[0-9]\{4\}/[0-9]\{2\}/[0-9]\{2\}/[0-9]\{8\}T[0-9]\{6\}Z\.tsv$' || true)"
[ "$(printf '%s\n' "$hk" | grep -c .)" = 1 ] && s3 -o "$check/heads.got" "$E/$S3_BUCKET/$hk" && cmp -s "$check/heads.got" "$check/heads.out" && [ "$(state offsite_heads)" = ok ] && ec_valid_time "$(state offsite_heads_at)" &&
    ok "先頭: コマンドがあれば、形を確かめて平文の TSV を heads/ へ送る（state に ok と時刻）" || ng "先頭: 送った形が違う（${hk} $(state offsite_heads)）"
run && [ "$(s3_keys | grep -c '^heads/' || true)" = 1 ] && ok "先頭: 前に送った中身と同じなら送らない" || ng "先頭: 同じ中身を 2 度送った"
sleep 1
printf 'shop1\t121\t%s\t2026-11-19T04:20:00+09:00\ndemo-sake\t7\t%s\t2026-11-19T03:00:00Z\n' "$h2" "$h2" > "$check/heads.out"
run && [ "$(s3_keys | grep -c '^heads/' || true)" = 2 ] && ok "先頭: 変わったら新しい名前で送る" || ng "先頭: 変わったのに送らない"
bad_heads() {
    local rc=0
    # shellcheck disable=SC2059
    printf "$@" > "$check/heads.out"
    run || rc=$?
    [ "$rc" -eq 1 ] && [ "$(s3_keys | grep -c '^heads/' || true)" = 2 ] && [ "$(state offsite_heads)" = failed ]
}
bad_heads 'shop1\t121\t%s\t2026-11-19T04:20:00+09:00\textra\n' "$h1" && ok "先頭: 余分な欄のある行は送らない（失敗として残す）" || ng "先頭: 余分な欄を送った"
bad_heads 'shop1\t121\t%s%s\t2026-11-19T04:20:00+09:00\n' "$h1" "$h1" && ok "先頭: 長すぎる値は送らない" || ng "先頭: 長い値を送った"
bad_heads 'shop1\t121\tnot-a-hash\t2026-11-19T04:20:00+09:00\n' && ok "先頭: hash でない値は送らない" || ng "先頭: hash でない値を送った"
bad_heads 'shop1\t121\t%s\t2026-11-19T04:20:00+09:00\n\nshop2 x\n' "$h1" && ok "先頭: 空の行・形の違う行が混じれば送らない" || ng "先頭: 形の違う行を送った"
printf 'shop1\t121\t%s\t2026-11-19T04:20:00+09:00\ndemo-sake\t7\t%s\t2026-11-19T03:00:00Z\n' "$h2" "$h2" > "$check/heads.out"
run && [ "$(state offsite_heads)" = ok ] && ok "先頭: 直れば ok に戻る" || ng "先頭: 戻らない"
printf 'operations:alert   知らせの係\n' > "$check/artisan.list"
printf 'cccccccccccccccccccccccccccccccccccccccc\n' > "$EC_STATE/current_commit"

# ---------- 10c. 置き場のロックの確かめ・隔離した写し ----------
answers "OFFSITE_ENDPOINT=$E" "OFFSITE_BUCKET=$S3_BUCKET" "OFFSITE_LOCK_CHECK=yes"
mkbackup ${B4} 0
ec_offsite_stage "$EC_BACKUP/${B4}"
: > "$check/run.out"
: > "$check/curl.args"
run || ng "ロックの確かめを入れた回が失敗した"
[ "$(state offsite_lock)" = open ] && grep -q '断りませんでした' "$check/run.out" && [ "$(grep -c '/_probe/' "$check/curl.args")" = 9 ] && grep -q -- '-X DELETE' "$check/curl.args" &&
    ok "ロック: 送れた回に db・files・heads の 3 つの前置きへ印を置き、上書きと削除が通れば open と知らせる（消せない期間の決まりの無い置き場）" || ng "ロック: open にならない（$(state offsite_lock)）"
: > "$check/lock409"
mkbackup ${B5} 0
ec_offsite_stage "$EC_BACKUP/${B5}"
run && [ "$(state offsite_lock)" = ok ] && ok "ロック: 上書きと削除が断られれば（409）ok" || ng "ロック: ok にならない（$(state offsite_lock)）"
# db/ にだけ決まりが掛かっている置き場（files/・heads/ は上書きと削除が通る）は open
printf '/db/' > "$check/lock409"
ec_offsite_lock_probe && [ "$EC_OFFSITE_LOCK_WORD" = open ] && ok "ロック: db/ だけ断って files/・heads/ が通る置き場は open（前置きごとに見る）" || ng "ロック: 一部の前置きだけの決まりを ok にした（${EC_OFFSITE_LOCK_WORD}）"
: > "$check/lock409"
ec_offsite_lock_probe && [ "$EC_OFFSITE_LOCK_WORD" = ok ] || ng "ロック: 3 つとも断る置き場が ok にならない"
rm -f "$check/lock409"
n="$(grep -c '/_probe/' "$check/curl.args")"
run && [ "$(grep -c '/_probe/' "$check/curl.args")" = "$n" ] && ok "ロック: バックアップを送らない回は印を置かない" || ng "ロック: 送らない回に印を置いた"
: > "$EC_STATE/isolated"
mkbackup ${B6} 0
ec_offsite_stage "$EC_BACKUP/${B6}"
n="$(puts)"
run && [ "$(puts)" = "$n" ] && [ ! -e "$EC_OFFSITE_WORK/stage/${B6}" ] && ok "隔離した写し: 控えを作らず、送らない" || ng "隔離した写しから送った"
rm -f "$EC_STATE/isolated"

# ---------- 9. state の形と、報告の引数 ----------
bad=0
while IFS= read -r line; do
    case "$line" in
        offsite=ok | offsite=failed | offsite_lock=ok | offsite_lock=open | offsite_lock=unknown | offsite_heads=ok | offsite_heads=failed) ;;
        reason= | reason=config | reason=curl-old | reason=no-gpg | reason=no-recipient | reason=bad-recipient | reason=put | reason=gave-up) ;;
        offsite_at=* | offsite_tried_at=* | offsite_heads_at=*) ec_valid_time "${line#*=}" || bad=1 ;;
        last_backup=*) ec_offsite_valid_name "${line#*=}" || bad=1 ;;
        # アプリへ知らせた公開鍵の指紋（公開してよい値）
        recipient_told=*) [[ "${line#*=}" =~ ^[0-9A-F]{40,64}$ ]] || bad=1 ;;
        *) bad=1 ;;
    esac
done < "$EC_STATE/offsite"
[ "$bad" -eq 0 ] && [ "$(cut -d= -f1 "$EC_STATE/offsite" | sort | tr '\n' ' ')" = "last_backup offsite offsite_at offsite_heads offsite_heads_at offsite_lock offsite_tried_at reason recipient_told " ] && [ "$(stat -c %a "$EC_STATE/offsite")" = 600 ] &&
    ok "state: 名前は offsite・offsite_at・offsite_tried_at・offsite_lock・offsite_heads・offsite_heads_at（と last_backup・reason・recipient_told）、値は決まった語・時刻・公開鍵の指紋だけ" || ng "state の形が違う（$(tr '\n' ' ' < "$EC_STATE/offsite")）"
out="$(ec_report_state_args | grep -- '--offsite' | tr '\n' ' ' || true)"
[[ "$out" == "--offsite=ok --offsite-tried-at="* ]] && [[ "$out" == *" --offsite-at="* ]] && [[ "$out" == *" --offsite-lock=ok "* ]] && [[ "$out" == *" --offsite-heads=ok --offsite-heads-at="* ]] && [[ "$out" != *"${B5}"* ]] && [[ "$out" != *"$S3_SECRET"* ]] && [[ "$out" != *"$S3_BUCKET"* ]] &&
    ok "報告: 運営の様子への引数に、結果・時刻・ロック・先頭を --offsite… で出す（鍵・バケット・バックアップの名前は出さない）" || ng "報告の引数が違う（${out}）"
out="$(tool offsite status 2>&1)" && [[ "$out" == *"最後に送れた    バックアップ ${B5}"* ]] && [[ "$out" == *"ストレージのロック  効いている"* ]] && [[ "$out" == *"ハッシュチェーンの先頭  送れている"* ]] && [[ "$out" != *"$S3_SECRET"* ]] &&
    ok "status: 設定・最後の結果・ロック・先頭を見せる（鍵は出さない）" || ng "status が違う（${out}）"
# ---------- 11. 本物のコマンド（ec-sugar offsite test・pull・run） ----------
# 道具の写しの「入口に使える形」だけを http に広げて流す（ほかは 1 文字も変えない。手元の偽の置き場は http のため）
cp -a /repo/deploy "$check/deploy"
sed -i 's/^EC_OFFSITE_SCHEMES=https$/EC_OFFSITE_SCHEMES="https http"/' "$check/deploy/lib/common.sh"
[ "$(diff /repo/deploy/lib/common.sh "$check/deploy/lib/common.sh" | grep -c '^[<>]')" = 2 ] || ng "道具の写しを作れていない"
tool2() { bash "$check/deploy/ec-sugar" "$@"; }
rc=0
out="$(tool2 offsite test 2>&1)" || rc=$?
[ "$rc" -eq 1 ] && [[ "$out" == *"公開鍵で暗号化できる"* ]] && [[ "$out" == *"ストレージに書ける"* ]] && [[ "$out" == *"断りませんでした"* ]] && [[ "$out" != *"読めない"* ]] && [[ "$out" != *"$S3_SECRET"* ]] &&
    ok "offsite test: 書ける・暗号化できると見せる。上書きと削除が通る置き場では 1 で終わる（消えた印を「読めない鍵」と取り違えない）" || ng "offsite test が違う（rc=${rc} ${out}）"
: > "$check/lock409"
rc=0
out="$(tool2 offsite test 2>&1)" || rc=$?
[ "$rc" -eq 0 ] && [[ "$out" == *"上書きと削除を断った"* ]] && [[ "$out" == *"ストレージを読めます"* ]] && ok "offsite test: 上書きと削除が断られる置き場では 0。このサーバーの鍵で読めることも知らせる" || ng "offsite test が、断る置き場で通らない（rc=${rc} ${out}）"
rm -f "$check/lock409"
out="$(tool2 offsite pull --list --credentials-file "$check/read.credentials" 2>&1)" && [[ "$out" == *"db     ${B5}  ストレージの側の時刻 20"* ]] && ok "offsite pull --list: 置き場のそろっている組を見せる" || ng "offsite pull --list が違う（${out}）"
rc=0
out="$(tool2 offsite pull --latest 2>&1)" || rc=$?
[ "$rc" -ne 0 ] && [[ "$out" == *"--credentials-file"* ]] && ok "offsite pull: 読む鍵を渡さなければ断る（サーバーの書く鍵を黙って使わない）" || ng "offsite pull が鍵なしで動いた"
cp -a "$EC_BACKUP/${B5}" "$check/orig/"
rm -rf "$EC_BACKUP/${B5}"
# ここまでの確かめ（同じシェルで送る回と取る支度を交ぜた）が残した一時の置き場を片付けてから、本物のコマンドが残さないことを見る
rm -rf /run/ec-sugar/offsite-gpg.*
rc=0
out="$(tool2 offsite pull ${B5} --credentials-file "$check/read.credentials" --decrypt-key-file "$check/owner.key" 2>&1)" || rc=$?
[ "$rc" -eq 0 ] && diff -r "$check/orig/${B5}" "$EC_BACKUP/${B5}" > /dev/null && [[ "$out" == *"sudo ec-sugar restore ${B5}"* ]] &&
    [ -z "$(find /run/ec-sugar -name 'offsite-gpg.*')" ] && [ ! -e "$EC_OFFSITE_WORK/tmp" ] && [[ "$out" != *"$S3_SECRET"* ]] &&
    [[ "$out" =~ ストレージの側の時刻\ 20[0-9]{2}-[0-9]{2}-[0-9]{2}T ]] && [[ "$out" == *"管理画面 admin.offsite-check.test"* ]] &&
    ok "offsite pull: 名前で取って開き、元と同じものを置く。置き場の側の時刻と manifest の管理画面・時刻を見せる。開ける鍵を入れた一時の置き場は残さない" || ng "offsite pull が違う（rc=${rc} ${out}）"
mkbackup ${B8} 0
ec_offsite_stage "$EC_BACKUP/${B8}"
rc=0
out="$(tool2 offsite run 2>&1)" || rc=$?
[ "$rc" -eq 0 ] && [ "$(s3_keys | grep -c "^db/.*/${B8}\.[0-9TZ]*/INDEX\$" || true)" = 1 ] && [ "$(state last_backup)" = ${B8} ] && [[ "$out" != *"$S3_SECRET"* ]] &&
    ok "offsite run: 本物のコマンドで、溜まった控えを送る" || ng "offsite run が違う（rc=${rc} ${out}）"

# ---------- 12. 失敗のやり直しの間と回数・公開鍵が使えない時・消し残し・控えの段と送る回の重なり ----------
: > "$check/run.out"
EC_OFFSITE_RETRY_BASE=600
mkbackup "${B9}" 0
ec_offsite_stage "$EC_BACKUP/${B9}"
st="$EC_OFFSITE_WORK/stage/${B9}"
printf '%s' "/${B9}." > "$check/fail"
rc=0
run || rc=$?
read -r tries next < "$st/.retry" || true
[ "$rc" -eq 1 ] && [ "$(state reason)" = put ] && [ "$tries" = 1 ] && [ "$next" -ge $(($(date +%s) + 590)) ] && [ "$next" -le $(($(date +%s) + 600)) ] && ok "やり直し: 失敗を数え、次に試すのは 10 分後" || ng "やり直し: 失敗の数えが違う（rc=${rc} ${tries} ${next}）"
n="$(puts)"
tried="$(state offsite_tried_at)"
rc=0
run || rc=$?
[ "$rc" -eq 0 ] && [ "$(puts)" = "$n" ] && [ "$(state offsite)" = failed ] && [ "$(state offsite_tried_at)" = "$tried" ] && ok "やり直し: 間が空くまでは送り直さない（消せない切れ端を 10 分ごとに増やさない）。state は失敗のまま" || ng "やり直し: 間を空けずに送り直した"
rc=0
run retry || rc=$?
[ "$rc" -eq 1 ] && [ "$(puts)" -gt "$n" ] && [ "$(cut -d' ' -f1 "$st/.retry")" = 1 ] && ok "やり直し: run --retry は数えを消して、今すぐ試し直す" || ng "やり直し: --retry が試し直さない"
# 間は倍々で 6 時間まで
mkdir "$check/retry"
waits=""
for _ in 1 2 3 4 5 6 7; do
    ec_offsite_retry_note "$check/retry" || true
    waits="$waits $(($(cut -d' ' -f2 "$check/retry/.retry") - $(date +%s)))"
done
[[ "$waits" =~ ^\ (59[0-9]|600)\ (119[0-9]|1200)\ (239[0-9]|2400)\ (479[0-9]|4800)\ (959[0-9]|9600)\ (1919[0-9]|19200)\ (2159[0-9]|21600)$ ]] && ok "やり直し: 間は 10 分から倍々で、6 時間まで" || ng "やり直し: 間が違う（${waits}）"
# 回数の上限
EC_OFFSITE_RETRY_BASE=0
EC_OFFSITE_TRIES_MAX=2
printf '1 0\n' > "$st/.retry"
: > "$check/run.out"
rc=0
run || rc=$?
[ "$rc" -eq 1 ] && [ "$(state reason)" = gave-up ] && grep -q 'もう自動では送り直しません' "$check/run.out" && ok "やり直し: 回数の上限まで試したら、理由を gave-up にして知らせる" || ng "やり直し: 上限で止まらない（rc=${rc} $(state reason)）"
rm -f "$check/fail"
n="$(puts)"
run && [ "$(puts)" = "$n" ] && [ -e "$st" ] && [ "$(state reason)" = gave-up ] && ok "やり直し: あきらめた控えは、置き場が直っても自動では送り直さない" || ng "やり直し: あきらめた控えを送った"
[ "$(ec_offsite_retry_state "$st")" = gave-up ] || ng "やり直し: あきらめた控えの語が違う（$(ec_offsite_retry_state "$st")）"
: > "$check/lock409"
run retry && [ ! -e "$st" ] && [ "$(state offsite)" = ok ] && [ "$(state last_backup)" = "${B9}" ] && ok "やり直し: 原因を直して run --retry すれば送れて、ok に戻る" || ng "やり直し: --retry で送れない（$(cat "$check/run.out")）"
EC_OFFSITE_TRIES_MAX=8
# 公開鍵で暗号化できない（期限切れ・壊れた）: 置き場の失敗と取り違えない・やり直しの回数に数えない・何も送らない
mkbackup "${B10}" 0
ec_offsite_stage "$EC_BACKUP/${B10}"
st="$EC_OFFSITE_WORK/stage/${B10}"
cp -p "$EC_OFFSITE_DIR/recipient.asc" "$check/recipient.keep"
chmod 0600 "$EC_OFFSITE_DIR/recipient.asc"
printf -- '-----BEGIN PGP PUBLIC KEY BLOCK-----\n\nbm90IGEga2V5\n-----END PGP PUBLIC KEY BLOCK-----\n' > "$EC_OFFSITE_DIR/recipient.asc"
n="$(puts)"
rc=0
run || rc=$?
[ "$rc" -eq 1 ] && [ "$(state reason)" = bad-recipient ] && [ "$(puts)" = "$n" ] && [ ! -e "$st/.retry" ] && grep -q '暗号化できないので送っていません' "$check/run.out" &&
    ok "公開鍵: 暗号化できない公開鍵では何も送らず、理由を bad-recipient にする（置き場の失敗と分ける）" || ng "公開鍵: 使えない鍵の時の形が違う（rc=${rc} $(state reason)）"
cat "$check/recipient.keep" > "$EC_OFFSITE_DIR/recipient.asc"
chmod 0400 "$EC_OFFSITE_DIR/recipient.asc"
run && [ ! -e "$st" ] && [ "$(state offsite)" = ok ] || ng "公開鍵: 戻しても送れない"
# 管理画面で作った復元のキットの鍵を採る: 公開鍵が無い時だけ・アプリの答えを確かめ直してから。置いてある鍵は替えない
fix14=/repo/tests/Support/fixtures/recovery-kit-public.asc
fixfpr=FF14D9B119B27A8850228B7DD4F3B7D4ADCB18CC
ownfpr="$(ec_offsite_recipient_fpr "$check/recipient.keep")"
rm -f "$EC_OFFSITE_DIR/recipient.asc" "$check/recipient.app"
n="$(puts)"
rc=0
run || rc=$?
[ "$rc" -eq 1 ] && [ "$(state reason)" = no-recipient ] && [ "$(puts)" = "$n" ] && [ ! -e "$EC_OFFSITE_DIR/recipient.asc" ] && grep -q '復元のキット' "$check/run.out" &&
    ok "鍵を採る: 公開鍵が無く、管理画面でもまだ作っていなければ、何も送らず「復元のキットを作る」と案内する" || ng "鍵を採る: 鍵が無い時の形が違う（rc=${rc} $(state reason)）"
bad=""
for case14 in private wrongfpr garbage twice nofpr; do
    case "$case14" in
        private) { echo "$fixfpr"; sed 's/PUBLIC KEY/PRIVATE KEY/' "$fix14"; } > "$check/recipient.app" ;;
        wrongfpr) { echo "$ownfpr"; cat "$fix14"; } > "$check/recipient.app" ;;
        garbage) printf '%s\n-----BEGIN PGP PUBLIC KEY BLOCK-----\n\nbm90IGEga2V5\n-----END PGP PUBLIC KEY BLOCK-----\n' "$fixfpr" > "$check/recipient.app" ;;
        twice) { echo "$fixfpr"; cat "$fix14" "$fix14"; } > "$check/recipient.app" ;;
        nofpr) cat "$fix14" > "$check/recipient.app" ;;
    esac
    rc=0
    run || rc=$?
    { [ "$rc" -eq 1 ] && [ "$(state reason)" = no-recipient ] && [ ! -e "$EC_OFFSITE_DIR/recipient.asc" ] && [ "$(puts)" = "$n" ] && [ -z "$(find "$EC_OFFSITE_DIR" -name '.adopt.*')" ]; } || bad="${bad} ${case14}"
done
[ -z "$bad" ] && ok "鍵を採る: アプリの答えを信じない（秘密の鍵の包み・指紋が違う・鍵でない・包みが 2 つ・指紋の行が無い、は採らず、何も送らない）" || ng "鍵を採る: 採ってはいけない答えを採った（${bad}）"
{ echo "$fixfpr"; cat "$fix14"; } > "$check/recipient.app"
ec_offsite_adopt_recipient > "$check/adopt.out" 2>&1 && [ "$(stat -c '%a %U' "$EC_OFFSITE_DIR/recipient.asc")" = "400 root" ] && [ "$(ec_offsite_recipient_fpr "$EC_OFFSITE_DIR/recipient.asc")" = "$fixfpr" ] && grep -qF "$fixfpr" "$check/adopt.out" &&
    ok "鍵を採る: ブラウザで作った鍵（RSA 4096）を、この gpg で確かめて採る（root だけの 0400。指紋を見せる）" || ng "鍵を採る: 正しい答えを採れない（$(cat "$check/adopt.out")）"
{ echo "$ownfpr"; cat "$check/recipient.keep"; } > "$check/recipient.app"
ec_offsite_adopt_recipient > /dev/null 2>&1 && [ "$(ec_offsite_recipient_fpr "$EC_OFFSITE_DIR/recipient.asc")" = "$fixfpr" ] &&
    ok "鍵を採る: 置いてある公開鍵は、アプリが別の鍵を答えても替えない" || ng "鍵を採る: 置いてある鍵を替えた"
rm -f "$check/recipient.app" "$check/adopt.out"
chmod 0600 "$EC_OFFSITE_DIR/recipient.asc"
cat "$check/recipient.keep" > "$EC_OFFSITE_DIR/recipient.asc"
chmod 0400 "$EC_OFFSITE_DIR/recipient.asc"
# 送る控えが無い回でも、支度（公開鍵）が整えば、前の「送れていない」を消す（次の控えができるまで知らせが出続けない）
run && [ "$(state offsite)" = ok ] && [ -z "$(state reason)" ] && ok "鍵を採る: 公開鍵が整った後の回は、送る控えが無くても、前の「公開鍵が無い」の失敗を消す" ||
    ng "鍵を採る: 公開鍵が整っても失敗が残る（$(state offsite) $(state reason)）"
# いま使っている公開鍵の指紋を、アプリへ知らせる（管理画面が、キットの鍵と同じかを比べる）。同じ指紋は 1 回だけ
run
run
[ "$(cat "$check/recipient.told")" = "$ownfpr" ] && [ "$(wc -l < "$check/recipient.told" | tr -d ' ')" = 1 ] && [ "$(state recipient_told)" = "$ownfpr" ] &&
    ok "鍵を知らせる: いま暗号化に使っている公開鍵の指紋を、アプリへ 1 回だけ知らせる（指紋だけ）" || ng "鍵を知らせる: 知らせ方が違う（$(tr '\n' ' ' < "$check/recipient.told")）"
# 送れずに間を空けている控えが残っている時は、支度の失敗を消さない（送れていないのは本当）
mkdir -p "$EC_OFFSITE_WORK/stage/20260101-000000"
: > "$EC_OFFSITE_WORK/stage/20260101-000000/.complete"
printf '1 %s\n' "$(($(date +%s) + 3600))" > "$EC_OFFSITE_WORK/stage/20260101-000000/.retry"
ec_offsite_state_set offsite=failed reason=no-recipient
run || true
keep14="$(state offsite) $(state reason)"
rm -rf "$EC_OFFSITE_WORK/stage/20260101-000000"
ec_offsite_state_set offsite=ok reason=
[ "$keep14" = "failed no-recipient" ] && ok "鍵を採る: 間を空けている控えが残っている間は、前の失敗を消さない（送れていないのは本当）" || ng "鍵を採る: 控えが残っているのに失敗を消した（${keep14}）"

# 強く切られた前の回の消し残し（gpg の一時の置き場・切れ端）は、次の回が錠を取った直後に片付ける
mkdir -p /run/ec-sugar/offsite-gpg.stale "$EC_OFFSITE_WORK/tmp"
echo secret > /run/ec-sugar/offsite-gpg.stale/private-key
echo plain > "$EC_OFFSITE_WORK/tmp/db.tar"
run && [ ! -e /run/ec-sugar/offsite-gpg.stale ] && [ ! -e "$EC_OFFSITE_WORK/tmp" ] && ok "消し残し: 前の回が残した gpg の一時の置き場と開けた途中のものは、次の回が片付ける" || ng "消し残しが残った"
# 控えの段は、送る回が動いている間（送る回の錠が取られている間）は古い控えを外さない
for b in "${B11}" "${B12}" "${B13}" "${B14}" "${B15}"; do mkbackup "$b" 0; done
(
    flock 9
    sleep 4
) 9> "$EC_OFFSITE_LOCK" &
holder=$!
sleep 1
out=""
for b in "${B11}" "${B12}" "${B13}" "${B14}"; do out="$out$(ec_offsite_stage "$EC_BACKUP/$b" 2>&1)"; done
[ "$(find "$EC_OFFSITE_WORK/stage" -mindepth 1 -maxdepth 1 -type d ! -name '.*' | wc -l)" = 4 ] && [ -z "$out" ] && ok "重なり: 送る回が動いている間は、溜まった控えを外さない（送っている最中の控えを消さない）" || ng "重なり: 送っている間に控えを外した（${out}）"
wait "$holder"
out="$(ec_offsite_stage "$EC_BACKUP/${B15}" 2>&1)"
[ "$(find "$EC_OFFSITE_WORK/stage" -mindepth 1 -maxdepth 1 -type d ! -name '.*' | sort | tr '\n' ' ')" = "$EC_OFFSITE_WORK/stage/${B13} $EC_OFFSITE_WORK/stage/${B14} $EC_OFFSITE_WORK/stage/${B15} " ] && [[ "$out" == *"${B11}"*"${B12}"* ]] &&
    ok "重なり: 送る回が終われば、古い方から外して 3 つまでにし、外したことを知らせる" || ng "重なり: 外し方が違う（${out}）"
rm -rf "$EC_OFFSITE_WORK"/stage/*
rm -f "$check/lock409"

# ---------- 13. 公開鍵の指紋・差し替え・元のファイルの持ち主、隔離した写しの test ----------
fpr="$(gpg --homedir "$check/gh" --batch --no-options --with-colons --import-options show-only --import "$check/owner.pub" 2> /dev/null | awk -F: '$1 == "fpr" {print $10; exit}')"
[ "${#fpr}" -ge 40 ] && [ "$(ec_offsite_recipient_fpr "$EC_OFFSITE_DIR/recipient.asc")" = "$fpr" ] && out="$(tool offsite status 2>&1)" && [[ "$out" == *"公開鍵の指紋    $fpr"* ]] &&
    ok "指紋: status が、置いてある公開鍵の指紋を見せる" || ng "指紋: status に公開鍵の指紋が出ない（${fpr}）"
answers "OFFSITE_ENDPOINT=$E" "OFFSITE_BUCKET=$S3_BUCKET" "OFFSITE_KEY_FILE=$check/write.credentials" "OFFSITE_RECIPIENT_FILE=$check/owner.pub" "OFFSITE_LOCK_CHECK=yes"
(ec_offsite_put_secrets) > "$check/out" 2>&1 && grep -q "公開鍵の指紋 $fpr" "$check/out" && ok "指紋: インストーラーが、置いた公開鍵の指紋を見せる（同じ鍵の流し直しは通る）" || ng "指紋: インストーラーに指紋が出ない（$(cat "$check/out")）"
answers "OFFSITE_ENDPOINT=$E" "OFFSITE_BUCKET=$S3_BUCKET" "OFFSITE_KEY_FILE=$check/write.credentials" "OFFSITE_RECIPIENT_FILE=$check/other.pub" "OFFSITE_LOCK_CHECK=yes"
rc=0
(ec_offsite_put_secrets) > "$check/out" 2>&1 || rc=$?
[ "$rc" -ne 0 ] && grep -q '黙っては置き換えません' "$check/out" && grep -qF -- "$fpr" "$check/out" && cmp -s "$EC_OFFSITE_DIR/recipient.asc" "$check/owner.pub" &&
    ok "差し替え: 流し直しで公開鍵の元のファイルが別の鍵になっていたら、入れ替えずに止まる（前と今度の指紋を見せる）" || ng "差し替え: 別の公開鍵に黙って入れ替えた（$(cat "$check/out")）"
cp "$check/owner.pub" "$check/loose.pub"
chmod 0666 "$check/loose.pub"
answers "OFFSITE_ENDPOINT=$E" "OFFSITE_BUCKET=$S3_BUCKET" "OFFSITE_KEY_FILE=$check/write.credentials" "OFFSITE_RECIPIENT_FILE=$check/loose.pub"
dies && grep -q 'ほかの人が書ける' "$check/out" && ok "元のファイル: ほかの人が書ける公開鍵のファイルは断る" || ng "元のファイル: ほかの人が書ける公開鍵を通した"
ln -s "$check/write.credentials" "$check/link.credentials"
answers "OFFSITE_ENDPOINT=$E" "OFFSITE_BUCKET=$S3_BUCKET" "OFFSITE_KEY_FILE=$check/link.credentials" "OFFSITE_RECIPIENT_FILE=$check/owner.pub"
dies && grep -q 'リンク' "$check/out" && ok "元のファイル: リンクの鍵のファイルは断る" || ng "元のファイル: リンクの鍵を通した"
cp "$check/write.credentials" "$check/nobody.credentials"
chown 65534 "$check/nobody.credentials"
answers "OFFSITE_ENDPOINT=$E" "OFFSITE_BUCKET=$S3_BUCKET" "OFFSITE_KEY_FILE=$check/nobody.credentials" "OFFSITE_RECIPIENT_FILE=$check/owner.pub"
dies && grep -q 'root の持ち物でない' "$check/out" && ok "元のファイル: root の持ち物でない鍵のファイルは断る" || ng "元のファイル: root の持ち物でない鍵を通した"
answers "OFFSITE_ENDPOINT=$E" "OFFSITE_BUCKET=$S3_BUCKET" "OFFSITE_LOCK_CHECK=yes"
: > "$EC_STATE/isolated"
n="$(puts)"
rc=0
out="$(tool2 offsite test 2>&1)" || rc=$?
[ "$rc" -ne 0 ] && [[ "$out" == *"隔離した複製環境"* ]] && [ "$(puts)" = "$n" ] && ok "隔離した写し: offsite test も、置き場に印を置かずに断る" || ng "隔離した写しで test が印を置いた（${out}）"
rm -f "$EC_STATE/isolated"

# 設定を外す（鍵のファイルを消す）と、送る回は何もしない
mv "$EC_OFFSITE_DIR/credentials" "$check/credentials.off"
mkbackup ${B7} 0
n="$(puts)"
ec_offsite_stage "$EC_BACKUP/${B7}" && run && [ "$(puts)" = "$n" ] && [ ! -e "$EC_OFFSITE_WORK/stage/${B7}" ] && ok "設定を外す: 鍵のファイルを消せば、控えも送る回も止まる" || ng "設定を外しても送った"

# ---------- 14. 復元のキット: 作る → キットで取る → 元と同じ中身 ----------
# 持ち主の手元の道具（deploy/recovery-kit）でキットを作り、本物のコマンド（ec-sugar offsite pull --kit）で取る。開ける鍵は合い言葉つき
# （キットの中の合い言葉で開ける道を通す）。gpg も「引数と環境を書き残して本物へ渡す」ものに差し替えて、鍵と合い言葉が引数・環境変数に出ないことを見る
command -v python3 > /dev/null 2>&1 && command -v jq > /dev/null 2>&1 || { echo "NG: python3 か jq が無い（復元のキットを確かめられない）"; exit 1; }
real_gpg="$(command -v gpg)"
mkdir "$check/bin14"
cat > "$check/bin14/gpg" << EOF
#!/bin/bash
printf '%s\n' "\$*" >> $check/gpg.args
env >> $check/gpg.env
exec $real_gpg "\$@"
EOF
chmod +x "$check/bin14/gpg"
# 本物の道具は https の入口しか受けない（替える引数も環境変数も無い）。偽の置き場は http なので、その 1 行を書き換えた写しを使う
sed 's/^ALLOW_HTTP = False$/ALLOW_HTTP = True/' /repo/deploy/recovery-kit > "$check/recovery-kit-http"
[ "$(grep -c '^ALLOW_HTTP = True$' "$check/recovery-kit-http")" = 1 ] || ng "キット: 確かめ用の写しを作れない（ALLOW_HTTP の行が無い）"
kit() { PATH="$check/bin14:$PATH" GNUPGHOME="$check/kg" bash "$check/recovery-kit-http" "$@"; }
kit_real() { PATH="$check/bin14:$PATH" GNUPGHOME="$check/kg" bash /repo/deploy/recovery-kit "$@"; }
tool14() { PATH="$check/bin14:$PATH" bash "$check/deploy/ec-sugar" "$@"; }
keypass14='decrypt key passphrase 14'
kitpass14='recovery kit passphrase 14'
app14="base64:$(printf 'KITAPPKEY%.0s' 1 2 3 4)KITAPPK="
leak14() { grep -c -F -e "$S3_SECRET" -e "$S3_ID" -e "$keypass14" -e "$kitpass14" -e "${app14#base64:}" "$@" 2> /dev/null || true; }
left14() { find /run/ec-sugar -mindepth 1 \( -name 'kit.*' -o -name 'offsite-gpg.*' \) 2> /dev/null | tr '\n' ' '; [ ! -e "$EC_OFFSITE_WORK/tmp" ] || printf 'tmp'; }
(umask 077; printf 'APP_NAME=EC-SUGAR\nAPP_KEY=%s\n' "$app14" > "$check/kit-app-key"; printf '%s\n' "$kitpass14" > "$check/kitpp"; printf 'not the kit passphrase\n' > "$check/kitpp-wrong")
# 持ち主の手元の鍵束（使い捨て）: 開ける鍵を入れて、合い言葉を付ける
mkdir -m 700 "$check/kg"
gpg --homedir "$check/kg" --batch --no-options --quiet --import "$check/owner.key" > /dev/null 2>&1
ofpr="$(gpg --homedir "$check/kg" --batch --no-options --with-colons --list-secret-keys 2> /dev/null | awk -F: '$1 == "fpr" {print $10; exit}')"
printf '%s\n' "$keypass14" | gpg --homedir "$check/kg" --batch --no-options --quiet --pinentry-mode loopback --passphrase-fd 0 --change-passphrase "$ofpr" > /dev/null 2>&1 || true
gpgconf --homedir "$check/kg" --kill all > /dev/null 2>&1 || true
printf 'mark14' > "$check/mark14"
enc "$check/mark14" "$check/mark14.gpg"
if gpg --homedir "$check/kg" --batch --no-options --quiet --pinentry-mode loopback --passphrase '' --decrypt "$check/mark14.gpg" > /dev/null 2>&1; then
    ng "キット: 確かめ用の開ける鍵に合い言葉を付けられていない（合い言葉つきの鍵の道を確かめられない）"
fi
gpgconf --homedir "$check/kg" --kill all > /dev/null 2>&1 || true
kit_args=(--server admin.offsite-check.test --endpoint "$E" --bucket "$S3_BUCKET" --gpg-key "$ofpr" --app-key-file "$check/kit-app-key")

# 作る前の断り: 本物の道具は http の入口を使い方の誤りとして断り、--allow-http のような引数も知らない。置き場に断られる読む鍵ではキットを作らない
rc=0
out="$(kit_real create "$check/kit.gpg" "${kit_args[@]}" --read-key-file "$check/read.credentials" --allow-http < /dev/null 2>&1)" || rc=$?
[ "$rc" -eq 2 ] && [ ! -e "$check/kit.gpg" ] && ok "キット: 入口の守りを外す引数は無い（--allow-http は知らない引数）" || ng "キット: 守りを外す引数を受けた（rc=${rc} ${out}）"
rc=0
out="$(kit_real create "$check/kit.gpg" "${kit_args[@]}" --read-key-file "$check/read.credentials" < /dev/null 2>&1)" || rc=$?
[ "$rc" -eq 2 ] && [ ! -e "$check/kit.gpg" ] && ok "キット: http の入口のキットは作らない（本物の道具は https だけ）" || ng "キット: http の入口を通した（rc=${rc} ${out}）"
(umask 077; printf 'access_key_id=%s\nsecret_access_key=%s\n' "$S3_ID" "NotTheSecret$(printf 'Xx%.0s' 1 2 3 4 5 6 7 8 9 10)" > "$check/wrong.credentials")
rc=0
out="$(printf '%s\n%s\n%s\n' "$keypass14" "$kitpass14" "$kitpass14" | kit create "$check/kit.gpg" "${kit_args[@]}" --read-key-file "$check/wrong.credentials" --verify-bucket 2>&1)" || rc=$?
[ "$rc" -eq 1 ] && [ ! -e "$check/kit.gpg" ] && [[ "$out" == *"キットは作っていません"* ]] && ok "キット: 読む鍵が置き場に断られたら、キットを作らない（--verify-bucket。鍵の取り違えに作る時に気づく）" || ng "キット: 断られる読む鍵でキットを作った（rc=${rc} ${out}）"

# 作る（読む鍵で置き場の一覧を 1 回引いて確かめる）→ 確かめる
: > "$check/curl.args"
: > "$check/curl.env"
: > "$check/curl.stdin"
: > "$check/gpg.args"
: > "$check/gpg.env"
rc=0
out="$(printf '%s\n%s\n%s\n' "$keypass14" "$kitpass14" "$kitpass14" | kit create "$check/kit.gpg" "${kit_args[@]}" --read-key-file "$check/read.credentials" --verify-bucket 2>&1)" || rc=$?
all14="$out"
[ "$rc" -eq 0 ] && [ "$(stat -c '%a' "$check/kit.gpg")" = 600 ] && [[ "$out" == *"読み取り用のアクセスキーでストレージに届く（サーバーの名前 admin.offsite-check.test のバックアップがある）"* ]] && [[ "$out" == *"秘密鍵の指紋    ${ofpr}（パスフレーズ あり）"* ]] &&
    ! grep -a -q -F -e "$S3_ID" -e "$S3_BUCKET" -e 'BEGIN PGP' -e 'recovery-kit' "$check/kit.gpg" &&
    ok "キット: recovery-kit create が、読む鍵で置き場に届くことを確かめて、本人だけの 0600 のキットを作る（ファイルに平文が無い）" || ng "キット: recovery-kit create が違う（rc=${rc} ${out}）"
rc=0
out="$(printf '%s\n' "$kitpass14" | kit check "$check/kit.gpg" --verify-bucket 2>&1)" || rc=$?
all14="$all14"$'\n'"$out"
[ "$rc" -eq 0 ] && [[ "$out" == *"秘密鍵で、暗号化した印を復号できた"* ]] && [[ "$out" == *"読み取り用のアクセスキーでストレージに届く"* ]] && ok "キット: recovery-kit check が、開けて・開ける鍵を試して・置き場に届くことを確かめる" || ng "キット: recovery-kit check が違う（rc=${rc} ${out}）"
[ -z "$(find /tmp /dev/shm /run -maxdepth 2 -name 'ec-kit.*' 2> /dev/null)" ] && ! grep -rqF -e 'ec-sugar-recovery-kit' /tmp /dev/shm /run /root "$EC_OFFSITE_WORK" "$EC_STATE" "$EC_BACKUP" 2> /dev/null &&
    ok "キット: 作る・確かめる道具は、gpg の一時の鍵束も平文の JSON も残さない" || ng "キット: 一時の鍵束か平文の JSON が残った"

# キットで取る（答えには入口もバケットも無い。全部キットの中から）
: > "$EC_ANSWERS"
rm -rf "$EC_BACKUP/${B5}" /run/ec-sugar/offsite-gpg.* /run/ec-sugar/kit.*
install -d -m 0700 /run/ec-sugar/kit.stale
printf 'stale' > /run/ec-sugar/kit.stale/credentials
rc=0
out="$(tool14 offsite pull --list --kit "$check/kit.gpg" --kit-passphrase-file "$check/kitpp" 2>&1)" || rc=$?
all14="$all14"$'\n'"$out"
[ "$rc" -eq 0 ] && [[ "$out" == *"db     ${B5}  ストレージの側の時刻 20"* ]] && [ -z "$(left14)" ] &&
    ok "キットで取る: --list が、キットの中の読む鍵・入口・バケット・サーバーの名前で一覧を見せる。前の回の消し残しのキットは片付ける" || ng "キットで取る: --list が違う（rc=${rc} ${out} 残り: $(left14)）"
rc=0
out="$(tool14 offsite pull "${B5}" --kit "$check/kit.gpg" --kit-passphrase-file "$check/kitpp" 2>&1)" || rc=$?
all14="$all14"$'\n'"$out"
[ "$rc" -eq 0 ] && diff -r "$check/orig/${B5}" "$EC_BACKUP/${B5}" > /dev/null && (cd "$EC_BACKUP/${B5}" && sha256sum --status -c SHA256SUMS) &&
    ok "キットで取る: 名前で取って開くと、元と同じ中身（合い言葉つきの開ける鍵を、キットの中の合い言葉で開ける）" || ng "キットで取る: 元と違う（rc=${rc} ${out}）"
[[ "$out" == *"戻すには: sudo ec-sugar restore ${B5} --kit $check/kit.gpg"* ]] && [[ "$out" != *"--app-key-file"* ]] && ok "キットで取る: 終わりの「戻すには」は --kit の形" || ng "キットで取る: 「戻すには」が --kit の形でない（${out}）"
[ -z "$(left14)" ] && ! grep -rqF -e "$S3_SECRET" -e "$keypass14" -e "$kitpass14" -e "${app14#base64:}" -e 'ec-sugar-recovery-kit' /run /tmp /dev/shm /root "$EC_OFFSITE_WORK" "$EC_STATE" "$EC_BACKUP" 2> /dev/null &&
    ok "キットで取る: 開いた中身（読む鍵・開ける鍵・合い言葉・APP_KEY・平文の JSON）は、終わった後に /run にもディスクにも残らない" || ng "キットで取る: 開いた中身が残った（$(left14)）"
# 鍵と合い言葉が、curl と gpg の引数・環境変数・画面に出ない（空振りでないことも見る: 鍵は curl の標準入力の設定で、合い言葉は gpg へ口かファイルの道筋で渡っている）
[ "$(leak14 "$check/curl.args" "$check/curl.env" "$check/gpg.args" "$check/gpg.env" | awk -F: '{s += $NF} END {print s + 0}')" = 0 ] && [ "$(printf '%s\n' "$all14" | leak14)" = 0 ] &&
    ok "キット: 鍵・合い言葉・APP_KEY が、curl と gpg の引数・環境変数・画面に出ない（作る・確かめる・取るの全部）" || ng "キット: 鍵か合い言葉が引数・環境変数・画面に出た（$(leak14 "$check/curl.args" "$check/curl.env" "$check/gpg.args" "$check/gpg.env" | tr '\n' ' ')）"
grep -q "user = \"$S3_ID:$S3_SECRET\"" "$check/curl.stdin" && grep -q -- '--passphrase-fd' "$check/gpg.args" && grep -q -- "--passphrase-file $check/kitpp" "$check/gpg.args" && grep -q -- '--passphrase-file /run/ec-sugar/kit\.' "$check/gpg.args" &&
    ok "キット: 鍵は curl の標準入力の設定で、合い言葉は gpg へ口（--passphrase-fd）かファイルの道筋で渡っている（確かめは空振りしていない）" || ng "キット: 鍵・合い言葉の渡し方が想定と違う（確かめが空振りしている）"

# 合い言葉が違えば開けず、何も置かない
rm -rf "$EC_BACKUP/${B5}"
rc=0
out="$(tool14 offsite pull "${B5}" --kit "$check/kit.gpg" --kit-passphrase-file "$check/kitpp-wrong" 2>&1)" || rc=$?
[ "$rc" -ne 0 ] && [[ "$out" == *"復号できません"* ]] && [ ! -e "$EC_BACKUP/${B5}" ] && [ -z "$(left14)" ] && [ "$(printf '%s\n' "$out" | leak14)" = 0 ] &&
    ok "キットで取る: 合い言葉が違えば開けず、何も置かない・何も残さない" || ng "キットで取る: 違う合い言葉で動いた（rc=${rc} ${out} 残り: $(left14)）"
# 端末が無く、合い言葉のファイルも無ければ聞けないと断る
rc=0
out="$(tool14 offsite pull "${B5}" --kit "$check/kit.gpg" < /dev/null 2>&1)" || rc=$?
[ "$rc" -ne 0 ] && [[ "$out" == *"--kit-passphrase-file"* ]] && [ ! -e "$EC_BACKUP/${B5}" ] && [ -z "$(left14)" ] && ok "キットで取る: 端末が無ければ、合い言葉のファイルを求めて止まる" || ng "キットで取る: 端末なしで動いた（rc=${rc} ${out}）"
# 形の違うキット（同じ合い言葉で包み直したもの）と、暗号化されていない平文・暗号でない包み
good14="$(gpg --homedir "$check/gh" --batch --no-options --quiet --pinentry-mode loopback --passphrase-file "$check/kitpp" --decrypt "$check/kit.gpg" 2> /dev/null || true)"
seal14() { (umask 077; gpg --homedir "$check/gh" --batch --no-options --quiet --pinentry-mode loopback --passphrase-file "$check/kitpp" --symmetric --cipher-algo AES256 --output "$check/bad-$1.gpg" 2> /dev/null); }
printf '%s' "$good14" | jq -c '. + {"note": "x"}' | seal14 extra
printf '%s' "$good14" | jq -c '.format = 2' | seal14 format
printf '%s' "$good14" | jq -c 'del(.decrypt_key)' | seal14 missing
printf '%s' "$good14" | jq -c '.offsite.endpoint = "http://s3:9000/path"' | seal14 endpoint
printf '%s' "$good14" | jq -c '.offsite.secret_access_key = "bad\"quote-0123456789"' | seal14 secret
printf 'not json at all\n' | seal14 nojson
(umask 077; printf '%s' "$good14" > "$check/bad-plain.gpg"; gpg --homedir "$check/gh" --batch --no-options --quiet --compress-algo none --store --output "$check/bad-store.gpg" "$check/bad-plain.gpg" 2> /dev/null)
bad14=""
for name in extra format missing endpoint secret nojson plain store; do
    [ -s "$check/bad-$name.gpg" ] || { bad14="${bad14} ${name}（作れない）"; continue; }
    rc=0
    out="$(tool14 offsite pull "${B5}" --kit "$check/bad-$name.gpg" --kit-passphrase-file "$check/kitpp" 2>&1)" || rc=$?
    { [ "$rc" -ne 0 ] && [[ "$out" == *"止めました"* ]] && [ ! -e "$EC_BACKUP/${B5}" ] && [ -z "$(left14)" ] && [ "$(printf '%s\n' "$out" | leak14)" = 0 ]; } || bad14="${bad14} ${name}（rc=${rc}）"
done
rm -f "$check/bad-plain.gpg"
[ -n "$good14" ] && [ -z "$bad14" ] && ok "キットで取る: 形の違うキット（知らない欄・format 違い・欄が足りない・道筋つきの入口・形の違う鍵・JSON でない・平文の JSON・暗号でない包み）を、中身を出さずに断り、何も置かない" ||
    ng "キットで取る: 形の違うキットを断らない（${bad14}）"
# 管理画面で作ったキット: 置き場と読む鍵が入っていない（開ける鍵と APP_KEY だけ）。置き場の入口・バケット・読む鍵を一緒に渡せば取れる
printf '%s' "$good14" | jq -c 'del(.offsite)' | seal14 nooffsite
rm -rf "$EC_BACKUP/${B5}"
rc=0
out="$(tool14 offsite pull "${B5}" --kit "$check/bad-nooffsite.gpg" --kit-passphrase-file "$check/kitpp" 2>&1)" || rc=$?
[ "$rc" -ne 0 ] && [[ "$out" == *"ストレージと読み取り用のアクセスキーが入っていません"* ]] && [[ "$out" == *"--credentials-file"* ]] && [ ! -e "$EC_BACKUP/${B5}" ] && [ -z "$(left14)" ] &&
    ok "キットで取る: 置き場と読む鍵の無いキットだけでは取らず、一緒に渡すものを案内する" || ng "キットで取る: 置き場の無いキットだけで動いた（rc=${rc} ${out}）"
rc=0
out="$(tool14 offsite pull "${B5}" --kit "$check/bad-nooffsite.gpg" --kit-passphrase-file "$check/kitpp" --credentials-file "$check/read.credentials" --endpoint "$E" --bucket "$S3_BUCKET" 2>&1)" || rc=$?
[ "$rc" -eq 0 ] && [ -e "$EC_BACKUP/${B5}/.complete" ] && [ -z "$(left14)" ] && [ "$(printf '%s\n' "$out" | leak14)" = 0 ] &&
    ok "キットで取る: 置き場と読む鍵の無いキットでも、入口・バケット・読む鍵を一緒に渡せば取れる（開ける鍵とサーバーの名前はキットから）" || ng "キットで取る: 置き場の無いキットで取れない（rc=${rc} ${out} 残り: $(left14)）"
rm -rf "$EC_BACKUP/${B5}"
rc=0
out="$(kit check "$check/bad-nooffsite.gpg" < "$check/kitpp" 2>&1)" || rc=$?
[ "$rc" -eq 0 ] && [[ "$out" == *"秘密鍵で、暗号化した印を復号できた"* ]] && [[ "$out" == *"入っていない"* ]] &&
    ok "キット: recovery-kit check が、置き場と読む鍵の無いキット（管理画面で作ったもの）も確かめる" || ng "キット: 置き場の無いキットを確かめられない（rc=${rc} ${out}）"
good14=""
# 本物の道具（https だけ）は、http の入口のキットを断る
rc=0
out="$(PATH="$check/bin14:$PATH" tool offsite pull --list --kit "$check/kit.gpg" --kit-passphrase-file "$check/kitpp" 2>&1)" || rc=$?
[ "$rc" -ne 0 ] && [[ "$out" == *"https://<名前> の形でありません"* ]] && [ -z "$(left14)" ] && ok "キットで取る: 本物の道具は、http の入口のキットを断る（鍵を平文の道へ送らない）" || ng "キットで取る: http の入口のキットを通した（rc=${rc} ${out}）"
# キットと、ばらの鍵・入口の引数を同時に渡すと断る
bad14=""
for a in "--credentials-file $check/read.credentials" "--decrypt-key-file $check/owner.key" "--passphrase-file $check/kitpp" "--endpoint $E" "--bucket $S3_BUCKET" "--prefix admin.offsite-check.test" "--region auto"; do
    rc=0
    # shellcheck disable=SC2086
    out="$(tool14 offsite pull "${B5}" --kit "$check/kit.gpg" --kit-passphrase-file "$check/kitpp" $a 2>&1)" || rc=$?
    { [ "$rc" -ne 0 ] && [[ "$out" == *"同時に使えません"* ]] && [ ! -e "$EC_BACKUP/${B5}" ]; } || bad14="${bad14} [${a%% *}]"
done
rc=0
out="$(tool14 restore "${B5}" --kit "$check/kit.gpg" --app-key-file "$check/kit-app-key" 2>&1)" || rc=$?
{ [ "$rc" -ne 0 ] && [[ "$out" == *"同時に使えません"* ]]; } || bad14="${bad14} [restore --app-key-file]"
[ -z "$bad14" ] && ok "キット: --kit と、ばらの鍵・入口の引数を同時に渡すと断る（pull の 7 つと、restore の --app-key-file）" || ng "キット: 同時に渡しても断らない（${bad14}）"
# ほかの人が読めるキットと合い言葉のファイルを断る
cp "$check/kit.gpg" "$check/kit-loose.gpg"
chmod 0644 "$check/kit-loose.gpg"
cp "$check/kitpp" "$check/kitpp-loose"
chmod 0640 "$check/kitpp-loose"
rc=0
out="$(tool14 offsite pull "${B5}" --kit "$check/kit-loose.gpg" --kit-passphrase-file "$check/kitpp" 2>&1)" || rc=$?
rc2=0
out2="$(tool14 offsite pull "${B5}" --kit "$check/kit.gpg" --kit-passphrase-file "$check/kitpp-loose" 2>&1)" || rc2=$?
[ "$rc" -ne 0 ] && [[ "$out" == *"復元のキットのファイル"*"ほかの利用者から読めます"* ]] && [ "$rc2" -ne 0 ] && [[ "$out2" == *"パスフレーズのファイル"*"ほかの利用者から読めます"* ]] && [ ! -e "$EC_BACKUP/${B5}" ] && [ -z "$(left14)" ] &&
    ok "キットで取る: ほかの人が読めるキットと合い言葉のファイルは断る" || ng "キットで取る: 読めるファイルを通した（${out} ${out2}）"
# 割り込み: 置き場の答えを待っている間に止めても、開いた中身を消してから終わる
: > "$check/slow"
setsid env PATH="$check/bin14:$PATH" bash "$check/deploy/ec-sugar" offsite pull "${B5}" --kit "$check/kit.gpg" --kit-passphrase-file "$check/kitpp" > "$check/int.out" 2>&1 &
pid14=$!
seen14=0
for _ in $(seq 1 100); do
    if [ -n "$(find /run/ec-sugar -mindepth 2 -maxdepth 2 -path '*/kit.*/credentials' 2> /dev/null)" ]; then seen14=1; break; fi
    sleep 0.1
done
sleep 0.5
kill -TERM -- "-$pid14" 2> /dev/null || kill -TERM "$pid14" 2> /dev/null || true
wait "$pid14" 2> /dev/null || true
rm -f "$check/slow"
[ "$seen14" -eq 1 ] && [ -z "$(left14)" ] && [ ! -e "$EC_BACKUP/${B5}" ] && ok "キットで取る: 途中で止めても（TERM）、開いた中身と一時の鍵束を消してから終わる" || ng "キットで取る: 止めた後に開いた中身が残った（開いたのを見たか: ${seen14} 残り: $(left14) $(tail -n 3 "$check/int.out" | tr '\n' ' ')）"
gpgconf --homedir "$check/kg" --kill all > /dev/null 2>&1 || true

[ "$fails" -eq 0 ] || { echo "NG が ${fails} 件"; exit 1; }
echo "すべて ok（送る・取る）"
