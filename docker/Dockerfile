# EC-SUGAR app image
# 方針（docs/CONCEPT.md 7.2 / 7.3）:
#   - コードはイメージに焼き込む。実行時に書き込めるのは storage/ と bootstrap/cache/ だけ
#   - Web プロセスは非 root。コードを書き換えられない
#   - 更新 = イメージの差し替え。プラグインもビルド時にしか入らない
#   - 土台は版と digest まで固定する（同じコミットから同じ中身を組み直せるように。署名して配る前提）。
#     上げるときは、本家で出てから 7 日より後の版を選び、札と digest をそろえて変える（Docker Hub の日付は土台の作り直しでも進むので、本家の出た日で見る）

# ---------- 1. PHP 依存のインストール（ビルド専用ステージ） ----------
FROM composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
# プラグイン（path リポジトリ）はここに含める。plugins/ が無くても通るように条件付き
COPY plugins/ ./plugins/
RUN composer install \
      --no-dev --no-interaction --no-progress --no-scripts \
      --prefer-dist --optimize-autoloader

# ---------- 2. フロントのビルド（Node.js はここだけ。実行イメージには入らない。ADR 0015） ----------
FROM node:26.13.0-alpine3.24@sha256:8996bb5821d62a8fd9bb27c1cb07462f3fdce6e47d640c6a881ead0b064ce216 AS assets
WORKDIR /build
# lock に書かれたバージョンだけを入れ、パッケージのインストール時のスクリプトは動かさない（.npmrc も同じ設定）
COPY package.json package-lock.json .npmrc ./
RUN npm ci --ignore-scripts --no-audit --no-fund
COPY resources/admin/ ./resources/admin/
# 管理画面のヘルプの画像（HelpPaymentsPage.vue が読む）と、ショップの核の部品（カート。vite.config.js の入口にある）
COPY resources/help/ ./resources/help/
COPY resources/storefront/ ./resources/storefront/
# 店頭のテーマ（CSS と、Tailwind が使うクラスを探す画面のファイル）
COPY plugins/ ./plugins/
COPY vite.config.js ./
# バージョン（コミットの印）を管理画面に焼き込む。サーバーのバージョンと食い違えば、古い画面が残っていると分かる（ADR 0031）
ARG EC_SUGAR_REVISION=""
RUN VITE_EC_SUGAR_REVISION="$EC_SUGAR_REVISION" npm run build

# ---------- 3. PHP と拡張（実行イメージと検査用のコンテナの共通の土台） ----------
FROM php:8.5.13-fpm-alpine3.24@sha256:1712c76e2d29b8a3e966642fb120b02fa0c72616f8dc667b54ccd26e582d7856 AS php-base

# 拡張: pdo_pgsql(DB) / redis(キュー・キャッシュ) / intl(ロケール) / gd(画像の再エンコード) / exif(写真の向き) / zip / bcmath(金額)。opcache は PHP 8.5 で本体組み込み
RUN apk add --no-cache icu-libs libpng libjpeg-turbo libwebp libzip postgresql-libs \
 && apk add --no-cache --virtual .build icu-dev libpng-dev libjpeg-turbo-dev libwebp-dev libzip-dev postgresql-dev $PHPIZE_DEPS \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install pdo_pgsql intl gd zip bcmath pcntl exif \
 && pecl install redis && docker-php-ext-enable redis \
 && apk del .build

# 設定は名前の順に読まれる。php-fpm.d は公式のイメージの zz-docker.conf（listen = 9000）より後ろの名前にする（受け口をソケットで上書きするため）
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-ec-sugar.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-ec-sugar.conf

# ---------- 4. 実行イメージ ----------
FROM php-base AS app

WORKDIR /var/www/html

# アプリ本体（.dockerignore で .env / storage の中身 / bootstrap/cache の控え / vendor / node_modules を除外）
# コードは root の持ち物のまま入れる（--chown を付けない）。
COPY . .
COPY --from=vendor /build/vendor ./vendor
COPY --from=assets /build/public/build ./public/build

# 書き込み可能な場所を最小にする
#   - コード（アプリ・vendor・public/build・設定）は root の持ち物で、誰も書き込めない。
#     実行ユーザー（www-data）は持ち主ではないので、chmod で書き込みを戻すこともできない
#     （持ち主の物にして a-w にするだけでは、持ち主が自分で u+w に戻せてしまう）
#   - 置き場（/var/www/html）は元のイメージで www-data の持ち物・誰でも書ける（1777）ので root に戻し、
#     新しいファイル（例: public/ に PHP）を置けないようにする
#   - 実行ユーザーの物にするのは、アプリが書く storage/（ログ・セッション・キャッシュ・写真・画面の下書き。店ごとの区画も
#     この下）と bootstrap/cache/（Laravel の起動の控え）だけ
#   確かめは docker/check-image.sh（CI の image の仕事で毎回走る）
#   php-fpm の既定の受け口（Unix ソケット /run/php-fpm/fpm.sock。docker/php/www.conf）の置き場も www-data の 0750 で作る
#   （本番は名前つきの置き場 fpm を重ねる。TCP では受けない）
RUN chown root:root . \
 && chmod -R a-w . \
 && chmod 0555 . \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R u+w storage bootstrap/cache \
 && install -d -m 0750 -o www-data -g www-data /run/php-fpm

# バージョン（コミットの印と日時）。インストーラーが build-arg で渡す（deploy/lib/common.sh）。渡さなければ空で「バージョンの印なし」
# 最後の方に置く（バージョンが変わっても前の層のキャッシュを使える）
ARG EC_SUGAR_REVISION=""
ARG EC_SUGAR_REVISION_DATE=""
ENV EC_SUGAR_REVISION=$EC_SUGAR_REVISION \
    EC_SUGAR_REVISION_DATE=$EC_SUGAR_REVISION_DATE

USER www-data
# ポートは出さない（EXPOSE を書かない）。php-fpm は既定で Unix ソケットだけで受ける（docker/php/www.conf）
CMD ["php-fpm"]

# ---------- 5. 検査用のコンテナ（make check-docker。本番には使わない・配らない） ----------
# 本番と同じ PHP と拡張（php-base）に composer を足しただけ。手元の PHP の版や拡張に左右されずに pint・phpstan・テストを流す。
# コードは走らせる時に重ね（イメージに焼かない）、開発の依存（vendor）は名前つきの volume に入れて手元の vendor と混ぜない。
# vendor と composer のキャッシュの場所は誰でも書ける形で用意する（走らせる人の番号で動かすので。名前つきの volume は最初にここの権限を写す）
FROM php-base AS check
COPY --from=composer:2.10.3@sha256:2cf275428757e5435a667bfc0d038b14520ca02be2b1b70d44a05c8c19e2de48 /usr/bin/composer /usr/local/bin/composer
# テストのスクリプトが使う bash と git だけ足す。シェル実行の無効化（本番の php.ini）は、composer とテストが使うのでこのコンテナでだけ外す
RUN apk add --no-cache bash git \
 && printf 'disable_functions =\n' > /usr/local/etc/php/conf.d/zzz-check.ini \
 && install -d -m 0777 /app /app/vendor /composer-cache
ENV COMPOSER_CACHE_DIR=/composer-cache \
    HOME=/tmp
WORKDIR /app
