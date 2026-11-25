#!/bin/sh
# 中央アプリ用ロール。superuser ではなく、テナント DB とロールを作る権限だけ持つ（ADR 0003 / 0009）。
# 初回起動時だけ実行される。管理用の superuser は POSTGRES_USER（ec）のまま
#
# アプリのロールの合言葉は EC_APP_PASSWORD（本番の docker-compose.prod.yml が DB_PASSWORD を渡す）。
# 無ければ superuser と同じ合言葉（開発の compose）。本番では superuser の合言葉をアプリに持たせない
# 合言葉は psql の変数（:'pw'）で渡し、SQL の文に直に埋め込まない（引用符を含んでも壊れない）
set -e
psql -v ON_ERROR_STOP=1 -v pw="${EC_APP_PASSWORD:-$POSTGRES_PASSWORD}" -v db="$POSTGRES_DB" -U "$POSTGRES_USER" -d "$POSTGRES_DB" <<'SQL'
CREATE ROLE ec_app WITH LOGIN PASSWORD :'pw' NOSUPERUSER CREATEDB CREATEROLE;
ALTER DATABASE :"db" OWNER TO ec_app;
SQL
