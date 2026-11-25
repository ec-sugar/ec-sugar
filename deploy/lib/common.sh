# shellcheck shell=bash
# EC-SUGAR のインストーラー（deploy/install.sh）と運用のコマンド（deploy/ec-sugar）が共通で使う部品（ADR 0030）
#
# 決まり:
#   - 合言葉や鍵の値を、外のコマンドの引数に渡さない（ps で見える）。env ファイルの読み書きは bash の中だけで行う
#   - 文はすべて日本語。何をしているか・なぜ止まったか・どう直すかを 1 行ずつ出す
#   - git の操作は専用の利用者 ec-sugar で行う（取ってきた中身を root で扱わない）。docker は root で動かす
#   - イメージを得る所は ec_obtain_image の 1 つだけ（後で「置き場から取って署名を確かめる」に差し替える。ADR 0030 区切り 2）

# 管の先で「あるか」を見る grep -q の代わり（どこからでも使う）。grep -q は見つけた時点で読むのをやめるので、前のコマンドが書き込みの途中だと
# 管が切れ、pipefail の下では「無い」と同じ失敗になる（タイミングしだいで、たまにだけ起きる。コマンドの有無や動いているかを読み違える）。
# 先に全部を受け取ってから探す。引数は grep -q の後ろに付けていたものと同じ（-x・-F・-- など）
ec_q() {
    local in
    in="$(cat)"
    if [ -z "$in" ]; then grep -q "$@" < /dev/null; else grep -q "$@" <<< "$in"; fi
}

# ---------- 置き場（変えない） ----------
# 読み込む側（install.sh・ec-sugar）だけが使う変数もあるので、使われない変数の注意（SC2034）はこの節では出さない
# shellcheck disable=SC2034
EC_ROOT=/srv/ec-sugar
EC_SRC=$EC_ROOT/src
# 公開版の形（CHANNEL=release）で compose が読む一式（compose のファイルと、compose が差し込む docker/ の中身）を、確かめたコミットの git の中身から
# 取り出す root だけの置き場（コミットごとに 1 つ）。作業場所 $EC_SRC は利用者 ec-sugar が書けるので、公開版の形では compose に使わない
EC_COMPOSE_ROOT=$EC_ROOT/compose
EC_ENV_DIR=$EC_ROOT/env
EC_ENV_FILE=$EC_ENV_DIR/.env.production
EC_SECRETS=$EC_ROOT/secrets
# 前の形（nginx が証明書を読んでいた頃）の置き場。手で用意した証明書を新しい置き場へ移すときに読むだけ
EC_TLS_DIR=$EC_SECRETS/tls
# 入口の Caddy の秘密（DNS の API の鍵・手で用意した証明書と鍵）。Caddy の利用者（コンテナの中の 65532）だけが読める
EC_CADDY_SECRETS=$EC_SECRETS/caddy
EC_CADDY_UID=65532
# すでにある Web サーバーの後ろに置く形で、入口の nginx が待つ Unix ソケットの置き場（ホスト）。前の Web サーバーの利用者のグループだけが入れる
EC_FRONT_SOCKET_DIR=/var/lib/ec-sugar/front
EC_FRONT_SOCKET=$EC_FRONT_SOCKET_DIR/web.sock
# ソケットの置き場のグループ（EC-SUGAR の nginx のコンテナが group_add で足し、ソケットを作る）。前の Web サーバーには ACL で通り抜けだけを許す
EC_FRONT_SOCKET_GROUP=ec-sugar-front
# ソケットに入れるグループにしてはいけない名前（ホスティングの契約者・人の利用者・管理者が共有する）
EC_DENY_PROXY_GROUPS="root adm sudo wheel staff users nogroup daemon sys bin psacln psaserv psaadm ftp sftp"
# Cloudflare Tunnel で出す形（FRONT=tunnel。ADR 0052）の cloudflared の設定（config.yml）とトークン（token）の置き場。
# root の持ち物・cloudflared の利用者（コンテナの中の 65533）のグループ・0750、中のファイルは 0440。compose へは EC_SUGAR_TUNNEL_DIR で渡し、読み取り専用で差し込む
EC_TUNNEL_DIR=$EC_SECRETS/tunnel
EC_TUNNEL_UID=65533
# cloudflared が要求を渡す先（入口の Caddy の 443 番。tunnel の網の中の名前）
EC_TUNNEL_SERVICE=https://caddy:443
# Cloudflare の Origin CA の元の証明書（cloudflared が入口の Caddy の証明書を確かめる元）。手で渡した証明書（manual）が Origin CA のものの時だけ、
# 置き場にこの名前で写し、config.yml の originRequest.caPool でコンテナの中の道筋を指す（見直しの中 3）
EC_TUNNEL_CA_NAME=origin-ca.pem
EC_TUNNEL_CA_IN=/etc/cloudflared/$EC_TUNNEL_CA_NAME
# 写す元（リポジトリの deploy/cloudflare/origin-ca-roots.crt。install.sh が自分の取り出した場所から入れる。ec-sugar は使わない）
EC_ORIGIN_CA_ROOTS=""
# トンネルの見張り（漏れたトークンで足された接続役に気づく。見直しの中 5）の、読むだけの Cloudflare の API の鍵の置き場。
# root だけの 0700、鍵は 0400。cloudflared には読ませない（トンネルの置き場とは分ける）
EC_TUNNEL_WATCH_DIR=$EC_SECRETS/tunnel-watch
# 見張りが聞く先（Cloudflare の API と、このサーバーを外から見た住所。IPv4・IPv6）。環境変数では替えない（root の鍵を送る先なので）
EC_CF_API=https://api.cloudflare.com/client/v4
EC_TRACE_URL4=https://1.1.1.1/cdn-cgi/trace
EC_TRACE_URL6='https://[2606:4700:4700::1111]/cdn-cgi/trace'
# 接続役が 0 と出たときに、聞き直すまで待つ秒数（更新で cloudflared を作り直した直後の数秒を「落ちた」と知らせない）
EC_TUNNEL_WATCH_RETRY_WAIT=30
EC_BACKUP=$EC_ROOT/backup
EC_STATE=$EC_ROOT/state
EC_LOGS=$EC_STATE/logs
EC_HOME=$EC_ROOT/home
EC_ANSWERS=$EC_ROOT/install.answers
EC_USER=ec-sugar
EC_PROJECT=ec-sugar
EC_IMAGE_REPO=ec-sugar
EC_LIB_DIR=/usr/local/lib/ec-sugar
EC_BIN=/usr/local/bin/ec-sugar
# 錠（root だけの置き場の中。ec_lock_dir。前の置き場 /run/ec-sugar.lock はだれでも握れた）
EC_LOCK=/run/ec-sugar/lock
EC_UNIT_DIR=/etc/systemd/system
# 控えを残す日数（ADR 0029: 7 日分）
EC_BACKUP_KEEP_DAYS=7
# バックアップに入れるファイル（storage の下の写真・これから入るダウンロード販売のファイルなど。DB の dump と同じ置き場に 1 つの tar で。ADR 0082）
EC_BACKUP_FILES_NAME=storage.tar
# 写真などのファイルの tar を残す世代の数（新しい方から。DB の dump は上の 7 日のまま。運営の判断、2026-11-06。毎朝 1 回なので 3 日分ほど）
EC_BACKUP_FILES_KEEP=3
# 健全の確かめを待つ秒数
EC_HEALTH_TIMEOUT=240
# キューの見本の仕事を待つ秒数（たまった仕事の後ろに並ぶので、余裕を見る）
EC_QUEUE_PROBE_TIMEOUT=90

# ---------- 文の出し方 ----------
if [ -t 1 ]; then
    EC_C_STEP=$'\033[1;36m' EC_C_WARN=$'\033[1;33m' EC_C_ERR=$'\033[1;31m' EC_C_OK=$'\033[32m' EC_C_END=$'\033[0m'
else
    EC_C_STEP='' EC_C_WARN='' EC_C_ERR='' EC_C_OK='' EC_C_END=''
fi
ec_step() { printf '\n%s== %s%s\n' "$EC_C_STEP" "$*" "$EC_C_END"; }
# ウィザードの段の頭（ADR 0031）。$1 段の番号 $2 段の数 $3 名前 $4 何をするか（1 行）
ec_stage() {
    printf '\n%s[%s/%s] %s%s\n' "$EC_C_STEP" "$1" "$2" "$3" "$EC_C_END"
    [ -z "${4:-}" ] || printf '   %s\n' "$4"
}
ec_say() { printf '   %s\n' "$*"; }
ec_ok() { printf '   %s✓%s %s\n' "$EC_C_OK" "$EC_C_END" "$*"; }
ec_warn() { printf '   %s注意%s %s\n' "$EC_C_WARN" "$EC_C_END" "$*" >&2; }
# 止める。1 つ目は理由、2 つ目からは直し方（1 行ずつ）
ec_die() {
    local why="$1"
    shift
    printf '\n%s止めました%s: %s\n' "$EC_C_ERR" "$EC_C_END" "$why" >&2
    local line
    for line in "$@"; do printf '   直し方: %s\n' "$line" >&2; done
    exit 1
}

# 確かめの一覧（環境の確かめで、全部を一度に ✓／✗ で見せる）。途中で止めずに貯めて、最後に ec_check_report で出す
EC_CHECK_LINES=()
EC_CHECK_NG=0
# $1 何を確かめたか（✓ の行）
ec_check_ok() { EC_CHECK_LINES+=("$(printf '   %s✓%s %s' "$EC_C_OK" "$EC_C_END" "$1")"); }
# $1 何が足りないか、2 つ目からは直し方（1 行ずつ）
ec_check_ng() {
    local line
    EC_CHECK_LINES+=("$(printf '   %s✗%s %s' "$EC_C_ERR" "$EC_C_END" "$1")")
    shift
    for line in "$@"; do EC_CHECK_LINES+=("       直し方: $line"); done
    EC_CHECK_NG=$((EC_CHECK_NG + 1))
}
# 止めはしないが知らせること
ec_check_warn() {
    local line
    EC_CHECK_LINES+=("$(printf '   %s!%s %s' "$EC_C_WARN" "$EC_C_END" "$1")")
    shift
    for line in "$@"; do EC_CHECK_LINES+=("       $line"); done
}
# 一覧を出す。✗ の数を返す（0 ならすべて ✓）
ec_check_report() {
    local line
    for line in "${EC_CHECK_LINES[@]}"; do printf '%s\n' "$line"; done
    return "$EC_CHECK_NG"
}

ec_require_root() {
    [ "$(id -u)" -eq 0 ] || ec_die "root で実行する必要があります" "前に sudo を付けて再実行する（例: sudo $0 …）"
}

# 錠の置き場（root だけの 0700。/run は起動のたびに空になるので、使うたびに作る）。前の置き場（/run/ec-sugar.lock、0644）は
# root でない人も開けて flock で握れた（読み取りの fd でも排他の錠を取れる）ので、更新を止めたり中の照らしを busy にし続けたりできた
ec_lock_dir() {
    local d
    d="$(dirname "$EC_LOCK")"
    install -d -m 0700 "$d" 2> /dev/null || [ -d "$d" ] || return 1
    [ ! -L "$d" ] || return 1
    chmod 0700 "$d" 2> /dev/null || true
    if [ "$(id -u)" -eq 0 ]; then chown 0:0 "$d" || return 1; fi
}
# 同時に 2 つ動かさない（タイマーの更新と手の操作がぶつからないように）。$1 が auto なら、ふさがっているときは静かに抜ける。
# 前のバージョンの道具（前の置き場の錠）が更新の途中で、この道具の install-units を呼んだ時は、新しい置き場の錠は空いているので取れる
# （前の道具は新しい置き場を知らない）。この道具の更新の中から呼ぶ install-units は EC_SUGAR_LOCK_HELD=1 で錠を取り直さない
ec_lock() {
    ec_lock_dir || ec_die "ロックのディレクトリ $(dirname "$EC_LOCK") を作れません" "sudo ls -ld $(dirname "$EC_LOCK") で見る（root の 0700 のディレクトリ）"
    exec 9> "$EC_LOCK"
    if ! flock -n 9; then
        if [ "${1:-}" = auto ]; then
            ec_say "ほかの ec-sugar の処理が動いているので、今回は見送ります"
            exit 0
        fi
        ec_die "ほかの ec-sugar の処理（更新・バックアップ・インストール）が動いています" "終わるのを待ってから再実行する（sudo ec-sugar status で状態を見る）"
    fi
}

# ---------- 値の確かめ ----------
ec_valid_host() {
    # 小文字の英数字とハイフンのラベルを点でつなぐ。末尾は英字のラベル。全体 253 文字まで
    [ "${#1}" -le 253 ] && [[ "$1" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$ ]]
}
ec_valid_ipv4() {
    local IFS=. o
    [[ "$1" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || return 1
    for o in $1; do [ $((10#$o)) -le 255 ] || return 1; done
}
ec_valid_cidr4() {
    local ip="${1%/*}" len="${1#*/}"
    [[ "$1" == */* ]] || len=32
    ec_valid_ipv4 "$ip" && [[ "$len" =~ ^[0-9]{1,2}$ ]] && [ "$len" -le 32 ]
}
# IPv6 は形だけを見る（16 進と : と /。細かい確かめはアプリの読み込みに任せる）
ec_valid_cidr6() { [[ "$1" =~ ^[0-9a-fA-F:]+(/[0-9]{1,3})?$ ]] && [[ "$1" == *:* ]]; }
ec_valid_cidr_list() {
    local item
    [ -n "$1" ] || return 1
    local IFS=,
    for item in $1; do
        ec_valid_cidr4 "$item" || ec_valid_cidr6 "$item" || return 1
    done
}
ec_valid_email() { [ "${#1}" -le 254 ] && [[ "$1" =~ ^[A-Za-z0-9._%+-]+@([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]]; }
# git の枝・タグ・コミットの名前（- で始まるものはコマンドの引数と取り違えるので断る）
ec_valid_ref() { [[ "$1" =~ ^[A-Za-z0-9._][A-Za-z0-9._/-]{0,199}$ ]] && [[ "$1" != *..* ]]; }
# ホスト名の 1 段（点を含まない。英小文字・数字・ハイフン、先頭と末尾は英数字、63 文字まで）
ec_valid_label() { [[ "$1" =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$ ]]; }
ec_valid_commit() { [[ "$1" =~ ^[0-9a-f]{40}$ ]]; }
# 店の ID（店の住所 <ID>.<ショップのドメイン> と店の DB の名前に使う）。php artisan shop:create と同じ決まり（app/Admin/Console/ShopCreateCommand.php）
EC_RESERVED_SHOP_IDS="admin api app assets cdn internal mail root smtp static staging status system www"
ec_valid_shop_id() {
    [[ "$1" =~ ^[a-z0-9][a-z0-9-]{0,30}[a-z0-9]$ ]] && [[ " $EC_RESERVED_SHOP_IDS " != *" $1 "* ]]
}
# Cloudflare の API の鍵の形。形の違う鍵を Caddy に渡すと、部品（caddy-dns/cloudflare）は鍵の中身を誤りの文に入れて記録に出すので、先に断る。
# 部品 v0.2.4 の確かめ方（cloudflare.go）と同じ: 従来の鍵は英数字と _ - の 35〜50 文字、新しい形は cfut_・cfat_ で始まり後ろが 32〜256 文字。
# 部品のバージョンを上げたら、ここも合わせる（docker/caddy/Dockerfile の CLOUDFLARE_VERSION）
ec_valid_cf_token() { [[ "$1" =~ ^[A-Za-z0-9_-]{35,50}$ ]] || [[ "$1" =~ ^cf(ut|at)_[A-Za-z0-9_-]{32,256}$ ]]; }

# ---------- key=value のファイル（env・答え・記録）。外のコマンドに値を渡さない ----------
# $1 ファイル $2 鍵。値を出す（無ければ何も出さず 1 を返す）。値の前後の " は外す
ec_kv_get() {
    local file="$1" key="$2" line val
    [ -r "$file" ] || return 1
    while IFS= read -r line || [ -n "$line" ]; do
        if [[ "$line" == "$key="* ]]; then
            val="${line#"$key="}"
            if [[ "$val" == \"*\" ]] && [ "${#val}" -ge 2 ]; then val="${val:1:${#val}-2}"; fi
            printf '%s' "$val"
            return 0
        fi
    done < "$file"
    return 1
}
# $1 ファイル $2 鍵 $3 値。最初の「鍵=」の行を置き換え、無ければ足す。同じ置き場の仮のファイルに書いてから入れ替える（途中で切れても壊れない）。
# 仮のファイルは umask 077 の下で作る（合言葉を書いている途中にほかの利用者から読めないように）。元のファイルがあれば、権限と持ち主を
# 先に写してから（cp -p。GNU と BSD の両方で動く）中身を書く
ec_kv_set() {
    local file="$1" key="$2" val="$3" line done=0 tmp old_umask
    [[ "$val" != *$'\n'* ]] || ec_die "設定の値に改行は使えません（${key}）"
    tmp="$(dirname "$file")/.$(basename "$file").tmp.$$"
    old_umask="$(umask)"
    umask 077
    if [ -f "$file" ]; then cp -p "$file" "$tmp"; fi
    {
        if [ -f "$file" ]; then
            while IFS= read -r line || [ -n "$line" ]; do
                if [ "$done" -eq 0 ] && [[ "$line" == "$key="* ]]; then
                    printf '%s=%s\n' "$key" "$val"
                    done=1
                else
                    printf '%s\n' "$line"
                fi
            done < "$file"
        fi
        [ "$done" -eq 1 ] || printf '%s=%s\n' "$key" "$val"
    } > "$tmp"
    umask "$old_umask"
    mv -f "$tmp" "$file"
}

ec_answer() { ec_kv_get "$EC_ANSWERS" "$1"; }
# $1 ファイル $2 鍵。最初の「鍵=」の行の値を、引用符もそのまま出す（無ければ何も出さず 1 を返す）
ec_kv_raw() {
    local file="$1" key="$2" line
    [ -r "$file" ] || return 1
    while IFS= read -r line || [ -n "$line" ]; do
        if [[ "$line" == "$key="* ]]; then
            printf '%s' "${line#"$key="}"
            return 0
        fi
    done < "$file"
    return 1
}

# ---------- env の名前（ADR 0030 の「作って決めたこと」） ----------
# compose と入口が読む項目の名前は EC_SUGAR_…（例 EC_SUGAR_IMAGE）。前の名前 SUGAR_EC_… は「両方を書いて移す」間だけ読む
# （公開の前に消す。backlog の「公開の前にやること」）:
#   - サーバーに入っている前のバージョンの道具は、新しいバージョンへの更新の途中でも前の名前だけを書く（切り替える先のイメージの名前を
#     env ファイルと環境変数に）。そのため前の名前の行がある間は、前の名前の値を正とする。compose も ${SUGAR_EC_X:-${EC_SUGAR_X}} の順で読む
#   - この道具は、前の名前の行があれば両方に同じ値を書き（ec_env_set）、前の名前の行ごとに新しい名前の行を同じ値で持たせる（ec_env_migrate）。
#     前のバージョンへロールバックしても、前の名前の行が残っているので前の compose がそのまま読める
#   - 新しく入れたサーバー（deploy/production.env.example から作る env）には新しい名前だけが入る
EC_ENV_PREFIX=EC_SUGAR_
EC_ENV_OLD_PREFIX=SUGAR_EC_

# 新しい名前 $1 に対応する前の名前を出す（EC_SUGAR_ で始まらなければ 1）
ec_env_old_name() {
    [[ "$1" == "$EC_ENV_PREFIX"?* ]] || return 1
    printf '%s%s' "$EC_ENV_OLD_PREFIX" "${1#"$EC_ENV_PREFIX"}"
}
# 前の名前の行に空でない値があればそれ、無ければ新しい名前の値（compose の読み方と同じ順）
ec_env_get() {
    local old val
    if old="$(ec_env_old_name "$1")" && val="$(ec_kv_get "$EC_ENV_FILE" "$old")" && [ -n "$val" ]; then
        printf '%s' "$val"
        return 0
    fi
    ec_kv_get "$EC_ENV_FILE" "$1"
}
# 新しい名前で書く。前の名前の行があれば、そこにも同じ値を書く（前の名前の行が無いサーバーには足さない）
ec_env_set() {
    local old
    ec_kv_set "$EC_ENV_FILE" "$1" "$2"
    if old="$(ec_env_old_name "$1")" && ec_kv_raw "$EC_ENV_FILE" "$old" > /dev/null; then
        ec_kv_set "$EC_ENV_FILE" "$old" "$2"
    fi
    return 0
}
# 前の名前（SUGAR_EC_…）の行ごとに、新しい名前（EC_SUGAR_…）の行を同じ値（引用符もそのまま）で持たせる。前の名前の行は消さない。
#   - 新しい名前の行が無ければ、前の名前の行のすぐ後ろに足す。あって値が違えば、前の名前の値に合わせる（前のバージョンの道具が後から書いた値が正）
#   - ほかの行（並び・コメント・空の行）は変えない。直すことが無ければ書かない（何度流しても同じ）
#   - 書くときは同じ置き場の仮のファイルから入れ替える。仮のファイルは元の権限と持ち主を写してから（cp -p）中身を書く。値は画面にもコマンドの引数にも出さない
# $1 ファイル（既定は env）。書けなければ注意を出して 1 を返す（ファイルは元のまま）
ec_env_migrate() {
    local file="${1:-$EC_ENV_FILE}" line name val old_val out=() changed=0 seen=" " tmp old_umask
    [ -f "$file" ] || return 0
    while IFS= read -r line || [ -n "$line" ]; do
        if [[ "$line" =~ ^${EC_ENV_OLD_PREFIX}([A-Z0-9_]+)=(.*)$ ]]; then
            name="${BASH_REMATCH[1]}"
            val="${BASH_REMATCH[2]}"
            out+=("$line")
            if [[ "$seen" != *" old:$name "* ]]; then
                seen="${seen}old:$name "
                if ! ec_kv_raw "$file" "$EC_ENV_PREFIX$name" > /dev/null; then
                    out+=("$EC_ENV_PREFIX$name=$val")
                    changed=1
                fi
            fi
        elif [[ "$line" =~ ^${EC_ENV_PREFIX}([A-Z0-9_]+)=(.*)$ ]]; then
            name="${BASH_REMATCH[1]}"
            val="${BASH_REMATCH[2]}"
            if [[ "$seen" != *" new:$name "* ]]; then
                seen="${seen}new:$name "
                if old_val="$(ec_kv_raw "$file" "$EC_ENV_OLD_PREFIX$name")" && [ "$old_val" != "$val" ]; then
                    out+=("$EC_ENV_PREFIX$name=$old_val")
                    changed=1
                    continue
                fi
            fi
            out+=("$line")
        else
            out+=("$line")
        fi
    done < "$file"
    [ "$changed" -eq 1 ] || return 0
    tmp="$(dirname "$file")/.$(basename "$file").migrate.$$"
    old_umask="$(umask)"
    umask 077
    if ! cp -p "$file" "$tmp" || ! printf '%s\n' "${out[@]}" > "$tmp" || ! mv -f "$tmp" "$file"; then
        rm -f "$tmp"
        umask "$old_umask"
        ec_warn "設定 $file に新しい名前（${EC_ENV_PREFIX}…）の行を足せませんでした（元のまま。空き容量と権限を見る）"
        return 1
    fi
    umask "$old_umask"
    return 0
}
# ほかの ec-sugar の処理が鍵を持っていなければ、env の名前を移す（status・start から。持っていれば、その処理が移すので見送る）
ec_env_migrate_if_free() {
    local rc=0
    [ -f "$EC_ENV_FILE" ] || return 0
    ec_lock_dir || return 0
    exec 8> "$EC_LOCK" || return 0
    if flock -n 8; then
        ec_env_migrate || rc=1
        flock -u 8
    fi
    exec 8>&-
    return "$rc"
}
# イメージ $1 を決めて $2 から後を流す（ec_dc か ec_has_caddy。env ファイルより先に効く環境変数で渡す）。前の名前でも渡す:
# env ファイルに前の名前の行があると compose はそちらを先に読み、取り出したバージョンが前の compose（ロールバックの先）なら前の名前しか読まない
ec_with_image() {
    local image="$1"
    shift
    (
        export "${EC_ENV_PREFIX}IMAGE=$image" "${EC_ENV_OLD_PREFIX}IMAGE=$image"
        "$@"
    )
}

# 英数字だけの合言葉（Redis の設定ファイルと URL に入れても壊れない）。管道を使わない（pipefail で SIGPIPE を拾わないように）
ec_random_alnum() {
    local want="${1:-40}" out="" chunk
    while [ "${#out}" -lt "$want" ]; do
        chunk="$(LC_ALL=C tr -dc 'A-Za-z0-9' < <(head -c 512 /dev/urandom))"
        out="$out$chunk"
    done
    printf '%s' "${out:0:$want}"
}
# Laravel の APP_KEY（php artisan key:generate と同じ形: base64: ＋ 32 バイトの乱数）
ec_random_app_key() { printf 'base64:%s' "$(head -c 32 /dev/urandom | base64 -w0)"; }

# ---------- APP_KEY の控え ----------
# APP_KEY はお客さんの個人情報の暗号の鍵の元で、バックアップ（ec-sugar backup）には入らない。控えがサーバーの外に無いと、
# 機械ごと失った時にバックアップを戻しても個人情報を開けない。取ったかどうかは、管理画面の運営の様子が「控えを取りました」と答えるまで出し続ける。
# ここの 2 つは、鍵の値を読まない・画面に出さない・外のコマンドの引数に渡さない（写す時の中身は cat の標準入力と標準出力だけを通る）

# 控えの説明（何の鍵か・どこにあるか・取り方）。値は読まない
ec_app_key_explain() {
    printf 'APP_KEY のバックアップ\n\n'
    printf '  何の鍵か      お客さんの個人情報と、店ごとの鍵・店の DB のパスワードを暗号化する鍵の元です。インストーラーが 1 回だけ作り、作り直しません\n'
    printf '  どこにあるか  設定のファイル %s（root だけが読める 0600）の APP_KEY の行\n' "$EC_ENV_FILE"
    printf '                バックアップ（sudo ec-sugar backup）には入りません（鍵と中身を同じ所に置かないため）\n'
    printf '  無いと        サーバーを失った時、バックアップを新しいサーバーへ戻しても、お客さんの個人情報を復号できません\n\n'
    printf '取り方:\n'
    printf '  1. 設定のファイルを、root だけが読めるファイルにコピーする（鍵は画面に出ません）\n'
    printf '       sudo ec-sugar app-key export /root/ec-sugar-env-backup\n'
    printf '  2. そのファイルをサーバーの外（パスワードマネージャー・オフラインの保管）へ移す\n'
    printf '     手元へ持っていくのは scp か、パスワードマネージャーの CLI で。画面に出してコピーしない・チャットやメールやチケットに貼らない\n'
    printf '  3. 移したら、サーバーに残ったファイルを消す\n'
    printf '       sudo rm /root/ec-sugar-env-backup\n'
    printf '  4. 管理画面の「運営ダッシュボード」で「バックアップを取りました」を押す（通知が消えます）\n\n'
    printf '戻す時: 新しいサーバーに EC-SUGAR を入れ、このバックアップを root だけが読める 0600 のファイルで置いて（そのまま渡せます）\n'
    printf '       sudo ec-sugar restore <バックアップの名前> --app-key-file <ファイル>\n'
}

# 設定のファイルを、root だけが読める 0600 のファイル $1 に写す。断る時は理由を出して止める（何も作らない）。
#   - 行き先がもうあれば断る（上書きしない）。シンボリックリンクも断る（先の無いリンクも。作る時も noclobber〔O_EXCL〕なので、リンクをたどって書かない）
#   - 置き場（行き先のディレクトリ）は、流した人（root）の持ち物で、ほかの人が書けないこと（/tmp のような置き場は、作る前後に差し替えられる）
#   - バックアップの置き場の下には作らない（鍵と中身を同じ所に置くと、バックアップを持ち出した人が個人情報まで開ける）
ec_app_key_export() {
    local dest="${1:-}" dir base real backup
    [ -n "$dest" ] || ec_die "コピーする先のファイルを書いてください" "例 sudo ec-sugar app-key export /root/ec-sugar-env-backup"
    [ -f "$EC_ENV_FILE" ] && [ -r "$EC_ENV_FILE" ] || ec_die "設定のファイル ${EC_ENV_FILE} が読めません"
    [ -n "$(ec_env_get APP_KEY || true)" ] || ec_die "設定のファイル ${EC_ENV_FILE} に APP_KEY がありません（バックアップするものが無い）" "入れている途中なら、sudo ./deploy/install.sh を終わりまで実行する"
    [ ! -L "$dest" ] || ec_die "${dest} はシンボリックリンクです（リンクの先には書きません）" "リンクでない新しいファイルの名前を書く"
    [ ! -e "$dest" ] || ec_die "${dest} はもうあります（上書きしません）" "別の名前を書くか、要らなければ消してから再実行する"
    dir="$(dirname -- "$dest")"
    base="$(basename -- "$dest")"
    real="$(cd -P -- "$dir" 2> /dev/null && pwd)" || ec_die "ディレクトリ ${dir} がありません" "先に作るか、/root の下の名前を書く"
    [ -n "$(find "$real" -prune -user "$EUID" -print 2> /dev/null)" ] ||
        ec_die "ディレクトリ ${real} は、実行した人（root）の持ち物ではありません" "/root の下の名前を書く（例 /root/ec-sugar-env-backup）"
    [ -z "$(find "$real" -prune \( -perm -g+w -o -perm -o+w \) -print 2> /dev/null)" ] ||
        ec_die "ディレクトリ ${real} は、ほかの人が書けます（作ったファイルを差し替えられる）" "/root の下の名前を書く（例 /root/ec-sugar-env-backup）"
    # バックアップの置き場も、リンクを解いた本当の道筋で比べる
    backup="$(cd -P -- "$EC_BACKUP" 2> /dev/null && pwd)" || backup="$EC_BACKUP"
    case "$real/" in
        "$backup"/*) ec_die "バックアップの保存先 ${EC_BACKUP} の下には作りません（鍵とバックアップを同じ所に置かない）" "/root の下の名前を書く（例 /root/ec-sugar-env-backup）" ;;
    esac
    dest="$real/$base"
    # umask 077 で、無い時だけ作る（間に同じ名前のファイルやリンクを置かれても、そこへは書かない）
    (umask 077 && set -o noclobber && cat < "$EC_ENV_FILE" > "$dest") 2> /dev/null ||
        ec_die "${dest} を作れませんでした（何もコピーしていません）" "sudo ls -ld ${real} と df -h ${real} を見る"
    if ! chmod 0600 "$dest" || ! cmp -s "$EC_ENV_FILE" "$dest"; then
        rm -f "$dest"
        ec_die "${dest} にコピーした中身が元と同じになりませんでした（消しました）" "もう一度実行する"
    fi
    ec_ok "設定のファイルを ${dest} にコピーした（root だけが読める 0600。鍵は画面に出していません）"
    printf '\n   このファイルをサーバーの外（パスワードマネージャーなど）へ移し、移したらサーバーから消してください（sudo rm %s）。\n' "$dest"
    printf '   手元へ持っていくのは scp か、パスワードマネージャーの CLI で（画面に出してコピーしない・チャットやメールに貼らない）。\n'
    printf '   管理画面の運営ダッシュボードで「バックアップを取りました」を押すと、通知が消えます。\n'
}

# ---------- 記録（state） ----------
ec_state_get() { [ -r "$EC_STATE/$1" ] && head -n1 "$EC_STATE/$1"; }
ec_state_put() {
    local tmp="$EC_STATE/.$1.tmp.$$"
    printf '%s\n' "$2" > "$tmp" && mv -f "$tmp" "$EC_STATE/$1"
}
# 更新の結果を残す。$1 結果（ok・rolled_back・stopped・failed・manual_rollback〔手でロールバックした〕）$2 前のバージョン $3 新しいバージョン $4 一言。
# 最後の結果（last_update）は運営の様子にも渡る（ec_host_report）。失敗・ロールバック・止めたは、次の成功まで要対応に出る
ec_record() {
    local now tmp
    now="$(date -Is)"
    tmp="$EC_STATE/.last_update.tmp.$$"
    {
        printf 'time=%s\n' "$now"
        printf 'result=%s\n' "$1"
        printf 'from=%s\n' "$2"
        printf 'to=%s\n' "$3"
        printf 'message=%s\n' "${4//$'\n'/ }"
    } > "$tmp"
    mv -f "$tmp" "$EC_STATE/last_update"
    ec_history "$now" "$@"
}
# 更新の記録（history.log）にだけ足す（最後の結果は変えない。取得元に届かなかった時など、更新を試す前に止まったもの）。$1 時刻 $2〜$5 は ec_record と同じ
ec_history() {
    printf '%s\t%s\t%s\t%s\t%s\n' "$1" "$2" "${3:0:12}" "${4:0:12}" "${5//$'\n'/ }" >> "$EC_STATE/history.log"
}
# 最後のバックアップの結果を残す（ec_backup が呼ぶ。運営の様子に渡る）。$1 結果（ok・failed）。残せなくてもバックアップの結果は変えない
ec_record_backup() {
    local tmp="$EC_STATE/.last_backup.tmp.$$"
    if { printf 'time=%s\n' "$(date -Is)" && printf 'result=%s\n' "$1"; } > "$tmp" 2> /dev/null; then
        mv -f "$tmp" "$EC_STATE/last_backup" 2> /dev/null || true
    fi
    return 0
}
# 止めたバージョン（自動の更新で取り込み直さない）
ec_hold() {
    grep -qx "$1" "$EC_STATE/held" 2> /dev/null || printf '%s\n' "$1" >> "$EC_STATE/held"
}
ec_is_held() { grep -qx "$1" "$EC_STATE/held" 2> /dev/null; }

# ---------- git（利用者 ec-sugar で） ----------
# 環境は空から（root の環境を持ち込まない）。https の合言葉を聞いて止まらないようにする。
# setpriv で利用者を替える（runuser は呼ぶたびに PAM の記録を残し、自動の更新の記録が埋まる）
# 置き換えの印（refs/replace/）は読まない（GIT_NO_REPLACE_OBJECTS。確かめたコミットの中身を、ほかのものに差し替えて見せられないように）
ec_git() {
    (cd / && umask 022 && setpriv --reuid="$EC_USER" --regid="$EC_USER" --init-groups -- env -i HOME="$EC_HOME" PATH=/usr/sbin:/usr/bin:/sbin:/bin LANG=C.UTF-8 \
        GIT_TERMINAL_PROMPT=0 GIT_NO_REPLACE_OBJECTS=1 GIT_SSH_COMMAND="ssh -o BatchMode=yes" git -C "$EC_SRC" "$@")
}
ec_src_head() { ec_git rev-parse --verify HEAD; }
# 取り出したソースを、コンテナの利用者（DB・nginx・PHP）が読める権限にそろえる（.git は除く）。
# 流した人の umask が 077 だった・手で権限を変えた、でも、作るイメージと差し込むファイルが読めるように
ec_src_fix_modes() {
    find "$EC_SRC" -mindepth 1 -path "$EC_SRC/.git" -prune -o \( -type d ! -perm -o=rx -exec chmod go+rx {} + \) -o \( -type f ! -perm -o=r -exec chmod go+r {} + \)
}
# 作業場所を決めたコミットにする。手で変えた所があれば止める（消さない）
ec_src_checkout() {
    local commit="$1"
    ec_valid_commit "$commit" || ec_die "コミットのハッシュが正しくありません（${commit}）"
    if [ -n "$(ec_git status --porcelain --untracked-files=no)" ]; then
        ec_die "$EC_SRC に手動で変えた所があります（更新で消さないために止めました）" \
            "変えた所を確認する: sudo -u $EC_USER git -C $EC_SRC status" \
            "要らなければ元に戻す: sudo -u $EC_USER git -C $EC_SRC checkout -- ."
    fi
    ec_git -c advice.detachedHead=false checkout --quiet --detach "$commit"
    # 公開版の形は、compose の向く先も同じコミットの root だけの置き場へ切り替える（作業場所の compose は使わない）
    if [ "$(ec_channel 2> /dev/null || true)" = release ]; then
        ec_compose_use "$commit" || ec_die "バージョン ${commit:0:12} の compose を git から ${EC_COMPOSE_ROOT} へ取り出せません" \
            "sudo -u $EC_USER git -C $EC_SRC fsck で作業ツリーの git を確認する"
    fi
}

# ---------- compose ----------
ec_mode() { ec_answer MODE || printf 'production'; }
# 入口の形（答えの FRONT）: caddy（既定。EC-SUGAR の Caddy が 80・443 番と証明書を受け持つ）か proxy（すでにある Web サーバーの後ろに置く。ADR 0031 の 5）か
# tunnel（Cloudflare Tunnel で出す。caddy の形に cloudflared を重ね、Caddy は 127.0.0.1 だけで受ける。ADR 0052）
ec_front() { ec_answer FRONT || printf 'caddy'; }
# 店の数の形（答えの SHOPS。CONCEPT 4.1）: single（既定。1 システム・1 店舗。ショップのホストは自分のドメインそのもの）か multi（複数の店）。
# 答えに SHOPS が無く SHOP_DOMAIN がある（この形を足す前に入れた）なら multi のまま
ec_shops() {
    local v
    v="$(ec_answer SHOPS 2> /dev/null || true)"
    if [ -n "$v" ]; then
        printf '%s' "$v"
    elif [ -n "$(ec_answer SHOP_DOMAIN 2> /dev/null || true)" ]; then
        printf 'multi'
    else
        printf 'single'
    fi
}
# ショップのホストの親（multi）か、ショップのホストそのもの（single）。nginx・Caddy の EC_SUGAR_SHOP_DOMAIN とメールの差出人に使う
ec_shop_domain() {
    if [ "$(ec_shops)" = multi ]; then ec_answer SHOP_DOMAIN || true; else ec_answer SHOP_HOST || true; fi
}
# 最初のショップのホスト（single はショップのホスト、multi は <店の住所>.<ショップのドメイン>）。
# multi でも、1 店舗の形から広げたサーバー（答えに SHOP_HOST が残り、それがショップのドメインの直下の 1 段の名前）は、そのホストのまま
# （ADR 0090。お客さんの URL・Stripe の通知先を変えない。店の ID と 1 段目が違っても〔ID shop・ホスト store.example.jp〕そのまま）
ec_shop_host() {
    local host domain
    if [ "$(ec_shops)" = multi ]; then
        host="$(ec_answer SHOP_HOST 2> /dev/null || true)"
        domain="$(ec_answer SHOP_DOMAIN 2> /dev/null || true)"
        if [ -n "$host" ] && [ -n "$domain" ] && [[ "$host" == *".$domain" ]] && ec_valid_label "${host%".$domain"}"; then
            printf '%s' "$host"
        else
            printf '%s.%s' "$(ec_answer SHOP_ID || true)" "$domain"
        fi
    else
        ec_answer SHOP_HOST || true
    fi
}
# 店の数の形で決まる env の項目を、答えから書く（install.sh の create_env と sudo ec-sugar shops が同じこれを使う）。
# ショップのホストの親・店の数の形・入口が受ける名前（1 店舗は管理画面とショップのホストの 2 つちょうど、複数の店は管理画面と *.<ショップのドメイン>）・
# 中央のホスト（決済代行の運営あての通知を受ける。複数の店だけ api.<ショップのドメイン>。1 店舗は置かない〔ショップのホストは店のもの〕）。
# 名前の形がおかしければ何も書かずに 1
ec_env_put_shops() {
    local admin site domain
    admin="$(ec_answer ADMIN_HOST 2> /dev/null || true)"
    site="$(ec_shop_site)"
    domain="$(ec_shop_domain)"
    ec_valid_host "$admin" && ec_valid_host "${site#\*.}" && ec_valid_host "$domain" || return 1
    ec_env_set EC_SUGAR_SHOP_DOMAIN "$domain"
    ec_env_set EC_SUGAR_SHOPS "$(ec_shops)"
    ec_env_set EC_SUGAR_SITE_SHOP "$site"
    ec_env_set EC_SUGAR_SERVER_NAMES "\"$admin $site\""
    if [ "$(ec_shops)" = multi ]; then ec_env_set CENTRAL_DOMAINS "api.$domain"; else ec_env_set CENTRAL_DOMAINS ""; fi
}
# ショップの場所の名前（入口の Caddy・DNS の行の見せ方）: single はショップのホスト、multi は *.<ショップのドメイン>
ec_shop_site() {
    if [ "$(ec_shops)" = multi ]; then printf '*.%s' "$(ec_answer SHOP_DOMAIN || true)"; else ec_answer SHOP_HOST || true; fi
}
# 定期の仕事（夜の更新・朝の控え）の時刻の帯（答えの TIMEZONE、既定 Asia/Tokyo）。サーバーの時刻の帯そのものは変えない（記録の時刻がずれるため）
ec_valid_timezone() { [[ "$1" =~ ^[A-Za-z][A-Za-z0-9_+-]*(/[A-Za-z0-9_+-]+){0,2}$ ]] && [ -f "/usr/share/zoneinfo/$1" ]; }
ec_timezone() {
    local tz
    tz="$(ec_answer TIMEZONE 2> /dev/null || true)"
    ec_valid_timezone "${tz:-x}" && printf '%s' "$tz" || printf 'Asia/Tokyo'
}
# 後ろに置く形で、ソケットに入れるグループ（前の Web サーバーの働き手のグループ。答えの PROXY_GROUP）
ec_proxy_group() { ec_answer PROXY_GROUP || true; }

# 443 番を本当に持っているプロセス（ss -p の pid。ホストのもの）と、その働き手（子のプロセス）の pid を 1 行ずつ
ec_front_pids() {
    local pid
    for pid in $(ss -Hltnp '( sport = :443 )' 2> /dev/null | grep -oE 'pid=[0-9]+' | cut -d= -f2 | sort -u); do
        printf '%s\n' "$pid"
        ps --ppid "$pid" -o pid= 2> /dev/null | tr -d ' '
    done | sort -u
}
# 443 番の働き手の利用者とグループ（root でないもの。"利用者 グループ" を 1 行ずつ）
ec_front_workers() {
    local pid
    for pid in $(ec_front_pids); do
        ps -o user=,group= -p "$pid" 2> /dev/null
    done | awk '$1 != "root" && $2 != "root"' | sort -u
}
# 443 番の働き手のグループ（いちばん多いもの）。見分けられなければ空
ec_detect_front_group() {
    ec_front_workers | awk '{print $2}' | sort | uniq -c | sort -rn | awk 'NR == 1 {print $2}'
}
# 443 番の働き手の名前（nginx・apache2 など）
ec_front_comm() {
    local pid
    for pid in $(ec_front_pids); do ps -o comm= -p "$pid" 2> /dev/null; done | sort -u | head -n1
}
# グループ $1 に所属する利用者（補助の所属と、主のグループがそれの利用者）を 1 行ずつ
ec_group_members() {
    local gid
    gid="$(getent group "$1" | cut -d: -f3)"
    [ -n "$gid" ] || return 0
    { getent group "$1" | cut -d: -f4 | tr ',' '\n'; getent passwd | awk -F: -v g="$gid" '$4 == g {print $1}'; } | grep -v '^$' | sort -u || true
}
# グループ $1 をソケットに入れてよいか。だめな理由を 1 行ずつ出す（無ければ何も出さない）:
#   断る名前、前の働き手のほかの利用者が所属する（補助の所属と主のグループの両方）
ec_proxy_group_problems() {
    local group="$1" gid workers user
    [[ " $EC_DENY_PROXY_GROUPS " != *" $group "* ]] || { printf '%s は共有のグループなので使えません（ホスティングの契約者や人の利用者が所属する）\n' "$group"; return 0; }
    gid="$(getent group "$group" | cut -d: -f3)"
    [ -n "$gid" ] || { printf 'グループ %s がありません\n' "$group"; return 0; }
    workers=" $(ec_front_workers | awk -v g="$group" '$2 == g {print $1}' | tr '\n' ' ') "
    for user in $(ec_group_members "$group"); do
        [[ "$workers" == *" $user "* ]] || printf 'グループ %s に、前段のリバースプロキシのワーカーではない利用者 %s が所属しています\n' "$group" "$user"
    done
}
# 同じグループの PHP などを見分けるときに読む設定（試験で差し替える）。
# php-fpm のプール（Ubuntu の PHP と Plesk の PHP）・Apache の働き手の利用者とグループ・利用者ごとの crontab
EC_PHP_POOL_GLOBS="/etc/php/*/fpm/pool.d/*.conf /opt/plesk/php/*/etc/php-fpm.d/*.conf"
EC_APACHE_ENVVARS=/etc/apache2/envvars
EC_CRONTAB_DIR=/var/spool/cron/crontabs
# php-fpm のプールの設定 $1 から「プール 利用者 グループ」を 1 行ずつ（書いていない項目は -。$pool はプールの名前に置き換える）
ec_php_pools() {
    awk '
        function flush() { if (pool != "" && pool != "global") print pool, (u == "" ? "-" : u), (g == "" ? "-" : g) }
        { sub(/[;#].*/, ""); gsub(/^[ \t]+|[ \t\r]+$/, "") }
        /^\[.*\]$/ { flush(); pool = substr($0, 2, length($0) - 2); u = ""; g = ""; next }
        /^(user|group)[ \t]*=/ {
            k = $0; sub(/[ \t]*=.*/, "", k); v = $0; sub(/^[^=]*=[ \t]*/, "", v); gsub(/"/, "", v); gsub(/\$pool/, pool, v)
            if (k == "user") u = v; else g = v
        }
        END { flush() }' "$1" 2> /dev/null || true
}
# グループ $1 の権限で動く、前の Web サーバーの働き手ではないもの（PHP など。そのグループの権限で EC-SUGAR のソケットにつなげる）を
# 「、」でつないで出す（無ければ何も出さない）。見るもの（4 回目の見直し）:
#   - 今動いているプロセス: 実効のグループか補助のグループ。名前でなく数（gid）で比べる（ps は長いグループの名前を幅で切る）
#   - php-fpm のプールの設定: ondemand のプールは要求が来るまでプロセスが無い。group がそのグループか、user がそのグループに所属する
#     （php-fpm は user の補助のグループも持つ）
#   - Apache の働き手のグループ（/etc/apache2/envvars の APACHE_RUN_GROUP。前の Web サーバーが Apache でないとき。Apache の中の PHP・CGI でつなげる）
#   - 前の Web サーバーが apache2・httpd で、PHP の部品（mod_php）を読み込んでいれば、それも（働き手の中で PHP が動く）
#   - そのグループの利用者の crontab（その利用者の権限で動く）
ec_shared_group_procs() {
    local group="$1" gid front_pids comm pid f pool user grp agroup found=()
    gid="$(getent group "$group" | cut -d: -f3)"
    [ -n "$gid" ] || return 0
    front_pids=" $(ec_front_pids | tr '\n' ' ') "
    for pid in $(ps -eo pid=,egid=,supgid:4096= 2> /dev/null | awk -v g="$gid" '
        { hit = ($2 == g); n = split($3, s, ","); for (i = 1; i <= n; i++) if (s[i] == g) hit = 1; if (hit) print $1 }'); do
        [[ "$front_pids" != *" $pid "* ]] || continue
        comm="$(ps -o comm= -p "$pid" 2> /dev/null || true)"
        [ -z "$comm" ] || found+=("$comm")
    done
    for f in $EC_PHP_POOL_GLOBS; do
        [ -f "$f" ] || continue
        while read -r pool user grp; do
            if [ "$grp" = "$group" ] || { [ "$user" != - ] && [[ " $(id -Gn -- "$user" 2> /dev/null || true) " == *" $group "* ]]; }; then
                found+=("php-fpm のプール ${pool}（${f}）")
            fi
        done < <(ec_php_pools "$f")
    done
    comm="$(ec_front_comm)"
    if [ "$comm" = apache2 ] || [ "$comm" = httpd ]; then
        if ls /etc/apache2/mods-enabled/php*.load > /dev/null 2>&1 || { command -v apache2ctl > /dev/null && apache2ctl -M 2> /dev/null | ec_q -i php; }; then
            found+=("$comm の中の PHP（mod_php）")
        fi
    elif [ -r "$EC_APACHE_ENVVARS" ]; then
        agroup="$(sed -n 's/^[[:space:]]*export[[:space:]][[:space:]]*APACHE_RUN_GROUP=//p' "$EC_APACHE_ENVVARS" | tail -n1 | tr -d "\"'[:space:]")"
        [ "$agroup" != "$group" ] || found+=("Apache（$EC_APACHE_ENVVARS の APACHE_RUN_GROUP）")
    fi
    for user in $(ec_group_members "$group"); do
        [ ! -s "$EC_CRONTAB_DIR/$user" ] || found+=("利用者 $user の crontab")
    done
    [ "${#found[@]}" -gt 0 ] || return 0
    printf '%s\n' "${found[@]}" | awk '!seen[$0]++ {out = out (out == "" ? "" : "、") $0} END {printf "%s", out}'
}
# 証明書の取り方（答えから決める）: dns（Cloudflare の鍵を渡した）・manual（証明書と鍵を渡した）・auto（既定。Let's Encrypt から自動で）
ec_tls_mode() {
    if [ -n "$(ec_answer CLOUDFLARE_TOKEN_FILE || true)" ]; then
        printf 'dns'
    elif [ -n "$(ec_answer TLS_CERT || true)" ]; then
        printf 'manual'
    else
        printf 'auto'
    fi
}
# 入れたときのサンプル（ADR 0053）。$1 答えの SAMPLES を、セットの名前（zakka・sweets・fashion・botanica）か none に読み替えて出す。
# 前の答え（yes・no）は、yes を zakka（前からの中身の雑貨のお店）、no を none と読む（前の答えのファイルを壊さない）。知らない値は 1
ec_sample_set() {
    case "$1" in
        zakka | sweets | fashion | botanica | none) printf '%s' "$1" ;;
        yes) printf 'zakka' ;;
        no) printf 'none' ;;
        *) return 1 ;;
    esac
}
# セットの見せ方（名前とテーマ）。$1 セットの名前（ec_sample_set で読み替えたもの）
ec_sample_label() {
    case "$1" in
        zakka) printf '雑貨のお店（テーマ 角砂糖）' ;;
        sweets) printf 'お菓子のお店（テーマ 角砂糖）' ;;
        fashion) printf '布ものと道具のお店（テーマ fashion）' ;;
        botanica) printf '植物のお店（テーマ botanica）' ;;
        *) printf '入れない（テーマは 角砂糖）' ;;
    esac
}
# 写真を入れる時に Unsplash から取るセットか（雑貨は配る物の中の絵を使い、取りに行かない）
ec_sample_fetches() { [ "$1" = sweets ] || [ "$1" = fashion ] || [ "$1" = botanica ]; }
# 最初のショップにサンプルを入れる（インストーラーの 4/5。テーマの切り替えは shop:sample がする）。$1 店の ID $2 セットの名前（none なら何もしない）。
# 入れ終えたら印（samples_added）を残し、2 回目からは流さない（消した後に戻さない）。shop:sample の終わりの番号で分ける:
#   0 入れた・もう入っていた（写真が欠けても 0）→ 印を残す ／ 2 引数の誤り → インストーラーの誤りなので止める ／
#   1 とそのほか（店が無い・お店の商品がある・別のセットが残っている・途中の失敗・テーマを切り替えられなかった・app に届かない）→ 注意を出して続ける（ショップは動く）
ec_add_samples() {
    local id="$1" sample_set="$2" rc=0
    [ "$sample_set" != none ] || return 0
    [ ! -e "$EC_STATE/samples_added" ] || return 0
    if ec_sample_fetches "$sample_set"; then
        ec_say "サンプル（$(ec_sample_label "$sample_set")）を入れます。写真を Unsplash から取ります（数十秒）"
    else
        ec_say "サンプル（$(ec_sample_label "$sample_set")）を入れます"
    fi
    # 途中の様子（写真を取る・回数の上限で待つ・欠けた写真の注意）が見えるように、そのまま流して出す（読み込む側は pipefail を付けているので、shop:sample の番号が残る）
    ec_dc exec -T app php artisan shop:sample "$id" --set="$sample_set" 2>&1 | sed 's/^/   | /' || rc=$?
    case "$rc" in
        0)
            ec_state_put samples_added "$(date -Is)"
            ec_ok "サンプルを入れた（$(ec_sample_label "$sample_set")。管理画面のホームの「サンプルをまとめて消す」で消せる）"
            ;;
        2)
            ec_die "サンプルを入れるコマンドに渡した引数が違いました（インストーラーの誤り。ショップはできています）" \
                "上の表示を添えて報告する" "サンプルを入れずに進めるなら、$EC_ANSWERS の SAMPLES を none にして再実行する"
            ;;
        *)
            ec_warn "サンプルを入れられませんでした（ショップは動きます）。上の理由を見て直し、sudo ./deploy/install.sh を再実行すると残りだけを入れます（入れないなら $EC_ANSWERS の SAMPLES を none に）"
            ;;
    esac
    return 0
}
# $1 証明書（PEM）。発行者が Cloudflare の Origin CA（O=CloudFlare, Inc. で、OU が CloudFlare Origin SSL Certificate Authority〔RSA〕か
# CloudFlare Origin SSL ECC Certificate Authority）なら 0。名前だけで見る（本物かは ec_check_origin_ca が元の証明書で確かめる）
ec_cert_is_origin_ca() {
    local issuer
    [ -f "$1" ] && [ -r "$1" ] || return 1
    issuer="$(openssl x509 -in "$1" -noout -issuer -nameopt RFC2253 2> /dev/null)" || return 1
    issuer=",${issuer#issuer=},"
    [[ "$issuer" == *',O=CloudFlare\, Inc.,'* ]] || return 1
    [[ "$issuer" == *',OU=CloudFlare Origin SSL Certificate Authority,'* ]] || [[ "$issuer" == *',OU=CloudFlare Origin SSL ECC Certificate Authority,'* ]]
}
# 手で渡した証明書 $1 が Cloudflare の Origin CA のものなら、使える形かを確かめる（Origin CA でなければ何もしない）。
# Origin CA は公開の認証局ではなくブラウザが信じないので、使えるのは Cloudflare Tunnel の形だけ（お客さんのブラウザは Cloudflare の証明書を見て、
# この証明書を確かめるのは同じサーバーの cloudflared だけ。ADR 0052）。発行者の名前だけを似せたものは、Origin CA の元の証明書
# （EC_ORIGIN_CA_ROOTS）で確かめて断る（cloudflared も確かめられず、Tunnel がつながらなくなる）
ec_check_origin_ca() {
    local cert="$1"
    ec_cert_is_origin_ca "$cert" || return 0
    if [ "$(ec_front)" != tunnel ]; then
        ec_die "証明書 $cert は Cloudflare の Origin CA のものです。ブラウザが信頼しないので、Cloudflare Tunnel の構成（FRONT=tunnel）でだけ使えます（設定ファイルは FRONT=$(ec_front)）" \
            "Cloudflare Tunnel で出すなら $EC_ANSWERS に FRONT=tunnel を書く（docs/install.md の「Cloudflare Tunnel で出す形」）" \
            "ほかの構成では、公開の認証局の証明書を渡すか、TLS_CERT・TLS_KEY を消して自動で取る"
    fi
    if [ -z "$EC_ORIGIN_CA_ROOTS" ] || [ ! -r "$EC_ORIGIN_CA_ROOTS" ]; then
        ec_die "Cloudflare の Origin CA の元の証明書（${EC_ORIGIN_CA_ROOTS:-パスが空}）が読めません" "取り出したリポジトリに deploy/cloudflare/origin-ca-roots.crt があるかを確認する（git status）"
    fi
    openssl verify -CAfile "$EC_ORIGIN_CA_ROOTS" -purpose sslserver "$cert" > /dev/null 2>&1 ||
        ec_die "証明書 $cert は、発行者の名前は Cloudflare の Origin CA なのに、Cloudflare の Origin CA の元の証明書で確認できません（似せた証明書か、元の証明書の期限切れ）" \
            "Cloudflare の画面（SSL/TLS › 配信元サーバー）で Origin CA の証明書を作り直して渡す（docs/install.md の「Cloudflare の Origin CA の証明書」）" \
            "Cloudflare が元の証明書を替えたときは、deploy/cloudflare/origin-ca-roots.crt を新しくしたバージョンで再実行する"
}
# 複数の店の形で、証明書がワイルドカード（*.<ショップのドメイン>）を覆うかを見るときの 1 段目（どの店の名前にもならない名前。
# 店の名前〔shop など〕で見ると、その名前だけを含む証明書でも通ってしまう）
EC_TLS_PROBE_LABEL=ec-sugar-tls-probe
# 手で用意した証明書と鍵を確かめる（読める・鍵が対・Origin CA なら使える形か・名前を含む・期限）。install.sh の 4/5 から
ec_check_tls() {
    local cert="$1" key="$2" admin shop
    admin="$(ec_answer ADMIN_HOST)"
    shop="$(ec_shop_domain)"
    openssl x509 -in "$cert" -noout 2> /dev/null || ec_die "証明書 $cert が PEM の証明書として読めません" "-----BEGIN CERTIFICATE----- で始まるファイルを渡す"
    openssl pkey -in "$key" -noout -passin pass: 2> /dev/null || ec_die "秘密の鍵 $key が読めません（パスフレーズが付いているか、形が違う）" "パスフレーズの無い PEM の鍵を渡す（openssl pkey -in 元の鍵 -out 新しい鍵）"
    [ "$(openssl x509 -in "$cert" -noout -pubkey | openssl sha256)" = "$(openssl pkey -in "$key" -pubout -passin pass: | openssl sha256)" ] ||
        ec_die "証明書と秘密の鍵が対になっていません" "同じときに作った証明書と鍵を渡す"
    ec_check_origin_ca "$cert"
    openssl x509 -in "$cert" -noout -checkhost "$admin" 2> /dev/null | ec_q 'does match' ||
        ec_die "証明書に管理画面のホスト名 $admin が入っていません" "$admin を含む証明書を作り直す"
    # ショップ: 1 店舗はそのホスト、複数の店は *.<ショップのドメイン>（ワイルドカード。どの店の名前でもない 1 つ下の名前で見る。
    # 前は shop.<ドメイン> で見ていたので、1 店舗の形から広げた本番〔店が shop.ec-sugar.jp〕では、shop.ec-sugar.jp だけを含む証明書でも通っていた）
    local probe="$shop" want="$shop"
    if [ "$(ec_shops)" = multi ]; then probe="$EC_TLS_PROBE_LABEL.$shop" want="*.$shop"; fi
    openssl x509 -in "$cert" -noout -checkhost "$probe" 2> /dev/null | ec_q 'does match' ||
        ec_die "証明書にショップの名前 $want が入っていません" "$want を含む証明書を作り直す"
    openssl x509 -in "$cert" -noout -checkend 86400 > /dev/null || ec_die "証明書の期限が切れているか、1 日以内に切れます" "新しい証明書を渡す"
    openssl x509 -in "$cert" -noout -checkend $((30 * 86400)) > /dev/null || ec_warn "証明書の期限まで 30 日を切っています"
}
# 今の取り方で使わない入口の秘密を消す（install.sh が、新しい取り方で立って健全になった後だけ呼ぶ）。
# dns から manual・auto へ替えたら DNS の API の鍵（cloudflare_token）を、manual から替えたら手で渡した証明書と鍵（tls/）を消す
ec_prune_caddy_secrets() {
    local mode dir="$EC_CADDY_SECRETS"
    mode="$(ec_tls_mode)"
    [ "$mode" = manual ] || rm -rf "$dir/tls"
    [ "$mode" = dns ] || rm -f "$dir/cloudflare_token"
    [ -n "$(ec_answer ACME_CA_ROOT || true)" ] || rm -f "$dir/acme-ca-root.pem"
    return 0
}
# compose が必ず要る env の項目（新しい名前の EC_SUGAR_ の後ろと説明）。compose の入れ子（${SUGAR_EC_X:-${EC_SUGAR_X:-}}）の内側に
# 必須の確かめ（:?）を置けない（compose 2.40 系は外側に値があっても内側を確かめて止まる）ので、compose を呼ぶ前に ec_dc がここで確かめる。
# 受け口の住所（BIND）は compose の中でも止まる（空だと全部の住所に出るので、IP でない印を既定にしてある）
EC_REQUIRED_ENV="ENV_FILE=この env ファイル自身のパス
IMAGE=動かすイメージの名前と印。例 ec-sugar:<コミット>
BIND=待ち受ける IP アドレス
ADMIN_HOST=管理画面のホスト
SHOP_DOMAIN=ショップのホストの親
SITE_SHOP=ショップの場所の名前
SERVER_NAMES=nginx が受ける名前
HSTS_MAX_AGE=HSTS の秒数
CADDY_SECRETS=リバースプロキシの秘密の保存先
DB_SUPERUSER_PASSWORD=DB の管理者のパスワード
FRONT_SOCKET_GID=ソケットのディレクトリのグループの番号
TUNNEL_DIR=cloudflared の設定とトークンの保存先"
# 必須の項目のうち、compose のファイル（$@）が新しい名前で読むのに、環境変数（前の名前か新しい名前）にも env にも値が無いものを
# 「名前（説明）」で 1 行ずつ出す。前の名前だけで読む compose（ロールバックの先の前のバージョン）は自分で :? を確かめるので、ここでは見ない。値は出さない
ec_env_missing() {
    local line key new old f used
    while IFS= read -r line; do
        key="${line%%=*}"
        new="$EC_ENV_PREFIX$key"
        old="$EC_ENV_OLD_PREFIX$key"
        used=0
        for f in "$@"; do
            if grep -q -- "$new" "$f" 2> /dev/null; then used=1; fi
        done
        [ "$used" -eq 1 ] || continue
        if [ -n "${!new:-}" ] || [ -n "${!old:-}" ] || [ -n "$(ec_env_get "$new" 2> /dev/null || true)" ]; then continue; fi
        printf '%s（%s）\n' "$new" "${line#*=}"
    done <<< "$EC_REQUIRED_ENV"
    return 0
}
# ---------- compose の置き場（開発の形は作業場所、公開版の形は root だけの置き場） ----------
# 公開版の形では、compose のファイルと compose が差し込むもの（docker/ の入口の Caddy の設定・nginx の設定・DB の初めのスクリプトなど）を、
# 作業場所（利用者 ec-sugar が書ける）から読まない。確かめたコミットの git の中身から $EC_COMPOSE_ROOT/<コミット> へ root が取り出し、
# そこを compose の project directory にする（運用の道具の ec_deploy_from_commit と同じ考え）。守りの検査の後・起こす前・再起動・
# ロールバックのどの時に作業場所を書き換えられても、compose には効かない。どのコミットの置き場を使うかは root の記録（state の compose_commit。
# ec_src_checkout が作業場所を切り替えるたびに書く）で、無ければ今のバージョン（current_commit）。開発の形（CHANNEL=source）は今までどおり作業場所
# 取り出すもの（コミットにあるものだけ）。守りの検査（docker/check-compose.sh）が読む開発の compose・env の見本・common.sh も入れる（検査も同じ置き場で流す）
EC_COMPOSE_PATHS="docker-compose.yml docker-compose.prod.yml docker-compose.proxy.yml docker-compose.tunnel.yml docker-compose.egress.yml docker-compose.isolated.yml docker deploy"
# コミット $1 の compose の一式を root だけの置き場へ取り出し、その道筋を出す（もう取り出してあれば取り出し直さない）。
# 置き場の中はふつうのファイルとディレクトリだけ（リンクのあるコミットは取り出さない。差し込む元をホストのほかの所へ向けさせない）で、
# 流した利用者（root）の持ち物の 0644・0755（コンテナの利用者は読めるが、root のほかは書けない）。途中で止まっても半端な置き場を残さない（取り出してから名前を付ける）
ec_compose_extract() {
    local commit="$1" dir tmp p have=()
    ec_valid_commit "$commit" || return 1
    dir="$EC_COMPOSE_ROOT/$commit"
    if [ -d "$dir" ] && [ ! -L "$dir" ] && [ -f "$dir/docker-compose.prod.yml" ]; then
        printf '%s' "$dir"
        return 0
    fi
    [ ! -L "$EC_COMPOSE_ROOT" ] || return 1
    { mkdir -p "$EC_COMPOSE_ROOT" && chmod 0755 "$EC_COMPOSE_ROOT"; } || return 1
    for p in $EC_COMPOSE_PATHS; do
        [ -z "$(ec_git ls-tree --name-only "$commit" -- "$p" 2> /dev/null || true)" ] || have+=("$p")
    done
    case " ${have[*]-} " in *" docker-compose.prod.yml "*) ;; *) return 1 ;; esac
    tmp="$(mktemp -d "$EC_COMPOSE_ROOT/.partial.XXXXXX")" || return 1
    if ! ec_git -c tar.umask=0022 archive --format=tar "$commit" -- "${have[@]}" | tar -x -f - -C "$tmp" --no-same-owner 2> /dev/null ||
        [ -n "$(find "$tmp" -type l -print 2> /dev/null | head -n 1)" ] || [ ! -f "$tmp/docker-compose.prod.yml" ] ||
        ! chmod -R go-w "$tmp" || ! chmod 0755 "$tmp"; then
        rm -rf "$tmp"
        return 1
    fi
    rm -rf "$dir"
    mv "$tmp" "$dir" || { rm -rf "$tmp"; return 1; }
    printf '%s' "$dir"
}
# 作業場所をコミット $1 へ切り替えた時に呼ぶ（ec_src_checkout）: そのコミットの置き場を用意し、compose の向く先にする（root の記録に残す）
ec_compose_use() {
    ec_compose_extract "$1" > /dev/null || return 1
    ec_state_put compose_commit "$1"
}
# compose の project directory を出す。開発の形は作業場所。公開版の形は記録のコミット（無ければ今のバージョン）の root だけの置き場（無ければ取り出す）。
# 更新の形が読めない・公開版の形で記録のコミットが分からない・取り出せないときは 1（作業場所へは落とさない）
ec_compose_dir() {
    local channel c
    channel="$(ec_channel)" || return 1
    if [ "$channel" != release ]; then
        printf '%s' "$EC_SRC"
        return 0
    fi
    c="$(ec_state_get compose_commit 2> /dev/null || true)"
    ec_valid_commit "$c" || c="$(ec_state_get current_commit 2> /dev/null || true)"
    ec_valid_commit "$c" || return 1
    ec_compose_extract "$c"
}
# 今のバージョン・前のバージョン（ロールバックの先）・compose の向く先のほかの置き場を消す（ec-sugar の ec_prune_images から）
ec_compose_prune() {
    local d keep
    [ -d "$EC_COMPOSE_ROOT" ] && [ ! -L "$EC_COMPOSE_ROOT" ] || return 0
    keep=" $(ec_state_get current_commit 2> /dev/null || true) $(ec_state_get previous_commit 2> /dev/null || true) $(ec_state_get compose_commit 2> /dev/null || true) "
    for d in "$EC_COMPOSE_ROOT"/* "$EC_COMPOSE_ROOT"/.partial.*; do
        [ -e "$d" ] || [ -L "$d" ] || continue
        case "$keep" in *" ${d##*/} "*) continue ;; esac
        rm -rf "$d"
    done
    return 0
}
# ---------- 外への通信の出口の代理（ADR 0102） ----------
# 形は env ファイルの EC_SUGAR_EGRESS_MODE: watch（既定。許す一覧に無い名前も通して記録する）・enforce（断る。アプリの網から外へ直に出る道も外す）・
# off（アプリが代理を使わない。代理が困ったときの逃げ道）。知らない値は watch とみなす（止めない方）
ec_egress_mode() {
    local mode
    mode="$(ec_env_get EC_SUGAR_EGRESS_MODE 2> /dev/null || true)"
    # 代理（docker/egress/proxy.php の Policy::mode）と同じ読み方: 前後の空白と引用符を外し、小文字にそろえる
    mode="$(printf '%s' "$mode" | tr -d "\"' \t" | tr '[:upper:]' '[:lower:]')"
    case "$mode" in
        enforce | off) printf '%s' "$mode" ;;
        *) printf 'watch' ;;
    esac
}
# 止める形で MAIL_HOST を代理の住所に向けるときの名前。MAIL_HOST が名前（点を含む）のときだけそれを、住所（IP）・Mailpit・空のときは
# どこにも当たらない印の名前を出す（Mailpit は内側の網で直に届く。住所は向けられない）
ec_egress_smtp_name() {
    local host
    host="$(ec_env_get MAIL_HOST 2> /dev/null || true)"
    if ec_valid_host "$host"; then printf '%s' "$host"; else printf 'smtp-relay.invalid'; fi
}
# 止める形へ切り替えられない理由を 1 行（無ければ何も出さない）。メールを SMTP で送るのに MAIL_HOST が住所（IP）だと、中継へ向けられず
# メールが止まる
ec_egress_enforce_problem() {
    local mailer host
    mailer="$(ec_env_get MAIL_MAILER 2> /dev/null || true)"
    host="$(ec_env_get MAIL_HOST 2> /dev/null || true)"
    if [ "$mailer" = smtp ] && [ "$host" != mailpit ] && ! ec_valid_host "$host"; then
        printf 'MAIL_HOST（%s）が名前でないので、止めるモードではメールを中継できません（メールの送信サービスのホスト名にする）\n' "$host"
    fi
    return 0
}

# 取り出したバージョンの compose に出口の代理があるか（代理の無い前のバージョンへ戻した時は、代理の見張りと確かめをしない）
ec_has_egress() { ec_dc config --services 2> /dev/null | ec_q -x egress; }
# app から、アプリと代理だけの網（egress_in）を通って代理の /healthz に届くか（更新のヘルスチェック。代理が healthy でも、アプリから届かなければ
# 外への https は全部止まる）。隔離した写しではアプリが代理の網に居ないので見ない
ec_egress_reachable() {
    ec_is_isolated && return 0
    ec_dc exec -T app php -r '$s = @fsockopen("egress", 3128, $e, $m, 5); if (! $s) { exit(1); } fwrite($s, "GET /healthz HTTP/1.0\r\n\r\n"); exit(str_starts_with((string) fgets($s), "HTTP/1.1 200") ? 0 : 1);' > /dev/null 2>&1
}
# 出口の代理の見張り（ec-sugar watch。10 分ごと）。止める形では代理が止まるとメールの道も切れ、運営の様子の「代理が止まった」のメールも届かない。
# そこでホストから代理の様子を見て、動いていない・unhealthy なら作り直し、ホストの記録（history.log。systemd の記録にも注意が残る）に残す。
# 作り直せなければ 1（呼ぶ側は続ける）
ec_egress_watch() {
    local state
    ec_has_egress || return 0
    state="$(ec_dc ps -a --format '{{.State}} {{.Health}}' egress 2> /dev/null | head -n1 || true)"
    case "$state" in
        "running healthy" | "running starting") return 0 ;;
    esac
    ec_warn "送信用プロキシ（egress）が「${state:-無い}」なので作り直します（sudo ec-sugar logs egress で理由を見る）"
    ec_history "$(date -Is)" egress_restart "" "" "送信用プロキシが「${state:-無い}」だったので作り直した"
    ec_dc up -d --no-deps --force-recreate egress > /dev/null 2>&1 && return 0
    ec_warn "送信用プロキシを作り直せませんでした（外への https とメールが止まっているかもしれません。困ったら sudo ec-sugar egress off）"
    return 1
}

ec_dc() {
    local dir profile=() files=() paths=() egress_env=() missing line
    if ! dir="$(ec_compose_dir)"; then
        ec_warn "compose のディレクトリを決められません（設定ファイル ${EC_ANSWERS} の CHANNEL と、release チャネルなら ${EC_STATE}/current_commit を確認する。compose を実行せずに止めました）"
        return 1
    fi
    files=(-f "$dir/docker-compose.prod.yml")
    paths=("$dir/docker-compose.prod.yml")
    [ "$(ec_mode)" = staging ] && profile=(--profile staging)
    # 後ろに置く形は重ね書き（Caddy を起こさず、nginx を TCP で待たせず、ホストの Unix ソケットだけで待たせる）。その形を知らないバージョン（ファイルが無い）には重ねない
    if [ "$(ec_front)" = proxy ] && [ -f "$dir/docker-compose.proxy.yml" ]; then
        files+=(-f "$dir/docker-compose.proxy.yml")
        paths+=("$dir/docker-compose.proxy.yml")
    fi
    # Cloudflare Tunnel の形は caddy の形に cloudflared を重ねる（ADR 0052）。その形を知らないバージョン（ファイルが無い）には重ねない
    if [ "$(ec_front)" = tunnel ] && [ -f "$dir/docker-compose.tunnel.yml" ]; then
        files+=(-f "$dir/docker-compose.tunnel.yml")
        paths+=("$dir/docker-compose.tunnel.yml")
    fi
    # 外への通信（ADR 0102）: 止める形はアプリの網を外へ出られない網だけにする形を重ねる（隔離の形より前。隔離が網をもっと狭くする）。
    # 重ねるファイルが無いバージョン（前のバージョンへロールバックした）は、代理の無い前の形のまま動かす（注意だけ。止めると支払いとメールが止まる）。
    # 代理を使わない形（off）は、アプリへ渡す HTTPS_PROXY を空にする
    case "$(ec_egress_mode)" in
        enforce)
            if [ -f "$dir/docker-compose.egress.yml" ]; then
                files+=(-f "$dir/docker-compose.egress.yml")
                paths+=("$dir/docker-compose.egress.yml")
            else
                ec_warn "外への通信を止めるモード（EC_SUGAR_EGRESS_MODE=enforce）ですが、取り出したバージョンに docker-compose.egress.yml がありません（前の構成のまま動かします）"
            fi
            ;;
        off) egress_env+=(EC_SUGAR_EGRESS_PROXY_URL=) ;;
    esac
    egress_env+=("EC_SUGAR_EGRESS_SMTP_NAME=$(ec_egress_smtp_name)")
    # 隔離した写し（ADR 0082）は、外へ出る網を外して定期の処理を止める形を重ねる。重ねるファイルが無いバージョン（前のバージョンへ
    # ロールバックした）では隔離が外れ、本番のデータのまま外へ送り出せてしまうので、compose を流さずに止める
    if ec_is_isolated; then
        if [ ! -f "$dir/docker-compose.isolated.yml" ]; then
            ec_warn "このサーバーは隔離した複製環境ですが、取り出したバージョンに docker-compose.isolated.yml がありません（隔離が外れるので compose を実行せずに止めました）"
            return 1
        fi
        files+=(-f "$dir/docker-compose.isolated.yml")
        paths+=("$dir/docker-compose.isolated.yml")
    fi
    missing="$(ec_env_missing "${paths[@]}")"
    if [ -n "$missing" ]; then
        while IFS= read -r line; do
            ec_warn "設定 ${EC_ENV_FILE} に ${line} がありません（compose を実行せずに止めました。sudo ./deploy/install.sh を再実行する）"
        done <<< "$missing"
        return 1
    fi
    # 外への通信の値は compose にだけ渡す（この処理の環境は変えない。サブシェルで export。docker を関数で差し替えた確かめでも同じに動く）
    (
        for line in "${egress_env[@]}"; do export "${line?}"; done
        docker compose -p "$EC_PROJECT" --project-directory "$dir" "${files[@]}" \
            --env-file "$EC_ENV_FILE" ${profile[@]+"${profile[@]}"} "$@"
    )
}
ec_image_of() { printf '%s:%s' "$EC_IMAGE_REPO" "$1"; }
# 取り出したバージョンの compose が入口の 2 段（caddy がある形）か。前の形（nginx が https を受ける）へ戻したときと、前の形から移るときに分ける
# 0: ある 1: 無い（前の形のバージョン） 2: compose を解けない（env が足りないなど。「無い」と取り違えない）
ec_has_caddy() {
    local services
    services="$(ec_dc config --services 2> /dev/null)" || return 2
    printf '%s\n' "$services" | ec_q -x caddy
}
# 取り出したバージョンの compose が要る env を、答えから決まる値で補う（前のバージョンの env に無い項目。更新の道で使う）。
# 答えから決められない項目が足りなければ 1（呼ぶ側が「install.sh を流し直す」と案内する）。
# 前のバージョンの道具が更新を始めたとき、取り出したこのバージョンの道具で最初に呼ばれる（ensure-env）ので、ここで env の名前も移す
ec_ensure_env() {
    local admin site
    ec_env_migrate || return 1
    admin="$(ec_env_get EC_SUGAR_ADMIN_HOST || true)"
    [ -n "$admin" ] || return 1
    [ -n "$(ec_env_get EC_SUGAR_SHOPS || true)" ] || ec_env_set EC_SUGAR_SHOPS "$(ec_shops)"
    # 外の見張りの合い言葉（sudo ec-sugar integrity token で作ったもの）が env に無ければ写す（nginx が env の雛形で読む）
    if [[ "$(head -n1 "$EC_INTEGRITY_DIR/sentinel_token" 2> /dev/null || true)" =~ ^[0-9a-f]{32}$ ]] && [ -z "$(ec_env_get EC_SUGAR_SENTINEL_TOKEN || true)" ]; then
        ec_env_set EC_SUGAR_SENTINEL_TOKEN "$(head -n1 "$EC_INTEGRITY_DIR/sentinel_token")"
    fi
    site="$(ec_shop_site)"
    if [ -z "$(ec_env_get EC_SUGAR_SITE_SHOP || true)" ]; then
        [ -n "$site" ] && [ "$site" != '*.' ] || return 1
        ec_env_set EC_SUGAR_SITE_SHOP "$site"
    fi
    [ -n "$(ec_env_get EC_SUGAR_SERVER_NAMES || true)" ] || ec_env_set EC_SUGAR_SERVER_NAMES "\"$admin $site\""
    [ -n "$(ec_env_get EC_SUGAR_CADDY_SECRETS || true)" ] || ec_env_set EC_SUGAR_CADDY_SECRETS "$EC_CADDY_SECRETS"
    [ -n "$(ec_env_get EC_SUGAR_TLS_MODE || true)" ] || ec_env_set EC_SUGAR_TLS_MODE "$(ec_tls_mode)"
    # ステージングの Mailpit の画面のパスワード（前のバージョンには無い。新しいバージョンの compose は無いと Mailpit を起こさない）
    [ -n "$(ec_env_get EC_SUGAR_MAILPIT_SECRETS || true)" ] || ec_env_set EC_SUGAR_MAILPIT_SECRETS "$EC_MAILPIT_SECRETS"
    ec_mailpit_auth_ensure || return 1
    if [ "$(ec_front)" = proxy ]; then
        [ -n "$(ec_env_get EC_SUGAR_FRONT_SOCKET_DIR || true)" ] || ec_env_set EC_SUGAR_FRONT_SOCKET_DIR "$EC_FRONT_SOCKET_DIR"
        if [ -z "$(ec_env_get EC_SUGAR_FRONT_SOCKET_GID || true)" ]; then
            # 専用のグループが無ければ（前のバージョンのソケットの置き場の作り方）、install.sh で置き場から作り直す
            getent group "$EC_FRONT_SOCKET_GROUP" > /dev/null || return 1
            ec_env_set EC_SUGAR_FRONT_SOCKET_GID "$(getent group "$EC_FRONT_SOCKET_GROUP" | cut -d: -f3)"
        fi
    fi
    if [ "$(ec_front)" = tunnel ] && [ -z "$(ec_env_get EC_SUGAR_TUNNEL_DIR || true)" ]; then
        # 置き場とトークンが無ければ（入れる途中で止まった）、install.sh で作り直す
        [ -s "$EC_TUNNEL_DIR/token" ] && [ -s "$EC_TUNNEL_DIR/config.yml" ] || return 1
        ec_env_set EC_SUGAR_TUNNEL_DIR "$EC_TUNNEL_DIR"
    fi
    return 0
}

# ---------- ステージングの Mailpit の画面のパスワード ----------
# 画面（127.0.0.1:8025）は、ホストのどの利用者からも届く（docker-proxy が root で受ける）。届いた注文のメール（名前・注文の URL）を読ませないように
# Basic 認証を求める。利用者の名前は ec-sugar、パスワードは乱数で 1 回だけ作り、平文は root だけが読むファイル（EC_MAILPIT_PASSWORD_FILE）に置く
# （画面にも、コマンドの引数にも出さない）。Mailpit には SHA-512 のハッシュ（htpasswd の形）だけを、Mailpit の利用者（コンテナの中の 1000）だけが
# 入れる保存先で渡す。ハッシュのファイルが無ければ Mailpit は起きない（認証なしでは開かない）
EC_MAILPIT_SECRETS=$EC_SECRETS/mailpit
EC_MAILPIT_PASSWORD_FILE=$EC_SECRETS/mailpit-ui-password
EC_MAILPIT_UID=1000
EC_MAILPIT_USER=ec-sugar
ec_mailpit_auth_ensure() {
    local pw hash old_umask
    [ "$(ec_mode)" = staging ] || return 0
    command -v openssl > /dev/null || { ec_warn "openssl が無いので、Mailpit の画面のパスワードを作れません（sudo apt-get install -y openssl）"; return 1; }
    old_umask="$(umask)"
    umask 077
    if [ ! -s "$EC_MAILPIT_PASSWORD_FILE" ]; then
        printf '%s\n' "$(ec_random_alnum 32)" > "$EC_MAILPIT_PASSWORD_FILE.new" && mv -f "$EC_MAILPIT_PASSWORD_FILE.new" "$EC_MAILPIT_PASSWORD_FILE"
    fi
    chown root:root "$EC_MAILPIT_PASSWORD_FILE"
    chmod 0600 "$EC_MAILPIT_PASSWORD_FILE"
    install -d -m 0700 -o "$EC_MAILPIT_UID" -g "$EC_MAILPIT_UID" "$EC_MAILPIT_SECRETS"
    # パスワードを作り直したとき（ファイルを消した）も、ハッシュを作り直す
    if [ ! -s "$EC_MAILPIT_SECRETS/ui.htpasswd" ] || [ "$EC_MAILPIT_PASSWORD_FILE" -nt "$EC_MAILPIT_SECRETS/ui.htpasswd" ]; then
        IFS= read -r pw < "$EC_MAILPIT_PASSWORD_FILE" || true
        if [ -z "$pw" ] || ! hash="$(printf '%s\n' "$pw" | openssl passwd -6 -stdin)"; then
            umask "$old_umask"
            ec_warn "Mailpit の画面のパスワードのハッシュを作れません"
            return 1
        fi
        pw=""
        printf '%s:%s\n' "$EC_MAILPIT_USER" "$hash" > "$EC_SECRETS/.mailpit-ui.htpasswd.tmp"
        install -m 0400 -o "$EC_MAILPIT_UID" -g "$EC_MAILPIT_UID" "$EC_SECRETS/.mailpit-ui.htpasswd.tmp" "$EC_MAILPIT_SECRETS/.ui.htpasswd.new"
        rm -f "$EC_SECRETS/.mailpit-ui.htpasswd.tmp"
        mv -f "$EC_MAILPIT_SECRETS/.ui.htpasswd.new" "$EC_MAILPIT_SECRETS/ui.htpasswd"
    fi
    umask "$old_umask"
    return 0
}

# ---------- イメージを得る（差し替える所は ここ 1 つ） ----------
# 区切り 1: サーバーで取った git のコミットから、サーバーの上で作る（CI の image の仕事と同じ作り方。印はコミット）。
# 区切り 2 で「置き場（GHCR）から digest で取り、署名（cosign）を確かめる」に替える。呼ぶ側は「コミット → イメージの名前」だけを知る。
# 前提: $EC_SRC はそのコミットを取り出してある（ec_src_checkout の後）。
# 公開版の形（答えの CHANNEL=release）では作らず、公開用の CI が作って署名したものを確かめてから digest で取る（ec_release_obtain。$2 にタグ）
ec_obtain_image() {
    local commit="$1" tag="${2:-}" image log date channel
    channel="$(ec_channel)" || { ec_warn "設定ファイル ${EC_ANSWERS} の CHANNEL が source でも release でもありません（イメージを取得しません）"; return 1; }
    if [ "$channel" = release ]; then
        ec_release_obtain "$commit" "$tag"
        return
    fi
    image="$(ec_image_of "$commit")"
    if docker image inspect "$image" > /dev/null 2>&1; then
        ec_ok "イメージ $image はもうある（作り直さない）"
        ec_obtain_caddy "$image"
        return
    fi
    [ "$(ec_src_head)" = "$commit" ] || ec_die "作業ツリーのコミットが作るイメージと違います（内部の誤り）"
    ec_src_fix_modes
    # バージョンの日時（コミットの日時、ISO 8601）。アプリが「今のバージョン」として見せる（管理画面の最初の登録の確かめ）。
    # 形を確かめてから渡す（数字・-・:・T・+ だけ。おかしければ止める）
    date="$(ec_git log -1 --format=%cI "$commit")"
    [[ "$date" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}([+-][0-9]{2}:[0-9]{2}|Z)$ ]] ||
        ec_die "コミット ${commit:0:12} の日時が読めません（${date}）"
    mkdir -p "$EC_LOGS"
    log="$EC_LOGS/build-${commit:0:12}.log"
    ec_say "イメージ $image を作っています（初回は 10 分ほど。ログは ${log}）"
    if ! docker build --progress=plain --file "$EC_SRC/docker/Dockerfile" --target app \
        --build-arg "EC_SUGAR_REVISION=$commit" --build-arg "EC_SUGAR_REVISION_DATE=$date" \
        --label "org.opencontainers.image.revision=$commit" --label "org.opencontainers.image.title=EC-SUGAR" \
        --tag "$image" "$EC_SRC" > "$log" 2>&1; then
        tail -n 30 "$log" >&2
        return 1
    fi
    ec_ok "イメージ $image を作った"
    ec_obtain_caddy "$image"
}

# 入口（Caddy）のイメージ。公式の Caddy に Cloudflare の DNS の部品を足したもの（docker/caddy/Dockerfile。土台は digest で固定）。
# 札（Caddy と部品のバージョン）は取り出したバージョンの compose から読む。もうあれば作らない（バージョンを変えたときだけ作る。前のバージョンの札は戻すときのために残す）。
# $1 そのバージョンの app のイメージ（compose を解くのに要る）
ec_obtain_caddy() {
    local app_image="$1" caddy log
    # 前の形のバージョン（caddy が無い compose）と、すでにある Web サーバーの後ろに置く形（Caddy を起こさない）には作るものが無い
    local has=0
    ec_with_image "$app_image" ec_has_caddy || has=$?
    if [ "$has" -eq 2 ]; then
        ec_warn "取り出したバージョンの compose を解けません（env に足りない項目がある見込み。sudo ./deploy/install.sh を再実行する）"
        return 1
    fi
    [ "$has" -eq 0 ] || return 0
    [ "$(ec_front)" != proxy ] || return 0
    caddy="$(ec_with_image "$app_image" ec_dc config --images 2> /dev/null | grep -m1 '^ec-sugar-caddy:' || true)"
    if [ -z "$caddy" ]; then
        ec_warn "取り出したバージョンの compose からリバースプロキシ（caddy）のイメージの名前を読めません（env ファイルの EC_SUGAR_CADDY_SECRETS など。install.sh を再実行する）"
        return 1
    fi
    if docker image inspect "$caddy" > /dev/null 2>&1; then
        ec_ok "リバースプロキシのイメージ $caddy はもうある"
        return 0
    fi
    mkdir -p "$EC_LOGS"
    log="$EC_LOGS/build-${caddy//[:\/]/-}.log"
    ec_say "リバースプロキシのイメージ $caddy を作っています（Caddy に DNS の部品を足す。数分。ログは ${log}）"
    if ! ec_with_image "$app_image" ec_dc build caddy > "$log" 2>&1; then
        tail -n 30 "$log" >&2
        return 1
    fi
    ec_ok "リバースプロキシのイメージ $caddy を作った"
}

# ---------- 公開版の形（人の鍵で署名したタグと CI の keyless の署名。ADR 0030・0098・0099） ----------
# 更新の形（答えの CHANNEL）:
#   source   開発の形。取得元のコミット（枝・タグ・コミット）をサーバーの上で組み立てる（今までの形。答えに無ければこれ）。
#            自動の更新は答えの AUTO_UPDATE=yes の時だけで、早送りのコミットだけを取り込む
#   release  公開版の形（公開用のリポジトリの既定。deploy/channel.default）。semver のタグ（v1.2.3・試しの版 v1.2.3-rc.1）だけを入れる:
#            タグの署名（git verify-tag。信じる鍵は EC_TRUST_FILE だけ。リポジトリの中の鍵と設定は読まない）→ タグの指すコミットのスクリプトと compose →
#            2 つのイメージの CI の署名（cosign keyless。身元・発行者と、証明書の workflow のコミットがタグの指すコミットと同じ）→ digest で取る
EC_TRUST_DIR=$EC_ROOT/trust
EC_TRUST_FILE=$EC_TRUST_DIR/allowed_signers
# 信じる鍵の一覧の持ち主（root）。docker/check-release.sh だけが自分の番号に替える
EC_TRUST_UID=0
EC_RELEASE_REPO=ec-sugar/ec-sugar
EC_RELEASE_WORKFLOW=.github/workflows/release.yml
EC_RELEASE_ISSUER=https://token.actions.githubusercontent.com
EC_RELEASE_IMAGE=ghcr.io/ec-sugar/ec-sugar
EC_RELEASE_CADDY_IMAGE=ghcr.io/ec-sugar/ec-sugar-caddy
# cosign はホストに入れず、版と digest を固定した公式のイメージで動かす（3 の系。公開用の CI も v3.1.4 で署名する。ADR 0099）
EC_COSIGN_IMAGE=ghcr.io/sigstore/cosign/cosign:v3.1.4@sha256:0f06154836d2de34f887a5c273506d52ca43edaa1d9e53e6510fa78a48f69c2d
# 非公開のレジストリから取る時の root の docker のログイン（sudo docker login …）。あれば cosign にも読み取り専用で渡す（公開の GHCR には要らない）
EC_REGISTRY_AUTH=/root/.docker/config.json
# 更新の途中の印（中「途中で止まったときの半端な状態」。作業場所を切り替えた直後に置き、止める・終わる所で消す）
EC_UPDATE_MARK=$EC_STATE/updating

# イメージを digest で取る。レジストリの一時のタイムアウト（11-24 のまっさらな VM で、GHCR の 1 つの層が応答を返さず止まった）に
# 備えて 3 回まで、間を 5 秒・15 秒あけて取り直す。digest で指すので、取り直しても中身は署名を確かめたものと同じ
# $1 イメージ（名前@sha256:…）、$2 記録のファイル。EC_PULL_WAITS で間の秒を替えられる（テスト用）
ec_pull_retry() {
    local n=0 w
    for w in ${EC_PULL_WAITS:-5 15} ""; do
        n=$((n + 1))
        docker pull --quiet "$1" >> "$2" 2>&1 && return 0
        [ -n "$w" ] || break
        printf '%s 回目の取得に失敗。%s 秒後に取り直す\n' "$n" "$w" >> "$2"
        sleep "$w"
    done
    return 1
}

# 更新の形を出す（source か release）。答えに無ければ source（この仕組みの前に入れたサーバー）。ほかの値は 1（取り違えて弱い形にしない）
ec_channel() {
    local c
    c="$(ec_answer CHANNEL 2> /dev/null || true)"
    case "${c:-source}" in
        source | release) printf '%s' "${c:-source}" ;;
        *) return 1 ;;
    esac
}

# semver 2.0 のタグ（頭に v。試しの版 -rc.1 などは通し、build の印 +… は断る。128 文字まで）。公開用の CI の release.yml と同じ形
EC_SEMVER_RE='^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-((0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(\.(0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*))?$'
ec_valid_semver_tag() { [ "${#1}" -le 128 ] && [[ "$1" =~ $EC_SEMVER_RE ]]; }
# 試しの版（-rc.1 など）か
ec_semver_is_pre() { ec_valid_semver_tag "$1" && [ -n "${BASH_REMATCH[5]}" ]; }
ec_semver_major() { ec_valid_semver_tag "$1" && printf '%s' "${BASH_REMATCH[1]}"; }
# 数字だけの 2 つを比べ、EC_CMP に -1・0・1（桁の数を先に。大きな数でも溢れない）
ec_num_cmp() {
    if [ "${#1}" -ne "${#2}" ]; then
        if [ "${#1}" -lt "${#2}" ]; then EC_CMP=-1; else EC_CMP=1; fi
    elif [ "$1" = "$2" ]; then
        EC_CMP=0
    elif [[ "$1" < "$2" ]]; then
        EC_CMP=-1
    else
        EC_CMP=1
    fi
}
# 試しの版の 1 つの区切りを比べる（semver 2.0 の 11.4: 数字どうしは数で、数字は英字より前、英字どうしは ASCII の順）
ec_pre_ident_cmp() {
    local a_num=0 b_num=0 first
    [[ "$1" =~ ^[0-9]+$ ]] && a_num=1
    [[ "$2" =~ ^[0-9]+$ ]] && b_num=1
    if [ "$a_num" -eq 1 ] && [ "$b_num" -eq 1 ]; then ec_num_cmp "$1" "$2"; return 0; fi
    if [ "$a_num" -ne "$b_num" ]; then
        if [ "$a_num" -eq 1 ]; then EC_CMP=-1; else EC_CMP=1; fi
        return 0
    fi
    if [ "$1" = "$2" ]; then EC_CMP=0; return 0; fi
    first="$(printf '%s\n%s\n' "$1" "$2" | LC_ALL=C sort | head -n1)"
    if [ "$first" = "$1" ]; then EC_CMP=-1; else EC_CMP=1; fi
}
# タグ $1 と $2 を semver の順で比べ、EC_CMP に -1（$1 が古い）・0・1（$1 が新しい）。形が違えば 1 を返す。
# 試しの版は同じ番号の正式の版より前（v1.0.0-rc.1 < v1.0.0）
ec_semver_cmp() {
    local a1 a2 a3 ap b1 b2 b3 bp i n x y
    ec_valid_semver_tag "$1" || return 1
    a1="${BASH_REMATCH[1]}" a2="${BASH_REMATCH[2]}" a3="${BASH_REMATCH[3]}" ap="${BASH_REMATCH[5]}"
    ec_valid_semver_tag "$2" || return 1
    b1="${BASH_REMATCH[1]}" b2="${BASH_REMATCH[2]}" b3="${BASH_REMATCH[3]}" bp="${BASH_REMATCH[5]}"
    for i in 1 2 3; do
        eval "x=\$a$i y=\$b$i"
        ec_num_cmp "$x" "$y"
        [ "$EC_CMP" -eq 0 ] || return 0
    done
    if [ -z "$ap" ] && [ -z "$bp" ]; then EC_CMP=0; return 0; fi
    if [ -z "$ap" ]; then EC_CMP=1; return 0; fi
    if [ -z "$bp" ]; then EC_CMP=-1; return 0; fi
    local IFS=.
    # shellcheck disable=SC2206 # 試しの版の区切り（形は上で確かめた。空白も * も入らない）
    local pa=($ap) pb=($bp)
    n="${#pa[@]}"
    [ "${#pb[@]}" -ge "$n" ] || n="${#pb[@]}"
    for ((i = 0; i < n; i++)); do
        ec_pre_ident_cmp "${pa[$i]}" "${pb[$i]}"
        [ "$EC_CMP" -eq 0 ] || return 0
    done
    ec_num_cmp "${#pa[@]}" "${#pb[@]}"
}

# 取得元のタグのうち semver の形のものを 1 行ずつ（並びは決めない。選ぶ所で比べる）
ec_release_tags() {
    local t
    while IFS= read -r t; do
        if ec_valid_semver_tag "$t"; then printf '%s\n' "$t"; fi
    done < <(ec_git for-each-ref --format='%(refname:strip=2)' refs/tags 2> /dev/null || true)
}

# ファイルの持ち主の番号（GNU と BSD の stat）
ec_file_uid() { stat -c %u "$1" 2> /dev/null || stat -f %u "$1" 2> /dev/null; }

# 鍵の指紋（SHA256:…）。$1 鍵の種類 $2 鍵（base64）。ssh-keygen が読めない鍵なら 1（鍵の形では断らない。読める鍵ならどの種類でも）
ec_key_fingerprint() {
    local tmp out
    [[ "$1" =~ ^[a-z0-9][a-z0-9@.-]{2,63}$ ]] && [[ "$2" =~ ^[A-Za-z0-9+/]+={0,2}$ ]] && [ "${#2}" -ge 16 ] && [ "${#2}" -le 8192 ] || return 1
    tmp="$(mktemp)" || return 1
    printf '%s %s\n' "$1" "$2" > "$tmp"
    out="$(ssh-keygen -l -E sha256 -f "$tmp" 2> /dev/null | awk 'NR == 1 {print $2}')" || out=""
    rm -f "$tmp"
    [[ "$out" =~ ^SHA256:[A-Za-z0-9+/]{43}$ ]] || return 1
    printf '%s' "$out"
}
EC_TRUST_NAME_RE='^[A-Za-z0-9._@+-]{1,64}$'
# 鍵のファイル $1 を、信じる鍵の一覧の行（`<名前> namespaces="git" <種類> <鍵>`）にして 1 行ずつ出す。読めない行が 1 つでもあれば 1。
# 読める形: allowed signers の行（`<名前> [namespaces="git"] <種類> <鍵> [説明]`）か、公開鍵（`<種類> <鍵> [説明]`。名前は $2）。
# ほかの決まり（cert-authority・valid-before など）は断る（一覧の意味を人が読み違えないように）
ec_trust_lines_from_file() {
    local file="$1" name="$2" line f1 f2 f3 f4 rest who type key count=0
    [ -f "$file" ] && [ -r "$file" ] || return 1
    while IFS= read -r line || [ -n "$line" ]; do
        line="${line%$'\r'}"
        [[ "$line" =~ ^[[:space:]]*(#|$) ]] && continue
        read -r f1 f2 f3 f4 rest <<< "$line" || true
        if ec_key_fingerprint "$f1" "$f2" > /dev/null; then
            who="$name" type="$f1" key="$f2"
        elif [ "$f2" = 'namespaces="git"' ] && ec_key_fingerprint "$f3" "$f4" > /dev/null; then
            who="$f1" type="$f3" key="$f4"
        elif ec_key_fingerprint "$f2" "$f3" > /dev/null; then
            who="$f1" type="$f2" key="$f3"
        else
            return 1
        fi
        [[ "$who" =~ $EC_TRUST_NAME_RE ]] || return 1
        printf '%s namespaces="git" %s %s\n' "$who" "$type" "$key"
        count=$((count + 1))
    done < "$file"
    [ "$count" -gt 0 ]
}
# 信じる鍵の一覧を「指紋 名前 種類」で 1 行ずつ。一覧が無い・読めない行があれば 1
ec_trust_fingerprints() {
    local line who opt type key fp
    [ -f "$EC_TRUST_FILE" ] || return 1
    while IFS= read -r line || [ -n "$line" ]; do
        [[ "$line" =~ ^[[:space:]]*(#|$) ]] && continue
        read -r who opt type key <<< "$line" || true
        [[ "$who" =~ $EC_TRUST_NAME_RE ]] && [ "$opt" = 'namespaces="git"' ] || return 1
        fp="$(ec_key_fingerprint "$type" "$key")" || return 1
        printf '%s %s %s\n' "$fp" "$who" "$type"
    done < "$EC_TRUST_FILE"
}
# 信じる鍵の一覧が使える形か: 置き場とファイルがリンクでなく root の持ち物で、持ち主のほかが書けず、読める鍵が 1 つ以上（読めない行があれば使わない）
ec_trust_file_ok() {
    local f="$EC_TRUST_FILE" d
    d="$(dirname "$f")"
    [ -f "$f" ] && [ ! -L "$f" ] && [ -d "$d" ] && [ ! -L "$d" ] || return 1
    [ "$(ec_file_uid "$f")" = "$EC_TRUST_UID" ] && [ "$(ec_file_uid "$d")" = "$EC_TRUST_UID" ] || return 1
    [ -z "$(find "$f" "$d" -prune \( -perm -g+w -o -perm -o+w \) -print 2> /dev/null)" ] || return 1
    local fps
    fps="$(ec_trust_fingerprints 2> /dev/null)" || return 1
    [ -n "$fps" ]
}
# 標準入力の行（ec_trust_lines_from_file の形）を信じる鍵の一覧にする（root:root の 0644。同じ置き場の仮のファイルから入れ替える。行は確かめ直す）
ec_trust_write() {
    local tmp line n=0
    install -d -m 0755 "$EC_TRUST_DIR" || return 1
    if [ "$(id -u)" -eq 0 ]; then chown 0:0 "$EC_TRUST_DIR" && chmod 0755 "$EC_TRUST_DIR" || return 1; fi
    tmp="$EC_TRUST_DIR/.allowed_signers.tmp.$$"
    {
        printf '# EC-SUGAR が信頼するタグの署名の鍵（git の allowed signers の形）。替えるのは sudo ec-sugar trust（手動で書き換えない）\n'
        while IFS= read -r line; do
            [ -n "$line" ] || continue
            printf '%s\n' "$line"
        done
    } > "$tmp" || { rm -f "$tmp"; return 1; }
    while IFS= read -r line; do
        [[ "$line" =~ ^# ]] && continue
        n=$((n + 1))
    done < "$tmp"
    [ "$n" -gt 0 ] || { rm -f "$tmp"; return 1; }
    chmod 0644 "$tmp"
    if [ "$(id -u)" -eq 0 ]; then chown 0:0 "$tmp" || { rm -f "$tmp"; return 1; }; fi
    mv -f "$tmp" "$EC_TRUST_FILE"
    ec_trust_fingerprints > /dev/null
}

# タグ $1 を確かめる: semver の形・注記つきのタグ・タグの中の名前が同じ（古いタグを新しい名前で出し直す巻き戻しを断る）・コミットを直に指す・
# 人の鍵の SSH の署名（信じるのは EC_TRUST_FILE の鍵だけ。署名の確かめに使う道具・鍵の一覧・信じる段階は、リポジトリの設定〔.git/config〕より強い -c で決め打つ）。
# 通れば 0 で、EC_TAG_COMMIT にコミット・EC_TAG_SIGNER に鍵の名前。通らなければ 1 で、EC_TAG_PROBLEM に理由（1 行）
ec_tag_verify() {
    local tag="$1" kind name target out
    EC_TAG_COMMIT="" EC_TAG_SIGNER="" EC_TAG_PROBLEM=""
    if ! ec_valid_semver_tag "$tag"; then EC_TAG_PROBLEM="タグ「${tag}」は v1.2.3（リリース候補は v1.2.3-rc.1）の形ではありません"; return 1; fi
    if ! ec_trust_file_ok; then
        EC_TAG_PROBLEM="信頼する鍵の一覧 ${EC_TRUST_FILE} が無いか、root の持ち物でないか、ほかの利用者が書けるか、読めない行があります（sudo ec-sugar trust list）"
        return 1
    fi
    kind="$(ec_git cat-file -t "refs/tags/$tag" 2> /dev/null || true)"
    case "$kind" in
        tag) ;;
        commit) EC_TAG_PROBLEM="タグ ${tag} に署名がありません（注記の無い軽いタグ）"; return 1 ;;
        *) EC_TAG_PROBLEM="タグ ${tag} が取得元にありません"; return 1 ;;
    esac
    name="$(ec_git for-each-ref --format='%(tag)' "refs/tags/$tag" 2> /dev/null || true)"
    if [ "$name" != "$tag" ]; then EC_TAG_PROBLEM="タグ ${tag} の中に書かれた名前が違います（別のタグを新しい名前で出し直した恐れ）"; return 1; fi
    target="$(ec_git for-each-ref --format='%(*objecttype) %(*objectname)' "refs/tags/$tag" 2> /dev/null || true)"
    if ! [[ "$target" =~ ^commit\ ([0-9a-f]{40})$ ]]; then EC_TAG_PROBLEM="タグ ${tag} がコミットを直に指していません"; return 1; fi
    target="${BASH_REMATCH[1]}"
    if ! out="$(ec_git -c gpg.format=ssh -c gpg.ssh.program=ssh-keygen -c "gpg.ssh.allowedSignersFile=$EC_TRUST_FILE" -c gpg.minTrustLevel=fully \
        verify-tag "refs/tags/$tag" 2>&1)"; then
        case "$out" in
            *'No principal matched'*) EC_TAG_PROBLEM="タグ ${tag} の署名は、信頼する鍵のどれのものでもありません" ;;
            *'no signature found'*) EC_TAG_PROBLEM="タグ ${tag} に署名がありません" ;;
            *) EC_TAG_PROBLEM="タグ ${tag} の署名が中身と合いません（書き換えられた恐れ）" ;;
        esac
        return 1
    fi
    if ! [[ "$out" =~ Good\ \"git\"\ signature\ for\ ([A-Za-z0-9._@+-]+)\ with ]]; then
        EC_TAG_PROBLEM="タグ ${tag} の署名の鍵の名前を読めません"
        return 1
    fi
    EC_TAG_SIGNER="${BASH_REMATCH[1]}"
    EC_TAG_COMMIT="$target"
}

# 公開版の形で、入れるタグを決める。$1 --to のタグ（空なら取得元の署名の通る最新）$2 自動の更新なら 1 $3 --allow-downgrade なら 1
# $4 今のコミット $5 今のバージョン（タグ）。
#   0: 入れる（EC_PICK_TAG・EC_PICK_COMMIT・EC_PICK_SIGNER） 3: 新しいものは無い 1: 断る（EC_PICK_PROBLEM に理由）
# どれでも、今より新しいのに署名の通らなかったタグを EC_PICK_SKIPPED に、自動の更新が見送ったメジャーバージョンを EC_PICK_MAJOR に（空白で区切る）。
# 決まり: 古いバージョン（巻き戻し）は手の更新で --allow-downgrade を付けた時だけ。同じ名前で別のコミットを指すタグ（書き換え）は断る。
# 新しいバージョンは今のコミットから早送りでつながる時だけ。自動の更新は試しの版とメジャーバージョンの上がるものを取らない（--to で名指しした時だけ）
ec_release_pick() {
    local to="$1" auto="$2" allow="$3" cur="$4" curv="$5" t best cands=() rest=()
    EC_PICK_TAG="" EC_PICK_COMMIT="" EC_PICK_SIGNER="" EC_PICK_PROBLEM="" EC_PICK_SKIPPED="" EC_PICK_MAJOR=""
    if ! ec_valid_semver_tag "$curv"; then
        EC_PICK_PROBLEM="今のバージョン（${EC_STATE}/current_version）が分かりません（release チャネルで入れたサーバーには残る。sudo ./deploy/install.sh を再実行する）"
        return 1
    fi
    if [ -n "$to" ]; then
        if ! ec_valid_semver_tag "$to"; then
            EC_PICK_PROBLEM="release チャネルでは、--to にはバージョンのタグ（v1.2.3・リリース候補 v1.2.3-rc.1）だけを書けます（コミットやブランチは入れない）"
            return 1
        fi
        if ! ec_tag_verify "$to"; then EC_PICK_PROBLEM="$EC_TAG_PROBLEM"; return 1; fi
        EC_PICK_TAG="$to"
    else
        # 今より新しいタグの候補（自動の更新は、試しの版とメジャーバージョンの上がるものを外す）
        while IFS= read -r t; do
            ec_semver_cmp "$t" "$curv" || continue
            [ "$EC_CMP" -eq 1 ] || continue
            if [ "$auto" = 1 ]; then
                ec_semver_is_pre "$t" && continue
                if [ "$(ec_semver_major "$t")" != "$(ec_semver_major "$curv")" ]; then
                    EC_PICK_MAJOR="${EC_PICK_MAJOR:+$EC_PICK_MAJOR }$t"
                    continue
                fi
            fi
            cands+=("$t")
        done < <(ec_release_tags)
        # いちばん新しいものから確かめ、署名が通らなければ飛ばして次へ（飛ばしたものは知らせる）
        while [ "${#cands[@]}" -gt 0 ]; do
            best="${cands[0]}"
            for t in "${cands[@]}"; do
                if ec_semver_cmp "$t" "$best" && [ "$EC_CMP" -eq 1 ]; then best="$t"; fi
            done
            if ec_tag_verify "$best"; then
                EC_PICK_TAG="$best"
                break
            fi
            EC_PICK_SKIPPED="${EC_PICK_SKIPPED:+$EC_PICK_SKIPPED }$best"
            rest=()
            for t in "${cands[@]}"; do [ "$t" = "$best" ] || rest+=("$t"); done
            cands=(${rest[@]+"${rest[@]}"})
        done
        [ -n "$EC_PICK_TAG" ] || return 3
    fi
    EC_PICK_COMMIT="$EC_TAG_COMMIT"
    EC_PICK_SIGNER="$EC_TAG_SIGNER"
    ec_semver_cmp "$EC_PICK_TAG" "$curv"
    case "$EC_CMP" in
        0)
            [ "$EC_PICK_COMMIT" != "$cur" ] || return 3
            EC_PICK_PROBLEM="タグ ${EC_PICK_TAG} が、今のバージョン（同じ名前）と別のコミットを指しています（出したタグが書き換えられた恐れ。入れません）"
            return 1
            ;;
        -1)
            if [ "$allow" != 1 ] || [ "$auto" = 1 ]; then
                EC_PICK_PROBLEM="${EC_PICK_TAG} は今のバージョン ${curv} より古いバージョンです（巻き戻しは、手の更新で --allow-downgrade を付けた時だけ）"
                return 1
            fi
            ;;
        *)
            if ! ec_git merge-base --is-ancestor "$cur" "$EC_PICK_COMMIT" 2> /dev/null; then
                EC_PICK_PROBLEM="${EC_PICK_TAG} は今のバージョン ${curv} から fast-forward できません（取得元の履歴が書き換えられた恐れ。入れません）"
                return 1
            fi
            ;;
    esac
    return 0
}

# 公開版のイメージ $1（名前@sha256:…）の CI の署名を確かめる cosign の引数（1 行に 1 つ）。$2 タグ $3 タグの指すコミット。
# 身元は「公開用のリポジトリの release.yml がこのタグで動いた」ことちょうど（正規表現の形は使わない）・発行者は GitHub Actions・
# 証明書の workflow のコミットがタグの指すコミット（人の署名と CI の署名を結ぶ。ADR 0099 の 7）。透明性のログも確かめる（既定。--insecure-… を付けない）
ec_cosign_args() {
    local ref="$1" tag="$2" commit="$3"
    case "${ref%@*}" in
        "$EC_RELEASE_IMAGE" | "$EC_RELEASE_CADDY_IMAGE") ;;
        *) return 1 ;;
    esac
    [[ "${ref#*@}" =~ ^sha256:[0-9a-f]{64}$ ]] || return 1
    ec_valid_semver_tag "$tag" && ec_valid_commit "$commit" || return 1
    printf '%s\n' verify \
        --certificate-identity "https://github.com/${EC_RELEASE_REPO}/${EC_RELEASE_WORKFLOW}@refs/tags/${tag}" \
        --certificate-oidc-issuer "$EC_RELEASE_ISSUER" \
        --certificate-github-workflow-repository "$EC_RELEASE_REPO" \
        --certificate-github-workflow-ref "refs/tags/${tag}" \
        --certificate-github-workflow-sha "$commit" \
        "$ref"
}
# cosign を、版と digest を固定した公式のイメージで動かす（ホストに入れない）。引数は標準入力から 1 行に 1 つ。
# 根は読み取り専用・権限なし・使い捨ての /tmp（TUF の控えはここ）。root の docker のログインがあれば読み取り専用で渡す（中では root だが権限は無い）
ec_cosign() {
    local args=() line opts=()
    while IFS= read -r line; do args+=("$line"); done
    [ "${#args[@]}" -gt 0 ] || return 1
    if [ -f "$EC_REGISTRY_AUTH" ]; then
        opts=(--user 0:0 -v "$EC_REGISTRY_AUTH:/run/ec-sugar-registry/config.json:ro" -e DOCKER_CONFIG=/run/ec-sugar-registry)
    fi
    docker run --rm --read-only --cap-drop ALL --security-opt no-new-privileges:true \
        --tmpfs /tmp:size=64m,mode=1777,noexec,nosuid,nodev -e HOME=/tmp ${opts[@]+"${opts[@]}"} "$EC_COSIGN_IMAGE" "${args[@]}"
}
# イメージ $1（名前:タグ）が今指す digest を、取らずに置き場へ聞く（sha256:… を出す）
ec_registry_digest() {
    local d
    d="$(docker buildx imagetools inspect --format '{{.Manifest.Digest}}' "$1" 2> /dev/null)" || return 1
    [[ "$d" =~ ^sha256:[0-9a-f]{64}$ ]] || return 1
    printf '%s' "$d"
}
# 取り出したバージョンの compose の入口（Caddy）のイメージの名前（要らない形〔caddy の無い前の形・すでにある Web サーバーの後ろ〕は空）。解けなければ 1
ec_caddy_name() {
    local image="$1" has=0 name
    ec_with_image "$image" ec_has_caddy || has=$?
    [ "$has" -ne 2 ] || return 1
    if [ "$has" -ne 0 ] || [ "$(ec_front)" = proxy ]; then return 0; fi
    name="$(ec_with_image "$image" ec_dc config --images 2> /dev/null | grep -m1 '^ec-sugar-caddy:' || true)"
    [ -n "$name" ] || return 1
    printf '%s' "$name"
}
# 取ったイメージ $1 の印（org.opencontainers.image.revision）が、タグの指すコミット $2 か（公開用の CI が付ける）
ec_release_revision_ok() {
    [ "$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$1" 2> /dev/null || true)" = "$2" ]
}
# 公開版のイメージを得る: 2 つのイメージ（アプリと入口の Caddy）が今指す digest を聞き、それぞれの digest の CI の署名を確かめてから、
# digest で取る（タグの名前では取らない）。取ったものは今までの名前（ec-sugar:<コミット> と compose の caddy の名前）でも呼べるようにする
# （ロールバック・片付け・compose は今までと同じ）。1 つでも通らなければ、何も取らず・名前も付けずに 1。$1 コミット $2 タグ
ec_release_obtain() {
    local commit="$1" tag="$2" image caddy app_ref caddy_ref="" d log
    if ! ec_valid_commit "$commit" || ! ec_valid_semver_tag "$tag"; then
        ec_warn "公開版のイメージを得るには、コミットとタグが要ります（内部の誤り）"
        return 1
    fi
    image="$(ec_image_of "$commit")"
    if ! caddy="$(ec_caddy_name "$image")"; then
        ec_warn "取り出したバージョンの compose からリバースプロキシ（caddy）のイメージの名前を読めません（env に足りない項目がある見込み。sudo ./deploy/install.sh を再実行する）"
        return 1
    fi
    mkdir -p "$EC_LOGS"
    log="$EC_LOGS/verify-${tag}.log"
    : > "$log"
    if ! d="$(ec_registry_digest "$EC_RELEASE_IMAGE:$tag")"; then
        ec_warn "公開版のイメージ ${EC_RELEASE_IMAGE}:${tag} がレジストリに見つかりません（タグの名前と、ghcr.io に届くかを確かめる。sudo ./deploy/install.sh --check）"
        return 1
    fi
    app_ref="$EC_RELEASE_IMAGE@$d"
    if [ -n "$caddy" ]; then
        if ! d="$(ec_registry_digest "$EC_RELEASE_CADDY_IMAGE:$tag")"; then
            ec_warn "公開版のリバースプロキシのイメージ ${EC_RELEASE_CADDY_IMAGE}:${tag} がレジストリに見つかりません"
            return 1
        fi
        caddy_ref="$EC_RELEASE_CADDY_IMAGE@$d"
    fi
    ec_say "イメージの署名を確認しています（cosign。記録は ${log}）"
    if ! ec_cosign_args "$app_ref" "$tag" "$commit" | ec_cosign >> "$log" 2>&1; then
        ec_warn "アプリのイメージ ${app_ref} の署名が通りません（公開用のリポジトリの release.yml が、タグ ${tag} のコミット ${commit:0:12} から作ったものではない）"
        return 1
    fi
    if [ -n "$caddy_ref" ] && ! ec_cosign_args "$caddy_ref" "$tag" "$commit" | ec_cosign >> "$log" 2>&1; then
        ec_warn "リバースプロキシのイメージ ${caddy_ref} の署名が通りません（公開用のリポジトリの release.yml が、タグ ${tag} のコミット ${commit:0:12} から作ったものではない）"
        return 1
    fi
    ec_ok "イメージの署名が通った（${EC_RELEASE_REPO} の release.yml がタグ ${tag} のコミット ${commit:0:12} から作ったもの）"
    for d in "$app_ref" $caddy_ref; do
        if ! ec_pull_retry "$d" "$log"; then
            ec_warn "イメージ ${d} を digest で取れませんでした（3 回試した。記録 ${log}）"
            return 1
        fi
        if ! ec_release_revision_ok "$d" "$commit"; then
            ec_warn "イメージ ${d} の印（org.opencontainers.image.revision）が、タグの指すコミット ${commit:0:12} と違います"
            return 1
        fi
    done
    docker tag "$app_ref" "$image" || return 1
    if [ -n "$caddy_ref" ]; then docker tag "$caddy_ref" "$caddy" || return 1; fi
    ec_kv_set "$EC_STATE/release-$commit" tag "$tag"
    ec_kv_set "$EC_STATE/release-$commit" app "$app_ref"
    [ -z "$caddy_ref" ] || ec_kv_set "$EC_STATE/release-$commit" caddy "$caddy_ref"
    ec_ok "イメージを digest で取った（${app_ref}${caddy_ref:+、${caddy_ref}}）"
}

# 取得元の URL にパスワードやトークンが入っているか（中: 記録・state・画面に出る）。http(s):// は @ があれば（トークンを名前の所に
# 入れる形もある）、ssh:// は名前の後ろにパスワード（:…）があれば。git@github.com:… と ssh://git@… は名前だけなので通す
ec_url_has_secret() {
    local auth
    case "$1" in
        http://* | https://*)
            auth="${1#*://}"
            auth="${auth%%/*}"
            [[ "$auth" == *@* ]]
            ;;
        ssh://* | git+ssh://*)
            auth="${1#*://}"
            auth="${auth%%/*}"
            [[ "$auth" == *@* ]] && [[ "${auth%@*}" == *:* ]]
            ;;
        *) return 1 ;;
    esac
}

# $1 がリンクでないふつうのファイルで、たどった先も置き場 $2 の中か（運用の道具を写す前。中「ec_install_tools がリンクを写す」）
ec_plain_file() {
    local real root
    [ -f "$1" ] && [ ! -L "$1" ] || return 1
    real="$(readlink -f "$1" 2> /dev/null)" && root="$(readlink -f "$2" 2> /dev/null)" || return 1
    case "$real" in "$root"/*) return 0 ;; *) return 1 ;; esac
}
# 確かめたコミット $1 の運用の道具（deploy/）と compose の守りの検査（docker/check-compose.sh。あれば）を、git の中身から root だけの一時の置き場へ
# 取り出し、その道筋を出す（作業場所のファイルは使わない。作業場所は利用者 ec-sugar が書けるため。高）。呼ぶ側が rm -rf で消す
ec_deploy_from_commit() {
    local commit="$1" dir
    ec_valid_commit "$commit" || return 1
    dir="$(mktemp -d "${TMPDIR:-/tmp}/ec-sugar-deploy.XXXXXX")" || return 1
    chmod 0700 "$dir"
    if ! ec_git archive --format=tar "$commit" -- deploy | tar -x -f - -C "$dir" --no-same-owner 2> /dev/null; then
        rm -rf "$dir"
        return 1
    fi
    if [ -n "$(ec_git ls-tree --name-only "$commit" -- docker/check-compose.sh 2> /dev/null || true)" ]; then
        mkdir -p "$dir/docker"
        if ! ec_git archive --format=tar "$commit" -- docker/check-compose.sh | tar -x -f - -C "$dir" --no-same-owner 2> /dev/null; then
            rm -rf "$dir"
            return 1
        fi
    fi
    if ! ec_plain_file "$dir/deploy/ec-sugar" "$dir" || ! ec_plain_file "$dir/deploy/lib/common.sh" "$dir"; then
        rm -rf "$dir"
        return 1
    fi
    printf '%s' "$dir"
}
# 取り出したバージョンの compose（compose の置き場。開発の形は作業場所 $EC_SRC、公開版の形は root だけの置き場）を、今入っているバージョンの守りの検査（$EC_LIB_DIR/check-compose.sh）にかける（高。
# 新しいバージョンが守り〔ポート・権限・網・イメージの固定〕を緩めていたら、起こす前に気づく）。入っていなければ（この仕組みより前のバージョンから
# 初めて上げる時）、取り出したバージョンの検査 $1 で。0 通った 1 通らない 2 確かめられない（jq か検査が無い）。記録は EC_COMPOSE_GUARD_LOG
ec_compose_guard() {
    local checker="$EC_LIB_DIR/check-compose.sh" tree
    EC_COMPOSE_GUARD_LOG=""
    [ -f "$checker" ] || checker="${1:-}"
    [ -n "$checker" ] && [ -f "$checker" ] || return 2
    command -v jq > /dev/null || return 2
    mkdir -p "$EC_LOGS"
    EC_COMPOSE_GUARD_LOG="$EC_LOGS/compose-check-$(date +%Y%m%d-%H%M%S).log"
    # 見るのは compose が使う置き場そのもの（公開版の形は root だけの置き場。検査の後に作業場所を書き換えられても、起こすのは検査した compose）
    if ! tree="$(ec_compose_dir)"; then
        echo "NG: compose のディレクトリを決められない（git から取り出せない）" | tee "$EC_COMPOSE_GUARD_LOG" >&2
        return 1
    fi
    if EC_CHECK_TREE="$tree" bash "$checker" > "$EC_COMPOSE_GUARD_LOG" 2>&1; then return 0; fi
    grep '^NG' "$EC_COMPOSE_GUARD_LOG" | head -n 10 >&2 || true
    return 1
}
# 更新の途中の印を置く・消す（$1 今のコミット $2 新しいコミット）
ec_update_mark() {
    local tmp="$EC_STATE/.updating.tmp.$$"
    { printf 'time=%s\n' "$(ec_now)" && printf 'from=%s\n' "$1" && printf 'to=%s\n' "$2"; } > "$tmp" && mv -f "$tmp" "$EC_UPDATE_MARK"
}
ec_update_mark_clear() { rm -f "$EC_UPDATE_MARK"; }

# ---------- 移行（入れ替える前に新しいイメージで。失敗しても動いているバージョンはそのまま） ----------
# Laravel は PostgreSQL では移行を 1 つずつトランザクションで包むので、失敗した移行は丸ごと取り消される。
# db と redis はもう動いている前提（--no-deps で起こし直さない）。イメージの名前は env ファイルより先に効く環境変数で渡す
ec_migrate() {
    local image="$1" log
    mkdir -p "$EC_LOGS"
    log="$EC_LOGS/migrate-$(date +%Y%m%d-%H%M%S).log"
    ec_say "マイグレーションしています（ログは ${log}）"
    if ! ec_with_image "$image" ec_dc run --rm --no-deps -T app php artisan migrate --force > "$log" 2>&1; then
        tail -n 20 "$log" >&2
        ec_warn "中央の DB のマイグレーションに失敗しました"
        return 1
    fi
    if ! ec_with_image "$image" ec_dc run --rm --no-deps -T app php artisan tenants:migrate --force >> "$log" 2>&1; then
        tail -n 20 "$log" >&2
        ec_warn "店の DB のマイグレーションに失敗しました（一部の店だけ済んだかもしれません。DB の変え方は「足してから後で消す」なので古いバージョンはどちらでも動きます）"
        return 1
    fi
    ec_ok "マイグレーションした"
}

# DB が TCP で受け付けるまで待つ（初回は初期化の間だけ仮の DB がソケットだけで動くので、pg_isready の既定では早すぎる）
ec_wait_db() {
    local i
    for i in $(seq 1 90); do
        ec_dc exec -T db pg_isready -q -h 127.0.0.1 -U ec -d ec_central > /dev/null 2>&1 && return 0
        sleep 2
    done
    return 1
}

# ---------- 健全の確かめ ----------
# 入口（Caddy）へ https で聞く住所（受け口が 0.0.0.0 なら自分の 127.0.0.1）
ec_probe_ip() {
    local bind
    bind="$(ec_env_get EC_SUGAR_BIND)"
    [ "$bind" = 0.0.0.0 ] && bind=127.0.0.1
    printf '%s' "$bind"
}
# 応答の番号の形を確かめて出す（100〜599 の 3 桁でなければ 000）。curl は届かないと %{http_code} に 000 を出してから失敗で終わるので、
# 後ろに || printf 000 を続けると 000000 になり、「000 でなく 500 未満」の比べを素通りしていた（見直しの軽 1）
ec_code_norm() { if [[ "$1" =~ ^[1-5][0-9]{2}$ ]]; then printf '%s' "$1"; else printf '000'; fi; }
# 届いていて 500 未満か（形を確かめてから比べる）
ec_code_below_500() { [[ "$1" =~ ^[1-4][0-9]{2}$ ]]; }
# 入口（Caddy）に https で聞き、応答の番号を出す（届かなければ 000）。$3 が verify なら証明書を確かめる（$4 は信じる根のファイル。空なら OS の根）。
# 既定は確かめない（健全の確かめは「動いているか」を見る。証明書の取得はインストールの仕上げで別に確かめる）
ec_http_code() {
    local host="$1" path="$2" verify="${3:-}" ca="${4:-}" port ip tls=(-k)
    port="$(ec_env_get EC_SUGAR_HTTPS_PORT || true)"
    port="${port:-443}"
    ip="$(ec_probe_ip)"
    if [ "$verify" = verify ]; then
        tls=()
        [ -z "$ca" ] || tls=(--cacert "$ca")
    fi
    ec_code_norm "$(curl -sS "${tls[@]}" -o /dev/null -w '%{http_code}' --max-time 20 --resolve "$host:$port:$ip" "https://$host:$port$path" 2> /dev/null || true)"
}
# 後ろに置く形で、ホストの Unix ソケットへ Host と https の印を付けて聞く（前の Web サーバーが転送する道と同じ。root で流す）。応答の番号を出す
ec_proxy_code() {
    ec_code_norm "$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 --unix-socket "$EC_FRONT_SOCKET" -H "Host: $1" -H 'X-Forwarded-Proto: https' \
        "http://localhost$2" 2> /dev/null || true)"
}
# 後ろの段（nginx → PHP）に聞く（証明書に頼らない）。応答の番号を出す（届かなければ 000）。
# ふつうの形は nginx のコンテナの中から 8080 番へ、後ろに置く形は TCP で受けないのでホストのソケットへ
ec_back_code() {
    local code
    if [ "$(ec_front)" = proxy ]; then
        ec_proxy_code "$1" "$2"
        return 0
    fi
    code="$(ec_dc exec -T web wget -S -q -O /dev/null -T 10 --header "Host: $1" "http://127.0.0.1:8080$2" 2>&1 | awk '$1 ~ /^HTTP\// {c = $2} END {print c}' || true)"
    ec_code_norm "$code"
}
# $1 期待するイメージ。全部のコンテナが動いて健全、app が期待するイメージ、後ろの段で /up が 200・管理画面の入口とショップのホストが 500 未満、
# キューの処理が見本の仕事を片付ける（operations:queue-probe を持つバージョンだけ）、
# 入口（Caddy）から https で /up が 200（EC_HEALTH_TLS=0 のときは見ない。初めて入れるときは証明書を取る前なので、仕上げで確かめる）。
# 前の形のバージョン（caddy が無い。戻したとき）は、前の確かめ方（nginx が受ける https の /up）だけ。
# --remove-orphans: 前の形へ戻したとき caddy のコンテナを残さない（残ると 80・443 番を持ったままで、nginx が起きない）
ec_health() {
    local want="$1" admin shop code i bad svc two=1 has=0
    ec_has_caddy || has=$?
    if [ "$has" -eq 2 ]; then
        ec_warn "compose を解けません（env に足りない項目がある見込み。sudo ./deploy/install.sh を再実行する）"
        return 1
    fi
    [ "$has" -eq 0 ] || two=0
    # 網が作り直されるときに決まりの無い間を作らない（作るだけ → 防火壁 → 起こす）
    ec_dc_create_guarded --remove-orphans
    if ! ec_dc up -d --wait --remove-orphans --wait-timeout "$EC_HEALTH_TIMEOUT" > /dev/null 2>&1; then
        ec_firewall_apply || ec_warn "ホストのファイアウォールを適用できませんでした（sudo ec-sugar firewall で再適用する）"
        ec_warn "コンテナが時間内にヘルスチェックを通りませんでした"
        ec_dc ps --format '   {{.Service}}: {{.State}} {{.Health}}' >&2 || true
        # Cloudflare Tunnel の形: cloudflared は Cloudflare につながるまで healthy にならない（ADR 0052）
        if [ "$(ec_front)" = tunnel ] && [ "$(ec_tunnel_state)" != "running healthy" ]; then
            ec_warn "cloudflared が Cloudflare に接続していません（トークンが正しいか・このサーバーから外の 7844 番〔UDP と TCP〕へ出られるかを見る。sudo ec-sugar logs cloudflared）"
        fi
        return 1
    fi
    # 網が作り直されていれば、防火壁の決まりも足し直す（当てられなくても、動いているバージョンの良し悪しとは別なので戻さない。status が ✗ を出す）
    ec_firewall_apply || ec_warn "ホストのファイアウォールを適用できませんでした（sudo ec-sugar firewall で再適用する）"
    bad="$(ec_dc ps --format '{{.Service}} {{.State}} {{.Health}}' | awk '$2 != "running" || ($3 != "" && $3 != "healthy") {print}')"
    if [ -n "$bad" ]; then
        ec_warn "ヘルスチェックを通らないコンテナがあります: $bad"
        return 1
    fi
    for svc in app queue scheduler; do
        if [ "$(ec_dc ps --format '{{.Image}}' "$svc")" != "$want" ]; then
            ec_warn "$svc が $want で動いていません"
            return 1
        fi
    done
    admin="$(ec_env_get EC_SUGAR_ADMIN_HOST)"
    if [ "$two" -eq 1 ]; then
        for i in $(seq 1 30); do
            code="$(ec_back_code "$admin" /up)"
            [ "$code" = 200 ] && break
            sleep 2
        done
        if [ "$code" != 200 ]; then
            ec_warn "バックエンド（nginx から PHP）で $admin の /up が $code を返しました（200 のはず）"
            return 1
        fi
        code="$(ec_back_code "$admin" /)"
        if ! ec_code_below_500 "$code"; then
            ec_warn "バックエンド（nginx から PHP）で $admin の / が $code を返しました"
            return 1
        fi
        # ショップのホストも見る（店の DB と店の画面を描く道。準備中のパスワードの画面や 404 は通す）
        shop="$(ec_shop_host)"
        if [ -n "$shop" ]; then
            code="$(ec_back_code "$shop" /)"
            if ! ec_code_below_500 "$code"; then
                ec_warn "バックエンド（nginx から PHP）で $shop の / が $code を返しました"
                return 1
            fi
        fi
        # app から出口の代理に届くか（ADR 0102）。代理の無いバージョン（戻した先の前のバージョン）では見ない
        if ec_has_egress && ! ec_egress_reachable; then
            ec_warn "app から送信用プロキシ（egress の 3128 番）に届きません（外への https が止まります。sudo ec-sugar logs egress で理由を見る）"
            return 1
        fi
        # キューの処理が見本の仕事を片付けるか。口を持たないバージョン（戻した先の前のバージョン）では見ない
        if ec_dc exec -T app php artisan list --raw 2> /dev/null | ec_q '^operations:queue-probe '; then
            if ! ec_dc exec -T app php artisan operations:queue-probe --timeout="$EC_QUEUE_PROBE_TIMEOUT" > /dev/null 2>&1; then
                ec_warn "キューの処理が ${EC_QUEUE_PROBE_TIMEOUT} 秒のうちに見本の仕事を片付けませんでした（sudo ec-sugar logs queue で理由を見る）"
                return 1
            fi
        fi
    fi
    if [ "$two" -eq 1 ] && [ "$(ec_front)" = proxy ]; then
        # 後ろに置く形: https は前の Web サーバーが受けるので、前の Web サーバーが転送するソケットで確かめる
        for i in $(seq 1 30); do
            code="$(ec_proxy_code "$admin" /up)"
            [ "$code" = 200 ] && break
            sleep 2
        done
        if [ "$code" != 200 ]; then
            ec_warn "ソケット $EC_FRONT_SOCKET に $admin の /up を聞くと $code でした（200 のはず。sudo ec-sugar logs web で理由を見る）"
            return 1
        fi
        ec_ok "ヘルスチェックを通った（コンテナはすべて healthy、ソケットで $admin の /up が 200）"
    elif [ "${EC_HEALTH_TLS:-1}" = 1 ] || [ "$two" -eq 0 ]; then
        for i in $(seq 1 30); do
            code="$(ec_http_code "$admin" /up)"
            [ "$code" = 200 ] && break
            sleep 2
        done
        if [ "$code" != 200 ]; then
            ec_warn "リバースプロキシ（Caddy）から https://$admin/up が $code を返しました（200 のはず。sudo ec-sugar logs caddy で理由を見る）"
            return 1
        fi
        ec_ok "ヘルスチェックを通った（コンテナはすべて healthy、https://$admin/up が 200）"
    else
        ec_ok "ヘルスチェックを通った（コンテナはすべて healthy、バックエンドで /up が 200。https は仕上げで確認する）"
    fi
}

# ---------- すでにある Web サーバーの後ろに置く形の確かめ（ec-sugar check-front） ----------
# 前の Web サーバーを https で通して、管理画面とショップの両方のホストで見る:
#   1. https で開けるか（前の Web サーバーの設定と証明書）
#   2. https の印（X-Forwarded-Proto: https）を付け直しているか。偽の X-Forwarded-Proto: http も付けて送り、そのまま渡す設定も見つける
#      （渡していないと nginx が https へ送り直し続ける）
#   3. 本当の接続元が届くか: 偽の X-Forwarded-For を付けて送り、EC-SUGAR に届いた接続元が、この確かめの送り元（受け口の住所か 127.0.0.1）と
#      同じ IPv4 のときだけ通す（そのまま渡す設定は偽の住所、落とす設定は unix: が届く。どちらも管理画面の接続元の制限が正しく効かない）
# 返り: 0 通った、1 直すことがある（偽れる・印が無い）、2 まだ確かめられない（開けない）。結果を state/front_check に残す（status で出す）
ec_check_front() {
    local host fake=203.0.113.250 tag line addr code rc=0 ca=() result logs probe
    [ -z "${EC_SUGAR_TEST_TRUST:-}" ] || ca=(--cacert "$EC_SUGAR_TEST_TRUST")
    for host in "$(ec_env_get EC_SUGAR_ADMIN_HOST)" "$(ec_shop_host)"; do
        [ -n "$host" ] || continue
        tag="ec-sugar-front-check-$(ec_random_alnum 12)"
        probe="$(ec_probe_ip)"
        code="$(ec_code_norm "$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "${ca[@]}" -H "X-Forwarded-For: $fake" -H 'X-Forwarded-Proto: http' \
            --resolve "$host:443:$probe" "https://$host/up?$tag" 2> /dev/null || true)")"
        if [ "$code" = 000 ]; then
            ec_warn "$host: まだ https で開けません（前段のリバースプロキシの設定・証明書がまだ）"
            [ "$rc" -eq 1 ] || rc=2
            continue
        fi
        if [ "$code" = 308 ] || [ "$code" = 301 ]; then
            ec_warn "$host: 前段のリバースプロキシが X-Forwarded-Proto を https に付け直していません（渡していないか、外から来た値をそのまま渡している。転送の設定に proxy_set_header X-Forwarded-Proto \$scheme; を足す）"
            rc=1
            continue
        fi
        sleep 1
        if ! logs="$(ec_dc logs --no-log-prefix --since 10m web 2> /dev/null)"; then
            ec_warn "$host: EC-SUGAR のログを読めません（compose を解けない。env が足りない見込み。sudo ./deploy/install.sh を再実行する）"
            [ "$rc" -eq 1 ] || rc=2
            continue
        fi
        line="$(printf '%s\n' "$logs" | grep -F "$tag" | tail -n1 || true)"
        addr="${line%% *}"
        if [ -z "$line" ]; then
            ec_warn "$host: 確認の要求が EC-SUGAR に届きません（前段のリバースプロキシの転送先が EC-SUGAR のソケットか見る）"
            [ "$rc" -eq 1 ] || rc=2
        elif [ "$addr" = "$fake" ]; then
            ec_warn "$host: 前段のリバースプロキシが、外から来た X-Forwarded-For をそのまま渡しています（管理画面の接続元の制限を偽れる。proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for; を足す）"
            rc=1
        elif ! ec_valid_ipv4 "$addr" || { [ "$addr" != "$probe" ] && [ "$addr" != 127.0.0.1 ]; }; then
            # 落とす設定（届くのは unix:）・別の住所に付け直す設定（CDN など）。管理画面の接続元の制限が、本当の接続元で効かない
            ec_warn "$host: EC-SUGAR に届いた接続元が $addr で、この確認の送り元（${probe}）と違います（前段のリバースプロキシが X-Forwarded-For を付けていない・別の IP アドレスに付け直している。proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for; を足す）"
            rc=1
        else
            ec_ok "$host: https で開け、偽の X-Forwarded-For を付けても本当の接続元（${addr}）が届く"
        fi
    done
    case "$rc" in
        0) result=ok ;;
        1) result=ng ;;
        *) result='wait' ;;
    esac
    ec_state_put front_check "$result $(date -Is)"
    return "$rc"
}

# ---------- Cloudflare Tunnel で出す形（FRONT=tunnel。ADR 0052） ----------
# トークンは Cloudflare がトンネルごとに出す 1 行（base64 の JSON。a はアカウント、t はトンネルの ID〔UUID〕、s はトンネルの秘密）。
# 決まり: トークンは bash の中だけで読み、画面・記録・環境変数・外のコマンドの引数に出さない（ps・docker inspect で見える）。
# 外のコマンド（base64）へは標準入力で渡す。誤りの文にも中身を出さない
ec_valid_uuid() { [[ "$1" =~ ^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$ ]]; }
# $1 トークンのファイル。中身の書いてある行がちょうど 1 つなら、その行から空白（CR も）を落として EC_TUNNEL_TOKEN に入れる（呼ぶ側は使ったらすぐ空にする）。
# 返り: 0 読めた、1 形が違う（中身の行が 0 か 2 つ以上・大きすぎる。コマンドを丸ごと貼ったなど）、2 読めない（無い・読む権限が無い）
EC_TUNNEL_TOKEN=""
ec_tunnel_token_read() {
    local f="$1" line size n=0
    EC_TUNNEL_TOKEN=""
    [ -f "$f" ] && [ -r "$f" ] || return 2
    # 大きすぎるファイルは読まない（トークンは 200 文字ほど）
    size="$(wc -c < "$f" 2> /dev/null | tr -d ' ')"
    [[ "$size" =~ ^[0-9]+$ ]] && [ "$size" -le 4096 ] || return 1
    while IFS= read -r line || [ -n "$line" ]; do
        line="${line//[[:space:]]/}"
        [ -n "$line" ] || continue
        n=$((n + 1))
        [ "$n" -eq 1 ] && EC_TUNNEL_TOKEN="$line"
    done < "$f"
    line=""
    if [ "$n" -ne 1 ]; then
        EC_TUNNEL_TOKEN=""
        return 1
    fi
    return 0
}
# $1 トークンのファイル。形が正しければトンネルの ID（小文字の UUID）を出す。返り: 0 正しい、1 形が違う、2 読めない（無い・読む権限が無い）
ec_tunnel_token_id() {
    local out rc=0
    out="$(ec_tunnel_token_ids "$1")" || rc=$?
    [ "$rc" -eq 0 ] || return "$rc"
    printf '%s' "${out#* }"
}
# $1 トークンのファイル。形が正しければ「アカウントの ID（32 桁の 16 進） トンネルの ID（小文字の UUID）」を出す（どちらも秘密でない。
# 秘密は s だけで、ここでは出さない）。返りは ec_tunnel_token_id と同じ
ec_tunnel_token_ids() {
    local f="$1" token="" json="" id account="" rc=0
    ec_tunnel_token_read "$f" || rc=$?
    token="$EC_TUNNEL_TOKEN"
    EC_TUNNEL_TOKEN=""
    [ "$rc" -eq 0 ] || return "$rc"
    # 長さは別に見る（{40,2000} の形は macOS の正規表現の繰り返しの上限 255 を超えて、手元の確かめで効かない）
    if ! [[ "$token" =~ ^[A-Za-z0-9+/]+={0,2}$ ]] || [ "${#token}" -lt 40 ] || [ "${#token}" -gt 2000 ] || [ $((${#token} % 4)) -ne 0 ]; then
        token=""
        return 1
    fi
    json="$(printf '%s' "$token" | base64 -d 2> /dev/null || true)"
    token=""
    if [[ "$json" =~ ^\{.*\}$ ]] && [[ "$json" =~ \"a\"[[:space:]]*:[[:space:]]*\"[0-9a-f]{32}\" ]] &&
        [[ "$json" =~ \"s\"[[:space:]]*:[[:space:]]*\"[A-Za-z0-9+/]{16,}={0,2}\" ]] &&
        [[ "$json" =~ \"t\"[[:space:]]*:[[:space:]]*\"([0-9A-Fa-f-]{36})\" ]]; then
        # 小文字にそろえる（ID は秘密でない。bash 3.2〔手元の macOS〕でも動く形で）
        id="$(printf '%s' "${BASH_REMATCH[1]}" | tr '[:upper:]' '[:lower:]')"
        [[ "$json" =~ \"a\"[[:space:]]*:[[:space:]]*\"([0-9a-f]{32})\" ]] && account="${BASH_REMATCH[1]}"
        json=""
        ec_valid_uuid "$id" && [ -n "$account" ] || return 1
        printf '%s %s' "$account" "$id"
        return 0
    fi
    json=""
    return 1
}
# このサーバーのトンネルの ID（答えのトークンのファイルか、置き場に写したトークンから）。分からなければ何も出さず 1
ec_tunnel_id() {
    local f id
    f="$(ec_answer TUNNEL_TOKEN_FILE 2> /dev/null || true)"
    if [ -n "$f" ] && id="$(ec_tunnel_token_id "$f")"; then
        printf '%s' "$id"
        return 0
    fi
    id="$(ec_tunnel_token_id "$EC_TUNNEL_DIR/token" 2> /dev/null)" || return 1
    printf '%s' "$id"
}
# cloudflared の設定（config.yml）を出す。$1 管理画面のホスト $2 ショップの場所の名前（1 店舗はショップのホスト、複数の店は *.<ショップのドメイン>）。
# どの名前も入口の Caddy の 443 番へ、要求のホスト名どおりの名前（SNI）で https。ほかの名前は 404。cloudflared は規則ごとに接続を使い回し、
# SNI は新しくつなぐ時だけ決まるので、*.<ショップのドメイン> の規則では店 A の名前の接続に店 B の要求が載る（Tunnel の形の Caddy は
# SNI と Host の違いを断らない。docker/caddy/etc/front-tunnel.servers）。
# トンネルの ID と秘密はトークンから読むので、ここには書かない。名前の形がおかしければ何も出さず 1
# $3 が origin-ca なら、行き先ごとに caPool（Cloudflare の Origin CA の元。置き場の origin-ca.pem）を足す（Caddy の証明書が Origin CA のもの）。
# 確かめを切る noTLSVerify は決して書かない
ec_tunnel_config() {
    local admin="$1" site="$2" ca="${3:-}" host
    ec_valid_host "$admin" || return 1
    ec_valid_host "${site#\*.}" || return 1
    [ -z "$ca" ] || [ "$ca" = origin-ca ] || return 1
    printf '# EC-SUGAR のインストーラーが設定ファイルから書く（手動で直さない。直すときは設定ファイルを直して sudo ./deploy/install.sh を再実行する）。ADR 0052\n'
    printf '# トンネルの ID と秘密はトークン（同じディレクトリの token）から読む。ここは行き先だけ\n'
    [ -z "$ca" ] || printf '# リバースプロキシの Caddy の証明書は Cloudflare の Origin CA のもの。確認する元は同じディレクトリの %s（Cloudflare が公開している元の証明書）\n' "$EC_TUNNEL_CA_NAME"
    printf 'ingress:\n'
    for host in "$admin" "$site"; do
        printf '  - hostname: "%s"\n' "$host"
        printf '    service: %s\n' "$EC_TUNNEL_SERVICE"
        printf '    originRequest:\n'
        printf '      matchSNItoHost: true\n'
        [ -z "$ca" ] || printf '      caPool: %s\n' "$EC_TUNNEL_CA_IN"
    done
    printf '  - service: http_status:404\n'
}
# 置き場を作る（root の持ち物・cloudflared のグループ・0750。何度流しても同じ）
ec_tunnel_dir_ensure() {
    install -d -m 0750 -o root -g "$EC_TUNNEL_UID" "$EC_TUNNEL_DIR"
    chown "root:$EC_TUNNEL_UID" "$EC_TUNNEL_DIR"
    chmod 0750 "$EC_TUNNEL_DIR"
}
# 置き場のファイル $1（名前）を、仮のファイル $2 の中身にする（中身が同じなら仮のファイルを消すだけ）。root の持ち物・cloudflared のグループ・0440。
# 仮のファイルは置き場の中に umask 077 で作ったもの（ほかの利用者から読めない）。入れ替えたら EC_TUNNEL_CHANGED=1
EC_TUNNEL_CHANGED=0
ec_tunnel_put() {
    local tmp="$2" to="$EC_TUNNEL_DIR/$1"
    if cmp -s "$tmp" "$to" 2> /dev/null; then
        rm -f "$tmp"
        return 0
    fi
    if ! { chown "root:$EC_TUNNEL_UID" "$tmp" && chmod 0440 "$tmp" && mv -f "$tmp" "$to"; }; then
        rm -f "$tmp"
        return 1
    fi
    EC_TUNNEL_CHANGED=1
}
# トークンを置き場へ写す。$1 元のファイル（答えの TUNNEL_TOKEN_FILE。無ければ、前に写したものを使う）。形が違えば止める（中身は出さない）
ec_tunnel_put_token() {
    local from="$1" token="" tmp old_umask rc=0
    if [ -z "$from" ] || [ ! -e "$from" ]; then
        if [ -s "$EC_TUNNEL_DIR/token" ] && ec_tunnel_token_id "$EC_TUNNEL_DIR/token" > /dev/null; then
            ec_ok "トンネルのトークンは前にコピーしたもの（$EC_TUNNEL_DIR/token）を使う"
            return 0
        fi
        ec_die "トンネルのトークンのファイル（設定ファイルの TUNNEL_TOKEN_FILE）が無く、前にコピーしたものもありません" \
            "Cloudflare で作ったトンネルのトークンを 1 行で書いたファイルを置き、そのパスを $EC_ANSWERS の TUNNEL_TOKEN_FILE に書いて再実行する（docs/install.md の「Cloudflare Tunnel で出す形」）"
    fi
    ec_tunnel_token_id "$from" > /dev/null || rc=$?
    case "$rc" in
        0) ;;
        2) ec_die "トンネルのトークンのファイル $from が読めません" "ファイルを置き直すか、$EC_ANSWERS の TUNNEL_TOKEN_FILE を直して再実行する" ;;
        *) ec_die "トンネルのトークンの形が違います（Cloudflare のトンネルのトークンでない。中身は出しません）" \
            "Cloudflare で出したトークン（eyJ で始まる 1 行）だけを、引用符やコマンド（cloudflared service install など）を付けずに書いたファイルを渡す" ;;
    esac
    ec_tunnel_token_read "$from" || ec_die "トンネルのトークンのファイル $from が読めません"
    token="$EC_TUNNEL_TOKEN"
    EC_TUNNEL_TOKEN=""
    old_umask="$(umask)"
    umask 077
    tmp="$(mktemp "$EC_TUNNEL_DIR/.token.XXXXXX")"
    printf '%s\n' "$token" > "$tmp"
    token=""
    umask "$old_umask"
    ec_tunnel_put token "$tmp" || ec_die "トンネルのトークンを $EC_TUNNEL_DIR に置けません" "空き容量と権限を確認して再実行する"
    ec_ok "トンネルのトークンは $EC_TUNNEL_DIR の中だけ（cloudflared だけが読める 0440。元のファイル $from は消してよい）"
}
# cloudflared の設定を書く（答えから。何度流しても同じ）。入口の Caddy の証明書（install.sh が先に写した $EC_CADDY_SECRETS/tls/cert.pem。
# Caddy が今から出すもの）が Cloudflare の Origin CA のものなら、先に元の証明書を置き場へ写してから caPool を足す（無いファイルを指す設定を作らない）。
# そうでなければ、設定を書き換えた後に元の証明書を片付ける
ec_tunnel_put_config() {
    local tmp old_umask ca=""
    if ec_tunnel_wants_ca; then
        ec_tunnel_put_ca
        ca=origin-ca
    fi
    old_umask="$(umask)"
    umask 077
    tmp="$(mktemp "$EC_TUNNEL_DIR/.config.yml.XXXXXX")"
    umask "$old_umask"
    if ! ec_tunnel_config "$(ec_answer ADMIN_HOST)" "$(ec_shop_site)" "$ca" > "$tmp"; then
        rm -f "$tmp"
        ec_die "cloudflared の設定を作れません（管理画面かショップのホスト名の形が違う）" "$EC_ANSWERS の ADMIN_HOST・SHOP_HOST（複数の店は SHOP_DOMAIN）を直して再実行する"
    fi
    ec_tunnel_put config.yml "$tmp" || ec_die "cloudflared の設定を $EC_TUNNEL_DIR に置けません" "空き容量と権限を確認して再実行する"
    [ -n "$ca" ] || rm -f "$EC_TUNNEL_DIR/$EC_TUNNEL_CA_NAME"
    ec_ok "cloudflared の設定 $EC_TUNNEL_DIR/config.yml（管理画面と $(ec_shop_site) をリバースプロキシの Caddy の 443 番へ。ほかの名前は 404$([ -z "$ca" ] || printf '。リバースプロキシの証明書は Cloudflare の Origin CA のものなので、確認する元 %s を同じディレクトリに置いて caPool で指す' "$EC_TUNNEL_CA_NAME")）"
}
# 入口の Caddy の証明書が、手で渡した Cloudflare の Origin CA のものか（cloudflared に元の証明書を教えるか）
ec_tunnel_wants_ca() {
    [ "$(ec_tls_mode)" = manual ] && ec_cert_is_origin_ca "$EC_CADDY_SECRETS/tls/cert.pem"
}
# Cloudflare の Origin CA の元の証明書（EC_ORIGIN_CA_ROOTS）を、証明書の部分だけ置き場へ写す（root の持ち物・cloudflared のグループ・0440）。
# 元が読めない・証明書が入っていなければ止める
ec_tunnel_put_ca() {
    local from="$EC_ORIGIN_CA_ROOTS" tmp old_umask
    if [ -z "$from" ] || [ ! -r "$from" ]; then
        ec_die "Cloudflare の Origin CA の元の証明書（${from:-パスが空}）が読めません" "取り出したリポジトリに deploy/cloudflare/origin-ca-roots.crt があるかを確認する（git status）"
    fi
    old_umask="$(umask)"
    umask 077
    tmp="$(mktemp "$EC_TUNNEL_DIR/.$EC_TUNNEL_CA_NAME.XXXXXX")"
    umask "$old_umask"
    sed -n '/^-----BEGIN CERTIFICATE-----$/,/^-----END CERTIFICATE-----$/p' "$from" > "$tmp"
    if ! grep -q '^-----BEGIN CERTIFICATE-----$' "$tmp" || ! openssl x509 -in "$tmp" -noout 2> /dev/null; then
        rm -f "$tmp"
        ec_die "Cloudflare の Origin CA の元の証明書 $from に、証明書が入っていません" "取り出したリポジトリの deploy/cloudflare/origin-ca-roots.crt を元に戻す（git checkout -- deploy/cloudflare）"
    fi
    ec_tunnel_put "$EC_TUNNEL_CA_NAME" "$tmp" || ec_die "Cloudflare の Origin CA の元の証明書を $EC_TUNNEL_DIR に置けません" "空き容量と権限を確認して再実行する"
}
# 答えの確かめ（install.sh の 2/5 から。何も変えない）: 証明書は DNS の確かめか手で用意したもの（外から 80 番に届かないので自動では取れない）、
# 待ち受けるアドレスは 127.0.0.1 だけ（外から 443 番に直に届かせない）、トークンのファイルが読めて形が正しい（元のファイルを消した後は、前に写したもの）
ec_tunnel_check_answers() {
    local bind f rc=0
    if [ "$(ec_tls_mode)" = auto ]; then
        ec_die "Cloudflare Tunnel の構成（FRONT=tunnel）では、外から 80 番に届かないので、証明書を自動で取れません（何も入れていません）" \
            "Cloudflare の Origin CA の証明書（勧める。DNS の鍵をサーバーに置かずに済む）を作り、TLS_CERT・TLS_KEY に書く（docs/install.md の「Cloudflare の Origin CA の証明書」）" \
            "DNS で確認して取るなら、Cloudflare の DNS の API トークンを CLOUDFLARE_TOKEN_FILE に書く"
    fi
    bind="$(ec_answer BIND 2> /dev/null || true)"
    if [ "$bind" != 127.0.0.1 ]; then
        ec_die "Cloudflare Tunnel の構成では、待ち受けるアドレス（BIND）は 127.0.0.1 だけです（設定ファイルは ${bind:-空}。外から 443 番に直に届かせない。何も入れていません）" \
            "$EC_ANSWERS の BIND の行を消すか、BIND=127.0.0.1 にして再実行する"
    fi
    # トンネルの見張りの読むだけの鍵（任意。無ければ見張りは「見ていない」）
    f="$(ec_answer TUNNEL_WATCH_TOKEN_FILE 2> /dev/null || true)"
    if [ -n "$f" ]; then
        if [ -e "$f" ]; then
            ec_tunnel_watch_key_read "$f" || rc=$?
            EC_TUNNEL_WATCH_KEY=""
            case "$rc" in
                0) ;;
                2) ec_die "トンネルの監視のトークンのファイル $f が読めません（何も入れていません）" "root で読めるファイルにするか、$EC_ANSWERS の TUNNEL_WATCH_TOKEN_FILE を直す" ;;
                *) ec_die "トンネルの監視のトークンの形が違います（Cloudflare の API トークンの形でない。中身は出しません。何も入れていません）" \
                    "Cloudflare の画面で作った読み取り専用の API トークンだけを、引用符や括弧を付けずに 1 行で書いたファイルを渡す（docs/install.md の「トンネルの監視」）" ;;
            esac
            rc=0
        elif [ ! -s "$EC_TUNNEL_WATCH_DIR/token" ]; then
            ec_die "トンネルの監視のトークンのファイル $f がありません（前にコピーしたものもありません。何も入れていません）" \
                "鍵のファイルを置き直すか、監視しないなら $EC_ANSWERS の TUNNEL_WATCH_TOKEN_FILE の行を消す"
        fi
    fi
    f="$(ec_answer TUNNEL_TOKEN_FILE 2> /dev/null || true)"
    if [ -z "$f" ] || [ ! -e "$f" ]; then
        [ -s "$EC_TUNNEL_DIR/token" ] && return 0
        ec_die "Cloudflare Tunnel の構成には、トンネルのトークンのファイル（設定ファイルの TUNNEL_TOKEN_FILE）が要ります${f:+（$f がありません）}（何も入れていません）" \
            "Cloudflare でトンネルを作り、トークンを 1 行で書いたファイル（root だけ読める 0600）を置いて、そのパスを $EC_ANSWERS の TUNNEL_TOKEN_FILE に書く（docs/install.md の「Cloudflare Tunnel で出す形」）"
    fi
    ec_tunnel_token_id "$f" > /dev/null || rc=$?
    case "$rc" in
        0) return 0 ;;
        2) ec_die "トンネルのトークンのファイル $f が読めません（何も入れていません）" "root で読めるファイルにするか、$EC_ANSWERS の TUNNEL_TOKEN_FILE を直す" ;;
        *) ec_die "トンネルのトークンの形が違います（Cloudflare のトンネルのトークンでない。中身は出しません。何も入れていません）" \
            "Cloudflare で出したトークン（eyJ で始まる 1 行）だけを、引用符やコマンド（cloudflared service install など）を付けずに書いたファイルを渡す" ;;
    esac
}
# cloudflared の様子（status の 1 行）。「状態 健全さ」を出す（例 running healthy）。コンテナが無ければ何も出さない
ec_tunnel_state() {
    ec_dc ps -a --format '{{.State}} {{.Health}}' cloudflared 2> /dev/null | head -n1 || true
}
# Cloudflare Tunnel の形の確かめ（ec-sugar check-front・5/5・更新の後。ADR 0052）。管理画面のホストで:
#   1. サーバーの中から入口の Caddy（待ち受けるアドレス 127.0.0.1）へ、偽の CF-Connecting-IP・X-Forwarded-For・True-Client-IP を付けて送り、
#      EC-SUGAR に届いた接続元が偽の住所でない（Caddy は cloudflared の住所から来た接続のときだけ CF-Connecting-IP を信じる）
#   2. 公開の DNS（DNS over HTTPS）で名前を引いて外（Cloudflare）を回り、https://<管理画面>/up が 200 で、偽の X-Forwarded-For を付けても
#      届いた接続元が偽の住所でも cloudflared の住所でもない（Cloudflare が付けた本当の接続元が届く）
# 返り: 0 通った、1 偽れる・本当の接続元が届かない（直すことがある）、2 まだ確かめられない（外から開けない。DNS・トンネルがまだ）。結果を state/front_check に残す
ec_check_tunnel() {
    local admin fake=203.0.113.250 tag code logs line addr rc=0 probe port tunnel_addr doh result
    admin="$(ec_env_get EC_SUGAR_ADMIN_HOST)"
    probe="$(ec_probe_ip)"
    port="$(ec_env_get EC_SUGAR_HTTPS_PORT 2> /dev/null || true)"
    port="${port:-443}"
    tunnel_addr="$(ec_env_get EC_SUGAR_TUNNEL_ADDR 2> /dev/null || true)"
    tunnel_addr="${tunnel_addr:-172.30.255.242}"
    doh="${EC_DOH_URL:-https://1.1.1.1/dns-query}"
    # 1. 中から偽の見出しを付けて
    tag="ec-sugar-tunnel-check-$(ec_random_alnum 12)"
    code="$(ec_code_norm "$(curl -sS -k -o /dev/null -w '%{http_code}' --max-time 20 -H "CF-Connecting-IP: $fake" -H "X-Forwarded-For: $fake" -H "True-Client-IP: $fake" \
        --resolve "$admin:$port:$probe" "https://$admin:$port/up?$tag" 2> /dev/null || true)")"
    sleep 1
    logs="$(ec_dc logs --no-log-prefix --since 10m web 2> /dev/null || true)"
    line="$(printf '%s\n' "$logs" | grep -F "$tag" | tail -n1 || true)"
    addr="${line%% *}"
    if [ -z "$code" ] || [ "$code" = 000 ] || [ -z "$line" ]; then
        ec_warn "$admin: サーバーの中からリバースプロキシ（${probe}:${port}）に届きません（sudo ec-sugar logs caddy web で理由を見る）"
        rc=2
    elif [ "$addr" = "$fake" ]; then
        ec_warn "$admin: サーバーの中から送った偽の CF-Connecting-IP・X-Forwarded-For を、EC-SUGAR が接続元として受けました（管理画面の接続元の制限を偽れる。Caddy の設定 front-tunnel.servers と EC_SUGAR_TUNNEL_ADDR を確認する）"
        rc=1
    else
        ec_ok "$admin: サーバーの中から偽の CF-Connecting-IP を付けても、接続元は偽れない（届いたのは ${addr}）"
    fi
    # 2. 外を回って
    tag="ec-sugar-tunnel-check-$(ec_random_alnum 12)"
    code="$(ec_code_norm "$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 --doh-url "$doh" -H "X-Forwarded-For: $fake" "https://$admin/up?$tag" 2> /dev/null || true)")"
    if [ "$code" != 200 ]; then
        ec_warn "$admin: 外（Cloudflare）を回って https://$admin/up を開けません（応答 ${code:-000}。Cloudflare の DNS の CNAME と、トンネルが接続しているか〔sudo ec-sugar logs cloudflared〕を見る）"
        [ "$rc" -eq 1 ] || rc=2
    else
        sleep 1
        logs="$(ec_dc logs --no-log-prefix --since 10m web 2> /dev/null || true)"
        line="$(printf '%s\n' "$logs" | grep -F "$tag" | tail -n1 || true)"
        addr="${line%% *}"
        if [ -z "$line" ]; then
            ec_warn "$admin: 外を回った確認の要求が EC-SUGAR に届いていません（ほかのサーバーへ向いている見込み。Cloudflare の DNS の CNAME の値を見る）"
            [ "$rc" -eq 1 ] || rc=2
        elif [ "$addr" = "$fake" ]; then
            ec_warn "$admin: 外から付けた偽の X-Forwarded-For を、EC-SUGAR が接続元として受けました（管理画面の接続元の制限を偽れる）"
            rc=1
        elif [ "$addr" = "$tunnel_addr" ] || { ! ec_valid_ipv4 "$addr" && [[ "$addr" != *:* ]]; }; then
            ec_warn "$admin: 外を回った要求の接続元が ${addr:-空} で、本当の接続元（Cloudflare の CF-Connecting-IP）が届いていません（管理画面の接続元の制限と回数の制限が効かない）"
            rc=1
        else
            ec_ok "$admin: 外（Cloudflare）を回って https で開け、本当の接続元（${addr}）が届く"
        fi
    fi
    case "$rc" in
        0) result=ok ;;
        1) result=ng ;;
        *) result='wait' ;;
    esac
    ec_state_put front_check "$result $(date -Is)"
    return "$rc"
}

# ---------- トンネルの見張り（漏れたトークンに気づく。見直しの中 5、ADR 0052） ----------
# トークンを持つ者は、どこからでも同じトンネルに接続役（connector）を足せ、Cloudflare は要求を接続役の間で振り分ける
# （お客さんの要求の一部が平文で偽の接続役へ）。ec-sugar watch（10 分ごと）が、読むだけの Cloudflare の API の鍵
# （権限は「アカウント › Cloudflare Tunnel › 読み取り」だけ）でトンネルの接続役の一覧を引き、つなぎ元の住所がこのサーバー自身の
# 外向けの住所（IPv4 はそのまま、IPv6 は頭の /64）のどれとも合わない接続役があれば「よそからつながっている」とする。
# 決まり: 鍵は bash の中だけで読み、curl へは設定を標準入力で渡す（--config -。引数・環境変数に出さない）。API の答えと住所は画面・記録・
# 運営の様子へ出さず、決まった語だけを state/tunnel_watch に「語 時刻」で残す（ec_report_state_args が運営の様子へ渡す）

# $1 鍵のファイル。中身の書いてある行がちょうど 1 つで、Cloudflare の API の鍵の形なら EC_TUNNEL_WATCH_KEY に入れる（呼ぶ側は使ったらすぐ空にする）。
# 返り: 0 読めた、1 形が違う、2 読めない（無い・読む権限が無い）
EC_TUNNEL_WATCH_KEY=""
ec_tunnel_watch_key_read() {
    local f="$1" line size n=0
    EC_TUNNEL_WATCH_KEY=""
    [ -f "$f" ] && [ -r "$f" ] || return 2
    size="$(wc -c < "$f" 2> /dev/null | tr -d ' ')"
    [[ "$size" =~ ^[0-9]+$ ]] && [ "$size" -le 4096 ] || return 1
    while IFS= read -r line || [ -n "$line" ]; do
        # 前後の空白（CR も）だけを落とす。中の空白は落とさない（「Bearer <鍵>」を 1 つの鍵と取り違えない）
        line="${line#"${line%%[![:space:]]*}"}"
        line="${line%"${line##*[![:space:]]}"}"
        [ -n "$line" ] || continue
        n=$((n + 1))
        [ "$n" -eq 1 ] && EC_TUNNEL_WATCH_KEY="$line"
    done < "$f"
    line=""
    # 形は英数字と _ - だけ（curl の設定の引用符の中に入れるので、引用符・バックスラッシュ・改行が入らないことを、ここで決める）
    if [ "$n" -ne 1 ] || ! ec_valid_cf_token "$EC_TUNNEL_WATCH_KEY"; then
        EC_TUNNEL_WATCH_KEY=""
        return 1
    fi
    return 0
}

# 読むだけの鍵を置き場へ写す（インストーラーから。root だけの 0700 の置き場・0400 のファイル）。$1 元のファイル（答えの TUNNEL_WATCH_TOKEN_FILE）。
# 無ければ前に写したものを使い、それも無ければ注意だけ（見張りは「見ていない」。止めない）。形は答えの確かめ（ec_tunnel_check_answers）でも見る
ec_tunnel_watch_put_key() {
    local from="$1" tmp old_umask
    install -d -m 0700 -o root -g root "$EC_TUNNEL_WATCH_DIR"
    chown root:root "$EC_TUNNEL_WATCH_DIR"
    chmod 0700 "$EC_TUNNEL_WATCH_DIR"
    if [ -z "$from" ] || [ ! -e "$from" ]; then
        if [ -s "$EC_TUNNEL_WATCH_DIR/token" ]; then
            ec_ok "トンネルの監視のトークンは前にコピーしたもの（$EC_TUNNEL_WATCH_DIR/token）を使う"
        else
            ec_warn "トンネルの監視のトークン（設定ファイルの TUNNEL_WATCH_TOKEN_FILE）が無いので、トークンが漏れて、よそからコネクタを足されても気づけません（docs/install.md の「トンネルの監視」）"
        fi
        return 0
    fi
    ec_tunnel_watch_key_read "$from" || ec_die "トンネルの監視のトークンの形が違うか、読めません（中身は出しません）" "鍵のファイルを置き直して再実行する"
    old_umask="$(umask)"
    umask 077
    tmp="$(mktemp "$EC_TUNNEL_WATCH_DIR/.token.XXXXXX")"
    printf '%s\n' "$EC_TUNNEL_WATCH_KEY" > "$tmp"
    EC_TUNNEL_WATCH_KEY=""
    umask "$old_umask"
    if cmp -s "$tmp" "$EC_TUNNEL_WATCH_DIR/token" 2> /dev/null; then
        rm -f "$tmp"
    elif ! { chown root:root "$tmp" && chmod 0400 "$tmp" && mv -f "$tmp" "$EC_TUNNEL_WATCH_DIR/token"; }; then
        rm -f "$tmp"
        ec_die "トンネルの監視のトークンを $EC_TUNNEL_WATCH_DIR に置けません" "空き容量と権限を確認して再実行する"
    fi
    ec_ok "トンネルの監視のトークンは $EC_TUNNEL_WATCH_DIR の中だけ（root だけが読める 0400。cloudflared には見えない。元のファイル $from は消してよい）"
}

# $1 IPv6 の住所。頭の 64 ビット（4 つの組を 4 桁の小文字の 16 進にして : でつないだもの）を出す。形が違えば何も出さず 1
# （IPv4 を埋めた形〔::ffff:1.2.3.4〕・範囲の長さつき・:: が 2 つ・組が 4 桁を超える・組が多すぎるものは断る）
ec_ipv6_prefix64() {
    local a head tail="" part colons n=0 fill=0 list g i=0 out="" IFS
    a="$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')"
    [[ "$a" =~ ^[0-9a-f:]{2,39}$ ]] || return 1
    head="$a"
    if [[ "$a" == *::* ]]; then
        head="${a%%::*}"
        tail="${a#*::}"
        [[ "$tail" != *::* ]] || return 1
        fill=1
    fi
    for part in "$head" "$tail"; do
        [ -n "$part" ] || continue
        [[ "$part" =~ ^[0-9a-f]{1,4}(:[0-9a-f]{1,4})*$ ]] || return 1
        colons="${part//[^:]/}"
        n=$((n + ${#colons} + 1))
    done
    if [ "$fill" -eq 1 ]; then [ "$n" -le 7 ] || return 1; else [ "$n" -eq 8 ] || return 1; fi
    list="$head"
    if [ "$fill" -eq 1 ]; then
        while [ "$n" -lt 8 ]; do
            list="${list:+$list:}0"
            n=$((n + 1))
        done
        [ -z "$tail" ] || list="$list:$tail"
    fi
    IFS=:
    for g in $list; do
        [ "$i" -lt 4 ] || break
        g="000$g"
        out="${out:+$out:}${g:${#g}-4}"
        i=$((i + 1))
    done
    printf '%s' "$out"
}

# このサーバーを外から見た住所を 1 行ずつ出す（IPv4 はそのまま、IPv6 は「v6 頭の /64」）。どちらも分からなければ何も出さない
ec_tunnel_watch_own() {
    local ip p
    ip="$(curl -4 -sS --max-time 10 --max-filesize 4096 "$EC_TRACE_URL4" 2> /dev/null | sed -n 's/^ip=//p' | head -n1 || true)"
    if ec_valid_ipv4 "$ip"; then printf '%s\n' "$ip"; fi
    ip="$(curl -6 -sS --max-time 10 --max-filesize 4096 "$EC_TRACE_URL6" 2> /dev/null | sed -n 's/^ip=//p' | head -n1 || true)"
    if [[ "$ip" == *:* ]] && p="$(ec_ipv6_prefix64 "$ip")"; then printf 'v6 %s\n' "$p"; fi
    return 0
}

# $1 アカウントの ID $2 トンネルの ID。今つながっている接続役のつなぎ元の住所を 1 行ずつ出す（文字でないつなぎ元は「?」の行）。
# 接続役が 0 なら何も出さず 0。API に届かない・答えが読めない・鍵が読めなければ 1
ec_tunnel_watch_origins() {
    local account="$1" tunnel="$2" body="" rest n=0
    [[ "$account" =~ ^[0-9a-f]{32}$ ]] && ec_valid_uuid "$tunnel" || return 1
    ec_tunnel_watch_key_read "$EC_TUNNEL_WATCH_DIR/token" || { EC_TUNNEL_WATCH_KEY=""; return 1; }
    # 鍵は設定として標準入力で（printf は bash の中の命令なので、ps に出ない）。答えは 1 MB まで・4xx と 5xx は失敗
    body="$(printf 'url = "%s/accounts/%s/cfd_tunnel/%s/connections"\nheader = "Authorization: Bearer %s"\n' "$EC_CF_API" "$account" "$tunnel" "$EC_TUNNEL_WATCH_KEY" |
        curl -sS --fail --max-time 20 --max-filesize 1048576 --config - 2> /dev/null)" || body=""
    EC_TUNNEL_WATCH_KEY=""
    [[ "$body" =~ \"success\"[[:space:]]*:[[:space:]]*true ]] && [[ "$body" =~ \"result\"[[:space:]]*:[[:space:]]*\[ ]] || return 1
    # 接続役（result[]）ごとの接続（conns[]）のつなぎ元（origin_ip）を、出てきた順に全部。中身の形は決め打ちせず、キーの数だけ拾う
    rest="$body"
    body=""
    while [[ "$rest" =~ \"origin_ip\"[[:space:]]*:[[:space:]]*(\"([^\"]{0,64})\")? ]]; do
        n=$((n + 1))
        [ "$n" -le 1000 ] || return 1
        if [ -n "${BASH_REMATCH[1]}" ] && [ -n "${BASH_REMATCH[2]}" ]; then printf '%s\n' "${BASH_REMATCH[2]}"; else printf '?\n'; fi
        rest="${rest#*"${BASH_REMATCH[0]}"}"
    done
    return 0
}

# 見張りの結果の語を出す（どれも 0 で返る）:
#   ok        どの接続役もこのサーバーから
#   foreign   このサーバーの住所と合わない接続役がある（よそからつながっている。トークンが漏れたかもしれない）
#   down      接続役が 0（トンネルが落ちている。少し待って聞き直しても 0 のとき）
#   unknown   API に届かない・答えが読めない・このサーバーの住所が分からない・トークンが読めない
#   unwatched 読むだけの鍵が置いていない（見ていない）
ec_tunnel_watch_check() {
    local ids account tunnel origins own ip p foreign=0
    if [ ! -e "$EC_TUNNEL_WATCH_DIR/token" ]; then
        printf 'unwatched'
        return 0
    fi
    ids="$(ec_tunnel_token_ids "$EC_TUNNEL_DIR/token" 2> /dev/null)" || { printf 'unknown'; return 0; }
    account="${ids%% *}"
    tunnel="${ids#* }"
    origins="$(ec_tunnel_watch_origins "$account" "$tunnel")" || { printf 'unknown'; return 0; }
    if [ -z "$origins" ]; then
        sleep "$EC_TUNNEL_WATCH_RETRY_WAIT"
        origins="$(ec_tunnel_watch_origins "$account" "$tunnel")" || { printf 'unknown'; return 0; }
        if [ -z "$origins" ]; then
            printf 'down'
            return 0
        fi
    fi
    own="$(ec_tunnel_watch_own)"
    if [ -z "$own" ]; then
        printf 'unknown'
        return 0
    fi
    while IFS= read -r ip; do
        [ -n "$ip" ] || continue
        if ec_valid_ipv4 "$ip"; then
            printf '%s\n' "$own" | ec_q -xF -- "$ip" || foreign=1
        elif [[ "$ip" == *:* ]] && p="$(ec_ipv6_prefix64 "$ip")"; then
            printf '%s\n' "$own" | ec_q -xF -- "v6 $p" || foreign=1
        else
            # 住所の形でないつなぎ元は、このサーバーからと確かめられないので、よそからとみなす
            foreign=1
        fi
    done <<< "$origins"
    if [ "$foreign" -eq 1 ]; then printf 'foreign'; else printf 'ok'; fi
}

# 今の時刻（date -Is の形。GNU の date が無い手元の Mac では UTC の Z の形）
ec_now() { date -Is 2> /dev/null || date -u +%Y-%m-%dT%H:%M:%SZ; }

# 見張りの 1 回（ec_watch から）。Tunnel の形なら確かめて state/tunnel_watch に「語 時刻」を残し、困ったときは注意を 1 行（住所は出さない）。
# ほかの形では前の結果を消す（運営の様子へ古い結果を渡さない）
ec_tunnel_watch() {
    local result
    if [ "$(ec_front)" != tunnel ]; then
        rm -f "$EC_STATE/tunnel_watch"
        return 0
    fi
    result="$(ec_tunnel_watch_check)"
    case "$result" in ok | foreign | down | unknown | unwatched) ;; *) result=unknown ;; esac
    ec_state_put tunnel_watch "$result $(ec_now)"
    case "$result" in
        foreign) ec_warn "Cloudflare Tunnel に、このサーバーでない所からのコネクタが接続しています（トークンが漏れたかもしれません。docs/install.md の「トークンが漏れた時」）" ;;
        down) ec_warn "Cloudflare Tunnel にコネクタが 1 つも接続していません（sudo ec-sugar status・sudo ec-sugar logs cloudflared）" ;;
        unknown) ec_warn "Cloudflare Tunnel のコネクタを確認できませんでした（Cloudflare の API に届かないか、このサーバーのグローバル IP アドレスが分からない。読み取り専用の API トークンの権限も見る）" ;;
    esac
    return 0
}

# ---------- バックアップ（DB と写真などのファイル） ----------
# 中央と全部の店の DB を pg_dump の独自形式で、写真などのファイル（storage の下）を tar で。1 回分を 1 つの置き場（日時の名前）にまとめ、
# DB は読めるかを pg_restore --list で、ファイルは tar の目次で確かめ、指紋（SHA256SUMS）を残す。
# 終わった印（.complete）が無い置き場は途中で切れたものとして扱う。7 日より古いものは消す（いちばん新しいものは残す）
# $1 理由 $2 ファイルも入れるか（1 既定・0 は DB だけ。更新の前のバックアップは DB だけ〔更新でファイルは変わらない〕）
ec_backup() {
    local reason="${1:-手で}" files="${2:-1}" dir prefix old_umask started kb
    started=$SECONDS
    prefix="$(ec_env_get TENANT_DB_PREFIX || true)"
    [[ "$prefix" =~ ^[a-z0-9_]+$ ]] || ec_die "env の TENANT_DB_PREFIX が読めません"
    if ! ec_dc ps --status running --format '{{.Service}}' db | ec_q -x db; then
        ec_warn "DB が動いていないのでバックアップを取れません（sudo systemctl start ec-sugar で起動してから再実行する）"
        ec_record_backup failed
        return 1
    fi
    install -d -m 0700 -o root -g root "$EC_BACKUP"
    # 名前は日時（秒まで）。同じ秒に 2 回取ると名前がぶつかるので、ぶつかったら 1 秒待って取り直す
    for _ in 1 2 3; do
        dir="$EC_BACKUP/$(date +%Y%m%d-%H%M%S)"
        mkdir -m 0700 "$dir" 2> /dev/null && break
        dir=""
        sleep 1
    done
    [ -n "$dir" ] || { ec_warn "バックアップの保存先を $EC_BACKUP の下に作れません"; ec_record_backup failed; return 1; }
    old_umask="$(umask)"
    umask 077
    if ! ec_backup_into "$dir" "$prefix" "$reason" "$files"; then
        umask "$old_umask"
        # 半端な控えを残さない
        rm -rf "$dir"
        ec_warn "バックアップを取れませんでした（半端なバックアップは消しました）"
        ec_record_backup failed
        return 1
    fi
    umask "$old_umask"
    ec_record_backup ok
    # 今取ったバックアップ（戻す前のバックアップの名前に使う。いちばん新しい名前とは限らない: 別のマシンから写した名前が先の日時のことがある）
    EC_BACKUP_LAST="$dir"
    kb="$(du -sk "$dir" | cut -f1)"
    ec_ok "バックアップを取った: ${dir}（DB $(find "$dir" -name '*.dump' | wc -l | tr -d ' ') 個$([ -e "$dir/$EC_BACKUP_FILES_NAME" ] && printf '・写真などのファイル %s MB' "$(($(du -sk "$dir/$EC_BACKUP_FILES_NAME" | cut -f1) / 1024))" || printf '・ファイルは入れない（DB だけ）')・合計 $((kb / 1024)) MB・$((SECONDS - started)) 秒）"
    ec_prune_backups
}
# 控えの中身。どこかで失敗したら 1 を返す（呼ぶ側が if で受けるので set -e は効かない。1 つずつ確かめる）
ec_backup_into() {
    local dir="$1" prefix="$2" reason="$3" files="$4" dbs db commit shops=0
    ec_backup_space_ok "$prefix" "$files" || return 1
    dbs="$(ec_dc exec -T db psql -U ec -d ec_central -Atc \
        "SELECT datname FROM pg_database WHERE datname = 'ec_central' OR left(datname, length('$prefix')) = '$prefix' ORDER BY datname")" || return 1
    [ -n "$dbs" ] || { ec_warn "DB の一覧が空です"; return 1; }
    for db in $dbs; do
        [[ "$db" =~ ^[a-z0-9_]+$ ]] || { ec_warn "DB の名前が想定の外です（${db}）"; return 1; }
        ec_dc exec -T db pg_dump -U ec -Fc -d "$db" > "$dir/$db.dump" || { ec_warn "$db のバックアップに失敗しました"; return 1; }
        # 読み戻せるか（目次を読めるか）を確かめる
        ec_dc exec -T db pg_restore --list < "$dir/$db.dump" > /dev/null || { ec_warn "$db のバックアップが読めません"; return 1; }
        [ "$db" = ec_central ] || shops=$((shops + 1))
    done
    # ロール（店ごとの DB の持ち主。合言葉は scram の形で入る）。戻すときは店のロールの行だけを選んで流す（ec_restore_roles）
    ec_dc exec -T db pg_dumpall -U ec --globals-only > "$dir/globals.sql" || return 1
    if [ "$files" = 1 ]; then
        ec_app_sh "$EC_FILES_PACK" > "$dir/$EC_BACKUP_FILES_NAME" || { ec_warn "写真などのファイルのバックアップに失敗しました"; return 1; }
        tar -tf "$dir/$EC_BACKUP_FILES_NAME" > /dev/null || { ec_warn "写真などのファイルのバックアップが読めません"; return 1; }
    fi
    # 指紋（壊れた・すり替わったバックアップを、戻す前に見つける。書き換えられないバックアップは ）
    (cd "$dir" && ec_sha256 -- *.dump globals.sql $([ "$files" = 1 ] && printf '%s' "$EC_BACKUP_FILES_NAME")) > "$dir/SHA256SUMS" || { ec_warn "指紋（SHA256SUMS）を作れません"; return 1; }
    commit="$(ec_state_get current_commit || true)"
    {
        printf 'time=%s\n' "$(ec_now)"
        printf 'reason=%s\n' "$reason"
        printf 'commit=%s\n' "$commit"
        printf 'databases=%s\n' "${dbs//$'\n'/ }"
        printf 'prefix=%s\n' "$prefix"
        printf 'shops=%s\n' "$shops"
        printf 'files=%s\n' "$([ "$files" = 1 ] && printf '%s' "$EC_BACKUP_FILES_NAME" || printf none)"
        printf 'admin_host=%s\n' "$(ec_env_get EC_SUGAR_ADMIN_HOST 2> /dev/null || true)"
    } > "$dir/manifest" || return 1
    chmod 0600 "$dir"/* || return 1
    : > "$dir/.complete"
}
# 写真などのファイル（storage の下。画面の下書きと控え〔framework〕・記録〔logs〕・戻す途中の置き場は除く）を 1 つの tar にして出す、
# app のコンテナの中の sh のスクリプト（決まった文。利用者の入力は入らない）。中身が無ければ空の app を入れる（tar は空の束を作らない）
EC_FILES_PACK='cd /var/www/html/storage && set -- $(find . -mindepth 1 -maxdepth 1 ! -name framework ! -name logs ! -name .restore-new | sort) && if [ $# -eq 0 ]; then mkdir -p app && set -- ./app; fi && exec tar -cf - "$@"'
# 戻す: 標準入力の tar を一度横（.restore-new）に広げ、広げられたときだけ今のファイルと入れ替える（壊れた tar で今のファイルを消さない）
EC_FILES_UNPACK='cd /var/www/html/storage && rm -rf .restore-new && mkdir .restore-new && tar -xf - -C .restore-new && find . -mindepth 1 -maxdepth 1 ! -name framework ! -name logs ! -name .restore-new -exec rm -rf {} \; && for e in .restore-new/* .restore-new/.[!.]*; do if [ -e "$e" ]; then mv "$e" . || exit 1; fi; done && rmdir .restore-new'
# app のコンテナで決まった sh のスクリプトを流す（動いていれば中で、止まっていれば同じイメージ・同じ置き場で使い捨てを起こして）
ec_app_sh() {
    if ec_dc ps --status running --format '{{.Service}}' app 2> /dev/null | ec_q -x app; then
        ec_dc exec -T app sh -c "$1"
    else
        ec_dc run --rm --no-deps -T app sh -c "$1" 2> >(ec_quiet_run >&2)
    fi
}
# 使い捨ての入れ物を起こす時の「Container … Created」の行を除く（誤りの行はそのまま）
ec_quiet_run() { grep -v -E '^ *Container [^ ]+ (Creating|Created|Starting|Started|Stopping|Stopped|Removing|Removed) *$' || true; }
# 指紋（sha256sum。無い手元の Mac では shasum）
ec_sha256() {
    if command -v sha256sum > /dev/null 2>&1; then sha256sum "$@"; else shasum -a 256 "$@"; fi
}
# 空き容量の確かめ。DB の大きさとファイルの大きさの 1.1 倍と 64 MB が、バックアップの置き場の空きに収まるか（dump は縮むので多めの見積もり）
ec_backup_space_ok() {
    local prefix="$1" files="$2" db_kb files_kb=0 free_kb need_kb
    db_kb="$(ec_dc exec -T db psql -U ec -d ec_central -Atc \
        "SELECT (coalesce(sum(pg_database_size(datname)), 0) / 1024)::bigint FROM pg_database WHERE datname = 'ec_central' OR left(datname, length('$prefix')) = '$prefix'")" || return 1
    if [ "$files" = 1 ]; then
        files_kb="$(ec_app_sh 'du -sk /var/www/html/storage | cut -f1')" || { ec_warn "写真などのファイルの大きさを測れません"; return 1; }
    fi
    free_kb="$(df -Pk "$EC_BACKUP" 2> /dev/null | awk 'NR == 2 {print $4}')"
    if ! [[ "$db_kb" =~ ^[0-9]+$ ]] || ! [[ "$files_kb" =~ ^[0-9]+$ ]] || ! [[ "$free_kb" =~ ^[0-9]+$ ]]; then
        ec_warn "空き容量を測れません（DB ${db_kb} KB・ファイル ${files_kb} KB・空き ${free_kb} KB）"
        return 1
    fi
    need_kb=$(((db_kb + files_kb) * 11 / 10 + 65536))
    if [ "$free_kb" -lt "$need_kb" ]; then
        ec_warn "バックアップの保存先の空きが足りません（要るのは約 $((need_kb / 1024)) MB、空きは $((free_kb / 1024)) MB。df -h ${EC_BACKUP} で見て、古いバックアップを外へ移すか消す）"
        return 1
    fi
}
ec_prune_backups() {
    local newest d
    newest="$(ec_latest_backup || true)"
    while IFS= read -r d; do
        [ -n "$d" ] && [ "$d" != "$newest" ] && [ "$d" != "${EC_BACKUP_PROTECT:-}" ] && rm -rf "$d"
    done < <(find "$EC_BACKUP" -mindepth 1 -maxdepth 1 -type d -mtime +"$((EC_BACKUP_KEEP_DAYS - 1))" 2> /dev/null)
    # 途中で切れた控え（印の無いもの）で 1 日より古いもの
    while IFS= read -r d; do
        [ -n "$d" ] && [ ! -e "$d/.complete" ] && rm -rf "$d"
    done < <(find "$EC_BACKUP" -mindepth 1 -maxdepth 1 -type d -mtime +0 2> /dev/null)
    ec_prune_backup_files
    # 上の繰り返しの最後の「消さない」（偽）を、この関数の失敗として返さない
    return 0
}
# 写真などのファイルの tar は、tar を持つ世代の新しい方から EC_BACKUP_FILES_KEEP だけ残し、古い世代からは tar だけを外す（DB の dump・ロールは残す）。
# 戻すのに使っているバックアップ（EC_BACKUP_PROTECT）の tar は外さない。外した世代は指紋と manifest も直し、戻すときは DB だけになる
ec_prune_backup_files() {
    local d list="" count=0 drop
    for d in "$EC_BACKUP"/*/; do
        d="${d%/}"
        if [ -e "$d/.complete" ] && [ -e "$d/$EC_BACKUP_FILES_NAME" ]; then
            list="$list$d"$'\n'
            count=$((count + 1))
        fi
    done
    drop=$((count - EC_BACKUP_FILES_KEEP))
    [ "$drop" -gt 0 ] || return 0
    while IFS= read -r d; do
        [ -n "$d" ] && [ "$d" != "${EC_BACKUP_PROTECT:-}" ] || continue
        ec_backup_drop_files "$d" || ec_warn "バックアップ $(basename "$d") の写真などのファイルを外せませんでした（sudo ls -l ${d} で見る）"
    done < <(printf '%s' "$list" | head -n "$drop")
    return 0
}
# 1 つの世代から tar を外す（指紋の行を外し、manifest の files を none に。先に指紋と manifest を直してから tar を消す）
ec_backup_drop_files() {
    local d="$1" old_umask rc=0
    old_umask="$(umask)"
    umask 077
    if [ -e "$d/SHA256SUMS" ]; then
        { grep -v "  ${EC_BACKUP_FILES_NAME}\$" "$d/SHA256SUMS" > "$d/.SHA256SUMS.tmp" && mv -f "$d/.SHA256SUMS.tmp" "$d/SHA256SUMS"; } || rc=1
    fi
    [ "$rc" -eq 0 ] && ec_kv_set "$d/manifest" files none && rm -f "$d/$EC_BACKUP_FILES_NAME" || rc=1
    umask "$old_umask"
    return "$rc"
}
ec_latest_backup() {
    local d latest=""
    for d in "$EC_BACKUP"/*/; do
        [ -e "$d.complete" ] && latest="${d%/}"
    done
    [ -n "$latest" ] && printf '%s' "$latest"
}

# ---------- サーバーの外のバックアップ（S3 互換の置き場へ送る・取る。ADR 0110） ----------
# 毎朝と手で取ったバックアップを、公開鍵で暗号化して S3 互換の置き場（Cloudflare R2 など）へ送る。置き場の側で一定の期間、削除も上書きも
# できない形にしておく（置き場の側の決まり。R2 ではバケットのロック）。送るのはホストの root で、道具は入っている curl（--aws-sigv4。署名は
# curl に任せる）と gpg だけ。新しいイメージも PHP の部品も足さず、鍵はどのコンテナにも渡さない。
# 流れ:
#   ec-sugar backup（運用の錠の中）   取れたら、送る分の控えを $EC_OFFSITE_WORK/stage/<名前>/ にハードリンクで作る（ec_offsite_stage）
#   ec-sugar-offsite.timer（10 分ごと） ec-sugar offsite run（運用の錠は取らない。自分の錠だけ）が、控えを古い順に送って消す（ec_offsite_run）
#   ec-sugar offsite pull             戻す先の機械で、読む鍵と開ける鍵を渡して取り、バックアップの置き場に広げる（その先は ec-sugar restore）
# 名前（キー）:
#   db/<サーバー>/<年>/<月>/<バックアップの名前>.<送った時刻>/part-0001 … と INDEX     DB の dump・ロール・manifest・指紋を 1 本の tar に
#   files/<サーバー>/<年>/<月>/<バックアップの名前>.<送った時刻>/part-0001 … と INDEX  写真などのファイル（storage.tar）
#   heads/<サーバー>/<年>/<月>/<日>/<時刻>.tsv                                       店ごとの記録の連鎖の先頭（平文。アプリにコマンドがある時だけ）
#   <db・files・heads>/<サーバー>/_probe/<時刻>-<乱数>                               置き場のロックの確かめの印（前置きごとに 1 つ）
# 決まり:
#   - 置き場を設定していない（鍵のファイルが無い）サーバーでは何もしない（置き場を作らない・state を書かない・注意を出さない）
#   - 鍵は bash の中だけで読み、curl へは設定を標準入力で渡す（--config -。引数・環境変数・記録に出さない）
#   - 送る側が使うのは PUT だけ（ロックの確かめを入れた時だけ、印に上書きと削除を 1 回ずつ試して断られることを見る）。読む・一覧は取る側だけ
#   - env（APP_KEY・合言葉）は送らない。送るのはバックアップの置き場の決まった名前のファイルだけ
#   - 平文をディスクに増やさない（控えはハードリンク。切れ端は暗号化した後のものを 1 つずつ書いて、送って、消す）
#   - 作業用の置き場はバックアップの置き場の外（中に作ると ec_prune_backups が消す）
#   - INDEX（切れ端ごとの大きさと SHA-256）を最後に送る。INDEX の無い組は途中で切れたものとして数えない。やり直しは新しい名前で初めから
#   - 時刻として信じるのは置き場の側の時刻（名前の時刻・送った時刻は送る側が書ける）。取る側の「いちばん新しい」は置き場の側の時刻で選び、
#     時刻の食い違う組は選ばない。同じ名前の組が 2 つ以上あれば黙って選ばず、送った時刻を打たせる
#     （書く鍵を持つ人は、偽の組を本物より先にも後にも足せる。置き場の中身に本物の証しは無い。ADR 0110 の「残る危険」）
# 置き場の鍵と暗号の公開鍵（root だけの 0700、中は 0400。どのコンテナにも差し込まない）:
#   credentials    置き場の書く鍵（access_key_id=… と secret_access_key=… の 2 行。鍵の ID・秘密の鍵の順の 2 行でもよい）
#   recipient.asc  暗号の公開鍵（gpg。鍵束には取り込まない）。開ける鍵と読む鍵はこのサーバーに置かない
EC_OFFSITE_DIR=$EC_SECRETS/offsite
# 作業用の置き場（送る分の控え stage/ と、切れ端・取ったものの一時の置き場 tmp/）。バックアップの置き場と同じファイルシステム（ハードリンクが効く）
EC_OFFSITE_WORK=$EC_ROOT/offsite
# 送る回どうしの錠（運用の錠とは別。同じ root だけの置き場）
EC_OFFSITE_LOCK=/run/ec-sugar/offsite.lock
# 暗号化した流れを切る大きさ（1 GiB。1 回の PUT の上限に当たらない。分けて送る API は使わない）
EC_OFFSITE_PART_BYTES=1073741824
# 送れないまま溜めておく控えの数（超えたら古いものから外して知らせる。ディスクを埋めない）
EC_OFFSITE_STAGE_KEEP=3
# curl の下限。--aws-sigv4 の PUT が S3 互換の置き場に通ることを確かめた版（Ubuntu 24.04 の 8.5.0・26.04 の 8.18.0 は通る。22.04 の 7.81.0 は
# x-amz-content-sha256 を自分で足さず、渡すと署名が合わないので、どの要求も断られる。docker/check-offsite.sh）
EC_OFFSITE_CURL_MIN=8.5.0
# 置き場の入口に使える形（https だけ。確かめの台本だけが、手元の偽の置き場のために http を足す。環境変数では替えない）
EC_OFFSITE_SCHEMES=https
# 1 回の PUT・GET を待つ秒数の上限（細い回線で 1 GiB を送る）。遅すぎる（1 KB/秒を 2 分下回る）ときは先に切る
EC_OFFSITE_MAX_TIME=21600
# 取る側の上限: INDEX と一覧の答えの大きさ・1 つの切れ端の大きさ（置き場の 1 回の PUT の上限）
EC_OFFSITE_META_MAX=8388608
EC_OFFSITE_PART_MAX=5368709120
# 使う鍵のファイル（送る側は置き場の中の書く鍵。取る側は --credentials-file の読む鍵に替える）
EC_OFFSITE_CRED_FILE=""
# gpg の一時の homedir（鍵束を残さない。使う側が作って消す）と、取る側の合い言葉のファイル（任意）
EC_OFFSITE_GPG_HOME=""
EC_OFFSITE_PASSPHRASE_FILE=""
# 設定（ec_offsite_conf_load が答えから入れる）と、鍵（ec_offsite_cred_read が入れ、使ったらすぐ空にする）
EC_OFFSITE_ENDPOINT=""
EC_OFFSITE_BUCKET=""
EC_OFFSITE_PREFIX=""
EC_OFFSITE_REGION=""
EC_OFFSITE_ENCRYPT=""
EC_OFFSITE_LOCK_CHECK=""
EC_OFFSITE_ID=""
EC_OFFSITE_SECRET=""
EC_OFFSITE_HTTP=000
EC_OFFSITE_PUT_SHA=""
EC_OFFSITE_PROBLEM=""

# 置き場を設定しているか（書く鍵のファイルがあるか）。無ければ、送る側は何もしない
ec_offsite_configured() { [ -e "$EC_OFFSITE_DIR/credentials" ]; }

# 値の形。入口は「形://名前[:ポート]」だけ（道筋・問い合わせ・利用者の部分は書けない。鍵を送る先なので）
ec_offsite_valid_endpoint() {
    [[ "$1" =~ ^(https?)://[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?(:[0-9]{1,5})?$ ]] && [[ " $EC_OFFSITE_SCHEMES " == *" ${BASH_REMATCH[1]} "* ]]
}
ec_offsite_valid_bucket() { [[ "$1" =~ ^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$ ]] && [[ "$1" != *..* ]]; }
# <サーバー>（名前の 2 段目）。英数字と . _ - だけ（URL にそのまま書けて、/ で段を増やせない）
ec_offsite_valid_prefix() { [[ "$1" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$ ]] && [[ "$1" != *..* ]]; }
ec_offsite_valid_region() { [[ "$1" =~ ^[a-z0-9-]{1,32}$ ]]; }
# バックアップの名前（ec_backup が付ける日時）と、送った時刻（UTC）
ec_offsite_valid_name() { [[ "$1" =~ ^[0-9]{8}-[0-9]{6}$ ]]; }
ec_offsite_valid_stamp() { [[ "$1" =~ ^[0-9]{8}T[0-9]{6}Z$ ]]; }
ec_offsite_stamp() { date -u +%Y%m%dT%H%M%SZ; }
# gpg の決まった引数（設定を読まない・端末に聞かない・鍵を外へ取りに行かない）
EC_OFFSITE_GPG=(gpg --batch --no-options --no-tty --quiet --no-auto-key-retrieve --disable-dirmngr)
# 一覧の 1 回の数（置き場の上限は 1,000。確かめの台本は小さくして、続きを取る道を踏む）
EC_OFFSITE_LIST_MAX=1000
# 送れなかった控えのやり直し: 間は 10 分から倍々で 6 時間まで、1 つの控えにつき EC_OFFSITE_TRIES_MAX 回まで
# （途中まで置いた切れ端は消せないので、切れる回線で 10 分ごとに初めから送り直さない。1 日に多くて 4 回ほど）
EC_OFFSITE_RETRY_BASE=600
EC_OFFSITE_RETRY_MAX=21600
EC_OFFSITE_TRIES_MAX=8
# 取った組の、置き場の側の時刻・送った時刻・開けた manifest の値（成功の行に見せる）
EC_OFFSITE_PULLED_AT=""
EC_OFFSITE_PULLED_SENT=""

# $1 鍵のファイル。鍵の ID と秘密の鍵を EC_OFFSITE_ID・EC_OFFSITE_SECRET に入れる（呼ぶ側は使ったらすぐ空にする）。
# 形は英数字（秘密の鍵は / + = も）だけ（curl の設定の引用符の中に入れるので、引用符・バックスラッシュ・空白・改行が入らないことを、ここで決める）。
# 返り: 0 読めた、1 形が違う、2 読めない（無い・読む権限が無い）
ec_offsite_cred_read() {
    local f="$1" line size id="" secret="" n=0 first="" second=""
    EC_OFFSITE_ID=""
    EC_OFFSITE_SECRET=""
    [ -f "$f" ] && [ -r "$f" ] || return 2
    size="$(wc -c < "$f" 2> /dev/null | tr -d ' ')"
    [[ "$size" =~ ^[0-9]+$ ]] && [ "$size" -le 4096 ] || return 1
    while IFS= read -r line || [ -n "$line" ]; do
        line="${line#"${line%%[![:space:]]*}"}"
        line="${line%"${line##*[![:space:]]}"}"
        [ -n "$line" ] || continue
        case "$line" in
            access_key_id=*) id="${line#*=}" ;;
            secret_access_key=*) secret="${line#*=}" ;;
            *)
                n=$((n + 1))
                if [ "$n" -eq 1 ]; then first="$line"; else second="$line"; fi
                ;;
        esac
    done < "$f"
    line=""
    if [ -z "$id$secret" ] && [ "$n" -eq 2 ]; then
        id="$first"
        secret="$second"
        n=0
    fi
    first=""
    second=""
    if [ "$n" -ne 0 ] || ! [[ "$id" =~ ^[A-Za-z0-9]{16,128}$ ]] || ! [[ "$secret" =~ ^[A-Za-z0-9/+=]{16,128}$ ]]; then
        secret=""
        return 1
    fi
    EC_OFFSITE_ID="$id"
    EC_OFFSITE_SECRET="$secret"
    secret=""
    return 0
}

# 設定を答えから読む（形は見ない。ec_offsite_conf_check が見る）。<サーバー> の既定は管理画面のホスト、リージョンの既定は auto（R2）、
# 暗号化の既定は yes、ロックの確かめの既定は R2 の入口なら yes・ほかは no（置き場によって答えが違うので）
ec_offsite_conf_load() {
    EC_OFFSITE_ENDPOINT="$(ec_answer OFFSITE_ENDPOINT 2> /dev/null || true)"
    EC_OFFSITE_ENDPOINT="${EC_OFFSITE_ENDPOINT%/}"
    EC_OFFSITE_BUCKET="$(ec_answer OFFSITE_BUCKET 2> /dev/null || true)"
    EC_OFFSITE_PREFIX="$(ec_answer OFFSITE_PREFIX 2> /dev/null || true)"
    [ -n "$EC_OFFSITE_PREFIX" ] || EC_OFFSITE_PREFIX="$(ec_env_get EC_SUGAR_ADMIN_HOST 2> /dev/null || true)"
    EC_OFFSITE_REGION="$(ec_answer OFFSITE_REGION 2> /dev/null || true)"
    [ -n "$EC_OFFSITE_REGION" ] || EC_OFFSITE_REGION=auto
    EC_OFFSITE_ENCRYPT="$(ec_answer OFFSITE_ENCRYPT 2> /dev/null || true)"
    [ -n "$EC_OFFSITE_ENCRYPT" ] || EC_OFFSITE_ENCRYPT=yes
    EC_OFFSITE_LOCK_CHECK="$(ec_answer OFFSITE_LOCK_CHECK 2> /dev/null || true)"
    if [ -z "$EC_OFFSITE_LOCK_CHECK" ]; then
        if [[ "$EC_OFFSITE_ENDPOINT" =~ ^https://[a-z0-9.-]+\.r2\.cloudflarestorage\.com$ ]]; then EC_OFFSITE_LOCK_CHECK=yes; else EC_OFFSITE_LOCK_CHECK=no; fi
    fi
    return 0
}
# 読んだ設定の形を確かめる。駄目なら、どれが駄目かを EC_OFFSITE_PROBLEM に入れて 1（文は呼ぶ側が出す）
ec_offsite_conf_check() {
    EC_OFFSITE_PROBLEM=""
    if ! ec_offsite_valid_endpoint "$EC_OFFSITE_ENDPOINT"; then
        EC_OFFSITE_PROBLEM="ストレージのエンドポイント（OFFSITE_ENDPOINT）が https://<名前> の形でない"
    elif ! ec_offsite_valid_bucket "$EC_OFFSITE_BUCKET"; then
        EC_OFFSITE_PROBLEM="バケットの名前（OFFSITE_BUCKET）の形が違う"
    elif ! ec_offsite_valid_prefix "$EC_OFFSITE_PREFIX"; then
        EC_OFFSITE_PROBLEM="サーバーの名前（OFFSITE_PREFIX。無ければ管理画面のホスト）の形が違う"
    elif ! ec_offsite_valid_region "$EC_OFFSITE_REGION"; then
        EC_OFFSITE_PROBLEM="リージョン（OFFSITE_REGION）の形が違う"
    elif [ "$EC_OFFSITE_ENCRYPT" != yes ] && [ "$EC_OFFSITE_ENCRYPT" != no ]; then
        EC_OFFSITE_PROBLEM="OFFSITE_ENCRYPT は yes か no"
    elif [ "$EC_OFFSITE_LOCK_CHECK" != yes ] && [ "$EC_OFFSITE_LOCK_CHECK" != no ]; then
        EC_OFFSITE_PROBLEM="OFFSITE_LOCK_CHECK は yes か no"
    fi
    [ -z "$EC_OFFSITE_PROBLEM" ]
}

# curl の版（1 行目の「curl 8.5.0 …」）と、下限に足りるか
ec_offsite_curl_version() { curl --version 2> /dev/null | sed -n '1s/^curl \([0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*\).*/\1/p'; }
ec_offsite_curl_ok() {
    local v
    v="$(ec_offsite_curl_version)"
    [ -n "$v" ] && [ "$(printf '%s\n%s\n' "$EC_OFFSITE_CURL_MIN" "$v" | sort -V | head -n1)" = "$EC_OFFSITE_CURL_MIN" ]
}

# 確かめ用の gpg の一時の homedir（送る・取る回の homedir とは別の名前。回の初めの片付けに消されない）
ec_offsite_tmp_home() {
    ec_lock_dir || return 1
    mktemp -d "$(dirname "$EC_OFFSITE_LOCK")/offsite-chk.XXXXXX"
}
# 暗号の公開鍵が、鍵束なし（空の一時の homedir）で使えるか（期限の切れた鍵・暗号化に使えない鍵は 1）。$1 公開鍵のファイル
ec_offsite_recipient_ok() {
    local home rc=0
    command -v gpg > /dev/null 2>&1 || return 1
    home="$(ec_offsite_tmp_home)" || return 1
    printf 'ec-sugar' | "${EC_OFFSITE_GPG[@]}" --homedir "$home" --trust-model always --compress-algo none \
        --recipient-file "$1" --encrypt --output - > /dev/null 2>&1 || rc=1
    rm -rf "$home"
    return "$rc"
}
# 公開鍵の指紋（最初の鍵。16 進の大文字）を出す。$1 公開鍵のファイル。読めなければ 1
ec_offsite_recipient_fpr() {
    local home out
    command -v gpg > /dev/null 2>&1 || return 1
    home="$(ec_offsite_tmp_home)" || return 1
    out="$("${EC_OFFSITE_GPG[@]}" --homedir "$home" --with-colons --import-options show-only --import "$1" 2> /dev/null | awk -F: '$1 == "fpr" {print $10; exit}')" || out=""
    rm -rf "$home"
    [[ "$out" =~ ^[0-9A-F]{40,64}$ ]] || return 1
    printf '%s' "$out"
}
# 暗号の公開鍵がまだ無い時に、管理画面で作った復元のキットの「開ける鍵」の公開の半分を採る（ADR 0111）。
# オーナーがブラウザの中で鍵を作り、保存したキットを開け直して確かめた後に、アプリが公開の半分だけを持つ（operations:recovery-recipient）。
# - 採るのは、公開鍵がまだ無い時だけ。置いてある鍵は、アプリが何を答えても替えない（アプリの DB を書き換えられても、以後のバックアップの
#   相手は替わらない。替えるのはサーバーの上の人の手: sudo rm でいまの公開鍵を消してから）
# - アプリの答えを信じない: 大きさ・包みの形・秘密の鍵でないこと・gpg で暗号化できること・gpg が数えた指紋が 1 行目と同じことを確かめる
# 返り: 0 採った・もうある、1 採れるものが無い（まだ作っていない・アプリに聞けない・形が違う）
ec_offsite_adopt_recipient() {
    local out want tmp fpr old_umask
    [ ! -s "$EC_OFFSITE_DIR/recipient.asc" ] || return 0
    out="$(ec_dc exec -T app php artisan operations:recovery-recipient 2> /dev/null | head -c 16385)" || return 1
    [ -n "$out" ] && [ "${#out}" -le 16384 ] || return 1
    want="${out%%$'\n'*}"
    [[ "$want" =~ ^[0-9A-F]{40}$ ]] || return 1
    old_umask="$(umask)"
    umask 077
    tmp="$(mktemp "$EC_OFFSITE_DIR/.adopt.XXXXXX")" || { umask "$old_umask"; return 1; }
    umask "$old_umask"
    printf '%s\n' "${out#*$'\n'}" > "$tmp" || { rm -f "$tmp"; return 1; }
    if [ "$(head -n 1 "$tmp")" != '-----BEGIN PGP PUBLIC KEY BLOCK-----' ] || grep -q 'PRIVATE KEY' "$tmp" || [ "$(grep -c '^-----BEGIN ' "$tmp")" != 1 ] ||
        ! ec_offsite_recipient_ok "$tmp"; then
        rm -f "$tmp"
        ec_warn "管理画面で作った復元のキットの公開鍵が、暗号化に使える形ではないので採りません（管理画面の運営ダッシュボードで、復元のキットを作り直す）"
        return 1
    fi
    fpr="$(ec_offsite_recipient_fpr "$tmp" || true)"
    if [ "$fpr" != "$want" ]; then
        rm -f "$tmp"
        ec_warn "管理画面で作った復元のキットの公開鍵の指紋が、アプリの応答（${want}）と違うので採りません"
        return 1
    fi
    if ! ec_offsite_put_file "$tmp" recipient.asc; then
        rm -f "$tmp"
        return 1
    fi
    rm -f "$tmp"
    ec_ok "サーバーの外のバックアップを暗号化する相手を、管理画面で作った復元のキットの鍵にした（指紋 ${fpr}。キットの画面の指紋と同じはず）"
    return 0
}
# いま暗号化に使っている公開鍵の指紋を、アプリへ知らせる。管理画面が、復元のキットの鍵と同じかを比べて、違えば知らせる
# （違うキットでは、サーバーの外のバックアップを開けない）。渡すのは指紋だけ。前に知らせたものと同じなら、アプリを呼ばない。
# アプリが古い（コマンドや引数が無い）・止まっている時は黙って飛ばし、次の回にまた試す
ec_offsite_tell_recipient() {
    local fpr
    [ -s "$EC_OFFSITE_DIR/recipient.asc" ] || return 0
    fpr="$(ec_offsite_recipient_fpr "$EC_OFFSITE_DIR/recipient.asc" || true)"
    [[ "$fpr" =~ ^[0-9A-F]{40,64}$ ]] || return 0
    [ "$(ec_kv_get "$EC_STATE/offsite" recipient_told 2> /dev/null || true)" != "$fpr" ] || return 0
    if ec_dc exec -T app php artisan operations:recovery-recipient "--host-fingerprint=$fpr" > /dev/null 2>&1; then
        ec_offsite_state_set "recipient_told=$fpr" || true
    fi
    return 0
}
# 鍵・公開鍵の元のファイル $1 が、root の持ち物で、ほかの人が書けず、リンクでないか（流し直しのたびに写すので、ほかの人が差し替えられる所から読まない）
ec_offsite_source_ok() {
    local mode
    [ -f "$1" ] && [ ! -L "$1" ] && [ "$(ec_file_uid "$1")" = 0 ] || return 1
    mode="$(stat -c %a "$1" 2> /dev/null)" || return 1
    [[ "$mode" =~ ^[0-7]?[0-7][0145][0145]$ ]]
}
# 強く切られた前の回の消し残し（gpg の一時の homedir・開いた復元のキット・切れ端・開けた途中のもの）を片付ける。自分の錠を取った直後にだけ呼ぶ
ec_offsite_sweep() {
    local d
    for d in "$(dirname "$EC_OFFSITE_LOCK")"/offsite-gpg.* "$(dirname "$EC_OFFSITE_LOCK")"/kit.*; do
        [ ! -d "$d" ] || rm -rf "$d"
    done
    rm -rf "$EC_OFFSITE_WORK/tmp"
    # 公開鍵を採る途中で切られた時の切れ端（中身は公開鍵だけ）
    rm -f "$EC_OFFSITE_DIR"/.adopt.* 2> /dev/null || true
}
# gpg の一時の homedir を root だけの置き場（/run の下。ディスクに残らない）に作って、道筋を出す
ec_offsite_gpg_home() {
    ec_lock_dir || return 1
    mktemp -d "$(dirname "$EC_OFFSITE_LOCK")/offsite-gpg.XXXXXX"
}
ec_offsite_gpg_home_drop() {
    [ -n "$EC_OFFSITE_GPG_HOME" ] || return 0
    gpgconf --homedir "$EC_OFFSITE_GPG_HOME" --kill all > /dev/null 2>&1 || true
    rm -rf "$EC_OFFSITE_GPG_HOME"
    EC_OFFSITE_GPG_HOME=""
}

# 置き場への 1 回の要求。$1 PUT・GET・DELETE $2 バケットの後ろ（/<キー> か ?<問い合わせ>）$3 ファイル（PUT は送る元、GET は受ける先。DELETE は空）
# $4 から 足す見出し。応答の番号を EC_OFFSITE_HTTP に入れ、2xx なら 0。GET の答えの上限は EC_OFFSITE_GET_MAX（無ければ切れ端の上限）。
# 鍵は設定として標準入力で（printf は bash の中の命令なので、ps に出ない）。curl の誤りの文は出さない（URL と名前だけでも記録に残さない）
ec_offsite_request() {
    local method="$1" suffix="$2" file="${3:-}" rc=0 h args=()
    if [ $# -ge 3 ]; then shift 3; else shift $#; fi
    EC_OFFSITE_HTTP=000
    # -q は先頭に（root の ~/.curlrc を読まない）。行き先を変える答え（3xx）は追わない
    args=(-q -sS --proto "=${EC_OFFSITE_SCHEMES// /,}" --connect-timeout 20 --max-time "$EC_OFFSITE_MAX_TIME" --speed-limit 1024 --speed-time 120 -w '%{http_code}')
    case "$method" in
        PUT) args+=(-o /dev/null -T "$file") ;;
        GET) args+=(-o "$file" --max-filesize "${EC_OFFSITE_GET_MAX:-$EC_OFFSITE_PART_MAX}") ;;
        DELETE) args+=(-o /dev/null -X DELETE) ;;
        *) return 1 ;;
    esac
    for h in "$@"; do args+=(-H "$h"); done
    ec_offsite_cred_read "${EC_OFFSITE_CRED_FILE:-$EC_OFFSITE_DIR/credentials}" || { EC_OFFSITE_ID=""; EC_OFFSITE_SECRET=""; return 1; }
    EC_OFFSITE_HTTP="$(printf 'user = "%s:%s"\naws-sigv4 = "aws:amz:%s:s3"\n' "$EC_OFFSITE_ID" "$EC_OFFSITE_SECRET" "$EC_OFFSITE_REGION" |
        curl "${args[@]}" --config - "$EC_OFFSITE_ENDPOINT/$EC_OFFSITE_BUCKET$suffix" 2> /dev/null)" || rc=1
    EC_OFFSITE_ID=""
    EC_OFFSITE_SECRET=""
    EC_OFFSITE_HTTP="$(ec_code_norm "$EC_OFFSITE_HTTP")"
    [ "$rc" -eq 0 ] && [[ "$EC_OFFSITE_HTTP" =~ ^2[0-9]{2}$ ]]
}

# 1 つのファイルを置く。$1 キー $2 ファイル $3 が overwrite なら「まだ無い時だけ」を付けない（ロックの確かめの上書きの試しだけ）。
# どの PUT にも中身の指紋（x-amz-content-sha256 と Content-MD5）を付け、ふつうは「まだ無い時だけ書く」（If-None-Match: *）。
# 送った中身の SHA-256 を EC_OFFSITE_PUT_SHA に残す
ec_offsite_put() {
    local key="$1" file="$2" md5
    EC_OFFSITE_PUT_SHA="$(ec_integrity_sha "$file")" || return 1
    md5="$(openssl dgst -md5 -binary < "$file" | base64)" || return 1
    [[ "$md5" =~ ^[A-Za-z0-9+/]{22}==$ ]] || return 1
    if [ "${3:-}" = overwrite ]; then
        ec_offsite_request PUT "/$key" "$file" "x-amz-content-sha256: $EC_OFFSITE_PUT_SHA" "Content-MD5: $md5"
    else
        ec_offsite_request PUT "/$key" "$file" "x-amz-content-sha256: $EC_OFFSITE_PUT_SHA" "Content-MD5: $md5" "If-None-Match: *"
    fi
}

# 標準入力を包んで標準出力へ（公開鍵で暗号化。圧縮はしない〔dump も写真も縮んでいる〕。OFFSITE_ENCRYPT=no の時だけそのまま）
ec_offsite_seal() {
    if [ "$EC_OFFSITE_ENCRYPT" = no ]; then
        cat
        return
    fi
    "${EC_OFFSITE_GPG[@]}" --homedir "$EC_OFFSITE_GPG_HOME" --trust-model always --compress-algo none \
        --recipient-file "$EC_OFFSITE_DIR/recipient.asc" --encrypt --output -
}
# 標準入力を EC_OFFSITE_PART_BYTES ごとに切り、切れ端を 1 つずつ一時の置き場に書いて送って消す。$1 キーの頭 $2 一時の置き場。
# 切れ端ごとの「名前 大きさ SHA-256」を $2/parts に残す。この中で標準入力を読むのは head だけ（ほかは < ファイル か パイプから読む）
ec_offsite_split() {
    local base="$1" tmp="$2" n=0 part="$2/part" size name
    : > "$tmp/parts" || return 1
    while :; do
        n=$((n + 1))
        [ "$n" -le 9999 ] || return 1
        head -c "$EC_OFFSITE_PART_BYTES" > "$part" || return 1
        [ -s "$part" ] || break
        size="$(wc -c < "$part" | tr -d ' ')"
        name="$(printf 'part-%04d' "$n")"
        if ! ec_offsite_put "$base/$name" "$part"; then
            rm -f "$part"
            return 1
        fi
        printf '%s %s %s\n' "$name" "$size" "$EC_OFFSITE_PUT_SHA" >> "$tmp/parts"
        # 短い切れ端は最後（流れの終わり）
        [ "$size" -eq "$EC_OFFSITE_PART_BYTES" ] || break
    done
    rm -f "$part"
    [ -s "$tmp/parts" ]
}

# 1 つの組（種類 $1 = db・files）を送る。$2 控えの置き場 $3 バックアップの名前。切れ端を全部送ってから INDEX を最後に送る
ec_offsite_send_kind() {
    local kind="$1" dir="$2" name="$3" sent base tmp f st=() members=()
    sent="$(ec_offsite_stamp)"
    base="$kind/$EC_OFFSITE_PREFIX/${name:0:4}/${name:4:2}/$name.$sent"
    tmp="$EC_OFFSITE_WORK/tmp"
    rm -rf "$tmp"
    install -d -m 0700 "$tmp" || return 1
    if [ "$kind" = db ]; then
        # 決まった名前のファイルだけ（env は置き場が別で、ここには無い。控えの中の印 .sent-db なども入れない）
        for f in "$dir"/*.dump "$dir/globals.sql" "$dir/manifest" "$dir/SHA256SUMS" "$dir/.complete"; do
            [ -f "$f" ] && [ ! -L "$f" ] || continue
            members+=("$(basename "$f")")
        done
        [ "${#members[@]}" -ge 4 ] || return 1
        tar -C "$dir" -cf - -- "${members[@]}" | ec_offsite_seal | ec_offsite_split "$base" "$tmp"
        st=("${PIPESTATUS[@]}")
    else
        [ -f "$dir/$EC_BACKUP_FILES_NAME" ] && [ ! -L "$dir/$EC_BACKUP_FILES_NAME" ] || return 1
        # 3 段にそろえる（どの段が失敗したかを同じ形で見る）
        # shellcheck disable=SC2002
        cat "$dir/$EC_BACKUP_FILES_NAME" | ec_offsite_seal | ec_offsite_split "$base" "$tmp"
        st=("${PIPESTATUS[@]}")
    fi
    if [ "${st[0]}" -ne 0 ] || [ "${st[1]}" -ne 0 ] || [ "${st[2]}" -ne 0 ]; then
        rm -rf "$tmp"
        return 1
    fi
    {
        printf 'format=1\nkind=%s\nbackup=%s\nsent=%s\nserver=%s\nencrypted=%s\nparts=%s\n' \
            "$kind" "$name" "$sent" "$EC_OFFSITE_PREFIX" "$([ "$EC_OFFSITE_ENCRYPT" = no ] && printf no || printf yes)" "$(wc -l < "$tmp/parts" | tr -d ' ')"
        cat "$tmp/parts"
    } > "$tmp/INDEX" || { rm -rf "$tmp"; return 1; }
    if ! ec_offsite_put "$base/INDEX" "$tmp/INDEX"; then
        rm -rf "$tmp"
        return 1
    fi
    rm -rf "$tmp"
}

# 送る分の控えを作る（ec-sugar backup が、取れた後に運用の錠を持ったまま呼ぶ）。$1 取ったバックアップの置き場。
# ハードリンクなので一瞬で、容量を食わない（掃除が元を外しても控えは残る。指紋と manifest の直しは置き換えなので、控えの中身は変わらない）。
# ファイルシステムが違ってリンクできなければ写す。置き場を設定していなければ何もしない。作れなくても、バックアップの結果は変えない（1 を返すだけ）。
# 溜まった古い控えを外すのは、送る回が動いていない時だけ（送っている最中の控えを消さない。送る回の錠を試して、取れた時だけ）
ec_offsite_stage() {
    local dir="$1" name stage new f d list=() drop
    ec_offsite_configured || return 0
    # 隔離した写し（復元の訓練）からは送らない
    ! ec_is_isolated || return 0
    name="$(basename "$dir")"
    ec_offsite_valid_name "$name" && [ -e "$dir/.complete" ] || return 1
    stage="$EC_OFFSITE_WORK/stage"
    install -d -m 0700 -o root -g root "$EC_OFFSITE_WORK" "$stage" || return 1
    new="$stage/.$name.new"
    rm -rf "$new"
    mkdir -m 0700 "$new" || return 1
    for f in "$dir"/*.dump "$dir/globals.sql" "$dir/manifest" "$dir/SHA256SUMS" "$dir/.complete" "$dir/$EC_BACKUP_FILES_NAME"; do
        [ -f "$f" ] && [ ! -L "$f" ] || continue
        if ! ln "$f" "$new/" 2> /dev/null && ! cp -p "$f" "$new/"; then
            rm -rf "$new"
            return 1
        fi
    done
    # 同じ名前の控えがもうあれば（送っている最中かもしれない）、そのまま使う
    if [ -e "$stage/$name" ]; then
        rm -rf "$new"
        return 0
    fi
    mv "$new" "$stage/$name" || { rm -rf "$new"; return 1; }
    # 送れないまま溜まった控えは EC_OFFSITE_STAGE_KEEP まで（名前の順＝古い順に外す）
    ec_lock_dir || return 0
    {
        if flock -n 7; then
            for d in "$stage"/*/; do
                [ -d "$d" ] && list+=("${d%/}")
            done
            drop=$((${#list[@]} - EC_OFFSITE_STAGE_KEEP))
            for d in "${list[@]}"; do
                [ "$drop" -gt 0 ] || break
                rm -rf "$d"
                ec_warn "サーバーの外へ送れていないバックアップが溜まったので、いちばん古いバックアップ（$(basename "$d")）を送らずに外しました（sudo ec-sugar offsite status で理由を見る）"
                drop=$((drop - 1))
            done
        fi
    } 7> "$EC_OFFSITE_LOCK"
    return 0
}

# 失敗の理由の決まった語（state/offsite の reason。運営の様子へ渡すのはこの語だけ。アプリの HostReportCommand::OFFSITE_REASONS と同じ並び）
EC_OFFSITE_REASONS="config curl-old no-gpg no-recipient bad-recipient put gave-up"
ec_offsite_valid_reason() { [[ "$1" =~ ^[a-z][a-z-]{0,15}$ ]] && [[ " $EC_OFFSITE_REASONS " == *" $1 "* ]]; }
# 結果を state/offsite に残す（key=value。名前は運営の様子へ渡す引数と同じ。ec_report_state_args が形を確かめて --offsite… で渡す）:
#   offsite            ok・failed（最後の回）
#   offsite_at         最後にバックアップを送れた時刻
#   offsite_tried_at   最後に試した時刻
#   offsite_lock       ok・open・unknown（置き場が上書きと削除を断ったか。確かめを入れた時だけ）
#   offsite_heads      ok・failed（連鎖の先頭。アプリにコマンドがある時だけ）
#   offsite_heads_at   先頭を最後に送れた（か、前と同じと確かめた）時刻
#   last_backup        最後に送れたバックアップの名前
#   reason             失敗の理由の決まった語（config・curl-old・no-gpg・no-recipient・bad-recipient〔公開鍵で暗号化できない〕・
#                      put〔置き場に置けない。間を空けて送り直す〕・gave-up〔回数の上限まで試した。もう自動では送り直さない〕。成功なら空）
# $1 から「名前=値」。書いていない名前の値は前のまま
ec_offsite_state_set() {
    local pair
    for pair in "$@"; do
        ec_kv_set "$EC_STATE/offsite" "${pair%%=*}" "${pair#*=}" || return 1
    done
}

# 置き場のロックの確かめ（バックアップを送れた回と ec-sugar offsite test）。小さな印を置き、同じ名前への上書きと削除を試して、語を
# EC_OFFSITE_LOCK_WORD に、印のキーを EC_OFFSITE_PROBE_KEY に入れる。印を置けなければ 1:
#   ok 両方とも断られた（409）、open どちらかが通った（ロックが掛かっていない・外された）、unknown 印を置けない・答えが 409 でも 2xx でもない
# $1 が put-only なら印を置くだけ（上書きと削除を試さない。語は unknown のまま）
EC_OFFSITE_LOCK_WORD=""
EC_OFFSITE_PROBE_KEY=""
ec_offsite_lock_probe() {
    local kind key tmp="$EC_OFFSITE_WORK/tmp" over del all=ok
    EC_OFFSITE_LOCK_WORD=unknown
    EC_OFFSITE_PROBE_KEY=""
    rm -rf "$tmp"
    install -d -m 0700 "$tmp" || return 1
    printf 'ec-sugar offsite probe\n' > "$tmp/probe"
    # 消せない期間の決まりは前置きごとなので、3 つの前置き（db・files・heads）のどれにも印を置いて試す。
    # 名前には時刻と乱数（同じ秒に 2 回確かめても、前の印〔消せない〕とぶつからない）
    for kind in db files heads; do
        key="$kind/$EC_OFFSITE_PREFIX/_probe/$(ec_offsite_stamp)-$(ec_integrity_random_hex 4)"
        [ -n "$EC_OFFSITE_PROBE_KEY" ] || EC_OFFSITE_PROBE_KEY="$key"
        if ! ec_offsite_put "$key" "$tmp/probe"; then
            rm -rf "$tmp"
            return 1
        fi
        # put-only は db/ に印を 1 つ置くだけ
        if [ "${1:-}" = put-only ]; then
            rm -rf "$tmp"
            return 0
        fi
        ec_offsite_put "$key" "$tmp/probe" overwrite || true
        over="$EC_OFFSITE_HTTP"
        ec_offsite_request DELETE "/$key" "" || true
        del="$EC_OFFSITE_HTTP"
        if [[ "$over" =~ ^2 ]] || [[ "$del" =~ ^2 ]]; then
            all=open
        elif [ "$over" != 409 ] || [ "$del" != 409 ]; then
            [ "$all" = open ] || all=unknown
        fi
    done
    rm -rf "$tmp"
    EC_OFFSITE_LOCK_WORD="$all"
    return 0
}

# 連鎖の先頭の 1 行の形（店の ID・番号・hash〔16 進 64 桁〕・控えた時刻をタブで）。$1 ファイル。空・形の違う行・多すぎる行は 1
ec_offsite_heads_valid() {
    local f="$1" line n=0 shop seq hash at rest
    [ -s "$f" ] || return 1
    while IFS= read -r line || [ -n "$line" ]; do
        n=$((n + 1))
        [ "$n" -le 100000 ] || return 1
        IFS=$'\t' read -r shop seq hash at rest <<< "$line" || return 1
        [ -z "$rest" ] && [[ "$shop" =~ ^[a-z0-9][a-z0-9-]{0,30}[a-z0-9]$ ]] && [[ "$seq" =~ ^[0-9]{1,18}$ ]] && [[ "$hash" =~ ^[0-9a-f]{64}$ ]] && ec_valid_time "$at" || return 1
        [ "$line" = "$shop"$'\t'"$seq"$'\t'"$hash"$'\t'"$at" ] || return 1
    done < "$f"
    return 0
}
# 連鎖の先頭を送る（アプリに operations:chain-heads がある時だけ。無ければ飛ばす）。返り: 0 送った・前と同じ、1 失敗、2 飛ばした。
# コマンドがあるかは、入っているバージョンごとに 1 回だけ聞いて state/offsite_heads_cmd に残す（10 分ごとに聞かない）
ec_offsite_heads() {
    local commit known tmp="$EC_OFFSITE_WORK/tmp" sum now
    commit="$(ec_state_get current_commit 2> /dev/null || true)"
    known="$(ec_state_get offsite_heads_cmd 2> /dev/null || true)"
    if [ -z "$commit" ] || [ "${known% *}" != "$commit" ]; then
        ec_dc ps --status running --format '{{.Service}}' app 2> /dev/null | ec_q -x app || return 2
        known="$(ec_dc exec -T app php artisan list --raw operations 2> /dev/null)" || return 2
        # 道具は pipefail で流れるので、grep -q へはパイプでなく here-string で渡す（先に閉じられても失敗にしない）
        if grep -q '^operations:chain-heads\( \|$\)' <<< "$known"; then known="$commit yes"; else known="$commit no"; fi
        [ -z "$commit" ] || ec_state_put offsite_heads_cmd "$known"
    fi
    [ "${known##* }" = yes ] || return 2
    rm -rf "$tmp"
    install -d -m 0700 "$tmp" || return 1
    if ! ec_dc exec -T app php artisan operations:chain-heads > "$tmp/heads.tsv" 2> /dev/null; then
        rm -rf "$tmp"
        return 1
    fi
    # 店が 1 つも無ければ送るものが無い
    [ -s "$tmp/heads.tsv" ] || { rm -rf "$tmp"; return 0; }
    if ! ec_offsite_heads_valid "$tmp/heads.tsv"; then
        rm -rf "$tmp"
        return 1
    fi
    sum="$(ec_integrity_sha "$tmp/heads.tsv")" || { rm -rf "$tmp"; return 1; }
    # 前に送った中身と同じなら送らない
    if [ "$sum" = "$(ec_state_get offsite_heads_sum 2> /dev/null || true)" ]; then
        rm -rf "$tmp"
        return 0
    fi
    now="$(ec_offsite_stamp)"
    if ! ec_offsite_put "heads/$EC_OFFSITE_PREFIX/${now:0:4}/${now:4:2}/${now:6:2}/$now.tsv" "$tmp/heads.tsv"; then
        rm -rf "$tmp"
        return 1
    fi
    rm -rf "$tmp"
    ec_state_put offsite_heads_sum "$sum"
}
# 控え $1 を今試してよいか、語を出す: due（試す）・wait（前の失敗から間が空いていない）・gave-up（回数の上限に達した）。
# 控えの中の .retry に「失敗した回数 次に試す時刻（秒）」を残す
ec_offsite_retry_state() {
    local n=0 at=0
    [ -r "$1/.retry" ] || { printf 'due'; return 0; }
    read -r n at < "$1/.retry" || true
    [[ "$n" =~ ^[0-9]{1,4}$ ]] && [[ "$at" =~ ^[0-9]{1,12}$ ]] || { printf 'due'; return 0; }
    if [ "$n" -ge "$EC_OFFSITE_TRIES_MAX" ]; then
        printf 'gave-up'
    elif [ "$(date +%s)" -lt "$at" ]; then
        printf 'wait'
    else
        printf 'due'
    fi
}
# 控え $1 の失敗を 1 回数え、次に試す時刻を倍々で延ばす。回数の上限に達したら 1
ec_offsite_retry_note() {
    local n=0 at=0 wait="$EC_OFFSITE_RETRY_BASE" i=1
    if [ -r "$1/.retry" ]; then read -r n at < "$1/.retry" || true; fi
    [[ "$n" =~ ^[0-9]{1,4}$ ]] || n=0
    n=$((n + 1))
    while [ "$i" -lt "$n" ] && [ "$wait" -lt "$EC_OFFSITE_RETRY_MAX" ]; do
        wait=$((wait * 2))
        i=$((i + 1))
    done
    [ "$wait" -le "$EC_OFFSITE_RETRY_MAX" ] || wait="$EC_OFFSITE_RETRY_MAX"
    printf '%s %s\n' "$n" "$(($(date +%s) + wait))" > "$1/.retry" || true
    [ "$n" -lt "$EC_OFFSITE_TRIES_MAX" ]
}

# 送る回（ec-sugar offsite run。ec-sugar-offsite.timer が 10 分ごと）。設定が無ければ黙って 0。送るものも試すことも無い回は state を書かない。
# 先頭 → 控えを古い順に送る → ロックの確かめ → 結果を state に、の順。失敗は注意を 1 行出して 1（控えは残り、間を空けて新しい名前で送り直す。
# 間は倍々・回数に上限。ec_offsite_retry_note）。$1 が retry なら、失敗の数え（.retry）を消して今すぐ試し直す
ec_offsite_run() {
    local now d name stage reason="" sent="" lock="" heads_rc=0 pairs=() pending=0 checked=0 fpr cleared=0
    ec_offsite_configured || return 0
    ! ec_is_isolated || return 0
    ec_lock_dir || return 1
    exec 8> "$EC_OFFSITE_LOCK"
    # 前の回がまだ送っている（大きなバックアップ・細い回線）なら、今回は黙って見送る
    flock -n 8 || return 0
    ec_offsite_sweep
    now="$(ec_now)"
    stage="$EC_OFFSITE_WORK/stage"
    ec_offsite_conf_load
    if ! ec_offsite_conf_check; then
        reason=config
    elif ! ec_offsite_curl_ok; then
        reason=curl-old
    elif [ "$EC_OFFSITE_ENCRYPT" = yes ] && ! command -v gpg > /dev/null 2>&1; then
        reason=no-gpg
    elif [ "$EC_OFFSITE_ENCRYPT" = yes ] && [ ! -r "$EC_OFFSITE_DIR/recipient.asc" ] && ! ec_offsite_adopt_recipient; then
        # 公開鍵が無ければ、管理画面で作った復元のキットの鍵を採る。まだ作っていなければ、作るまで送らない
        reason=no-recipient
    fi
    if [ -n "$reason" ]; then
        ec_offsite_state_set offsite=failed "offsite_tried_at=$now" "reason=$reason" || true
        case "$reason" in
            config) ec_warn "サーバーの外へのバックアップの設定が読めません（${EC_OFFSITE_PROBLEM}。$EC_ANSWERS を直す。使うのをやめるなら sudo rm $EC_OFFSITE_DIR/credentials）" ;;
            curl-old) ec_warn "この curl（$(ec_offsite_curl_version)）では S3 互換のストレージへ送れません（${EC_OFFSITE_CURL_MIN} 以上が要る。Ubuntu 22.04 の curl は署名が通らない）" ;;
            no-gpg) ec_warn "gpg が無いので、バックアップを暗号化して送れません（sudo apt-get install -y gnupg）" ;;
            no-recipient) ec_warn "公開鍵が無いので送りません。管理画面の運営ダッシュボードで「復元のキット」を作ると、その鍵で暗号化して送り始めます（手元で作った gpg の公開鍵を使うなら、設定ファイルの OFFSITE_RECIPIENT_FILE。平文で送るなら OFFSITE_ENCRYPT=no）" ;;
        esac
        return 1
    fi
    install -d -m 0700 -o root -g root "$EC_OFFSITE_WORK" "$stage" || return 1
    [ "$EC_OFFSITE_ENCRYPT" != yes ] || ec_offsite_tell_recipient
    if [ "${1:-}" = retry ]; then
        for d in "$stage"/*/; do rm -f "$d.retry"; done
    fi
    if [ "$EC_OFFSITE_ENCRYPT" = yes ]; then
        EC_OFFSITE_GPG_HOME="$(ec_offsite_gpg_home)" || return 1
    fi
    # 1. 連鎖の先頭（アプリにコマンドがある時だけ）
    ec_offsite_heads || heads_rc=$?
    [ "$heads_rc" -ne 2 ] || heads_rc=skip
    case "${heads_rc}" in
        skip) ;;
        0) pairs+=(offsite_heads=ok "offsite_heads_at=$now") ;;
        *) pairs+=(offsite_heads=failed) ;;
    esac
    # 2. 控えを古い順に（名前の順）。DB の組を送れたら印を置き、写真の組だけ失敗した時に DB を送り直さない。
    #    前の失敗から間が空いていない控え・回数の上限に達した控えは飛ばす
    for d in "$stage"/*/; do
        d="${d%/}"
        [ -d "$d" ] && [ -e "$d/.complete" ] || continue
        name="$(basename "$d")"
        ec_offsite_valid_name "$name" || continue
        [ "$(ec_offsite_retry_state "$d")" = due ] || continue
        pending=1
        # 公開鍵が使えるか（期限が切れた・差し替わって壊れた）は、送る前に 1 回だけ見る（置き場の失敗と取り違えない。やり直しの回数には数えない）
        if [ "$checked" -eq 0 ] && [ "$EC_OFFSITE_ENCRYPT" = yes ]; then
            checked=1
            if ! ec_offsite_recipient_ok "$EC_OFFSITE_DIR/recipient.asc"; then
                reason=bad-recipient
                break
            fi
        fi
        if [ ! -e "$d/.sent-db" ]; then
            if ! ec_offsite_send_kind db "$d" "$name"; then
                reason=put
                ec_offsite_retry_note "$d" || reason=gave-up
                break
            fi
            : > "$d/.sent-db"
        fi
        if [ -e "$d/$EC_BACKUP_FILES_NAME" ]; then
            if ! ec_offsite_send_kind files "$d" "$name"; then
                reason=put
                ec_offsite_retry_note "$d" || reason=gave-up
                break
            fi
        fi
        rm -rf "$d"
        sent="$name"
    done
    # 3. 置き場のロックの確かめ（バックアップを送れた回だけ。確かめを入れた時だけ）
    if [ -n "$sent" ] && [ "$EC_OFFSITE_LOCK_CHECK" = yes ]; then
        ec_offsite_lock_probe || true
        lock="$EC_OFFSITE_LOCK_WORD"
        pairs+=("offsite_lock=$lock")
    fi
    ec_offsite_gpg_home_drop
    # 前の回が「送る前の支度」（設定・curl・gpg・公開鍵）で止まっていたなら、支度が整った今、その失敗を消す。
    # 消さないと、送る控えが次にできるまで、直したのに「送れていない」が運営の様子に出続ける（公開鍵を採った直後など）。
    # 消すのは、送る控えが 1 つも残っていない時だけ（間を空けている・あきらめた控えがあるなら、送れていないのは本当）。
    # 連鎖の先頭を送った回でも同じ（先頭を送るかどうかで、消える・消えないが変わらないように）
    if [ "$pending" -eq 0 ] && ! compgen -G "$stage/*/.complete" > /dev/null; then
        case "$(ec_kv_get "$EC_STATE/offsite" reason 2> /dev/null || true)" in
            config | curl-old | no-gpg | no-recipient) cleared=1 ;;
        esac
    fi
    # 何も試していない回（控えが無い・間を空けている、先頭も飛ばした）は、何も書かない
    if [ "$pending" -eq 0 ] && [ "$heads_rc" = skip ] && [ "$cleared" -eq 0 ]; then return 0; fi
    if [ -n "$sent" ]; then pairs+=("offsite_at=$now" "last_backup=$sent"); fi
    if [ "$pending" -eq 1 ] || [ ! -e "$EC_STATE/offsite" ]; then
        pairs+=("offsite=$([ -z "$reason" ] && printf ok || printf failed)" "reason=$reason")
    elif [ "$cleared" -eq 1 ]; then
        pairs+=(offsite=ok reason=)
    fi
    ec_offsite_state_set "offsite_tried_at=$now" "${pairs[@]}" || ec_warn "サーバーの外へのバックアップの結果を $EC_STATE/offsite に残せませんでした"
    if [ "$lock" = open ]; then
        ec_warn "ストレージが、置いた印の上書きか削除を断りませんでした（オブジェクトロックのルールが db/・files/・heads/ のどれかに掛かっていないか、外されています。ストレージの画面で確認する）"
    fi
    case "$reason" in
        bad-recipient)
            ec_warn "公開鍵（$EC_OFFSITE_DIR/recipient.asc）で暗号化できないので送っていません（期限が切れたか、壊れている。バックアップは残っている。新しい公開鍵を置き直す）"
            return 1
            ;;
        gave-up)
            ec_warn "バックアップを ${EC_OFFSITE_TRIES_MAX} 回試してもサーバーの外へ送れなかったので、このバックアップはもう自動では送り直しません（ストレージの応答 ${EC_OFFSITE_HTTP}。原因を直してから sudo ec-sugar offsite run --retry）"
            return 1
            ;;
        put)
            ec_warn "バックアップをサーバーの外へ送れませんでした（ストレージの応答 ${EC_OFFSITE_HTTP}。バックアップは残っているので、間を空けて新しい名前で送り直す。sudo ec-sugar offsite test で確認する）"
            return 1
            ;;
    esac
    if [ "$heads_rc" != skip ] && [ "$heads_rc" != 0 ]; then
        ec_warn "記録のハッシュチェーンの先頭をサーバーの外へ送れませんでした（次の回に送り直す）"
        return 1
    fi
    [ -z "$sent" ] || ec_ok "バックアップ $sent までをサーバーの外へ送った"
    return 0
}

# 送る回の結果を運営の様子へ渡す（ec-sugar offsite run の終わりに）。毎回は渡さない: 渡す値（結果・理由・最後に送れた時刻・
# ロック・先頭の語）が回の前と変わった時だけ。最後に試した時刻と先頭を確かめた時刻は毎回動くので比べない（毎晩の報告が新しくする）。
# 渡せなかった時（app が動いていない間など）は印を残し、次の回（10 分後）に渡し直す。失敗しても送る回の結果は変えない
# ec_offsite_report_key は比べる値を 1 行で出す（state が無ければ空）。ec_offsite_report_if_changed の $1 は回の前のその 1 行
ec_offsite_report_key() {
    local f="$EC_STATE/offsite" k
    [ -r "$f" ] || return 0
    for k in offsite reason offsite_at offsite_lock offsite_heads; do
        printf '%s=%s ' "$k" "$(ec_kv_get "$f" "$k" || true)"
    done
}
ec_offsite_report_if_changed() {
    local pending="$EC_STATE/offsite_report_pending"
    ec_offsite_configured || return 0
    if [ "$(ec_offsite_report_key)" = "$1" ] && [ ! -e "$pending" ]; then return 0; fi
    if ec_host_report > /dev/null 2>&1; then
        rm -f "$pending"
    else
        : > "$pending" || true
    fi
    return 0
}

# ---------- 取る側（ec-sugar offsite pull。戻す先の機械で、読む鍵と開ける鍵を渡して） ----------
# URL の問い合わせに書く値（英数字と . _ ~ - 以外を %XX に。ASCII だけを渡す）
ec_offsite_urlencode() {
    local s="$1" out="" c i
    for ((i = 0; i < ${#s}; i++)); do
        c="${s:i:1}"
        case "$c" in
            [A-Za-z0-9._~-]) out="$out$c" ;;
            *) out="$out$(printf '%%%02X' "'$c")" ;;
        esac
    done
    printf '%s' "$out"
}
# 前置き $1 の下のキーを「キー<タブ>置き場の側の時刻」で 1 行ずつ出す（ListObjectsV2。続きがあれば続きも）。
# 問い合わせの項目は名前の順に並べる（古い curl は並べ直さずに署名する）。形の違うキー・時刻は出さない
ec_offsite_list() {
    local prefix="$1" token="" q body rest item key at page=0 tmp="$EC_OFFSITE_WORK/tmp"
    # < と > は [[ ]] の中で別の意味になるので、形は変数に入れてから比べる
    local re_key='<Key>([A-Za-z0-9._/-]{1,512})</Key>' re_at='<LastModified>([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2})([.][0-9]+)?Z</LastModified>'
    local re_token='<NextContinuationToken>([^<]{1,2048})</NextContinuationToken>'
    install -d -m 0700 "$tmp" || return 1
    while :; do
        page=$((page + 1))
        [ "$page" -le 100000 ] || return 1
        q="list-type=2&max-keys=$EC_OFFSITE_LIST_MAX&prefix=$(ec_offsite_urlencode "$prefix")"
        [ -z "$token" ] || q="continuation-token=$(ec_offsite_urlencode "$token")&$q"
        EC_OFFSITE_GET_MAX="$EC_OFFSITE_META_MAX" ec_offsite_request GET "?$q" "$tmp/list.xml" || { rm -f "$tmp/list.xml"; return 1; }
        body="$(tr -d '\n\r' < "$tmp/list.xml")"
        rm -f "$tmp/list.xml"
        [[ "$body" == *"<ListBucketResult"* ]] || return 1
        rest="$body"
        while [[ "$rest" == *"<Contents>"* ]]; do
            rest="${rest#*<Contents>}"
            item="${rest%%</Contents>*}"
            key=""
            at=""
            if [[ "$item" =~ $re_key ]]; then key="${BASH_REMATCH[1]}"; fi
            if [[ "$item" =~ $re_at ]]; then at="${BASH_REMATCH[1]}Z"; fi
            [ -z "$key" ] || [ -z "$at" ] || printf '%s\t%s\n' "$key" "$at"
        done
        [[ "$body" == *"<IsTruncated>true</IsTruncated>"* ]] || break
        [[ "$body" =~ $re_token ]] || return 1
        token="${BASH_REMATCH[1]}"
    done
    return 0
}
# 名前の時刻 $1・送った時刻 $2・置き場の側の時刻 $3 が食い違うか（食い違えば 0＝疑わしい）。名前と送った時刻は送る側が自由に書けるので、
# 置き場の側の時刻（置き場が付ける）と合わないものは、先回りで置いた・日付を偽ったものを疑う:
#   - 置き場の側の時刻（INDEX を置いた時）は、送った時刻（送り始め）の 10 分前から 2 日後まで
#   - 送った時刻（UTC）は、名前の時刻（サーバーの時刻の帯。UTC との差は −12〜＋14 時間）より前でなく、8 日より後でもない
ec_offsite_set_suspect() {
    local n s a
    n="$(date -u -d "${1:0:4}-${1:4:2}-${1:6:2} ${1:9:2}:${1:11:2}:${1:13:2}" +%s 2> /dev/null)" || return 0
    s="$(date -u -d "${2:0:4}-${2:4:2}-${2:6:2} ${2:9:2}:${2:11:2}:${2:13:2}" +%s 2> /dev/null)" || return 0
    a="$(date -u -d "$3" +%s 2> /dev/null)" || return 0
    [ "$a" -ge $((s - 600)) ] && [ "$a" -le $((s + 172800)) ] || return 0
    [ "$s" -ge $((n - 50400 - 600)) ] && [ "$s" -le $((n + 43200 + 691200)) ] || return 0
    return 1
}
# そろっている組（INDEX のあるもの）を「種類 バックアップの名前 送った時刻 置き場の側の時刻 印」で 1 行ずつ、置き場の側の時刻の古い順に出す。
# 印は ok か suspect（時刻が食い違う。ec_offsite_set_suspect）
ec_offsite_sets() {
    local kind key at rel list
    for kind in db files; do
        list="$(ec_offsite_list "$kind/$EC_OFFSITE_PREFIX/")" || exit 1
        while IFS=$'\t' read -r key at; do
            [ -n "$key" ] || continue
            rel="${key#"$kind/$EC_OFFSITE_PREFIX/"}"
            [ "$rel" != "$key" ] || continue
            if [[ "$rel" =~ ^([0-9]{4})/([0-9]{2})/([0-9]{8}-[0-9]{6})\.([0-9]{8}T[0-9]{6}Z)/INDEX$ ]] &&
                [ "${BASH_REMATCH[1]}${BASH_REMATCH[2]}" = "${BASH_REMATCH[3]:0:6}" ]; then
                if ec_offsite_set_suspect "${BASH_REMATCH[3]}" "${BASH_REMATCH[4]}" "$at"; then
                    printf '%s %s %s %s suspect\n' "$kind" "${BASH_REMATCH[3]}" "${BASH_REMATCH[4]}" "$at"
                else
                    printf '%s %s %s %s ok\n' "$kind" "${BASH_REMATCH[3]}" "${BASH_REMATCH[4]}" "$at"
                fi
            fi
        done <<< "$list"
    done | sort -k4,4 -k3,3
    [ "${PIPESTATUS[0]}" -eq 0 ]
}
# 標準入力を開けて標準出力へ（開ける鍵を入れた一時の homedir で。合い言葉つきの鍵は --passphrase-file のファイルから）。
# gpg の様子（--status-fd）を一時の置き場の gpg.status に残す。呼ぶ側が「本当に暗号を開けた」（DECRYPTION_OKAY）ことを確かめる
# （gpg --decrypt は、暗号化されていない包み・圧縮だけのものも中身を出して 0 で終わる）
ec_offsite_open() {
    local args=(--homedir "$EC_OFFSITE_GPG_HOME" --pinentry-mode loopback --status-fd 3)
    [ -z "$EC_OFFSITE_PASSPHRASE_FILE" ] || args+=(--passphrase-file "$EC_OFFSITE_PASSPHRASE_FILE")
    "${EC_OFFSITE_GPG[@]}" "${args[@]}" --decrypt 3> "$EC_OFFSITE_WORK/tmp/gpg.status"
}
# 1 つの組を取って開き、$4 のファイルに書く。$1 種類 $2 バックアップの名前 $3 送った時刻 $5 開ける鍵があるか（1・0）。
# INDEX の形を確かめ、空きを確かめ、切れ端を 1 つずつ取って大きさと SHA-256 を照らしてから流す。暗号化された組は、gpg が本当に暗号を開けた
# ことを確かめる。開けた後の大きさは切れ端の合計まで（圧縮で膨らむ中身を断る）
ec_offsite_fetch_set() {
    local kind="$1" name="$2" sent="$3" out="$4" have_key="$5" base tmp="$EC_OFFSITE_WORK/tmp" line n=0 bad=0 parts="" total=0 encrypted="" count="" st=() limit pname psize psha free_kb
    base="$kind/$EC_OFFSITE_PREFIX/${name:0:4}/${name:4:2}/$name.$sent"
    install -d -m 0700 "$tmp" || return 1
    EC_OFFSITE_GET_MAX="$EC_OFFSITE_META_MAX" ec_offsite_request GET "/$base/INDEX" "$tmp/INDEX" || { ec_warn "INDEX を取れません（${kind}。ストレージの応答 ${EC_OFFSITE_HTTP}）"; return 1; }
    while IFS= read -r line || [ -n "$line" ]; do
        n=$((n + 1))
        case "$n" in
            1) [ "$line" = format=1 ] || bad=1 ;;
            2) [ "$line" = "kind=$kind" ] || bad=1 ;;
            3) [ "$line" = "backup=$name" ] || bad=1 ;;
            4) [ "$line" = "sent=$sent" ] || bad=1 ;;
            5) [[ "$line" =~ ^server=[A-Za-z0-9._-]{1,100}$ ]] || bad=1 ;;
            6) if [[ "$line" =~ ^encrypted=(yes|no)$ ]]; then encrypted="${BASH_REMATCH[1]}"; else bad=1; fi ;;
            7) if [[ "$line" =~ ^parts=([1-9][0-9]{0,3})$ ]]; then count="${BASH_REMATCH[1]}"; else bad=1; fi ;;
            *)
                if [[ "$line" =~ ^(part-[0-9]{4})\ ([1-9][0-9]{0,10})\ ([0-9a-f]{64})$ ]] && [ "${BASH_REMATCH[1]}" = "$(printf 'part-%04d' "$((n - 7))")" ] &&
                    [ "${BASH_REMATCH[2]}" -le "$EC_OFFSITE_PART_MAX" ]; then
                    parts="$parts$line"$'\n'
                    total=$((total + BASH_REMATCH[2]))
                else
                    bad=1
                fi
                ;;
        esac
        [ "$bad" -eq 0 ] || break
    done < "$tmp/INDEX"
    rm -f "$tmp/INDEX"
    if [ "$bad" -ne 0 ] || [ -z "$count" ] || [ "$((n - 7))" -ne "$count" ]; then
        ec_warn "INDEX の形が違います（${kind}。壊れたか、後から足されたもの）"
        return 1
    fi
    if [ "$encrypted" = yes ] && [ "$have_key" != 1 ]; then
        ec_warn "このセット（${kind}）は暗号化されています。秘密鍵を --decrypt-key-file で渡す"
        return 1
    fi
    # 開ける鍵を渡したのに平文の組は、後から足された偽のものを疑って断る（送る側は、公開鍵があれば必ず暗号化する）
    if [ "$encrypted" = no ] && [ "$have_key" = 1 ]; then
        ec_warn "このセット（${kind}）は暗号化されていません（後から足されたものかもしれないので取りません。平文で送っているストレージなら --decrypt-key-file を付けずに実行する）"
        return 1
    fi
    # 空き: 切れ端 1 つ・開けたもの・広げたものが同時に載るので、INDEX の名乗る合計の 2 倍と 64 MB
    free_kb="$(df -Pk "$EC_OFFSITE_WORK" 2> /dev/null | awk 'NR == 2 {print $4}')"
    if ! [[ "$free_kb" =~ ^[0-9]+$ ]] || [ "$free_kb" -lt $((total / 512 + 65536)) ]; then
        ec_warn "空きが足りません（${kind} のセットは $((total / 1048576)) MB と記載されている。要るのは約 $((total / 524288 + 64)) MB、空きは $((${free_kb:-0} / 1024)) MB。df -h ${EC_OFFSITE_WORK}）"
        return 1
    fi
    limit=$((total + 1))
    rm -f "$tmp/gpg.status"
    {
        while IFS=' ' read -r pname psize psha; do
            [ -n "$pname" ] || continue
            EC_OFFSITE_GET_MAX="$psize" ec_offsite_request GET "/$base/$pname" "$tmp/part" || exit 1
            [ "$(wc -c < "$tmp/part" | tr -d ' ')" = "$psize" ] && [ "$(ec_integrity_sha "$tmp/part")" = "$psha" ] || exit 2
            cat "$tmp/part" || exit 1
            rm -f "$tmp/part"
        done <<< "$parts"
    } | if [ "$encrypted" = yes ]; then ec_offsite_open 2> /dev/null; else cat; fi | head -c "$limit" > "$out"
    st=("${PIPESTATUS[@]}")
    rm -f "$tmp/part"
    case "${st[0]}" in
        0) ;;
        2) ec_warn "取ったチャンクが INDEX の大きさ・指紋と違います（${kind}。壊れたか、すり替えられた）"; rm -f "$out" "$tmp/gpg.status"; return 1 ;;
        *) ec_warn "チャンクを取れません（${kind}。ストレージの応答 ${EC_OFFSITE_HTTP}）"; rm -f "$out" "$tmp/gpg.status"; return 1 ;;
    esac
    if [ "$(wc -c < "$out" | tr -d ' ')" -ge "$limit" ]; then
        rm -f "$out" "$tmp/gpg.status"
        ec_warn "復号した中身が大きすぎます（${kind}。チャンクの合計より大きい。展開すると大きくなるものは取らない）"
        return 1
    fi
    if [ "${st[1]}" -ne 0 ] || [ "${st[2]}" -ne 0 ]; then
        rm -f "$out" "$tmp/gpg.status"
        ec_warn "復号できません（${kind}。秘密鍵が違うか、中身が壊れている）"
        return 1
    fi
    if [ "$encrypted" = yes ]; then
        if ! grep -q '^\[GNUPG:\] DECRYPTION_OKAY' "$tmp/gpg.status" 2> /dev/null || grep -q -e '^\[GNUPG:\] BADMDC' -e '^\[GNUPG:\] DECRYPTION_FAILED' "$tmp/gpg.status"; then
            rm -f "$out" "$tmp/gpg.status"
            ec_warn "このセット（${kind}）は、暗号化されていると記載されていますが、復号した跡がありません（平文の包みか圧縮だけのもの。後から足されたものを疑って取りません）"
            return 1
        fi
    fi
    rm -f "$tmp/gpg.status"
    return 0
}
# DB の組の tar の中身を確かめる（決まった名前の、ふつうのファイルだけ。同じ名前が 2 つ無い。名乗る大きさの合計が tar より大きくない
# ＝穴あきで巨大に見せたものを断る）。$1 tar
ec_offsite_tar_ok() {
    local tarfile="$1" line seen=" " n=0 sum size
    while IFS= read -r line; do
        [[ "$line" == -* ]] || return 1
    done < <(tar -tvf "$tarfile" 2> /dev/null || printf 'x\n')
    while IFS= read -r line; do
        n=$((n + 1))
        [ "$n" -le 100000 ] || return 1
        [[ "$line" =~ ^([a-z0-9_]{1,63}\.dump|globals\.sql|manifest|SHA256SUMS|\.complete)$ ]] || return 1
        [[ "$seen" != *" $line "* ]] || return 1
        seen="$seen$line "
    done < <(tar -tf "$tarfile" 2> /dev/null || printf '/\n')
    [ "$n" -ge 4 ] || return 1
    sum="$(tar -tvf "$tarfile" 2> /dev/null | awk '{s += $3} END {printf "%.0f", s}')"
    size="$(wc -c < "$tarfile" | tr -d ' ')"
    [[ "$sum" =~ ^[0-9]+$ ]] && [[ "$size" =~ ^[0-9]+$ ]] && [ "$sum" -le "$size" ]
}
# 開けて広げたバックアップ $1 の、指紋（SHA256SUMS）と manifest の形を確かめる（置き場から来たものは、書く鍵を持つ人が作れる）:
#   - SHA256SUMS は決まった名前の行だけ（sha256sum -c に、外の道筋・装置のファイルを読ませない）。dump とロールは全部載っている
#   - manifest は決まった鍵と値の形だけ。制御文字を通さない（戻す前の確認の画面にそのまま出るので、端末の行を書き換えさせない）
ec_offsite_backup_shape_ok() {
    local dir="$1" line listed=" " f key val seen=" " n=0
    for f in SHA256SUMS manifest; do
        [ -f "$dir/$f" ] && [ ! -L "$dir/$f" ] && [ "$(wc -c < "$dir/$f" | tr -d ' ')" -le 1048576 ] || return 1
    done
    while IFS= read -r line || [ -n "$line" ]; do
        [[ "$line" =~ ^[0-9a-f]{64}\ \ ([a-z0-9_]+\.dump|globals\.sql|storage\.tar)$ ]] || return 1
        listed="$listed${BASH_REMATCH[1]} "
    done < "$dir/SHA256SUMS"
    for f in "$dir"/*.dump "$dir/globals.sql"; do
        [ -e "$f" ] || continue
        [[ "$listed" == *" $(basename "$f") "* ]] || return 1
    done
    # 制御文字（C0・DEL と、UTF-8 で書いた C1）
    ! LC_ALL=C grep -q '[[:cntrl:]]' "$dir/manifest" || return 1
    ! LC_ALL=C grep -q $'\xc2[\x80-\x9f]' "$dir/manifest" || return 1
    while IFS= read -r line || [ -n "$line" ]; do
        n=$((n + 1))
        [ "$n" -le 20 ] || return 1
        key="${line%%=*}"
        val="${line#*=}"
        [ "$key" != "$line" ] && [[ "$seen" != *" $key "* ]] || return 1
        seen="$seen$key "
        case "$key" in
            time) ec_valid_time "$val" || return 1 ;;
            reason) [ "${#val}" -le 200 ] || return 1 ;;
            commit) [[ "$val" =~ ^[0-9a-f]{0,40}$ ]] || return 1 ;;
            databases) [[ "$val" =~ ^[a-z0-9_]+(\ [a-z0-9_]+)*$ ]] || return 1 ;;
            prefix) [[ "$val" =~ ^[a-z0-9_]+$ ]] || return 1 ;;
            shops) [[ "$val" =~ ^[0-9]{1,6}$ ]] || return 1 ;;
            files) [ "$val" = "$EC_BACKUP_FILES_NAME" ] || [ "$val" = none ] || return 1 ;;
            admin_host) [ -z "$val" ] || ec_valid_host "$val" || return 1 ;;
            *) return 1 ;;
        esac
    done < "$dir/manifest"
    [[ "$seen" == *" time "* ]]
}
# 置き場から 1 つのバックアップを取って、バックアップの置き場に広げる。$1 バックアップの名前（空ならいちばん新しい名前）$2 開ける鍵があるか（1・0）
# $3 写真などのファイルも取るか（1・0）。取った名前を EC_OFFSITE_PULLED に入れる。指紋（SHA256SUMS）が合うものだけを置く
EC_OFFSITE_PULLED=""
ec_offsite_pull() {
    local want="$1" have_key="$2" with_files="$3" want_sent="${4:-}" sets kind name sent at flag db_sent="" db_at="" db_flag="" n=0 tmp out tried=0 have_files=0 got_files=0 want_sum host
    EC_OFFSITE_PULLED=""
    sets="$(ec_offsite_sets)" || { ec_warn "ストレージの一覧を取れません（ストレージの応答 ${EC_OFFSITE_HTTP}。読み取り用のアクセスキー・エンドポイント・バケット・サーバーの名前を確認する）"; return 1; }
    if [ -z "$want" ]; then
        # いちばん新しい＝置き場の側の時刻がいちばん新しい、時刻の食い違わない DB の組（一覧は置き場の側の時刻の古い順。名前の時刻では選ばない）
        want="$(awk '$1 == "db" && $5 == "ok" {n = $2} END {print n}' <<< "$sets")"
        [ -n "$want" ] || { ec_warn "ストレージに、そろっていて時刻の食い違わないバックアップがありません（サーバーの名前 ${EC_OFFSITE_PREFIX}。--list で一覧を見る）"; return 1; }
    fi
    while IFS=' ' read -r kind name sent at flag; do
        [ "$kind" = db ] && [ "$name" = "$want" ] || continue
        n=$((n + 1))
        if [ -z "$db_sent" ] && { [ -z "$want_sent" ] || [ "$sent" = "$want_sent" ]; }; then
            db_sent="$sent"
            db_at="$at"
            db_flag="$flag"
        fi
    done <<< "$sets"
    [ -n "$db_sent" ] || { ec_warn "ストレージに、バックアップ ${want}${want_sent:+（送った時刻 ${want_sent}）} のそろったセットがありません（--list で一覧を見る）"; return 1; }
    # 同じ名前の組が 2 つ以上あれば、黙って選ばない（送り直しのこともあるが、書く鍵を持つ人は偽の組を、本物より先にも後にも足せる）
    if [ "$n" -gt 1 ] && [ -z "$want_sent" ]; then
        ec_warn "バックアップ $want のセットがストレージに ${n} つあります。どれを取るかを --sent <送った時刻> で選んでください（ストレージの側の時刻を見て、侵入の前のものを。黙っては選びません）:"
        while IFS=' ' read -r kind name sent at flag; do
            [ "$kind" = db ] && [ "$name" = "$want" ] || continue
            ec_say "  --sent $sent   ストレージの側の時刻 ${at}$([ "$flag" = ok ] || printf '  疑わしい（時刻が食い違う）')"
        done <<< "$sets"
        return 1
    fi
    if [ "$db_flag" != ok ] && [ -z "$want_sent" ]; then
        ec_warn "バックアップ $want のセットは、名前の時刻・送った時刻・ストレージの側の時刻（${db_at}）が食い違っています（先に置かれた・日付を偽ったものを疑う）。分かったうえで取るなら --sent $db_sent を付ける"
        return 1
    fi
    [ ! -e "$EC_BACKUP/$want" ] || { ec_warn "バックアップの保存先に、同じ名前（${want}）がもうあります（消すか、名前を変えてから）"; return 1; }
    tmp="$EC_OFFSITE_WORK/tmp"
    rm -rf "$tmp"
    install -d -m 0700 -o root -g root "$EC_OFFSITE_WORK" "$tmp" "$tmp/out" || return 1
    out="$tmp/out"
    ec_offsite_fetch_set db "$want" "$db_sent" "$tmp/db.tar" "$have_key" || { rm -rf "$tmp"; return 1; }
    if ! ec_offsite_tar_ok "$tmp/db.tar"; then
        rm -rf "$tmp"
        ec_warn "取った DB のセットの中身が、バックアップの形ではありません（決まった名前のファイル以外が入っている）"
        return 1
    fi
    tar -xf "$tmp/db.tar" -C "$out" --no-same-owner --no-same-permissions || { rm -rf "$tmp"; return 1; }
    rm -f "$tmp/db.tar"
    if ! ec_offsite_backup_shape_ok "$out"; then
        rm -rf "$tmp"
        ec_warn "取ったバックアップの指紋（SHA256SUMS）か manifest の形が違います（決まった形の行だけを通す。置いていません）"
        return 1
    fi
    want_sum="$(sed -n "s/^\([0-9a-f]\{64\}\)  ${EC_BACKUP_FILES_NAME}\$/\1/p" "$out/SHA256SUMS" | head -n1)"
    if [ "$with_files" = 1 ] && [ -n "$want_sum" ]; then
        # 写真の組は、DB の組の中の指紋と合うものを使う（合わない組は飛ばして次を試す。3 つまで）
        while IFS=' ' read -r kind name sent at flag; do
            [ "$kind" = files ] && [ "$name" = "$want" ] || continue
            have_files=1
            tried=$((tried + 1))
            [ "$tried" -le 3 ] || break
            if ec_offsite_fetch_set files "$want" "$sent" "$out/$EC_BACKUP_FILES_NAME" "$have_key" && [ "$(ec_integrity_sha "$out/$EC_BACKUP_FILES_NAME")" = "$want_sum" ]; then
                got_files=1
                break
            fi
            rm -f "$out/$EC_BACKUP_FILES_NAME"
            ec_warn "写真などのファイルのセット（送った時刻 ${sent}）は、取れないか、DB のセットの中の指紋と合わないので使いません"
        done <<< "$sets"
        if [ "$got_files" -ne 1 ] && [ "$have_files" -eq 1 ]; then
            rm -rf "$tmp"
            ec_warn "写真などのファイルのセットを取れませんでした（置いていません。DB だけでよければ --no-files を付けて再実行する）"
            return 1
        fi
        [ "$got_files" -eq 1 ] || ec_warn "このバックアップは写真などのファイルつきで取られていますが、ストレージに写真のセットがありません（送り終わる前に切れた）。DB だけのバックアップとして置きます"
    fi
    if [ ! -e "$out/$EC_BACKUP_FILES_NAME" ]; then
        # 写真などのファイルの無い世代（取らない・置き場に無い）: 指紋と manifest を「写真なし」に直す（掃除が tar を外す時と同じ直し方）
        ec_backup_drop_files "$out" || { rm -rf "$tmp"; return 1; }
    fi
    if [ ! -e "$out/.complete" ] || [ ! -s "$out/ec_central.dump" ] || [ ! -s "$out/globals.sql" ] || [ ! -s "$out/SHA256SUMS" ] || [ ! -s "$out/manifest" ] ||
        ! ec_offsite_backup_shape_ok "$out" || ! (cd "$out" && ec_sha256 --status -c SHA256SUMS); then
        rm -rf "$tmp"
        ec_warn "取ったバックアップの中身が、取った時の指紋（SHA256SUMS）と違います（壊れたか、すり替えられた。置いていません）"
        return 1
    fi
    host="$(ec_kv_get "$out/manifest" admin_host 2> /dev/null || true)"
    if [ -n "$host" ] && [ "$host" != "$EC_OFFSITE_PREFIX" ]; then
        ec_warn "このバックアップの manifest の管理画面（${host}）が、ストレージのサーバーの名前（${EC_OFFSITE_PREFIX}）と違います（別のサーバーのものかもしれない）"
    fi
    chmod 0700 "$out" && chmod 0600 "$out"/* "$out/.complete" || { rm -rf "$tmp"; return 1; }
    install -d -m 0700 -o root -g root "$EC_BACKUP" || { rm -rf "$tmp"; return 1; }
    mv "$out" "$EC_BACKUP/$want" || { rm -rf "$tmp"; return 1; }
    rm -rf "$tmp"
    EC_OFFSITE_PULLED="$want"
    EC_OFFSITE_PULLED_AT="$db_at"
    EC_OFFSITE_PULLED_SENT="$db_sent"
    return 0
}

# 答えの OFFSITE_… を確かめる（何も入れる前に）。1 つも書いていなければ何もしない
ec_offsite_check_answers() {
    local key any="" f rc=0
    for key in OFFSITE_ENDPOINT OFFSITE_BUCKET OFFSITE_PREFIX OFFSITE_REGION OFFSITE_KEY_FILE OFFSITE_RECIPIENT_FILE OFFSITE_ENCRYPT OFFSITE_LOCK_CHECK; do
        any="$any$(ec_answer "$key" 2> /dev/null || true)"
    done
    [ -n "$any" ] || return 0
    ec_offsite_conf_load
    # <サーバー> の既定（管理画面のホスト）は、env ができる前は答えから
    [ -n "$EC_OFFSITE_PREFIX" ] || EC_OFFSITE_PREFIX="$(ec_answer ADMIN_HOST 2> /dev/null || true)"
    ec_offsite_conf_check || ec_die "サーバーの外へのバックアップの設定（設定ファイルの OFFSITE_…）が違います: ${EC_OFFSITE_PROBLEM}（何も入れていません）" \
        "$EC_ANSWERS の OFFSITE_ENDPOINT（例 https://<アカウントの ID>.r2.cloudflarestorage.com）・OFFSITE_BUCKET を直す。使わないなら OFFSITE_… の行を全部消す"
    f="$(ec_answer OFFSITE_KEY_FILE 2> /dev/null || true)"
    if [ -n "$f" ] && [ -e "$f" ]; then
        ec_offsite_source_ok "$f" || ec_die "ストレージのアクセスキーのファイル $f が、root の持ち物でないか、ほかの人が書けるか、リンクです（何も入れていません）" \
            "sudo chown root:root $f && sudo chmod 600 ${f}（再実行のたびにコピーするので、ほかの人が差し替えられる所から読まない）"
        ec_offsite_cred_read "$f" || rc=$?
        EC_OFFSITE_ID=""
        EC_OFFSITE_SECRET=""
        case "$rc" in
            0) ;;
            2) ec_die "ストレージのアクセスキーのファイル $f が読めません（何も入れていません）" "root で読めるファイルにするか、$EC_ANSWERS の OFFSITE_KEY_FILE を直す" ;;
            *) ec_die "ストレージのアクセスキーの形が違います（中身は出しません。何も入れていません）" \
                "access_key_id=<鍵の ID> と secret_access_key=<秘密の鍵> の 2 行（引用符を付けない）を書いたファイルを渡す" ;;
        esac
    elif [ ! -s "$EC_OFFSITE_DIR/credentials" ]; then
        ec_die "サーバーの外へのバックアップには、ストレージのアクセスキーのファイル（設定ファイルの OFFSITE_KEY_FILE）が要ります${f:+（$f がありません）}（前にコピーしたものもありません。何も入れていません）" \
            "ストレージで作った書き込み用のアクセスキーを、access_key_id=… と secret_access_key=… の 2 行で書いたファイル（root だけ読める 0600）を置いて、そのパスを $EC_ANSWERS の OFFSITE_KEY_FILE に書く"
    fi
    if [ "$EC_OFFSITE_ENCRYPT" = yes ]; then
        f="$(ec_answer OFFSITE_RECIPIENT_FILE 2> /dev/null || true)"
        if [ -n "$f" ] && [ -e "$f" ]; then
            ec_offsite_source_ok "$f" || ec_die "公開鍵のファイル $f が、root の持ち物でないか、ほかの人が書けるか、リンクです（何も入れていません）" \
                "sudo chown root:root $f && sudo chmod 644 ${f}（ほかの人が別の鍵に差し替えられると、以後のバックアップを持ち主が復号できなくなる）"
            [ -r "$f" ] && [ "$(wc -c < "$f" | tr -d ' ')" -le 1048576 ] && grep -q '^-----BEGIN PGP PUBLIC KEY BLOCK-----' "$f" ||
                ec_die "公開鍵のファイル $f が、gpg の公開鍵（-----BEGIN PGP PUBLIC KEY BLOCK----- で始まる形）ではありません（何も入れていません）" \
                    "手元で gpg --armor --export <鍵> で書き出した公開鍵を渡す（秘密の鍵は渡さない）"
        elif [ ! -s "$EC_OFFSITE_DIR/recipient.asc" ]; then
            # 公開鍵を渡していない: 管理画面で復元のキットを作ると、その鍵を採る（ec_offsite_adopt_recipient）。それまでは送らない
            ec_say "   サーバーの外へのバックアップは、管理画面の運営ダッシュボードで「復元のキット」を作ると、その鍵で暗号化して送り始めます（それまでは送りません。手元で作った gpg の公開鍵を使うなら、設定ファイルの OFFSITE_RECIPIENT_FILE に書く）"
        fi
    fi
    return 0
}
# $1 元のファイル $2 置き場の中の名前。同じ中身ならそのまま。root だけの 0400 で置く
ec_offsite_put_file() {
    local from="$1" name="$2" tmp old_umask
    old_umask="$(umask)"
    umask 077
    tmp="$(mktemp "$EC_OFFSITE_DIR/.$name.XXXXXX")" || { umask "$old_umask"; return 1; }
    umask "$old_umask"
    if ! cat "$from" > "$tmp"; then
        rm -f "$tmp"
        return 1
    fi
    if cmp -s "$tmp" "$EC_OFFSITE_DIR/$name" 2> /dev/null; then
        rm -f "$tmp"
    elif ! { chown root:root "$tmp" && chmod 0400 "$tmp" && mv -f "$tmp" "$EC_OFFSITE_DIR/$name"; }; then
        rm -f "$tmp"
        return 1
    fi
}
# 置き場の鍵と暗号の公開鍵を置き場へ写す（インストーラーから。答えの確かめの後・道具を入れた後）。答えに OFFSITE_… が無く、前に写した鍵も
# 無ければ、何も作らない。公開鍵は、前に置いたものと違う鍵には黙って入れ替えない（差し替わると、以後のバックアップを持ち主が開けない。
# 分かるのは戻す時）。指紋を見せるので、手元の鍵の指紋（gpg --fingerprint）と比べる
ec_offsite_put_secrets() {
    local key_file recipient fpr old
    key_file="$(ec_answer OFFSITE_KEY_FILE 2> /dev/null || true)"
    recipient="$(ec_answer OFFSITE_RECIPIENT_FILE 2> /dev/null || true)"
    if [ -z "$(ec_answer OFFSITE_ENDPOINT 2> /dev/null || true)" ] && ! ec_offsite_configured; then return 0; fi
    install -d -m 0700 -o root -g root "$EC_OFFSITE_DIR" "$EC_OFFSITE_WORK"
    chown root:root "$EC_OFFSITE_DIR" "$EC_OFFSITE_WORK"
    chmod 0700 "$EC_OFFSITE_DIR" "$EC_OFFSITE_WORK"
    if [ -n "$key_file" ] && [ -e "$key_file" ]; then
        ec_offsite_source_ok "$key_file" || ec_die "ストレージのアクセスキーのファイル $key_file が、root の持ち物でないか、ほかの人が書けるか、リンクです" "sudo chown root:root $key_file && sudo chmod 600 $key_file"
        ec_offsite_cred_read "$key_file" || ec_die "ストレージのアクセスキーの形が違うか、読めません（中身は出しません）" "鍵のファイルを置き直して再実行する"
        EC_OFFSITE_ID=""
        EC_OFFSITE_SECRET=""
        ec_offsite_put_file "$key_file" credentials || ec_die "ストレージのアクセスキーを $EC_OFFSITE_DIR に置けません" "空き容量と権限を確認して再実行する"
    fi
    ec_offsite_configured || ec_die "ストレージのアクセスキー（設定ファイルの OFFSITE_KEY_FILE）がありません" "鍵のファイルを置き直して再実行する"
    if [ -n "$recipient" ] && [ -e "$recipient" ]; then
        ec_offsite_source_ok "$recipient" || ec_die "公開鍵のファイル $recipient が、root の持ち物でないか、ほかの人が書けるか、リンクです" "sudo chown root:root $recipient && sudo chmod 644 $recipient"
        ec_offsite_recipient_ok "$recipient" || ec_die "公開鍵（${recipient}）で暗号化できません（gpg の公開鍵でないか、暗号化に使える鍵が入っていない・期限が切れている）" \
            "手元で gpg --armor --export <鍵> で書き出し直して再実行する"
        if [ -s "$EC_OFFSITE_DIR/recipient.asc" ] && ! cmp -s "$recipient" "$EC_OFFSITE_DIR/recipient.asc"; then
            fpr="$(ec_offsite_recipient_fpr "$recipient" || printf '読めない')"
            old="$(ec_offsite_recipient_fpr "$EC_OFFSITE_DIR/recipient.asc" || printf '読めない')"
            ec_die "公開鍵（${recipient}。指紋 ${fpr}）が、前に置いたもの（指紋 ${old}）と違います。黙っては置き換えません" \
                "鍵を替えるつもりなら sudo rm $EC_OFFSITE_DIR/recipient.asc の後に再実行する（以後のバックアップは新しい鍵でしか復号できない）" \
                "替えるつもりが無ければ、$recipient がだれかに差し替えられていないかを調べる"
        fi
        ec_offsite_put_file "$recipient" recipient.asc || ec_die "公開鍵を $EC_OFFSITE_DIR に置けません" "空き容量と権限を確認して再実行する"
    fi
    ec_offsite_conf_load
    if [ "$EC_OFFSITE_ENCRYPT" = no ]; then
        ec_warn "サーバーの外へのバックアップを暗号化しません（OFFSITE_ENCRYPT=no）。ストレージのアクセスキーを持つ人は、バックアップの中の個人情報を読めます"
    elif fpr="$(ec_offsite_recipient_fpr "$EC_OFFSITE_DIR/recipient.asc")"; then
        ec_ok "公開鍵の指紋 ${fpr}（手元の鍵の指紋と同じかを確認する。違えば、戻す時に復号できない）"
    fi
    ec_offsite_curl_ok || ec_warn "この curl（$(ec_offsite_curl_version)）では S3 互換のストレージへ送れません（${EC_OFFSITE_CURL_MIN} 以上が要る。Ubuntu 22.04 の curl は署名が通らない）。送る回は失敗として残ります"
    ec_ok "サーバーの外へのバックアップの鍵は $EC_OFFSITE_DIR の中だけ（root だけが読める 0400。どのコンテナにも渡さない。元のファイルは消してよい。確認は sudo ec-sugar offsite test）"
}

# サーバーの外へ送る unit（ec-sugar-offsite.service・.timer）を入れて、10 分ごとのタイマーを有効にする。置き場を設定していないサーバーでは、
# unit の条件（ConditionPathExists。鍵のファイルがあること）でタイマーそのものが動かない（記録にも何も出ない）。設定した後は、ここ（install.sh の
# 流し直し・install-units）か sudo systemctl start ec-sugar-offsite.timer で動き出す。
# 入れる道具に unit が無い（この仕組みの前のバージョンへ更新・ロールバックした）なら、タイマーを止めて unit を消す
# （知らない使い方の ec-sugar offsite を 10 分ごとに呼ばない）。置き場を設定していたら、送られなくなることを知らせる
ec_install_offsite_units() {
    local from="$1" f
    if [ -f "$from/systemd/ec-sugar-offsite.service" ] && [ -f "$from/systemd/ec-sugar-offsite.timer" ]; then
        for f in ec-sugar-offsite.service ec-sugar-offsite.timer; do
            ec_plain_file "$from/systemd/$f" "$from" || ec_die "$from/systemd/$f がふつうのファイルでないか、ディレクトリの外を指すリンクです（運用のスクリプトを置き換えない）"
            install -m 0644 -o root -g root "$from/systemd/$f" "$EC_UNIT_DIR/.$f.new"
            mv -f "$EC_UNIT_DIR/.$f.new" "$EC_UNIT_DIR/$f"
        done
        systemctl daemon-reload
        systemctl enable --now ec-sugar-offsite.timer > /dev/null 2>&1 || ec_warn "サーバーの外へ送るタイマー（ec-sugar-offsite.timer）を入れられませんでした（sudo systemctl status ec-sugar-offsite.timer で原因を見る）"
        return 0
    fi
    if [ -e "$EC_UNIT_DIR/ec-sugar-offsite.timer" ] || [ -e "$EC_UNIT_DIR/ec-sugar-offsite.service" ]; then
        systemctl disable --now ec-sugar-offsite.timer > /dev/null 2>&1 || true
        rm -f "$EC_UNIT_DIR/ec-sugar-offsite.timer" "$EC_UNIT_DIR/ec-sugar-offsite.service"
        systemctl daemon-reload
        if ec_offsite_configured; then
            ec_warn "入れたバージョンにはサーバーの外へのバックアップがないので、ec-sugar-offsite.timer を止めました（バックアップはサーバーの外へ送られなくなります。手元のバックアップは今までどおり）"
        fi
    fi
    return 0
}

# ---------- 復元のキット（ec-sugar offsite pull --kit・restore --kit。ADR 0110 の「作って決めたこと」） ----------
# サーバーの外のバックアップから戻すのに要る、サーバーに置いていない 3 つ（置き場を読む鍵・暗号の開ける鍵と合い言葉・APP_KEY の控え）を、
# 持ち主が手元で 1 つのファイルにまとめたもの（作る道具は deploy/recovery-kit）。外側は gpg の共通鍵の暗号（合い言葉）、中は JSON の 1 つのオブジェクト:
#   {"format":1,"kind":"ec-sugar-recovery-kit","created_at":…,"server":…,"offsite":{"endpoint","bucket","region","access_key_id","secret_access_key"},
#    "decrypt_key":…,"decrypt_key_passphrase":…,"app_key":…}
# 決まり:
#   - キットのファイルも、合い言葉のファイルも、root だけが読めるもの（ほかの人が読めるものは断る）
#   - 合い言葉は端末で聞く（画面に出さない）か、--kit-passphrase-file のファイルから。引数・環境変数に載せない
#   - 開いた中身は錠の置き場（/run/ec-sugar。root だけ・ディスクに残らない）の下の一時の置き場にだけ置き、呼ぶ側が終わり・失敗・割り込みで消す（ec_kit_drop）
#   - 知らない欄・形の違う値・format が 1 でないものは、中身を出さずに断る。値の形は、ばらで渡す時と同じ確かめ（入口・バケット・鍵・APP_KEY）
EC_KIT_MAX=1048576
# 開いたキットの一時の置き場（中に credentials・decrypt-key.asc・passphrase〔合い言葉つきの鍵の時だけ〕・app-key）と、秘密でない欄
EC_KIT_DIR=""
EC_KIT_SERVER=""
EC_KIT_ENDPOINT=""
EC_KIT_BUCKET=""
EC_KIT_REGION=""

# ファイル $1 が、ふつうのファイルで、読めて、ほかの人（グループとその他）から読めないか
ec_kit_private_file() {
    [ -f "$1" ] && [ -r "$1" ] || return 1
    [ -z "$(find "$1" -maxdepth 0 \( -perm -040 -o -perm -004 \) 2> /dev/null)" ]
}
# 開いたキットの一時の置き場を消す（何度呼んでもよい）
ec_kit_drop() {
    EC_KIT_SERVER=""
    EC_KIT_ENDPOINT=""
    EC_KIT_BUCKET=""
    EC_KIT_REGION=""
    [ -n "$EC_KIT_DIR" ] || return 0
    gpgconf --homedir "$EC_KIT_DIR/gnupg" --kill all > /dev/null 2>&1 || true
    rm -rf "$EC_KIT_DIR"
    EC_KIT_DIR=""
}
# 合い言葉を聞ける端末から流しているか（確かめの台本が差し替える）
ec_kit_tty() { [ -t 0 ]; }
# キット $1 を開いて、一時の置き場 EC_KIT_DIR に中身をばらして置く。$2 キットの合い言葉のファイル（空なら端末で聞く）。
# 断る時は理由を出して止める（中身は出さない。一時の置き場は、呼ぶ側の trap の ec_kit_drop が消す）
ec_kit_open() {
    local kit="$1" pass_file="${2:-}" size pass="" st=() old_umask dir json line
    command -v gpg > /dev/null 2>&1 || ec_die "gpg がありません（復元のキットを復号できない）" "sudo apt-get install -y gnupg"
    command -v jq > /dev/null 2>&1 || ec_die "jq がありません（復元のキットを読めない）" "sudo apt-get install -y jq"
    [ -f "$kit" ] && [ -r "$kit" ] || ec_die "復元のキットのファイル ${kit} が読めません"
    ec_kit_private_file "$kit" || ec_die "復元のキットのファイル ${kit} がほかの利用者から読めます" "sudo chown root:root ${kit} と sudo chmod 600 ${kit} にしてから再実行する"
    size="$(wc -c < "$kit" 2> /dev/null | tr -d ' ')"
    [[ "$size" =~ ^[0-9]+$ ]] && [ "$size" -ge 1 ] && [ "$size" -le "$EC_KIT_MAX" ] || ec_die "復元のキットのファイル ${kit} の大きさが違います（空か、大きすぎる。復元のキットでない）"
    if [ -n "$pass_file" ]; then
        [ -f "$pass_file" ] && [ -r "$pass_file" ] || ec_die "キットのパスフレーズのファイル ${pass_file} が読めません"
        ec_kit_private_file "$pass_file" || ec_die "キットのパスフレーズのファイル ${pass_file} がほかの利用者から読めます" "sudo chmod 600 ${pass_file} にしてから再実行する"
    else
        ec_kit_tty || ec_die "キットのパスフレーズを聞けません（端末から実行していない）" "端末から実行するか、パスフレーズを 1 行で書いた root だけが読める 0600 のファイルを --kit-passphrase-file で渡す"
        IFS= read -rs -p "   復元のキットのパスフレーズ（画面には出ません）: " pass || pass=""
        printf '\n' >&2
        [ -n "$pass" ] || ec_die "キットのパスフレーズが空です"
    fi
    ec_lock_dir || ec_die "ロックのディレクトリ $(dirname "$EC_LOCK") を作れません"
    old_umask="$(umask)"
    umask 077
    EC_KIT_DIR="$(mktemp -d "$(dirname "$EC_LOCK")/kit.XXXXXX")" || { EC_KIT_DIR=""; ec_die "復元のキットを復号する一時ディレクトリを作れません"; }
    dir="$EC_KIT_DIR"
    json="$dir/kit.json"
    mkdir "$dir/gnupg" || ec_die "復元のキットを復号する一時ディレクトリを作れません"
    # 合い言葉は、ファイルの道筋（--passphrase-file）か、bash の中の printf からの管（--passphrase-fd）で渡す。開けた大きさには上限
    # （管が失敗しても、ここで終わらせない: errexit と pipefail の下では、gpg が合い言葉の誤りで失敗した時に、下の案内を出す前に黙って終わる）
    if [ -n "$pass_file" ]; then
        "${EC_OFFSITE_GPG[@]}" --homedir "$dir/gnupg" --pinentry-mode loopback --status-fd 3 --passphrase-file "$pass_file" --decrypt < "$kit" 2> /dev/null 3> "$dir/gpg.status" |
            head -c "$((EC_KIT_MAX + 1))" > "$json" && st=(0 0) || st=("${PIPESTATUS[@]}")
    else
        "${EC_OFFSITE_GPG[@]}" --homedir "$dir/gnupg" --pinentry-mode loopback --status-fd 3 --passphrase-fd 4 --decrypt < "$kit" 2> /dev/null 3> "$dir/gpg.status" 4< <(printf '%s\n' "$pass") |
            head -c "$((EC_KIT_MAX + 1))" > "$json" && st=(0 0) || st=("${PIPESTATUS[@]}")
        pass=""
    fi
    gpgconf --homedir "$dir/gnupg" --kill all > /dev/null 2>&1 || true
    # gpg --decrypt は、暗号化されていない包みも中身を出して 0 で終わるので、本当に暗号を開けた跡（DECRYPTION_OKAY）を確かめる
    if [ "${st[0]}" -ne 0 ] || ! grep -q '^\[GNUPG:\] DECRYPTION_OKAY' "$dir/gpg.status" 2> /dev/null ||
        grep -q -e '^\[GNUPG:\] BADMDC' -e '^\[GNUPG:\] DECRYPTION_FAILED' "$dir/gpg.status" 2> /dev/null; then
        ec_die "復元のキット ${kit} を復号できません（パスフレーズが違うか、復元のキット〔パスフレーズで暗号化したファイル〕でない。中身は出しません）" "手元で recovery-kit check <キットのファイル> を実行して、パスフレーズと中身を確認する"
    fi
    rm -f "$dir/gpg.status"
    [ "$(wc -c < "$json" | tr -d ' ')" -le "$EC_KIT_MAX" ] || ec_die "復元のキット ${kit} の中身が大きすぎます（復元のキットでない）"
    # 形: JSON の 1 つのオブジェクト・決まった欄だけ・値は文字（制御文字なし。開ける鍵だけは改行つきの ASCII）
    jq -e -s '
        def plain: type == "string" and (explode | all(. >= 32 and . != 127));
        def armor: type == "string" and (explode | all(. == 10 or . == 13 or (. >= 32 and . < 127)));
        length == 1 and (.[0] |
            type == "object"
            and ((keys - ["app_key", "created_at", "decrypt_key", "decrypt_key_passphrase", "format", "kind", "offsite", "server"]) == [])
            and ((["app_key", "created_at", "decrypt_key", "format", "kind", "server"] - keys) == [])
            and (.format | type == "number" and . == 1)
            and .kind == "ec-sugar-recovery-kit"
            and (.created_at | plain) and (.server | plain) and (.app_key | plain) and (.decrypt_key | armor)
            and ((if has("decrypt_key_passphrase") then .decrypt_key_passphrase else "" end) | plain)
            and (if has("offsite") then (.offsite | type == "object" and (keys == ["access_key_id", "bucket", "endpoint", "region", "secret_access_key"]) and all(.[]; plain)) else true end))
    ' "$json" > /dev/null 2>&1 ||
        ec_die "復元のキット ${kit} の形が違います（知らないフィールドがある・フィールドが足りない・format が 1 でない。中身は出しません）" "手元で recovery-kit check <キットのファイル> を実行する。新しいツールで作ったキットなら、このサーバーの EC-SUGAR を更新する"
    # 置き場と読む鍵（offsite）は無いキットもある（管理画面で作ったもの）。無ければ EC_KIT_ENDPOINT は空で、credentials のファイルも作らない
    local has_offsite=0
    jq -e 'has("offsite")' "$json" > /dev/null 2>&1 && has_offsite=1
    EC_KIT_SERVER="$(jq -r '.server' "$json")"
    if [ "$has_offsite" -eq 1 ]; then
        EC_KIT_ENDPOINT="$(jq -r '.offsite.endpoint' "$json")"
        EC_KIT_ENDPOINT="${EC_KIT_ENDPOINT%/}"
        EC_KIT_BUCKET="$(jq -r '.offsite.bucket' "$json")"
        EC_KIT_REGION="$(jq -r '.offsite.region' "$json")"
        jq -r '"access_key_id=\(.offsite.access_key_id)", "secret_access_key=\(.offsite.secret_access_key)"' "$json" > "$dir/credentials" || ec_die "復元のキット ${kit} の中身を取り出せません"
    fi
    jq -j '.decrypt_key' "$json" > "$dir/decrypt-key.asc" &&
        jq -r '.app_key' "$json" > "$dir/app-key" &&
        jq -r '.decrypt_key_passphrase // ""' "$json" > "$dir/passphrase" || ec_die "復元のキット ${kit} の中身を取り出せません"
    rm -f "$json"
    [ "$(wc -c < "$dir/passphrase" | tr -d ' ')" -gt 1 ] || rm -f "$dir/passphrase"
    umask "$old_umask"
    # 値の形（ばらで渡す時と同じ確かめ）
    ec_offsite_valid_prefix "$EC_KIT_SERVER" || ec_die "復元のキットの中の、サーバーの名前の形が違います"
    if [ "$has_offsite" -eq 1 ]; then
        ec_offsite_valid_endpoint "$EC_KIT_ENDPOINT" || ec_die "復元のキットの中の、ストレージのエンドポイントが https://<名前> の形でありません"
        ec_offsite_valid_bucket "$EC_KIT_BUCKET" || ec_die "復元のキットの中の、バケットの名前の形が違います"
        ec_offsite_valid_region "$EC_KIT_REGION" || ec_die "復元のキットの中の、リージョンの形が違います"
        ec_offsite_cred_read "$dir/credentials" || { EC_OFFSITE_ID=""; EC_OFFSITE_SECRET=""; ec_die "復元のキットの中の、ストレージの読み取り用のアクセスキーの形が違います（中身は出しません）"; }
        EC_OFFSITE_ID=""
        EC_OFFSITE_SECRET=""
    fi
    IFS= read -r line < "$dir/app-key" || true
    [[ "$line" =~ ^base64:[A-Za-z0-9+/]{43}=$ ]] || { line=""; ec_die "復元のキットの中の、APP_KEY の形が違います（中身は出しません）"; }
    IFS= read -r line < "$dir/decrypt-key.asc" || true
    [ "$line" = '-----BEGIN PGP PRIVATE KEY BLOCK-----' ] || { line=""; ec_die "復元のキットの中の、秘密鍵が gpg の秘密鍵の形でありません（中身は出しません）"; }
    line=""
    return 0
}

# ---------- バックアップから戻す（ec-sugar restore と rollback --restore-backup。ADR 0082） ----------
# 戻す前に、バックアップそのものを確かめる（何も変えない）。そろっているか・DB の名前がこのサーバーの前置きと合うか・指紋が合うか・
# ファイルの tar の名前に外へ出る道筋（.. や / で始まるもの）が無いか。$1 置き場。駄目なら理由を出して 1
ec_restore_check_backup() {
    local dir="$1" prefix f name line listed=" " tarfile="$1/$EC_BACKUP_FILES_NAME"
    [ -e "$dir/.complete" ] || { ec_warn "バックアップ $(basename "$dir") は途中で切れています（.complete が無い）"; return 1; }
    [ -s "$dir/ec_central.dump" ] || { ec_warn "バックアップに中央の DB（ec_central.dump）がありません"; return 1; }
    [ -s "$dir/globals.sql" ] || { ec_warn "バックアップにロール（globals.sql）がありません"; return 1; }
    prefix="$(ec_env_get TENANT_DB_PREFIX || true)"
    [[ "$prefix" =~ ^[a-z0-9_]+$ ]] || { ec_warn "env の TENANT_DB_PREFIX が読めません"; return 1; }
    for f in "$dir"/*.dump; do
        name="$(basename "$f" .dump)"
        [[ "$name" =~ ^[a-z0-9_]+$ ]] || { ec_warn "バックアップの DB の名前が想定の外です（${name}）"; return 1; }
        [ "$name" = ec_central ] || [[ "$name" == "$prefix"* ]] ||
            { ec_warn "このサーバーの店の DB のプレフィックス（${prefix}）と違う DB（${name}）が入っています（env の TENANT_DB_PREFIX を取った所とそろえる）"; return 1; }
    done
    if [ -e "$dir/SHA256SUMS" ]; then
        # 決まった名前の行だけ（ほかの名前の行があれば、すり替えを疑って止める）
        while IFS= read -r line || [ -n "$line" ]; do
            if [[ "$line" =~ ^[0-9a-f]{64}\ \ ([a-z0-9_]+\.dump|globals\.sql|storage\.tar)$ ]]; then
                listed="$listed${BASH_REMATCH[1]} "
            else
                ec_warn "指紋（SHA256SUMS）に想定の外の行があります"
                return 1
            fi
        done < "$dir/SHA256SUMS"
        for f in "$dir"/*.dump "$dir/globals.sql" "$tarfile"; do
            [ -e "$f" ] || continue
            [[ "$listed" == *" $(basename "$f") "* ]] || { ec_warn "$(basename "$f") が指紋（SHA256SUMS）に載っていません"; return 1; }
        done
        if ! (cd "$dir" && ec_sha256 --status -c SHA256SUMS); then
            ec_warn "バックアップの中身が、取った時の指紋と違います（壊れたか、書き換えられた）"
            return 1
        fi
    else
        ec_warn "このバックアップには指紋（SHA256SUMS）がありません（指紋を付けるようになる前のバージョンで取ったもの）。DB が読めるかだけを確認します"
        for f in "$dir"/*.dump; do
            ec_dc exec -T db pg_restore --list < "$f" > /dev/null 2>&1 || { ec_warn "$(basename "$f") が読めません"; return 1; }
        done
    fi
    if [ -e "$tarfile" ]; then
        ec_files_names_ok "$tarfile" || return 1
    fi
    return 0
}
# tar の中の名前が storage の下に収まるか（絶対の道筋・.. の段・制御文字を断る。日本語などの名前は通す〔ダウンロード販売のファイルの名前〕）と、リンクが無いか
ec_files_names_ok() {
    local entry kinds
    while IFS= read -r entry; do
        case "/$entry/" in
            //* | */../*) ec_warn "写真などのファイルの tar に、storage の外を指す名前があります（$(printf '%s' "$entry" | cut -c1-80)）"; return 1 ;;
        esac
        if [[ "$entry" =~ [[:cntrl:]] ]]; then
            ec_warn "写真などのファイルの tar に、制御文字を含む名前があります"
            return 1
        fi
    done < <(tar -tf "$1")
    # リンク（シンボリック・ハード）は作らない（置き場の外や、ほかの店の写真を指させない。こちらで取った tar には無い）
    kinds="$(tar -tvf "$1" | cut -c1 | sort -u | tr -d '\n')"
    if [[ "$kinds" == *[lh]* ]]; then
        ec_warn "写真などのファイルの tar に、リンク（シンボリック・ハード）があります"
        return 1
    fi
    return 0
}

# globals.sql（pg_dumpall --globals-only）から、店ごとの DB のロールの作成・属性とパスワード・アプリのロール（ec_app）への付与だけを、
# 決まった形の行だけ選んで流す。管理者（ec）・アプリ（ec_app）・組み込み（pg_*）の行は流さない（写しのサーバーのパスワードを本番の値で
# 上書きしない）。強い属性（SUPERUSER など）を付ける行があれば、すり替えを疑って止める。もうあるロールは作らない（属性とパスワードは合わせる）
ec_restore_roles() {
    local file="$1" existing line name sql="" attrs
    local re_name='("[A-Za-z0-9_]{1,63}"|[a-z_][a-z0-9_]{0,62})'
    local re_create="^CREATE ROLE ${re_name};\$"
    local re_alter="^ALTER ROLE ${re_name} WITH(( [A-Z]+)*)( PASSWORD 'SCRAM-SHA-256\\\$[A-Za-z0-9+/=:\$]+')?;\$"
    local re_grant="^GRANT ${re_name} TO \"?ec_app\"?( WITH (INHERIT|SET|ADMIN) (TRUE|FALSE|OPTION)(, (INHERIT|SET|ADMIN) (TRUE|FALSE|OPTION))*)?( GRANTED BY \"?[a-z_][a-z0-9_]*\"?)?;\$"
    existing=" $(ec_dc exec -T db psql -U ec -d postgres -Atc 'SELECT rolname FROM pg_roles' | tr '\n' ' ') " || return 1
    while IFS= read -r line || [ -n "$line" ]; do
        if [[ "$line" =~ $re_create ]]; then
            name="${BASH_REMATCH[1]//\"/}"
            ec_restore_role_ok "$name" || continue
            [[ "$existing" == *" $name "* ]] && continue
        elif [[ "$line" =~ $re_alter ]]; then
            name="${BASH_REMATCH[1]//\"/}"
            attrs=" ${BASH_REMATCH[2]} "
            ec_restore_role_ok "$name" || continue
            if [[ "$attrs" =~ \ (SUPERUSER|CREATEROLE|CREATEDB|REPLICATION|BYPASSRLS)\  ]]; then
                ec_warn "ロール ${name} に特権の属性（${BASH_REMATCH[1]}）を付ける行があります（店の DB のロールには付けない。すり替えを疑って止めました）"
                return 1
            fi
        elif [[ "$line" =~ $re_grant ]]; then
            name="${BASH_REMATCH[1]//\"/}"
            ec_restore_role_ok "$name" || continue
        else
            continue
        fi
        sql="$sql$line"$'\n'
    done < "$file"
    [ -n "$sql" ] || return 0
    # 付与がもうある時の知らせ（NOTICE）は出さない
    printf 'SET client_min_messages = warning;\n%s' "$sql" | ec_dc exec -T db psql -U ec -d postgres -q -v ON_ERROR_STOP=1 > /dev/null
}
ec_restore_role_ok() {
    case "$1" in
        ec | ec_app | postgres | pg_*) return 1 ;;
    esac
    return 0
}

# DB を戻す（ロール → 中央 → 店の順。名前の順で ec_central が先）。戻せた DB は EC_RESTORE_DONE に足し、止まった所は EC_RESTORE_FAILED に残す
ec_restore_databases() {
    local dir="$1" f db prefix extra=""
    EC_RESTORE_FAILED="店の DB のロール（globals.sql）"
    ec_restore_roles "$dir/globals.sql" || return 1
    for f in "$dir"/*.dump; do
        db="$(basename "$f" .dump)"
        [[ "$db" =~ ^[a-z0-9_]+$ ]] || { EC_RESTORE_FAILED="${db}（名前が想定の外）"; return 1; }
        ec_say "$db を戻しています"
        EC_RESTORE_FAILED="$db"
        ec_dc exec -T db pg_restore -U ec -d postgres --clean --if-exists --create --exit-on-error < "$f" || return 1
        EC_RESTORE_DONE="${EC_RESTORE_DONE:+${EC_RESTORE_DONE}・}DB $db"
    done
    EC_RESTORE_FAILED=""
    # バックアップの後に作った店の DB は消さない（中央の店の一覧からは外れる。中身を確かめてから手で消す）
    prefix="$(ec_env_get TENANT_DB_PREFIX)"
    for db in $(ec_dc exec -T db psql -U ec -d postgres -Atc "SELECT datname FROM pg_database WHERE left(datname, length('$prefix')) = '$prefix' ORDER BY datname" 2> /dev/null || true); do
        [ -e "$dir/$db.dump" ] || extra="$extra $db"
    done
    [ -z "$extra" ] || ec_warn "バックアップに無い店の DB が残っています（${extra# }。バックアップの後に作った店。中央の店の一覧からは外れたので、中身を確認してから手動で消す）"
    return 0
}

# 写真などのファイルを戻す（app は止めてから。使い捨ての app のコンテナで広げて入れ替える）
ec_restore_files() {
    local tarfile="$1/$EC_BACKUP_FILES_NAME"
    EC_RESTORE_FAILED="写真などのファイル"
    ec_dc run --rm --no-deps -T app sh -c "$EC_FILES_UNPACK" < "$tarfile" 2> >(ec_quiet_run >&2) || return 1
    EC_RESTORE_FAILED=""
    EC_RESTORE_DONE="${EC_RESTORE_DONE:+${EC_RESTORE_DONE}・}写真などのファイル"
}

# APP_KEY を、root だけが読めるファイルから読む（引数・画面・記録に出さない）。形が違えば中身を出さずに止める。読めたら EC_RESTORE_KEY に
ec_restore_key_read() {
    local file="$1" key=""
    [ -f "$file" ] && [ -r "$file" ] || ec_die "APP_KEY のファイル ${file} が読めません"
    [ -z "$(find "$file" -maxdepth 0 -perm -004 2> /dev/null)" ] || ec_die "APP_KEY のファイル ${file} がほかの利用者から読めます" "sudo chmod 600 ${file} にしてから再実行する"
    # 設定のファイルの写し（sudo ec-sugar app-key export の控え）をそのまま渡せるように、APP_KEY= の行があればそれを使う。
    # 無ければ 1 行目（鍵だけを書いたファイル）。中身は画面にも記録にも出さない
    local line=""
    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in APP_KEY=*) key="$line"; break ;; esac
    done < "$file"
    [ -n "$key" ] || IFS= read -r key < "$file" || true
    key="${key#APP_KEY=}"
    key="${key%$'\r'}"
    if [[ "$key" == \"*\" ]] && [ "${#key}" -ge 2 ]; then key="${key:1:${#key}-2}"; fi
    [[ "$key" =~ ^base64:[A-Za-z0-9+/]{43}=$ ]] || ec_die "APP_KEY のファイル ${file} の形が違います（base64: で始まる 1 行。中身は出しません）"
    EC_RESTORE_KEY="$key"
}

# 隔離した写しにしてよいサーバーか（Cloudflare Tunnel の形〔外から開ける〕と、隔離の形を持たないバージョンは断る）
ec_isolate_precheck() {
    if [ "$(ec_front)" = tunnel ] || [ -e "$EC_TUNNEL_DIR/token" ]; then
        ec_die "Cloudflare Tunnel の構成のサーバーは、隔離した複製環境にできません（外から開ける構成＝本番の見込み）" \
            "複製環境は使い捨ての VM に MODE=staging・FRONT=caddy で入れてから実行する（docs/development/restore-drill.md）"
    fi
    local dir
    dir="$(ec_compose_dir)" || ec_die "compose のディレクトリを決められません" "sudo ec-sugar status で状態を見る"
    [ -f "$dir/docker-compose.isolated.yml" ] || ec_die "入っているバージョンに隔離の構成（docker-compose.isolated.yml）がありません" "隔離の構成が入ったバージョンに更新してから実行する"
}
# 隔離した写しの env: 外のサービスの鍵を空に、メールは Mailpit（MODE=staging）か送らない（array）に、本番の Stripe の鍵を置けなくする。
# 印は compose（docker-compose.isolated.yml）でも app・queue・scheduler に渡す（env を書き換えられても外れない）
ec_isolate_env() {
    local key
    ec_env_set EC_SUGAR_ISOLATED true
    if [ "$(ec_mode)" = staging ]; then
        ec_env_set MAIL_MAILER smtp
        ec_env_set MAIL_HOST mailpit
        ec_env_set MAIL_PORT 1025
        ec_env_set MAIL_SCHEME null
        ec_env_set MAIL_USERNAME null
        ec_env_set MAIL_PASSWORD null
    else
        ec_env_set MAIL_MAILER array
        ec_env_set MAIL_PASSWORD null
    fi
    ec_env_set PAYMENT_LIVE_KEYS_ALLOWED false
    for key in STRIPE_PLATFORM_SECRET_KEY STRIPE_CONNECT_WEBHOOK_SECRET POSTMARK_API_KEY RESEND_API_KEY \
        SLACK_BOT_USER_OAUTH_TOKEN LOG_SLACK_WEBHOOK_URL AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY; do
        if ec_kv_raw "$EC_ENV_FILE" "$key" > /dev/null; then ec_kv_set "$EC_ENV_FILE" "$key" ""; fi
    done
}
ec_is_isolated() { [ -e "$EC_STATE/isolated" ]; }

# 戻すときの止まり方。どこまで戻ったか・戻していない所・戻し方を出して止める（$1 何で止まったか）
ec_restore_fail() {
    printf '\n%s止めました%s: %s\n' "$EC_C_ERR" "$EC_C_END" "$1" >&2
    printf '   戻した所: %s\n' "${EC_RESTORE_DONE:-まだ何も戻していません}" >&2
    [ -z "${EC_RESTORE_FAILED:-}" ] || printf '   止まった所: %s（半端なことがある）\n' "$EC_RESTORE_FAILED" >&2
    printf '   app・リバースプロキシ・キュー・定期の処理は止まったままです\n' >&2
    printf '   直し方: 原因を直して、同じ sudo ec-sugar restore %s をもう一度実行する（初めから同じ所へ戻る）\n' "$EC_RESTORE_NAME" >&2
    if [ -n "${EC_RESTORE_SAFETY:-}" ]; then
        printf '   直し方: 戻す前の状態へ戻すなら sudo ec-sugar restore %s（戻す前に取ったバックアップ）\n' "$EC_RESTORE_SAFETY" >&2
    fi
    printf '   直し方: どちらもせずに今のまま動かすなら sudo systemctl restart ec-sugar（DB や写真が半端なことがある。sudo ec-sugar restore-check で確認する）\n' >&2
    exit 1
}

# 戻す本体（確かめ・確認・鍵は呼ぶ側の cmd_restore）。$1 置き場 $2 隔離するか（1/0）$3 ファイルも戻すか（1/0）$4 APP_KEY を入れるか（1/0。EC_RESTORE_KEY）
ec_restore_run() {
    local dir="$1" isolated="$2" files="$3" set_key="$4" image cur
    EC_RESTORE_NAME="$(basename "$dir")"
    EC_RESTORE_DONE=""
    EC_RESTORE_FAILED=""
    EC_RESTORE_SAFETY=""
    if [ "$files" = 1 ] && [ ! -e "$dir/$EC_BACKUP_FILES_NAME" ]; then
        ec_warn "このバックアップには写真などのファイルが入っていません（DB だけを戻し、今のファイルはそのまま）"
        files=0
    fi
    image="$(ec_env_get EC_SUGAR_IMAGE)"
    cur="$(ec_state_get current_commit || true)"

    ec_step "今の状態のバックアップ（戻し直せるように）"
    # これから戻すバックアップは、バックアップの後の片付け（7 日より古いものを消す）から外す
    EC_BACKUP_PROTECT="$dir"
    ec_backup "バックアップから戻す前（${EC_RESTORE_NAME} へ）" "$files" ||
        ec_die "今の状態のバックアップを取れないので、戻すのを止めました（何も変えていません）" "sudo ec-sugar backup で原因を見る（ディスクの空き: df -h ${EC_BACKUP}）"
    EC_RESTORE_SAFETY="$(basename "$EC_BACKUP_LAST")"

    ec_step "app・リバースプロキシ・キュー・定期の処理を止める"
    ec_dc stop app queue scheduler web > /dev/null 2>&1 || ec_restore_fail "app などを止められませんでした（sudo ec-sugar status で状態を見る）"

    # 隔離と鍵は、戻したデータで何かが起きる前に（止めた後・DB を戻す前に）入れる
    if [ "$isolated" = 1 ]; then
        ec_step "隔離した複製環境にする（外へ出るネットワークを外す・メールは $([ "$(ec_mode)" = staging ] && echo 'Mailpit へ' || echo '送らない')・外のサービスの鍵を空に・定期の処理を止める）"
        EC_RESTORE_FAILED="隔離の設定（env）"
        ec_isolate_env || ec_restore_fail "隔離の設定を env に書けませんでした"
        ec_state_put isolated "$(ec_now) ${EC_RESTORE_NAME}" || ec_restore_fail "隔離の印（${EC_STATE}/isolated）を置けませんでした"
        EC_RESTORE_FAILED=""
        EC_RESTORE_DONE="隔離の設定"
    fi
    if [ "$set_key" = 1 ]; then
        EC_RESTORE_FAILED="APP_KEY"
        ec_env_set APP_KEY "$EC_RESTORE_KEY" || ec_restore_fail "APP_KEY を env に書けませんでした"
        EC_RESTORE_KEY=""
        EC_RESTORE_FAILED=""
        EC_RESTORE_DONE="${EC_RESTORE_DONE:+${EC_RESTORE_DONE}・}APP_KEY"
    fi

    ec_step "DB を戻す"
    ec_restore_databases "$dir" || ec_restore_fail "DB を戻せませんでした（sudo ec-sugar logs db で原因を見る）"
    if [ "$files" = 1 ]; then
        ec_step "写真などのファイルを戻す"
        ec_restore_files "$dir" || ec_restore_fail "写真などのファイルを戻せませんでした（今のファイルは、展開できた時だけ置き換わる）"
    fi

    # バックアップが前のバージョンのときは、今のバージョンの形へ（新しいバージョンのバックアップを前のバージョンに戻すときは、cmd_restore の確認で知らせる）
    ec_step "マイグレーション"
    EC_RESTORE_FAILED="マイグレーション"
    ec_migrate "$image" || ec_restore_fail "マイグレーションに失敗しました（ログは ${EC_LOGS}/ の migrate-*.log）"
    EC_RESTORE_FAILED="起動とヘルスチェック"
    ec_step "起動してヘルスチェック"
    ec_health "$image" || ec_restore_fail "戻した後のヘルスチェックを通りません（sudo ec-sugar logs app web で原因を見る）"
    EC_RESTORE_FAILED=""
    ec_history "$(ec_now)" restored "$cur" "$cur" "バックアップ ${EC_RESTORE_NAME} から戻した$([ "$isolated" = 1 ] && echo '（隔離した複製環境）' || true)"
    ec_restore_summary
    ec_ok "バックアップ ${EC_RESTORE_NAME} の時点へ戻しました（${EC_RESTORE_DONE}）。戻す前の状態はバックアップ ${EC_RESTORE_SAFETY}"
}

# 戻した後の数（店ごとの取引・台帳・記録の鎖・写真）を出す。口を持たないバージョンでは見送る。合わなくても止めない（数を見て人が決める）
ec_restore_summary() {
    if ec_dc exec -T app php artisan list --raw 2> /dev/null | ec_q '^operations:restore-check '; then
        ec_step "戻した後の数（sudo ec-sugar restore-check と同じ）"
        ec_dc exec -T app php artisan operations:restore-check || ec_warn "数が読めない店があります（上の行。APP_KEY が取った所と違うと、店の鍵を復号できない）"
    fi
    return 0
}

# ---------- 運用の道具と systemd の unit を入れる（インストールと、更新が済んだ後） ----------
# $1 は deploy/ の置き場（取り出したバージョンのもの。更新では確かめたコミットの git の中身から取り出したもの: ec_deploy_from_commit）。
# 写すのはリンクでないふつうのファイルだけ（たどった先も $1 の中。リンクで root の持ち物のファイルを写させない。中）。
# compose の守りの検査（$1 の隣の docker/check-compose.sh）があれば一緒に入れる（次の更新で、新しいバージョンの compose を今の検査にかける）
ec_install_tools() {
    local from="$1" mode cal delay f tz checker
    for f in ec-sugar lib/common.sh systemd/ec-sugar.service systemd/ec-sugar-update.service systemd/ec-sugar-backup.service systemd/ec-sugar-firewall.service \
        systemd/ec-sugar-watch.service systemd/ec-sugar-watch.timer systemd/ec-sugar-backup.timer systemd/ec-sugar-update.timer; do
        ec_plain_file "$from/$f" "$from" || ec_die "$from/$f がふつうのファイルでないか、ディレクトリの外を指すリンクです（運用のスクリプトを置き換えない）"
    done
    for f in "$from/ec-sugar" "$from/lib/common.sh"; do
        bash -n "$f" || ec_die "$f に文法の誤りがあります（切り替えない）"
    done
    checker="$(dirname "$from")/docker/check-compose.sh"
    if [ -e "$checker" ]; then
        ec_plain_file "$checker" "$(dirname "$from")" || ec_die "$checker がふつうのファイルでないか、ディレクトリの外を指すリンクです（運用のスクリプトを置き換えない）"
        bash -n "$checker" || ec_die "$checker に文法の誤りがあります（切り替えない）"
    fi
    install -d -m 0755 -o root -g root "$EC_LIB_DIR" "$EC_LIB_DIR/lib"
    install -m 0644 -o root -g root "$from/lib/common.sh" "$EC_LIB_DIR/lib/.common.sh.new"
    mv -f "$EC_LIB_DIR/lib/.common.sh.new" "$EC_LIB_DIR/lib/common.sh"
    install -m 0755 -o root -g root "$from/ec-sugar" "$EC_LIB_DIR/.ec-sugar.new"
    mv -f "$EC_LIB_DIR/.ec-sugar.new" "$EC_LIB_DIR/ec-sugar"
    ln -sfn "$EC_LIB_DIR/ec-sugar" "$EC_BIN"
    if [ -e "$checker" ]; then
        install -m 0644 -o root -g root "$checker" "$EC_LIB_DIR/.check-compose.sh.new"
        mv -f "$EC_LIB_DIR/.check-compose.sh.new" "$EC_LIB_DIR/check-compose.sh"
    fi

    mode="$(ec_mode)"
    tz="$(ec_timezone)"
    ec_valid_timezone "$tz" || ec_die "タイムゾーン $tz が見つかりません（/usr/share/zoneinfo）" "sudo apt-get install -y tzdata の後に再実行するか、設定ファイルの TIMEZONE を直す"
    if [ "$mode" = staging ]; then
        # ステージング: main が通るたびに（区切り 1 は 15 分ごとに決めた枝の新しいコミットを見る）
        cal='*:0/15'
        delay=60
    else
        # 本番: 夜に 1 回（お客さんの少ない時間。サーバーごとにずらす）
        cal="*-*-* 03:30:00 $tz"
        delay=1800
    fi
    for f in ec-sugar.service ec-sugar-update.service ec-sugar-backup.service ec-sugar-firewall.service ec-sugar-watch.service ec-sugar-watch.timer; do
        install -m 0644 -o root -g root "$from/systemd/$f" "$EC_UNIT_DIR/$f"
    done
    install -m 0644 -o root -g root "$from/systemd/ec-sugar-backup.timer" "$EC_UNIT_DIR/ec-sugar-backup.timer"
    # 朝の控えの時刻の帯は drop-in で（unit のファイルは時刻の帯なしでも読める形のまま）
    install -d -m 0755 -o root -g root "$EC_UNIT_DIR/ec-sugar-backup.timer.d"
    printf '# ec-sugar が書く（設定ファイルの TIMEZONE）。手動で直さない\n[Timer]\nOnCalendar=\nOnCalendar=*-*-* 04:15:00 %s\n' "$tz" > "$EC_UNIT_DIR/ec-sugar-backup.timer.d/.10-timezone.conf.new"
    chmod 0644 "$EC_UNIT_DIR/ec-sugar-backup.timer.d/.10-timezone.conf.new"
    mv -f "$EC_UNIT_DIR/ec-sugar-backup.timer.d/.10-timezone.conf.new" "$EC_UNIT_DIR/ec-sugar-backup.timer.d/10-timezone.conf"
    sed -e "s|@ON_CALENDAR@|$cal|" -e "s|@DELAY@|$delay|" "$from/systemd/ec-sugar-update.timer" > "$EC_UNIT_DIR/.ec-sugar-update.timer.new"
    chmod 0644 "$EC_UNIT_DIR/.ec-sugar-update.timer.new"
    mv -f "$EC_UNIT_DIR/.ec-sugar-update.timer.new" "$EC_UNIT_DIR/ec-sugar-update.timer"
    systemctl daemon-reload
    # 動いているタイマーは、読み直しただけでは次の時刻を数え直さないことがある（書き方を直した後も「次は無し」のまま。使い捨ての Ubuntu で見た）
    systemctl try-restart ec-sugar-update.timer ec-sugar-backup.timer > /dev/null 2>&1 || true
    # 自動の更新を入れているかを記録に残す（運営の様子へ渡る。入れ直しの時は install.sh が後で上書きする）
    if systemctl is-enabled ec-sugar-update.timer > /dev/null 2>&1; then ec_state_put auto_update yes; else ec_state_put auto_update no; fi
    # 定期の処理の見張りはどの形でも入れる（前のバージョンで入れたサーバーにも、更新の後の install-units で入る）
    systemctl enable --now ec-sugar-watch.timer > /dev/null 2>&1 || ec_warn "定期の処理の監視（ec-sugar-watch.timer）を入れられませんでした（sudo systemctl status ec-sugar-watch.timer で原因を見る）"
    ec_install_integrity_units "$from"
    ec_install_offsite_units "$from"
}

# 中の照らし（ADR 0105）の unit を入れて、毎分のタイマーを有効にする。入れる道具に unit が無い（この仕組みの前のバージョンへ
# 更新・ロールバックした）なら、タイマーを止めて unit を消す（知らない使い方の ec-sugar integrity を毎分呼ばない）
ec_install_integrity_units() {
    local from="$1" f
    if [ -f "$from/systemd/ec-sugar-integrity.service" ] && [ -f "$from/systemd/ec-sugar-integrity.timer" ]; then
        for f in ec-sugar-integrity.service ec-sugar-integrity.timer; do
            ec_plain_file "$from/systemd/$f" "$from" || ec_die "$from/systemd/$f がふつうのファイルでないか、ディレクトリの外を指すリンクです（運用のスクリプトを置き換えない）"
            install -m 0644 -o root -g root "$from/systemd/$f" "$EC_UNIT_DIR/.$f.new"
            mv -f "$EC_UNIT_DIR/.$f.new" "$EC_UNIT_DIR/$f"
        done
        systemctl daemon-reload
        systemctl enable --now ec-sugar-integrity.timer > /dev/null 2>&1 || ec_warn "内部の整合性チェック（ec-sugar-integrity.timer）を入れられませんでした（sudo systemctl status ec-sugar-integrity.timer で原因を見る）"
        return 0
    fi
    if [ -e "$EC_UNIT_DIR/ec-sugar-integrity.timer" ] || [ -e "$EC_UNIT_DIR/ec-sugar-integrity.service" ]; then
        systemctl disable --now ec-sugar-integrity.timer > /dev/null 2>&1 || true
        rm -f "$EC_UNIT_DIR/ec-sugar-integrity.timer" "$EC_UNIT_DIR/ec-sugar-integrity.service"
        systemctl daemon-reload
        ec_warn "入れたバージョンには内部の整合性チェックがないので、ec-sugar-integrity.timer を止めました（外部監視は「状態が古い」と通知します）"
    fi
    return 0
}

# ---------- ホストの防火壁 ----------
# 1. どの形でも: ホストの root でない利用者から、この compose の網（internal・edge・front・egress・egress_in。Cloudflare Tunnel の形は tunnel・tunnel_out も）のコンテナへ直に届かないようにする
#    （OUTPUT の鎖で、その網の bridge へ出る、root でない利用者の持つソケットから始めた接続を断る）。同じサーバーの契約者の PHP・cron・SSH の利用者が
#    app・db・redis・nginx の住所へ直に送れないように（3 回目の見直し。使い捨ての Ubuntu で、前は 5432・6379・9000 に届いた）。
#    コンテナどうしの通信と、root（docker・ec-sugar）の通信には触らない。ソケットの無い通信（カーネルが返すもの）にも触らない。
#    断るのはホストから始めた接続の向き（--ctdir ORIGINAL）だけ。コンテナから始めた接続の返り（例: MAIL_HOST をホストの Postfix に向けたときの
#    Postfix の答え）は通す（4 回目の見直し。前は向きを見ず、返りも断ってメールが黙って止まった）。
#    公開したポート（Caddy の 80・443 番など）への接続は通す（! --ctstate DNAT）。ホストの中から待ち受けるアドレスへつなぐと、Docker の nat の
#    OUTPUT の決まりでその場で DNAT され、bridge へ出てこの決まりに当たる（ホストの見張りが自分の https を開けなかった）。公開したポートは外からも
#    届くので、ホストの利用者に許しても失うものは無い。この形は、追跡の記録の無い包み（conntrack の無いもの）も断る（--ctdir だけでは素通りした）。
#    IPv4（iptables）と IPv6（ip6tables）の両方に当てる（今の網は IPv4 だけで、コンテナに IPv6 の住所は無いが、Docker の設定で IPv6 が付いても抜けない）
# 3. どの形でも: この compose の網の bridge から**ホスト自身**への接続（INPUT）を断る（2026-11-13）。コンテナが取られても、ホストの
#    待ち受け（SSH・DNS・メールの送り口など）へ届かせない。外へ出られない網（internal）でも、ふつうは bridge の住所（gateway）からホストへ届く
#    （egress_in は compose でホストの側に住所を持たない形にしてある）。INPUT の先頭に bridge ごとの飛び先（-i br-… -j EC-SUGAR-HOST）を入れ、
#    鎖 EC-SUGAR-HOST で: 返り（ESTABLISHED・RELATED）と DNAT された接続は通し（ホストから始めた接続の返り・公開したポート）、env の
#    EC_SUGAR_HOST_ALLOW_PORTS の TCP のポートだけを INPUT の続き（ufw など）に任せ、ほかは断る（REJECT。すぐ失敗が分かる）。
#    要るホストへの道は今のところ無い（Docker の中の DNS 127.0.0.11 はコンテナの中、コンテナどうしは bridge の中で INPUT を通らない）。
#    MAIL_HOST をホストの Postfix に向けるときだけ、そのポート（例 25,587）を EC_SUGAR_HOST_ALLOW_PORTS に書く
# 2. ステージングだけ: 受け口を家の LAN だけに（Docker は ufw を迂回するので DOCKER-USER の鎖で）。外から公開のポートへ来た接続（DNAT されたもの）の
#    うち、決めた LAN の外から来たものを落とす。すでにある Web サーバーの後ろに置く形は、80・443 番は前の Web サーバーのものなので、この決まりは入れない
#    （1 はどの形でも入れる）
# どちらも、ほかの道具（Plesk のファイアウォールの適用・iptables-restore・ufw の作り直し）に消されることがある。消えたら status が ✗ を出し、
# 毎晩（ステージングは 15 分ごと）の更新の確かめと sudo ec-sugar firewall で当て直す
EC_GUARD_COMMENT=ec-sugar-bridge-guard
EC_LAN_COMMENT=ec-sugar-lan-only
# 3. の鎖と印（確かめの台本 docker/check-egress.sh は、本物の EC-SUGAR の決まりに触らないよう別の名前で当てる）
EC_HOST_CHAIN="${EC_HOST_CHAIN:-EC-SUGAR-HOST}"
EC_HOST_COMMENT="${EC_HOST_COMMENT:-ec-sugar-host-guard}"

# 決まりを当てる道具（IPv4 の iptables と、使えれば IPv6 の ip6tables）を 1 行ずつ
ec_fw_tools() {
    local tool
    for tool in iptables ip6tables; do
        command -v "$tool" > /dev/null && "$tool" -S OUTPUT > /dev/null 2>&1 && printf '%s\n' "$tool"
    done
    return 0
}
# この compose の網の bridge の名前（br-<網の ID の頭 12 文字>）を 1 行ずつ。網がまだ無ければ何も出さない。
# Docker に網の一覧を聞けなければ 1 を返す（「網が無い」と取り違えて、今ある網の決まりを片付けないように）
ec_bridges() {
    local list name id net
    command -v docker > /dev/null || return 0
    list="$(docker network ls --no-trunc --format '{{.Name}} {{.ID}}' 2> /dev/null)" || return 1
    while read -r name id; do
        # tunnel と tunnel_out は Cloudflare Tunnel の形だけにある網（cloudflared と caddy・cloudflared が外へ出る網。ADR 0052）。
        # egress_in はアプリと出口の代理だけの網（ADR 0102）
        for net in internal edge front egress egress_in tunnel tunnel_out; do
            [ "$name" != "${EC_PROJECT}_$net" ] || [ -z "$id" ] || printf 'br-%s\n' "${id:0:12}"
        done
    done <<< "$list"
    return 0
}
# bridge $1 の決まりを EC_GUARD_RULE に作る（当てる・確かめるの両方で同じ形を使う）
ec_guard_rule() {
    EC_GUARD_RULE=(-o "$1" -m owner --uid-owner 1-4294967294 -m conntrack --ctdir ORIGINAL ! --ctstate DNAT -m comment --comment "$EC_GUARD_COMMENT" -j REJECT)
}
# 当てられなかったら注意を出して 1 を返す（止めない。呼ぶ側が || で受ける）
ec_bridge_guard_apply() {
    local tool br bridges tools want=" " line rc=0
    command -v docker > /dev/null || return 0
    tools="$(ec_fw_tools)"
    if [ -z "$tools" ]; then
        ec_warn "iptables が使えないので、ホストのファイアウォールを適用できません（sudo apt-get install -y iptables）"
        return 1
    fi
    if ! bridges="$(ec_bridges)"; then
        ec_warn "Docker にネットワークの一覧を聞けないので、ホストのファイアウォールを適用できません（今あるルールは消さずに残す。sudo systemctl status docker で見る）"
        return 1
    fi
    for br in $bridges; do want="$want$br "; done
    for tool in $tools; do
        for br in $bridges; do
            ec_guard_rule "$br"
            "$tool" -C OUTPUT "${EC_GUARD_RULE[@]}" 2> /dev/null || "$tool" -I OUTPUT 1 "${EC_GUARD_RULE[@]}" ||
                { ec_warn "$tool で $br のルールを足せませんでした"; rc=1; }
        done
        # 作り直されて無くなった網の決まりと、前の形（向きを見ない形・DNAT を見ない形）の決まりを片付ける
        while IFS= read -r line; do
            br="$(printf '%s' "$line" | grep -oE -- '-o br-[0-9a-f]+' | cut -d' ' -f2 || true)"
            if [ -n "$br" ] && [[ "$want" == *" $br "* ]] && [[ "$line" == *'--ctdir ORIGINAL'* ]] && [[ "$line" == *'! --ctstate DNAT'* ]]; then continue; fi
            # shellcheck disable=SC2086  # iptables -S の 1 行を引数に分けて -D に渡す
            "$tool" -D ${line#-A } 2> /dev/null || true
        done < <("$tool" -S OUTPUT 2> /dev/null | grep -- "--comment $EC_GUARD_COMMENT" || true)
    done
    ec_host_guard_apply "$tools" "$bridges" || rc=1
    return "$rc"
}
# 3. ホストへ通してよい TCP のポート（env の EC_SUGAR_HOST_ALLOW_PORTS。カンマ区切りで 15 個まで）。形が違えば注意を出して空（断る側に倒す）
ec_host_allow_ports() {
    local ports p
    ports="$(ec_env_get EC_SUGAR_HOST_ALLOW_PORTS 2> /dev/null || true)"
    ports="$(printf '%s' "$ports" | tr -d ' "')"
    [ -n "$ports" ] || return 0
    if [[ "$ports" =~ ^[0-9]{1,5}(,[0-9]{1,5}){0,14}$ ]]; then
        for p in ${ports//,/ }; do
            if [ "$((10#$p))" -lt 1 ] || [ "$((10#$p))" -gt 65535 ]; then ports=""; fi
        done
    else
        ports=""
    fi
    if [ -z "$ports" ]; then
        ec_warn "env の EC_SUGAR_HOST_ALLOW_PORTS の形が違うので、コンテナからホストへはどのポートも通しません（例 25,587）"
        return 0
    fi
    printf '%s' "$ports"
}
# 3. の鎖の決まりを 1 行ずつ（$1 通すポート）。順が大事: 返りと DNAT を通す → 決めたポートを INPUT の続きへ → ほかは断る
ec_host_chain_rules() {
    printf -- '-m conntrack --ctstate RELATED,ESTABLISHED,DNAT -m comment --comment %s -j RETURN\n' "$EC_HOST_COMMENT"
    [ -z "$1" ] || printf -- '-p tcp -m multiport --dports %s -m comment --comment %s -j RETURN\n' "$1" "$EC_HOST_COMMENT"
    printf -- '-m comment --comment %s -j REJECT\n' "$EC_HOST_COMMENT"
}
ec_host_jump_rule() { EC_HOST_JUMP=(-i "$1" -m comment --comment "$EC_HOST_COMMENT" -j "$EC_HOST_CHAIN"); }
# 3. を当てる（$1 道具 $2 bridge。どちらも 1 行ずつ）。鎖の中身が決まりと違えば作り直し、bridge ごとの飛び先を足し、無くなった網の飛び先を片付ける。
# 当てられなかったら注意を出して 1
ec_host_guard_apply() {
    local tools="$1" bridges="$2" tool br ports line want=" " n same rc=0
    ports="$(ec_host_allow_ports)"
    for br in $bridges; do want="$want$br "; done
    for tool in $tools; do
        "$tool" -N "$EC_HOST_CHAIN" 2> /dev/null || true
        same=1
        n=0
        while IFS= read -r line; do
            n=$((n + 1))
            # shellcheck disable=SC2086  # 決まりの 1 行を引数に分ける（値に空白は無い）
            "$tool" -C "$EC_HOST_CHAIN" $line 2> /dev/null || same=0
        done < <(ec_host_chain_rules "$ports")
        [ "$("$tool" -S "$EC_HOST_CHAIN" 2> /dev/null | grep -c -- "^-A $EC_HOST_CHAIN " || true)" = "$n" ] || same=0
        "$tool" -S "$EC_HOST_CHAIN" 2> /dev/null | grep -- "^-A $EC_HOST_CHAIN " | tail -n 1 | ec_q -- '-j REJECT' || same=0
        if [ "$same" -eq 0 ]; then
            "$tool" -F "$EC_HOST_CHAIN" 2> /dev/null || true
            while IFS= read -r line; do
                # shellcheck disable=SC2086
                "$tool" -A "$EC_HOST_CHAIN" $line || { ec_warn "$tool でチェーン $EC_HOST_CHAIN のルールを足せませんでした"; rc=1; }
            done < <(ec_host_chain_rules "$ports")
        fi
        for br in $bridges; do
            ec_host_jump_rule "$br"
            "$tool" -C INPUT "${EC_HOST_JUMP[@]}" 2> /dev/null || "$tool" -I INPUT 1 "${EC_HOST_JUMP[@]}" ||
                { ec_warn "$tool で $br からホストへのルールを足せませんでした"; rc=1; }
        done
        # 作り直されて無くなった網の飛び先を片付ける
        while IFS= read -r line; do
            br="$(printf '%s' "$line" | grep -oE -- '-i br-[0-9a-f]+' | cut -d' ' -f2 || true)"
            if [ -n "$br" ] && [[ "$want" == *" $br "* ]]; then continue; fi
            # shellcheck disable=SC2086
            "$tool" -D ${line#-A } 2> /dev/null || true
        done < <("$tool" -S INPUT 2> /dev/null | grep -- "--comment $EC_HOST_COMMENT" || true)
    done
    return "$rc"
}
# 3. の足りないものを「道具 INPUT bridge」か「道具 鎖」で 1 行ずつ（status の ✗）
ec_host_guard_missing() {
    local tools="$1" bridges="$2" tool br line ports
    ports="$(ec_host_allow_ports 2> /dev/null)"
    for tool in $tools; do
        while IFS= read -r line; do
            # shellcheck disable=SC2086
            "$tool" -C "$EC_HOST_CHAIN" $line 2> /dev/null || { printf '%s チェーン %s\n' "$tool" "$EC_HOST_CHAIN"; break; }
        done < <(ec_host_chain_rules "$ports")
        for br in $bridges; do
            ec_host_jump_rule "$br"
            "$tool" -C INPUT "${EC_HOST_JUMP[@]}" 2> /dev/null || printf '%s INPUT %s\n' "$tool" "$br"
        done
    done
    return 0
}
# 今ある網の bridge のうち、決まりが入っていないものを「道具 bridge」で 1 行ずつ出す（status で ✗ にする。全部あれば何も出さない）
ec_bridge_guard_missing() {
    local tool br bridges tools
    tools="$(ec_fw_tools)"
    if [ -z "$tools" ]; then
        printf 'iptables が使えない\n'
        return 0
    fi
    if ! bridges="$(ec_bridges)"; then
        printf 'Docker にネットワークの一覧を聞けない\n'
        return 0
    fi
    for tool in $tools; do
        for br in $bridges; do
            ec_guard_rule "$br"
            "$tool" -C OUTPUT "${EC_GUARD_RULE[@]}" 2> /dev/null || printf '%s %s\n' "$tool" "$br"
        done
    done
    ec_host_guard_missing "$tools" "$bridges"
    return 0
}
# ステージングの受け口の決まり（DOCKER-USER）を EC_LAN_RULE に作る。$1 ポート $2 LAN の範囲
ec_lan_rule() {
    EC_LAN_RULE=(-p tcp -m conntrack --ctstate DNAT --ctdir ORIGINAL --ctorigdstport "$1" ! -s "$2" -m comment --comment "$EC_LAN_COMMENT" -j DROP)
}
# ステージングで受け口を LAN だけにする決まりが要る形か（EC-SUGAR の Caddy が 80・443 番を外へ出す形だけ。後ろに置く形は前の Web サーバーのもの、
# Cloudflare Tunnel の形は 127.0.0.1 だけで受けるので要らない）
ec_lan_only_wanted() { [ "$(ec_mode)" = staging ] && [ "$(ec_front)" = caddy ]; }
# ステージングの受け口の決まりのうち、入っていないポートを 1 行ずつ出す（要らない形・LAN_CIDR が読めないときは何も出さない）
ec_lan_only_missing() {
    local lan https http port
    ec_lan_only_wanted || return 0
    lan="$(ec_answer LAN_CIDR 2> /dev/null || true)"
    ec_valid_cidr4 "$lan" || { printf 'LAN_CIDR が読めない\n'; return 0; }
    https="$(ec_env_get EC_SUGAR_HTTPS_PORT 2> /dev/null || true)"
    http="$(ec_env_get EC_SUGAR_HTTP_PORT 2> /dev/null || true)"
    for port in "${https:-443}" "${http:-80}"; do
        ec_lan_rule "$port" "$lan"
        iptables -C DOCKER-USER "${EC_LAN_RULE[@]}" 2> /dev/null || printf '%s\n' "$port"
    done
    return 0
}
# 1 と 2 を当てる。何度流しても同じ。当てられないものがあれば注意を出して 1 を返す（ec_die で抜けない。呼ぶ側が || で受けられるように）
ec_firewall_apply() {
    local lan https http port rc=0
    ec_bridge_guard_apply || rc=1
    ec_lan_only_wanted || return "$rc"
    lan="$(ec_answer LAN_CIDR 2> /dev/null || true)"
    if ! ec_valid_cidr4 "$lan"; then
        ec_warn "install.answers の LAN_CIDR が読めないので、待ち受けの 80・443 番を LAN だけにするルールを適用できません（$EC_ANSWERS を直して sudo ec-sugar firewall）"
        return 1
    fi
    https="$(ec_env_get EC_SUGAR_HTTPS_PORT 2> /dev/null || true)"
    http="$(ec_env_get EC_SUGAR_HTTP_PORT 2> /dev/null || true)"
    iptables -N DOCKER-USER 2> /dev/null || true
    for port in "${https:-443}" "${http:-80}"; do
        ec_lan_rule "$port" "$lan"
        iptables -C DOCKER-USER "${EC_LAN_RULE[@]}" 2> /dev/null || iptables -I DOCKER-USER 1 "${EC_LAN_RULE[@]}" ||
            { ec_warn "DOCKER-USER に $port 番のルールを足せませんでした"; rc=1; }
    done
    return "$rc"
}
# 網を作るとき、決まりの無い間を作らない: コンテナと網を作るだけ（起こさない）→ 防火壁を当てる → 起こす（呼ぶ側）。$@ は up に渡すもの（サービスの名前など）
ec_dc_create_guarded() {
    ec_dc up --no-start "$@" > /dev/null 2>&1 || true
    ec_firewall_apply || ec_warn "ホストのファイアウォールを適用できませんでした（上の行。sudo ec-sugar firewall で再適用する）"
}

# ---------- 管理画面を開ける接続元を替える（家の回線の住所が変わったとき） ----------
# $1 IP か範囲のカンマ区切り（IPv4 と IPv6）。形を確かめてから、答え（流し直しでも残る）と env の ADMIN_ALLOWED_IPS に書き、
# app を作り直して効かせる。形が違えば何も書かずに 1。作り直しに失敗したら 1（書いた値は残るので、もう一度流せば効く）
# 管理画面を開ける接続元として広すぎる・危ない範囲なら、理由を 1 行出す（無ければ何も出さない）。形の確かめ（ec_valid_cidr_list）の後に呼ぶ。
# IPv4 は /16 より広い範囲、IPv6 は /48 より広い範囲（と /128 を超える長さ）を断る（0.0.0.0/0・::/0 で制限が外れる）。
# Tunnel の形では私設の範囲も断る（サーバーの中からの要求は入口の網の住所〔172.30.…〕に見えるので、許すと中から入れる。見直しの軽 2）
ec_admin_ips_problem() {
    local item ip len IFS=,
    for item in $1; do
        ip="${item%/*}"
        if [[ "$item" == *:* ]]; then
            len=128
            [[ "$item" == */* ]] && len="${item#*/}"
            if [ "$len" -gt 128 ] || [ "$len" -lt 48 ]; then printf '%s は IPv6 の範囲として広すぎるか長さが違います（/48 から /128）\n' "$item"; return 0; fi
            if [ "$(ec_front)" = tunnel ] && [[ "$(printf '%s' "$ip" | tr '[:upper:]' '[:lower:]')" =~ ^(f[cd]|fe[89ab]|::1$|::$|::ffff:) ]]; then printf '%s はプライベート・内部の IP アドレスです（Tunnel の構成では、サーバーの中からの要求に当たる）\n' "$item"; return 0; fi
        else
            len=32
            [[ "$item" == */* ]] && len="${item#*/}"
            if [ "$len" -lt 16 ]; then printf '%s は広すぎます（/16 より狭い範囲にする）\n' "$item"; return 0; fi
            if [ "$(ec_front)" = tunnel ] && [[ "$ip" =~ ^(10\.|127\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.|169\.254\.|0\.) ]]; then printf '%s はプライベート・内部の IP アドレスです（Tunnel の構成では、サーバーの中からの要求に当たる）\n' "$item"; return 0; fi
        fi
    done
    return 0
}
# サーバーの外へ送った記録の連鎖の先頭（ストレージの heads/ から取った 1 つの .tsv）と、今の店の記録を照らす（ADR 0110 の 5）。
# 中身はアプリの operations:check-chain-heads が行ごとに確かめる（形・店・番号・hash）。ここでは、ふつうのファイルで、大きすぎないことだけを見て、
# 標準入力で渡す（コンテナからホストのファイルは見えないため）。返り: アプリの返り（0 全部合う・1 合わない店がある・2 形が違う）
ec_check_chain_heads_file() {
    local f="$1" size
    [ -n "$f" ] && [ -f "$f" ] && [ ! -L "$f" ] && [ -r "$f" ] || { ec_warn "先頭のファイル（${f:-なし}）を読めません（ストレージの heads/ から取った .tsv を指定する）"; return 2; }
    size="$(stat -c %s "$f" 2> /dev/null || echo 0)"
    [ "$size" -gt 0 ] && [ "$size" -le 10485760 ] || { ec_warn "先頭のファイル（${f}）が空か、大きすぎます（10 MB まで）"; return 2; }
    ec_dc exec -T app php artisan operations:check-chain-heads < "$f"
}
ec_set_admin_ips() {
    local list="$1"
    ec_valid_cidr_list "$list" || return 1
    [ -z "$(ec_admin_ips_problem "$list")" ] || return 1
    ec_kv_set "$EC_ANSWERS" ADMIN_ALLOWED_IPS "$list" || return 1
    ec_env_set ADMIN_ALLOWED_IPS "$list" || return 1
    ec_dc up -d --no-deps --force-recreate app > /dev/null 2>&1
}

# ---------- 組み立ての控え（Docker の build cache）の片付け ----------
# イメージをサーバーの上で組むので、組み立ての控えが溜まる（ステージングでは 15 分ごとに組んで 16 GB になった）。
# 1 週間より前のものを消したうえで、全体を EC_BUILD_CACHE_MAX（既定 4gb）までに収める（古い順に消える。次に組む時に要る分は組み直す）。
# 上限の書き方は Docker 28 から --max-used-space。知らない版（前の --keep-storage だけの版）では前の書き方で。どちらも失敗しても止めない
EC_BUILD_CACHE_MAX="${EC_BUILD_CACHE_MAX:-4gb}"
ec_prune_build_cache() {
    docker builder prune -f --filter until=168h > /dev/null 2>&1 || true
    docker builder prune -f --max-used-space "$EC_BUILD_CACHE_MAX" > /dev/null 2>&1 ||
        docker builder prune -f --keep-storage "$EC_BUILD_CACHE_MAX" > /dev/null 2>&1 || true
    return 0
}

# ---------- 定期の処理の見張り ----------
# 知らせの係（operations:alert）は定期の処理（scheduler）の中で毎分走るので、定期の処理が止まると「止まった」ことの知らせも出ない。
# ec-sugar-watch.timer が 10 分ごとに、app の中で知らせの係を直に走らせる（運営の様子は最後に集めた時刻が 30 分より古いと止まりとみなす）。
# 回どうしは知らせの係の鍵で 1 つずつ。更新の鍵は取らない（更新が長くかかっても見張りを止めない）。
# app が動いていなければ注意を出して 1（ここからは知らせられない。サーバーの外からの見張りが受け持つ）。
# Cloudflare Tunnel の形では、先にトンネルの接続役を確かめ（ec_tunnel_watch）、サーバーの見張りの報告ごと運営の様子へ渡してから知らせの係を走らせる
# （報告は前の報告を丸ごと置き換えるので、証明書・空き容量なども一緒に渡す。渡せなくても知らせの係は走らせる）
ec_watch() {
    ec_tunnel_watch
    # 出口の代理。知らせの係のメールも代理を通る（止める形）ので、先に見て直す
    ec_egress_watch || true
    if ! ec_dc ps --status running --format '{{.Service}}' app 2> /dev/null | ec_q -x app; then
        ec_warn "app が動いていないので、通知のジョブを実行できません（sudo ec-sugar status で状態を見る）"
        return 1
    fi
    if [ "$(ec_front)" = tunnel ]; then ec_host_report || true; fi
    if ec_dc exec -T app php artisan operations:alert; then return 0; fi
    ec_warn "通知のジョブが失敗しました（sudo ec-sugar logs app で理由を見る）"
    return 1
}

# ---------- ホストから見た状態をアプリへ渡す（ADR 0043） ----------
# 証明書の期限・空き容量・最後の更新・最後のバックアップは、サーバーの外（ここ）からしか見えない。HTTP の入口は作らず、
# コンテナの中の php artisan operations:host-report に決まった項目だけを引数で渡す（シェルを通さない）。アプリが形を厳しく確かめ、
# 中央の表 host_checks に置き換えで書き、運営の様子と要対応のメール（operations:alert）に出る。
# 渡すのはホスト名・時刻・割合・バイト数・決まった語・コミットのハッシュだけ。証明書の中身・鍵の場所・合言葉・自由な文は渡さない

# 時刻の形（date -Is の 2026-10-30T03:31:02+09:00 か、UTC の …Z）
ec_valid_time() { [[ "$1" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(Z|[+-][0-9]{2}:[0-9]{2})$ ]]; }

# 最後の更新・取得元に最後に届いた時刻・最後のバックアップを、state のファイルから引数にして 1 行ずつ出す。形の違う値は渡さない
ec_report_state_args() {
    local f="$EC_STATE/last_update" result at to checked latest auto
    if [ -r "$f" ]; then
        result="$(ec_kv_get "$f" result || true)"
        at="$(ec_kv_get "$f" time || true)"
        to="$(ec_kv_get "$f" to || true)"
        case "$result" in ok | rolled_back | failed | stopped | manual_rollback) ;; *) result="" ;; esac
        if [ -n "$result" ] && ec_valid_time "$at"; then
            printf '%s\n' "--update-result=$result" "--update-at=$at"
            if ec_valid_commit "$to"; then printf '%s\n' "--update-version=$to"; fi
        fi
    fi
    checked="$(ec_state_get last_check || true)"
    if ec_valid_time "$checked"; then printf '%s\n' "--update-checked-at=$checked"; fi
    # 自動の更新を入れているか（install-units が systemd から見て記録する）。入れていないサーバーでは、
    # 運営の様子は「取得元に 48 時間届いていない」を出さない（誰も見に行かないので古くなって当たり前）
    auto="$(ec_state_get auto_update || true)"
    case "$auto" in yes | no) printf '%s\n' "--update-auto=$auto" ;; esac
    # バックアップ: 結果の記録（last_backup）があればそれ。無ければ（この仕組みの前に取ったもの）いちばん新しいバックアップの時刻を成功として
    f="$EC_STATE/last_backup"
    result=""
    at=""
    if [ -r "$f" ]; then
        result="$(ec_kv_get "$f" result || true)"
        at="$(ec_kv_get "$f" time || true)"
    else
        latest="$(ec_latest_backup || true)"
        if [ -n "$latest" ]; then
            result=ok
            at="$(ec_kv_get "$latest/manifest" time || true)"
        fi
    fi
    case "$result" in ok | failed) ;; *) result="" ;; esac
    if [ -n "$result" ] && ec_valid_time "$at"; then printf '%s\n' "--backup-result=$result" "--backup-at=$at"; fi
    # トンネルの見張り（Cloudflare Tunnel の形だけ。ec_tunnel_watch が「語 時刻」で残す。ほかの形では残っていない）
    f="$(ec_state_get tunnel_watch || true)"
    result="${f%% *}"
    at="${f#* }"
    case "$result" in ok | foreign | down | unknown | unwatched) ;; *) result="" ;; esac
    if [ -n "$result" ] && ec_valid_time "$at"; then printf '%s\n' "--tunnel=$result" "--tunnel-at=$at"; fi
    # 中の照らし（ADR 0105。ec_integrity_note が「語 時刻 困りごと…」で残す）。困りごとは名前だけ（形の違うものは渡さない）
    local parts=() p n=0
    f="$(ec_state_get integrity 2> /dev/null || true)"
    read -r -a parts <<< "$f" || true
    result="${parts[0]:-}"
    at="${parts[1]:-}"
    case "$result" in ok | mismatch) ;; *) result="" ;; esac
    if [ -n "$result" ] && ec_valid_time "$at"; then
        printf '%s\n' "--integrity=$result" "--integrity-at=$at"
        if [ "$result" = mismatch ]; then
            for p in "${parts[@]:2}"; do
                ec_integrity_valid_problem "$p" && [ "$n" -lt "$EC_INTEGRITY_MAX_PROBLEMS" ] || continue
                printf '%s\n' "--integrity-problem=$p"
                n=$((n + 1))
            done
        fi
    fi
    # 外の見張りの生存（来たことがある時だけ。seen か silent と、最後に来た時刻）
    f="$(ec_state_get integrity_sentinel 2> /dev/null || true)"
    result="${f%% *}"
    at="${f#* }"
    case "$result" in seen | silent) ;; *) result="" ;; esac
    if [ -n "$result" ] && ec_valid_time "$at"; then printf '%s\n' "--sentinel=$result" "--sentinel-at=$at"; fi
    # サーバーの外のバックアップ（ADR 0110。置き場を設定したサーバーだけ。ec_offsite_run が state/offsite に key=value で残す）。
    # 設定していない・まだ 1 回も試していないサーバーでは 1 つも足さない（運営の様子に札も困りごとも出さない）。
    # 渡すのは決まった語と時刻だけ（入口・バケット・鍵・バックアップの名前・置き場の答えは渡さない）
    f="$EC_STATE/offsite"
    if ec_offsite_configured && [ -r "$f" ]; then
        result="$(ec_kv_get "$f" offsite || true)"
        at="$(ec_kv_get "$f" offsite_tried_at || true)"
        case "$result" in ok | failed) ;; *) result="" ;; esac
        if [ -n "$result" ] && ec_valid_time "$at"; then
            printf '%s\n' "--offsite=$result" "--offsite-tried-at=$at"
            at="$(ec_kv_get "$f" offsite_at || true)"
            if ec_valid_time "$at"; then printf '%s\n' "--offsite-at=$at"; fi
            # 失敗の理由は決まった語だけ（知らない語は渡さない。運営の様子は理由なしの失敗として出す）
            if [ "$result" = failed ]; then
                p="$(ec_kv_get "$f" reason || true)"
                if ec_offsite_valid_reason "$p"; then printf '%s\n' "--offsite-reason=$p"; fi
            fi
            case "$(ec_kv_get "$f" offsite_lock || true)" in
                ok) printf '%s\n' "--offsite-lock=ok" ;;
                open) printf '%s\n' "--offsite-lock=open" ;;
                unknown) printf '%s\n' "--offsite-lock=unknown" ;;
            esac
            p="$(ec_kv_get "$f" offsite_heads || true)"
            case "$p" in
                ok | failed)
                    printf '%s\n' "--offsite-heads=$p"
                    at="$(ec_kv_get "$f" offsite_heads_at || true)"
                    if ec_valid_time "$at"; then printf '%s\n' "--offsite-heads-at=$at"; fi
                    ;;
            esac
        fi
    fi
    return 0
}

# $1 置き場の名前（data・backup）$2 道筋。「名前,空きの割合,空きのバイト数」を出す（df が読めなければ何も出さない）
ec_disk_arg() {
    local line fs="" size="" avail=""
    line="$(df -Pk "$2" 2> /dev/null | awk 'NR == 2 {print $1, $2, $4}' || true)"
    read -r fs size avail <<< "$line" || true
    if [ -n "$fs" ] && [[ "$size" =~ ^[0-9]+$ ]] && [[ "$avail" =~ ^[0-9]+$ ]] && [ "$size" -gt 0 ]; then
        printf '%s,%s,%s\n' "$1" "$((avail * 100 / size))" "$((avail * 1024))"
    fi
    return 0
}

# 空き容量: data は Docker の置き場（DB・写真・Redis の volume）、backup は $EC_ROOT（バックアップとソース）。同じファイルシステムなら data だけ。
# $1 Docker の置き場 $2 バックアップの置き場（テストで替えられるように引数で）
ec_disk_args() {
    local data="$1" backup="$2" data_mount backup_mount
    data_mount="$(df -Pk "$data" 2> /dev/null | awk 'NR == 2 {print $NF}' || true)"
    backup_mount="$(df -Pk "$backup" 2> /dev/null | awk 'NR == 2 {print $NF}' || true)"
    ec_disk_arg data "$data"
    if [ -n "$backup_mount" ] && [ "$backup_mount" != "$data_mount" ]; then ec_disk_arg backup "$backup"; fi
    return 0
}

# $1 ホスト名。入口が今出している証明書の期限を「ホスト名,期限の時刻（UTC）」で出す（status と同じ読み方）。読めなければ「ホスト名,unreadable」
ec_cert_arg() {
    local host="$1" port end at=""
    ec_valid_host "$host" || return 0
    port="$(ec_env_get EC_SUGAR_HTTPS_PORT 2> /dev/null || true)"
    end="$(timeout 20 openssl s_client -connect "$(ec_probe_ip):${port:-443}" -servername "$host" < /dev/null 2> /dev/null | openssl x509 -noout -enddate 2> /dev/null || true)"
    end="${end#notAfter=}"
    if [ -n "$end" ]; then at="$(date -u -d "$end" +%Y-%m-%dT%H:%M:%SZ 2> /dev/null || true)"; fi
    if ec_valid_time "$at"; then printf '%s,%s\n' "$host" "$at"; else printf '%s,unreadable\n' "$host"; fi
}

# 証明書を見るホスト（管理画面と最初のショップ。重なりは 1 つに）
ec_cert_hosts() {
    local admin shop
    admin="$(ec_env_get EC_SUGAR_ADMIN_HOST 2> /dev/null || true)"
    shop="$(ec_shop_host 2> /dev/null || true)"
    if [ -n "$admin" ]; then printf '%s\n' "$admin"; fi
    if [ -n "$shop" ] && [ "$shop" != "$admin" ]; then printf '%s\n' "$shop"; fi
    return 0
}

# 集めて渡す。$1 が verbose なら、アプリの答えをそのまま見せる（sudo ec-sugar report）。
# 失敗しても呼んだ処理（更新・バックアップ）の結果は変えない（注意を 1 行出して 1 を返す。呼ぶ側は || true で受ける）
ec_host_report() {
    local verbose="${1:-}" args=() line host root
    while IFS= read -r host; do
        [ -n "$host" ] || continue
        line="$(ec_cert_arg "$host")"
        [ -z "$line" ] || args+=("--cert=$line")
    done < <(ec_cert_hosts)
    root="$(docker info --format '{{.DockerRootDir}}' 2> /dev/null || true)"
    [ -d "$root" ] || root=/var/lib/docker
    while IFS= read -r line; do
        [ -z "$line" ] || args+=("--disk=$line")
    done < <(ec_disk_args "$root" "$EC_ROOT")
    while IFS= read -r line; do
        [ -z "$line" ] || args+=("$line")
    done < <(ec_report_state_args)
    if [ "${#args[@]}" -eq 0 ]; then
        ec_warn "サーバーの監視の報告に載せる値がありません"
        return 1
    fi
    if ! ec_dc ps --status running --format '{{.Service}}' app 2> /dev/null | ec_q -x app; then
        ec_warn "app が動いていないので、サーバーの監視の報告をアプリへ渡せません（運営ダッシュボードの値は前のまま。sudo ec-sugar status で状態を見る）"
        return 1
    fi
    if [ "$verbose" = verbose ]; then
        if ec_dc exec -T app php artisan operations:host-report "${args[@]}"; then return 0; fi
    elif ec_dc exec -T app php artisan operations:host-report "${args[@]}" > /dev/null 2>&1; then
        return 0
    fi
    # 後から足した値を知らない前のバージョンのアプリ（ロールバックの後）は、報告ごと断る。新しい方から外して、渡せるまで渡し直す
    # （証明書・空き容量・更新・バックアップを古いままにしない）:
    #   1. サーバーの外のバックアップの値（--offsite…）を外す
    #   2. さらに中の照らしと外の見張りの値（--integrity…・--sentinel…）を外す
    #   3. さらにトンネルの見張りの値（--tunnel・--tunnel-at）を外す
    # 外すものが無い段は飛ばす（同じ報告を 2 回渡さない）。注意には、実際に外したものだけを書く
    local kept=() arg level before="${#args[@]}" what name d_offsite d_integrity d_tunnel
    for level in 1 2 3; do
        kept=()
        d_offsite=0
        d_integrity=0
        d_tunnel=0
        for arg in "${args[@]}"; do
            case "$arg" in
                --offsite=* | --offsite-at=* | --offsite-tried-at=* | --offsite-reason=* | --offsite-lock=* | --offsite-heads=* | --offsite-heads-at=*)
                    d_offsite=1
                    ;;
                --integrity=* | --integrity-at=* | --integrity-problem=* | --sentinel=* | --sentinel-at=*)
                    if [ "$level" -lt 2 ]; then
                        kept+=("$arg")
                    else
                        d_integrity=1
                    fi
                    ;;
                --tunnel=* | --tunnel-at=*)
                    if [ "$level" -lt 3 ]; then
                        kept+=("$arg")
                    else
                        d_tunnel=1
                    fi
                    ;;
                *) kept+=("$arg") ;;
            esac
        done
        [ "${#kept[@]}" -gt 0 ] && [ "${#kept[@]}" -lt "$before" ] || continue
        before="${#kept[@]}"
        if ec_dc exec -T app php artisan operations:host-report "${kept[@]}" > /dev/null 2>&1; then
            what=""
            for name in "${d_offsite}:サーバーの外のバックアップ" "${d_integrity}:内部の整合性チェックと外部監視" "${d_tunnel}:トンネルの監視"; do
                [ "${name%%:*}" = 1 ] || continue
                if [ -z "$what" ]; then what="${name#*:}"; else what="${what}・${name#*:}"; fi
            done
            ec_warn "サーバーの監視の報告を、${what}の値を外して渡しました（動いているアプリがそれを知らない前のバージョン）"
            return 0
        fi
    done
    ec_warn "サーバーの監視の報告をアプリへ渡せませんでした（運営ダッシュボードの値は前のまま。sudo ec-sugar report で理由を見る）"
    return 1
}

# ---------- 店の数の形を広げる・戻す（ADR 0090。sudo ec-sugar shops） ----------
# 1 店舗の形（SHOPS=single）で動いているサーバーを、お客さんの URL を変えずに複数の店の形（multi）へ広げ、要るなら 1 店舗へ戻す。
# 筋: 1 店舗のショップのホスト（答えの SHOP_HOST）が <1 段>.<新しいショップのドメイン> なら、広げても最初の店はそのホストのまま（ec_shop_host）。
# 店の DB・データの鍵（APP_KEY と店の鍵）・Stripe の通知先（ショップのホストの経路）・管理画面のホスト（パスキー）は変えない。
# 変えるのは答え（SHOPS・SHOP_DOMAIN）・env の店の数の項目（ec_env_put_shops）・cloudflared の行き先と、入口・後ろの段・アプリの作り直しだけ。
# 答えも env も install.sh と同じ書き方なので、後で sudo ./deploy/install.sh を流し直しても同じ形のまま

# 公開の接尾辞（誰でもその下に名前を取れるもの）か。ショップのドメインにすると、ほかの人のドメインの名前で店を作れてしまう（見直しの軽 6）。
# 全部の一覧（Public Suffix List）は持たず、1 段のもの（ec_valid_host が断る）・日本の 2 段（属性型と都道府県型）・よくある海外の 2 段だけを見る
EC_PUBLIC_SUFFIXES_JP="ac ad co ed go gr lg ne or aichi akita aomori chiba ehime fukui fukuoka fukushima gifu gunma hiroshima hokkaido hyogo ibaraki ishikawa iwate kagawa kagoshima kanagawa kawasaki kitakyushu kobe kochi kumamoto kyoto mie miyagi miyazaki nagano nagasaki nagoya nara niigata oita okayama okinawa osaka saga saitama sapporo sendai shiga shimane shizuoka tochigi tokushima tokyo tottori toyama wakayama yamagata yamaguchi yamanashi yokohama"
EC_PUBLIC_SUFFIXES_OTHER="co.uk org.uk ac.uk gov.uk com.au net.au org.au co.nz com.cn com.tw com.hk co.kr com.br co.in github.io pages.dev workers.dev netlify.app vercel.app herokuapp.com"
ec_is_public_suffix() {
    local d="$1"
    [[ "$d" == *.* ]] || return 0
    if [[ "$d" == *.jp ]] && [[ "${d%.jp}" != *.* ]] && [[ " $EC_PUBLIC_SUFFIXES_JP " == *" ${d%.jp} "* ]]; then return 0; fi
    [[ " $EC_PUBLIC_SUFFIXES_OTHER " == *" $d "* ]]
}

# 複数の店の形へ広げられるかを確かめる（何も変えない）。$1 新しいショップのドメイン。広げられなければ理由を 1 行出す（広げられれば何も出さない）。
# もう同じドメインで複数の店の形なら何も出さない（流し直し・途中で止まった続き）
ec_shops_multi_problem() {
    local domain="$1" admin host cur sd cert src
    admin="$(ec_answer ADMIN_HOST 2> /dev/null || true)"
    if ! ec_valid_host "$domain"; then
        printf '「%s」はショップのドメインの形ではありません（小文字のドメイン。例 example.jp）\n' "$domain"
        return 0
    fi
    if ec_is_public_suffix "$domain"; then
        printf '「%s」は公開の接尾辞（誰でもその下にドメインを取れるもの。co.jp・tokyo.jp など）です。自分のドメイン（例 example.co.jp）を渡す\n' "$domain"
        return 0
    fi
    if [ "$domain" = "$admin" ]; then
        printf 'ショップのドメインを管理画面のホスト名（%s）と同じにはできません\n' "$admin"
        return 0
    fi
    if [ "$(ec_shops)" = multi ]; then
        cur="$(ec_answer SHOP_DOMAIN 2> /dev/null || true)"
        [ "$cur" = "$domain" ] || printf 'もう複数の店の構成です（ショップのドメインは %s）。ショップのドメインは替えません（店の URL が変わる）\n' "${cur:-空}"
        return 0
    fi
    host="$(ec_answer SHOP_HOST 2> /dev/null || true)"
    if ! ec_valid_host "$host"; then
        printf '設定ファイル（%s）にショップのホスト（SHOP_HOST）がありません\n' "$EC_ANSWERS"
        return 0
    fi
    if [[ "$host" != *".$domain" ]] || ! ec_valid_label "${host%".$domain"}"; then
        printf '今のショップのホスト %s は %s の直下の名前（<1 段>.%s）ではないので、複数の店の構成に切り替えるとお客さんの URL が変わります（切り替えません）\n' "$host" "$domain" "$domain"
        return 0
    fi
    # セッションの Cookie は店ごとのホストだけ（Domain 属性なし）。ショップのドメイン全体にすると、ある店のセッションがほかの店・管理画面・
    # 同じドメインのほかのサーバーのサイト（www など）へ送られる。ショップはアプリの側でも Domain を付けないが、env で広げてあるなら先に直させる
    sd="$(ec_env_get SESSION_DOMAIN 2> /dev/null || true)"
    case "$sd" in
        '' | null | '(null)') ;;
        *)
            printf '設定 %s の SESSION_DOMAIN が %s です。複数の店の構成では店ごとのホストだけにするので、null にしてから切り替える\n' "$EC_ENV_FILE" "$sd"
            return 0
            ;;
    esac
    # 手で渡した証明書（Cloudflare の Origin CA など）は、ワイルドカード *.<ドメイン> を覆っていること（入口の Caddy は *.<ドメイン> の場所で出す）
    if [ "$(ec_tls_mode)" = manual ]; then
        cert="$EC_CADDY_SECRETS/tls/cert.pem"
        src="$(ec_answer TLS_CERT 2> /dev/null || true)"
        if [ -n "$src" ] && [ -r "$src" ] && [ -r "$cert" ] && ! cmp -s "$src" "$cert"; then
            printf '設定ファイルの証明書 %s が、リバースプロキシの Caddy が使っているもの（%s）と違います。先に sudo ./deploy/install.sh を実行して証明書を替えてから切り替える\n' "$src" "$cert"
            return 0
        fi
        if [ ! -r "$cert" ]; then
            printf 'リバースプロキシの Caddy の証明書 %s が読めません\n' "$cert"
            return 0
        fi
        if ! openssl x509 -in "$cert" -noout -checkhost "$EC_TLS_PROBE_LABEL.$domain" 2> /dev/null | ec_q 'does match'; then
            printf 'リバースプロキシの証明書に *.%s が含まれていません。*.%s を含む証明書を作り直し、設定ファイルの TLS_CERT・TLS_KEY に書いて sudo ./deploy/install.sh を実行してから切り替える（docs/install.md の「店の数」）\n' "$domain" "$domain"
            return 0
        fi
    fi
    return 0
}

# 答えの店の数の形を書き換える（仮のファイルに全部書いてから入れ替える。途中で止まっても、答えは前の形か新しい形のどちらか）。
# $1 single か multi、$2 multi のときのショップのドメイン。single へ戻すとき、答えに SHOP_HOST が無ければ（初めから複数の店で入れた）、今の最初の店のホストを書く。
# ショップのドメイン（SHOP_DOMAIN）は single へ戻しても残す（SHOPS=single があるので 1 店舗と読む。もう一度広げる時に使う）
ec_shops_set_answers() {
    local form="$1" domain="${2:-}" tmp host
    tmp="$(dirname "$EC_ANSWERS")/.$(basename "$EC_ANSWERS").shops.$$"
    cp -p "$EC_ANSWERS" "$tmp" || return 1
    case "$form" in
        multi)
            if ! ec_valid_host "$domain" || ! { ec_kv_set "$tmp" SHOP_DOMAIN "$domain" && ec_kv_set "$tmp" SHOPS multi; }; then
                rm -f "$tmp"
                return 1
            fi
            ;;
        single)
            host="$(ec_answer SHOP_HOST 2> /dev/null || true)"
            [ -n "$host" ] || host="$(ec_shop_host)"
            if ! ec_valid_host "$host" || ! { ec_kv_set "$tmp" SHOP_HOST "$host" && ec_kv_set "$tmp" SHOPS single; }; then
                rm -f "$tmp"
                return 1
            fi
            ;;
        *)
            rm -f "$tmp"
            return 1
            ;;
    esac
    mv -f "$tmp" "$EC_ANSWERS"
}

# 入口が今 $1 の名前で出している証明書が、その名前を覆うか（信頼の確かめはしない。Origin CA の証明書でも見られるように、名前だけを見る）
ec_front_cert_covers() {
    local host="$1" port
    port="$(ec_env_get EC_SUGAR_HTTPS_PORT 2> /dev/null || true)"
    timeout 20 openssl s_client -connect "$(ec_probe_ip):${port:-443}" -servername "$host" < /dev/null 2> /dev/null |
        openssl x509 -noout -checkhost "$host" 2> /dev/null | ec_q 'does match'
}

# 管理画面と最初の店を入口から https で開けるか（3 秒ごとに最大 $1 回）。管理画面は /up が 200、最初の店は / が 500 未満で 404 でない
# （店が見つからない 404 を「開けた」と取り違えない。見直しの軽 4）。どちらも、入口が出す証明書がその名前を覆うことも見る。
# 複数の店の形では管理画面も *.<ドメイン> に覆われるので、dns の取り方ではワイルドカードを取り終えるまで管理画面の握手も通らない（見直しの中 2）。
# すでにある Web サーバーの後ろに置く形は、https を前の Web サーバーが受けるので見ない（後ろの段はヘルスチェックで見た）
EC_SHOPS_HTTPS_TRIES="${EC_SHOPS_HTTPS_TRIES:-60}"
# dns の取り方で、ワイルドカードの証明書を待つ間（3 秒ごとに 100 回 = 5 分）
EC_SHOPS_DNS_TRIES="${EC_SHOPS_DNS_TRIES:-100}"
ec_shops_https_ok() {
    local tries="${1:-$EC_SHOPS_HTTPS_TRIES}" admin shop i code ok_admin=1 ok_shop=1
    [ "$(ec_front)" != proxy ] || return 0
    admin="$(ec_env_get EC_SUGAR_ADMIN_HOST 2> /dev/null || true)"
    shop="$(ec_shop_host)"
    for i in $(seq 1 "$tries"); do
        if [ "$ok_admin" -ne 0 ] && ec_front_cert_covers "$admin" && [ "$(ec_http_code "$admin" /up)" = 200 ]; then ok_admin=0; fi
        if [ "$ok_shop" -ne 0 ] && ec_front_cert_covers "$shop"; then
            code="$(ec_http_code "$shop" /)"
            if ec_code_below_500 "$code" && [ "$code" != 404 ]; then ok_shop=0; fi
        fi
        [ "$ok_admin" -ne 0 ] || [ "$ok_shop" -ne 0 ] || return 0
        [ "$i" -lt "$tries" ] && sleep 3
    done
    [ "$ok_admin" -eq 0 ] || ec_warn "リバースプロキシから https://$admin/up を開けません（証明書に $admin が含まれていないか、200 でない。sudo ec-sugar logs caddy web で理由を見る）"
    [ "$ok_shop" -eq 0 ] || ec_warn "リバースプロキシから https://$shop/ を開けません（証明書に $shop が含まれていないか、500 以上か 404。sudo ec-sugar logs caddy web で理由を見る）"
    return 1
}

# Cloudflare Tunnel の形で、管理画面と最初の店を外（Cloudflare）から一度聞く（公開の DNS で名前を引いて。3 秒ごとに最大 EC_SHOPS_OUTSIDE_TRIES 回。
# cloudflared を作り直した直後は Cloudflare につなぎ直すのに数秒かかる）。管理画面は /up が 200、最初の店は 500 未満で 404 でない
# （cloudflared の行き先に無い名前は http_status:404）。0 開けた、1 開けない
EC_SHOPS_OUTSIDE_TRIES="${EC_SHOPS_OUTSIDE_TRIES:-10}"
ec_shops_outside_code() {
    ec_code_norm "$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 --doh-url "${EC_DOH_URL:-https://1.1.1.1/dns-query}" "https://$1$2" 2> /dev/null || true)"
}
ec_shops_outside_ok() {
    local admin shop i code
    admin="$(ec_env_get EC_SUGAR_ADMIN_HOST 2> /dev/null || true)"
    shop="$(ec_shop_host)"
    for i in $(seq 1 "$EC_SHOPS_OUTSIDE_TRIES"); do
        if [ "$(ec_shops_outside_code "$admin" /up)" = 200 ]; then
            code="$(ec_shops_outside_code "$shop" /)"
            if ec_code_below_500 "$code" && [ "$code" != 404 ]; then return 0; fi
        fi
        [ "$i" -lt "$EC_SHOPS_OUTSIDE_TRIES" ] && sleep 3
    done
    return 1
}

# 答えの店の数の形を、動いている EC-SUGAR に当てる: env の店の数の項目と cloudflared の行き先を書き、入口・後ろの段・アプリを作り直して
# ヘルスチェックを通し、管理画面と最初の店を入口から https で開けるかを見る。0 当たった、1 当たらなかった（呼ぶ側が前の形へ戻す）。
# 作り直すのは web（nginx の受ける名前）・caddy（証明書の場所）・app と queue と scheduler（env の CENTRAL_DOMAINS・EC_SUGAR_SHOPS）・
# cloudflared（行き先は起動の時にだけ読む）。DB・redis は作り直さない（データに触らない）。
# dns の取り方では、ヘルスチェックの https の確かめ（管理画面の /up を 1 分ほど）を外し、ワイルドカードを取るのを待つ確かめ（5 分まで）で見る
# （先に 1 分で落ちて毎回戻していた。見直しの中 2）。
# Cloudflare Tunnel の形は、終わりに外（Cloudflare）からも聞く（見直しの軽 5）。EC_SHOPS_OUTSIDE_REQUIRED=1（替える前は外から開けた）なら、
# 開けなければ当たらなかったとする（替えたせいで外から開けなくなった。前の形へ戻す）。0（替える前から開けなかった）なら、注意を出すだけ
EC_SHOPS_OUTSIDE_REQUIRED=0
ec_shops_apply() {
    local services=(web app queue scheduler) tries="$EC_SHOPS_HTTPS_TRIES" tls=1 rc=0
    ec_env_migrate || { ec_warn "設定の env に新しい名前の行を足せません"; return 1; }
    ec_env_put_shops || { ec_warn "設定ファイルの管理画面かショップのホスト名の形が違うので、env を書けません"; return 1; }
    [ "$(ec_front)" = proxy ] || services+=(caddy)
    if [ "$(ec_front)" = tunnel ]; then
        # ec_tunnel_put_config は止まるときに ec_die で抜けるので、子のシェルで流して返りで受ける
        (ec_tunnel_dir_ensure && ec_tunnel_put_config > /dev/null) || { ec_warn "cloudflared の行き先を書けません"; return 1; }
        services+=(cloudflared)
    fi
    if ! ec_dc up -d --no-deps --force-recreate "${services[@]}" > /dev/null 2>&1; then
        ec_warn "リバースプロキシ・バックエンド・アプリを作り直せませんでした（sudo ec-sugar logs で理由を見る）"
        return 1
    fi
    if [ "$(ec_tls_mode)" = dns ]; then
        tls=0
        tries="$EC_SHOPS_DNS_TRIES"
        ec_say "証明書を Cloudflare の DNS の鍵で取り直しています（ワイルドカード。1〜2 分、管理画面とショップの https が開けないことがあります。5 分まで待ちます）"
    fi
    EC_HEALTH_TLS="$tls" ec_health "$(ec_env_get EC_SUGAR_IMAGE)" || return 1
    ec_shops_https_ok "$tries" || return 1
    [ "$(ec_front)" = tunnel ] || return 0
    # 中から接続元を偽れないこと（偽れたら当たらなかったとする）と、外から管理画面と最初の店が開くこと
    ec_check_tunnel > /dev/null 2>&1 || rc=$?
    if [ "$rc" -eq 1 ]; then
        ec_warn "Cloudflare Tunnel の構成で、接続元を偽れるか本当の接続元が届いていません（sudo ec-sugar check-front で詳しく）"
        return 1
    fi
    if ec_shops_outside_ok; then
        ec_ok "外（Cloudflare）から管理画面と https://$(ec_shop_host)/ が開ける"
        return 0
    fi
    if [ "$EC_SHOPS_OUTSIDE_REQUIRED" = 1 ]; then
        ec_warn "替える前は外（Cloudflare）から開けたのに、替えた後は管理画面か https://$(ec_shop_host)/ が外から開けません（sudo ec-sugar logs cloudflared）"
        return 1
    fi
    ec_warn "大事: 外（Cloudflare）から管理画面か https://$(ec_shop_host)/ が開けません（替える前から開けなかった）。Cloudflare の DNS の CNAME とトンネルを見て、sudo ec-sugar check-front を実行する"
    return 0
}

# 形を替える（答え → 当てる）。駄目なら前の形へ戻す（お客さんの店を開けたままにする）。$1 single か multi、$2 multi のショップのドメイン。
# 返り: 0 替えた（もうその形なら当て直した）、1 答えを書けず何も変えていない、2 替えられず前の形へ戻した、3 当てられず前の形へも戻せなかった
# （もうその形だったときの当て直しの失敗も 3。前の形が無い）。Tunnel の形では、替える前に外から開けるかを見ておく（替えた後に開けなくなったら戻す）
ec_shops_switch() {
    local form="$1" domain="${2:-}" before before_domain commit
    before="$(ec_shops)"
    before_domain="$(ec_answer SHOP_DOMAIN 2> /dev/null || true)"
    commit="$(ec_state_get current_commit 2> /dev/null || true)"
    EC_SHOPS_OUTSIDE_REQUIRED=0
    if [ "$(ec_front)" = tunnel ] && ec_shops_outside_ok; then EC_SHOPS_OUTSIDE_REQUIRED=1; fi
    ec_shops_set_answers "$form" "$domain" || { ec_warn "設定ファイル $EC_ANSWERS を書けません（権限と空き容量を見る）"; return 1; }
    if ec_shops_apply; then
        ec_history "$(ec_now)" "shops_$form" "$commit" "$commit" "店の数の構成: ${before} → ${form}（ショップのドメイン $(ec_shop_domain)）" 2> /dev/null || true
        return 0
    fi
    [ "$before" != "$form" ] || return 3
    ec_warn "店の数の構成を $form にできなかったので、前の構成（${before}）へ戻します"
    if ec_shops_set_answers "$before" "$before_domain" && ec_shops_apply; then
        ec_history "$(ec_now)" "shops_reverted" "$commit" "$commit" "店の数の構成を $form にできず $before へ戻した" 2> /dev/null || true
        return 2
    fi
    return 3
}

# 店のホスト（中央 DB の domains）を 1 行ずつ。読めなければ何も出さず 1
ec_shop_domains() {
    local out line
    out="$(ec_dc exec -T db psql -U ec -d ec_central -Atc 'SELECT domain FROM domains ORDER BY domain' < /dev/null 2> /dev/null)" || return 1
    while IFS= read -r line; do
        ! ec_valid_host "$line" || printf '%s\n' "$line"
    done <<< "$out"
}

# 複数の店の形で、DNS に足す行（人が足す）を出す。$@ 店のホスト（無ければ中央 DB の domains から）。ショップのドメインの直下の名前だけ出す
# （ほかの名前は入口が受けない）。Cloudflare Tunnel の形は、店ごとの名前の CNAME を勧める（ショップのドメインに別のサーバーのサイトがあるとき、
# ワイルドカードの CNAME は知らない名前〔打ち間違い・消し忘れたサイトの名前〕まで全部トンネルへ連れてくる。ADR 0090）
ec_shops_dns_note() {
    local domain admin id h bind
    local hosts=()
    domain="$(ec_answer SHOP_DOMAIN 2> /dev/null || true)"
    admin="$(ec_answer ADMIN_HOST 2> /dev/null || true)"
    if [ "$#" -gt 0 ]; then
        hosts=("$@")
    else
        while IFS= read -r h; do [ -z "$h" ] || hosts+=("$h"); done < <(ec_shop_domains || true)
    fi
    case "$(ec_front)" in
        tunnel)
            id="$(ec_tunnel_id || true)"
            printf '\n   Cloudflare の DNS（ゾーン %s）に、まだ無い行を足してください（種類 CNAME・プロキシ済み〔オレンジの雲〕。同じ名前の A・AAAA があれば消す）:\n\n' "$domain"
            for h in ${hosts[@]+"${hosts[@]}"}; do
                if [[ "$h" == *".$domain" ]] && ec_valid_label "${h%".$domain"}" && [ "$h" != "$admin" ]; then
                    printf '       種類 CNAME   名前 %-40s 値 %s.cfargotunnel.com\n' "$h" "${id:-<トンネルの ID>}"
                fi
            done
            printf '       種類 CNAME   名前 %-40s 値 %s.cfargotunnel.com   （Stripe Connect を使うときだけ）\n' "api.$domain" "${id:-<トンネルの ID>}"
            printf '\n   店を足したら、その店のホストの行を足す（cloudflared tunnel route dns <トンネルの名前> <ホスト名> でも作れる。sudo ec-sugar shops で一覧が出る）。\n'
            printf '   ワイルドカード（*.%s）の CNAME 1 行でも動くが、%s に別のサーバーのサイトがあるなら勧めない（知らない名前まで全部このサーバーへ来る）\n' "$domain" "$domain"
            ;;
        proxy)
            printf '\n   すでにある Web サーバーで、店のホスト（*.%s のワイルドカードか、店ごとの名前）を EC-SUGAR のソケットへ送る設定と証明書を足してください（docs/install.md の「すでにある Web サーバーの後ろに置く」）\n' "$domain"
            ;;
        *)
            bind="$(ec_env_get EC_SUGAR_BIND 2> /dev/null || true)"
            printf '\n   DNS に、まだ無い行を足してください（店のホストをリバースプロキシへ向ける。ワイルドカード 1 行か、店ごとの名前）:\n\n'
            printf '       種類 A   名前 %-40s 値 %s\n' "*.$domain" "${bind:-<このサーバーの公開の IPv4>}"
            ;;
    esac
}

# ---------- 中で照らす（ADR 0105） ----------
# 入られた後の書き換えに気づく。あるべき姿を root だけの置き場に控え、ec-sugar-integrity.timer が毎分照らす。結果は Ed25519 で署名した
# 1 行の JSON（integrity.json と integrity.json.sig）にして、nginx がどのホストでも /.well-known/ec-sugar/ で返す（外の見張り ec-sugar-sentinel が
# 取りに来て確かめる）。違いと「外の見張りが来ていない」「錠が 30 分を超えて握られている」は、今の知らせの道（ec_host_report → 運営の様子の
# 要対応とオーナーへのメール。ADR 0043）にも載せる。
# あるべき姿（控え expected。1 行 1 項目「種類 名前 値」）:
#   service <サービス> <イメージ ID> <設定の指紋>   compose が作ったコンテナ（一度きりでないもの。止まっているものも）ごとに 1 行
#   code <サービス> <sha256>                       app と web のイメージの中のコードの木（書ける差し込みと tmpfs は除く。10 分ごとに照らす）
#   file <短い名前> <sha256>                       compose と入口の設定・unit・運用の道具・cloudflared の行き先・信じる鍵
#   setting <env|answers>:<名前> <sha256>          .env.production と答えの値（サーバーだけの塩つきの hash。平文は控えない）
# 決まり:
#   - 公開の JSON の problems は種類だけ（image・service・container・config・code・file・docker・expected・lock）。細かい名前
#     （image:app・file:nginx-front-proxy.inc など）は中の知らせ（state・運営の様子・メール・status）にだけ出す。値・パス・秘密・版は出さない
#   - 署名の鍵・控え・塩・外の見張りの合い言葉はどのコンテナにも差し込まない（合い言葉だけは web の env で nginx に渡す。生存の確かめにしか使わない値）
#   - 更新などが錠（ec_lock。root だけの /run/ec-sugar/lock）を持っている間は照らさない。"busy":true を足して署名だけ新しくする。30 分を超えたら lock:held
#   - 一度きりのコンテナ（マイグレーション・バックアップ）は、錠を持っている間だけ許す（照らすのは錠の無い時なので、見えたら container:extra）
#   - 控え直すのは、そのコマンドが作り直した項目だけ（ec_integrity_record。ほかの項目は前の控えの値を残す）
EC_INTEGRITY_DIR=$EC_TRUST_DIR/integrity
# 書いた様子の置き場（integrity.json と integrity.json.sig。だれでも読める 0644）。docker-compose.prod.yml が web の /usr/share/ec-sugar/integrity へ
# 読み取り専用で差し込む（compose はこの道筋を決め打ちしている。docker/check-compose.sh がそろっているかを見る）
EC_INTEGRITY_PUBLIC=/var/lib/ec-sugar/integrity
# web（nginx）が、合い言葉の付いた外の見張りの取得の時刻だけを書く記録（web の中の tmpfs。ホストは毎分 docker exec で時刻を見て空にする）
EC_INTEGRITY_FETCH_LOG=/var/log/ec-sugar-integrity/fetch.log
# 外の見張りが一度でも来たことがあって、これより長く来ていなければ困りごと（秒）
EC_INTEGRITY_SENTINEL_SILENT=1800
# 錠がこれより長く握られていれば困りごと lock:held（秒）
EC_INTEGRITY_BUSY_LIMIT=1800
# コードの木を照らす間（秒。毎分の照らしの中で間引く）
EC_INTEGRITY_CODE_EVERY=600
# 中の知らせに並べる困りごとの数の上限
EC_INTEGRITY_MAX_PROBLEMS=20
# 報告（ec_host_report）を渡せなかった時に、渡し直すまで待つ分（app が動いていない間、毎分の照らしを長くしない）
EC_INTEGRITY_REPORT_RETRY_MIN=10
# 公開の JSON に出してよい困りごとの種類
EC_INTEGRITY_KINDS="code config container docker expected file image lock service"
# 運用の道具が入れる unit（これだけは install-units が作り直す。ほかの ec-sugar* の unit・drop-in は前の控えのまま）
EC_INTEGRITY_OWN_UNITS="ec-sugar.service ec-sugar-update.service ec-sugar-update.timer ec-sugar-backup.service ec-sugar-backup.timer ec-sugar-firewall.service ec-sugar-watch.service ec-sugar-watch.timer ec-sugar-integrity.service ec-sugar-integrity.timer ec-sugar-offsite.service ec-sugar-offsite.timer ec-sugar-backup.timer.d-10-timezone.conf"
# コードの木（サービスごと。イメージの中の読み取り専用の所。-xdev で書ける差し込み〔storage〕と tmpfs を除く）
EC_INTEGRITY_CODE_DIRS_app="/var/www/html /usr/local/bin /usr/local/sbin /usr/local/lib/php /usr/local/etc"
EC_INTEGRITY_CODE_DIRS_web="/var/www/html/public /usr/sbin /usr/lib/nginx /etc/nginx /docker-entrypoint.sh /docker-entrypoint.d"

# UTC の RFC3339（小数なし・Z）
ec_integrity_now() { date -u +%Y-%m-%dT%H:%M:%SZ; }
# ファイル $1 の sha256（16 進 64 桁）。標準入力から読むのは ec_integrity_sha_in
ec_integrity_sha() { ec_integrity_sha_in < "$1"; }
ec_integrity_sha_in() {
    local out
    if command -v sha256sum > /dev/null 2>&1; then out="$(sha256sum)" || return 1; else out="$(shasum -a 256)" || return 1; fi
    out="${out%% *}"
    [[ "$out" =~ ^[0-9a-f]{64}$ ]] || return 1
    printf '%s' "$out"
}
# 困りごとの名前の形（小文字の英数字と . _ - を : でつなぐ）と、公開の JSON の種類
ec_integrity_valid_problem() { [ "${#1}" -le 100 ] && [[ "$1" =~ ^[a-z][a-z0-9-]*(:[a-z0-9][a-z0-9._-]*){1,2}$ ]]; }
ec_integrity_valid_service() { [[ "$1" =~ ^[a-z0-9][a-z0-9_-]{0,62}$ ]]; }
ec_integrity_valid_image() { [[ "$1" =~ ^sha256:[0-9a-f]{64}$ ]]; }
ec_integrity_valid_hex() { [[ "$1" =~ ^[0-9a-f]{64}$ ]]; }
# 短い名前（ファイルの名前から。小文字にし、決めた文字のほかは _ にする）
ec_integrity_name() { printf '%s' "$1" | tr 'A-Z' 'a-z' | tr -c 'a-z0-9._-' '_'; }
# 16 進の乱数（$1 バイト）
ec_integrity_random_hex() { od -An -tx1 -N"$1" /dev/urandom | tr -d ' \n'; }

# 置き場と鍵と識別子と塩を用意する（無ければ作る。あれば権限をそろえ直す）。root で
ec_integrity_ensure() {
    local old_umask tmp f n
    install -d -m 0755 "$EC_TRUST_DIR" || return 1
    install -d -m 0700 "$EC_INTEGRITY_DIR" || return 1
    install -d -m 0755 "$EC_INTEGRITY_PUBLIC" || return 1
    chmod 0700 "$EC_INTEGRITY_DIR" && chmod 0755 "$EC_INTEGRITY_PUBLIC" || return 1
    if [ "$(id -u)" -eq 0 ]; then chown 0:0 "$EC_INTEGRITY_DIR" "$EC_INTEGRITY_PUBLIC" || return 1; fi
    if [ ! -s "$EC_INTEGRITY_DIR/key" ]; then
        old_umask="$(umask)"
        umask 077
        tmp="$EC_INTEGRITY_DIR/.key.tmp.$$"
        if ! openssl genpkey -algorithm ed25519 -out "$tmp" 2> /dev/null; then
            rm -f "$tmp"
            umask "$old_umask"
            ec_warn "署名の鍵（Ed25519）を作れません（openssl 3 が要る: openssl version で見る）"
            return 1
        fi
        umask "$old_umask"
        { chmod 0400 "$tmp" && mv -f "$tmp" "$EC_INTEGRITY_DIR/key"; } || { rm -f "$tmp"; return 1; }
    fi
    chmod 0400 "$EC_INTEGRITY_DIR/key" || return 1
    # 識別子（16 桁）と、設定の値の hash の塩（64 桁）。どちらも一度だけ作って保つ
    for f in server:8 salt:32; do
        n="${f#*:}"
        f="${f%%:*}"
        if ! [[ "$(head -n1 "$EC_INTEGRITY_DIR/$f" 2> /dev/null || true)" =~ ^[0-9a-f]{$((n * 2))}$ ]]; then
            tmp="$EC_INTEGRITY_DIR/.$f.tmp.$$"
            { (umask 077 && ec_integrity_random_hex "$n" > "$tmp") && mv -f "$tmp" "$EC_INTEGRITY_DIR/$f"; } || { rm -f "$tmp"; return 1; }
        fi
        chmod 0600 "$EC_INTEGRITY_DIR/$f" || return 1
    done
}
ec_integrity_server() { head -n1 "$EC_INTEGRITY_DIR/server" 2> /dev/null; }
# 控えの世代（無ければ 0）
ec_integrity_generation() {
    local g
    g="$(sed -n 's/^generation=\([0-9]\{1,9\}\)$/\1/p' "$EC_INTEGRITY_DIR/expected" 2> /dev/null | awk 'NR == 1')"
    printf '%s' "${g:-0}"
}
# 公開の鍵（PEM）を出す
ec_integrity_pubkey() { openssl pkey -in "$EC_INTEGRITY_DIR/key" -pubout; }

# 外の見張りの合い言葉（32 桁の 16 進。root の 0600 に一度だけ作り、nginx へは env の EC_SUGAR_SENTINEL_TOKEN で渡す）を出す
ec_integrity_token() {
    local f="$EC_INTEGRITY_DIR/sentinel_token" tmp
    if ! [[ "$(head -n1 "$f" 2> /dev/null || true)" =~ ^[0-9a-f]{32}$ ]]; then
        tmp="$EC_INTEGRITY_DIR/.sentinel_token.tmp.$$"
        { (umask 077 && ec_integrity_random_hex 16 > "$tmp") && mv -f "$tmp" "$f"; } || { rm -f "$tmp"; return 1; }
    fi
    chmod 0600 "$f" || return 1
    head -n1 "$f"
}

# 錠（ec_lock）を今だれかが持っているか（持っていれば 0）。取れたらすぐ放す（照らしは更新などを待たせない）。
# 錠は root だけの置き場の中（root でない人は握れない）。flock が無ければ、錠を取る処理（ec_lock）も動けないので、持っている処理は無い
ec_integrity_busy() {
    command -v flock > /dev/null 2>&1 || return 1
    ec_lock_dir || return 1
    exec 7> "$EC_LOCK" || return 1
    if flock -n 7; then
        flock -u 7
        exec 7>&-
        return 1
    fi
    exec 7>&-
    return 0
}

# このサーバーのコンテナを 1 行ずつ「ID・動いているか・イメージ ID・compose のプロジェクト・サービス・一度きりか・名前・設定の指紋」（タブ区切り）で出す。
# 設定の指紋は、HostConfig の Privileged・CapAdd・CapDrop・Binds・NetworkMode・PidMode・IpcMode・UsernsMode・SecurityOpt・ReadonlyRootfs・Devices・
# PortBindings・Tmpfs と Config の User・Entrypoint・Cmd と、差し込み（種類・元・先・書けるか。並べ替えてから）の sha256。名札は偽れるので、
# 照らすのは名札でなく、この指紋とイメージ ID。Docker に聞けなければ 1
ec_integrity_containers() {
    local ids out fmt id running image project service oneoff cname cfg mounts m sorted fp
    ids="$(docker ps -aq --no-trunc 2> /dev/null)" || return 1
    [ -n "$ids" ] || return 0
    fmt=$'{{.Id}}\t{{.State.Running}}\t{{.Image}}\t{{index .Config.Labels "com.docker.compose.project"}}\t{{index .Config.Labels "com.docker.compose.service"}}\t{{index .Config.Labels "com.docker.compose.oneoff"}}\t{{.Name}}\t'
    fmt+='{{json .HostConfig.Privileged}} {{json .HostConfig.CapAdd}} {{json .HostConfig.CapDrop}} {{json .HostConfig.Binds}} {{json .HostConfig.NetworkMode}} '
    fmt+='{{json .HostConfig.PidMode}} {{json .HostConfig.IpcMode}} {{json .HostConfig.UsernsMode}} {{json .HostConfig.SecurityOpt}} {{json .HostConfig.ReadonlyRootfs}} '
    fmt+='{{json .HostConfig.Devices}} {{json .HostConfig.PortBindings}} {{json .HostConfig.Tmpfs}} {{json .Config.User}} {{json .Config.Entrypoint}} {{json .Config.Cmd}}'
    fmt+=$'\t{{range .Mounts}}{{.Type}},{{.Source}},{{.Destination}},{{.RW}};{{end}}'
    # 一覧の後に消えたコンテナがあると inspect は失敗で終わるが、残りは出す。何も出なければ聞けなかったとする
    # shellcheck disable=SC2086  # ID は空白で分けて渡す
    out="$(docker inspect --format "$fmt" $ids 2> /dev/null || true)"
    [ -n "$out" ] || return 1
    while IFS=$'\t' read -r id running image project service oneoff cname cfg mounts; do
        [ -n "$id" ] || continue
        # 差し込みの並びは決まっていない（Docker の中の map）ので、並べ替えてから指紋に入れる
        sorted="$(printf '%s' "$mounts" | tr ';' '\n' | LC_ALL=C sort | tr '\n' ';')"
        fp="$(printf '%s\n%s\n' "$cfg" "$sorted" | ec_integrity_sha_in)" || fp=unreadable
        printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$id" "$running" "$image" "$project" "$service" "$oneoff" "${cname#/}" "$fp"
    done <<< "$out"
}

# 答えの INTEGRITY_ALLOW_CONTAINERS（「<名前>@<イメージ ID>」か「<名前>@config:<設定の指紋>」を , で区切る。名前には * が使える）に、
# 名前 $1・イメージ ID $2・設定の指紋 $3 の組が当たるか。名前は --name で偽れるので、名前だけでは許さない。展開（glob）を掛けずに読む
ec_integrity_allowed() {
    local name="$1" image="$2" cfg="$3" list items item pat want
    list="$(ec_answer INTEGRITY_ALLOW_CONTAINERS 2> /dev/null || true)"
    [ -n "$list" ] || return 1
    IFS=', ' read -r -a items <<< "$list"
    for item in ${items[@]+"${items[@]}"}; do
        [[ "$item" =~ ^([A-Za-z0-9_.*-]{1,128})@(sha256:[0-9a-f]{64}|config:[0-9a-f]{64})$ ]] || continue
        pat="${BASH_REMATCH[1]}"
        want="${BASH_REMATCH[2]}"
        [ "$want" = "$image" ] || [ "$want" = "config:$cfg" ] || continue
        # shellcheck disable=SC2053  # 名前は形（*）で当てる（[[ ]] の中なのでファイル名には展開しない）
        [[ "$name" == $pat ]] && return 0
    done
    return 1
}

# 照らすファイルを「短い名前<タブ>道筋」で 1 行ずつ出す（compose が実際に読むもの。ec_dc と同じ選び方）。compose の置き場を決められなければ 1
ec_integrity_files() {
    local dir front f
    dir="$(ec_compose_dir)" || return 1
    front="$(ec_front)"
    printf 'compose\t%s\n' "$dir/docker-compose.prod.yml"
    if [ "$front" = proxy ] && [ -f "$dir/docker-compose.proxy.yml" ]; then printf 'compose-proxy\t%s\n' "$dir/docker-compose.proxy.yml"; fi
    if [ "$front" = tunnel ] && [ -f "$dir/docker-compose.tunnel.yml" ]; then printf 'compose-tunnel\t%s\n' "$dir/docker-compose.tunnel.yml"; fi
    if ec_is_isolated; then printf 'compose-isolated\t%s\n' "$dir/docker-compose.isolated.yml"; fi
    # 入口の設定: nginx の雛形と外の口、Caddy の設定の置き場の全部（後ろに置く形は Caddy を起こさないので見ない）
    printf 'nginx-prod.conf\t%s\n' "$dir/docker/nginx/prod.conf"
    if [ "$front" = proxy ]; then
        printf 'nginx-front-proxy.inc\t%s\n' "$dir/docker/nginx/front-proxy.inc"
    else
        printf 'nginx-front-caddy.inc\t%s\n' "$dir/docker/nginx/front-caddy.inc"
        for f in "$dir"/docker/caddy/etc/*; do
            [ ! -f "$f" ] || printf 'caddy-%s\t%s\n' "$(ec_integrity_name "$(basename "$f")")" "$f"
        done
    fi
    # Cloudflare Tunnel の形: cloudflared の行き先と、入口の Caddy を確かめる元の証明書（トークンは見ない）
    if [ "$front" = tunnel ]; then
        printf 'tunnel-config\t%s\n' "$EC_TUNNEL_DIR/config.yml"
        if [ -e "$EC_TUNNEL_DIR/$EC_TUNNEL_CA_NAME" ]; then printf 'tunnel-ca\t%s\n' "$EC_TUNNEL_DIR/$EC_TUNNEL_CA_NAME"; fi
    fi
    # systemd の unit と drop-in（ec-sugar で始まるものの全部。足された unit も見つける）
    for f in "$EC_UNIT_DIR"/ec-sugar.service "$EC_UNIT_DIR"/ec-sugar-*.service "$EC_UNIT_DIR"/ec-sugar-*.timer; do
        [ ! -f "$f" ] || printf 'unit-%s\t%s\n' "$(ec_integrity_name "$(basename "$f")")" "$f"
    done
    for f in "$EC_UNIT_DIR"/ec-sugar*.d/*; do
        [ ! -f "$f" ] || printf 'unit-%s-%s\t%s\n' "$(ec_integrity_name "$(basename "$(dirname "$f")")")" "$(ec_integrity_name "$(basename "$f")")" "$f"
    done
    # 運用の道具（/usr/local/bin/ec-sugar の指す先と lib）・compose の守りの検査・タグの署名で信じる鍵
    printf 'tool-ec-sugar\t%s\n' "$EC_BIN"
    for f in "$EC_LIB_DIR"/lib/*; do
        [ ! -f "$f" ] || printf 'tool-%s\t%s\n' "$(ec_integrity_name "$(basename "$f")")" "$f"
    done
    if [ -e "$EC_LIB_DIR/check-compose.sh" ]; then printf 'tool-check-compose.sh\t%s\n' "$EC_LIB_DIR/check-compose.sh"; fi
    if [ -e "$EC_TRUST_FILE" ]; then printf 'trust-signers\t%s\n' "$EC_TRUST_FILE"; fi
    return 0
}

# 照らすファイルの今の値を「file <短い名前> <sha256・missing・elsewhere・unreadable>」で 1 行ずつ出す
ec_integrity_hashes() {
    local list name path value
    list="$(ec_integrity_files)" || return 1
    while IFS=$'\t' read -r name path; do
        [ -n "$name" ] || continue
        if [ "$name" = tool-ec-sugar ] && [ "$(readlink -f "$path" 2> /dev/null || true)" != "$(readlink -f "$EC_LIB_DIR/ec-sugar" 2> /dev/null || true)" ]; then
            # /usr/local/bin/ec-sugar が、入れた道具でない所を指している
            value=elsewhere
        elif [ -f "$path" ] && [ -r "$path" ]; then
            value="$(ec_integrity_sha "$path")" || value=unreadable
        else
            value=missing
        fi
        printf 'file %s %s\n' "$name" "$value"
    done <<< "$list"
}

# 設定の値（.env.production と答え）を「setting <env|answers>:<名前> <塩つきの sha256>」で 1 行ずつ出す。値はコマンドの引数に渡さない（標準入力だけ）。
# 同じ名前が 2 度あれば初めの行（ec_kv_get と同じ）
ec_integrity_settings() {
    local salt src file line name h
    local -A seen=()
    salt="$(head -n1 "$EC_INTEGRITY_DIR/salt" 2> /dev/null || true)"
    [[ "$salt" =~ ^[0-9a-f]{64}$ ]] || return 1
    for src in env answers; do
        if [ "$src" = env ]; then file="$EC_ENV_FILE"; else file="$EC_ANSWERS"; fi
        [ -r "$file" ] || continue
        while IFS= read -r line || [ -n "$line" ]; do
            [[ "$line" =~ ^([A-Za-z_][A-Za-z0-9_]{0,99})=(.*)$ ]] || continue
            name="$src:${BASH_REMATCH[1]}"
            [ -z "${seen[$name]:-}" ] || continue
            seen[$name]=1
            h="$(printf '%s\n%s\n%s' "$salt" "$name" "${BASH_REMATCH[2]}" | ec_integrity_sha_in)" || return 1
            printf 'setting %s %s\n' "$name" "$h"
        done < "$file"
    done
}

# コードの木の hash を出す（$1 コンテナの ID、$2 サービス）。中で動くのは決まったスクリプトだけで、答えは 64 桁の 16 進だけを読む（大きさの上限つき）
ec_integrity_code_hash() {
    local id="$1" service="$2" dirs var out
    var="EC_INTEGRITY_CODE_DIRS_$service"
    dirs="${!var:-}"
    [ -n "$dirs" ] || return 1
    # shellcheck disable=SC2016  # コンテナの中の sh が展開する
    out="$(docker exec "$id" sh -c 'for d in '"$dirs"'; do [ -e "$d" ] || continue; find "$d" -xdev -type f -exec sha256sum {} + 2> /dev/null; find "$d" -xdev -type l 2> /dev/null | while IFS= read -r l; do printf "L %s %s\n" "$l" "$(readlink "$l")"; done; done | LC_ALL=C sort | sha256sum' 2> /dev/null | head -c 100 || true)"
    out="${out%% *}"
    ec_integrity_valid_hex "$out" || return 1
    printf '%s' "$out"
}

# コードの木を照らすサービス（app と web）の、動いている一度きりでないコンテナの ID を「サービス ID」で出す。$1 コンテナの一覧
ec_integrity_code_targets() {
    printf '%s\n' "$1" | awk -F '\t' -v p="$EC_PROJECT" '$2 == "true" && $4 == p && $6 != "True" && ($5 == "app" || $5 == "web") && !seen[$5]++ {print $5, $1}'
}

# 今の姿（控えの本体）を出す。並びは決まった順。$1 が code ならコードの木も（重いので、控える時と 10 分ごとの照らしだけ）。
# Docker に聞けない・compose の置き場を決められない・塩が無ければ 1
ec_integrity_snapshot() {
    local with_code="${1:-}" listing hashes settings id running image project service oneoff cname cfg lines=() h
    listing="$(ec_integrity_containers)" || return 1
    hashes="$(ec_integrity_hashes)" || return 1
    settings="$(ec_integrity_settings)" || return 1
    while IFS=$'\t' read -r id running image project service oneoff cname cfg; do
        [ -n "$id" ] && [ "$project" = "$EC_PROJECT" ] && [ "$oneoff" != True ] || continue
        ec_integrity_valid_service "$service" && ec_integrity_valid_image "$image" && ec_integrity_valid_hex "$cfg" || continue
        lines+=("service $service $image $cfg")
    done <<< "$listing"
    if [ "$with_code" = code ]; then
        while read -r service id; do
            [ -n "$service" ] || continue
            h="$(ec_integrity_code_hash "$id" "$service")" && lines+=("code $service $h")
        done < <(ec_integrity_code_targets "$listing")
    fi
    { [ "${#lines[@]}" -eq 0 ] || printf '%s\n' "${lines[@]}"; printf '%s\n' "$hashes" "$settings"; } | grep -v '^$' | LC_ALL=C sort
}

# 控えの 1 項目（種類 $1・名前 $2）の分け方（控え直す時に、どのコマンドがどれを作り直したか）:
#   service・code・compose（compose と入口の設定）・tools・units（道具が入れる unit）・units-extra（ほかの ec-sugar* の unit と drop-in）・
#   tunnel・trust・env:<名前>・answers:<名前>
ec_integrity_category() {
    case "$1" in
        service) printf 'service' ;;
        code) printf 'code' ;;
        setting) printf '%s' "$2" ;;
        file)
            case "$2" in
                compose | compose-* | nginx-* | caddy-*) printf 'compose' ;;
                tool-*) printf 'tools' ;;
                unit-*) if [[ " $EC_INTEGRITY_OWN_UNITS " == *" ${2#unit-} "* ]]; then printf 'units'; else printf 'units-extra'; fi ;;
                tunnel-*) printf 'tunnel' ;;
                trust-*) printf 'trust' ;;
                *) printf 'other' ;;
            esac
            ;;
        *) printf 'other' ;;
    esac
}
# 困りごとの名前 $1 が、作り直した分け方の一覧（$2。空白区切り）の中の違いか（設定のコマンドの後に、ほかの違いが無いかを見る）
ec_integrity_problem_in() {
    local p="$1" cats=" $2 " name
    case "$p" in
        image:* | service:missing:*) [[ "$cats" == *" service "* ]] ;;
        config:*) [[ "$cats" == *" service "* ]] || [[ "$cats" == *" service-config "* ]] ;;
        code:*) [[ "$cats" == *" code "* ]] ;;
        file:env-* | file:answers-*)
            [[ "$cats" == *" settings "* ]] && return 0
            name="${p#file:}"
            # 名前は小文字にして出しているので、分け方の一覧の名前も小文字にして比べる
            [[ "$(printf '%s' "$cats" | tr 'A-Z' 'a-z' | tr ':' '-')" == *" ${name} "* ]]
            ;;
        file:*) [[ "$cats" == *" $(ec_integrity_category file "${p#file:}") "* ]] ;;
        *) return 1 ;;
    esac
}

# compose の置き場のファイル（compose・入口の設定）が、確かめたコミットの git の中身とそろっているか（作業場所・root だけの置き場の
# どちらでも。git status には頼らない〔skip-worktree・assume-unchanged で隠せる〕）。そろわなければ 1
ec_integrity_compose_from_git() {
    local dir commit list name path rel want have
    dir="$(ec_compose_dir)" || return 1
    if [ "$(ec_channel 2> /dev/null || true)" = release ]; then
        commit="$(ec_state_get compose_commit 2> /dev/null || true)"
        ec_valid_commit "$commit" || commit="$(ec_state_get current_commit 2> /dev/null || true)"
    else
        commit="$(ec_state_get current_commit 2> /dev/null || true)"
    fi
    ec_valid_commit "$commit" || return 1
    list="$(ec_integrity_files)" || return 1
    while IFS=$'\t' read -r name path; do
        [ "$(ec_integrity_category file "$name")" = compose ] || continue
        rel="${path#"$dir"/}"
        [ "$rel" != "$path" ] || return 1
        want="$(ec_git cat-file -p "$commit:$rel" 2> /dev/null | ec_integrity_sha_in)" || return 1
        have="$(ec_integrity_sha "$path" 2> /dev/null)" || return 1
        [ "$want" = "$have" ] || return 1
    done <<< "$list"
    return 0
}

# あるべき姿を控え直す。$1 always（作り直したコマンドの後）か if-ok（設定のコマンドの後。控える直前に照らし直し、そのコマンドが書いたもの
# のほかに違いがあれば控えずに止める）。$2 から後は、そのコマンドが作り直した分け方（ec_integrity_category の名前。all は全部、settings は
# env と答えの全部、settings-new は前の控えに無かった設定の名前、service-config は設定の指紋だけが変わったサービス）。
# 作り直した項目だけを今の値に替え、ほかは前の控えの値を残す（足された unit などを黙って「あるべき姿」にしない）。前の控えが無ければ全部を控える。
# compose は、確かめたコミットの git の中身とそろっている時だけ替える。中身が前と同じなら書かない（世代を上げない）。控えた・同じだったら 0、
# 控えなかったら 1（注意を出す）。錠（ec_lock）は呼ぶ側が持つ
ec_integrity_record() {
    local mode="${1:-always}" cats="" new old merged gen tmp problems p bad="" key kind name value cat take with_code=""
    shift || true
    cats=" $* "
    ec_integrity_ensure || { ec_warn "内部の整合性チェックの保存先か鍵を用意できません（基準の状態を記録し直していません）"; return 1; }
    local -A old_val=() new_val=() keys=()
    old="$(grep -E '^(service|code|file|setting) ' "$EC_INTEGRITY_DIR/expected" 2> /dev/null || true)"
    [ -n "$old" ] || cats=" all "
    if [ "$mode" = if-ok ] && [ "$cats" != " all " ]; then
        problems="$(EC_INTEGRITY_CODE_FORCE=1 ec_integrity_scan)"
        while IFS= read -r p; do
            [ -n "$p" ] || continue
            ec_integrity_problem_in "$p" "$cats" || bad="${bad:+$bad }$p"
        done <<< "$problems"
        if [ -n "$bad" ]; then
            ec_warn "内部の整合性チェックで、このコマンドが書いていない所にも違いがあるので、基準の状態を記録し直していません（${bad}）。調べてから sudo ec-sugar integrity record"
            return 1
        fi
    fi
    if [[ "$cats" == *" all "* ]] || [[ "$cats" == *" code "* ]]; then with_code=code; fi
    new="$(ec_integrity_snapshot "$with_code")" || { ec_warn "Docker か compose のディレクトリに聞けないので、基準の状態を記録し直していません"; return 1; }
    if { [[ "$cats" == *" all "* ]] || [[ "$cats" == *" compose "* ]]; } && ! ec_integrity_compose_from_git; then
        ec_warn "compose のディレクトリのファイルが、確認したコミットの git の中身とそろわないので、compose とリバースプロキシの設定は前の記録のままにします（sudo ec-sugar integrity check で見る）"
        if [[ "$cats" == *" all "* ]] && [ -n "$old" ]; then cats=" service code tools units units-extra tunnel trust settings "; elif [[ "$cats" != *" all "* ]]; then cats="${cats/ compose / }"; fi
    fi
    # サービスは 1 つのまとまり（同じサービスの行が並ぶ）として替えるか残す。ほかは「種類 名前」ごと
    while read -r kind name value; do
        [ -n "$kind" ] || continue
        if [ "$kind" = service ]; then old_val["service"]+="$kind $name $value"$'\n'; else old_val["$kind $name"]="$value"; keys["$kind $name"]=1; fi
    done <<< "$old"
    while read -r kind name value; do
        [ -n "$kind" ] || continue
        if [ "$kind" = service ]; then new_val["service"]+="$kind $name $value"$'\n'; else new_val["$kind $name"]="$value"; keys["$kind $name"]=1; fi
    done <<< "$new"
    merged=""
    take=0
    if [[ "$cats" == *" all "* ]] || [[ "$cats" == *" service "* ]] || [[ "$cats" == *" service-config "* ]]; then take=1; fi
    if [ "$take" -eq 1 ]; then merged+="${new_val[service]:-}"; else merged+="${old_val[service]:-}"; fi
    for key in "${!keys[@]}"; do
        kind="${key%% *}"
        name="${key#* }"
        cat="$(ec_integrity_category "$kind" "$name")"
        take=0
        if [[ "$cats" == *" all "* ]] || [[ "$cats" == *" $cat "* ]]; then take=1; fi
        if [ "$kind" = setting ]; then
            if [[ "$cats" == *" settings "* ]]; then take=1; fi
            if [[ "$cats" == *" settings-new "* ]] && [ -z "${old_val[$key]+x}" ]; then take=1; fi
        fi
        if [ "$kind" = code ] && [ "$take" -eq 1 ] && [ -z "${new_val[$key]+x}" ]; then
            # コードの木を読めなかった（コンテナが止まっている）なら、前の値を残す
            take=0
        fi
        if [ "$take" -eq 1 ]; then
            [ -z "${new_val[$key]+x}" ] || merged+="$key ${new_val[$key]}"$'\n'
        else
            [ -z "${old_val[$key]+x}" ] || merged+="$key ${old_val[$key]}"$'\n'
        fi
    done
    merged="$(printf '%s' "$merged" | grep -v '^$' | LC_ALL=C sort)"
    if [ -n "$old" ] && [ "$merged" = "$(printf '%s\n' "$old" | grep -v '^$' | LC_ALL=C sort)" ]; then
        return 0
    fi
    gen=$(($(ec_integrity_generation) + 1))
    tmp="$EC_INTEGRITY_DIR/.expected.tmp.$$"
    if ! (umask 077 && { printf 'format=1\ngeneration=%s\nrecorded_at=%s\n' "$gen" "$(ec_integrity_now)" && printf '%s\n' "$merged"; } > "$tmp") ||
        ! chmod 0600 "$tmp" || ! mv -f "$tmp" "$EC_INTEGRITY_DIR/expected"; then
        rm -f "$tmp"
        ec_warn "基準の状態を書けません（${EC_INTEGRITY_DIR}）"
        return 1
    fi
    # コードの木は次の照らしで照らし直す
    rm -f "$EC_INTEGRITY_DIR/code_state"
    ec_say "内部の整合性チェックの基準の状態を記録し直した（世代 ${gen}）"
    return 0
}

# コードの木の困りごと（code:<サービス>）。10 分ごと（EC_INTEGRITY_CODE_FORCE=1 なら今）に照らし、間は前の結果を使う（code_state）。$1 コンテナの一覧
ec_integrity_code_problems() {
    local listing="$1" state="$EC_INTEGRITY_DIR/code_state" now head at gen service id want have out=""
    local -A want_code=()
    now="$(date -u +%s)"
    head="$(awk 'NR == 1' "$state" 2> /dev/null || true)"
    at="${head%% *}"
    gen="${head#* }"
    if [ "${EC_INTEGRITY_CODE_FORCE:-0}" != 1 ] && [[ "$at" =~ ^[0-9]+$ ]] && [ "$gen" = "$(ec_integrity_generation)" ] && [ $((now - at)) -lt "$EC_INTEGRITY_CODE_EVERY" ]; then
        awk 'NR > 1' "$state"
        return 0
    fi
    while read -r _ service want; do want_code[$service]="$want"; done < <(grep '^code ' "$EC_INTEGRITY_DIR/expected" 2> /dev/null || true)
    while read -r service id; do
        [ -n "$service" ] && [ -n "${want_code[$service]:-}" ] || continue
        have="$(ec_integrity_code_hash "$id" "$service")" || have=unreadable
        [ "$have" = "${want_code[$service]}" ] || out="${out}code:$service"$'\n'
    done < <(ec_integrity_code_targets "$listing")
    { printf '%s %s\n' "$now" "$(ec_integrity_generation)" && printf '%s' "$out"; } > "$state.tmp.$$" 2> /dev/null && mv -f "$state.tmp.$$" "$state" 2> /dev/null || rm -f "$state.tmp.$$"
    printf '%s' "$out"
}

# 控えと照らし、困りごとの名前を 1 行ずつ出す（決まった順・重なりなし・上限まで）。無ければ何も出さない。
# compose の外の、許していないコンテナの詳しいこと（名前・イメージ ID・設定の指紋）は root だけの extra に書く（sudo ec-sugar integrity check で出す）
ec_integrity_scan() {
    local expected="$EC_INTEGRITY_DIR/expected" kind name value cfg listing hashes settings id running image project service oneoff cname n extras=""
    local -A want_img=() want_cfg=() want_count=() run_count=() want_item=() seen_item=()
    local problems=()
    if [ ! -r "$expected" ] || ! grep -qx 'format=1' "$expected"; then
        printf 'expected:missing\n'
        return 0
    fi
    while read -r kind name value cfg; do
        case "$kind" in
            service)
                want_img[$name]="${want_img[$name]:-} $value"
                want_cfg[$name]="${want_cfg[$name]:-} $cfg"
                want_count[$name]=$((${want_count[$name]:-0} + 1))
                ;;
            file | setting) want_item["$kind $name"]="$value" ;;
        esac
    done < "$expected"
    if ! listing="$(ec_integrity_containers)"; then
        problems+=(docker:unreachable)
    else
        while IFS=$'\t' read -r id running image project service oneoff cname cfg; do
            [ -n "$id" ] && [ "$running" = true ] || continue
            # 控えにあるサービスの名札で、一度きりでないものだけを数える。名札は偽れるので、イメージ ID と設定の指紋も控えと照らす。
            # 一度きりのもの（マイグレーション・バックアップ）は錠を持っている間だけで、照らすのは錠の無い時なので、見えたら外のものと同じ
            if [ "$project" = "$EC_PROJECT" ] && [ "$oneoff" != True ] && ec_integrity_valid_service "$service" && [ -n "${want_count[$service]:-}" ]; then
                [[ "${want_img[$service]} " == *" $image "* ]] || problems+=("image:$service")
                [[ "${want_cfg[$service]} " == *" $cfg "* ]] || problems+=("config:$service")
                run_count[$service]=$((${run_count[$service]:-0} + 1))
            elif ! ec_integrity_allowed "$cname" "$image" "$cfg"; then
                # compose の外で動いている、許していないコンテナ（名札に関係なく）
                problems+=(container:extra)
                extras="${extras}${cname} ${image} config:${cfg}"$'\n'
            fi
        done <<< "$listing"
        for service in "${!want_count[@]}"; do
            n="${run_count[$service]:-0}"
            if [ "$n" -lt "${want_count[$service]}" ]; then
                problems+=("service:missing:$service")
            elif [ "$n" -gt "${want_count[$service]}" ]; then
                problems+=(container:extra)
            fi
        done
        while IFS= read -r name; do [ -z "$name" ] || problems+=("$name"); done < <(ec_integrity_code_problems "$listing")
    fi
    { (umask 077 && printf '%s' "$extras" > "$EC_INTEGRITY_DIR/extra"); } 2> /dev/null || true
    if ! hashes="$(ec_integrity_hashes)"; then
        # compose の置き場を決められない（答えの CHANNEL か記録のコミットが壊された）
        problems+=(file:compose)
        hashes=""
    fi
    settings="$(ec_integrity_settings 2> /dev/null || true)"
    while read -r kind name value; do
        [ -n "$kind" ] || continue
        seen_item["$kind $name"]=1
        [ "${want_item["$kind $name"]:-}" = "$value" ] || problems+=("$(ec_integrity_item_problem "$kind" "$name")")
    done <<< "$hashes"$'\n'"$settings"
    for name in "${!want_item[@]}"; do
        [ -n "${seen_item[$name]:-}" ] || problems+=("$(ec_integrity_item_problem "${name%% *}" "${name#* }")")
    done
    [ "${#problems[@]}" -gt 0 ] || return 0
    for name in "${problems[@]}"; do
        ! ec_integrity_valid_problem "$name" || printf '%s\n' "$name"
    # 上限で切るのは awk で（head は先に閉じて、pipefail の下で sort を SIGPIPE で落とす）
    done | LC_ALL=C sort -u | awk -v n="$EC_INTEGRITY_MAX_PROBLEMS" 'NR <= n'
}
# 控えの項目（ファイルか設定）の困りごとの名前。設定は file:env-<名前>・file:answers-<名前>（小文字）
ec_integrity_item_problem() {
    if [ "$1" = setting ]; then
        printf 'file:%s-%s' "${2%%:*}" "$(ec_integrity_name "${2#*:}")"
    else
        printf 'file:%s' "$2"
    fi
}

# 困りごとの名前（1 行ずつ）を、公開の JSON に出す種類だけにする（重なりなし・決まった順）
ec_integrity_kinds() {
    local p k
    while IFS= read -r p; do
        [ -n "$p" ] || continue
        k="${p%%:*}"
        [[ " $EC_INTEGRITY_KINDS " == *" $k "* ]] && printf '%s\n' "$k"
    done <<< "$1" | LC_ALL=C sort -u
}

# 様子を書いて署名する。$1 ok（true・false）$2 困りごと（1 行ずつ。細かい名前のまま渡すと、種類だけにして出す）$3 busy なら 1。
# 一時のファイルから入れ替える（途中の形を出さない）
ec_integrity_write() {
    local ok="$1" problems="$2" busy="${3:-0}" server gen json list="" k tmp
    server="$(ec_integrity_server)"
    [[ "$server" =~ ^[0-9a-f]{16}$ ]] || { ec_warn "このサーバーの識別子が読めません（${EC_INTEGRITY_DIR}/server）"; return 1; }
    gen="$(ec_integrity_generation)"
    while IFS= read -r k; do
        [ -n "$k" ] || continue
        list="${list:+$list,}\"$k\""
    done < <(ec_integrity_kinds "$problems")
    json="{\"format\":1,\"server\":\"$server\",\"generation\":$gen,\"checked_at\":\"$(ec_integrity_now)\",\"ok\":$ok,\"problems\":[$list]"
    [ "$busy" != 1 ] || json="$json,\"busy\":true"
    json="$json}"
    tmp="$EC_INTEGRITY_PUBLIC/.integrity.tmp.$$"
    # 末尾に改行を付けない（外の見張りがコマンドの置き換え $(…) で読んでも、署名したバイト列が変わらない）
    if ! printf '%s' "$json" > "$tmp.json" ||
        ! openssl pkeyutl -sign -inkey "$EC_INTEGRITY_DIR/key" -rawin -in "$tmp.json" -out "$tmp.bin" 2> /dev/null ||
        ! openssl base64 -A -in "$tmp.bin" | tr -d '\n' > "$tmp.sig" || [ ! -s "$tmp.sig" ] ||
        ! chmod 0644 "$tmp.json" "$tmp.sig" ||
        ! mv -f "$tmp.json" "$EC_INTEGRITY_PUBLIC/integrity.json" || ! mv -f "$tmp.sig" "$EC_INTEGRITY_PUBLIC/integrity.json.sig"; then
        rm -f "$tmp.json" "$tmp.bin" "$tmp.sig"
        ec_warn "状態に署名して書けません（openssl 3 の pkeyutl -rawin が要る。${EC_INTEGRITY_PUBLIC}）"
        return 1
    fi
    rm -f "$tmp.bin"
}

# 最後の照らしの困りごと（state の integrity の 3 つ目から。lock:held は除く）を 1 行ずつ
ec_integrity_last_problems() {
    local last parts=() p
    last="$(ec_state_get integrity 2> /dev/null || true)"
    read -r -a parts <<< "$last" || true
    [ "${parts[0]:-}" = mismatch ] && [ "${#parts[@]}" -gt 2 ] || return 0
    for p in "${parts[@]:2}"; do [ "$p" = lock:held ] || printf '%s\n' "$p"; done
}

# 外の見張りが取りに来たか（web の nginx が、合い言葉の付いた取得だけを書く記録を docker exec で見て、時刻を root の置き場に残し、記録を空にする）。
# $1 コンテナの一覧（ec_integrity_containers の出力）。web が動いていない・見られなければ何もしない。答えは大きさの上限つきで読む
ec_integrity_poll_sentinel() {
    local listing="$1" id running image project service oneoff cname cfg web="" at now last
    while IFS=$'\t' read -r id running image project service oneoff cname cfg; do
        if [ "$running" = true ] && [ "$project" = "$EC_PROJECT" ] && [ "$service" = web ] && [ "$oneoff" != True ]; then web="$id"; fi
    done <<< "$listing"
    [ -n "$web" ] || return 0
    # 記録の中身は読まない（時刻と大きさだけ）。中で動くのは決まった短いスクリプトだけ
    at="$(docker exec "$web" sh -c "f=$EC_INTEGRITY_FETCH_LOG; "'if [ -s "$f" ]; then stat -c %Y "$f" && : > "$f"; fi' 2> /dev/null | head -c 32 || true)"
    at="${at%%[!0-9]*}"
    [[ "$at" =~ ^[0-9]{9,11}$ ]] || return 0
    now="$(date -u +%s)"
    # 時計の先の時刻は信じない（5 分まで）
    [ "$at" -le $((now + 300)) ] || return 0
    last="$(head -n1 "$EC_INTEGRITY_DIR/last_fetch" 2> /dev/null || true)"
    [[ "$last" =~ ^[0-9]{1,11}$ ]] || last=0
    if [ "$at" -gt "$last" ]; then
        { printf '%s\n' "$at" > "$EC_INTEGRITY_DIR/.last_fetch.tmp.$$" && mv -f "$EC_INTEGRITY_DIR/.last_fetch.tmp.$$" "$EC_INTEGRITY_DIR/last_fetch"; } || rm -f "$EC_INTEGRITY_DIR/.last_fetch.tmp.$$"
    fi
    return 0
}

# 外の見張りの様子: none（一度も来ていない）か、「seen（30 分の内に来た）か silent（来たことはあるが 30 分来ていない） 最後に来た時刻（UTC）」
ec_integrity_sentinel_state() {
    local last now
    last="$(head -n1 "$EC_INTEGRITY_DIR/last_fetch" 2> /dev/null || true)"
    if ! [[ "$last" =~ ^[0-9]{9,11}$ ]]; then
        printf 'none\n'
        return 0
    fi
    now="$(date -u +%s)"
    if [ $((now - last)) -gt "$EC_INTEGRITY_SENTINEL_SILENT" ]; then printf 'silent '; else printf 'seen '; fi
    date -u -d "@$last" +%Y-%m-%dT%H:%M:%SZ 2> /dev/null || date -u -r "$last" +%Y-%m-%dT%H:%M:%SZ
}

# 照らした結果と外の見張りの様子を state に残し（integrity は「語 時刻 困りごと…」、integrity_sentinel は「語 最後に来た時刻」）、
# 変わった時だけ運営の様子へ渡す（ec_host_report。毎分は渡さない）。渡せなかった時は 10 分おきに渡し直す（app が動いていない間など）。
# $1 困りごと（1 行ずつ。細かい名前）$2 コンテナの一覧
ec_integrity_note() {
    local problems="$1" listing="$2" word joined old parts=() sentinel old_sentinel changed=0 pending="$EC_STATE/integrity_report_pending"
    joined="$(printf '%s' "$problems" | grep -v '^$' | tr '\n' ' ' | sed 's/ *$//')"
    if [ -z "$joined" ]; then word=ok; else word=mismatch; fi
    old="$(ec_state_get integrity 2> /dev/null || true)"
    read -r -a parts <<< "$old" || true
    # 時刻を除いて比べる
    if [ -z "$old" ] || [ "${parts[0]:-} ${parts[*]:2}" != "$word $joined" ]; then
        ec_state_put integrity "$word $(ec_integrity_now)${joined:+ $joined}"
        changed=1
    fi
    sentinel="$(ec_integrity_sentinel_state)"
    old_sentinel="$(ec_state_get integrity_sentinel 2> /dev/null || true)"
    if [ "$sentinel" = none ]; then
        if [ -n "$old_sentinel" ]; then rm -f "$EC_STATE/integrity_sentinel"; changed=1; fi
    elif [ "${sentinel%% *}" != "${old_sentinel%% *}" ]; then
        ec_state_put integrity_sentinel "$sentinel"
        changed=1
    fi
    if [ "$changed" -eq 0 ]; then
        # 渡せていない報告は、10 分おきに渡し直す
        [ -e "$pending" ] && [ -n "$(find "$pending" -mmin +"$EC_INTEGRITY_REPORT_RETRY_MIN" 2> /dev/null || true)" ] || return 0
    else
        [ "$word" = ok ] || ec_warn "内部の整合性チェックで、基準の状態との違いがあります: ${joined}（sudo ec-sugar integrity check で見る。更新や設定の変更に心当たりが無ければ、入られたかもしれません）"
        [ "${sentinel%% *}" != silent ] || ec_warn "外部監視（ec-sugar-sentinel）が 30 分以上来ていません（最後 ${sentinel#* }）"
    fi
    # app が動いていなければ渡さない（証明書の確かめなどで照らしを長くしない）
    if printf '%s\n' "$listing" | awk -F '\t' -v p="$EC_PROJECT" '$2 == "true" && $4 == p && $5 == "app" && $6 != "True" {found = 1} END {exit found ? 0 : 1}' &&
        ec_host_report > /dev/null 2>&1; then
        rm -f "$pending"
    else
        : > "$pending"
    fi
    return 0
}

# 錠を持っている処理がある間: 照らさず、最後の結果（無ければ ok）に busy を足して署名だけ新しくする。
# 錠が 30 分を超えて握られていれば lock:held を足し、中の知らせにも載せる。$1 コンテナの一覧
ec_integrity_while_busy() {
    local listing="$1" since now problems
    now="$(date -u +%s)"
    since="$(head -n1 "$EC_INTEGRITY_DIR/busy_since" 2> /dev/null || true)"
    if ! [[ "$since" =~ ^[0-9]{9,11}$ ]] || [ "$since" -gt "$now" ]; then
        since="$now"
        printf '%s\n' "$now" > "$EC_INTEGRITY_DIR/busy_since"
    fi
    problems="$(ec_integrity_last_problems)"
    if [ $((now - since)) -gt "$EC_INTEGRITY_BUSY_LIMIT" ]; then
        problems="$(printf '%s\n%s\n' "$problems" lock:held | grep -v '^$' | LC_ALL=C sort -u)"
        ec_integrity_write false "$problems" 1 || return 1
        ec_integrity_note "$problems" "$listing"
        ec_warn "ロック（${EC_LOCK}）が $(((now - since) / 60)) 分解除されていません（更新などが止まっていないか、sudo ec-sugar status で見る）"
        return 0
    fi
    if [ -n "$problems" ]; then ec_integrity_write false "$problems" 1; else ec_integrity_write true "" 1; fi
}

# 毎分の照らし（ec-sugar-integrity.timer。sudo ec-sugar integrity check）。困りごとがあれば 1 行ずつ出す。
# 錠を持っている処理があれば照らさず、busy で署名だけ新しくする
ec_integrity_check() {
    local listing problems gen ok=true
    ec_integrity_ensure || { ec_warn "内部の整合性チェックの保存先か鍵を用意できません"; return 1; }
    listing="$(ec_integrity_containers 2> /dev/null || true)"
    ec_integrity_poll_sentinel "$listing"
    if ec_integrity_busy; then
        ec_say "ほかの ec-sugar の処理が動いているのでチェックしません（状態に busy を付けた）"
        ec_integrity_while_busy "$listing"
        return $?
    fi
    gen="$(ec_integrity_generation)"
    problems="$(ec_integrity_scan)"
    # 照らしている間に更新などが始まったら、入れ替えの途中を見たかもしれない。控え直されたら照らし直す
    if ec_integrity_busy; then
        ec_integrity_while_busy "$listing"
        return $?
    fi
    rm -f "$EC_INTEGRITY_DIR/busy_since"
    if [ "$(ec_integrity_generation)" != "$gen" ]; then problems="$(ec_integrity_scan)"; fi
    [ -z "$problems" ] || ok=false
    ec_integrity_write "$ok" "$problems" 0 || return 1
    ec_integrity_note "$problems" "$listing"
    if [ -n "$problems" ]; then printf '%s\n' "$problems"; fi
    return 0
}
