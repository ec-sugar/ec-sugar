#!/usr/bin/env bash
# 実行イメージの書き込みの境界を確かめる（CONCEPT 7.2「Web プロセスはコードを書き換えない」）
#
#   docker/check-image.sh <イメージ>
#
# 確かめること:
#   1. イメージの実行ユーザーが www-data で、php-fpm の子（worker）も www-data で動く
#   2. 実行ユーザーはコード（アプリ・vendor・public/build・設定・置き場そのもの）を書き換えられず、
#      chmod で書き込みを戻すこともできない。新しいファイルも置けない
#   3. アプリが書く所（storage/ と bootstrap/cache/）には書ける
#   4. アプリが起動する（php artisan --version / route:list）
#   5. php-fpm は既定で Unix ソケット（/run/php-fpm/fpm.sock）だけで受け、TCP では待たない（土台の公式イメージの EXPOSE 9000 の印は
#      引き継がれて消せないが、印だけで、待ち受けはしない）
#
# コンテナは本番と同じく権限を落として（cap_drop ALL・no-new-privileges）、ネットワーク無しで走らせ、終わったら消す（--rm）。
# イメージは外へ送らない。
set -euo pipefail

image="${1:?使い方: docker/check-image.sh <イメージ>}"

user="$(docker inspect --format '{{.Config.User}}' "$image")"
if [ "$user" != "www-data" ]; then
    echo "NG: イメージの実行ユーザーが www-data ではない（${user:-未設定 = root}）"
    exit 1
fi
echo "ok: イメージの実行ユーザーは www-data"

# 中の確かめは標準入力で渡す（--user を付けず、イメージに書かれた実行ユーザーのまま動かす）
docker run --rm -i --network none --cap-drop ALL --security-opt no-new-privileges \
    -e APP_ENV=production -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    --entrypoint /bin/sh "$image" -s <<'PROBE'
set -u
cd /var/www/html
fails=0
ng() { echo "NG: $*"; fails=$((fails + 1)); }
ok() { echo "ok: $*"; }

[ "$(id -un)" = www-data ] && ok "実行ユーザーは www-data" || ng "実行ユーザーが $(id -un)"

# コードのファイルと置き場: 持ち主が自分でないこと、権限を戻せないこと、書けないこと
for path in . app app/Providers/AppServiceProvider.php bootstrap bootstrap/app.php config routes \
            public public/index.php public/build vendor vendor/autoload.php plugins artisan; do
    if [ ! -e "$path" ]; then ng "$path が無い"; continue; fi
    owner="$(stat -c %U "$path")"
    [ "$owner" != www-data ] || ng "$path の持ち主が www-data"
    if chmod u+w "$path" 2>/dev/null; then ng "$path の権限を戻せた"; else ok "$path の権限は戻せない（持ち主 ${owner}）"; fi
    if [ -d "$path" ]; then
        if (: > "$path/.t0069-probe") 2>/dev/null; then ng "$path に新しいファイルを置けた"; rm -f "$path/.t0069-probe"; fi
    else
        if (: >> "$path") 2>/dev/null; then ng "$path に書けた"; fi
    fi
done

# 取りこぼしが無いか: storage/ と bootstrap/cache/ の外に、実行ユーザーの物か書ける物が無いこと
# （リンク（vendor/<作り手の名前>/* → plugins/）自体の権限は常に 777 で意味が無いので除く。リンク先はこの検索に入る）
leaks="$(find . \( -path ./storage -o -path ./bootstrap/cache \) -prune -o ! -type l \
    \( -user www-data -o -group www-data -perm -g+w -o -perm -o+w \) -print 2>/dev/null | head -20)"
[ -z "$leaks" ] && ok "storage/ と bootstrap/cache/ の外に実行ユーザーが書ける物は無い" || ng "実行ユーザーが書ける物: $leaks"

# アプリが書く所には書ける
for dir in storage/logs storage/framework/cache storage/framework/sessions storage/framework/views storage/app bootstrap/cache; do
    if (: > "$dir/.t0069-probe") 2>/dev/null && rm "$dir/.t0069-probe"; then ok "$dir に書ける"; else ng "$dir に書けない"; fi
done
mkdir storage/t0069-probe 2>/dev/null && rmdir storage/t0069-probe && ok "storage/ に区画を作れる" || ng "storage/ に区画を作れない"

# アプリが起動する（bootstrap/cache の控えもここで書かれる）
php artisan --version >/dev/null 2>&1 && ok "php artisan --version" || { ng "php artisan --version が落ちた"; php artisan --version; }
php artisan route:list >/dev/null 2>&1 && ok "php artisan route:list" || { ng "php artisan route:list が落ちた"; php artisan route:list 2>&1 | tail -20; }

# php-fpm の子（worker）は www-data で動く（root で動くものが無い）
php-fpm -t >/dev/null 2>&1 || { ng "php-fpm の設定が通らない"; php-fpm -t; }
php-fpm -F >/dev/null 2>&1 &
fpm=$!
sleep 2
procs="$(ps -o user,args | grep '[p]hp-fpm' || true)"
# （ここはコンテナの中の /bin/sh で、pipefail を入れていない。管の先の grep -q で偽の失敗にならない）
echo "$procs"
echo "$procs" | grep -q 'pool www' && ok "php-fpm の子が動いている" || ng "php-fpm の子が見つからない"
echo "$procs" | awk '{print $1}' | grep -qv -e '^www-data$' -e '^82$' && ng "www-data 以外で動く php-fpm がある" || ok "php-fpm はすべて www-data"
# 既定の受け口は Unix ソケットだけで、TCP では待たない（9000 番を網に出すと、同じサーバーの別の利用者が FastCGI を直に送れる。
# 9000 番で受けるのは開発の compose だけで、環境変数 EC_SUGAR_FPM_LISTEN で渡す）
[ -S /run/php-fpm/fpm.sock ] && ok "php-fpm は既定で Unix ソケット /run/php-fpm/fpm.sock で受ける" || ng "php-fpm の既定のソケット /run/php-fpm/fpm.sock が無い"
listening="$(awk 'NR > 1 && $4 == "0A" {print $2}' /proc/net/tcp /proc/net/tcp6 2>/dev/null | tr '\n' ' ')"
[ -z "$listening" ] && ok "php-fpm は TCP で待たない" || ng "TCP で待っているものがある（16 進の住所:ポート）: $listening"
kill "$fpm" 2>/dev/null

[ "$fails" -eq 0 ] || { echo "NG が ${fails} 件"; exit 1; }
echo "すべて ok"
PROBE
