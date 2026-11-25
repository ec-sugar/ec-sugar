# Microsoft Clarity（App）

ショップでのお客さんの動き（どこをクリックしたか・どこまでスクロールしたか）を、ヒートマップと操作の録画で見られるようにする App です。Clarity は Microsoft の無料のサービスです。

## 使い方

1. https://clarity.microsoft.com/ でプロジェクトを作る（ショップの URL を入れる）
2. Clarity の「設定」→「セットアップ」を開き、**プロジェクト ID**（英小文字と数字の 10 文字ほど。例: `3t0wlogvdz`）を写す。「トラッキングコードを取得」で出るコードの最後の `"script", "…"` の `…` の部分も同じ ID です。**コードそのものは貼りません**
3. EC-SUGAR の管理画面で店を開き、メニューの「App」を押す。一覧の「Microsoft Clarity」の名前（か右の「設定」）を押すと、この App の画面に入る
4. 「設定」の「プロジェクト ID」に入れて、「設定を保存する」を押す
   - 「使わない」のままでも先に保存できます。保存しただけでは動きません
5. 上の「この店では」の「使う」を押す
   - 切り替えと設定の保存は「運用」以上の権限の人だけで、押すとパスキーでもう一度確認します
6. Clarity の「設定」→「マスク」で、マスクのモードを **Strict（厳しい）** にしておくのをおすすめします（下の「記録されるもの」を参照）

## いつ動くか

- 同意の帯は既定では出さないので、ショップを開いたときから動きます。管理画面の設定の「同意の帯」で「出す」にした店では、お客さんが帯で「解析」に同意したときだけ動きます（同意しないお客さんには、Clarity を読み込みません）
- 動くのはふつうの画面（トップ・商品の一覧と詳しい画面・カート・特定商取引法の表示など）だけです。注文の入力と確認・購入の完了・ご注文の確認の画面には入れません
- ショップの「外部送信に関する公表」のページに、Microsoft へ送る中身が自動で載ります

## 記録されるもの

- 見たページの URL と参照元、クリック・スクロール・マウスの動き、端末とブラウザの種類、Clarity の Cookie の識別子
- 画面の見た目。**この App は、ページの文字をすべて伏せ字にして記録させます**（Clarity の `data-clarity-mask` をページ全体に付けます。Clarity の管理画面のマスクの設定より優先されます）。商品名などの文字も伏せ字になりますが、クリックの位置やスクロールの深さは見られます
- 入力欄（検索など）とプルダウンの中身は、Clarity がどの設定でも記録しません
- 購入の金額や注文番号は送りません

## 注意

- **18 歳未満を対象にしたショップでは使えません**（Clarity の利用条件）
- 欧州経済領域・英国・スイスからのお客さんには、同意の信号が必要です（Clarity が 2025-10-31 から求めています）。この App は読み込んだときに「解析は同意あり・広告は同意なし」を Clarity に伝えます。こうした国のお客さんを相手にする店は、設定の「同意の帯」で「出す」にしてください（同意したお客さんにだけ読み込みます）
- 同意の帯を出す店で同意をやめたお客さんには、次のページから読み込みません（読み込み済みのページの記録は止められません）

## 技術的なこと（エンジニア向け）

- スクリプト: `assets/clarity.js`。公式のスニペットと同じ手順（`window.clarity` のキューを作る → `https://www.clarity.ms/tag/<ID>` を `createElement('script')` で読む）を、同じオリジンのファイルで行う。同意は `clarity('consentv2', { ad_Storage: 'denied', analytics_Storage: 'granted' })`
- CSP の宛先（公式の案内 https://learn.microsoft.com/en-us/clarity/setup-and-installation/clarity-csp ）: `script-src` は `https://*.clarity.ms`（1 段目の `www.clarity.ms/tag/…` と 2 段目の `scripts.clarity.ms/…/clarity.js`）、`connect-src` と `img-src` は `https://*.clarity.ms` と `https://c.bing.com`。公式の例にある `'unsafe-inline'` は、インラインのスニペットのためなので足さない
- Trusted Types: 公式の資料に記述は無い。2026-10-29 に配信元のスクリプト（`tag/<ID>` と `scripts.clarity.ms/0.8.72/clarity.js`）を読んだ限り、`innerHTML`・`document.write`・`eval`・`new Function` は無く、自前のポリシー（`trustedTypes.createPolicy`）も作らない。script の `src` に文字の URL を入れる所は、この App の `clarity.js`（1 段目を読む）、1 段目（2 段目を読む）、2 段目（追加の部品を読む。宛先は配信元から来る）の 3 か所。ショップの `require-trusted-types-for 'script'` の下では、**核の既定の関所（default policy）が `https://www.clarity.ms/` と `https://scripts.clarity.ms/` を通さないと、どれも止まる**。関所はこの App では作らない。実際に Trusted Types の下で動くかは、ブラウザでまだ確かめていない
