# EC-SUGAR 開発ループ。`make` で一覧。
# 方針: 1 コマンドで作り直せる / 検査は落ちたまま進めない / ポートは 127.0.0.1 束縛

.DEFAULT_GOAL := help
SHELL := /bin/bash
COMPOSE := docker compose
APP := $(COMPOSE) exec -T app

.PHONY: help setup up down restart logs sh psql build test-clean test-serial \
        migrate seed fresh tenant tenants-migrate demo-sugar \
        test check check-docker lint stan audit hooks front watch e2e e2e-db old-down \
        doctor test-env test-work check-guard e2e-guard \
        test-remote test-remote-up test-remote-down

help: ## このヘルプ
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

setup: ## 初回セットアップ（env・鍵・コンテナ・移行・デモテナント・git hooks）
	@test -f .env || cp .env.example .env
	composer install --no-interaction
	@$(MAKE) --no-print-directory old-down
	@grep -q '^APP_KEY=base64' .env || php artisan key:generate --no-interaction
	$(COMPOSE) up -d --build
	@$(MAKE) --no-print-directory wait-db
	$(APP) php artisan migrate --force
	$(APP) php artisan db:seed --force
	$(APP) php artisan tenants:migrate
	@$(MAKE) --no-print-directory hooks
	@echo; echo "  中央:     http://localhost:8080/"; echo "  テナント: http://demo.localhost:8080/"; echo "  Mailpit:  http://127.0.0.1:8025/"

up: ## コンテナ起動（流していない移行があれば流す。git でバージョンを上げただけでは移行は流れないため）
	@$(MAKE) --no-print-directory old-down
	$(COMPOSE) up -d
	@$(MAKE) --no-print-directory wait-db
	@$(MAKE) --no-print-directory migrate

down: ## コンテナ停止（データは残す）
	@$(MAKE) --no-print-directory old-down
	$(COMPOSE) down

# 前の compose の名前（sugar-ec。で ec-sugar に替えた）のコンテナが残っていれば片付ける。同じポートと同じ DB の置き場を取り合わないように。
# 置き場（volume）は消さない（-v を付けない。docker-compose.yml が前の名前の置き場をそのまま指す）
old-down:
	@if [ -n "$$(docker ps -aq --filter label=com.docker.compose.project=sugar-ec 2>/dev/null)" ]; then \
	  echo "  前の名前（sugar-ec）のコンテナを片付けます（DB と写真の置き場はそのまま）"; \
	  $(COMPOSE) -p sugar-ec down --remove-orphans; \
	fi

restart: ## app / queue / scheduler を再起動
	$(COMPOSE) restart app queue scheduler

build: ## app イメージを作り直す
	$(COMPOSE) build app
	$(COMPOSE) up -d app queue scheduler

logs: ## ログを追う
	$(COMPOSE) logs -f --tail=100 app queue scheduler web

sh: ## app コンテナに入る
	$(COMPOSE) exec app sh

psql: ## 中央 DB に psql で入る
	$(COMPOSE) exec db psql -U ec -d ec_central

migrate: ## 中央 DB とテナント DB を移行
	$(APP) php artisan migrate --force
	$(APP) php artisan tenants:migrate

seed: ## デモデータ投入（冪等）
	$(APP) php artisan db:seed --force

fresh: ## 中央 DB を作り直してデモテナントを作り直す（テナント DB も消える）
	$(APP) php artisan tinker --execute='App\Models\Tenant::all()->each->delete();'
	$(APP) php artisan migrate:fresh --force --seed
	$(APP) php artisan tenants:migrate

demo-sugar: ## 既定テーマの見本の店 SUGAR SHOP を作り直す（http://sugar.localhost:8080/。写真は storage/app/demo/sugar にあれば使う）
	$(APP) php artisan db:seed --class=SugarShopSeeder --force

tenant: ## テナント作成: make tenant id=shop1 [domain=shop1.localhost]
	@test -n "$(id)" || { echo "usage: make tenant id=<id> [domain=<host>]"; exit 1; }
	$(APP) php artisan tinker --execute='$$t = App\Models\Tenant::firstOrCreate(["id" => "$(id)"], ["name" => "$(id)"]); $$t->domains()->firstOrCreate(["domain" => "$(or $(domain),$(id).localhost)"]); echo "tenant=", $$t->id, " http://", $$t->domains()->first()->domain, ":8080/", PHP_EOL;'

tenants-migrate: ## テナント DB だけ移行
	$(APP) php artisan tenants:migrate

# PHP のテストは並べて流す（ADR 0057）。並べた各々は ec_test_p<番号>・ec_test_p<番号>_t_・media-testing-p<番号> の組
# （作業ごとに DB_DATABASE=ec_test_<文字> を渡せば ec_test_<文字>p<番号> の組。tests/bootstrap.php）。数は既定で CPU の数、
# 減らすときは make test PROCESSES=4。1 つの処理で流すときは make test-serial（1 つのファイルだけなら php artisan test --compact <ファイル>）
PROCESSES ?=

# 開発機ごとの決まり（テストを流す前の歯止め・離れた機械でテストを流す口）は ops/dev/local.mk に置き、あれば読む。
# 無ければ歯止めは何もしない（GUARD は true）。ふつうに make test で流せる
GUARD := true
-include ops/dev/local.mk

test: ## テスト（並べて流す。CI 用。手元では止まる。数は PROCESSES=<数>）
	@$(GUARD) suite test
	php artisan test --parallel $(if $(PROCESSES),--processes=$(PROCESSES),)

test-serial: ## テストを 1 つの処理で順に流す（CI 用。手元では止まる）
	@$(GUARD) suite test-serial
	php artisan test --compact

# make test-clean で片付ける作業の文字。空なら既定の ec_test_t_*・ec_e2e_t_*、TEST_WORK=g なら作業ごとの ec_test_g_t_*・ec_e2e_g_t_*
# （tests/Support/TestEnvironmentGuard と同じ形だけを受ける。SQL に入れる前に確かめる）。PHP のテストを並べて流した各々の店の DB
# （ec_test_p<番号>_t_*。作業ごとなら ec_test_<文字>p<番号>_t_*）と、店の DB の型（<頭>_template。ADR 0057）も消す。
# 消すのは店の DB・型とロールだけで、中央の DB（ec_test・ec_test_g・ec_test_p1・ec_e2e・ec_e2e_g）と開発の DB（ec_central・ec_t_*）は形が合わないので消さない。
# 値は make の変数を文に直に埋めず、環境変数（コマンドラインの変数は make が渡す）から読む（' などを含む値でも、確かめる前にシェルが崩れない）。
# 確かめは文字列の全体で（grep -x は行ごとに見るので、改行を挟んだ値の 1 行だけが合っても通ってしまう）
test-clean: ## テストが途中で落ちて残った店の DB とロールを消す（既定は ec_test_t_* / ec_test_p<番号>_t_* / ec_e2e_t_*。作業ごとのものは TEST_WORK=<文字>）
	@work="$$TEST_WORK"; base=ec_test; sep=_; e2e=ec_e2e; \
	if [ -n "$$work" ]; then \
	  LC_ALL=C; [[ "$$work" =~ ^[a-z0-9]+$$ && "$$work" != t ]] \
	    || { echo "TEST_WORK は英小文字と数字（t は除く）にしてください"; exit 1; }; \
	  base="ec_test_$$work"; sep=; e2e="ec_e2e_$$work"; \
	fi; \
	$(COMPOSE) exec -T db psql -U ec -d ec_central -v ON_ERROR_STOP=1 -Atc \
	  "SELECT format('DROP DATABASE %I WITH (FORCE);', datname) FROM pg_database WHERE datname ~ '^$$base($${sep}p[0-9]+)?_t_' OR datname ~ '^$${e2e}_t_' \
	   UNION ALL SELECT format('DROP ROLE %I;', rolname) FROM pg_roles WHERE rolname ~ '^$$base($${sep}p[0-9]+)?_t_u_' OR rolname ~ '^$${e2e}_t_u_'" \
	  | $(COMPOSE) exec -T db psql -U ec -d ec_central -v ON_ERROR_STOP=1 -q -e

# 作業ごとのテストの環境（ops/dev/test-work.sh、docs/development/doctor.md）。印は test-clean と同じ形（t と p<数字> で終わるものは除く）。
# 値は make の変数を文に直に埋めず、環境変数（コマンドラインの変数は make が渡す）から読む
test-env: ## 作業ごとのテストの DB と置き場を用意し、使う環境変数の行を出す（eval "$(make -s test-env TEST_WORK=<文字>)" でシェルに入る）
	@bash ops/dev/test-work.sh env

test-work: ## 作業ごとの環境で PHP のテストを流す（make test-work TEST_WORK=<文字> FILES="<1 ファイル>" [FILTER=…] [STOP=1]。手元では 1 ファイルだけ）
	@$(GUARD) files "$(FILES)"
	@bash ops/dev/test-work.sh run

doctor: ## 開発の環境を診断する（読むだけ。ディスク・Docker・PHP と依存・控えとビルド・DB と移行・テストの残り・.env。NG があれば 1 で終わる）
	@bash ops/dev/doctor.sh

lint: ## コードスタイル検査
	vendor/bin/pint --test

stan: ## 静的解析
	vendor/bin/phpstan analyse --no-progress --memory-limit=1G

audit: ## 依存の脆弱性と、npm のパッケージの署名
	composer audit
	ops/dev/npm-audit.sh
	npm audit signatures

front: ## 管理画面の SPA をビルド（lock のバージョンだけを入れる。Node.js はビルドの時だけ。ADR 0015）
	npm ci --ignore-scripts --no-audit --no-fund
	npm run build

watch: ## 管理画面の SPA を、ファイルが変わるたびにビルドし直す（開発サーバーは立てない。ブラウザは手で読み込み直す）
	npm run watch

# ブラウザのテストの DB。作業ごとに分けるときは E2E_DB=ec_e2e_<文字>（tests/e2e/env.js と同じ形だけを受ける。SQL に入れる前に確かめる）
E2E_DB ?= ec_e2e

e2e-db:
	@echo '$(E2E_DB)' | grep -Eqx 'ec_e2e(_[a-z0-9]+)?' && [ '$(E2E_DB)' != 'ec_e2e_t' ] \
	  || { echo "E2E_DB は ec_e2e か ec_e2e_<英小文字と数字>（t は除く）にしてください"; exit 1; }
	@$(COMPOSE) exec -T db psql -U ec -d ec_central -tAc "SELECT 1 FROM pg_database WHERE datname = '$(E2E_DB)'" | grep -q 1 \
	  || $(COMPOSE) exec -T db psql -U ec -d ec_central -v ON_ERROR_STOP=1 -c "CREATE DATABASE $(E2E_DB) OWNER ec_app"

e2e: e2e-guard e2e-db ## 管理画面をブラウザで通しで確かめる（Playwright。CI 用。手元では止まる。場面を選ぶなら npm run e2e -- -g "<名前>"）
	npm run build
	npm run e2e

check: check-guard lint stan audit secrets test e2e ## 検査をすべて通す（CI と同じ。手元では止まる）

# 歯止めは並びの先頭で止める（lint や phpstan、DB の作り直しを済ませてから止まると時間の無駄になるため）
check-guard:
	@$(GUARD) suite check

e2e-guard:
	@$(GUARD) suite e2e

# 本番と同じ PHP と拡張のコンテナ（docker/Dockerfile の check）の中で、pint・phpstan（CI と同じ phpstan.ci.neon）・PHP のテストを流す。
# 手元の PHP の版・拡張・vendor に左右されず、誰がどこで流しても同じ結果にするため。コードはこの作業場所を重ね、開発の依存は名前つきの
# volume（ec-sugar-check-vendor。手元の vendor とは別）に入れる。DB と Redis は手元の開発用（127.0.0.1:54329・63799）へ host.docker.internal で繋ぐ
# （Linux の Docker で 127.0.0.1 にだけ開いている時は CHECK_DB_HOST / CHECK_REDIS_HOST を変える）。テストは TESTS=<ファイル> で絞れる（空なら全部を並べて）。
# APP_KEY・DB_DATABASE・TENANT_DB_PREFIX・MEDIA_DIRECTORY は、手元で渡していればそのままコンテナへ渡す（.env の無い作業場所では APP_KEY を渡す）。
# phpstan のキャッシュ（tmpDir）は手元の .phpstan.cache と分ける（手元のパスが入ったキャッシュを読むと、コンテナの中で落ちる）。
# 片付け: docker image rm ec-sugar-check && docker volume rm ec-sugar-check-vendor ec-sugar-check-composer
CHECK_IMAGE ?= ec-sugar-check
CHECK_DB_HOST ?= host.docker.internal
CHECK_REDIS_HOST ?= host.docker.internal
TESTS ?=

check-docker: ## 本番と同じ PHP のコンテナの中で pint・phpstan・PHP のテスト（手元の PHP に左右されない。TESTS=<ファイル> で絞る）
	docker build --file docker/Dockerfile --target check --tag $(CHECK_IMAGE) .
	docker run --rm --user "$$(id -u):$$(id -g)" --add-host=host.docker.internal:host-gateway \
	  -v "$(CURDIR)":/app -v ec-sugar-check-vendor:/app/vendor -v ec-sugar-check-composer:/composer-cache \
	  -e DB_HOST=$(CHECK_DB_HOST) -e REDIS_HOST=$(CHECK_REDIS_HOST) \
	  -e APP_KEY -e DB_DATABASE -e TENANT_DB_PREFIX -e MEDIA_DIRECTORY -e APP_URL \
	  $(CHECK_IMAGE) sh -c 'set -e; \
	    composer install --no-interaction --no-progress; \
	    vendor/bin/pint --test; \
	    printf "includes:\n    - /app/phpstan.ci.neon\nparameters:\n    tmpDir: /tmp/phpstan\n" > /tmp/phpstan.neon; \
	    vendor/bin/phpstan analyse --no-progress --memory-limit=1G -c /tmp/phpstan.neon; \
	    if [ -n "$(TESTS)" ]; then php artisan test --compact $(TESTS); else php artisan test --parallel; fi'

secrets: ## 履歴に秘密情報が無いか（gitleaks。無ければ警告だけ。CI では必ず走る）
	@# 「入っていない」と「秘密を見つけた」を分ける（A && B || C の形だと、見つけて落ちても成功で終わる）
	@if command -v gitleaks >/dev/null 2>&1; then \
	  gitleaks git --no-banner --redact . || { echo "✗ 履歴に秘密情報らしき文字列があります"; exit 1; }; \
	else \
	  echo "  (gitleaks 未インストール: brew install gitleaks)"; \
	fi

hooks: ## git hooks を有効化（.githooks/）
	git config core.hooksPath .githooks
	@chmod +x .githooks/* && echo "  hooks: .githooks/ を有効化"

wait-db:
	@for i in $$(seq 1 30); do $(COMPOSE) exec -T db pg_isready -U ec -d ec_central >/dev/null 2>&1 && exit 0; sleep 2; done; echo "db が起動しない"; exit 1
