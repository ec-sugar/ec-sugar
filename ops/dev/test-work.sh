#!/usr/bin/env bash
# 作業ごとの PHP のテストの環境。作業を並べて進めるときに、ほかの作業とぶつからない自分の DB と写真の置き場でテストを流すためのもの。
#
#   make test-env  TEST_WORK=<印>
#       中央のテスト DB（ec_test_<印>）が無ければ作り、写真の置き場（storage/app/media-testing-<印>）を作って、使う環境変数の行を出す。
#       eval "$(make -s test-env TEST_WORK=<印>)" でそのシェルに入る（その後は php artisan test --compact <ファイル>）
#   make test-work TEST_WORK=<印> FILES="<ファイルかフォルダ> …" [FILTER=<テストの名前>] [ARGS="<phpunit の引数>"] [STOP=1]
#       上の環境で 1 ファイルずつ流す。流す前に、前のファイルが落ちて残した店の DB とロールを片付ける（同じ店の ID をまた作ると
#       「Tenant cannot be created」で落ちるため）。フォルダは中の *Test.php を名前の順に。STOP=1 なら最初に落ちたところで止める
#   make test-clean TEST_WORK=<印>（対。Makefile）
#       店の DB・ロールに加えて型（<頭>_template）も消す。中央の DB ec_test_<印> は残る
#
# 渡す環境変数は、引き継ぎの文に毎回書いていた形と同じ: DB_DATABASE=ec_test_<印> TENANT_DB_PREFIX=ec_test_<印>_t_
# MEDIA_DIRECTORY=media-testing-<印>（組が合わないとテストの入口の守り tests/Support/TestEnvironmentGuard が止める）、
# APP_ENV=testing、APP_URL=http://localhost・APP_DEBUG=false（CI と同じ。手元の .env の http://localhost:8080・true が入ると結果が変わる）、
# APP_KEY は回ごとに作る使い捨ての鍵（worktree には .env が無く、店を作るテストが鍵の無さで落ちる。鍵は表示しない）。
#
# 消すのは、この印の店の DB とロール（ec_test_<印>_t_*・並べた各々の ec_test_<印>p<番号>_t_*）だけで、型・中央の DB・開発の DB・
# ほかの印のものには触らない。名前は ec_valid_work（英小文字と数字、t と p<数字> で終わるものは除く）を通ったものだけを SQL に入れる。
#
# 書き方: Mac の /bin/bash（3.2）でも Linux でも動く形（ops/dev/lib.sh の決まり）。
set -o pipefail

cd "$(dirname "$0")/../.." || exit 2
# shellcheck source=ops/dev/lib.sh
. ops/dev/lib.sh

mode=$1
work=${TEST_WORK:-}

if ! ec_valid_work "$work"; then
  {
    echo "TEST_WORK に作業の印を付けてください（英小文字と数字の 1 区切り。t と、p<数字> で終わるものは除く）"
    echo "  例: make test-work TEST_WORK=dr FILES=\"tests/Feature/Admin/ProductsTest.php\""
  } >&2
  exit 2
fi

base=ec_test_$work
prefix=${base}_t_
media=media-testing-$work

if ! command -v docker >/dev/null 2>&1 || ! db=$(ec_container db); then
  echo "開発の db コンテナが動いていません（make up。詳しくは make doctor）" >&2
  exit 1
fi

if ! ec_port_open 127.0.0.1 "$EC_DB_HOST_PORT"; then
  echo "手元から 127.0.0.1:${EC_DB_HOST_PORT} の DB に届きません（make doctor で詳しく）" >&2
  exit 1
fi

# 中央のテスト DB と写真の置き場を用意する（あれば何もしない）。型はテストの最初の店が作る（ADR 0057）
prepare() {
  local exists
  exists=$(ec_psql "$db" ec_central "SELECT 1 FROM pg_database WHERE datname = '${base}'") || return 1
  if [ "$exists" != 1 ]; then
    ec_psql "$db" ec_central "CREATE DATABASE ${base} OWNER ec_app" >/dev/null || return 1
    echo "  中央のテスト DB ${base} を作りました" >&2
  fi
  mkdir -p "storage/app/${media}" || return 1
  if [ "$(ec_psql "$db" ec_central "SELECT 1 FROM pg_database WHERE datname = '${prefix}_template'")" = 1 ]; then
    echo "  店の DB の型 ${prefix}_template があります（マイグレーションが変わっていれば最初の店で作り直す）" >&2
  else
    echo "  店の DB の型はまだありません（最初の店を作るテストが作る。そのテストだけ数十秒長くなる）" >&2
  fi
}

# この印の、型でない店の DB とロールを消す（前のファイルが落ちて残したもの）。消した数を出す
cleanup() {
  local drops count
  drops=$(ec_psql "$db" ec_central "SELECT format('DROP DATABASE %I WITH (FORCE);', datname) FROM pg_database
    WHERE datname ~ '^${base}(p[0-9]+)?_t_' AND datname !~ '_t__template\$' ORDER BY 1") || return 1
  drops="$drops
$(ec_psql "$db" ec_central "SELECT format('DROP ROLE %I;', rolname) FROM pg_roles
    WHERE rolname ~ '^${base}(p[0-9]+)?_t_u_' AND rolname !~ '_t_u__template\$' ORDER BY 1")" || return 1
  count=$(printf '%s\n' "$drops" | grep -c .)
  [ "$count" -eq 0 ] && return 0
  printf '%s\n' "$drops" | grep . | docker exec -i "$db" psql -U ec -d ec_central -v ON_ERROR_STOP=1 -q >/dev/null || return 1
  echo "  残っていた店の DB とロールを ${count} つ片付けました（${base}_t_*）" >&2
}

case $mode in
  env)
    prepare || { echo "テストの DB を用意できませんでした" >&2; exit 1; }
    # 鍵はここで作らず、eval したときに作る行を出す（画面に鍵を出さない）
    printf 'export DB_DATABASE=%s TENANT_DB_PREFIX=%s MEDIA_DIRECTORY=%s APP_ENV=testing APP_URL=http://localhost APP_DEBUG=false\n' "$base" "$prefix" "$media"
    # shellcheck disable=SC2016
    printf '%s\n' 'export APP_KEY="base64:$(php -r '\''echo base64_encode(random_bytes(32));'\'')"'
    echo "  使い方: eval \"\$(make -s test-env TEST_WORK=${work})\" の後に php artisan test --compact <ファイル>。まとめて実行するなら make test-work" >&2
    ;;

  run)
    files=()
    missing=0
    for arg in ${FILES:-}; do
      if [ -d "$arg" ]; then
        while IFS= read -r f; do
          files+=("$f")
        done < <(find "$arg" -type f -name '*Test.php' | LC_ALL=C sort)
      elif [ -f "$arg" ]; then
        files+=("$arg")
      else
        echo "テストのファイルがありません: $arg" >&2
        missing=$((missing + 1))
      fi
    done
    if [ "${#files[@]}" -eq 0 ]; then
      echo "実行するファイルがありません。FILES=\"tests/Feature/….php tests/Unit/…\" で渡してください" >&2
      exit 2
    fi
    if [ -L vendor ] || [ ! -f vendor/autoload.php ]; then
      echo "vendor が無いか、ほかの場所へのリンクです（composer install --no-interaction。詳しくは make doctor）" >&2
      exit 1
    fi

    prepare || { echo "テストの DB を用意できませんでした" >&2; exit 1; }
    key="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
    filter=()
    [ -n "${FILTER:-}" ] && filter=("--filter=${FILTER}")

    passed=0
    failed=()
    for file in "${files[@]}"; do
      cleanup || { echo "残りの店の DB を片付けられませんでした" >&2; exit 1; }
      printf '\n== %s\n' "$file"
      started=$(date +%s)
      # shellcheck disable=SC2086 # ARGS は phpunit の引数をそのまま並べる（空白で分ける）
      env -u DB_URL -u TEST_TOKEN -u EC_TEST_PARALLEL_DATABASE -u APP_CONFIG_CACHE \
        DB_DATABASE="$base" TENANT_DB_PREFIX="$prefix" MEDIA_DIRECTORY="$media" \
        APP_ENV=testing APP_URL=http://localhost APP_DEBUG=false APP_KEY="$key" \
        php artisan test --compact "${filter[@]}" ${ARGS:-} "$file"
      rc=$?
      took=$(($(date +%s) - started))
      if [ "$rc" -eq 0 ]; then
        passed=$((passed + 1))
        printf '%s   ok（%d 秒）%s\n' "$EC_C_OK" "$took" "$EC_C_OFF"
      else
        failed+=("$file")
        printf '%s   NG（終わりの値 %d、%d 秒）%s\n' "$EC_C_NG" "$rc" "$took" "$EC_C_OFF"
        [ -n "${STOP:-}" ] && break
      fi
    done
    cleanup || true

    printf '\n通った %d・落ちた %d' "$passed" "${#failed[@]}"
    [ "$missing" -gt 0 ] && printf '・見つからない %d' "$missing"
    printf '（DB %s。中央の DB と型は次の回のために残す。使い終わったら make test-clean TEST_WORK=%s）\n' "$base" "$work"
    for file in "${failed[@]}"; do
      printf '  落ちた: %s\n' "$file"
    done
    [ "${#failed[@]}" -eq 0 ] && [ "$missing" -eq 0 ] || exit 1
    ;;

  *)
    echo "使い方: ops/dev/test-work.sh env|run（make test-env・make test-work から）" >&2
    exit 2
    ;;
esac
