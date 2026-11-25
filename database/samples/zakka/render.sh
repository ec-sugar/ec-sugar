#!/usr/bin/env bash
# サンプルの写真を作り直す（開発の機械で手で流す。本番やインストーラーでは流さない）。
# このディレクトリの SVG（自作の絵）を、Chrome（ヘッドレス）で PNG に描き、ImageMagick で WebP にする。
# 出た WebP をリポジトリに入れる（shop:sample はそれを読むだけ。本番のイメージに Chrome と ImageMagick は要らない）。
#
#   ./database/samples/zakka/render.sh
#
# 要るもの: Google Chrome（または CHROME に道筋）と ImageMagick（magick）
set -euo pipefail

dir="$(cd "$(dirname "$0")" && pwd)"
chrome="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"
command -v magick > /dev/null || { echo 'ImageMagick（magick）が要ります' >&2; exit 1; }
[ -x "$chrome" ] || { echo "Chrome が見つかりません（CHROME に道筋を入れる）: $chrome" >&2; exit 1; }

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

for svg in "$dir"/*.svg; do
    name="$(basename "$svg" .svg)"
    # 大きさは SVG の width と height から読む
    width="$(sed -nE 's/.*<svg[^>]* width="([0-9]+)".*/\1/p' "$svg" | head -n1)"
    height="$(sed -nE 's/.*<svg[^>]* height="([0-9]+)".*/\1/p' "$svg" | head -n1)"
    "$chrome" --headless --disable-gpu --hide-scrollbars --force-device-scale-factor=1 \
        --window-size="$width,$height" --screenshot="$tmp/$name.png" "file://$svg" > /dev/null 2>&1
    magick "$tmp/$name.png" -strip -quality 88 "$dir/$name.webp"
    printf '%s %sx%s %s bytes\n' "$name" "$width" "$height" "$(wc -c < "$dir/$name.webp" | tr -d ' ')"
done
