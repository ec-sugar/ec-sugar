# タグの App の下調べ（計測・広告のタグ 最初のまとまり）

調べた日: **2026-10-29**。**公式の資料だけを元にした。実際に動かして確認するのは作るとき。**
決まりの元: [ADR 0034](../adr/0034-App-はショップに決まった形でタグを差し込む.md)・[storefront-scripts.md](storefront-scripts.md)。

## 読み方

- 【公式】= 各社の公式の資料に書いてある
- 【読み取り】= 公式のスニペット、または公式のスニペットが読み込むスクリプト（各社の配信元から取得して読んだもの）から読み取った。仕様として約束されたものではない
- 【推測】= 公式に書いていない。作るときに確認する
- 「公式の CSP の案内は無い」と書いたものは、宛先を【読み取り】で埋めている。作るときにブラウザの CSP 違反の報告（report-only）で足りない宛先を洗い出す

## 先に結論（大事な変化と、ぶつかりそうな所）

1. **LINE タグは作らない**。LINE 広告は LINEヤフー広告に統合され、**2026-10-26 に配信を終えた**（2027 年 3 月末ごろ提供終了の予定）【公式】。LINE Tag は統合後の「LINEヤフー広告 ディスプレイ広告」に対応しておらず、「計測タグ」を新たに設定する必要がある【公式】。LINE への広告の計測は、下の「LINEヤフー広告」の App（計測タグ）で受ける
2. **Yahoo! 広告は「LINEヤフー広告」に名前が変わり、タグが 2 種類ある**: 新しい「計測タグ」（ディスプレイ広告・検索広告〔ショッピング〕・LINE 公式アカウント）と、従来の「サイトジェネラルタグ ＋ コンバージョン測定タグ」（検索広告）。従来版は新規作成の提供を終えつつある（ショッピングでは 2026-10-06 に終了）【公式】。**計測タグのスニペットの本文は公開の資料に無く、管理画面で発行される**ので、作るにはお店の実アカウント（試用のアカウント）が要る
3. **CSP**: ショップの CSP は `default-src 'none'` なので、`script-src`・`connect-src`・`img-src` に加えて、**iframe を使うタグは `frame-src` を書かないと止まる**（Google の広告の機能・X）。公式に宛先の一覧を出しているのは **Google と Microsoft Clarity だけ**
4. **Trusted Types**: どの会社も公式の記述は無い。読み取った限り、**どのタグも `document.createElement('script')` に文字の URL を `.src` で入れて次のスクリプトを読む**。`require-trusted-types-for 'script'` の下では、ADR 0034 の「既定の関門（default policy）」が無いと全部止まる。HTML の差し込み（`innerHTML`・`document.write`）は、読んだ範囲のスクリプトには無かった（作るときに Pinterest の 2 段目で 1 つ見つけた。10 節）。**Meta だけは自前で Trusted Types のポリシーを作る**（ショップの CSP に `trusted-types` の行〔ポリシー名の制限〕が無いので今は通る。名前を絞るときは Meta の分を足す）
5. **`<noscript>` の画像のピクセルは使わない**（Meta・Pinterest など）。同意の前に、JavaScript 無しで送ってしまうため
6. **送られるのは注文の値だけではない**: どのタグもページの URL・参照元・端末の情報を送る。TikTok は「ページのメタデータ・構造化データ・ボタンのクリック」も集める【公式】。Meta・Google・TikTok には、ページの入力欄からメールなどを自動で拾う機能（自動の詳細マッチング・ユーザー提供データの自動検出）が管理画面の設定にある【公式】。個人情報の画面に入れない約束（ADR 0034）で大半は防げるが、**お店の人の管理画面の設定で拾う機能を切ってもらう案内**が要る
7. **Clarity は画面の録画をする**。既定の「Balanced」で隠すのは数字とメールだけで、名前や住所の文字は録られる【公式】。購入の完了の画面に名前・住所を出すテーマでは Clarity を入れてはいけない（今の 4 つのテーマの完了の画面は注文番号と合計だけ）

## 一覧

| タグ | App にするか | ID の形 | 公式の CSP の案内 | 購入の計測（番号・金額・通貨・品） | 同意の仕組み | サーバーから送る形 |
|---|---|---|---|---|---|---|
| GA4（gtag.js） | する | `G-` ＋英数字【公式】 | あり | `purchase`。全部送れる【公式】 | 同意モード v2【公式】 | Measurement Protocol |
| Google 広告 | する | `AW-` ＋数字／ラベル【読み取り】 | あり | `conversion`（send_to・value・currency・transaction_id）【公式】 | 同意モード v2【公式】 | Google 広告 API のオフライン CV |
| Meta ピクセル | する | 数字【推測】 | 無い | `Purchase`（value・currency 必須、contents）【公式】 | `fbq('consent', ...)`【公式】 | Conversions API |
| LINE タグ | **しない** | — | 無い | 公式の公開の資料に無い | 無い | LINE Conversion API（LINE 広告とともに終了へ） |
| LINEヤフー広告（計測タグ） | する（実アカウント待ち） | `tagId`（文字列。形は公式に無い） | 無い | `purchase`（transactionId・value・currency・items）【公式】 | 無い（外部送信を止める `isExternalTransmission` はある） | コンバージョン API |
| LINEヤフー広告（従来の検索広告のタグ） | 計測タグの対応を待つか決める | 数字の ID ＋ラベル【読み取り】 | 無い | 金額は書き換えで渡す【公式】 | 無い | — |
| TikTok ピクセル | する | 形は公式に無い | 無い | `Purchase`（value・currency・content_ids）【公式】 | 公式の API の記述は見つからない | Events API |
| X ピクセル | 後回し | 英数字 5 文字ほど【読み取り】 | 無い | `twq('event', ...)`（value・currency・conversion_id・contents）【公式の抜粋】 | 見つからない | Conversion API |
| Microsoft Clarity | する | 英小文字と数字 10 文字【推測】 | あり | 無い（解析だけ） | `clarity('consentv2', ...)`【公式】 | 無い |
| Microsoft 広告（UET） | する | 数字【推測】 | 無い | `purchase`（transaction_id・revenue_value・currency・items）【公式】 | 同意モード（ad_storage）【公式】 | Conversions API |
| Pinterest タグ | する | 数字【読み取り】 | 無い | `checkout`（order_id・value・currency・line_items）【公式】 | 見つからない | Conversions API |

## 共通の作り（どのタグの App も同じ）

- **読み込み**: 公式のスニペットのインラインの部分は、どれも「キューの関数（`gtag`・`fbq` など）を作る → 外のスクリプトの `<script>` を作って差し込む → 初期化を呼ぶ」だけ【読み取り】。`document.currentScript` などインラインであることに頼る書き方は無いので、**App のパッケージの同じオリジンのファイルに移しても同じことができる**【読み取り】。ID はコアが `data-` の印で渡し、ファイルの中で読む
- **Trusted Types**: 自前のファイルで `script.src = 'https://...'` と書くと止まる。コアの既定の関門（default policy）で、その App のマニフェストの `script-src` の宛先に合う URL だけを通す。外のスクリプトが続けて読む 2 段目のスクリプト（下の各節）も同じ関門を通るので、2 段目の宛先もマニフェストに書く。既定の関門は 1 ページに 1 つしか作れないので、**コアが外のどのスクリプトより先に作る**
- **eval**: ショップの `script-src` に `'unsafe-eval'` は無い。読んだ範囲では、X の `new Function("return this")`（予備の書き方）だけが当たる。主な動きは止まらない見込み【推測】
- **同意**（2026-10-30 に置き換え。ADR 0034 の 4）: **同意バナーは既定で出さない**。バナーを出さない店では、ふつうの画面と購入の完了に最初から読み込み、送信先は「外部送信に関する公表」のページに載せるだけ（日本の外部送信規律が求めるのは公表）。店の設定（設定 › App › 同意バナー）で「出す」にした店（海外のお客さんを相手にする店）だけ、同意の前は読み込まない（Google と Microsoft の言う「基本の同意モード」）。同意をやめたら、読み込み済みのタグは止められないので、各社の「同意をやめる」呼び出しをしてからページを読み直す
  - App のスクリプトは、どちらの店でも「読み込まれたら同意あり」として各社の同意の呼び出しをする（バナーを出さない店では、店がバナーを出さないと決めたため。各社へ「同意なし」を伝える形は作っていない。欧州などのお客さんにはバナーを出す店にしてもらう）
- **購入の完了**: 二重の送信を防ぐため、コアは購入の値を **最初の 1 回の表示にだけ** 渡すのがよい【推測】（Google・LINEヤフー・Microsoft は取引の番号で重複を除くが、Meta・TikTok のブラウザの送信は番号だけでは除かれない）。支払いの確定（Webhook）の前に完了の画面が出ることがあるので、どの時点で「購入」と送るかは決めること
- **購入の値の確認**（2026-11-19）: 各社へ送る前に、通貨が 3 文字の形で、金額が安全な整数で**負でないこと**、品の数が安全な整数で**1 個以上**であることを確認し、当たらない値は送らない。負の額や 0 個の品を送ると、各社の管理画面の集計が狂う（返金で負になった値や、`data-` の印を作り替えた要求でも送らない）
- **同じ部品の食い違いを仕組みで止める**（2026-11-19）: 1 つのタグに 1 つの App（ADR 0034）で、App はそれぞれ別の composer パッケージなので、購入の値を読む部品は App ごとに同じ形で書き写している。次の 3 組は同一を自動で監視する（`tests/Unit/TagAppScriptsTest.php`）。**片方だけ直すと落ちる**ので、落ちたら両方（3 つなら全部）を同じに直す（1 つに畳むにはコアに新しい口が要るので 1.0 の後。ADR 0098）
  - `ga4.js` と `google-ads.js` の「ここから下は同じ部品」の印から下（gtag.js の分け合いと `purchase()`）
  - `tiktok-pixel.js`・`pinterest-tag.js`・`microsoft-uet.js` の `purchase(values, sentPrefix)`
  - `smartnews-ads.js` の `purchase(values, sentPrefix)`（上と、戻り値から注文の番号を落とした 1 行だけが違う。許す違いもテストに書いてある）
- **目的**: マニフェストの `category` に、解析のタグは `analytics`、広告の計測のタグは `marketing` を書く（管理画面の App の画面で「アクセスの解析」「集客と広告」のまとまりに並ぶ。言葉の一覧は plugins/README.md）
- **タグの JavaScript が読めない画面**（2026-11-06、ADR 0088）: タグは同じオリジンで動くので、お客さんの Cookie を持ったまま別の画面を fetch できる。個人情報を出す経路（マイページ・ログインのコードの画面・注文の入力と確認・ご注文の状況・App の会員の画面と申し込みの流れ）は印 `navigation-only` で、ブラウザが画面を開く要求のほか（fetch・XHR・枠）を中身の無い 403 にし、キャッシュにも残さず、`window.open` の窓からも読めない。タグの App のスクリプトはこれらの画面を読もうとしない（読めない）。Sec-Fetch を送らない古いブラウザでは効かない
- **URL に秘密を入れない**: どのタグもページの URL をそのまま送る。タグを入れる画面の URL に、パスワードの再設定の印や注文の閲覧の印を入れない（今の完了の画面は `/checkout/complete` で、印は無い）

---

## 1. GA4（Google アナリティクス 4、gtag.js）

1. **公式の資料**
   - 設置: https://developers.google.com/tag-platform/gtagjs/install
   - CSP: https://developers.google.com/tag-platform/security/guides/csp
   - 購入（e コマース）: https://developers.google.com/analytics/devguides/collection/ga4/ecommerce
   - 同意モード: https://developers.google.com/tag-platform/security/guides/consent ・ 基本と高度の違い: https://developers.google.com/tag-platform/security/concepts/consent-mode
   - 測定 ID: https://support.google.com/analytics/answer/12270356
2. **ID の形**: 「`G-` の後に数字と英字」【公式】。正規表現 `^G-[A-Z0-9]{4,20}$`（大文字だけ・長さは【推測】。入力は大文字に直してから確認する）
3. **読み込み**: `https://www.googletagmanager.com/gtag/js?id=ID` を async で読み、インラインで `dataLayer` と `gtag()` を作り、`gtag('js', new Date())`・`gtag('config', ID)` を呼ぶ【公式】。外部ファイルに移せるかは公式の記述は無いが、中身から移せる【読み取り】
4. **CSP**（公式の案内は Tag Manager 向けの節の「GA4」。gtag.js も同じ配信元から読む）【公式】
   - Google の広告の機能（Google シグナルなど）を使わない場合: script-src `https://www.googletagmanager.com` ／ img-src `https://www.googletagmanager.com https://*.google-analytics.com` ／ connect-src `https://www.googletagmanager.com https://*.google-analytics.com https://*.google.com`
   - 広告の機能を使う場合: 上に加えて img-src・connect-src に `https://*.google.com https://*.google.<TLD> https://*.g.doubleclick.net`、connect-src に `https://pagead2.googlesyndication.com`、**frame-src `https://www.googletagmanager.com`**
   - `<TLD>` は国ごとに変わる。日本なら `*.google.co.jp` を足す【推測】。マニフェストには最初は「広告の機能を使わない」形だけ書く
5. **購入**: `gtag('event', 'purchase', { transaction_id, value, currency, items: [{ item_id, quantity }] })`。`transaction_id` は購入に必須、items は `item_id` か `item_name` のどちらか、`quantity` を省くと 1【公式】。**注文の番号・金額・通貨・品の ID と数だけで送れる**。金額は税と送料を含めるかを決める（`tax`・`shipping` は任意）
6. **同意**: 同意モード v2（`ad_storage`・`analytics_storage`・`ad_user_data`・`ad_personalization`）。`gtag('consent', 'default', {...})` を config より前に、選ばれたら `'update'`。**基本の同意モードは「バナーで選ぶまでタグを読まない」**、高度は読み込んで Cookie 無しで送る【公式】。こちらは基本の形。読み込むときに「解析」の同意に合わせて `analytics_storage: 'granted'`、広告の同意が無ければ `ad_*` は `'denied'` で始める
7. **Trusted Types**: 公式の記述は無い
8. **サーバーから**: Measurement Protocol https://developers.google.com/analytics/devguides/collection/protocol/ga4 （タグの「補い」で、置き換えではない【公式】）
9. **外部送信の公表**: Google LLC ／ ページの URL・参照元・端末とブラウザの情報・Cookie の識別子・購入の情報（注文番号・金額・品の ID と数） ／ アクセスの解析 ／ https://policies.google.com/privacy?hl=ja 、https://policies.google.com/technologies/partner-sites?hl=ja

## 2. Google 広告（コンバージョンとリマーケティング）

1. **公式の資料**
   - コンバージョンのタグ: https://support.google.com/google-ads/answer/6331314
   - 取引 ID で重複を減らす: https://support.google.com/google-ads/answer/6386790
   - 動的リマーケティングの値: https://support.google.com/google-ads/answer/7305793
   - CSP・同意モードは GA4 と同じページ
   - タグ ID の例（`GT-`・`G-`・`AW-`）: https://support.google.com/tagmanager/answer/12326985
2. **ID の形**: コンバージョン ID は例が `AW-123456789`、ラベルは例が `AbC-D_efG-h12_34-567`【読み取り。公式に規則は無い】。正規表現 `^AW-[0-9]{6,15}$` と `^[A-Za-z0-9_-]{1,64}$`（長さは【推測】）
3. **読み込み**: GA4 と同じ gtag.js（`gtag('config', 'AW-...')`）【公式】。外部ファイルに移せる【読み取り】
4. **CSP**（コンバージョン・リマーケティング・コンバージョンリンカー）【公式】
   - script-src `https://www.googletagmanager.com https://www.googleadservices.com https://www.google.com`
   - img-src `https://www.googletagmanager.com https://www.googleadservices.com https://googleads.g.doubleclick.net https://pagead2.googlesyndication.com https://www.google.com https://www.google.<TLD>`
   - connect-src 上と同じ ＋ `https://ad.doubleclick.net`
   - **frame-src `https://www.googletagmanager.com`**
   - 「ユーザーのデータのビーコン」用に connect-src `https://www.google.com` も載っている。拡張コンバージョンを使わないなら要るかは【推測】で要確認
5. **購入**: `gtag('event', 'conversion', { send_to: 'AW-ID/ラベル', value, currency, transaction_id })`【公式】。取引 ID は重複を減らすため【公式】。リマーケティングは `items: [{ id, google_business_vertical: 'retail' }]`、id は Merchant Center の商品の id と合わせる【公式】。**番号・金額・通貨・品の ID だけで送れる**。拡張コンバージョン（メールなどを送る）は使わない。Google タグの管理画面の「ユーザー提供データ」の自動検出も切ってもらう（https://support.google.com/google-ads/answer/14662970）
6. **同意**: GA4 と同じ同意モード v2。広告の同意で `ad_storage`・`ad_user_data`・`ad_personalization` を `'granted'`
7. **Trusted Types**: 公式の記述は無い
8. **サーバーから**: Google 広告 API のコンバージョンの取り込み https://developers.google.com/google-ads/api/docs/conversions/overview
9. **外部送信の公表**: Google LLC ／ ページの URL・参照元・端末の情報・Cookie の識別子・広告のクリックの識別子・購入の情報 ／ 広告の効果の測定と、広告の配信（リマーケティング） ／ GA4 と同じページ

**決めること**: GA4 と Google 広告は、同じ `window.gtag`・`dataLayer` と同じ同意の状態を使う。App を分けたまま、gtag の読み込みと同意の受け渡しを 1 か所（共通の小さな部品）にまとめるか

## 3. Meta ピクセル（Facebook・Instagram 広告）

1. **公式の資料**
   - 設置: https://developers.facebook.com/docs/meta-pixel/get-started
   - 標準のイベント: https://developers.facebook.com/docs/meta-pixel/reference
   - 同意: https://developers.facebook.com/docs/meta-pixel/implementation/gdpr
   - 自動の詳細マッチング: https://www.facebook.com/business/help/1993001664341800
2. **ID の形**: 公式に形の記述は無い。数字だけ【推測】。正規表現 `^[0-9]{10,20}$`【推測】
3. **読み込み**: インラインで `fbq` のキューを作り、`https://connect.facebook.net/en_US/fbevents.js` を async で差し込み、`fbq('init', ID)`・`fbq('track', 'PageView')`【公式】。`<noscript>` の画像 `https://www.facebook.com/tr?id=…&ev=PageView&noscript=1` は使わない。外部ファイルに移せる【読み取り】
4. **CSP**: **公式の CSP の案内は無い**。【読み取り】script-src `https://connect.facebook.net`（fbevents.js がさらに `signals/config/` の設定のスクリプトを読む。配信元の名前はファイルの中で読み取れず、同じ `connect.facebook.net` と【推測】）／ img-src・connect-src `https://www.facebook.com`（`/tr` へ送る）／ frame-src は要らない見込み【推測】
5. **購入**: `fbq('track', 'Purchase', { value, currency, contents: [{ id, quantity }], content_type: 'product' })`。必須は value と currency、contents の中は id と quantity が必須【公式】。注文の番号の項目は無い。サーバーからも送るときは 4 つめの引数の `eventID` で重複を除く【公式】。**番号（eventID として）・金額・通貨・品の ID と数だけで送れる**。管理画面の「自動の詳細マッチング」（入力欄からメールなどを拾う）を切ってもらう【公式に設定がある】
6. **同意**: `fbq('consent', 'revoke')` で送信を止め、`fbq('consent', 'grant')` で再開【公式】。読み込みを同意の後にする書き方は公式に無いが、禁じてもいない
7. **Trusted Types**: 公式の記述は無い。【読み取り】fbevents.js は `trustedTypes.createPolicy` で自前のポリシー（`createScriptURL`）を作ってからスクリプトを読む。ポリシー名は要確認
8. **サーバーから**: Conversions API https://developers.facebook.com/docs/marketing-api/conversions-api
9. **外部送信の公表**: Meta Platforms, Inc. ／ ページの URL・参照元・端末の情報・Cookie の識別子・ボタンのクリックなどの操作・購入の情報 ／ 広告の効果の測定と配信 ／ https://www.facebook.com/privacy/policy/

## 4. LINE タグ（LINE 広告）— 作らない

1. **公式の資料**: https://www.lycbiz.com/jp/manual/line-ads/tracking_001/ （ベースコード tracking_002、コンバージョンコード tracking_003）。**コードの本文は公開されていない（管理画面で取る）**【公式】
   - LINE 広告の配信の終了: https://www.lycbiz.com/jp/support/line-ads/ （「2026年10月26日でLINE広告の広告配信を終了」）
   - マイグレーションの案内: https://www.lycbiz.com/jp/column/ly-ads/displayads-auc/service-information/migration-guide/ （LINE Tag は統合後のディスプレイ広告に対応しない。計測タグを新たに設定）
   - LINE 公式アカウントの LINE Tag も「将来的に提供を終了」、計測タグを使うよう案内: https://www.lycbiz.com/jp/manual/OfficialAccountManager/line-tag
2. **ID の形**: 公式に記述は無い
3. **読み込み**: 【読み取り】ローダー `https://d.line-scdn.net/n/line_tag/public/release/v1/lt.js` は、さらに `https://tag.flvcdn.net/ltag.js` を読み、`https://tr.line.me/tag.gif` へ画像で送る
4. **CSP**: 公式の CSP の案内は無い
5. **購入**: 金額や注文番号を送る方法は、公開のページでは確認できなかった（ヘルプは JavaScript で描く作りで本文を読めず）
6. **同意**: 公式の記述は見つからない
7. **Trusted Types**: 公式の記述は無い（【読み取り】`script.src` に文字を入れる。js-sha256 の部品に `eval` がある）
8. **サーバーから**: LINE Conversion API https://developers.line.biz/en/docs/line-conversion-api/ （トークン: https://www.lycbiz.com/jp/manual/BusinessManager/conversion-api/ ）
9. **外部送信の公表**: LINEヤフー株式会社（次の節と同じ）

## 5. LINEヤフー広告（旧 Yahoo! 広告。検索広告とディスプレイ広告、LINE 面を含む）

**2 つの形がある**【公式】

- **計測タグ（新しい形）**: ディスプレイ広告・検索広告（ショッピング）・LINE 公式アカウントで使える。グローバルスニペット（全ページ、1 ページに 1 つ）＋ イベントスニペット
- **従来の形**: サイトジェネラルタグ（全ページ、`<head>` の直後）＋ コンバージョン測定タグ（完了の画面）。**検索広告（ショッピング以外）は今もこちらの手順**。ショッピングの従来版の新規作成は 2026-10-06 に終了、計測もいずれ終える予定（時期は未定）。同じページに両方置くと重複して数える恐れ

1. **公式の資料**
   - 計測タグの開発者向けの案内: https://ads-developers.yahoo.co.jp/ja/lytag/ （概要 post/30590623.html、スタートアップ post/30590626.html、イベントの一覧 post/30590632.html、**パラメータ post/30590635.html**）
   - 計測タグの発行と設置: https://ads-help.yahoo-net.jp/s/article/H000054773?language=ja
   - 計測タグでのコンバージョン測定（ディスプレイ）: https://ads-help.yahoo-net.jp/s/article/H000054774?language=ja
   - 計測タグとコンバージョン API への対応（検索広告ショッピング、2026-08-26）: https://www.lycbiz.com/jp/news/ly-ads/20260818-01/
   - 統合のお知らせ（2026-04-01）: https://www.lycbiz.com/jp/news/20260218/
   - 従来のサイトジェネラルタグ: https://ads-help.yahoo-net.jp/s/article/H000044574?language=ja ／ 検索広告のコンバージョン測定: https://ads-help.yahoo-net.jp/s/article/H000046007?language=ja ／ ショッピングの例（ytag の本文あり）: https://ads-help.yahoo-net.jp/s/article/H000048550?language=ja
   - 計測タグ利用規約: https://www.lycbiz.com/sites/default/files/media/jp/terms-and-policies/pdf/ly/tag.pdf
2. **ID の形**
   - 計測タグ: `tagId` は「文字列」とだけある【公式】。形は不明。**実アカウントで発行して確認する**
   - 従来の形: `yahoo_conversion_id` は例が `1000178837`（数字 10 桁）、`yahoo_conversion_label` は例が `e5y9CLnUlawBEJ2kiMsD`【読み取り】。正規表現 `^[0-9]{6,12}$`・`^[A-Za-z0-9_-]{1,64}$`【推測】
3. **読み込み**
   - 計測タグ: **スニペットの本文（読み込む JS の URL・関数名）は公開の資料に無い**。管理画面（ビジネスマネージャーか広告管理ツール）でコピーする【公式】。設定は `type: 'init'`・`tagId`・`config`（`useCookie`・`useLocalStorage` など）、イベントは `type: 'event'`・`eventType`・`tagId`・`config`【公式】
   - 従来の形: `ytag({ type: 'yss_ssa_conversion', config: {...} })` を `<script async>` で書く例がある【公式】。ytag の本体を読む部分は公式の文字の資料に無い（画像）
   - どちらも外部ファイルに移せるかは、本文を取ってから確認する
4. **CSP**: **公式の CSP の案内は無い**。宛先は、スニペットを取ってから読み取る
5. **購入**（計測タグ）: `eventType: 'purchase'`、`config` に `transactionId`（アカウント ID と合わせて 7 日間の重複除き）・`value`（指定するなら `currency` 必須）・`currency`（`JPY` か `USD`）・`items`（最大 10。`itemId`〔100 文字以内、英数字・`-`・`_`〕・`price`・`quantity`〔整数〕）【公式】。**番号・金額・通貨・品の ID と数だけで送れる**。`email`・`phoneNumber`（詳細マッチング）は使わない。従来の形は金額をタグの書き換えで渡す【公式】
6. **同意**: 同意の状態を伝える仕組みの記述は見つからない。`isExternalTransmission`（既定は true）を false にすると「パートナー企業へ送らない」【公式】。既定で false にするか決めること
7. **Trusted Types**: 公式の記述は無い
8. **サーバーから**: コンバージョン API https://ads-developers.yahoo.co.jp/ja/conversion-api/
9. **外部送信の公表**: LINEヤフー株式会社 ／ ページの URL・参照元・端末の情報・Cookie とローカルストレージの識別子・購入の情報 ／ 広告の効果の測定と配信（サイトリターゲティング） ／ https://www.lycorp.co.jp/ja/company/privacypolicy/ 、https://privacy.lycorp.co.jp/ja/
10. **作ったときに読み取ったこと**【読み取り】（2026-10-29。LINEヤフーが公開する Google タグ マネージャーのテンプレート〔yahoojp-marketing の gtm-template-tag-global-snippet・gtm-template-tag-event-snippet〕と、lytag.js〔最終更新 2026-10-15〕から。`plugin-lineyahoo-ads`）
   - 読み込み先は `https://tag.flvcdn.net/lytag.js`。キューの関数 `lytag` が `lyDataLayer` に積み、読めたら順に処理する
   - 送信は fetch で `https://apm.yahoo.co.jp` へ。応答のヘッダー（`X-Z-BurlS`）にある別の宛先へも送ろうとするが、マニフェストに書いていない宛先は CSP で止まる
   - `tagId` の形は UUID の第 4 版（36 文字）。lytag.js も同じ形でなければ何もしないので、App の設定もこの形で確認する
   - lytag.js は自前の Trusted Types の関門を作らず、2 段目のスクリプトも読まない（createElement・innerHTML・eval が無い）。App も自前の関門は作らず、コアの既定の関門にまかせる
   - App では `isExternalTransmission: false`（パートナー企業へ送らない。上の 6 の「決めること」はこれで決めた）、初期化の `useCookie`・`useLocalStorage` は true（lytag.js の既定は残さない。残さないと、広告を押した後の別のページでの購入が数えられない）。`email`・`phoneNumber` は渡さない。金額は JPY と USD のときだけ付け、品は 10 個まで（11 個以上は lytag.js が品を全部捨てる）

## 6. TikTok ピクセル

1. **公式の資料**
   - 始め方: https://ads.tiktok.com/help/article/get-started-pixel ／ 概要（集める情報）: https://ads.tiktok.com/help/article/tiktok-pixel
   - 標準のイベント: https://ads.tiktok.com/help/article/standard-events-parameters
   - Cookie: https://ads.tiktok.com/help/article/using-cookies-with-tiktok-pixel 、https://ads.tiktok.com/help/article/how-to-use-cookies-with-tiktok-pixel
   - **ベースコードの本文は公開のヘルプでは見つからない**（Events Manager で取る）
2. **ID の形**: 公式に記述は無い。英大文字と数字 20 文字ほど【推測】。正規表現 `^[A-Z0-9]{15,25}$`【推測】
3. **読み込み**: 【読み取り】`https://analytics.tiktok.com/i18n/pixel/events.js` が、さらに `https://analytics.tiktok.com/i18n/pixel/static/main.<文字>.js` を `script.src` で読む。`ttq.load(ID)`・`ttq.page()` という呼び方は公式の公開ページでは確認できなかった
4. **CSP**: **公式の CSP の案内は無い**。【読み取り】script-src `https://analytics.tiktok.com`。送信先は main の中にあり読めていない。Cookie の表に `analytics.pangle-ads.com`（サードパーティー Cookie）がある【公式】ので、connect-src・img-src の候補【推測】
5. **購入**: 標準のイベントは `Purchase`（`CompletePayment` は今の表に無い）。おすすめの値は `content_type`・`quantity`・`description`・`content_ids`・`currency`・`value`【公式】。注文の番号の項目は表に無い。重複の除き（event_id）は別のページ https://business-api.tiktok.com/portal/docs?id=1739584864945154 。**金額・通貨・品の ID と数だけで送れる**
6. **同意**: 公式は「タグマネージャーや同意のツールでベースコードの読み込みを制御する」「TikTok のピクセルの同意モード」と書くが、**呼び出しの書き方（holdConsent など）は公式の資料で見つからない**。同意の後に読む形なら足りる
7. **Trusted Types**: 公式の記述は無い（【読み取り】ローダーは `script.src` に文字を入れる）
8. **サーバーから**: Events API https://ads.tiktok.com/help/article/events-api
9. **外部送信の公表**: TikTok の事業者（日本の利用者向けの運営の会社名は、公開の前にプライバシーポリシーで確認する）／ 広告と操作の情報・時刻・IP アドレス・ブラウザの情報・Cookie・ページのメタデータ・ボタンのクリック【公式】・購入の情報 ／ 広告の効果の測定と配信 ／ https://www.tiktok.com/legal/page/row/privacy-policy/ja-JP 【推測。公開の前に確認する】
10. **作ったときに読み取ったこと**【読み取り】（2026-10-29。events.js と 2 段目の main から。`plugin-tiktok-pixel`）
   - 読み込み先は `https://analytics.tiktok.com/i18n/pixel/events.js`、続いて `https://analytics.tiktok.com/i18n/pixel/static/main.<文字>.js`。マニフェストは script-src・connect-src・img-src とも `https://analytics.tiktok.com` だけ。IPv6 の補い（`analytics-ipv6.tiktokw.us`）は書いていないので止まる
   - events.js はキューの名前を `window.TiktokAnalyticsObject` から、読み込みの時刻や設定を `ttq._i`・`_t`・`_o` から読む。ID の形は上の 2 のまま（英大文字と数字 15〜25 文字）
   - どちらのスクリプトも自前の Trusted Types の関門を作らず、続くスクリプトを文字の URL で `src` に入れる。App も自前の関門は作らず、コアの既定の関門にまかせる
   - 同意: スクリプトに `holdConsent`・`revokeConsent`・`grantConsent` がある。App は同意の後に読み、`ttq.grantConsent()` を呼ぶ。照合の `identify` は使わない。購入は注文の番号を event_id として送り、同じタブでは二度送らない（ブラウザの送信は番号だけでは重複が除かれない）

## 7. X（旧 Twitter）ピクセル

1. **公式の資料**
   - ヘルプ: https://business.x.com/en/help/campaign-measurement-and-analytics/conversion-tracking-for-websites （**ボット除けで本文を取得できなかった**。検索の抜粋でだけ確認）
   - 開発者向け（Conversion API）: https://docs.x.com/x-ads-api/measurement/web-conversions
2. **ID の形**: 公式の例はピクセル ID `oka17`、イベント ID `ol288`【公式の例】。イベントのタグは `tw-<ピクセル ID>-<イベント ID>`（検索の抜粋）。正規表現 `^[a-z0-9]{4,8}$`【推測】
3. **読み込み**: 【読み取り】`https://static.ads-twitter.com/uwt.js`。`twq` は `config`・`event`・`init`・`track`・`set` を受ける
4. **CSP**: **公式の CSP の案内は無い**。【読み取り】script-src `https://static.ads-twitter.com` ／ 送信先 `https://t.co`・`https://analytics.x.com`（ドメインによって `https://analytics.twitter.com`）を img-src・connect-src に。**iframe のピクセルを作る部分がある**ので frame-src も要るかもしれない（宛先は【推測】`https://t.co`）
5. **購入**: `twq('event', 'tw-…-…', { value, currency, conversion_id, contents: [{ content_id, num_items }] })`。`conversion_id` は重複の除き【公式の抜粋・開発者向けの案内】。**番号・金額・通貨・品の ID と数だけで送れる**見込み
6. **同意**: 公式の記述は見つからない
7. **Trusted Types**: 公式の記述は無い（【読み取り】`script.src` に文字を入れる部分、`new Function` の予備がある）
8. **サーバーから**: Conversion API（上の docs.x.com）。**クリックの識別子・メール・電話のどれか 1 つが必須**【公式】なので、メール無しならクリックの識別子（`twclid`）を保存しておく必要がある
9. **外部送信の公表**: X Corp.（日本の利用者向けの事業者名は公開の前に確認する）／ ページの URL・参照元・端末の情報・Cookie の識別子・購入の情報 ／ 広告の効果の測定と配信 ／ https://x.com/ja/privacy
10. **作ったときに読み取ったこと**【読み取り】（2026-10-29。uwt.js 2.4.12〔最終更新 2026-10-13〕から。`plugin-x-pixel`）
   - 読み込み先は `https://static.ads-twitter.com/uwt.js`。送信は sendBeacon（止まれば画像）で `https://t.co` と `https://analytics.twitter.com` へ（ショップが x.com でなければ analytics.twitter.com を使う）。マニフェストの connect-src・img-src はこの 2 つで、frame-src は書かない
   - ID はピクセル ID（英小文字と数字、例 `oka17`）と、購入のイベント ID（`tw-oka17-ol288` の最後の `ol288`）を別の設定にした。イベント ID が空なら購入は送らない
   - uwt.js は自前の Trusted Types の関門を作らない。2 段目のスクリプトと iframe を作るのは古い呼び出し（`twttr.conversion` の buildScript・buildIframe）だけで、App は使わない（使っても関門と CSP で止まる）。`new Function` は globalThis が無いときの予備だけ
   - **自動の照合を止めた**: uwt.js は、ページの入力欄に文字があれば送信のたびにメール・電話・名前を拾う。App は `restricted_data_use: 'restrict_optimization'`（データの使い方の制限）を config と購入に付けて、これを止める。その代わり X は送った情報を広告の配信の最適化に使わない
   - 同意の API は uwt.js に見当たらない。同意の後に読むことだけで守る。`conversion_id` での重複の除きは確認きれていないので、同じタブでは二度送らない

## 8. Microsoft Clarity（ヒートマップと画面の録画）

1. **公式の資料**
   - 設置: https://learn.microsoft.com/en-us/clarity/setup-and-installation/clarity-setup
   - CSP: https://learn.microsoft.com/en-us/clarity/setup-and-installation/clarity-csp
   - 同意（ConsentV2）: https://learn.microsoft.com/en-us/clarity/setup-and-installation/clarity-consent-api-v2
   - 隠す設定（マスク）: https://learn.microsoft.com/en-us/clarity/setup-and-installation/clarity-masking
2. **ID の形**: 公式に記述は無い。公式のデモのプロジェクト ID は `3t0wlogvdz`【公式の例】。正規表現 `^[a-z0-9]{8,12}$`【推測】
3. **読み込み**: 公式は「トラッキングコードをコピーして `<head>` に」とだけ書く（本文は管理画面）【公式】。npm の部品もある。外部ファイルに移せるかは、本文を取ってから確認する
4. **CSP**【公式】: 例は `default-src 'self' https://*.clarity.ms https://c.bing.com 'unsafe-inline'`。個別に書くなら `https://www.clarity.ms`・`https://c.bing.com`・`https://[a-z].clarity.ms`。ショップは各行を個別に書くので、script-src・connect-src・img-src に `https://*.clarity.ms https://c.bing.com` を足す。例の `'unsafe-inline'` はインラインのスニペットのためで、こちらは外部ファイルにするので足さない
5. **購入**: 無い（解析だけ。購入の値は渡さない）
6. **同意**: `window.clarity('consentv2', { ad_Storage: 'granted', analytics_Storage: 'granted' })`。断られたら Cookie を使わない形で動く。`clarity('consent', false)` で Cookie を消す。2025-10-31 から EEA・英国・スイスでは同意の信号が必須【公式】
7. **Trusted Types**: 公式の記述は無い
8. **サーバーから**: 無い
9. **外部送信の公表**: Microsoft Corporation ／ ページの URL・画面の操作（クリック・スクロール・マウスの動き）・画面の内容（隠した部分を除く）・端末の情報・Cookie の識別子 ／ サイトの使いやすさの解析 ／ https://privacy.microsoft.com/ja-jp/privacystatement

**注意**【公式】: 18 歳未満を対象にするサイトで使ってはいけない。入力欄とプルダウンは常に隠す。既定の「Balanced」は数字とメールだけ隠す。→ App の設定の説明で「Strict」をすすめ、購入の完了の画面には入れない（または完了の画面に個人情報を出さない約束をテーマ API に入れる）

## 9. Microsoft 広告（UET）

1. **公式の資料**
   - 設置のまとめ: https://learn.microsoft.com/en-us/advertising/msa-help/hlp_ba_conc_uet_setup_master
   - e コマースのイベントの例: https://learn.microsoft.com/en-us/advertising/msa-help/hlp_ba_proc_uet_exampleeventsecommerce
   - 同意モード: https://learn.microsoft.com/en-us/advertising/msa-help/hlp_ba_conc_uet_consent ・ よくある質問: https://learn.microsoft.com/en-us/advertising/msa-help/hlp_ba_conc_uet_consentfaq
2. **ID の形**: 公式はスニペットの `ti:"TAG_ID_HERE"` だけ。数字【推測】。正規表現 `^[0-9]{5,12}$`【推測】
3. **読み込み**: インラインで `//bat.bing.com/bat.js` の `<script>` を作り、読めたら `new UET({ ti, enableAutoSpaTracking: true })` を `window.uetq` に入れて `pageLoad` を送る【公式】。外部ファイルに移せる【読み取り】（`//` は https に直す）
4. **CSP**: **公式の CSP の案内は無い**（Q&A の掲示板にだけ記述）。【読み取り】script-src `https://bat.bing.com`（`/p/action/<ID>.js` も読む）／ img-src・connect-src `https://bat.bing.com https://bat.bing.net` ／ UET と Clarity の接続を有効にすると Clarity も読むので、**UET のタグの設定で「Clarity を有効にする」を外してもらう**（同意も別に要る【公式】）
5. **購入**: `uetq.push('event', 'purchase', { transaction_id, ecomm_prodid: [...], ecomm_pagetype: 'purchase', ecomm_totalvalue, revenue_value, currency, items: [{ id, quantity, price }] })`【公式】。**番号・金額・通貨・品の ID と数（と単価）だけで送れる**
6. **同意**: `uetq.push('consent', 'default', { ad_storage: 'denied' })` → `'update'`。基本（同意まで読まない）と高度の両方を案内し、**Microsoft は高度をすすめる**。よくある質問には「UET はバナーの操作の前に読む必要がある（既定は denied）」ともある【公式】。日本では同意モードの強制は無い（強制は EEA・英国・スイス）【公式】。こちらの「同意の後に読む」は公式の言う基本の形にあたる
7. **Trusted Types**: 公式の記述は無い（【読み取り】`script.src` に文字を入れる。HTML の差し込みは無い）
8. **サーバーから**: Conversions API https://learn.microsoft.com/en-us/advertising/guides/uet-conversion-api-integration
9. **外部送信の公表**: Microsoft Corporation ／ ページの URL・参照元・端末の情報・Cookie の識別子（MUID・_uetsid・_uetvid）・購入の情報 ／ 広告の効果の測定と配信（リマーケティング） ／ https://privacy.microsoft.com/ja-jp/privacystatement
10. **作ったときに読み取ったこと**【読み取り】（2026-10-29。bat.js から。`plugin-microsoft-uet`）
   - 読み込み先は `https://bat.bing.com/bat.js`（公式の `//` は https に直す）、続いて `https://bat.bing.com/p/action/<タグ ID>.js`。送信先は `bat.bing.com`・`bat.bing.net`（上の 4 のとおり）
   - bat.js は自前の Trusted Types の関門を作らず、2 段目を文字の URL で `src` に入れる。App も自前の関門は作らず、コアの既定の関門にまかせる
   - UET のタグの設定で Clarity を接続していると `clarity.microsoft.com` も読もうとするが、宛先に書いていないので止まる（Clarity は別の App で、解析の同意の後に入れる）
   - 画面は毎回サーバーが描くので `enableAutoSpaTracking` は使わない。拡張コンバージョンの照合（pid）は送らない

## 10. Pinterest タグ

1. **公式の資料**
   - 開発者向け: https://developers.pinterest.com/docs/track-conversions/pinterest-tag/
   - イベントのコード: https://help.pinterest.com/en/business/article/add-event-codes
   - Conversions API: https://developers.pinterest.com/docs/track-conversions/track-conversions-in-the-api/
   - 日本での広告: https://business.pinterest.com/blog/pinterest-ads-launch-in-japan/
2. **ID の形**: 例は `123456789`・`0123456789012`（数字）【公式の例】。正規表現 `^[0-9]{9,16}$`【推測】
3. **読み込み**: インラインで `pintrk` のキューを作り、`https://s.pinimg.com/ct/core.js` を async で読む。`pintrk('load', ID)`・`pintrk('page')`【公式】。`load` にメールを渡す詳細マッチング（`em`）は使わない。`<noscript>` の画像 `https://ct.pinterest.com/v3/?tid=…&noscript=1` は使わない。外部ファイルに移せる【読み取り】
4. **CSP**: **公式の CSP の案内は無い**。【読み取り】script-src `https://s.pinimg.com`（`/ct/lib/main.<8 桁>.js` も読む）／ connect-src・img-src `https://ct.pinterest.com`
5. **購入**: `pintrk('track', 'checkout', { order_id, value, currency, order_quantity, line_items: [{ product_id, product_quantity }] })`。`order_id` は分析のために必須とヘルプにある【公式】。**番号・金額・通貨・品の ID と数だけで送れる**
6. **同意**: 同意の状態を伝える仕組みの記述は見つからない（自社の Cookie・ローカルストレージを使わない `fp_cookie: false` などはある【公式】）
7. **Trusted Types**: 公式の記述は無い（【読み取り】`script.src` に文字を入れる。同期の XMLHttpRequest で記録を送る部分がある）
8. **サーバーから**: Conversions API（上の URL）
9. **外部送信の公表**: Pinterest の事業者（日本の利用者向けの事業者名は公開の前に確認する）／ ページの URL・参照元・端末の情報・Cookie の識別子・購入の情報 ／ 広告の効果の測定と配信 ／ https://policy.pinterest.com/ja/privacy-policy 【推測。公開の前に確認する】
10. **作ったときに読み取ったこと**【読み取り】（2026-10-29。core.js〔c61fa7c9〕と 2 段目の main から。`plugin-pinterest-tag`）
   - 読み込み先は `https://s.pinimg.com/ct/core.js`、続いて `https://s.pinimg.com/ct/lib/main.<8 桁>.js`。送信先は `https://ct.pinterest.com`（上の 4 のとおり）
   - core.js は自前の Trusted Types の関門を作らず、2 段目を文字の URL で `src` に入れる。App も自前の関門は作らず、コアの既定の関門にまかせる
   - main は Chrome で `ct.pinterest.com` の `token_create.js` と iframe の `ct.html` も読もうとするが、マニフェストに書いていないので止まる（Cookie の受け渡しの補いが動かないだけで、計測の送信は届く）
   - **innerHTML の所**: ページの構造化データを拾う所が、コピーの要素へ `innerHTML` を入れる。Trusted Types の関門で止まり、Pinterest の中で握りつぶされる（「先に結論」の 4 の「HTML の差し込みは無かった」の例外）
   - 同意の API は無いので、同意の後に読むことが「同意あり」にあたる。照合の `em` は渡さない。購入は注文の番号を `order_id` と重複を除く `event_id` に入れ、同じタブでは二度送らない

---

## 作る順のおすすめ

1. **GA4** — 公式の資料（CSP の宛先・購入・同意モード・ID の形）が全部そろっている。コアの 3 つの口（差し込み・CSP・設定の画面）と同意・既定の関門を、まずこれで通す
2. **Google 広告** — GA4 と同じ gtag と同意の仕組み。共通の部分をどうまとめるかを決めてから
3. **Microsoft Clarity** — 公式の CSP と同意の API がある。購入の値を渡さないので簡単。ただし録画の注意（Strict・完了の画面）を App の説明に入れる
4. **Meta ピクセル** — 日本の EC でよく使う。購入と同意の API は公式にある。CSP の宛先は読み取りで、作るときに report-only で確認する。Trusted Types の自前のポリシーを確認する
5. **LINEヤフー広告（計測タグ）** — 日本では大事（LINE 面もここ）。ただし**スニペットの本文が公開されていないので、試用のアカウントで計測タグを発行してから**。検索広告（ショッピング以外）の従来の形をどうするかも、そのとき決める
6. **Pinterest タグ**・**Microsoft 広告（UET）** — 公式の購入の資料はそろう。CSP は読み取り
7. **TikTok ピクセル** — ベースコードと同意の書き方が公開の資料に無い。Events Manager で取ってから
8. **X ピクセル** — 公式のヘルプが取得できず、確認できない点が多い。最後
- **LINE タグは作らない**（LINE 広告の終了。上の 4 節）

## 公式の資料が見つからなかった・あいまいだった点

| タグ | 点 |
|---|---|
| 全部 | Trusted Types の下で動くかの公式の記述は、どの会社にも無い |
| GA4・Google 広告 | 公式の CSP の案内は Tag Manager 向けの節。gtag.js も同じ宛先で足りるかは作るときに確認する。`*.google.<TLD>` の国の数 |
| Google 広告 | コンバージョン ID とラベルの形の公式の規則は無い（例だけ） |
| Meta | CSP の案内が無い。ピクセル ID の形が無い。fbevents.js の 2 段目の読み込み先と、Trusted Types のポリシー名 |
| LINE タグ | コードの本文・金額の送り方が公開のページに無い（ヘルプが JavaScript で描く作り）。作らないので深追いしない |
| LINEヤフー広告 | **計測タグのスニペットの本文（読み込む JS の URL・関数名）が公開されていない**。`tagId` の形。CSP。同意の仕組み。`email`・`phoneNumber` をハッシュにするかの記述も無い。検索広告（ショッピング以外）がいつ計測タグに対応するか。従来版の計測の終了の時期（未定） |
| TikTok | ベースコードの本文・ピクセル ID の形・同意の API（holdConsent などは第三者の資料にだけある）・送信先のドメイン |
| X | 公式のヘルプがボット除けで読めない（検索の抜粋だけ）。同意・CSP・iframe の宛先 |
| Clarity | プロジェクト ID の形。トラッキングコードの本文（管理画面で取る） |
| Microsoft 広告 | CSP の公式の案内（掲示板の回答にだけ `bat.bing.com`・`bat.bing.net`）。タグ ID の形。「同意の前に読み込む必要がある」との記述と、基本の同意モードの案内が同じ資料に並んでいる |
| Pinterest | 通貨: 開発者向けの資料は `USD GBP CAD EUR AUD NZD` だけを挙げ、ヘルプは `JPY` を含むと書く。**日本円で送れるかは作るときに確認する**。同意の仕組み |
| 外部送信の公表 | TikTok・X・Pinterest の、日本の利用者に対する事業者の正式な名前とプライバシーポリシーの URL は、公開の前に各社の案内で確認する（文の雛形は専門家に確認する、は ADR 0034 のまま） |

---

## 次のまとまり（2026-10-30 調べ）

調べた日: **2026-10-30**。公式の資料と、各社の配信元から取ったスクリプト（同じ日に取得）を読んだ。**実際に店で動かして確認するのは作るとき**。印は上の「読み方」と同じ。加えて:

- 【公式の抜粋】= 公式のページが自動の取得を断る（Reddit・Snapchat・Hotjar のヘルプ）ので、検索で出た公式のページの抜粋でだけ確認した。取得の制限を回り道して読むことはしていない。作る前に人がブラウザで本文を確認する

### 先に結論（次のまとまり）

1. **日本の EC で効きそうなのは SmartNews 広告・アフィリエイト（A8.net・バリューコマース）・Criteo**。LinkedIn・Reddit・Snapchat・Amazon は、公式の資料はおおむねあるが日本の小さな店では使われにくい（理由は各節）
2. **アフィリエイトは成果の通知の仕組みが違う**: 広告のクリックでランディングページの URL にクリック IDが付く → 店がそれを保存 → 注文のときに印と注文を送る → **後から店が成果を承認・否認する**（A8.net は 30 日、バリューコマースは 45 日で自動で承認【公式】）。A8.net とバリューコマースには、**ブラウザのタグを使わずに店のサーバーから成果を送る形が公式にある**（A8.net のセッショントラッキングと確定 API、バリューコマースの iTrack2.0 の URL リクエスト通知）。コアの注文と取消・返金から、承認・否認まで自動にできる。ただし今の App の口（ブラウザへの差し込み・CSP・設定）では作れない。コアに「ランディングページでクリック IDを受け取って保存する口」と「注文の出来事（支払いの確定・取消・返金）でサーバーから外へ送る口」が要る → **作る前に ADR を足す**
3. **同意とアフィリエイト**: ブラウザのタグの形だと、同意バナーを出す店では（ADR 0034 の 4。既定は出さない）「同意の前は読まない」により、広告から来た最初のページでクリック IDを拾えない（バナーで選んだ後のページの URL に印が残っていなければ失う）。サーバーの形ならコアがランディングページで印を受け取れるが、**印の保存に「広告」の同意を要るか**を決める（要るとすれば、同意の前は印を短い間だけ預かるなど）
4. **コアの口の足りないところ**
   - Hotjar は WebSocket（`wss://ws.hotjar.com`）を connect-src に要る。今の `CspSources` は `https://` だけを許す
   - frame-src を要るタグ: Criteo（`gum.criteo.com`）・Snapchat（`tr.snapchat.com` の照合）・Amazon（iframe で発火）。無くても計測の送信は届く見込み【推測】
   - ショップの CSP は `style-src 'self'`。`<style>` や style 属性を文字で差し込む部品（Hotjar のアンケート、Ptengine）は止まる
5. **自動の照合（入力欄からメールや電話を拾う）**: Reddit（管理画面の設定で有効になる `aam`）、Criteo（Criteo 側の店ごとの設定 `smartPiiCollection…`）にある【読み取り】。前のまとまりと同じく、お店の人に切ってもらう案内を App の説明に入れる
6. **Trusted Types（スクリプトの URL の関門）**: 今回もどのタグも自前の関門を作らない【読み取り】。HTML の文字の差し込みは Hotjar（`innerHTML`）。**`new Function`（文字をコードとして動かす）で中心の動きをするのはバリューコマースのブラウザのタグ（vccv.min.js）**で、ショップの CSP（`'unsafe-eval'` 無し）では動かない → バリューコマースはサーバーの形でだけ作る
7. **外した候補**: ミエルカヒートマップ・User Insight・Ptengine・Gunosy Ads・もしもアフィリエイトなど（下の「外した候補」）

### 一覧（次のまとまり）

| タグ | App にするか | ID の形 | 公式の CSP の案内 | 購入の計測 | 同意の仕組み | サーバーから送る形 |
|---|---|---|---|---|---|---|
| SmartNews 広告 | **作った**（`plugin-smartnews-ads`。11 節の 10） | 英小文字と数字（公式の例 21 文字） | 無い | `Purchase`（value・currency・content_ids）【公式】 | 無い | Conversion API |
| A8.net | **する（サーバーの形。先に ADR）** | プログラム ID 15 バイト固定【公式】 | 無い | `a8sales()`／サーバーから GET（注文番号・単価-数-総額-商品コード・通貨）【公式】 | 無い | **セッショントラッキング・確定 API【公式】** |
| バリューコマース | する（サーバーの形だけ。A8.net の口を使い回す） | 広告主 ID（数値 10 文字まで）【公式】 | 無い | 注文番号・品の明細【公式】 | 無い（顧客区分にオプトアウトの値） | **iTrack2.0 の URL リクエスト通知【公式】** |
| Criteo OneTag | する（契約の店が出てから） | パートナー ID（例 `12345`。担当者が渡す）【公式】 | 無い | `trackTransaction`（注文番号・品の ID・単価・数）【公式】 | 無い（IAB の TCF・GPP を読むだけ） | 公開の資料に無い |
| Hotjar | 保留（コアで `wss://` を決めてから。Clarity と重なる） | サイト ID（数字）【読み取り】 | `*.hotjar.com` と WebSocket を許す、だけ【公式の抜粋】 | 無い（解析だけ） | 無い（同意は店が集める【公式】） | 無い |
| LinkedIn Insight Tag | 後回し（B2B 向け） | パートナー ID・コンバージョン ID（数字）【公式の例】 | 無い | `lintrk('track', { conversion_id, event_id })`【公式】。金額はルール側 | 無い | Conversions API |
| Reddit Pixel | 後回し | 公式に無い | 無い | `Purchase`（value・currency・itemCount・transactionId）【公式の抜粋】。報告は USD だけ | `optOut`【読み取り】 | Conversions API |
| Snapchat（Snap ピクセル） | 後回し | UUID【公式の例】 | 無い | `PURCHASE`（price・currency・transaction_id）【公式の抜粋】 | 見つからない | Conversions API |
| Amazon Ads のタグ | 後回し（Amazon DSP の契約が前提） | Amazon DSP が渡す【公式】 | 無い | `Off-AmazonPurchases`（value・currencyCode・unitsSold・clientDedupeId）【公式：GTM のテンプレート】 | Amazon Consent Signal・TCF・GPP【公式】 | 公開の資料で確認していない |
| ミエルカヒートマップ・User Insight・Ptengine・Gunosy Ads・もしも ほか | **しない** | — | — | — | — | —（下の「外した候補」） |

## 11. SmartNews 広告（SmartNews Ads のピクセル）

1. **公式の資料**
   - ピクセルとイベントの設定（ベースコード・標準のイベント・値の表）: https://help-ads.smartnews.com/en/item-3056/
   - ダイナミック広告の導入（購入の例、日本語）: https://help-ads.smartnews.com/item-5608/
   - 複数のコンバージョンの計測: https://help-ads.smartnews.com/item-1851/
   - Conversion API: https://help-ads.smartnews.com/en/item-5499/ （開発者向けの仕様 https://github.com/smartnews/smartnews-ads-advertising-api-spec/blob/v1.0/smartnews-ads-conversion-api.md ）
   - データポリシー: https://ads.smartnews.com/bm/public/data-policy/v6.0/index.html
2. **ID の形**: 公式の例は `ab124kadkkall3a134555`（英小文字と数字 21 文字）【公式の例】。正規表現 `^[a-z0-9]{16,32}$`【推測】
3. **読み込み**: インラインで `window.SmartnewsAds` と `p` のキューを作り、`https://cdn.smartnews-ads.com/i/pixel.js` を async で読み、`SmartnewsAds.p(ID, "PageView")`【公式】。ページの中でベースコードの版（1.0.0 と 1.0.1）が食い違う。`<noscript>` の画像 `https://i.smartnews-ads.com/p?id=…&e=PageView` は使わない。外部ファイルに移せる【読み取り】
4. **CSP**: **公式の CSP の案内は無い**。【読み取り】pixel.js（3KB ほど）は 2 段目のスクリプトを読まず、送るのは画像だけ（`https://i.smartnews-ads.com/p`、エラーは `/pe`、IPv6 は `https://i6.smartnews-ads.com/smallest.png`）
   - script-src `https://cdn.smartnews-ads.com` ／ img-src `https://i.smartnews-ads.com https://i6.smartnews-ads.com` ／ connect-src・frame-src は要らない
5. **購入**: `SmartnewsAds.p(ID, "Purchase", { currency: "JPY", value, content_ids: [...], content_type: "product" })`。購入には currency と value が必須、content_ids はダイナミック広告に必須【公式】。英語のページの例は `event_value`、値の表と日本語の例は `value` で**食い違う**【公式】→ `value` にした（下の 10）。注文の番号・重複を除く項目は無い（ピクセルと Conversion API の重複は「ベストエフォートで自動」【公式】）。**金額・通貨・品の ID だけで送れる**。同じタブで二度送らない
6. **同意**: 公式の記述は無い。同意の後に読む。【読み取り】識別子を Cookie（2 年）とローカルストレージ、さらに上位のドメインの Cookie にも書く。SmartNews のアプリの中のブラウザでは、アプリとの橋（`webkit.messageHandlers`）でアプリの識別子を受け取る
7. **Trusted Types**: 公式の記述は無い。【読み取り】pixel.js は script・iframe・`innerHTML`・`eval` を使わない（画像だけ）。コアの関門を通るのは、App のファイルが pixel.js を読む 1 回だけ
8. **サーバーから**: Conversion API（上の URL）。GET で通知するときは `click_id` が要る【公式】
9. **外部送信の公表**: スマートニュース株式会社 ／ ページの URL・参照元・端末の情報・Cookie とローカルストレージの識別子・購入の情報 ／ 広告の効果の測定と配信 ／ https://www.smartnews.com/privacy 、https://ads.smartnews.com/bm/public/data-policy/v6.0/index.html （SmartNews 自身の外部送信の公表 https://www.smartnews.com/jtba は書き方の参考）
10. **作った**（2026-10-30。`plugin-smartnews-ads`。決めたことと、pixel.js〔9KB ほど、中の版 8.18.0〕から読み取ったこと）
   - **金額の項目名は `value` にした**（`event_value` は送らない）。公式の中の取得元を並べると、`value` は英語のページの値の表・日本語のダイナミック広告のページの値の表と購入の例（その例の画像の URL も `d[value]=600000`）・Conversion API の仕様の `properties` の例・購入の必須の項目の一覧（「content_ids、currency、value はダイナミック広告に必須」）。`event_value` は英語のページの購入の例 1 か所と、Conversion API の上の段の項目（「`properties` の `event_value` を上書きする」と書く）だけ。英語の例は `content_type` も値の表に無い `"item"` で、画像の URL の例の括弧も `%5B…%5B` と崩れているので、古い書き方の残りと見た。両方を送ると同じ金額を 2 つの名前で送ることになるので、送る情報を絞る側に倒して 1 つにした【読み取り】
   - pixel.js は 3 つめの引数の物を `d[名前]=文字` にそのまま並べて送る（名前を読み替えない）。だから、どちらの名前が SmartNews の側で金額として数えられるかは、送信の中身からは決まらない。**最初の注文のあとに、広告管理画面の「イベント」タブで購入が金額つきで届いたかを確認する**（App の README に書いた）。届いていなければ `event_value` に替える
   - 配列の中の物（`contents: [{id, quantity}]`）は `String()` で `[object Object]` になって届かない。品は `content_ids`（ID の並び。同じ名前を繰り返して送られる）と `quantity`（合計の数）で渡し、`contents` は送らない。`content_type` は `product`
   - 注文の番号を受ける項目は無い（4 つめの引数が送信の `rid` になるが、公式の記述が無いので使わない）。番号は同じタブで二度送らないためのフラグにだけ使い、SmartNews には送らない。公表の文にも番号は書かない
   - ベースコードの前半（`SmartnewsAds.p` のキュー、`push`・`version = "1.0.1"`・`_p`）を App のファイルで作り、pixel.js を `https://cdn.smartnews-ads.com/i/pixel.js` から読む（公式の `//` は https に直す）。pixel.js は読み終えると `p.callMethod` を置き、溜まった呼び出しを送る
   - 送信は `new Image` だけ（`i.smartnews-ads.com/p` と、同じ中身を `i6.smartnews-ads.com/smallest.png` へも）。エラーは `i.smartnews-ads.com/pe` に、名前・メッセージ・スタック・ページの URL・ブラウザの名前を送る。マニフェストは script-src `https://cdn.smartnews-ads.com`、img-src `https://i.smartnews-ads.com`・`https://i6.smartnews-ads.com` だけ（connect-src・frame-src は書かない）
   - 入力欄から文字を拾う仕組みは無い（切ってもらう設定も無い）。その代わり、端末の情報として言語・時間帯・時刻の表し方・画面の大きさと色の深さ・タッチの点の数・CPU の数・メモリの量・回線の種類と速さを送る。URL か参照元に `snuuid`（SmartNews のアプリの利用者の識別子）があれば拾い、アプリの中のブラウザではアプリとの橋（iOS の `webkit.messageHandlers`・Android の `window.SmartNewsAds`）にも識別子を尋ねる。公表の文にはこれらを「ブラウザと端末の情報」「アプリの識別子」として書いた
   - 識別子（`snexid`・`snuuid`）は Cookie（2 年）とローカルストレージ、さらに**ショップのホストの上位のドメイン**にも `_r` の付いた名前で書く（書ける最も上のドメインを試して探す）。店が `<店>.<共通のドメイン>` の形で動くと、同じ共通のドメインのほかの店でも読める Cookie になる。App では止められない（残る問題として記録してある）
   - 同意の API は無いので、同意の後に読むことが「同意あり」にあたる。自前の Trusted Types の関門は作らない

## 12. A8.net（アフィリエイト。株式会社ファンコミュニケーションズ）

1. **公式の資料**: A8.net Documents https://document.a8.net/a8docs/index.html
   - JS タグトラッキング仕様書: https://document.a8.net/a8docs/a8-tracking/js-tracking/a8-js-tracking.html
   - **セッショントラッキング仕様書（サーバーから成果を送る）**: https://document.a8.net/a8docs/a8-tracking/session-tracking/a8-session-tracking.html
   - **確定 API v3（承認・取消を送る）**: https://document.a8.net/a8docs/ecsales-api/v3/ecsales-api-v3.html
   - 承認の期限（サービス利用規約）: https://support.a8.net/ec/terms.html
2. **ID の形**: プログラム ID `pid` は 15 バイト固定【公式】（別の形の `eid` 12 文字・`eno` 2 文字もある【読み取り】）。正規表現 `^[A-Za-z0-9]{15}$`（使える文字は【推測】）。確定 API は認証キー（秘密の項目）と、**呼び出し元の IP の登録**が要る【公式】
3. **仕組み**（ブラウザのタグとの違い）
   - 広告のクリック → A8.net を経由してランディングページへ。URL にクリック ID `a8=…` が付く【公式】
   - **JS タグの形**: ランディングページ（商品リンクを使うなら全ページ）の `<head>` に `//statics.a8.net/a8sales/a8sales.js` を置き、印を Cookie `_a8_{プログラム ID}`（上位のドメイン、期限 3653 日）に保存。完了の画面で `a8sales({...})` を呼ぶ。最初の成果で Cookie を消す【公式】
   - **セッショントラッキング（サーバーの形）**: 店が `a8` を受け取って保持（DB なら 256 バイト以上で 90 日以上）→ 成果のときに店のサーバーから `GET https://px.a8.net/a8fly/earnings?a8=…&pid=…&so=注文番号&si=単価-数-総額-商品コード&currency=JPY`（`si` は品ごとに繰り返す）。応答はいつも `0`【公式】
   - **承認**: 成果は「未確定」で入り、店が承認・否認する。成果の日から 30 日で自動で承認【公式】。確定 API v3（`https://ecsales-api.a8.net/v3`）で `decide`（確定）・`cancel`（取消）・`modify`（金額の修正）。取消と修正は理由の番号が必須、確定・取消を戻せるのは当日だけ、毎日 23:30〜翌 1:00 ごろは止まることがある【公式】
4. **CSP**: 公式の案内は無い。JS タグの形なら【読み取り】script-src `https://statics.a8.net` ／ img-src `https://px.a8.net`（クーポンの成果は別の宛先）。LINE の友だち追加の部品（`a8line-friendship.a8.net`・`access.line.me`）は使わない。**サーバーの形なら CSP は要らない**
5. **購入**: `a8sales({ pid, order_number, currency: "JPY", items: [{ code, price, quantity }], total_price, amount_priority: "total_price" })`。order_number は 50 バイトまで、price は小数 2 桁まで、quantity は 9999 まで、`amount_priority: "total_price"` で割引の後の合計に単価を合わせる【公式】。**番号・金額・通貨・品のコードと数だけで送れる**（名前やメールの項目は無い）
6. **同意**: 公式の記述は無い。JS タグの形は「先に結論」の 3 の問題がある（ランディングページで印を拾えない）
7. **Trusted Types**: 公式の記述は無い。【読み取り】a8sales.js は自前の関門を作らず、script と img を作る。`innerHTML`・`eval` は無い。`a8crossDomain.js`・`a8shopForm.js` はドメインをまたぐ店のためのもの（フォームに印を足す）で、同じドメインのショップでは要らない
8. **サーバーから**: 上のセッショントラッキングと確定 API。**こちらの形をすすめる**: CSP・Trusted Types・ブラウザの Cookie の制限（ITP）の影響を受けず、コアの取消・返金から否認・金額の修正まで自動にできる。秘密の認証キーは App の秘密の項目、呼び出し元の IP の固定は本番の形と一緒に決める
9. **外部送信の公表**: 株式会社ファンコミュニケーションズ ／ JS タグの形: ページの URL・Cookie の識別子・購入の情報 ／ 成果の確認（アフィリエイトの報酬の計算） ／ プライバシーポリシーの URL は公開の前に確認する。**サーバーの形は、お客さんの端末からは送らないので外部送信の規律の対象外の見込み【推測。専門家に確認する】**。それでも注文番号・金額とクリック IDを A8.net に渡すことは、ショップのプライバシーポリシーに書くのがよい【推測】

## 13. バリューコマース（アフィリエイト）

1. **公式の資料**
   - 技術資料の一覧: https://pub-docs.valuecommerce.ne.jp/docs/tag-support/ （iTAG4 導入ガイド 82_iTAG4_WebSmart.html、iTAG 導入ガイド 87_iTAG_WebSmart.html）
   - **iTrack2.0 導入ガイド**（PDF、2022-01-12。サーバーの形）: https://pub-docs.valuecommerce.ne.jp/docs/tag-support-files/itrack2.0_InstallationGuide.pdf
   - ITP の資料: https://help.valuecommerce.ne.jp/mer/dl/ITP2.0_for_iTAG.pdf
   - 同じ保存先に「第三者に開示しない」と書いた限定の資料（valuecommerceTracking.pdf）もある。**それは読まない・引かない**
2. **ID の形**: 広告主 ID（数値、10 文字まで）【公式】。正規表現 `^[0-9]{1,10}$`
3. **仕組み**
   - **iTAG4（ブラウザ）**: 完了の画面に `data-vc-*` の属性を書いた `<div>` と `//cv.valuecommerce.com/vccv.min.js`【公式】。**広告を経由しない注文も含めて、全部の注文をバリューコマースへ送る**作り【公式】
   - **iTrack2.0（サーバー）**: 店のサーバーに「エントリープログラム」を置き、バリューコマースからの遷移で Cookie `vc_cec<広告主 ID>`（60 日・HttpOnly・Secure）を発行してランディングページへ転送 → 完了の画面の CV タグ ＋ 中間ファイル（SFTP など）か **URL リクエスト通知** `GET https://kickback.valuecommerce.ne.jp/cgi-bin/<広告主 ID>/kb_wpone?cid=…&order_id=…&sales_number=…&sales_amount=…`【公式】。導入は申込とテストが要る【公式】
   - **承認**: 管理画面の「注文管理」で承認・否認。45 日で自動で承認【公式】。API の公開の資料は見つからない
4. **CSP**: 公式の案内は無い。【読み取り】vccv.min.js は `https://itag.valuecommerce.ne.jp`・`https://itag.valuecommerce.com` の `vc_itag.cgi` へ画像で送り、`https://a.imgvc.com/i/bf.png` を読んで canvas で画素を読む（キャッシュを使った識別の補いと【推測】）
5. **購入**: `data-vc-order-id`（300 バイト、必ず一意）・`data-vc-detail`（数・単価・品のコードなどを区切りでつなぐ）。単価は整数、値引きは負の値【公式】。氏名・電話・メールの属性（`data-vc-sales-name` など）は任意で、**個人情報は使わないよう書いてある**【公式】ので渡さない
6. **同意**: 同意の仕組みは無い。顧客の区分 `cs` にオプトアウト付きの値（NP・EP・OP）がある【公式】
7. **Trusted Types と eval**: 【読み取り】vccv.min.js は属性の文字を `new Function("return " + 文字)` で動かす（3 か所）。ショップの CSP では止まり、止まらなくても文字をコードとして動かすのは危ない → **ブラウザのタグ（iTAG・iTAG4）は使わない**
8. **サーバーから**: iTrack2.0 の URL リクエスト通知（またはファイル連携）。A8.net の節と同じコアの口で作れる。ただしランディングページの印を受け取るのが「エントリープログラム」（バリューコマースから店のサーバーへの転送）なので、コアのランディングページの口は「URL の印を拾う」形だけでなく「決まったパスで受けて転送する」形も要る
9. **外部送信の公表**: バリューコマース株式会社 ／ サーバーの形は A8.net と同じ扱い【推測。専門家に確認する】 ／ プライバシーポリシーの URL は公開の前に確認する

## 14. Criteo OneTag

1. **公式の資料**
   - 開発者向け（ローダーと `trackTransaction` の表）: https://developers.criteo.com/retailer-integration/docs/onetag
   - ヘルプ（OneTag の紹介・全部のイベント）: https://help.criteo.com/kb/guide/en/intro-to-the-criteo-onetag-8fjCDwCENw/Steps/775595 、https://help.criteo.com/kb/guide/en/all-criteo-onetag-events-and-parameters-vZbzbEeY86/Steps/775825 （日本語 https://help.criteo.com/kb/guide/ja/criteo-onetag-qRtjqWgKzF/Steps/775618 ）。ヘルプは JavaScript で描く作りで、取得では本文を読めなかった
2. **ID の形**: `account`（パートナー ID）は「担当者が渡す」、例は `12345`【公式】。正規表現 `^[0-9]{1,10}$`【推測】。**Criteo との契約と商品フィード（商品の一覧のファイル）が前提**で、品の ID はフィードの id と合わせる【公式】
3. **読み込み**: `<script src="//dynamic.criteo.com/js/ld/ld.js?a=ID" async>` ＋ `window.criteo_q.push({ event: "setAccount", account }, { event: "setSiteType", type: "d" | "m" | "t" }, ...)`【公式】。https に直す。外部ファイルに移せる【読み取り】
4. **CSP**: **公式の案内は無い**。【読み取り】`ld.js?a=` は店ごとの設定を埋めた ld.js を返す。設定には CSP の違反を監視する `enabledCspViolationDetection` と、`allowedDomainsForCsp`（criteo.com・criteo.net・crto.in ほか）がある
   - script-src `https://dynamic.criteo.com https://static.criteo.net https://sslwidget.criteo.com`（イベントは `sslwidget.criteo.com/event` を script で読む形〔応答が JavaScript〕か sendBeacon）
   - connect-src・img-src `https://sslwidget.criteo.com`
   - frame-src `https://gum.criteo.com`（Cookie の同期）
   - 応答の `callbacks` で他社の同期の画像を作る（宛先が決まらないので止まる側に倒す）。ID5（`cdn.id5-sync.com`）を iframe で読む所は、見本の設定では切れている（`addClientSideSupportForId5: false`）
5. **購入**: `{ event: "trackTransaction", id: 注文番号, item: [{ id, price, quantity }] }` ＋ 地域ごとの `page_id`【公式】。通貨の項目は表に無い（アカウントの通貨と【推測】）。`setEmail`（SHA256 のメール）は「強くすすめる」とある【公式】が**使わない**。**番号・品の ID・単価・数で送れる**
6. **同意**: 公式の記述は見つからない。【読み取り】ld.js は IAB の TCF（`__tcfapi`）・GPP（`__gpp`）・米国の USP を読む。日本の店には無いので、同意の後に読む形だけ
7. **Trusted Types**: 公式の記述は無い。【読み取り】自前の関門は作らない。script（イベントの応答・デバッグ用の `static.criteo.net/js/ld/ld-tag-debug.*.js`）と iframe を文字の URL で作る。`innerHTML`・`eval` は無い。**イベントの応答が JavaScript なので、宛先を許すことは応答の中身を信頼することになる**（ほかのタグの 2 段目と同じ）
   - **自動の照合**: 設定に `smartPiiCollectionEmailEnabled`・`smartPiiCollectionPhoneEnabled`（入力欄からメール・電話を拾う。名前に card・pass などを含む欄は除く）がある。見本の設定では false だが、Criteo 側の店ごとの設定で true になりうる → 契約のときに切ってもらう
8. **サーバーから**: 公開の資料では見つからない（担当者を通す）【推測】
9. **外部送信の公表**: Criteo SA（日本の窓口は CRITEO 株式会社） ／ ページの URL・参照元・端末の情報・Cookie の識別子・見た商品・購入の情報 ／ 広告の配信（リターゲティング）と効果の測定 ／ https://www.criteo.com/jp/privacy/ 【推測。公開の前に確認する】

## 15. Hotjar（ヒートマップと画面の録画。今は Contentsquare の会社）

1. **公式の資料**
   - 設置の問題（CSP と WebSocket）: https://help.hotjar.com/hc/en-us/articles/36820019419537-Troubleshooting-FAQs-for-Tracking-Code-Installation 【公式の抜粋】
   - 録画が崩れるとき（`*.hotjar.com` を許す。ドイツ語）: https://help.hotjar.com/hc/de/articles/360060206853 【公式の抜粋】
   - 個人データの扱い（同意を集めるのは店）: https://help.hotjar.com/hc/en-us/articles/36819990621073-Processing-Personal-Data-in-Hotjar
   - 速さ（読み込みを遅らせる）: https://help.hotjar.com/hc/en-us/articles/36820026186001-Will-Hotjar-Slow-Down-My-Site
   - Contentsquare の CSP（Hotjar の宛先は書いていない）: https://docs.contentsquare.com/en/web/content-security-policy/
2. **ID の形**: サイト ID（`hjid`）は数字【読み取り】。正規表現 `^[0-9]{5,10}$`【推測】
3. **読み込み**: 公式の文の資料にコードの本文は無い（管理画面の Sites で取る）【公式の抜粋】。【読み取り】`https://static.hotjar.com/c/hotjar-<サイト ID>.js?sv=6`（サイトの設定〔録画・数字とメールを隠すか・キー入力を録るか〕を埋めた JS）→ `https://script.hotjar.com/modules.<文字>.js`。サイトの設定に Contentsquare へ移った印（`cs_project_id`）があると `t.contentsquare.net` から読む
4. **CSP**: 公式は「`*.hotjar.com` を許す」「WebSocket を開けるようにする」だけ【公式の抜粋】。【読み取り】
   - script-src `https://static.hotjar.com https://script.hotjar.com`
   - connect-src `https://*.hotjar.com https://*.hotjar.io wss://ws.hotjar.com`（in・vars・surveys・identify.hotjar.com、content・metrics・events・surveystats・ask・vc.hotjar.io）
   - img-src `https://*.hotjar.com`（アンケートの画像）
   - **`wss://` は今の `CspSources` で書けない**。Hotjar を作るなら、connect-src にだけ `wss://` を許すようコアを直す（ADR 0034 に書き足す）
5. **購入**: 無い（解析だけ）
6. **同意**: 同意を集めるのは店（データの管理者）【公式】。同意の API は見つからない。同意の後に読む
7. **Trusted Types**: 公式の記述は無い。【読み取り】自前の関門は無い。modules は `innerHTML` を使う（アンケート・フィードバックの部品を HTML の文字で作る所、録画で `<style>` のコピーを作る所）。`new Function` は予備だけ。**関門と `style-src 'self'` で、アンケートとフィードバックの部品は止まる**。録画とヒートマップが動くかは作るときに確認する
8. **サーバーから**: 無い
9. **外部送信の公表**: Hotjar Ltd. ／ ページの URL・画面の操作・画面の内容（隠した部分を除く）・端末の情報・Cookie の識別子 ／ サイトの使いやすさの解析 ／ https://www.hotjar.com/legal/policies/privacy/ 【推測。公開の前に確認する】

**注意**: 録画の注意は Clarity（8 節）と同じ（購入の完了の画面に名前・住所を出さない）。Clarity と役割が重なるので、要望が出てからでよい

## 16. LinkedIn Insight Tag

1. **公式の資料**
   - 設置（Campaign Manager の Signals manager でコードを取る。個人情報や健康・お金の画面に置かない）: https://www.linkedin.com/help/lms/answer/a418880
   - 重複の除き（**ベースコードの本文あり**）: https://learn.microsoft.com/en-us/linkedin/marketing/conversions/deduplication
   - コンバージョンのルールと値（固定・動的・無し）: https://learn.microsoft.com/en-us/linkedin/marketing/integrations/ads-reporting/conversion-tracking
   - イベントごとのコンバージョン ID: https://www.linkedin.com/help/lms/answer/a1437736
2. **ID の形**: パートナー ID の例は `12345`・`5041322`、コンバージョン ID の例は `12345`（数字）【公式の例】。正規表現 `^[0-9]{3,12}$`【推測】
3. **読み込み**: インラインで `_linkedin_partner_id`・`window._linkedin_data_partner_ids` と `lintrk` のキューを作り、`https://snap.licdn.com/li.lms-analytics/insight.min.js` を async で差し込む【公式】。`<noscript>` の `https://px.ads.linkedin.com/collect/?pid=…&fmt=gif` は使わない。外部ファイルに移せる【読み取り】
4. **CSP**: 公式の案内は無い。【読み取り】script-src `https://snap.licdn.com` ／ connect-src・img-src `https://px.ads.linkedin.com`（sendBeacon・fetch・画像）。2 段目のスクリプトは無い
5. **購入**: `lintrk("track", { conversion_id, event_id })`【公式】。金額と通貨は「コンバージョンのルール」の側で決める（動的にするとイベントの値を使う）【公式】。【読み取り】insight.min.js は `conversion_value`・`conversion_currency`・`order_id` も受ける。**動的な金額の渡し方は公式の文の資料で確認きれていない**
6. **同意**: 広告主向けの同意の API は見つからない。【読み取り】スクリプトの consent は LinkedIn の会員の同意の Cookie を読む所。同意の後に読む
7. **Trusted Types**: 公式の記述は無い。【読み取り】自前の関門は無い。script・iframe・`innerHTML`・`eval` は無い。ハッシュしたメール（`li_hem`）を送る口があり、使わない
8. **サーバーから**: Conversions API（上の learn.microsoft.com の資料）
9. **外部送信の公表**: LinkedIn の事業者（日本の利用者向けの事業者名は公開の前に確認する） ／ ページの URL・参照元・端末の情報・Cookie の識別子・購入の情報 ／ 広告の効果の測定と配信 ／ https://jp.linkedin.com/legal/privacy-policy 【推測】

**後回しの理由**: 仕事の SNS で、日本の小売の EC で使う店は少ない。依頼されたら作る

## 17. Reddit Pixel

1. **公式の資料**（どれも自動の取得を断る。【公式の抜粋】）
   - 設置: https://business.reddithelp.com/en/categories/measurement/install-reddit-pixel
   - イベントの値: https://business.reddithelp.com/en/categories/measurement/event-metadata
   - 詳細マッチング: https://business.reddithelp.com/en/categories/measurement/advanced-matching
2. **ID の形**: 公式の記述は見つからない
3. **読み込み**: `rdt('init', ID, {...})`・`rdt('track', 'PageVisit')`【公式の抜粋】。【読み取り】`https://www.redditstatic.com/ads/pixel.js`
4. **CSP**: 公式の案内は無い。【読み取り】script-src `https://www.redditstatic.com` ／ connect-src `https://pixel-config.reddit.com https://alb.reddit.com` ／ img-src `https://alb.reddit.com`。2 段目のスクリプトと `<style>` を作るのは、イベントの設定のツール（Event Setup Tool）を開いたときだけ
5. **購入**: `rdt('track', 'Purchase', { value, currency, itemCount, transactionId })`。currency は ISO 4217 の 3 文字で、空なら USD、**報告は USD の値だけ**、transactionId は Reddit がハッシュして保存【公式の抜粋】。ピクセルと Conversions API の重複の除きは conversionId【公式の抜粋】。init の `useDecimalCurrencyValues`（既定 true）、`products`【読み取り】
6. **同意**: 【読み取り】init の `optOut` と、データの処理の制限（`dpm`・`dpcc`・`dprc`。米国の州法向け）。同意の後に読む
7. **Trusted Types**: 公式の記述は無い。【読み取り】自前の関門は無い。`Function(` が 1 か所（予備）。**自動の詳細マッチング（`aam`）**: 管理画面の設定（`pixel-config.reddit.com` から受け取る）で、入力欄からメール・電話を拾う → 切ってもらう
8. **サーバーから**: Conversions API
9. **外部送信の公表**: Reddit, Inc. ／ https://www.reddit.com/policies/privacy-policy 【推測】

**後回しの理由**: 日本の広告主が出せるかを公式の資料で確認できない（報道では、広告の支払いの通貨に円が無い【推測】）。報告は USD の値だけ【公式の抜粋】

## 18. Snapchat（Snap ピクセル）

1. **公式の資料**
   - 設置・購入・値の例（ヘルプ。自動の取得を断る。【公式の抜粋】）: https://businesshelp.snapchat.com/s/article/pixel-direct-implementation 、https://businesshelp.snapchat.com/s/article/purchase-event 、https://businesshelp.snapchat.com/s/article/additional-parameters
   - 開発者向け: https://developers.snap.com/marketing-api/Ads-API/snap-pixel
2. **ID の形**: UUID（例 `6abc82ca-4a3a-4391-98ba-0317a8471234`）【公式の例】。正規表現は UUID
3. **読み込み**: `snaptr('init', ID, {...})`・`snaptr('track', 'PAGE_VIEW')`【公式】。【読み取り】`https://sc-static.net/scevent.min.js`。init の `user_email` などは渡さない
4. **CSP**: 公式の案内は無い。【読み取り】script-src `https://sc-static.net` ／ connect-src・img-src `https://tr.snapchat.com`（IPv6 は `tr6.snapchat.com`、試しの `tr-shadow.snapchat.com`）／ **frame-src `https://tr.snapchat.com`**（Cookie の照合 `/cm/i`）。CSP の違反を監視して、止まると別の道を試す作り
5. **購入**: `snaptr('track', 'PURCHASE', { price, currency, transaction_id, item_ids, number_items })`。費用対効果の報告には price・currency・transaction_id の 3 つが必須【公式の抜粋】。重複の除きは `client_dedup_id`【読み取り】
6. **同意**: 公式の記述は見つからない。同意の後に読む
7. **Trusted Types**: 公式の記述は無い。【読み取り】自前の関門は無い。iframe（照合）を作る。`innerHTML`・`eval` は無い
8. **サーバーから**: Conversions API（developers.snap.com）
9. **外部送信の公表**: Snap Inc. ／ https://values.snap.com/ja-JP/privacy/privacy-policy 【推測】

**後回しの理由**: 日本の利用者が少ない

## 19. Amazon Ads のタグ（Amazon Ad Tag）

1. **公式の資料**
   - 同意の信号の要件: https://advertising.amazon.com/resources/ad-policy/consent-signal-requirements
   - ピクセルの決まり: https://advertising.amazon.com/resources/ad-policy/pixeling-policy
   - Amazon の出している Google タグマネージャーのテンプレート（GitHub の amzn）: https://github.com/amzn/ads-pao-amznjs-gtm-template
   - **タグの本文と手順は Amazon Ads の管理画面の中**で、公開の文の資料は見つからない
2. **ID の形**: タグ ID は「Amazon DSP が渡す」【公式】。形は不明
3. **読み込み**: 【読み取り】`https://c.amazon-adsystem.com/aat/amzn.js`（設定 `/aat/config`、許可の一覧 `/aat/allowlist/allowlist.json`）
4. **CSP**: 公式の案内は無い。【読み取り】script-src・connect-src `https://c.amazon-adsystem.com` ／ connect-src `https://tk.amazon-adsystem.com` と地域ごとの `https://s.amazon-adsystem.com`（NA）・`https://aax-eu.amazon-adsystem.com`（EU）・`https://aax-fe.amazon-adsystem.com`（FE）／ frame-src（iframe で発火する所がある。宛先は設定から）。日本の地域は、テンプレートは「NA に日本を含む」【公式】、スクリプトには FE もある → 作るときに確認する
5. **購入**: イベント `Off-AmazonPurchases`、属性 `value`・`currencyCode`・`unitsSold`・`clientDedupeId`（取引の番号）・`productId`【公式：テンプレート】
6. **同意**: Amazon Consent Signal（`amzn_user_data`・`amzn_ad_storage` を GRANTED／DENIED）・TCF・GPP のどれか。EEA と英国では必須、既定は DENIED【公式】。日本の記述は無い。名前・メール・電話は渡さない決まり【公式】
7. **Trusted Types**: 公式の記述は無い。【読み取り】自前の関門は無い。iframe を作る。`innerHTML`・`eval` は無い
8. **サーバーから**: 公開の資料では確認していない
9. **外部送信の公表**: Amazon の事業者（公開の前に確認する）

**後回しの理由**: Amazon DSP（Amazon の外の広告）の契約が前提で、店主が自分で始められる広告ではない。手順も公開されていない

## 外した候補

| 候補 | 理由 |
|---|---|
| ミエルカヒートマップ（株式会社 Faber Company） | タグの本文・読み込み先・CSP の公開の技術資料が無い（FAQ は「head に書く」だけ。外部送信の中身は FAQ にある https://mieru-ca.com/heatmap/faq/ ）。A/B テストとポップアップで画面を書き換える機能があり、ADR 0034 で App にしない「接客のツール」に近い |
| User Insight（株式会社ユーザーローカル） | 技術の FAQ はログインの中、タグは申込から発行。外部送信の公表のページはある https://info.userlocal.jp/external_transmission |
| Ptengine | 公式の CSP の案内が script-src・style-src に `'unsafe-inline'` を求める https://helps.ptengine.com/faq/others/csp-policy 。ショップの CSP では動かない。画面を書き換える機能（Experience）は ADR 0034 で App にしない「何でも入れられる箱」 |
| Gunosy Ads | 公開の技術資料が無い（広告主のヘルプは https://gunosy.my.site.com/gunosyads/s/ で、タグの手順は公開の検索で見つからない） |
| もしもアフィリエイト・afb・楽天のアフィリエイト | 広告主向けのタグや成果の通知の公開の仕様書が見つからない（A8.net・バリューコマースは公開の仕様がある） |
| バリューコマースのブラウザのタグ（iTAG・iTAG4） | `new Function` で属性の文字を動かす（13 節）。全部の注文を送る。サーバーの形（iTrack2.0）だけを作る |

## 決めること（次のまとまり）

1. **アフィリエイトのサーバーの形の ADR**: コアに「ランディングページでクリック IDを受け取る口」（A8.net は URL の `a8`、バリューコマースは決まったパスで受けて転送）と「注文の出来事（支払いの確定・取消・返金）でサーバーから外へ送る口」（失敗したら再送、送った記録をイベントに）を足すか。外へ送る宛先は App のマニフェストに前もって書く（CSP と同じ考え方）
2. **クリック IDの保存と同意**: 印（A8.net は 90 日以上）をどこに、何日、同意の有無でどう扱うか
3. **呼び出し元の IP**: A8.net の確定 API は呼び出し元の IP の登録が要る。本番の形で、外へ出る IP を固定するか
4. **`wss://` を許すか**（Hotjar）: connect-src に限って `wss://` のホストを許すか
5. **承認・否認を自動にする範囲**: 取消・返金を否認にする、部分の返金を金額の修正にする、承認は期限に任せる、など

## 作る順のおすすめ（次のまとまり）

1. **SmartNews 広告**（作った。2026-10-30）— 日本の会社で日本の利用者が多い。公式のヘルプに円での購入の例がある。スクリプトが画像で送るだけで、2 段目・iframe・HTML の差し込みが無い。今のコアの口のまま作れる
2. **A8.net（サーバーの形）** — 先に「決めること」の 1〜3 で ADR を書く。公式の仕様（セッショントラッキング・確定 API）がそろっていて、コアの注文・取消から承認・否認まで自動にできる
3. **バリューコマース（サーバーの形）** — 2 の口を使い回す。ランディングページの転送（エントリープログラム）とバリューコマースとのテストの手順が要る
4. **Criteo OneTag** — 日本の EC のリターゲティングでよく使う。ただし契約と商品フィードが前提で、宛先が多い（iframe・応答の JavaScript）。使う店が出てから、report-only で宛先を洗い出して作る
5. **Hotjar** — `wss://` を決めてから。Clarity で足りるなら後回し
- LinkedIn・Reddit・Snapchat・Amazon は依頼されたら（各節の「後回しの理由」）
