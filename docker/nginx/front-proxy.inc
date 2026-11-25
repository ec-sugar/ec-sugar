# 入口の nginx の外の口（すでにある Web サーバーの後ろに置く形、FRONT=proxy）。prod.conf の server から読み込む（ADR 0031 の 5）
# docker-compose.proxy.yml が front-caddy.inc の代わりに差し込む
#
# TCP では受けない。ホストの /var/lib/ec-sugar/front の Unix ソケットだけで受ける。置き場は前の Web サーバーの利用者のグループだけが
# 入れる（install.sh が root の持ち物・専用のグループの 2770 ＋ 前の Web サーバーのグループに ACL で通り抜けだけ、で作る。nginx はソケットを 0666 にするので、守りは置き場の権限）ので、同じサーバーの別の利用者（ホスティングの契約者の PHP・乗っ取られた WordPress・cron・SSH の利用者）は
# つなげない。127.0.0.1 の番号で待つ形だと、ホストのどの利用者も前の Web サーバーと同じ住所（gateway）から来たように見え、
# X-Forwarded-For を偽って管理画面の接続元の制限を越えられた（2 回目の見直し）
listen unix:/run/ec-sugar-front/web.sock;
# 本当の接続元の見出しは、ソケットから来た要求（＝前の Web サーバー）だけ信じる。最後の住所だけ（prod.conf の real_ip_recursive off）
set_real_ip_from unix:;
# PHP には https と伝える（prod.conf）ので、前の Web サーバーが http で受けた要求をそのまま渡してきたら https へ送る
if ($http_x_forwarded_proto != "https") {
    return 308 https://$host$request_uri;
}
