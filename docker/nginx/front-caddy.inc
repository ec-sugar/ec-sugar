# 入口の nginx の外の口（入口の Caddy の後ろに置く形。既定）。prod.conf の server から読み込む（ADR 0031）
# テンプレート（ひな形）。compose が /etc/nginx/templates/front.inc.template に差し込み、起動のスクリプトが ${EC_SUGAR_…} を置き換えて
# /etc/nginx/conf.d/front.inc に書く（.conf でないので http の中には読み込まれない）
#
# 内側の網の 8080 番で受け、本当の接続元の見出し（X-Forwarded-For）は Caddy の住所からだけ信じる
listen 8080;
set_real_ip_from ${EC_SUGAR_CADDY_ADDR};
