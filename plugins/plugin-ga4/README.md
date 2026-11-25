# Google アナリティクス 4（App）

ショップのアクセスと購入を Google アナリティクス（GA4）で計測します。

## 使い方

1. 管理画面で店を開き、メニューの「App」を押します。一覧の「Google アナリティクス 4」の名前（か右の「設定」）を押すと、この App の画面に入ります
2. 「設定」の「測定 ID」に入れて、「設定を保存する」を押します。`G-` で始まる英大文字と数字です（例: `G-ABC123DEF4`）
   - Google アナリティクスの「管理」→「データの収集と修正」→「データストリーム」→ ウェブのストリームを開くと、右上に出ています
   - 「使わない」のままでも先に保存できます。保存しただけでは動きません
3. 上の「この店では」の「使う」を押します
   - 切り替えと設定の保存は「運用」以上の権限の人だけで、押すとパスキーでもう一度確認します
4. 「使う」にして測定 ID が入っていると、ショップで計測が始まります。同意の帯は既定では出しません。管理画面の設定の「同意の帯」で「出す」にした店（海外のお客さんを相手にする店）では、お客さんが帯で「アクセス解析」に同意したときだけ計測します

## 何が送られるか

送信先は Google LLC です。

- 見たページの URL、参照元の URL、端末とブラウザの情報、Cookie の識別子
- 購入の完了の画面では、**注文の番号・金額・通貨・商品の ID と数だけ**（`purchase` のイベント）

お客さんの名前・メール・住所・電話は送りません。注文の入力・確認、ご注文の状況、アカウントの画面では、この App は動きません。
送る内容は、ショップの「外部送信に関する公表」のページに自動で載ります。

## お店の人にお願いすること

- Google アナリティクスの管理画面で「Google シグナル」を有効にしても、ショップからは送りません（広告の機能は使わない設定で読み込みます）
- 「ユーザー提供データの収集」（入力欄からメールなどを自動で拾う機能）は有効にしないでください

## 公式の資料

- 設置: https://developers.google.com/tag-platform/gtagjs/install
- 購入の計測: https://developers.google.com/analytics/devguides/collection/ga4/ecommerce
- 測定 ID: https://support.google.com/analytics/answer/12270356
- 同意モード: https://developers.google.com/tag-platform/security/guides/consent
- CSP（ブラウザに許す送信先）: https://developers.google.com/tag-platform/security/guides/csp

作りの決まりは docs/design/tag-apps.md の「1. GA4」と ADR 0034。
