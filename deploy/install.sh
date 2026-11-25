#!/usr/bin/env bash
# EC-SUGAR のインストーラー（ADR 0030・0031）。まっさらな Ubuntu のサーバー 1 台に EC-SUGAR を入れる
#
#   git clone <リポジトリの URL> ~/ec-sugar && cd ~/ec-sugar
#   sudo ./deploy/install.sh [--answers <設定ファイル>]
#   ./deploy/install.sh --check [--answers <設定ファイル>]   確認だけ（何も変えない。root でなくても実行できる）
#
# 5 段のウィザード: 1/5 環境の確認 → 2/5 聞く → 3/5 DNS の確認 → 4/5 入れて立てる（最初のショップとサンプル） → 5/5 仕上げ（手順書は docs/install.md）
#
# - 何度実行しても同じ（冪等）。止まった所から再実行できる。2 回目からは聞かない（設定ファイルは /srv/ec-sugar/install.answers）
# - 壊れる操作（作り直し・消す）はしない。パスワードと APP_KEY は 1 回だけ作り、作り直さない
# - パスワード・鍵は画面に出さない。コマンドの引数にも渡さない（ps で見える）
# - 入れた後の更新・バックアップ・ロールバックは sudo ec-sugar（deploy/ec-sugar）で行う。このインストーラーはバージョンを上げない
set -euo pipefail
# 作るファイルの権限を決め打つ（実行した人の umask が 077 でも、取り出したソースを DB・nginx・PHP のコンテナが読めるように）。
# 秘密のファイルは、その場だけ umask 077 で作る（common.sh）
umask 022

EC_INSTALLER="$(readlink -f "${BASH_SOURCE[0]}")"
EC_DEPLOY_DIR="$(dirname "$EC_INSTALLER")"
EC_REPO="$(dirname "$EC_DEPLOY_DIR")"
# shellcheck source=lib/common.sh
. "$EC_DEPLOY_DIR/lib/common.sh"
# Cloudflare の Origin CA の元の証明書（Tunnel の形で Origin CA の証明書を渡したとき、cloudflared の置き場へ写す。common.sh）
EC_ORIGIN_CA_ROOTS="$EC_DEPLOY_DIR/cloudflare/origin-ca-roots.crt"

# 段の数（ADR 0031 の端末のウィザード）
EC_STAGES=5
# 動作を確かめた Ubuntu のバージョン
EC_SUPPORTED_UBUNTU="22.04 24.04 26.04"
# 足りる大きさ（ADR 0029 の見込み 4 コア・4 GiB・40 GiB の下限）
EC_MIN_CPUS=2
EC_MIN_MEM_MB=3500
EC_MIN_DISK_GB=20
# Docker の下限（compose の「イメージを置き場として差し込む」type: image と subpath が要る）
EC_MIN_DOCKER=28.1
EC_MIN_COMPOSE=2.35
# Docker の apt の鍵の指紋（https://docs.docker.com/engine/install/ubuntu/ に書かれたもの）
EC_DOCKER_GPG_FPR=9DC858229FC7DD38854AE2D88D81803C0EBFCD88
# 証明書の取り先の既定（Let's Encrypt の本番）
EC_ACME_DEFAULT=https://acme-v02.api.letsencrypt.org/directory
# 答えのファイルに書ける鍵。ACME_CA・ACME_CA_ROOT は試験用（Pebble などの試験の認証局に向ける。本番では書かない）。
# OFFSITE_… はサーバーの外のバックアップ（S3 互換の置き場。任意。ADR 0110）: 入口・バケット・名前の頭（無ければ管理画面のホスト）・
# リージョン（無ければ auto）・置き場の書く鍵のファイル・暗号の公開鍵のファイル・暗号化するか（既定 yes）・置き場のロックを確かめるか
EC_ANSWER_KEYS="MODE TIMEZONE SHOPS FRONT PROXY_GROUP PROXY_GROUP_SHARED_OK ADMIN_HOST SHOP_HOST SHOP_DOMAIN SHOP_ID SHOP_NAME SAMPLES BIND LAN_CIDR ADMIN_ALLOWED_IPS OPERATOR_EMAIL OPERATOR_NAME TLS_CERT TLS_KEY CLOUDFLARE_TOKEN_FILE TUNNEL_TOKEN_FILE TUNNEL_WATCH_TOKEN_FILE ACME_CA ACME_CA_ROOT UPDATE_BRANCH AUTO_UPDATE CHANNEL TRUST_SIGNERS_FILE TRUST_FINGERPRINTS OFFSITE_ENDPOINT OFFSITE_BUCKET OFFSITE_PREFIX OFFSITE_REGION OFFSITE_KEY_FILE OFFSITE_RECIPIENT_FILE OFFSITE_ENCRYPT OFFSITE_LOCK_CHECK"
# 公開の DNS の聞き先（DNS over HTTPS。サーバーの中の /etc/hosts や家の DNS に惑わされない）と、このサーバーの外から見た住所の聞き先。
# EC_SUGAR_TEST_… は試験のときだけ替える（使い捨ての機械で、偽の DNS に向けて待ちと案内を確かめる）
EC_DOH_URL="${EC_SUGAR_TEST_DOH_URL:-https://1.1.1.1/dns-query}"
EC_TRACE_URL=https://1.1.1.1/cdn-cgi/trace
EC_DNS_INTERVAL="${EC_SUGAR_TEST_DNS_INTERVAL:-30}"
[[ "$EC_DNS_INTERVAL" =~ ^[0-9]{1,4}$ ]] || EC_DNS_INTERVAL=30

export DEBIAN_FRONTEND=noninteractive
APT=(apt-get -q -o DPkg::Lock::Timeout=600 -o Dpkg::Options::=--force-confold)

usage() { sed -n '2,15p' "$EC_INSTALLER" | sed 's/^# \{0,1\}//'; }

ANSWERS_IN=""
CHECK_ONLY=0
while [ $# -gt 0 ]; do
    case "$1" in
        --answers) [ $# -ge 2 ] || ec_die "--answers の後に設定ファイルを書く"; ANSWERS_IN="$2"; shift 2 ;;
        --answers=*) ANSWERS_IN="${1#--answers=}"; shift ;;
        --check) CHECK_ONLY=1; shift ;;
        -h | --help) usage; exit 0 ;;
        *) usage >&2; ec_die "知らない引数です: $1" ;;
    esac
done

# 利用者のリポジトリ（sudo で流すと持ち主が root でないので、この道筋だけ git に信じさせる）
# 流す人のリポジトリは読むだけ（--no-optional-locks: status が索引を root の持ち物で書き直し、流した人の git が使えなくなるのを防ぐ）
repo_git() { git --no-optional-locks -c safe.directory="$EC_REPO" -C "$EC_REPO" "$@"; }
installed() { [ -e "$EC_STATE/installed" ]; }
# バージョンの比べ（$1 >= $2）
version_ge() { [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" = "$2" ]; }
is_root() { [ "$(id -u)" -eq 0 ]; }
# 端末から聞けるか（ssh -T や systemd から流したときは聞けない）
has_tty() { { : < /dev/tty; } 2> /dev/null; }

# =====================================================================
# 1/5 環境の確かめ（何も変えない。全部を確かめてから ✓／✗ の一覧で見せ、✗ があれば止まる）
# =====================================================================
check_os() {
    local id ver arch
    id="$(ec_kv_get /etc/os-release ID || true)"
    ver="$(ec_kv_get /etc/os-release VERSION_ID || true)"
    arch="$(dpkg --print-architecture 2> /dev/null || true)"
    if [ "$id" != ubuntu ]; then
        ec_check_ng "Ubuntu ではありません（${id:-不明}）" "Ubuntu Server ${EC_SUPPORTED_UBUNTU// / か } の LTS を使う"
    elif [[ " $EC_SUPPORTED_UBUNTU " != *" $ver "* ]]; then
        ec_check_ng "Ubuntu $ver は確認していません" "Ubuntu ${EC_SUPPORTED_UBUNTU// / か } で作り直す"
    elif [ "$arch" != amd64 ] && [ "$arch" != arm64 ]; then
        ec_check_ng "CPU の種類 $arch は確認していません" "amd64 か arm64 のサーバーを使う"
    else
        ec_check_ok "OS: Ubuntu ${ver}（${arch}）"
    fi
}

check_resources() {
    local cpus mem_mb disk_gb
    cpus="$(nproc)"
    mem_mb=$(($(awk '/^MemTotal:/ {print $2}' /proc/meminfo) / 1024))
    disk_gb=$(($(df -Pk /var/lib | awk 'NR == 2 {print $4}') / 1024 / 1024))
    if [ "$cpus" -ge "$EC_MIN_CPUS" ]; then
        ec_check_ok "CPU: ${cpus} 個"
    else
        ec_check_ng "CPU が ${cpus} 個です（${EC_MIN_CPUS} 個以上が要る）" "大きいプランのサーバーに替える"
    fi
    if [ "$mem_mb" -ge "$EC_MIN_MEM_MB" ]; then
        ec_check_ok "メモリ: ${mem_mb} MiB"
    else
        ec_check_ng "メモリが ${mem_mb} MiB です（4 GB のプラン以上が要る。イメージを作るときに足りなくなる）" "メモリ 4 GB 以上のプランに替える"
    fi
    if [ "$disk_gb" -ge "$EC_MIN_DISK_GB" ]; then
        ec_check_ok "ディスクの空き: ${disk_gb} GiB"
    else
        ec_check_ng "ディスクの空きが ${disk_gb} GiB です（${EC_MIN_DISK_GB} GiB 以上が要る）" "ディスクを広げるか、要らないファイルを消す（df -h で見る）"
    fi
}

check_clock() {
    local synced headers remote age now diff
    synced="$(timedatectl show -p NTPSynchronized --value 2> /dev/null || true)"
    if [ "$synced" = yes ]; then
        ec_check_ok "時計: 合っている（NTP で同期）"
        return 0
    fi
    # 同期の印が無いとき（コンテナ型の仮の機械など）は、外の https の応答の時刻と比べる（パスキーと証明書は時計がずれると通らない）。
    # CDN の控えの応答は Date が古いので、控えてからの秒数（Age）を足す
    headers="$(curl -sSI --max-time 10 https://download.docker.com/ 2> /dev/null | tr -d '\r' || true)"
    remote="$(printf '%s\n' "$headers" | awk 'tolower($1) == "date:" {sub(/^[^:]*: /, ""); print; exit}')"
    age="$(printf '%s\n' "$headers" | awk 'tolower($1) == "age:" {print $2; exit}')"
    [[ "$age" =~ ^[0-9]+$ ]] || age=0
    if [ -z "$remote" ] || ! remote="$(date -d "$remote" +%s 2> /dev/null)"; then
        ec_check_warn "時計: 合っているかを確認できませんでした（NTP の同期のフラグが無く、外にも聞けない）" "sudo timedatectl set-ntp true で時刻の同期を有効にしておく"
        return 0
    fi
    now="$(date +%s)"
    diff=$((remote + age - now))
    if [ "${diff#-}" -le 120 ]; then
        ec_check_ok "時計: 合っている（外の時刻との差 ${diff#-} 秒）"
    else
        ec_check_ng "時計が ${diff} 秒ずれています（パスキーの登録と証明書の確認が通らなくなる）" \
            "sudo timedatectl set-ntp true で時刻の同期を有効にし、少し待ってから再実行する"
    fi
}

EC_COMMIT="" EC_ORIGIN="" EC_BRANCH=""
check_repo() {
    local dirty tag
    if ! command -v git > /dev/null; then
        ec_check_ng "git がありません" "sudo apt-get install -y git の後、リポジトリを git clone で取り直す"
        return 0
    fi
    if ! repo_git rev-parse --is-inside-work-tree > /dev/null 2>&1; then
        ec_check_ng "$EC_REPO は git の作業ツリーではありません" "git clone <リポジトリの URL> で取ってから、その中の deploy/install.sh を実行する"
        return 0
    fi
    if [ "$EC_REPO" = "$EC_SRC" ]; then
        ec_check_ng "リポジトリが $EC_SRC にあります（そこはインストーラーが使う保存先）" "ホームなどほかの所（例: ~/ec-sugar）に git clone し直す"
        return 0
    fi
    dirty="$(repo_git status --porcelain --untracked-files=no 2> /dev/null || true)"
    EC_COMMIT="$(repo_git rev-parse --verify HEAD)"
    EC_ORIGIN="$(repo_git remote get-url origin 2> /dev/null || true)"
    EC_BRANCH="$(repo_git symbolic-ref --quiet --short HEAD 2> /dev/null || true)"
    tag="$(repo_git describe --tags --exact-match HEAD 2> /dev/null || true)"
    if [ -n "$dirty" ]; then
        ec_check_ng "リポジトリに手動で変えた所があります（入れるバージョンが分からなくなる）" "変えた所を見る: git -C $EC_REPO status" "要らなければ戻す: git -C $EC_REPO checkout -- ."
    elif [ -z "$EC_ORIGIN" ]; then
        ec_check_ng "リポジトリに取得元（origin のリモート）がありません（更新で新しいバージョンを取れない）" "git clone <リポジトリの URL> で取り直す"
    elif ec_url_has_secret "$EC_ORIGIN"; then
        # URL は出さない（パスワードかトークンが入っている。中）
        ec_check_ng "取得元（origin）の URL にパスワードかトークンが入っています（記録と画面と更新の記録に残る）" \
            "git -C $EC_REPO remote set-url origin <パスワードの無い URL> に替える（非公開なら、入れた後に sudo ec-sugar deploy-key の鍵で取る）" \
            "トークンを URL に入れたなら、そのトークンを GitHub で取り消す"
    else
        ec_check_ok "入れるバージョン: ${EC_COMMIT:0:12}${tag:+（タグ ${tag}）}${EC_BRANCH:+（ブランチ ${EC_BRANCH}）}"
    fi
}

# サーバーの管理の道具（ほかのサイトを載せている印）。見つけたら、Web サーバーを止める助言をしない
detect_panel() {
    if [ -d /usr/local/psa ] || command -v plesk > /dev/null 2>&1; then printf 'Plesk'
    elif [ -d /usr/local/cpanel ]; then printf 'cPanel'
    elif [ -d /usr/local/directadmin ]; then printf 'DirectAdmin'
    elif [ -d /usr/local/ispconfig ]; then printf 'ISPConfig'
    fi
}
# $1 のポートを、このサーバーの EC-SUGAR（同じプロジェクトのコンテナ）が公開しているか
ours_publish() { command -v docker > /dev/null && [ -n "$(docker ps -q --filter label=com.docker.compose.project="$EC_PROJECT" --filter publish="$1" 2> /dev/null)" ]; }
is_group() { [[ "$1" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] && getent group "$1" > /dev/null && [ "$1" != root ]; }

# 80・443 番と、すでにある Web サーバー（ADR 0031）。
# 2 回目からは自分の入口が使っている（今の形は caddy、前の形〔より前〕は web）。
# ほかの Web サーバーが使っているときは、止めることを勧めない（ほかのサイトがすべて止まる）。後ろに置く形（FRONT=proxy）を案内する
EC_OTHER_WEB="" EC_PANEL=""
check_ports() {
    local busy names svc active=() front port
    front="$(ec_answer FRONT 2> /dev/null || true)"
    EC_PANEL="$(detect_panel)"
    if ours_publish 443 || ours_publish 80; then
        ec_check_ok "80・443 番: EC-SUGAR のリバースプロキシが使っている（2 回目）"
        return 0
    fi
    busy="$(ss -Hltnp '( sport = :80 or sport = :443 )' 2> /dev/null || true)"
    # 名前は users:(("nginx",pid=…),…) の「"」の中だけ
    names="$(printf '%s' "$busy" | grep -oE '\("[^"]+"' | tr -d '("' | sort -u | tr '\n' ' ' || true)"
    for svc in apache2 nginx caddy lighttpd httpd haproxy traefik; do
        systemctl is-active --quiet "$svc" 2> /dev/null && active+=("$svc")
    done
    if [ -z "$busy" ] && [ "${#active[@]}" -eq 0 ]; then
        ec_check_ok "80・443 番: 空いている"
        ec_check_ok "ほかの Web サーバー: 動いていない"
        [ "$front" != proxy ] || ec_check_warn "設定ファイルは FRONT=proxy（後ろに置く構成）ですが、80・443 番で受ける Web サーバーがありません" "前段のリバースプロキシを入れてから実行するか、FRONT=caddy（EC-SUGAR が 80・443 番を受ける）にする"
        return 0
    fi
    if [ -n "$busy" ] && [ -z "$names" ] && ! is_root; then
        ec_check_warn "80・443 番を何かが使っています（root でないので、EC-SUGAR のリバースプロキシか、ほかの Web サーバーかが分かりません）" "sudo を付けて --check を実行すると分かる"
        return 0
    fi
    EC_OTHER_WEB="$(printf '%s %s' "$names" "${active[*]}" | tr ' ' '\n' | grep -v '^$' | sort -u | tr '\n' ' ' | sed 's/ $//')"
    if [ "$front" = proxy ]; then
        ec_check_ok "80・443 番: すでにある Web サーバー（${EC_OTHER_WEB}${EC_PANEL:+、$EC_PANEL}）が受ける。EC-SUGAR はその後ろに置く（FRONT=proxy）"
    elif [ "$front" = caddy ] || [ "$front" = tunnel ]; then
        # Cloudflare Tunnel の形も、入口の Caddy が 127.0.0.1 の 80・443 番を使う（ほかの Web サーバーが全部の住所で受けているとぶつかる）
        if [ -n "$EC_PANEL" ]; then
            ec_check_ng "80・443 番を、すでにある Web サーバー（${EC_OTHER_WEB}）が使っています。$EC_PANEL のサーバーです" \
                "Web サーバーを止めないでください（$EC_PANEL のサイトがすべて止まります）" \
                "設定ファイルの FRONT=$front を FRONT=proxy にして、すでにある Web サーバーの後ろに置く（docs/install.md の「すでにある Web サーバーの後ろに置く」）"
        else
            ec_check_ng "80・443 番を、すでにある Web サーバー（${EC_OTHER_WEB}）が使っています（設定ファイルは FRONT=${front}）" \
                "ほかのサイトを載せているなら、止めずに FRONT=proxy（その後ろに置く構成）にする（docs/install.md の「すでにある Web サーバーの後ろに置く」）" \
                "このサーバーで使っていない Web サーバーだと確認できたときだけ、止めてから再実行する"
        fi
    else
        ec_check_warn "80・443 番を、すでにある Web サーバー（${EC_OTHER_WEB}${EC_PANEL:+、$EC_PANEL のサーバー}）が使っています" \
            "止めないでください（ほかのサイトが止まります）。EC-SUGAR をその後ろに置く構成で入れられます（2/5 で聞きます。設定ファイルなら FRONT=proxy）"
    fi
}

# 外へ出られるか（証明書・部品のイメージ・ソース・入口の部品）。届けば応答の番号は何でもよい
check_outbound() {
    local acme ca channel code name url targets
    acme="$(ec_answer ACME_CA 2> /dev/null || true)"
    ca="$(ec_answer ACME_CA_ROOT 2> /dev/null || true)"
    if ! command -v curl > /dev/null; then
        ec_check_ng "curl がありません（外へ出られるかを確認できない）" "sudo apt-get install -y curl"
        return 0
    fi
    targets=("Let's Encrypt（証明書を取る）|${acme:-$EC_ACME_DEFAULT}"
        "Docker Hub（部品のイメージ）|https://registry-1.docker.io/v2/"
        "GitHub（ソースと更新）|https://github.com/")
    # release チャネルはイメージを作らず、公開用の CI が作って署名したものを GHCR から取る（Caddy も作らないので Go のモジュールは要らない）
    channel="$(ec_answer CHANNEL 2> /dev/null || true)"
    is_channel "$channel" || channel="$(default_channel)"
    if [ "$channel" = release ]; then
        targets+=("GitHub のコンテナのレジストリ（公開版のイメージ。ghcr.io）|https://ghcr.io/v2/")
    else
        targets+=("Go のモジュールのプロキシ（リバースプロキシの Caddy を作る）|https://proxy.golang.org/")
    fi
    command -v docker > /dev/null || targets+=("Docker の公式のリポジトリ（Docker を入れる）|https://download.docker.com/")
    # Cloudflare Tunnel の形（答えが分かっているとき）: cloudflared がつなぎに行く Cloudflare の 7844 番（ふつうは UDP の QUIC、だめなら TCP。
    # ここで見られるのは TCP だけなので、届かなくても止めない）
    if [ "$(ec_answer FRONT 2> /dev/null || true)" = tunnel ]; then
        if command -v timeout > /dev/null && timeout 10 bash -c ': > /dev/tcp/region1.v2.argotunnel.com/7844' 2> /dev/null; then
            ec_check_ok "外への接続: Cloudflare Tunnel（region1.v2.argotunnel.com の 7844 番）"
        else
            ec_check_warn "外への接続: Cloudflare Tunnel（region1.v2.argotunnel.com の TCP の 7844 番）に届きません" "ルーターや上のファイアウォールで、外への 7844 番（UDP と TCP）を許す（cloudflared が接続しに行く先）"
        fi
    fi
    for name in "${targets[@]}"; do
        url="${name#*|}"
        name="${name%%|*}"
        if [ -n "$ca" ] && [ "$url" = "$acme" ] && [ -r "$ca" ]; then
            code="$(ec_code_norm "$(curl -sS --cacert "$ca" -o /dev/null -w '%{http_code}' --max-time 15 "$url" 2> /dev/null || true)")"
        else
            code="$(ec_code_norm "$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "$url" 2> /dev/null || true)")"
        fi
        if [ "$code" != 000 ]; then
            ec_check_ok "外への接続: $name"
        else
            ec_check_ng "外への接続: ${name}（${url}）に届きません" "サーバーから外への https（443 番）を許す（クラウドのファイアウォールの外向きのルール・会社のプロキシ・DNS の設定を見る）"
        fi
    done
    # 入れたときのサンプルの写真（雑貨以外のセットは入れる時に Unsplash から取る。ADR 0053）。届かなくても写真なしで入るので注意だけ
    if ec_sample_fetches "$(samples_for_check)"; then
        for url in https://unsplash.com/ https://images.unsplash.com/; do
            code="$(ec_code_norm "$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "$url" 2> /dev/null || true)")"
            if [ "$code" != 000 ]; then
                ec_check_ok "外への接続: Unsplash（サンプルの写真。${url#https://}）"
            else
                ec_check_warn "外への接続: Unsplash（${url}）に届きません（サンプルは写真なしで入ります）" "写真も入れるなら、サーバーから外への https（443 番）を許す（写真は後から管理画面で足せる）"
            fi
        done
    fi
}
# 1/5 で見るサンプルのセット。答えがまだ無い（初めて流す）ときは --answers のファイルの値、それも無ければ 2/5 の既定
# （新しく入れるなら sweets、もう入れたサーバーなら入れない）。入れ終えたサーバーでは見ない
samples_for_check() {
    local val
    if [ -e "$EC_STATE/samples_added" ]; then
        printf 'none'
        return 0
    fi
    val="$(ec_answer SAMPLES 2> /dev/null || true)"
    if [ -z "$val" ] && [ -n "$ANSWERS_IN" ]; then val="$(ec_kv_get "$ANSWERS_IN" SAMPLES 2> /dev/null || true)"; fi
    if [ -z "$val" ]; then
        if installed; then val=none; else val=sweets; fi
    fi
    ec_sample_set "$val" || printf 'none'
}

check_environment() {
    check_os
    check_resources
    check_clock
    check_repo
    check_ports
    check_outbound
    if ec_check_report; then
        return 0
    fi
    return 1
}

# =====================================================================
# 2/5 聞く
# =====================================================================
default_ip() { ip -4 route get 1.1.1.1 2> /dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "src") {print $(i + 1); exit}}'; }
# 受け口の住所がつながっている網（例 192.168.0.0/24）
default_lan() {
    local bind="$1"
    ip -4 route show proto kernel scope link 2> /dev/null | awk -v ip="$bind" '$0 ~ ("src " ip "( |$)") {print $1; exit}'
}
# 今の SSH の接続元（sudo は SSH_CLIENT を消すので、つながっている相手を ss で見る）
ssh_ports() {
    local p
    {
        sshd -T 2> /dev/null | awk '$1 == "port" {print $2}'
        ss -Hltnp 2> /dev/null | awk '/"sshd"/ {n = split($4, a, ":"); print a[n]}'
    } | sort -u | while read -r p; do [[ "$p" =~ ^[0-9]+$ ]] && printf '%s\n' "$p"; done
}
ssh_peers() {
    local p
    for p in $(ssh_ports); do
        ss -Htn state established "( sport = :$p )" 2> /dev/null | awk '{print $4}' | sed -E 's/^\[?([^]]*)\]?:[0-9]+$/\1/; s/^::ffff://'
    done | sort -u
}

# 答えを聞く。$1 鍵 $2 問い $3 既定 $4 確かめの関数 $5 駄目なときの説明
ask() {
    local key="$1" q="$2" def="$3" check="$4" hint="$5" val
    val="$(ec_answer "$key" || true)"
    if [ -n "$val" ]; then
        "$check" "$val" || ec_die "設定ファイル $EC_ANSWERS の $key=$val が正しくありません（${hint}）" "$EC_ANSWERS を直して再実行する"
        return 0
    fi
    has_tty || ec_die "設定ファイルに $key がなく、端末が無いので聞けません" "端末から実行するか、$EC_ANSWERS に $key=… を書く（書き方は docs/install.md）"
    while :; do
        printf '\n   %s\n' "$q" > /dev/tty
        read -r -p "   > ${def:+[$def] }" val < /dev/tty
        val="${val:-$def}"
        if "$check" "$val"; then break; fi
        printf '   %s\n' "使えない設定ファイルです: $hint" > /dev/tty
    done
    ec_kv_set "$EC_ANSWERS" "$key" "$val"
}
# 聞かずに既定を書いておく（答えのファイルに無ければ）。値の確かめは後の ask で
default_answer() { ec_answer "$1" > /dev/null || [ -z "$2" ] || ec_kv_set "$EC_ANSWERS" "$1" "$2"; }
is_mode() { [ "$1" = production ] || [ "$1" = staging ]; }
is_front() { [ "$1" = caddy ] || [ "$1" = proxy ] || [ "$1" = tunnel ]; }
is_shops() { [ "$1" = single ] || [ "$1" = multi ]; }
# 1 店舗の形のショップのホスト（管理画面のホストと同じにはできない）
is_shop_host() { ec_valid_host "$1" && [ "$1" != "$(ec_answer ADMIN_HOST)" ]; }

is_readable_file() { [ -f "$1" ] && [ -r "$1" ]; }
is_bind() { ec_valid_ipv4 "$1"; }
is_ref() { ec_valid_ref "$1"; }
is_name() { [ -n "$1" ] && [ "${#1}" -le 100 ] && [[ "$1" != *[$'\t\n\r']* ]]; }
is_yes_no() { [ "$1" = yes ] || [ "$1" = no ]; }
is_channel() { [ "$1" = source ] || [ "$1" = release ]; }
# 更新の形の既定: 木の deploy/channel.default（公開用のリポジトリにだけあり release。release/public/ から書き出す）。無ければ source
default_channel() {
    local c=""
    if [ -f "$EC_DEPLOY_DIR/channel.default" ]; then IFS= read -r c < "$EC_DEPLOY_DIR/channel.default" || true; fi
    if is_channel "$c"; then printf '%s' "$c"; else printf source; fi
}
# 入れたときのサンプルの答え（セットの名前か none。前の答えの yes・no も読める）
is_samples() { ec_sample_set "$1" > /dev/null; }
is_shop_domain() { ec_valid_host "$1" && [ "$1" != "$(ec_answer ADMIN_HOST)" ]; }
# 店の住所 <ID>.<ショップのドメイン> が管理画面のホストと重ならないこと
is_shop_id() { ec_valid_shop_id "$1" && [ "$1.$(ec_answer SHOP_DOMAIN)" != "$(ec_answer ADMIN_HOST)" ]; }
is_https_url() { [[ "$1" =~ ^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~/-]*)?$ ]]; }

import_answers() {
    local key val
    [ -n "$ANSWERS_IN" ] || return 0
    [ -r "$ANSWERS_IN" ] || ec_die "設定ファイル $ANSWERS_IN が読めません"
    for key in $EC_ANSWER_KEYS; do
        if val="$(ec_kv_get "$ANSWERS_IN" "$key")" && [ -n "$val" ] && ! ec_answer "$key" > /dev/null; then
            ec_kv_set "$EC_ANSWERS" "$key" "$val"
        fi
    done
    ec_ok "設定ファイル $ANSWERS_IN を読んだ"
}

# 証明書の取り方の答え（任意）を確かめる。どれも無ければ auto（Let's Encrypt から自動で）
check_tls_answers() {
    local cert key cf acme root mode
    cert="$(ec_answer TLS_CERT || true)"
    key="$(ec_answer TLS_KEY || true)"
    cf="$(ec_answer CLOUDFLARE_TOKEN_FILE || true)"
    acme="$(ec_answer ACME_CA || true)"
    root="$(ec_answer ACME_CA_ROOT || true)"
    if [ -n "$cert" ] || [ -n "$key" ]; then
        [ -n "$cert" ] && [ -n "$key" ] || ec_die "TLS_CERT と TLS_KEY は 2 つそろえて書きます（手動で用意した証明書を使うとき）" "$EC_ANSWERS を直す。自動で取るなら 2 つとも消す"
    fi
    if [ -n "$cf" ] && [ -n "$cert" ]; then
        ec_die "CLOUDFLARE_TOKEN_FILE と TLS_CERT・TLS_KEY は同時に使えません（証明書の取り方は 1 つ）" "$EC_ANSWERS からどちらかを消す"
    fi
    if [ -n "$cf" ] && ! is_readable_file "$cf" && [ ! -e "$EC_CADDY_SECRETS/cloudflare_token" ]; then
        ec_die "CLOUDFLARE_TOKEN_FILE のファイル $cf が読めません" "鍵のファイルを置き直すか、$EC_ANSWERS を直す"
    fi
    if [ -n "$acme" ]; then
        is_https_url "$acme" || ec_die "ACME_CA（試験の証明書の取得元）が https の URL ではありません" "$EC_ANSWERS を直す（本番では書かない）"
        ec_warn "証明書の取得元を試験の認証局 $acme に向けています（ブラウザは信頼しません。本番では ACME_CA を書かない）"
    fi
    if [ -n "$root" ] && ! is_readable_file "$root"; then
        ec_die "ACME_CA_ROOT のファイル $root が読めません" "$EC_ANSWERS を直す"
    fi
    if [ "$(ec_front)" = tunnel ]; then
        # Cloudflare Tunnel の形（ADR 0052）: 証明書は dns か manual・待ち受けは 127.0.0.1・トンネルのトークンの形
        ec_tunnel_check_answers
    elif [ -n "$(ec_answer TUNNEL_TOKEN_FILE || true)$(ec_answer TUNNEL_WATCH_TOKEN_FILE || true)" ]; then
        ec_die "TUNNEL_TOKEN_FILE（トンネルのトークン）と TUNNEL_WATCH_TOKEN_FILE（トンネルの監視のトークン）は Cloudflare Tunnel の構成（FRONT=tunnel）でだけ使います（設定ファイルは FRONT=$(ec_front)）" \
            "Cloudflare Tunnel で出すなら $EC_ANSWERS に FRONT=tunnel を書く。使わないなら TUNNEL_TOKEN_FILE・TUNNEL_WATCH_TOKEN_FILE の行を消す"
    fi
    if [ "$(ec_front)" = proxy ]; then
        # 後ろに置く形では、証明書はすでにある Web サーバーが受け持つ
        if [ -n "$cf$cert$acme" ]; then
            ec_die "すでにある Web サーバーの後ろに置く構成（FRONT=proxy）では、証明書の設定（TLS_CERT・CLOUDFLARE_TOKEN_FILE・ACME_CA）は使いません"                 "$EC_ANSWERS から消す（証明書は、すでにある Web サーバーで取る）"
        fi
        return 0
    fi
    # Cloudflare の Origin CA の証明書は Tunnel の形でだけ（ブラウザが信じない）。ここで先に止める（何も入れる前に。読めないときは 4/5 で確かめる）
    if [ -n "$cert" ] && is_readable_file "$cert"; then
        ec_check_origin_ca "$cert"
    fi
    mode="$(ec_tls_mode)"
    if [ "$(ec_answer MODE)" = staging ] && [ "$mode" = auto ]; then
        ec_die "ステージングは外から 80・443 番に届かないので、証明書を自動で取れません" \
            "Cloudflare の DNS の API トークンを CLOUDFLARE_TOKEN_FILE に書く（docs/install.md の「証明書の取り方」の dns）" \
            "手動で用意した証明書を使うなら、TLS_CERT・TLS_KEY に書く"
    fi
}

ask_all() {
    local mode bind lan peers="" def_allowed email admin allowed_q samples
    # 初回だけ作る（2 回目は持ち主を root:ec-sugar に揃えてあるので触らない。ここで止まっても ec-sugar が src に入れなくならないように）
    [ -d "$EC_ROOT" ] || install -d -m 0750 -o root -g root "$EC_ROOT"
    [ -e "$EC_ANSWERS" ] || install -m 0600 -o root -g root /dev/null "$EC_ANSWERS"
    import_answers

    # 更新の形。公開用のリポジトリ（deploy/channel.default が release）は公開版の形（署名したタグだけ）、開発用は開発の形（聞かない）
    default_answer CHANNEL "$(default_channel)"
    ask CHANNEL "" source is_channel "source か release"
    # 使い方は既定で本番（ステージングは答えのファイルで MODE=staging）
    default_answer MODE production
    ask MODE "" production is_mode "production か staging"
    mode="$(ec_answer MODE)"
    # 入口の形。80・443 番をすでにある Web サーバーが使っていれば聞く（既定は後ろに置く形）。空いていれば聞かずに caddy
    if [ -z "$EC_OTHER_WEB" ]; then
        default_answer FRONT caddy
    fi
    ask FRONT "80・443 番を、すでにある Web サーバー（${EC_OTHER_WEB}${EC_PANEL:+、$EC_PANEL のサーバー}）が使っています。EC-SUGAR をどう置きますか?
     proxy: その後ろに置く（すでにある Web サーバーとほかのサイトはそのまま。証明書もそちらで取り、EC-SUGAR へ転送する設定を足す。おすすめ）
     caddy: EC-SUGAR が 80・443 番を受ける（すでにある Web サーバーを止めてから再実行する。ほかのサイトがあれば止まる）" \
        proxy is_front "proxy か caddy"
    if { [ "$(ec_front)" = caddy ] || [ "$(ec_front)" = tunnel ]; } && [ -n "$EC_OTHER_WEB" ]; then
        ec_die "80・443 番を、すでにある Web サーバー（${EC_OTHER_WEB}）が使っているので、$(ec_front) の構成では入れられません（何も入れていません）" \
            "ほかのサイトを載せているなら、$EC_ANSWERS の FRONT を proxy にして再実行する（止めない）" \
            "このサーバーで使っていない Web サーバーだと確認できたときだけ、止めてから再実行する"
    fi
    if [ "$(ec_front)" = proxy ] && [ "$mode" = staging ]; then
        # 後ろに置くサーバーはほかの利用者（ホスティングの契約者など）と同居する。ステージングの Mailpit の画面（127.0.0.1）にはホストのどの利用者からも届き
        # （パスワードはあるが、届いた注文のメールを読まれる的が増える）、受け口を LAN だけにする決まりも入れられない（80・443 番は前の Web サーバーのもの）。
        ec_die "すでにある Web サーバーの後ろに置く構成（FRONT=proxy）では、ステージング（MODE=staging）は使えません。ほかのサイトと同居するサーバーでは、届いたメールの画面（Mailpit）にほかの利用者から届き、待ち受けを LAN だけにもできないため（何も入れていません）" \
            "$EC_ANSWERS の MODE を production にする（メールの送信先を決めるまでは送らずに捨てる）か、ステージングは専用のサーバーで FRONT=caddy にする"
    fi
    if [ "$(ec_front)" = proxy ]; then
        # EC-SUGAR は TCP では待たず、Unix ソケットで待つ。ソケットの置き場に入れるのは、前の Web サーバーの働き手のグループだけ
        # （同じサーバーの別の利用者〔契約者の PHP・cron・SSH の利用者〕は入れない）
        local group problems others
        # 443 番を本当に持っているプロセス（ss の pid）の働き手のグループ。見分けられたら聞かずに決め（下のまとめに出す）、見分けられなければ聞く
        group="$(ec_detect_front_group || true)"
        is_group "$group" && default_answer PROXY_GROUP "$group"
        ask PROXY_GROUP "EC-SUGAR へ転送する Web サーバーのワーカー（worker）のグループは?（そのグループだけが EC-SUGAR のリバースプロキシのソケットに接続する。ps -o user,group --ppid <443 番の pid> で見られる）" \
            "$group" is_group "このサーバーにあるグループの名前（root は使えない。例 nginx・www-data）"
        problems="$(ec_proxy_group_problems "$(ec_answer PROXY_GROUP)")"
        if [ -n "$problems" ]; then
            printf '%s\n' "$problems" | sed 's/^/   ✗ /' >&2
            ec_die "グループ $(ec_answer PROXY_GROUP) は、EC-SUGAR のソケットに入れるグループにできません（何も入れていません）" \
                "前段のリバースプロキシのワーカーだけのグループにする（ps -o user,group --ppid <443 番の pid> で見る）。$EC_ANSWERS の PROXY_GROUP を直して再実行する"
        fi
        # 同じグループで動く PHP など（php-fpm・mod_php）は、そのグループの権限でソケットにつなげ、接続元を偽れる。分かったうえでの印が無ければ止める
        others="$(ec_shared_group_procs "$(ec_answer PROXY_GROUP)")"
        if [ -n "$others" ] && [ "$(ec_answer PROXY_GROUP_SHARED_OK || true)" != 1 ]; then
            ec_die "グループ $(ec_answer PROXY_GROUP) で、前段のリバースプロキシのほかにも動いているものがあります（${others}）。それらは EC-SUGAR のソケットに接続し、管理画面の接続元の制限を偽れます（何も入れていません）" \
                "PHP（php-fpm のプール・Apache の mod_php）を別の利用者とグループで動かす（Plesk はサイトごとの利用者で動かすのが既定）" \
                "分かったうえで進めるなら、$EC_ANSWERS に PROXY_GROUP_SHARED_OK=1 を書いて再実行する（status にも出し続ける）"
        fi
    fi
    ask ADMIN_HOST "管理画面のホスト名は?（例 admin.example.jp。パスキーはこの名前に結びつくので後から変えにくい）" \
        "" ec_valid_host "小文字のホスト名（例 admin.example.jp）"
    # 店の数の形。既定は 1 店舗（聞かない。複数の店にするときは答えのファイルに SHOPS=multi。docs/install.md）
    default_answer SHOPS "$(ec_shops)"
    ask SHOPS "" single is_shops "single か multi"
    if [ "$(ec_shops)" = single ]; then
        ask SHOP_HOST "ショップのホスト名は?（お客さんが開くアドレス。例 example.jp か www.example.jp。後から変えにくい）" \
            "" is_shop_host "小文字のホスト名（管理画面のホスト名と同じにはできない）"
        # 店の ID は聞かずに決める（店の DB の名前にも使う。画面には出ない）
        default_answer SHOP_ID shop
        ask SHOP_ID "" shop ec_valid_shop_id "英小文字・数字・ハイフンの 2〜32 文字"
    else
        ask SHOP_DOMAIN "ショップのドメインは?（ショップは <店の名前>.<このドメイン> になる。例 example.jp → demo.example.jp。後から変えにくい）" \
            "" is_shop_domain "小文字のドメイン（管理画面のホスト名と同じにはできない）"
        ask SHOP_ID "最初のショップのサブドメインは?（英小文字・数字・ハイフンの 2〜32 文字。例 demo → demo.$(ec_answer SHOP_DOMAIN)）" \
            demo is_shop_id "英小文字・数字・ハイフンの 2〜32 文字（admin・api・www などと、管理画面と同じ名前は使えない）"
    fi
    ask SHOP_NAME "最初のショップの名前は?（お客さんに見える名前。後から管理画面で変えられる）" \
        "はじめてのショップ" is_name "1〜100 文字"
    # 入れたときのサンプルとテーマ（ADR 0053）。既定はお菓子のお店（角砂糖。見栄えの良いものを既定に）。
    # もう入れたサーバーで流し直すときは、聞かずに入れない（動いているショップに見本を並べない）
    installed && default_answer SAMPLES none
    ask SAMPLES "どのテーマで始めますか?（サンプルの商品とトップの見せ方が入ります。後から管理画面で消せます）
     sweets:   $(ec_sample_label sweets)。写真は Unsplash から取る
     fashion:  $(ec_sample_label fashion)。写真は Unsplash から取る
     botanica: $(ec_sample_label botanica)。写真は Unsplash から取る
     zakka:    $(ec_sample_label zakka)。写真は同梱の絵
     none:     サンプルを入れない（テーマは 角砂糖）" \
        sweets is_samples "sweets・fashion・botanica・zakka・none のどれか"
    ask OPERATOR_EMAIL "最初の管理者（管理画面に入る人）のメールは?" "" ec_valid_email "メールアドレス"
    email="$(ec_answer OPERATOR_EMAIL)"
    # 既定は付けない（メールの @ の前は名前でないことが多い。例 info）。答えのファイルで渡すときも OPERATOR_NAME を書く（無く、端末も無ければ止まる）
    ask OPERATOR_NAME "その人の表示名は?（管理画面に出る名前。例 山田 花子）" "" is_name "1〜100 文字"

    # 受け口の住所（このサーバーの IPv4。既定は外へ出る道の住所。聞かずに決め、答えのファイルで直せる）。
    # Cloudflare Tunnel の形は 127.0.0.1 に決める（外からは cloudflared を通ってだけ届く。ほかの値は 2/5 の終わりで断る）
    [ "$(ec_front)" != tunnel ] || default_answer BIND 127.0.0.1
    default_answer BIND "$(default_ip || true)"
    ask BIND "待ち受けるアドレス（このサーバーの IPv4）は?（80・443 番をこのアドレスだけで受ける）" "" is_bind "IPv4 の IP アドレス（例 203.0.113.10）"
    bind="$(ec_answer BIND)"
    if [ "$mode" = staging ]; then
        lan="$(default_lan "$bind" || true)"
        ask LAN_CIDR "見せてよい LAN の範囲は?（ステージングはこの範囲からだけ 80・443 番と SSH を受ける）" \
            "$lan" ec_valid_cidr4 "IPv4 の範囲（例 192.168.0.0/24）"
        def_allowed="$(ec_answer LAN_CIDR)"
    else
        peers="$(ssh_peers | grep -v ':' | head -n1 || true)"
        def_allowed="${peers:+$peers/32}"
    fi
    allowed_q="管理画面を開ける接続元は?（カンマ区切りの IP か範囲。会社や家の固定の IP。ここに無い所からは管理画面を開けない）"
    if [ "$mode" != staging ] && [ -n "$peers" ]; then
        # 今の SSH の接続元が私設の住所・このサーバーと同じ網の住所なら、踏み台（ssh -J）や同じ網の機械を経由している見込みが高い。
        # その住所を既定にすると、ブラウザで見る端末から管理画面を開けない（2026-10-29、ステージングの VM で踏み台の Linux の住所が既定になった）
        lan="$(default_lan "$bind" || true)"
        if is_private_ipv4 "$peers" || { [ -n "$lan" ] && ec_valid_cidr4 "$lan" && in_cidr4 "$peers" "$lan"; }; then
            allowed_q="$allowed_q
   今 SSH で接続している所（${peers}）は、プライベート IP アドレスかこのサーバーと同じネットワークの IP アドレスです（踏み台を経由している見込み）。既定にしないので、ブラウザで管理画面を見る端末の IP アドレスか範囲を入れてください（自分の IP アドレスは、その端末で https://1.1.1.1/cdn-cgi/trace を開くと ip= の行に出ます）"
            def_allowed=""
        else
            allowed_q="${allowed_q}（既定は今 SSH で接続している所。ブラウザで見る端末が別の回線なら、その IP アドレスを入れる）"
        fi
    fi
    ask ADMIN_ALLOWED_IPS "$allowed_q" "$def_allowed" ec_valid_cidr_list "IP か範囲をカンマ区切りで（例 203.0.113.5/32,198.51.100.0/24）"
    # 定期の仕事（夜の更新・朝の控え）の時刻の帯（聞かない。既定 Asia/Tokyo。サーバーの時刻の帯は変えない）
    default_answer TIMEZONE Asia/Tokyo
    ask TIMEZONE "" Asia/Tokyo ec_valid_timezone "タイムゾーンの名前（例 Asia/Tokyo）"
    # 自動の更新が見る枝（聞かない。リポジトリの今の枝か main。開発の形だけが使う）
    default_answer UPDATE_BRANCH "${EC_BRANCH:-main}"
    ask UPDATE_BRANCH "" main is_ref "ブランチの名前"
    # 自動の更新を入れるか（聞かない）。公開版の形は既定で入れる（人の鍵で署名したタグと CI の署名が通るものだけを、同じメジャーバージョンの中で取り込む。
    # ADR 0099）。開発の形は本番もステージングも既定で入れない（枝の先を人の署名なしに root で取り込むので。高）。
    # 入れるなら答えのファイルに AUTO_UPDATE=yes（開発の形でも、早送りのコミットだけを取り込む）
    default_answer AUTO_UPDATE "$([ "$(ec_channel)" = release ] && echo yes || echo no)"
    ask AUTO_UPDATE "" no is_yes_no "yes か no"
    check_tls_answers
    # サーバーの外のバックアップの答え（任意。聞かない。書いてあれば、何も入れる前に形を確かめる）
    ec_offsite_check_answers
    ask_trust
    check_release_head

    admin="$(ec_answer ADMIN_HOST)"
    if [ "$(ec_shops)" = single ]; then
        ec_ok "店の数: 1 店舗（複数の店にするときは設定ファイルに SHOPS=multi。docs/install.md）"
        ec_ok "管理画面: https://$admin/"
        ec_ok "ショップ: https://$(ec_shop_host)/（$(ec_answer SHOP_NAME)）"
    else
        ec_ok "店の数: 複数の店（店は <店の名前>.$(ec_answer SHOP_DOMAIN)）"
        ec_ok "管理画面: https://$admin/"
        ec_ok "最初のショップ: https://$(ec_shop_host)/（$(ec_answer SHOP_NAME)）"
    fi
    ec_ok "最初の管理者: ${email}（$(ec_answer OPERATOR_NAME)）"
    samples="$(ec_sample_set "$(ec_answer SAMPLES)")"
    if [ "$samples" = none ]; then
        ec_ok "サンプル: 入れない"
    else
        ec_ok "サンプル: $(ec_sample_label "$samples")$(ec_sample_fetches "$samples" && echo '。写真は Unsplash から取る')（後から管理画面のホームでまとめて消せる）"
    fi
    ec_ok "管理画面を開ける接続元: $(ec_answer ADMIN_ALLOWED_IPS)"
    if [ "$(ec_front)" = proxy ]; then
        ec_ok "リバースプロキシ: すでにある Web サーバーの後ろに置く（EC-SUGAR は $EC_FRONT_SOCKET で待ち、グループ $(ec_answer PROXY_GROUP) だけが接続できる。証明書はすでにある Web サーバーで取る）"
    elif [ "$(ec_front)" = tunnel ]; then
        ec_ok "リバースプロキシ: Cloudflare Tunnel（cloudflared が Cloudflare へ接続しに行く。ルーターのポートは開けない。EC-SUGAR の Caddy は 127.0.0.1 の 443 番で https を受ける）"
    else
        ec_ok "リバースプロキシ: EC-SUGAR の Caddy が 80・443 番を受ける"
    fi
    [ "$(ec_front)" = proxy ] || case "$(ec_tls_mode)" in
        auto) ec_ok "証明書: Let's Encrypt から自動で取る$([ "$(ec_shops)" = multi ] && echo '（店のホストは初めて開いたときに）' || echo '（管理画面とショップの 2 つ）')" ;;
        dns) ec_ok "証明書: Cloudflare の DNS の鍵で取る（管理画面と $(ec_shop_site)）" ;;
        manual) ec_ok "証明書: 手動で用意したもの（$(ec_answer TLS_CERT)$(! ec_cert_is_origin_ca "$(ec_answer TLS_CERT)" || printf '。Cloudflare の Origin CA のもので、cloudflared に元の証明書を教える')）" ;;
    esac
    ec_say "設定ファイルは $EC_ANSWERS に残しました（2 回目からは聞かない。変えるときはこのファイルを直して再実行する）"
}

# 公開版の形（CHANNEL=release。ADR 0099）: タグの署名で信じる鍵（allowed signers）を決める。
# 鍵の一覧は答えの TRUST_SIGNERS_FILE（公式サイトで配る allowed signers をサーバーに置いたもの）から読み、指紋を見せて、公式サイトで公表している
# 指紋と同じかを人に確かめさせる（鍵はリポジトリの中に置かない。ADR 0099 の 4。一覧は 4/5 で root の持ち物 /srv/ec-sugar/trust/allowed_signers に置く）。
# 答えのファイルで流すときは TRUST_FINGERPRINTS に指紋をカンマ区切りで書く（一覧の指紋とちょうど同じでなければ止まる）。もう置いてあれば聞かない
EC_TRUST_LINES=""
trust_fps_of() {
    local line who opt type key
    while IFS= read -r line; do
        [ -n "$line" ] || continue
        read -r who opt type key <<< "$line"
        ec_key_fingerprint "$type" "$key"
        printf '\n'
    done <<< "$1" | LC_ALL=C sort -u | paste -sd, -
}
ask_trust() {
    local src got want answer line who opt type key
    [ "$(ec_channel)" = release ] || return 0
    if [ -e "$EC_TRUST_FILE" ]; then
        ec_trust_file_ok || ec_die "信頼する鍵の一覧 $EC_TRUST_FILE が使えません（root:root の 0644 で、読めない行が無いこと）" "sudo ec-sugar trust list で状態を見る"
        ec_ok "タグの署名で信頼する鍵: $(ec_trust_fingerprints | awk '{print $1}' | paste -sd, -)（もう置いてある。替えるのは sudo ec-sugar trust）"
        return 0
    fi
    ask TRUST_SIGNERS_FILE "タグの署名で信頼する鍵の一覧（allowed signers）のファイルは?（公式サイトで配っているものをこのサーバーに置いたパス。例 /root/allowed_signers。docs/install.md の「タグの署名の鍵」）" \
        "" is_readable_file "このサーバーにある読めるファイルのパス"
    src="$(ec_answer TRUST_SIGNERS_FILE)"
    EC_TRUST_LINES="$(ec_trust_lines_from_file "$src" "")" ||
        ec_die "鍵の一覧 $src を読めません（1 行に 1 つ、<名前> namespaces=\"git\" <種類> <鍵> の形）"
    got="$(trust_fps_of "$EC_TRUST_LINES")"
    printf '\n   タグの署名で信頼する鍵（%s）:\n' "$src"
    while IFS= read -r line; do
        read -r who opt type key <<< "$line"
        printf '     %s  %s（%s）\n' "$(ec_key_fingerprint "$type" "$key")" "$who" "$type"
    done <<< "$EC_TRUST_LINES"
    want="$(ec_answer TRUST_FINGERPRINTS || true)"
    if [ -n "$want" ]; then
        want="$(printf '%s\n' "$want" | tr ',' '\n' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//' | grep -v '^$' | LC_ALL=C sort -u | paste -sd, -)"
        [ "$want" = "$got" ] || ec_die "設定ファイルの TRUST_FINGERPRINTS と、鍵の一覧の指紋が違います（何も入れていません）" \
            "公式サイトで公表している指紋と、$src の鍵を確認し直す（取り違えた鍵を信頼すると、その鍵の持ち主が作ったバージョンが入る）"
        ec_ok "鍵の指紋が設定ファイルの TRUST_FINGERPRINTS と同じ"
        return 0
    fi
    has_tty || ec_die "設定ファイルに TRUST_FINGERPRINTS が無く、端末も無いので、鍵を確認できません（何も入れていません）" \
        "公式サイトで公表している指紋を、設定ファイルに TRUST_FINGERPRINTS=SHA256:…,SHA256:… で書く"
    printf '   上の指紋が、公式サイトで公表している指紋と同じなら yes と打ってください（違えば止めます）\n' > /dev/tty
    read -r -p "   > " answer < /dev/tty
    [ "$answer" = yes ] || ec_die "鍵を確認できなかったので止めました（何も入れていません）" "公式サイトの指紋と同じ鍵の一覧を TRUST_SIGNERS_FILE に渡して再実行する"
    ec_kv_set "$EC_ANSWERS" TRUST_FINGERPRINTS "$got"
}

# 公開版の形で入れるバージョンのタグ（取り出した HEAD を指す semver のタグ。いちばん新しいもの）。署名は 4/5 で、置いた鍵で確かめる
EC_TAG=""
check_release_head() {
    local t
    [ "$(ec_channel)" = release ] || return 0
    installed && return 0
    while IFS= read -r t; do
        ec_valid_semver_tag "$t" || continue
        if [ -z "$EC_TAG" ] || { ec_semver_cmp "$t" "$EC_TAG" && [ "$EC_CMP" -eq 1 ]; }; then EC_TAG="$t"; fi
    done < <(repo_git tag --points-at HEAD 2> /dev/null || true)
    [ -n "$EC_TAG" ] || ec_die "release チャネルでは、署名したバージョンのタグを取り出してから入れます（今の HEAD にバージョンのタグがありません。何も入れていません）" \
        "git -C $EC_REPO checkout v1.0.0 のように、入れるバージョンのタグを取り出して再実行する"
    ec_ok "入れるバージョン: ${EC_TAG}（署名は 4/5 で確認する）"
}

# 4/5: 信じる鍵の一覧を root の持ち物で置く（もうあれば触らない。替えるのは sudo ec-sugar trust）
install_trust() {
    [ "$(ec_channel)" = release ] || return 0
    [ -e "$EC_TRUST_FILE" ] && return 0
    printf '%s\n' "$EC_TRUST_LINES" | ec_trust_write || ec_die "信頼する鍵の一覧 $EC_TRUST_FILE を書けません" "sudo ls -ld $EC_TRUST_DIR と df -h $EC_ROOT を見る"
    ec_trust_file_ok || ec_die "置いた信頼する鍵の一覧 $EC_TRUST_FILE を読めません（内部の誤り）"
    ec_ok "タグの署名で信頼する鍵を $EC_TRUST_FILE に置いた（root の 0644。更新では作業ツリーの中の鍵を読まない）"
}

# =====================================================================
# 3/5 DNS の確かめ
# =====================================================================
# 公開の DNS で名前を引く（DNS over HTTPS の JSON）。$1 名前 $2 種類（A か AAAA）。住所を 1 行ずつ出す。聞けなければ 1
dns_lookup() {
    local body want line
    body="$(curl -sS --max-time 10 -H 'accept: application/dns-json' "$EC_DOH_URL?name=$1&type=$2" 2> /dev/null)" || return 1
    [[ "$body" == *'"Status":'* ]] || return 1
    [ "$2" = A ] && want=1 || want=28
    # 答えの 1 つずつ（{…}）のうち、種類が合うものの data。CNAME（種類 5）などの途中の答えは飛ばす。
    # 最後の行には改行が無いので、読めた分も拾う（|| [ -n "$line" ]）
    while IFS= read -r line || [ -n "$line" ]; do
        [[ "$line" =~ \"type\":\ ?$want, ]] || continue
        if [[ "$line" =~ \"data\":\ ?\"([^\"]*)\" ]]; then printf '%s\n' "${BASH_REMATCH[1]}"; fi
    done < <(printf '%s' "$body" | tr '{' '\n')
    return 0
}
# このサーバーを外から見た住所（クラウドで受け口の住所が私設のときは、こちらを DNS に書く）
public_ip() {
    local ip="${EC_SUGAR_TEST_PUBLIC_IP:-}"
    [ -n "$ip" ] || ip="$(curl -sS --max-time 10 "$EC_TRACE_URL" 2> /dev/null | sed -n 's/^ip=//p' || true)"
    ec_valid_ipv4 "$ip" && printf '%s' "$ip"
}
# IPv4 の住所が範囲の中か（$1 住所 $2 範囲）
ip4_num() { local IFS=. a b c d; read -r a b c d <<< "$1"; echo $(((a << 24) | (b << 16) | (c << 8) | d)); }
in_cidr4() {
    local net="${2%/*}" len="${2#*/}" mask
    [[ "$2" == */* ]] || len=32
    mask=$(((0xFFFFFFFF << (32 - len)) & 0xFFFFFFFF))
    [ $(($(ip4_num "$1") & mask)) -eq $(($(ip4_num "$net") & mask)) ]
}
is_private_ipv4() { [[ "$1" =~ ^(10\.|127\.|169\.254\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.|100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.) ]]; }

# Cloudflare Tunnel の形の DNS（ADR 0052）: Cloudflare の代理の CNAME は、公開の DNS からは行き先が見えない（Cloudflare の住所が返る）ので確かめず、
# 作る行を出す（トンネルの ID はトークンから。分からなければ印のまま）
tunnel_dns_note() {
    local admin site id
    admin="$(ec_answer ADMIN_HOST)"
    site="$(ec_shop_site)"
    id="$(ec_tunnel_id || true)"
    ec_say "飛ばします（Cloudflare Tunnel の構成。Cloudflare のプロキシを通す CNAME は、公開の DNS からは行き先が見えないため。外から開けるかは 5/5 で確認します）"
    # 複数の店の形は、店ごとの名前を勧める（ショップのドメインに別のサーバーのサイトがあるとき、ワイルドカードの CNAME は知らない名前まで
    # 全部トンネルへ連れてくる。ADR 0090）。最初の店のホストを出し、店を足すたびに足す
    [ "$(ec_shops)" != multi ] || site="$(ec_shop_host)"
    printf '\n   Cloudflare の DNS（このドメインのゾーン）に、次の 2 行を足してください（プロキシの状態は「プロキシ済み」。オレンジの雲）:\n\n'
    printf '       種類 CNAME   名前 %-40s 値 %s.cfargotunnel.com\n' "$admin" "${id:-<トンネルの ID>}"
    printf '       種類 CNAME   名前 %-40s 値 %s.cfargotunnel.com\n' "$site" "${id:-<トンネルの ID>}"
    printf '\n   同じ名前の A・AAAA レコードがあれば消してください（cloudflared tunnel route dns <トンネルの名前> <ホスト名> でも作れます）\n'
    if [ "$(ec_shops)" = multi ]; then
        printf '   店を足したら、その店のホストの CNAME も足す（sudo ec-sugar shops で一覧が出る）。ショップのドメインにほかのサイトが無ければ、*.%s の 1 行でもよい\n' "$(ec_answer SHOP_DOMAIN)"
    fi
}

EC_DNS_WANT=()
EC_DNS_SHOW=""
# 1 つの名前を確かめる。$1 見せる名前 $2 引く名前。合えば 0
dns_check_name() {
    local label="$1" name="$2" got ip ok=1 v6
    if ! got="$(dns_lookup "$name" A)"; then
        ec_say "! $label: 公開の DNS に聞けませんでした（少し待ってもう一度見ます）"
        return 1
    fi
    for ip in $got; do
        ec_valid_ipv4 "$ip" || continue
        [[ " ${EC_DNS_WANT[*]} " == *" $ip "* ]] && ok=0
    done
    v6="$(dns_lookup "$name" AAAA || true)"
    if [ -z "$got" ]; then
        printf '   %s✗%s %s: A レコードがまだありません\n' "$EC_C_ERR" "$EC_C_END" "$label"
        return 1
    elif [ "$ok" -ne 0 ]; then
        printf '   %s✗%s %s: %s を指しています（このサーバーは %s）\n' "$EC_C_ERR" "$EC_C_END" "$label" "$(printf '%s' "$got" | tr '\n' ' ' | sed 's/ $//')" "$EC_DNS_SHOW"
        return 1
    elif [ -n "$v6" ]; then
        printf '   %s✗%s %s: AAAA レコード（IPv6）%s があります。このサーバーは IPv6 で受けないので、Let'"'"'s Encrypt がそちらへ確認に行って失敗します（消す）\n' "$EC_C_ERR" "$EC_C_END" "$label" "$(printf '%s' "$v6" | tr '\n' ' ')"
        return 1
    fi
    ec_ok "$label → $EC_DNS_SHOW"
    return 0
}

# 2 つの名前（管理画面と、ショップ〔1 店舗はそのホスト、複数の店は *.ショップのドメイン〕）が、公開の DNS でこのサーバーを指すまで待つ。
# $1 が once なら 1 回だけ見る
check_dns() {
    local once="${1:-}" admin site bind pub probe rc waited=0 max shown=0
    admin="$(ec_answer ADMIN_HOST)"
    site="$(ec_shop_site)"
    bind="$(ec_answer BIND || true)"
    if [ "$(ec_front)" = tunnel ]; then
        tunnel_dns_note
        return 0
    fi
    case "$(ec_tls_mode)" in
        dns) ec_say "飛ばします（証明書は Cloudflare の DNS の鍵で取るので、名前が外からこのサーバーを指していなくてよい）"; return 0 ;;
        manual) ec_say "飛ばします（手動で用意した証明書を使うので、ここでは確認しない。見る端末から $admin が開けるように DNS か hosts を向ける）"; return 0 ;;
    esac
    pub="$(public_ip || true)"
    EC_DNS_WANT=()
    [ -n "$pub" ] && EC_DNS_WANT+=("$pub")
    if [ -n "$bind" ] && [ "$bind" != 0.0.0.0 ] && ! is_private_ipv4 "$bind"; then
        EC_DNS_WANT+=("$bind")
    fi
    if [ "${#EC_DNS_WANT[@]}" -eq 0 ]; then
        ec_warn "このサーバーのグローバル IP アドレスが分かりません（待ち受けるアドレス $bind はプライベート IP アドレスで、外にも聞けない）。DNS の確認を飛ばします"
        return 0
    fi
    EC_DNS_SHOW="${EC_DNS_WANT[0]}"
    if [ "$once" = once ]; then
        max=0
    elif has_tty; then
        max=-1
    else
        # 端末が無い（答えのファイルで流した）ときは 10 分で止める
        max=600
    fi
    while :; do
        rc=0
        dns_check_name "$admin" "$admin" || rc=1
        if [ "$(ec_shops)" = multi ]; then
            # ワイルドカードは、毎回違う名前で引いて確かめる（引けなかった答えを DNS が覚えていても惑わされない）
            probe="$(ec_random_alnum 10)"
            probe="ec-sugar-check-${probe,,}.${site#\*.}"
        else
            probe="$site"
        fi
        dns_check_name "$site" "$probe" || rc=1
        if [ "$rc" -eq 0 ]; then
            ec_ok "2 つの名前がこのサーバー（${EC_DNS_SHOW}）を指している"
            return 0
        fi
        if [ "$shown" -eq 0 ]; then
            shown=1
            printf '\n   ドメインの DNS（ドメインを買った所か、Cloudflare などの DNS の画面）に、次の 2 行を足してください:\n\n'
            printf '       種類 A   名前 %-40s 値 %s\n' "$admin" "$EC_DNS_SHOW"
            printf '       種類 A   名前 %-40s 値 %s\n' "$site" "$EC_DNS_SHOW"
            printf '\n   違う値の A レコードや AAAA レコードがあれば消してください。反映には数分〜1 時間ほどかかることがあります\n'
        fi
        if [ "$max" -ge 0 ] && [ "$waited" -ge "$max" ]; then
            [ "$once" = once ] && return 1
            ec_die "DNS がまだこのサーバーを指していません（${max} 秒待ちました）" "上の 2 行を DNS に足し、反映を待ってから再実行する"
        fi
        ec_say "まだ向いていません。${EC_DNS_INTERVAL} 秒後にもう一度見ます（Ctrl-C で抜け、DNS を直してから再実行しても続きから進みます）"
        sleep "$EC_DNS_INTERVAL"
        waited=$((waited + EC_DNS_INTERVAL))
    done
}

# =====================================================================
# 4/5 入れて立てる
# =====================================================================
install_packages() {
    local need=() p
    for p in ca-certificates curl git openssl gnupg ufw unattended-upgrades iptables iproute2 util-linux acl jq; do
        dpkg-query -W -f='${Status}' "$p" 2> /dev/null | ec_q 'install ok installed' || need+=("$p")
    done
    if [ "${#need[@]}" -gt 0 ]; then
        ec_say "入れています: ${need[*]}"
        "${APT[@]}" update > /dev/null
        "${APT[@]}" install -y --no-install-recommends "${need[@]}" > /dev/null
    fi
    ec_ok "ツール（git・openssl・ufw・jq〔更新の前の compose のセキュリティチェック〕・自動のセキュリティ更新 など）"
}

docker_versions_ok() {
    local engine compose
    engine="$(docker version --format '{{.Server.Version}}' 2> /dev/null || true)"
    compose="$(docker compose version --short 2> /dev/null || true)"
    compose="${compose#v}"
    [ -n "$engine" ] && [ -n "$compose" ] && version_ge "$engine" "$EC_MIN_DOCKER" && version_ge "$compose" "$EC_MIN_COMPOSE"
}

install_docker() {
    local codename repo fpr arch
    if command -v docker > /dev/null; then
        systemctl enable --now docker > /dev/null 2>&1 || true
        docker_versions_ok || ec_die "Docker か compose が古いです（Engine $(docker version --format '{{.Server.Version}}' 2> /dev/null || echo '?')、compose $(docker compose version --short 2> /dev/null || echo '?')。Engine $EC_MIN_DOCKER 以上・compose $EC_MIN_COMPOSE 以上が要る）" \
            "Docker の公式の入れ方（https://docs.docker.com/engine/install/ubuntu/）で上げてから再実行する"
        ec_ok "Docker $(docker version --format '{{.Server.Version}}')・compose $(docker compose version --short)（もう入っている）"
        return 0
    fi
    codename="$(ec_kv_get /etc/os-release VERSION_CODENAME)"
    repo=https://download.docker.com/linux/ubuntu
    arch="$(dpkg --print-architecture)"
    if curl -fsSL --head --max-time 20 "$repo/dists/$codename/Release" > /dev/null 2>&1; then
        ec_say "Docker を公式のリポジトリから入れています（${codename}）"
        install -d -m 0755 /etc/apt/keyrings
        curl -fsSL --max-time 30 "$repo/gpg" -o /etc/apt/keyrings/docker.asc.new
        # 取った鍵の指紋が Docker の公表しているものと同じかを確かめる（置き場の乗っ取りで別の鍵を信じない）
        fpr="$(gpg --batch --quiet --with-colons --show-keys /etc/apt/keyrings/docker.asc.new 2> /dev/null | awk -F: '$1 == "fpr" {print $10; exit}')"
        if [ "$fpr" != "$EC_DOCKER_GPG_FPR" ]; then
            rm -f /etc/apt/keyrings/docker.asc.new
            ec_die "Docker の apt の鍵の指紋が違います（${fpr}）" "時間をおいて再実行する。続くなら https://docs.docker.com/engine/install/ubuntu/ で指紋を確認する"
        fi
        chmod a+r /etc/apt/keyrings/docker.asc.new
        mv -f /etc/apt/keyrings/docker.asc.new /etc/apt/keyrings/docker.asc
        printf 'deb [arch=%s signed-by=/etc/apt/keyrings/docker.asc] %s %s stable\n' "$arch" "$repo" "$codename" > /etc/apt/sources.list.d/docker.list
        "${APT[@]}" update > /dev/null
        "${APT[@]}" install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin > /dev/null
    else
        # LTS が出た直後は公式の置き場がまだ無いことがある。そのときは Ubuntu の同梱のバージョン（バージョンの下限は下で確かめる）
        ec_warn "Docker の公式のリポジトリが $codename にまだありません。Ubuntu の同梱のバージョンを入れます"
        "${APT[@]}" update > /dev/null
        "${APT[@]}" install -y docker.io docker-compose-v2 docker-buildx > /dev/null
    fi
    systemctl enable --now docker > /dev/null 2>&1 || ec_die "Docker を起動できません" "sudo systemctl status docker で原因を見る"
    docker_versions_ok || ec_die "入れた Docker か compose が古いです（Engine $EC_MIN_DOCKER 以上・compose $EC_MIN_COMPOSE 以上が要る）" \
        "Docker の公式の入れ方（https://docs.docker.com/engine/install/ubuntu/）で上げてから再実行する"
    ec_ok "Docker $(docker version --format '{{.Server.Version}}')・compose $(docker compose version --short)"
}

install_user_and_dirs() {
    if ! id "$EC_USER" > /dev/null 2>&1; then
        # ログインできない専用の利用者。git の取り込みだけをこの利用者で行う（docker のグループには入れない＝root 相当にしない）
        useradd --system --user-group --home-dir "$EC_HOME" --no-create-home --shell /usr/sbin/nologin "$EC_USER"
    fi
    # 置き場の持ち主と権限（流すたびに揃え直す）
    #   /srv/ec-sugar          root:ec-sugar 0750
    #   src/  home/            ec-sugar のもの（git の作業場所と、その利用者の鍵）
    #   env/ secrets/ backup/  root だけ（合言葉・鍵・個人情報を含む DB の控え）
    #   secrets/caddy/         入口の Caddy の利用者（コンテナの中の 65532）だけ（DNS の API の鍵・手で用意した秘密の鍵）
    #   secrets/tunnel/        Cloudflare Tunnel の形だけ。root の持ち物・cloudflared（65533）のグループ 0750（トークンと設定。中は 0440）
    #   secrets/offsite/       サーバーの外のバックアップを設定した時だけ。root だけ（置き場の書く鍵と暗号の公開鍵。中は 0400。ec_offsite_put_secrets）
    #   offsite/               同じく設定した時だけ。root だけ（送る分の控えと一時の置き場。バックアップの置き場の外）
    #   state/                 root だけ書ける（バージョンと更新の記録。合言葉は入れない）
    install -d -m 0750 -o root -g "$EC_USER" "$EC_ROOT"
    install -d -m 0700 -o "$EC_USER" -g "$EC_USER" "$EC_HOME"
    install -d -m 0700 -o root -g root "$EC_ENV_DIR" "$EC_SECRETS" "$EC_BACKUP"
    install -d -m 0700 -o "$EC_CADDY_UID" -g "$EC_CADDY_UID" "$EC_CADDY_SECRETS"
    [ "$(ec_front)" != tunnel ] || ec_tunnel_dir_ensure
    if [ "$(ec_front)" = proxy ]; then
        # 入口のソケットの置き場: 持ち主は root、グループは専用のグループ ec-sugar-front（EC-SUGAR の nginx のコンテナが group_add で足し、
        # ソケットを作る）、2770。前の Web サーバーの働き手のグループには、ACL で通り抜け（x）だけを許す（中を見られず、ソケットにだけつなげる）。
        # ほかの利用者は置き場に入れない（nginx はソケットそのものを 0666 にするので、守りは置き場）
        getent group "$EC_FRONT_SOCKET_GROUP" > /dev/null || groupadd --system "$EC_FRONT_SOCKET_GROUP"
        install -d -m 0755 -o root -g root "$(dirname "$EC_FRONT_SOCKET_DIR")"
        install -d -m 2770 -o root -g "$EC_FRONT_SOCKET_GROUP" "$EC_FRONT_SOCKET_DIR"
        chown "root:$EC_FRONT_SOCKET_GROUP" "$EC_FRONT_SOCKET_DIR"
        chmod 2770 "$EC_FRONT_SOCKET_DIR"
        setfacl -b "$EC_FRONT_SOCKET_DIR"
        setfacl -m "g:$(ec_answer PROXY_GROUP):--x" "$EC_FRONT_SOCKET_DIR" || ec_die "ソケットのディレクトリに ACL を付けられません（${EC_FRONT_SOCKET_DIR}）" "ACL を使えるファイルシステムか確認する（getfacl ${EC_FRONT_SOCKET_DIR}）"
    fi
    install -d -m 0750 -o root -g root "$EC_STATE"
    install -d -m 0700 -o root -g root "$EC_LOGS"
    chown root:root "$EC_ANSWERS"
    chmod 0600 "$EC_ANSWERS"
    ec_ok "利用者 $EC_USER と $EC_ROOT/{src,env,secrets,backup,state}"
}

install_firewall() {
    local mode lan p peer ports
    mode="$(ec_answer MODE)"
    if [ "$(ec_front)" = proxy ]; then
        ec_ok "ufw と DOCKER-USER には触らない（すでにある Web サーバーの後ろに置く構成。EC-SUGAR は TCP で待たず、Unix ソケット $EC_FRONT_SOCKET だけで待つ）"
        ec_say "ホストのファイアウォール（OUTPUT のルール。root でない利用者からコンテナへ直に届かない）は、この構成でも入れる（このあとの起動で）"
        return 0
    fi
    ports="$(ssh_ports)"
    [ -n "$ports" ] || ports=22
    ufw default deny incoming > /dev/null
    ufw default allow outgoing > /dev/null
    if [ "$mode" = staging ]; then
        lan="$(ec_answer LAN_CIDR)"
        for p in $ports; do
            ufw allow from "$lan" to any port "$p" proto tcp comment 'ec-sugar ssh (LAN)' > /dev/null
            # 今つないでいる SSH の接続元を締め出さない
            for peer in $(ssh_peers); do
                ufw allow from "$peer" to any port "$p" proto tcp comment 'ec-sugar ssh (current)' > /dev/null
            done
        done
        if [ "$(ec_front)" = tunnel ]; then
            # Cloudflare Tunnel の形は 80・443 番を外に開けない（Caddy は 127.0.0.1 だけで受け、外からは cloudflared を通ってだけ届く）。前の形で足した許しは消す
            ufw delete allow from "$lan" to any port 80 proto tcp > /dev/null 2>&1 || true
            ufw delete allow from "$lan" to any port 443 proto tcp > /dev/null 2>&1 || true
        else
            ufw allow from "$lan" to any port 80 proto tcp comment 'ec-sugar http (LAN)' > /dev/null
            ufw allow from "$lan" to any port 443 proto tcp comment 'ec-sugar https (LAN)' > /dev/null
        fi
    else
        for p in $ports; do
            # 総当たりを遅らせる（30 秒に 6 回まで）
            ufw limit "$p/tcp" comment 'ec-sugar ssh' > /dev/null
        done
        if [ "$(ec_front)" = tunnel ]; then
            ufw delete allow 80/tcp > /dev/null 2>&1 || true
            ufw delete allow 443/tcp > /dev/null 2>&1 || true
        else
            ufw allow 80/tcp comment 'ec-sugar http' > /dev/null
            ufw allow 443/tcp comment 'ec-sugar https' > /dev/null
        fi
    fi
    ufw --force enable > /dev/null || ec_die "ufw を有効にできませんでした" "sudo ufw enable の誤りを見る"
    if [ "$(ec_front)" = tunnel ]; then
        ec_ok "ufw（SSH ${ports//$'\n'/・} 番は$([ "$mode" = staging ] && echo " $lan と今の接続元" || echo '回数を絞って許す')、80・443 番は開けない。Cloudflare Tunnel の構成）"
        ec_say "EC-SUGAR の Caddy は 127.0.0.1 の 80・443 番だけで受ける（EC_SUGAR_BIND）。外からは cloudflared が Cloudflare から受けた要求だけが届く"
        return 0
    fi
    if [ "$mode" = staging ]; then
        ec_ok "ufw（SSH ${ports//$'\n'/・} 番は $lan と今の接続元、80・443 番は $lan だけ）"
    else
        ec_ok "ufw（SSH ${ports//$'\n'/・} 番は回数を絞って許す、80・443 番は誰でも）"
    fi
    ec_say "Docker は ufw を迂回するので、80・443 番の本当の締めは compose の待ち受けるアドレス（EC_SUGAR_BIND）$([ "$mode" = staging ] && echo 'と DOCKER-USER のルール')で行う"
}

install_auto_upgrades() {
    local f=/etc/apt/apt.conf.d/20auto-upgrades want
    want=$'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";'
    if [ "$(cat "$f" 2> /dev/null || true)" != "$want" ]; then
        printf '%s\n' "$want" > "$f"
    fi
    systemctl enable --now unattended-upgrades > /dev/null 2>&1 || true
    ec_ok "自動のセキュリティ更新（unattended-upgrades。Ubuntu のセキュリティの修正だけ。Docker は自動では上げない）"
}

EC_ENV_CREATED=0
create_env() {
    local mode admin shop key acme
    mode="$(ec_answer MODE)"
    admin="$(ec_answer ADMIN_HOST)"
    shop="$(ec_shop_domain)"
    if [ ! -f "$EC_ENV_FILE" ]; then
        install -m 0600 -o root -g root "$EC_REPO/deploy/production.env.example" "$EC_ENV_FILE"
        EC_ENV_CREATED=1
        # 作るときだけ決めるもの（後で人が書き換えたら残す）
        if [ "$mode" = production ]; then
            # メールの送信先を決めるまでは送らずに捨てる（個人情報を記録に書かない）。docs/install.md の「入れた後: ec-sugar コマンド」
            ec_env_set MAIL_MAILER array
            ec_env_set MAIL_HOST ''
            ec_env_set PAYMENT_LIVE_KEYS_ALLOWED true
        fi
        ec_env_set MAIL_FROM_ADDRESS "\"noreply@$shop\""
    else
        # 前に入れたサーバー: 前の名前（SUGAR_EC_…）の行に、新しい名前（EC_SUGAR_…）の行を同じ値で持たせる（前の名前の行は残し、
        # この後の書き込みは両方へ。新しく作る env は見本から作るので新しい名前だけ）。移せないまま進むと、下の「空のときだけ作る」が
        # DB の管理者のパスワードを作り直しかねないので止める
        ec_env_migrate || ec_die "設定 $EC_ENV_FILE に新しい名前の行を足せません（上の行）" "権限と空き容量を確認して再実行する（sudo ls -l ${EC_ENV_FILE}、df -h ${EC_ENV_DIR}）"
    fi
    # 【作る】空のときだけ作る（作り直さない。APP_KEY を作り直すと暗号化した個人情報が読めなくなる）
    for key in EC_SUGAR_DB_SUPERUSER_PASSWORD DB_PASSWORD REDIS_PASSWORD; do
        [ -n "$(ec_env_get "$key" || true)" ] || ec_env_set "$key" "$(ec_random_alnum 40)"
    done
    [ -n "$(ec_env_get APP_KEY || true)" ] || ec_env_set APP_KEY "$(ec_random_app_key)"
    ec_env_set EC_SUGAR_ENV_FILE "$EC_ENV_FILE"
    # 入口（Caddy）: 証明書の取り方・秘密の置き場・取り先（ACME_CA は試験のときだけ）
    ec_env_set EC_SUGAR_TLS_MODE "$(ec_tls_mode)"
    ec_env_set EC_SUGAR_CADDY_SECRETS "$EC_CADDY_SECRETS"
    # 後ろに置く形で、入口の nginx が待つ Unix ソケットの置き場
    if [ "$(ec_front)" = proxy ]; then
        ec_env_set EC_SUGAR_FRONT_SOCKET_DIR "$EC_FRONT_SOCKET_DIR"
        # ソケットの置き場のグループの番号（EC-SUGAR の nginx のコンテナが group_add で足す）
        ec_env_set EC_SUGAR_FRONT_SOCKET_GID "$(getent group "$EC_FRONT_SOCKET_GROUP" | cut -d: -f3)"
    fi
    # Cloudflare Tunnel の形で、cloudflared の設定とトークンの置き場（compose が読み取り専用で /etc/cloudflared に差し込む）
    [ "$(ec_front)" != tunnel ] || ec_env_set EC_SUGAR_TUNNEL_DIR "$EC_TUNNEL_DIR"
    acme="$(ec_answer ACME_CA || true)"
    ec_env_set EC_SUGAR_ACME_CA "${acme:-$EC_ACME_DEFAULT}"
    if [ -n "$(ec_answer ACME_CA_ROOT || true)" ]; then
        ec_env_set EC_SUGAR_ACME_CA_ROOT /run/secrets/ec-sugar/acme-ca-root.pem
    else
        ec_env_set EC_SUGAR_ACME_CA_ROOT /etc/ssl/certs/ca-certificates.crt
    fi
    # 【聞く】答えから毎回書く（答えのファイルが正）
    ec_env_set EC_SUGAR_BIND "$(ec_answer BIND)"
    ec_env_set EC_SUGAR_ADMIN_HOST "$admin"
    # 店の数の形で決まる項目（ショップのホストの親・店の数の形・入口が受ける名前・中央のホスト）。sudo ec-sugar shops と同じもの（common.sh）
    ec_env_put_shops || ec_die "管理画面かショップのホスト名の形が違うので、設定を書けません" "$EC_ANSWERS の ADMIN_HOST・SHOP_HOST（複数の店は SHOP_DOMAIN）を直して再実行する"
    ec_env_set APP_URL "https://$admin"
    ec_env_set ADMIN_DOMAIN "$admin"
    ec_env_set ADMIN_URL "https://$admin"
    ec_env_set ADMIN_ALLOWED_IPS "$(ec_answer ADMIN_ALLOWED_IPS)"
    if [ "$mode" = staging ]; then
        ec_env_set EC_SUGAR_HSTS_MAX_AGE 300
        # ステージングは本番の Stripe の鍵を置けない・メールは Mailpit で受けて外へ出さない（ADR 0029）
        ec_env_set PAYMENT_LIVE_KEYS_ALLOWED false
        ec_env_set MAIL_MAILER smtp
        ec_env_set MAIL_HOST mailpit
        ec_env_set MAIL_PORT 1025
    else
        ec_env_set EC_SUGAR_HSTS_MAX_AGE 31536000
    fi
    # ステージングの Mailpit の画面のパスワード（ハッシュの保存先。Mailpit はここが無ければ起きない）
    ec_env_set EC_SUGAR_MAILPIT_SECRETS "$EC_MAILPIT_SECRETS"
    chown root:root "$EC_ENV_FILE"
    chmod 0600 "$EC_ENV_FILE"
    ec_ok "設定 ${EC_ENV_FILE}（root だけ読める 0600。APP_KEY・DB と Redis のパスワードはここで作った。画面には出さない）"
    if [ "$mode" = staging ]; then
        ec_mailpit_auth_ensure || ec_die "Mailpit の画面のパスワードを作れません（上の行）" "openssl が入っているかを確認して再実行する"
        ec_ok "Mailpit の画面のパスワード（利用者 ${EC_MAILPIT_USER}。root だけが読める $EC_MAILPIT_PASSWORD_FILE に置いた。画面には出さない）"
    fi
}

# $1 元 $2 先 $3 権限。中身が違うときだけ、Caddy の利用者の持ち物で写す（先に仮の名前で書いてから入れ替える）。写したら EC_TLS_CHANGED=1
EC_TLS_CHANGED=0
put_caddy_secret() {
    local from="$1" to="$2" mode="$3"
    cmp -s "$from" "$to" 2> /dev/null && return 0
    install -m "$mode" -o "$EC_CADDY_UID" -g "$EC_CADDY_UID" "$from" "$to.new"
    mv -f "$to.new" "$to"
    EC_TLS_CHANGED=1
}

# 入口の Caddy の秘密（取り方ごとに要るものを置く。元のファイルは消してよい）。
# ここでは足すだけで消さない: 今動いている取り方の秘密は、新しい取り方で立って健全になってから ec_prune_caddy_secrets（common.sh）が消す
# （途中で止まっても、env と動いている入口が前の取り方のまま使える秘密が残る）
install_caddy_secrets() {
    local mode cert key f token tmp root dir="$EC_CADDY_SECRETS"
    mode="$(ec_tls_mode)"
    install -d -m 0700 -o "$EC_CADDY_UID" -g "$EC_CADDY_UID" "$dir"
    case "$mode" in
        dns)
            f="$(ec_answer CLOUDFLARE_TOKEN_FILE)"
            if is_readable_file "$f"; then
                # 鍵は bash の中だけで読み、形を確かめてから書く（画面・記録・コマンドの引数に出さない）
                IFS= read -r token < "$f" || true
                token="${token%$'\r'}"
                ec_valid_cf_token "$token" || ec_die "CLOUDFLARE_TOKEN_FILE の鍵の形が違います（Cloudflare の API トークンの形でない。中身は出しません）" \
                    "Cloudflare の画面で作った API トークンだけを、引用符や括弧を付けずに 1 行で書いたファイルを渡す"
                tmp="$(mktemp "$dir/.token.XXXXXX")"
                printf '%s\n' "$token" > "$tmp"
                token=""
                put_caddy_secret "$tmp" "$dir/cloudflare_token" 0400
                rm -f "$tmp"
            elif [ ! -e "$dir/cloudflare_token" ]; then
                ec_die "CLOUDFLARE_TOKEN_FILE のファイル $f が読めません" "鍵のファイルを置き直して再実行する"
            fi
            ec_ok "DNS の API トークンは $dir の中だけ（リバースプロキシの Caddy だけが読める 0400。元のファイル $f は消してよい）"
            ;;
        manual)
            cert="$(ec_answer TLS_CERT)"
            key="$(ec_answer TLS_KEY)"
            if ! is_readable_file "$cert" || ! is_readable_file "$key"; then
                # 元のファイルを消した後の 2 回目: 写したもの（前の形の置き場も）を使う
                if [ -r "$dir/tls/cert.pem" ] && [ -r "$dir/tls/key.pem" ]; then
                    cert="$dir/tls/cert.pem" key="$dir/tls/key.pem"
                elif [ -r "$EC_TLS_DIR/cert.pem" ] && [ -r "$EC_TLS_DIR/key.pem" ]; then
                    cert="$EC_TLS_DIR/cert.pem" key="$EC_TLS_DIR/key.pem"
                else
                    ec_die "証明書か鍵のファイルが読めません（${cert}・${key}）" "ファイルを置き直すか、$EC_ANSWERS の TLS_CERT・TLS_KEY を直して再実行する"
                fi
                ec_say "渡された証明書のファイルが無いので、前にコピーしたものを使う"
            fi
            ec_check_tls "$cert" "$key"
            install -d -m 0500 -o "$EC_CADDY_UID" -g "$EC_CADDY_UID" "$dir/tls"
            [ "$cert" = "$dir/tls/cert.pem" ] || put_caddy_secret "$cert" "$dir/tls/cert.pem" 0444
            [ "$key" = "$dir/tls/key.pem" ] || put_caddy_secret "$key" "$dir/tls/key.pem" 0400
            ec_ok "手動で用意した証明書$(! ec_cert_is_origin_ca "$dir/tls/cert.pem" || printf '（Cloudflare の Origin CA のもの。確認するのは cloudflared だけ）')（期限 $(openssl x509 -in "$dir/tls/cert.pem" -noout -enddate | cut -d= -f2)）。秘密の鍵は $dir の中だけ（元のファイルは消してよい）"
            ;;
        *)
            [ "$(ec_front)" != proxy ] || { ec_ok "証明書は、すでにある Web サーバーが受け持つ（EC-SUGAR は取らない）"; return 0; }
            ec_ok "証明書はリバースプロキシの Caddy が Let's Encrypt から取る（管理画面は起動のときに、店のホストは初めて開いたときに）"
            ;;
    esac
    root="$(ec_answer ACME_CA_ROOT || true)"
    [ -z "$root" ] || put_caddy_secret "$root" "$dir/acme-ca-root.pem" 0444
}

# Cloudflare Tunnel の形（ADR 0052）: トンネルのトークンと cloudflared の設定（答えから）を置き場へ。ほかの形では何もしない。
# 入口の証明書を写した後に流す（Caddy の証明書が Cloudflare の Origin CA のものなら、元の証明書を置いて caPool を足す。見直しの中 3）。
# トンネルの見張りの読むだけの鍵（任意。見直しの中 5）は別の置き場（root だけ）へ
install_tunnel_secrets() {
    [ "$(ec_front)" = tunnel ] || return 0
    ec_tunnel_dir_ensure
    ec_tunnel_put_token "$(ec_answer TUNNEL_TOKEN_FILE || true)"
    ec_tunnel_put_config
    ec_tunnel_watch_put_key "$(ec_answer TUNNEL_WATCH_TOKEN_FILE || true)"
}

# サーバーの外のバックアップ（ADR 0110）の鍵を置き場へ写す。答えに OFFSITE_… が無ければ何も作らない
install_offsite_secrets() {
    ec_offsite_put_secrets
}

create_src() {
    if [ ! -d "$EC_SRC/.git" ]; then
        rm -rf "$EC_SRC.partial"
        git -c safe.directory="$EC_REPO" clone --quiet --no-local --no-checkout "$EC_REPO" "$EC_SRC.partial"
        chown -R "$EC_USER:$EC_USER" "$EC_SRC.partial"
        mv "$EC_SRC.partial" "$EC_SRC"
    elif ! installed; then
        # 初回が途中で止まり、リポジトリを新しくしてから流し直したとき: 取ったバージョンを作業場所にも取り込む
        git -c safe.directory="$EC_SRC" -c safe.directory="$EC_REPO" -C "$EC_SRC" fetch --quiet "$EC_REPO" HEAD
        chown -R "$EC_USER:$EC_USER" "$EC_SRC"
    fi
    chmod 0750 "$EC_SRC"
    ec_src_fix_modes
    ec_git remote set-url origin "$EC_ORIGIN"
    if ! installed; then
        ec_git -c advice.detachedHead=false checkout --quiet --force --detach "$EC_COMMIT"
    fi
    ec_ok "作業ツリー ${EC_SRC}（$(ec_src_head | cut -c1-12)、取得元 ${EC_ORIGIN}）"
}

# 前の形（より前。nginx が https を受け、証明書を手で渡す）から、入口の 2 段（Caddy）へ移る。
# 入っているバージョンの compose には caddy が無いので、このリポジトリのバージョン（$EC_COMMIT）へ ec-sugar update で上げる
# （控え → 移行 → 入れ替え → 健全か → 駄目なら前のバージョンへ戻す。前の形の証明書の置き場と env の行は消さないので、戻しても動く）。
# 運用の道具は、このリポジトリの新しいもの（前の形の道具は入口の 2 段を知らない）
migrate_to_caddy() {
    local cur
    cur="$(ec_state_get current_commit)"
    ec_say "リバースプロキシが前の構成（nginx が https を受ける）です。Caddy を前に置く 2 段の構成のバージョン ${EC_COMMIT:0:12} へ更新して移ります（今のバージョン ${cur:0:12}）"
    [ "$cur" != "$EC_COMMIT" ] || ec_die "入っているバージョンとこのリポジトリのバージョンが同じですが、リバースプロキシが前の構成です（内部の誤り）" "sudo ec-sugar status で状態を見る"
    ec_install_tools "$EC_REPO/deploy"
    # 更新は自分でロックを取るので、ここのロックを放してから呼び、終わったら取り直す
    exec 9>&-
    "$EC_BIN" update --to "$EC_COMMIT" ||
        ec_die "2 段の構成（Caddy）へ移れませんでした（前のバージョンのまま動いています）" "sudo ec-sugar status と sudo ec-sugar logs caddy web で理由を見る" "直してから sudo ./deploy/install.sh を再実行する"
    ec_lock
    ec_ok "2 段の構成（Caddy）へ移った（前のバージョンへロールバックするときは sudo ec-sugar rollback。前の構成の証明書の保存先は残してある）"
}

stand_up() {
    local commit image deploy_dir
    if installed && [ "$(ec_channel)" = release ]; then
        # 公開版の形: 入っているバージョンのイメージを、署名を確かめ直して digest で取り直す（入口の Caddy の名前もそろう。作らない）
        commit="$(ec_state_get current_commit)"
        image="$(ec_image_of "$commit")"
        ec_say "もう入っている（バージョン $(ec_state_get current_version || echo '?')、${commit:0:12}）。バージョンを上げるのは sudo ec-sugar update"
        # compose は入っているコミットの git の中身から取り出した root だけの置き場で（作業場所は使わない）
        ec_compose_use "$commit" || ec_die "バージョン ${commit:0:12} の compose を git から取り出せません" "sudo -u $EC_USER git -C $EC_SRC fsck で作業ツリーの git を確認する"
        ec_obtain_image "$commit" "$(ec_state_get current_version || true)" || ec_die "入っているバージョンのイメージを確認して取れませんでした" "記録 $EC_LOGS/ の verify-*.log を見て、直してから再実行する"
    elif installed; then
        commit="$(ec_state_get current_commit)"
        image="$(ec_image_of "$commit")"
        if ! ec_with_image "$image" ec_has_caddy; then
            migrate_to_caddy
            commit="$(ec_state_get current_commit)"
            image="$(ec_image_of "$commit")"
        fi
        ec_say "もう入っている（バージョン ${commit:0:12}）。バージョンを上げるのは sudo ec-sugar update"
        ec_obtain_caddy "$image" || ec_die "リバースプロキシのイメージを作れませんでした" "ログ $EC_LOGS/ の build-ec-sugar-caddy-*.log を見て、直してから再実行する"
    else
        commit="$(ec_src_head)"
        image="$(ec_image_of "$commit")"
        if [ "$(ec_channel)" = release ]; then
            # 公開版の形: 取り出したタグの人の署名を、置いた鍵だけで確かめる（ADR 0099）
            ec_tag_verify "$EC_TAG" || ec_die "${EC_TAG_PROBLEM}（何も起動していません）" "公式サイトで公表している鍵と指紋・取り出したタグを確認する（sudo ec-sugar trust list）"
            [ "$EC_TAG_COMMIT" = "$commit" ] || ec_die "タグ ${EC_TAG} の指すコミットが、取り出したコミットと違います（内部の誤り）"
            ec_ok "タグ ${EC_TAG} の署名が通った（鍵 ${EC_TAG_SIGNER}）"
            # compose は確かめたコミットの git の中身から root だけの置き場へ取り出して使う（作業場所は利用者 ec-sugar が書ける）
            ec_compose_use "$commit" || ec_die "バージョン ${commit:0:12} の compose を git から取り出せません（何も起動していません）" "sudo -u $EC_USER git -C $EC_SRC fsck で作業ツリーの git を確認する"
        fi
        ec_obtain_image "$commit" "$EC_TAG" || ec_die "イメージを取得できませんでした" "ログ $EC_LOGS/ の build-*.log（release チャネルは verify-*.log）を見て、直してから再実行する"
        ec_env_set EC_SUGAR_IMAGE "$image"
        ec_say "DB と Redis を起動しています"
        # 網を作ったら、起こす前にホストの防火壁を当てる（DB と Redis が、root でない利用者から届く間を作らない）
        ec_dc up --no-start db redis > /dev/null 2>&1 || ec_die "DB と Redis のコンテナを作れません" "sudo docker compose -p $EC_PROJECT logs db redis で原因を見る"
        ec_firewall_apply || ec_die "ホストのファイアウォールを適用できません（上の行。何も起動していません）" "iptables が使えるか（sudo iptables -S OUTPUT）を確認して再実行する"
        ec_dc up -d --wait --wait-timeout 300 db redis > /dev/null 2>&1 || ec_die "DB か Redis が起きません" "sudo docker compose -p $EC_PROJECT logs db redis で原因を見る"
        ec_wait_db || ec_die "DB が受け付けません" "sudo docker compose -p $EC_PROJECT logs db で原因を見る"
        ec_migrate "$image" || ec_die "マイグレーションに失敗しました" "ログ $EC_LOGS/ の migrate-*.log を見る"
    fi

    # 運用の道具と unit は、入れるコミットの git の中身から（作業場所は利用者 ec-sugar が書けるので使わない。高）
    deploy_dir="$(ec_deploy_from_commit "$commit")" || ec_die "バージョン ${commit:0:12} の運用のスクリプトを git から読めません" "sudo -u $EC_USER git -C $EC_SRC fsck で作業ツリーの git を確認する"
    ec_install_tools "$deploy_dir/deploy"
    rm -rf "$deploy_dir"
    # ホストの防火壁（どの形でも: root でない利用者からコンテナへ直に届かない。ステージングは加えて受け口を LAN だけに）
    systemctl enable --now ec-sugar-firewall.service > /dev/null 2>&1 || ec_die "ホストのファイアウォールのルール（ec-sugar-firewall）を入れられません" "sudo systemctl status ec-sugar-firewall で原因を見る"
    if [ "$(ec_answer MODE)" = staging ] && [ "$(ec_front)" != proxy ]; then
        ec_ok "待ち受けの 80・443 番は $(ec_answer LAN_CIDR) からだけ（DOCKER-USER のルール）"
    fi
    systemctl enable ec-sugar.service > /dev/null 2>&1
    systemctl start ec-sugar.service || ec_die "EC-SUGAR を起動できません" "sudo ec-sugar logs で原因を見る"
    if [ "$EC_TLS_CHANGED" -eq 1 ] && installed; then
        # 手で用意した証明書や DNS の鍵を替えたとき: 入口を作り直して読み直させる
        ec_dc up -d --no-deps --force-recreate caddy > /dev/null 2>&1
    fi
    if [ "$EC_TUNNEL_CHANGED" -eq 1 ] && installed; then
        # トンネルのトークンや設定を替えたとき: cloudflared は起動のときにだけ読むので作り直す
        ec_dc up -d --no-deps --force-recreate cloudflared > /dev/null 2>&1
    fi
    # 証明書はまだ取っている途中のことがあるので、https は 5/5 で確かめる
    EC_HEALTH_TLS=0 ec_health "$image" || ec_die "立てましたがヘルスチェックを通りません" "sudo ec-sugar logs app web caddy で原因を見る"
    ec_prune_caddy_secrets

    if ! installed; then
        ec_state_put current_commit "$commit"
        [ "$(ec_channel)" != release ] || ec_state_put current_version "$EC_TAG"
        ec_state_put installed "$(date -Is)"
        ec_record ok "" "$commit" "インストールした"
    fi
    systemctl enable --now ec-sugar-backup.timer > /dev/null 2>&1
    if [ "$(ec_answer AUTO_UPDATE)" = yes ]; then
        systemctl enable --now ec-sugar-update.timer > /dev/null 2>&1
        ec_state_put auto_update yes
        ec_ok "自動の更新（$([ "$(ec_answer MODE)" = staging ] && echo '15 分ごと' || echo '毎晩')、$([ "$(ec_channel)" = release ] && echo '署名の通る新しいタグだけ。同じメジャーバージョンの中' || echo "ブランチ $(ec_answer UPDATE_BRANCH) の fast-forward のコミット")）と毎朝のバックアップを入れた"
    else
        # 前に入れていた自動の更新も、流し直すと止める（開発の形は既定で切る。高）
        systemctl disable --now ec-sugar-update.timer > /dev/null 2>&1 || true
        ec_state_put auto_update no
        ec_ok "毎朝のバックアップを入れた。自動の更新は入れていない（更新は sudo ec-sugar update で）"
    fi
}

# 最初のショップを作る（同じものがあれば何もしない。店の DB を作る力は CLI だけが持つ）
create_first_shop() {
    local id name shop out
    id="$(ec_answer SHOP_ID)"
    name="$(ec_answer SHOP_NAME)"
    shop="$(ec_shop_host)"
    if ! out="$(ec_dc exec -T app php artisan shop:create "$id" --name="$name" --domain="$shop" 2>&1)"; then
        printf '%s\n' "$out" | sed 's/^/   | /' >&2
        ec_die "最初のショップ $id を作れませんでした" "上の理由を見て、$EC_ANSWERS の SHOP_ID・SHOP_NAME を直して再実行する"
    fi
    ec_ok "最初のショップ ${id}（${name}）: https://$shop/"
}

# 入れたときのサンプル（ADR 0053）を、答えのセットで最初のショップに入れる（ec_add_samples。テーマの切り替えも shop:sample がする）。
# 入れ終えたら 2 回目からは流さない（消した後に戻さない）。入れられなくてもインストールは止めない（ショップは動く）。流し直すと残りだけを入れる
add_samples() {
    local sample_set
    sample_set="$(ec_sample_set "$(ec_answer SAMPLES || true)")" || sample_set=none
    ec_add_samples "$(ec_answer SHOP_ID)" "$sample_set"
}

# APP_KEY の控えの案内。まだ出していなければ出す（初回が途中で止まって 2 回目に進んだときも、一度は必ず出す）
app_key_note() {
    [ "$EC_ENV_CREATED" -eq 1 ] || [ ! -e "$EC_STATE/app_key_noted" ] || return 0
    ec_state_put app_key_noted "$(date -Is)"
    printf '\n   %s大事: 復元のキットを作ってください%s。お客さんの個人情報を暗号化する鍵の元（APP_KEY）は、バックアップには入りません。失くすと、バックアップがあっても読めません。\n' "$EC_C_WARN" "$EC_C_END"
    printf '   作り方: 管理画面にオーナーで入り、運営ダッシュボードの「復元のキット」でパスフレーズを決めて保存する（鍵は画面にもサーバーの記録にも出ません）。保存したファイルを復号し直して確認するまで、運営ダッシュボードに通知が出続けます。\n'
    printf '   サーバーの上でバックアップを取るなら: sudo ec-sugar app-key（説明）・sudo ec-sugar app-key export /root/ec-sugar-env-backup\n'
}

# =====================================================================
# 5/5 仕上げ
# =====================================================================
# 外から https で開けるか（証明書が取れていて、ブラウザが信じる形か）。$1 ホスト $2 道筋 $3 よい応答の形（up なら 200、page なら 500 未満）
https_ready() {
    local host="$1" path="$2" kind="$3" verify=verify trust code
    trust="${EC_SUGAR_TEST_TRUST:-}"
    # 手で用意した証明書は、自前の認証局のこともあるので信頼の確かめはしない（中身は 4/5 で確かめた）
    [ "$(ec_tls_mode)" = manual ] && verify=""
    code="$(ec_http_code "$host" "$path" "$verify" "$trust")"
    if [ "$kind" = up ]; then
        [ "$code" = 200 ]
    else
        ec_code_below_500 "$code"
    fi
}

check_https() {
    local admin shop_host i ok_admin=1 ok_shop=1 limit=60
    admin="$(ec_answer ADMIN_HOST)"
    shop_host="$(ec_shop_host)"
    ec_say "https で開けるか確認しています（証明書を取るのに 1 分ほどかかることがあります）"
    # 3 秒ごとに最大 3 分
    for i in $(seq 1 "$limit"); do
        [ "$ok_admin" -eq 0 ] || { https_ready "$admin" /up up && ok_admin=0; }
        [ "$ok_shop" -eq 0 ] || { https_ready "$shop_host" / page && ok_shop=0; }
        [ "$ok_admin" -eq 0 ] && [ "$ok_shop" -eq 0 ] && break
        [ "$i" -lt "$limit" ] && sleep 3
    done
    if [ "$ok_admin" -ne 0 ] || [ "$ok_shop" -ne 0 ]; then
        local why
        why="https で開けません（$([ "$ok_admin" -ne 0 ] && echo "$admin ")$([ "$ok_shop" -ne 0 ] && echo "$shop_host")。証明書が取れていないか、リバースプロキシに届かない）"
        case "$(ec_tls_mode)" in
            dns)
                ec_die "$why" "sudo ec-sugar logs caddy で証明書を取るときの誤りを見る" \
                    "Cloudflare の API トークンが、このドメインの「ゾーン DNS の編集」を許していて、期限が切れていないかを確認する" \
                    "鍵を作り直したら、CLOUDFLARE_TOKEN_FILE のファイルを置き直して再実行する" ;;
            manual)
                ec_die "$why" "sudo ec-sugar logs caddy web で誤りを見る" "証明書と鍵のファイル（TLS_CERT・TLS_KEY）を確認して再実行する" ;;
            *)
                ec_die "$why" "sudo ec-sugar logs caddy で証明書を取るときの誤りを見る" \
                    "DNS の 2 行（3/5）と、クラウドのファイアウォールで 80・443 番を開けたかを確認する" \
                    "Let's Encrypt の回数の上限に触れたときは、1 時間ほど待ってから再実行する（取れた証明書は残っているので続きから進む）" ;;
        esac
    fi
    if [ "$(ec_tls_mode)" = manual ]; then
        ec_ok "https://$admin/ と https://$shop_host/ が開ける（手動で用意した証明書）"
    elif [ -n "${EC_SUGAR_TEST_TRUST:-}" ]; then
        ec_ok "https://$admin/ と https://$shop_host/ が開ける（試験の認証局の証明書。ブラウザは信頼しない）"
    else
        ec_ok "https://$admin/ と https://$shop_host/ が開ける（ブラウザが信頼する証明書）"
    fi
}

EC_ENROLL_URL=""
create_operator() {
    local email name out line
    email="$(ec_answer OPERATOR_EMAIL)"
    name="$(ec_answer OPERATOR_NAME)"
    [ -e "$EC_STATE/operator_created" ] && return 0
    # 登録の URL はこの画面にだけ出す（記録のファイルには書かない）。出力は bash の中で受け、URL の行だけを最後に出す
    if out="$(ec_dc exec -T app php artisan operator create "$email" --name="$name" 2>&1)"; then
        ec_state_put operator_created "$(date -Is)"
        while IFS= read -r line; do
            line="${line#"${line%%[![:space:]]*}"}"
            [[ "$line" =~ ^https://[^[:space:]]+$ ]] && EC_ENROLL_URL="$line"
        done <<< "$out"
        [ -n "$EC_ENROLL_URL" ] || printf '%s\n' "$out" | sed 's/^/   | /'
    elif ec_dc exec -T app php artisan operator list 2> /dev/null | ec_q -F " $email "; then
        ec_state_put operator_created "$(date -Is)"
    else
        printf '%s\n' "$out" | sed 's/^/   | /' >&2
        ec_die "最初の管理者を作れませんでした" "sudo ec-sugar operator create $email --name=… で作り直す"
    fi
}

check_origin_reachable() {
    if ec_git ls-remote --exit-code origin HEAD > /dev/null 2>&1; then
        rm -f "$EC_STATE/warning"
    else
        ec_state_put warning "自動の更新の取得元 $EC_ORIGIN に届きません（非公開なら sudo ec-sugar deploy-key）"
        ec_warn "自動の更新の取得元 $EC_ORIGIN に届きません。非公開のリポジトリなら sudo ec-sugar deploy-key で読み取りだけの鍵を作って登録する"
    fi
}

# すでにある Web サーバーに足す設定を出す（Plesk ならその画面の手順、ほかは nginx の例。Apache の例は docs/install.md）
EC_PLESK_PLAN_NOTE=0
print_front_config() {
    local admin shop
    admin="$(ec_answer ADMIN_HOST)"
    shop="$(ec_shop_domain)"
    printf '\n   EC-SUGAR は Unix ソケット %s で待っています（グループ %s だけが接続できる）。すでにある Web サーバーに、次の設定を足してください（docs/install.md の「すでにある Web サーバーの後ろに置く」）:\n\n' "$EC_FRONT_SOCKET" "$(ec_answer PROXY_GROUP)"
    if [ "$EC_PANEL" = Plesk ] || [ -d /usr/local/psa ]; then
        printf '   Plesk の画面で:\n'
        if [ "$(ec_shops)" = single ]; then
            printf '     1. 「ウェブサイトとドメイン」で、%s と %s の 2 つのドメイン（かサブドメイン）を作る（ワイルドカードは要らない）\n' "$admin" "$(ec_shop_host)"
            printf '     2. 2 つそれぞれの「SSL/TLS 証明書」で Let'"'"'s Encrypt の証明書を取り、http を https へ送る\n'
        else
            printf '     1. 「ウェブサイトとドメイン」で %s を足す。ショップは *.%s（ワイルドカードのサブドメイン）か、店ごとの名前（例 %s）を足す\n' "$admin" "$shop" "$(ec_shop_host)"
            printf '     2. それぞれの「SSL/TLS 証明書」で Let'"'"'s Encrypt の証明書を取り、http を https へ送る（ワイルドカードは DNS で確認する方式になる）\n'
        fi
        printf '     3. それぞれの「Apache & nginx の設定」で「プロキシモード」を外し、「追加の nginx ディレクティブ」に次を貼る（4 も必ず確認する）:\n\n'
        EC_PLESK_PLAN_NOTE=1
    else
        printf '   nginx の例（%s と %s の server の中。https の証明書と、http を https へ送る設定はその Web サーバーで）:\n\n' "$admin" "$(ec_shop_site)"
    fi
    cat << EOF
       location ~ ^/ {
           proxy_pass http://unix:$EC_FRONT_SOCKET:;
           proxy_http_version 1.1;
           proxy_set_header Host \$host;
           proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
           proxy_set_header X-Forwarded-Proto \$scheme;
           client_max_body_size 100m;
       }

EOF
    if [ "$EC_PLESK_PLAN_NOTE" -eq 1 ]; then
        # 契約者が自分のドメインに「追加の nginx ディレクティブ」を書けると、前の nginx（ソケットに入れるグループ）から EC-SUGAR のソケットへ、
        # 偽の X-Forwarded-For を付けて転送させられる（管理画面の接続元の制限を偽れる。4 回目の見直し）
        printf '     4. 「サービスプラン」のすべてのプラン（と、プランに縛られない契約）の「権限」で、契約者に Web サーバーの設定\n'
        printf '        （追加の Apache と nginx のディレクティブ）を変える権限を与えていないことを確認する。与えていると、ほかの契約者が\n'
        printf '        自分のドメインから EC-SUGAR のソケットへ偽の接続元を付けて転送でき、管理画面の接続元の制限を偽れる\n\n'
    fi
    # shellcheck disable=SC2016  # nginx の変数の名前をそのまま見せる
    printf '   X-Forwarded-For は「付け足す」（$proxy_add_x_forwarded_for）か「付け直す」（$remote_addr）にする。そのまま渡すと、管理画面の接続元の制限を偽れる\n'
    printf '   X-Forwarded-Proto（https）も渡す。渡さないと EC-SUGAR が https へ送り直し続ける\n'
    printf '   足したら sudo ec-sugar check-front で確認する（管理画面とショップの両方を、偽の X-Forwarded-For を付けて通す）\n'
}

finish() {
    local email admin
    email="$(ec_answer OPERATOR_EMAIL)"
    admin="$(ec_answer ADMIN_HOST)"
    if [ "$(ec_front)" = proxy ]; then
        print_front_config
        local front_rc=0
        ec_check_front || front_rc=$?
        if [ "$front_rc" -eq 1 ]; then
            ec_die "前段のリバースプロキシの設定に直すことがあります（上の行。管理画面の接続元の制限を偽れる・https のヘッダーが無い）" \
                "直してから sudo ec-sugar check-front を実行する（通ったら、登録の URL を sudo ec-sugar operator enroll $email で出す）"
        fi
        create_operator
        check_origin_reachable
        printf '\n'
        if [ "$front_rc" -ne 0 ]; then
            printf '   %s大事: 前段のリバースプロキシの確認がまだ通っていません%s。上の設定を足し、sudo ec-sugar check-front が ✓ になるまで、\n' "$EC_C_ERR" "$EC_C_END"
            printf '   次のアドレスを開かないでください（設定を足す前は、管理画面に届かないか、接続元の制限を偽られる恐れがある）\n\n'
        fi
        if [ -n "$EC_ENROLL_URL" ]; then
            printf '   %s前段のリバースプロキシの確認が通ったら、このアドレスをブラウザで開いてください%s（管理者の本人が。期限つき・1 回だけ）:\n\n' "$EC_C_OK" "$EC_C_END"
            printf '       %s\n\n' "$EC_ENROLL_URL"
        else
            printf '   管理画面: https://%s/\n' "$admin"
        fi
        printf '   期限が切れた・開けなかったときの出し直し方: sudo ec-sugar operator enroll %s\n' "$email"
        return 0
    fi
    check_https
    local tunnel_rc=0
    if [ "$(ec_front)" = tunnel ]; then
        # Cloudflare Tunnel の形（ADR 0052）: 中から接続元を偽れないことと、外（Cloudflare）を回って開けることを確かめる。
        # 外からまだ開けない（DNS の CNAME がまだ・トンネルがまだ）ときは止めずに案内だけ
        ec_check_tunnel || tunnel_rc=$?
        if [ "$tunnel_rc" -eq 1 ]; then
            ec_die "Cloudflare Tunnel の構成で、接続元を偽れるか、本当の接続元が届いていません（上の行。管理画面の接続元の制限が効かない）" \
                "直してから sudo ec-sugar check-front を実行する（通ったら、登録の URL を sudo ec-sugar operator enroll $email で出す）"
        fi
    fi
    create_operator
    check_origin_reachable
    printf '\n'
    if [ "$tunnel_rc" -eq 2 ]; then
        printf '   %s外（Cloudflare）を回ってはまだ開けません%s。3/5 の CNAME の 2 行を Cloudflare の DNS に足し、sudo ec-sugar check-front が ✓ になってから、次のアドレスを開いてください\n\n' "$EC_C_WARN" "$EC_C_END"
    fi
    if [ "$(ec_front)" = tunnel ]; then
        printf '   家の回線や停電で止まったことは、このサーバーの中からは通知できません。外からの監視（https://%s/up を数分ごとに開くもの）を用意してください（docs/install.md）\n\n' "$admin"
    fi
    if [ -n "$EC_ENROLL_URL" ]; then
        printf '   %sこのアドレスをブラウザで開いてください%s（管理者の本人が。期限つき・1 回だけ。パスキーを登録して管理画面に入ります）:\n\n' "$EC_C_OK" "$EC_C_END"
        printf '       %s\n\n' "$EC_ENROLL_URL"
    else
        printf '   管理画面: https://%s/\n' "$admin"
    fi
    printf '   期限が切れた・開けなかったときの出し直し方: sudo ec-sugar operator enroll %s\n' "$email"
    if [ "$(ec_mode)" = staging ]; then
        # パスワードそのものは画面に出さない（端末の履歴やログに残さない）。root だけが読めるファイルの場所を出す
        printf '   届いたメール（Mailpit）: このサーバーの http://127.0.0.1:%s/ を SSH のポート転送で開く。利用者 %s、パスワードは sudo cat %s\n' \
            "$(ec_env_get EC_SUGAR_MAIL_UI_PORT 2> /dev/null || echo 8025)" "$EC_MAILPIT_USER" "$EC_MAILPIT_PASSWORD_FILE"
    fi
}

main() {
    if [ "$CHECK_ONLY" -eq 1 ]; then
        # 確かめだけ（何も変えない）。答えは、入っていれば /srv/ec-sugar の答え（root だけ読める）、無ければ --answers のファイルを読むだけ
        if [ ! -r "$EC_ANSWERS" ] && [ -n "$ANSWERS_IN" ]; then
            [ -r "$ANSWERS_IN" ] || ec_die "設定ファイル $ANSWERS_IN が読めません"
            EC_ANSWERS="$ANSWERS_IN"
        fi
        ec_stage 1 "$EC_STAGES" "環境の確認" "このサーバーで EC-SUGAR を動かせるかを確認します（確認だけ。何も変えません）"
        is_root || ec_say "root でないので、80・443 番を使っているものの名前など、一部は分からないことがあります"
        local rc=0
        check_environment || rc=1
        if [ -n "$(ec_answer ADMIN_HOST 2> /dev/null || true)" ] && [ -n "$(ec_shop_site 2> /dev/null || true)" ] && [ "$(ec_shop_site)" != '*.' ]; then
            ec_stage 3 "$EC_STAGES" "DNS の確認" "設定ファイルの 2 つの名前が、公開の DNS でこのサーバーを指しているかを 1 回だけ見ます"
            check_dns once || rc=1
        fi
        if [ "$rc" -eq 0 ]; then
            printf '\n   すべて ✓ です（何も変えていません）。入れるときは: sudo %s\n' "$EC_INSTALLER"
        else
            printf '\n   ✗ があります（何も変えていません）。直し方に従って直し、もう一度 --check で確認してください\n'
        fi
        exit "$rc"
    fi

    is_root || ec_die "root で実行する必要があります（確認だけなら --check を付ければ root でなくてよい）" "前に sudo を付けて再実行する（例: sudo ${EC_INSTALLER}）"

    ec_stage 1 "$EC_STAGES" "環境の確認" "このサーバーで EC-SUGAR を動かせるかを、全部確認してから一覧で見せます（ここでは何も変えません）"
    check_environment || ec_die "環境の確認で ✗ がありました（何も変えずに止めました）" "✗ の行の直し方に従って直し、再実行する（確認だけなら ./deploy/install.sh --check）"

    ec_stage 2 "$EC_STAGES" "聞く" "管理画面とショップの名前、最初の管理者とショップを決めます（2 回目からは前の設定ファイルを使い、聞きません）"
    ask_all

    ec_stage 3 "$EC_STAGES" "DNS の確認" "2 つの名前（管理画面とショップ）が、公開の DNS でこのサーバーを指しているかを見ます"
    check_dns

    ec_stage 4 "$EC_STAGES" "入れて立てる" "Docker・保存先・パスワードを用意し、EC-SUGAR を作って起動し、最初のショップ（とサンプル）を作ります（初回は 10 分ほど）"
    install_packages
    install_docker
    install_user_and_dirs
    install_trust
    install_firewall
    install_auto_upgrades
    ec_lock
    # 秘密（DNS の鍵・手で用意した証明書）を確かめて置いてから、env に取り方を書く（鍵の形で止まったとき、env だけが新しい取り方にならないように）
    install_caddy_secrets
    install_tunnel_secrets
    install_offsite_secrets
    create_env
    create_src
    stand_up
    create_first_shop
    add_samples
    app_key_note
    # 中の照らし（ADR 0105）: 立てた姿をあるべき姿として控える（鍵と識別子もここで作る。流し直しても、確かめたコミットから作り直した姿で控え直す）
    if ec_integrity_record always service code compose tools units tunnel settings; then
        ec_ok "内部の整合性チェックの基準の状態を記録した（毎分チェックして署名した状態を出す。外部監視に置く公開鍵は sudo ec-sugar integrity key）"
    else
        ec_warn "内部の整合性チェックの基準の状態を記録できませんでした（上の行。直してから sudo ec-sugar integrity record）"
    fi

    ec_stage 5 "$EC_STAGES" "仕上げ" "https で開けるかを確認、管理画面に入るアドレスを出します"
    finish
}

main
