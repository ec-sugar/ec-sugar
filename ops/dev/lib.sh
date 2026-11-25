# 開発の環境の診断（doctor.sh）と作業ごとのテストの環境（test-work.sh）が共に使う部品。source で読む。
#
# 書き方の決まり: Mac の /bin/bash（3.2）でも Linux の bash でも動くこと。連想配列・mapfile・${x,,}・timeout コマンド（Mac に無い）を使わない。
# set -u は使わない（bash 3.2 は空の配列の "${a[@]}" で落ちる）。${x:+…} の中に全角の文字を書かない（bash 3.2 が崩す）。

# 開発の compose の名前（docker-compose.yml の name）と より前の名前
EC_PROJECTS="ec-sugar sugar-ec"

# 開発の Postgres（docker-compose.yml の db）。テストは手元から 127.0.0.1:54329 で入る（phpunit.xml）
EC_DB_HOST_PORT=54329
EC_REDIS_HOST_PORT=63799

if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
  EC_C_OK=$'\033[32m'; EC_C_WARN=$'\033[33m'; EC_C_NG=$'\033[31m'; EC_C_DIM=$'\033[2m'; EC_C_OFF=$'\033[0m'
else
  EC_C_OK=; EC_C_WARN=; EC_C_NG=; EC_C_DIM=; EC_C_OFF=
fi

# ec_run_to <秒> <コマンド…>: 決めた秒で止める（Mac に timeout が無いため）。止めたら 124 を返す。出力はそのまま
ec_run_to() {
  local limit=$1 pid i=0
  shift
  "$@" &
  pid=$!
  while kill -0 "$pid" 2>/dev/null; do
    if [ "$i" -ge $((limit * 10)) ]; then
      # 止めるのはこの処理とその子だけ（子が出力を握ったままだと、$( ) が終わらない。名前でまとめて止めない）
      pkill -P "$pid" 2>/dev/null
      kill "$pid" 2>/dev/null
      wait "$pid" 2>/dev/null
      return 124
    fi
    sleep 0.1
    i=$((i + 1))
  done
  wait "$pid"
}

# ec_container <サービス>: 開発の compose のそのサービスの動いているコンテナの名前（無ければ空）。今の名前を先に探す
ec_container() {
  local project name
  for project in $EC_PROJECTS; do
    name=$(ec_run_to 10 docker ps --filter "label=com.docker.compose.project=$project" \
      --filter "label=com.docker.compose.service=$1" --format '{{.Names}}' 2>/dev/null | head -1)
    if [ -n "$name" ]; then
      echo "$name"
      return 0
    fi
  done
  return 1
}

# ec_psql <コンテナ> <DB> <SQL>: 管理者（ec）で 1 つの文を流し、値だけを出す（-At）。読むだけの所は ec_psql_ro を使う
ec_psql() {
  ec_run_to 20 docker exec "$1" psql -U ec -d "$2" -v ON_ERROR_STOP=1 -Atqc "$3"
}

# ec_psql_ro: 読むだけ（書く文を DB が断る。診断が値を変えないことを DB の側でも守る）
ec_psql_ro() {
  ec_run_to 20 docker exec -e PGOPTIONS='-c default_transaction_read_only=on' "$1" psql -U ec -d "$2" -v ON_ERROR_STOP=1 -Atqc "$3"
}

# ec_valid_work <印>: 作業の印の形（Makefile の test-clean・tests/Support/TestEnvironmentGuard と同じ英小文字と数字の 1 区切り、t は除く）。
# さらに p<数字> で終わる印も断る（ec_test_p3 は既定の make test の並べた 3 番目、ec_test_ap1 は印 a の並べた 1 番目の DB と同じ名前になる）
ec_valid_work() {
  local work=$1 re='^[a-z0-9]+$' tail='p[0-9]+$'
  [ -n "$work" ] || return 1
  [ "$work" != t ] || return 1
  [[ $work =~ $re ]] || return 1
  [[ $work =~ $tail ]] && return 1
  return 0
}

# ec_port_open <ホスト> <ポート>: 手元から TCP で届くか（3 秒まで。PHP で確かめる。nc の書き方は Mac と Linux で違う）
ec_port_open() {
  php -r '$s = @fsockopen($argv[1], (int) $argv[2], $no, $err, 3.0); exit($s === false ? 1 : 0);' -- "$1" "$2" >/dev/null 2>&1
}
