#!/usr/bin/env bash
# 開発の環境の診断（make doctor）。環境の不調とコードの不具合を切り分けるために、手元の環境を 1 行ずつ ok・注意・NG で出し、
# 直し方を 1 行添える。NG が 1 つでもあれば 1 で終わる。
#
#   make doctor                    （= bash ops/dev/doctor.sh）
#   DOCTOR_DISK_WARN_GB=10 DOCTOR_DISK_NG_GB=5   空きの閾値（GB。既定は 10 で注意、5 で NG）
#
# 読むだけで、何も変えない・消さない: ファイルは読むだけ、DB への問い合わせは読むだけの取引（default_transaction_read_only）で流す。
# Docker はコンテナの一覧と状態を読み、db コンテナの中で df と psql を走らせるだけ（起動・停止・作り直しをしない）。
# 直し方の行は案内で、ここでは流さない。
#
# 調べること（docs/development/doctor.md）:
#   ディスク   Mac 本体（この置き場のあるディスク）と Docker のデータ（db コンテナから見た空き）
#   Docker     応えるか（10 秒まで）・開発のコンテナ（app・db・redis・web・queue・scheduler）の状態・開発の画面がどのコードで動くか
#   PHP と依存 PHP の版と拡張（EnvironmentParityTest と同じ決まり）・vendor と composer.lock・vendor がリンクでないか・
#              composer.lock と composer.json・node_modules と package-lock.json・App とテーマの登録（composer の path のパッケージと Laravel の控え）
#   控えとビルド bootstrap/cache の設定・経路・イベントの控え・public/hot・管理画面とショップのビルド（public/build）がソースより古くないか
#   DB         中央の DB に入れるか・手元から 127.0.0.1:54329 に届くか・接続の数・中央と開発の店の移行の流し忘れ・テストの DB とロールの残り
#   .env       無い・鍵が空・テストに響く値（APP_URL・APP_DEBUG）・シェルに入れたままの DB の変数
#
# 書き方: Mac の /bin/bash（3.2）でも Linux（CI の VM）でも動く形（ops/dev/lib.sh の決まり）。
set -o pipefail

cd "$(dirname "$0")/../.." || exit 2
root=$(pwd -P)
# shellcheck source=ops/dev/lib.sh
. ops/dev/lib.sh

warn_gb=${DOCTOR_DISK_WARN_GB:-10}
ng_gb=${DOCTOR_DISK_NG_GB:-5}
num='^[0-9]+$'
if ! [[ $warn_gb =~ $num && $ng_gb =~ $num ]]; then
  echo "DOCTOR_DISK_WARN_GB と DOCTOR_DISK_NG_GB は GB の整数にしてください" >&2
  exit 2
fi

ngs=0
warns=0

ok() {
  printf '  %sok%s    %s\n' "$EC_C_OK" "$EC_C_OFF" "$1"
}
fix_line() {
  [ -n "$1" ] && printf '        %s→ %s%s\n' "$EC_C_DIM" "$1" "$EC_C_OFF"
  return 0
}
warn() {
  printf '  %s注意%s  %s\n' "$EC_C_WARN" "$EC_C_OFF" "$1"
  fix_line "$2"
  warns=$((warns + 1))
}
ng() {
  printf '  %sNG%s    %s\n' "$EC_C_NG" "$EC_C_OFF" "$1"
  fix_line "$2"
  ngs=$((ngs + 1))
}
section() {
  printf '\n%s\n' "$1"
}
# joined <これまで> <足すもの> <区切り>: 区切りを挟んでつなぐ（bash 3.2 は ${x:+…} の中の全角の文字を崩すので、if で書く）
joined() {
  if [ -n "$1" ]; then printf '%s%s%s' "$1" "$3" "$2"; else printf '%s' "$2"; fi
}

# 空きの行。$1 = 何の空きか、$2 = 空き（KB）、$3 = 足りないときの直し方
disk_line() {
  local what=$1 kb=$2 hint=$3 gb
  gb=$(awk -v k="$kb" 'BEGIN { printf "%.1f", k / 1048576 }')
  if [ "$kb" -lt $((ng_gb * 1048576)) ]; then
    ng "${what}の空きが ${gb} GB（${ng_gb} GB を切ると Postgres や Docker が止まる）" "$hint"
  elif [ "$kb" -lt $((warn_gb * 1048576)) ]; then
    warn "${what}の空きが ${gb} GB（${warn_gb} GB を切った）" "$hint"
  else
    ok "${what}の空きが ${gb} GB"
  fi
}

# DevDoctor（tests/Support/DevDoctor.php）の結果を 1 行ずつ出す。$1 = 調べる項目、$2 = 置き場の根、$3 = 見つけたことの頭（任意）。
# 根がこの置き場でなければ、直し方の行に「どこで流すか」を付ける
php_check() {
  local level message fix out head=$3 where=
  out=$(php -d display_errors=stderr -r 'require $argv[1]; exit(Tests\Support\DevDoctor::main([$argv[2], $argv[3]]));' -- \
    "$root/tests/Support/DevDoctor.php" "$1" "$2" 2>&1) || {
    ng "診断の PHP（$1）が落ちました: $(printf '%s' "$out" | head -1)" "php -v と tests/Support/DevDoctor.php を確認する"
    return
  }
  [ "$2" != "$root" ] && where="cd $2 && "
  while IFS=$'\t' read -r level message fix; do
    [ -n "$fix" ] && fix="$where$fix"
    case $level in
      ok) ok "$head$message" ;;
      warn) warn "$head$message" "$fix" ;;
      ng) ng "$head$message" "$fix" ;;
    esac
  done <<EOF
$out
EOF
}

printf 'EC-SUGAR の開発の環境の診断（読むだけ。何も変えません）\n  場所: %s\n' "$root"

# ---------------------------------------------------------------- ディスク
section "[ディスク]"
host=$(df -Pk "$root" 2>/dev/null | awk 'NR == 2 { print $4 " " $6 }')
if [ -n "$host" ]; then
  disk_line "このディレクトリのディスク（${host#* }）" "${host%% *}" "大きなものを消す（Docker のデータ領域は docker system df で見る。build cache は docker builder prune）"
else
  warn "このディレクトリのディスクの空きが読めません" "df -h で確認する"
fi

# ---------------------------------------------------------------- Docker
section "[Docker]"
docker_ok=0
db=
app=
devroot=
if ! command -v docker >/dev/null 2>&1; then
  ng "docker のコマンドがありません" "Mac は OrbStack を入れて起動する（Linux は Docker Engine）"
else
  started=$(date +%s)
  version=$(ec_run_to 10 docker info --format '{{.ServerVersion}}' 2>/dev/null)
  rc=$?
  took=$(($(date +%s) - started))
  if [ "$rc" -eq 124 ]; then
    ng "Docker が 10 秒応えません（OrbStack が固まっている・ディスクが満杯・更新中）" "OrbStack の状態を見る。ほかの作業のテストが流れていれば終わるのを待つ（再起動はほかの作業も止める）"
  elif [ "$rc" -ne 0 ] || [ -z "$version" ]; then
    ng "Docker に繋がりません" "OrbStack を起動する（Linux は systemctl status docker）"
  else
    docker_ok=1
    if [ "$took" -ge 4 ]; then
      warn "Docker の応えが遅い（${took} 秒。バージョン ${version}）" "ほかの作業のテストやビルドが重なっていないか見る（同時に動かす作業は 5 つまで）"
    else
      ok "Docker が応える（バージョン ${version}）"
    fi
  fi
fi

if [ "$docker_ok" -eq 1 ]; then
  states=$(ec_run_to 10 docker ps -a --filter label=com.docker.compose.project=ec-sugar \
    --format '{{.Label "com.docker.compose.service"}}|{{.State}}|{{.Status}}|{{.Names}}' 2>/dev/null)
  running=
  for service in app db redis web queue scheduler; do
    line=$(printf '%s\n' "$states" | awk -F'|' -v s="$service" '$1 == s { print; exit }')
    if [ -z "$line" ]; then
      ng "${service} のコンテナがありません" "make up（初めてなら make setup）"
      continue
    fi
    state=$(printf '%s' "$line" | cut -d'|' -f2)
    status=$(printf '%s' "$line" | cut -d'|' -f3)
    name=$(printf '%s' "$line" | cut -d'|' -f4)
    case "$state|$status" in
      running\|*unhealthy*) ng "${service} が不調（${status}）" "docker logs --tail 50 ${name} で理由を見る。直したら make up" ;;
      running\|*starting*) warn "${service} が起動中（${status}）" "数十秒待ってからもう一度 make doctor" ;;
      running\|*) running=$(joined "$running" "$service" ・) ;;
      *) ng "${service} が止まっています（${status}）" "make up（落ちた理由は docker logs --tail 50 ${name}）" ;;
    esac
  done
  [ -n "$running" ] && ok "開発のコンテナが動いている（${running}）"

  old=$(ec_run_to 10 docker ps -q --filter label=com.docker.compose.project=sugar-ec 2>/dev/null | wc -l | tr -d ' ')
  if [ "${old:-0}" -gt 0 ]; then
    warn "前の名前（sugar-ec）のコンテナが ${old} つ動いています（ポートと DB を取り合う）" "make up（前の名前のものを先に片付ける。DB と写真の保存先は残る）"
  fi

  db=$(ec_container db)
  app=$(ec_container app)
  if [ -n "$app" ]; then
    src=$(ec_run_to 10 docker inspect "$app" --format '{{range .Mounts}}{{if eq .Destination "/var/www/html"}}{{.Source}}{{end}}{{end}}' 2>/dev/null)
    if [ -n "$src" ] && [ -d "$src" ]; then
      devroot=$(cd "$src" && pwd -P)
      if [ "$devroot" != "$root" ]; then
        ok "開発の画面（:8080）は ${devroot} のコードで動いている（ここは別の作業ツリー。マイグレーションの比較はそちらのファイルで）"
      fi
    fi
  fi

  if [ -n "$db" ]; then
    data=$(ec_run_to 10 docker exec "$db" df -Pk /var/lib/postgresql/data 2>/dev/null | awk 'NR == 2 { print $4 }')
    if [[ $data =~ $num ]]; then
      disk_line "Docker のデータ" "$data" "docker system df で見て、使っていない build cache やイメージを消す（docker builder prune・docker image prune）"
    else
      warn "Docker のデータの空きが読めません" "docker system df で確認する"
    fi
  fi
fi

# 開発の画面の置き場（本体）か、worktree などの別の作業場所か。Docker から分からなければ .git がファイルか（worktree）で見る
if [ -n "$devroot" ]; then
  is_main=0
  [ "$devroot" = "$root" ] && is_main=1
elif [ -f "$root/.git" ]; then
  is_main=0
else
  is_main=1
fi

# ---------------------------------------------------------------- PHP と依存
section "[PHP と依存]"
if ! command -v php >/dev/null 2>&1; then
  ng "php のコマンドがありません" "brew install php ＋ pecl install redis（CLAUDE.md の「開発コマンド」）"
else
  php_id=$(php -r 'echo PHP_VERSION_ID;' 2>/dev/null)
  if ! [[ $php_id =~ $num ]] || [ "$php_id" -lt 80300 ]; then
    ng "PHP が古いか動きません（$(php -r 'echo PHP_VERSION;' 2>/dev/null)）" "brew upgrade php"
  else
    php_check php "$root"
    php_check composer "$root"
    if command -v composer >/dev/null 2>&1; then
      if ! ec_run_to 60 composer validate --no-check-publish --no-check-all --no-interaction -d "$root" >/dev/null 2>&1; then
        ng "composer.lock が composer.json と合いません（composer validate が通らない）" "composer validate で中身を見る。composer.json を変えたのが自分なら composer update --lock"
      fi
    fi
    php_check plugins "$root"
    php_check npm "$root"
    # worktree から診断したときは、開発の画面（:8080）が使うコードの vendor と App も見る（直し方はそちらの置き場で流す）
    if [ -n "$devroot" ] && [ "$devroot" != "$root" ]; then
      php_check composer "$devroot" "開発の画面のコード（${devroot}）: "
      php_check plugins "$devroot" "開発の画面のコード: "
    fi
  fi
fi

# ---------------------------------------------------------------- 控えとビルド
section "[キャッシュとビルド]"
cache=$root/bootstrap/cache
caches=0
if [ -f "$cache/config.php" ]; then
  ng "設定のキャッシュ（bootstrap/cache/config.php）があります。.env を変えても効かず、PHP のテストは始める前に止まる" "php artisan config:clear（開発の画面は docker compose exec app php artisan config:clear）"
  caches=1
fi
for f in "$cache"/routes*.php; do
  [ -f "$f" ] || continue
  warn "ルートのキャッシュ（bootstrap/cache/$(basename "$f")）があります。足したルートが出ない" "php artisan route:clear（開発の画面は docker compose exec app php artisan route:clear）"
  caches=1
done
if [ -f "$cache/events.php" ]; then
  warn "イベントのキャッシュ（bootstrap/cache/events.php）があります。足したリスナーが動かない" "php artisan event:clear"
  caches=1
fi
if [ -f "$cache/packages.php" ] && [ -f "$root/vendor/composer/installed.json" ] && [ "$root/vendor/composer/installed.json" -nt "$cache/packages.php" ]; then
  warn "Laravel のパッケージのキャッシュ（bootstrap/cache/packages.php）が vendor より古い" "php artisan package:discover"
  caches=1
fi
[ "$caches" -eq 0 ] && ok "設定・ルート・イベントのキャッシュは無い（毎回読み直す）"

if [ -f "$root/public/hot" ]; then
  warn "public/hot があります（Vite の開発サーバーの印。止まったサーバーから読もうとして画面が欠ける）" "rm public/hot（開発サーバーは使わない。変わるたびに作り直すのは make watch）"
fi

manifest=$root/public/build/manifest.json
if [ ! -f "$manifest" ]; then
  if [ "$is_main" -eq 1 ]; then
    ng "管理画面とショップの画面のビルド（public/build）がありません" "make front"
  else
    warn "この作業ツリーに画面のビルド（public/build）がありません（PHP のテストには要らない。画面を見るなら作る）" "npm ci --ignore-scripts && npm run build"
  fi
else
  # ソースの方が新しいもの。git の取り込み・切り替えで変わったファイルは、その時の時刻になる
  newer=$(cd "$root" && find resources/admin resources/storefront plugins/theme-*/resources/css vite.config.js package-lock.json \
    -type f -newer "$manifest" 2>/dev/null | head -3 | tr '\n' ' ')
  views=$(cd "$root" && find plugins/theme-*/resources/views -type f -newer "$manifest" 2>/dev/null | head -1)
  if [ -n "$newer" ]; then
    ng "画面のビルド（public/build）がソースより古い（例 ${newer% }）。画面が欠けたり古いまま出る" "make front（作っている間は make watch）"
  elif [ -n "$views" ]; then
    warn "テーマの Blade がビルドより新しい（例 ${views}）。Tailwind のクラスを足したならビルドに入っていない" "make front"
  else
    ok "画面のビルド（public/build）はソースより新しい"
  fi
fi

# ---------------------------------------------------------------- DB
section "[DB]"
if [ "$docker_ok" -ne 1 ]; then
  ng "Docker が応えないので DB を調べられません" "上の [Docker] を先に直す"
elif [ -z "$db" ]; then
  ng "開発の db コンテナが動いていないので DB を調べられません" "make up"
elif ! ec_psql_ro "$db" ec_central 'SELECT 1' >/dev/null 2>&1; then
  ng "中央の DB（ec_central）に入れません" "docker logs --tail 50 ${db} で理由を見る（ディスクが満杯だと止まる）"
else
  ok "中央の DB（ec_central）に入れる"

  if ec_port_open 127.0.0.1 "$EC_DB_HOST_PORT"; then
    ok "手元から 127.0.0.1:${EC_DB_HOST_PORT}（PHP のテストの入り口）に届く"
  else
    ng "手元から 127.0.0.1:${EC_DB_HOST_PORT} に届きません（PHP のテストが DB に入れない）" "make up（ポートは docker-compose.yml の db）"
  fi
  if ! ec_port_open 127.0.0.1 "$EC_REDIS_HOST_PORT"; then
    warn "手元から Redis（127.0.0.1:${EC_REDIS_HOST_PORT}）に届きません" "make up"
  fi

  conns=$(ec_psql_ro "$db" ec_central "SELECT count(*) || ' ' || current_setting('max_connections') FROM pg_stat_activity" 2>/dev/null)
  used=${conns%% *}
  max=${conns##* }
  if [[ $used =~ $num && $max =~ $num ]] && [ "$max" -gt 0 ] && [ $((used * 100 / max)) -ge 80 ]; then
    warn "DB の接続が ${used}/${max}（並列で実行しているテストが多い。超えると Tenant cannot be created などで落ちる）" "実行中のテストが終わるのを待つ。make test は PROCESSES=4 で減らせる"
  fi

  # 移行の流し忘れ。開発の画面が使うコード（devroot）の移行のファイルと比べる
  mroot=${devroot:-$root}
  mfiles=$(php -r 'require $argv[1]; exit(Tests\Support\DevDoctor::main([$argv[2], $argv[3]]));' -- "$root/tests/Support/DevDoctor.php" migrations-central "$mroot" 2>/dev/null | LC_ALL=C sort)
  done_central=$(ec_psql_ro "$db" ec_central 'SELECT migration FROM migrations' 2>/dev/null)
  if [ $? -ne 0 ]; then
    ng "中央の DB にマイグレーションの表がありません" "make setup（入れ直すなら）か make migrate"
  else
    pending=$(LC_ALL=C comm -23 <(printf '%s\n' "$mfiles") <(printf '%s\n' "$done_central" | LC_ALL=C sort) | grep -v '^$')
    if [ -n "$pending" ]; then
      ng "中央の DB に実行していないマイグレーションが $(printf '%s\n' "$pending" | wc -l | tr -d ' ') 本（例 $(printf '%s\n' "$pending" | head -1)）。画面が 500 になる" "make migrate"
    else
      ok "中央の DB のマイグレーションは実行済み（$(printf '%s\n' "$mfiles" | grep -c .) 本）"
    fi

    # 開発の店の DB。名前は tenancy の決め方（data の tenancy_db_name か、頭 + 店の ID）
    prefix=ec_t_
    if [ -f "$mroot/.env" ]; then
      p=$(grep -E '^TENANT_DB_PREFIX=' "$mroot/.env" | tail -1 | cut -d= -f2- | tr -d "\"' ")
      [ -n "$p" ] && prefix=$p
    fi
    if ! [[ $prefix =~ ^[a-z0-9_]+$ ]]; then
      warn "開発の店の DB の頭（TENANT_DB_PREFIX）が読めない形です" ".env を確認する"
    else
      tfiles=$(php -r 'require $argv[1]; exit(Tests\Support\DevDoctor::main([$argv[2], $argv[3]]));' -- "$root/tests/Support/DevDoctor.php" migrations-tenant "$mroot" 2>/dev/null | LC_ALL=C sort)
      shops=$(ec_psql_ro "$db" ec_central "SELECT coalesce(data->>'tenancy_db_name', '${prefix}' || id) FROM tenants ORDER BY 1 LIMIT 50" 2>/dev/null)
      existing=$(ec_psql_ro "$db" ec_central "SELECT datname FROM pg_database WHERE datname ~ '^${prefix}'" 2>/dev/null)
      total=0
      behind=
      missing=
      for shop in $shops; do
        total=$((total + 1))
        if ! printf '%s\n' "$existing" | grep -qx "$shop"; then
          missing=$(joined "$missing" "$shop" 、)
          continue
        fi
        done_shop=$(ec_psql_ro "$db" "$shop" 'SELECT migration FROM migrations' 2>/dev/null)
        count=$(LC_ALL=C comm -23 <(printf '%s\n' "$tfiles") <(printf '%s\n' "$done_shop" | LC_ALL=C sort) | grep -c .)
        [ "$count" -gt 0 ] && behind=$(joined "$behind" "${shop}: ${count} 本" 、)
      done
      if [ -n "$behind" ]; then
        ng "開発の店の DB に実行していないマイグレーションがあります（${behind}）。その店の画面が 500 になる" "make migrate（店の DB は tenants:migrate）"
      elif [ "$total" -gt 0 ]; then
        ok "開発の店 ${total} つの DB のマイグレーションは実行済み"
      else
        ok "開発の店はまだありません（make tenant id=<名前> で作る）"
      fi
      [ -n "$missing" ] && warn "店はあるのに DB がありません（${missing}）" "make fresh で作り直すか、その店を消す"
    fi
  fi

  # テストの DB とロールの残り。作業の印ごとにまとめる（印は Makefile の test-clean と同じ決め方）
  rows=$(ec_psql_ro "$db" ec_central "SELECT 'd|' || datname || '|' || (SELECT count(*) FROM pg_stat_activity a WHERE a.datname = d.datname) || '|' || pg_database_size(datname) FROM pg_database d WHERE datname ~ '^ec_(test|e2e)' UNION ALL SELECT 'r|' || rolname || '|0|0' FROM pg_roles WHERE rolname ~ '^ec_(test|e2e)'" 2>/dev/null)
  works=()
  w_shops=()
  w_roles=()
  w_busy=()
  central=0
  templates=0
  bytes=0
  shop_re='^ec_(test|e2e)_(([a-z0-9]+)_)?t_(u_)?(.*)$'
  central_re='^ec_(test|e2e)(_[a-z0-9]+)?$'
  strip_re='^(.*[a-z0-9])p[0-9]+$'
  default_p_re='^p[0-9]+$'
  while IFS='|' read -r kind name busy size; do
    [ -n "$name" ] || continue
    [[ $size =~ $num ]] && bytes=$((bytes + size / 1048576))
    if [[ $name =~ $central_re ]]; then
      [ "$kind" = d ] && central=$((central + 1))
      continue
    fi
    [[ $name =~ $shop_re ]] || continue
    work=${BASH_REMATCH[3]}
    rest=${BASH_REMATCH[5]}
    if [ "$rest" = _template ]; then
      [ "$kind" = d ] && templates=$((templates + 1))
      continue
    fi
    # 並べて流した各々（ec_test_p3_t_・ec_test_drp3_t_）は元の印に
    if [[ $work =~ $default_p_re ]]; then
      work=
    elif [[ $work =~ $strip_re ]]; then
      work=${BASH_REMATCH[1]}
    fi
    i=0
    while [ "$i" -lt "${#works[@]}" ] && [ "${works[$i]}" != "$work" ]; do i=$((i + 1)); done
    if [ "$i" -eq "${#works[@]}" ]; then
      works[$i]=$work
      w_shops[$i]=0
      w_roles[$i]=0
      w_busy[$i]=0
    fi
    if [ "$kind" = d ]; then
      w_shops[$i]=$((w_shops[i] + 1))
      [ "${busy:-0}" -gt 0 ] && w_busy[$i]=1
    else
      w_roles[$i]=$((w_roles[i] + 1))
    fi
  done <<EOF
$rows
EOF
  i=0
  while [ "$i" -lt "${#works[@]}" ]; do
    work=${works[$i]}
    label=${work:-既定（ec_test・ec_e2e）}
    note=
    [ "${w_busy[$i]}" -eq 1 ] && note="。いま接続があるので、実行している最中かもしれない"
    command="make test-clean${work:+ TEST_WORK=$work}"
    warn "テストの残り（作業 ${label}）: 店の DB ${w_shops[$i]}・ロール ${w_roles[$i]}。同じ店をまた作ると Tenant cannot be created で落ちる${note}" \
      "${command}（その作業のテストが実行中でないことを確認してから）"
    i=$((i + 1))
  done
  if [ "${#works[@]}" -eq 0 ]; then
    ok "テストの店の DB とロールの残りは無い（中央のテスト DB ${central}・型 ${templates}、合わせて ${bytes} MB）"
  fi
  if [ "$bytes" -ge 5120 ]; then
    warn "テストの DB が合わせて ${bytes} MB あります" "使い終わった作業の中央の DB を消す（docker exec ${db} psql -U ec -d ec_central -c 'DROP DATABASE ec_test_<印>'。make test-clean TEST_WORK=<印> は店の DB と型だけ）"
  fi
fi

# ---------------------------------------------------------------- .env
section "[.env]"
envfile=$root/.env
if [ ! -f "$envfile" ]; then
  if [ "$is_main" -eq 0 ]; then
    ok ".env は無い（worktree では作らない。テストは make test-work が使い捨ての鍵を渡す）"
  else
    ng ".env がありません" "make setup（.env.example から作り、鍵を作る）"
  fi
else
  # 値を出すのは秘密でないもの（APP_ENV・APP_DEBUG・APP_URL）だけ
  env_value() {
    grep -E "^$1=" "$envfile" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'\$//"
  }
  app_key=$(env_value APP_KEY)
  app_env=$(env_value APP_ENV)
  app_debug=$(env_value APP_DEBUG)
  app_url=$(env_value APP_URL)
  db_url=$(env_value DB_URL)
  [ -z "$app_key" ] && ng ".env の APP_KEY が空です（暗号の鍵。店を作れない）" "php artisan key:generate"
  [ "$app_env" = production ] && warn ".env の APP_ENV が production です（開発の環境）" "APP_ENV=local に戻す"
  [ -n "$db_url" ] && warn ".env に DB_URL が入っています（DB の名前を上書きし、テストは始める前に止まる）" "DB_URL を消して DB_HOST・DB_DATABASE で書く"
  if [ "$app_url" != "http://localhost" ] || [ "$app_debug" = true ]; then
    warn "素の php artisan test に .env の APP_URL（${app_url:-空}）と APP_DEBUG（${app_debug:-空}）が入り、CI（http://localhost・false）と結果が変わる" \
      "テストは make test-work TEST_WORK=<印> FILES=…（APP_URL=http://localhost APP_DEBUG=false を付ける）"
  else
    ok ".env の APP_URL と APP_DEBUG は CI と同じ"
  fi
  [ -n "$app_key" ] && [ "$app_env" != production ] && [ -z "$db_url" ] && ok ".env の鍵・APP_ENV（${app_env:-空}）・DB_URL は開発の形"
fi
shell_vars=
for v in APP_ENV DB_DATABASE TENANT_DB_PREFIX MEDIA_DIRECTORY DB_URL APP_CONFIG_CACHE; do
  if [ -n "$(printenv "$v")" ]; then
    shell_vars=$(joined "$shell_vars" "$v" ・)
  fi
done
if [ -n "$shell_vars" ]; then
  warn "シェルに ${shell_vars} が入っています（artisan とテストが .env より先に読む）" "unset ${shell_vars//・/ }（作業ごとのテストは make test-work が渡す）"
fi

# ---------------------------------------------------------------- まとめ
printf '\n'
if [ "$ngs" -gt 0 ]; then
  printf '%sNG %d・注意 %d%s。NG から直してください（直し方は → の行）\n' "$EC_C_NG" "$ngs" "$warns" "$EC_C_OFF"
  exit 1
fi
printf '%sNG 0・注意 %d%s\n' "$EC_C_OK" "$warns" "$EC_C_OFF"
exit 0
