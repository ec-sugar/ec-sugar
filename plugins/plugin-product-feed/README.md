# 商品フィード（EC-SUGAR の App）

Google Merchant Center（Google ショッピング）と Meta のカタログ（Facebook・Instagram のショップと広告）に商品を並べるための、ショップの商品の一覧の URL を出す App です。
2 つのサービスは、この URL を定期的に読みに来て、商品の名前・値段・在庫の具合・写真を取り込みます。
核の口（ショップの経路・プラグインの表・App の画面・商品の画面の欄・App の設定）だけで作っています（ADR 0025・0050・0051）。

## 使い方

1. 管理画面の店のメニュー「App」から「商品フィード」を開き、「使う」にします（運用の段階・直前の再確認）。設定で「ブランドの既定」と「Google の商品カテゴリの既定」を入れられます（どちらも空でよい）。
2. メニューの「App」の下に「商品フィード」が出ます。「Google ショッピング（Merchant Center）」か「Meta のカタログ（Facebook・Instagram）」を開き、「URL を作る」を押します。
3. 「フィードの URL」の下の「写す」で URL を写し、それぞれのサービスの画面に貼ります（手順は管理画面のヘルプ「商品フィード（App）」）。
4. 商品ごとの出し方は、管理画面の商品の画面の「商品フィード」の欄で決めます: フィードに出さない・ブランド・Google の商品カテゴリ（番号）・商品の状態（新品・中古・再生品）・品番をメーカーの型番（MPN）として出す。欄の上に、その商品がフィードに何行出るか（出ないなら理由）が出ます。

- **出るのは、ショップに出ている商品だけ**です（下書き・終了・見積もりで決まる商品・止めた種類・0 円の品は出ません）。店が準備中のあいだは出ません。
- 写真の無い商品は出せません（Google と Meta は写真を求めます）。1 件の画面の「フィードに載せられない商品」に理由と一緒に並びます。
- 種類（色・サイズ）のある商品は種類ごとに 1 行で、同じ商品の行には同じ商品グループ ID（`item_group_id`）が付きます。軸の名前が「色」「カラー」「Color」なら色（`color`）、「サイズ」「大きさ」「Size」ならサイズ（`size`）として出します。ほかの軸は商品名に添えます（「エプロン（墨 / M）」）。
- URL を知っている人は、ショップに公開している商品の一覧を読めます。漏れたと思ったら「URL を作り直す」（前の URL はすぐ使えなくなります。登録した画面に新しい URL を入れ直します）か「URL を止める」を押します。どちらも取り消せないので、パスキーでもう一度確認します。
- 作ったフィードは最長 1 時間とっておきます。商品・種類・在庫・写真・設定を直すと、次に読みに来た時に作り直します。
- 1 つのフィードに出す行は 5,000 までです（超えた分の商品は 1 件の画面に数が出ます）。
- ブランドの欄には `<` `>` `"` `'` などの記号は入れられません（核の設定の文の決まり。英語のブランド名の「'」も今は入りません）。

## 出す項目

RSS 2.0 の XML で、項目は Google の名前空間（`xmlns:g="http://base.google.com/ns/1.0"`）です。Meta はこの形を読めるので、同じ形で 2 つの URL を出し、違うところだけを分けています。

| 項目 | 中身 |
|---|---|
| `g:id` | 種類の無い商品は商品の ID、種類のある商品は種類の ID（どちらも 26 文字。Google は 50 文字、Meta は 100 文字まで） |
| `g:item_group_id` | 種類のある商品だけ、商品の ID |
| `g:title` | 商品名（種類は「（墨 / M）」を添える）。150 文字まで |
| `g:description` | 説明のブロックの文をつないだもの（無ければ短い説明、それも無ければ商品名）。5,000 文字まで |
| `g:link` | ショップの商品の画面の URL |
| `g:image_link` | 種類の写真（無ければ商品の 1 枚目の写真）。大きい方の写真の URL |
| `additional_image_link` | ほかの写真（10 枚まで）。Google は `g:` を付け、Meta は付けない（Meta の見本の形） |
| `g:availability` | Google は `in_stock`・`out_of_stock`、Meta は `in stock`・`out of stock`（残りわずかも在庫あり） |
| `g:price` | 「5400 JPY」（税込み。核の金額の整数のまま） |
| `g:condition` | `new`・`used`・`refurbished`（商品の欄。既定は新品） |
| `g:brand` | 商品の欄 → App の設定の既定 → 店の名前の順。70 文字まで |
| `g:mpn` | 「品番を MPN として出す」を入れた商品だけ、種類の品番 |
| `g:identifier_exists` | Google だけ。GTIN が無く、ブランドと MPN がそろわない商品に `no` |
| `color`・`size` | 種類の軸から。Google は `g:` を付け、Meta は付けない |
| `additional_variant_attribute` | Meta だけ。色・サイズでない軸（`label` と `value`） |
| `g:google_product_category` | 商品の欄 → App の設定の既定（Google の商品カテゴリの番号）。どちらも空なら出さない |

送料は出しません（Merchant Center のアカウントの送料の設定を使います）。GTIN（JAN など）は核に列が無いので出しません。

## 仕様の出どころ（2026-11-04 に取った）

- Google: 商品データ仕様 — https://support.google.com/merchants/answer/7052112?hl=ja （必須の項目、`in_stock`・`out_of_stock`、価格の形、`identifier_exists` の決まり、商品名 150 文字・説明 5,000 文字・ブランドと MPN 70 文字、追加の写真 10 枚、色とサイズ 100 文字、商品カテゴリは番号でよい）
- Meta: カタログの項目 — https://developers.facebook.com/docs/commerce-platform/catalog/fields （必須の項目、`in stock`・`out of stock`、価格の形、Google の名前空間の RSS の XML の見本〔追加の写真と色は `g:` なし〕、`additional_variant_attribute`、ブランド 100 文字、商品名 200 文字、説明 9,999 文字）
- Meta: カタログのフィードの形式 — https://developers.facebook.com/docs/marketing-api/catalog/reference （CSV・TSV・RSS XML・ATOM XML・Google スプレッドシートを読む）

各社は項目と画面を時々変えます。公開の前と、Merchant Center やコマースマネージャで誤りが出た時に、上の文書と照らし直してください。

## 作り

| ファイル | 役 |
|---|---|
| `database/migrations/…_create_product_feed_tables.php` | 店の DB の表。`product_feed_feeds`（行き先ごとのトークン。移行で Google と Meta の 2 行を作る）と `product_feed_listings`（商品ごとの出し方）。形は DB でも守る |
| `src/FeedTokens.php` | URL のトークンを作る・作り直す・止める（連鎖のロック → 行のロック → イベント `product_feed.token_issued`・`token_rotated`・`token_stopped`。中身は行き先の名前だけ）と照らし合わせ |
| `src/FeedBuilder.php` | 核のショップの窓口（`Catalog`）から行を集める（出さない商品と理由も） |
| `src/FeedWriter.php` | 行を XML に書く（XMLWriter。行き先ごとの違いは `FeedFormat`） |
| `src/FeedCache.php` | 作った XML と管理画面の数を 1 時間とっておく（鍵に店の記録の連鎖のいちばん新しい番号） |
| `src/Http/FeedController.php` | フィードの URL（回数の上限 → トークン → 店が公開か → キャッシュ） |
| `src/FeedAdminPage.php` | 管理画面の App の画面（行き先の一覧・1 件・操作） |
| `src/FeedListingPanel.php` | 管理画面の商品の画面の欄（イベント `product_feed.listing_updated`。中身は商品の ID と変えた欄の名前だけ） |

テストは `tests/Feature/Plugin/ProductFeedAppTest.php`（XML として読めること・必須の項目・種類ごとの行・在庫切れ・価格の形・エスケープ・下書きと外した商品と写真の無い商品・トークンの違い・作り直しと止める・ほかの店・準備中の店・キャッシュとその消え方・回数の上限・権限）。

## 守り

- URL のトークンは 160 ビットの乱数（16 進の 40 文字）。店の鍵で暗号化して置き、照らすのは SHA-256 の値どうし。違う・止めた・公開していない店は、どれも同じ 404
- 店と接続元ごとに 1 時間に 60 回まで（トークンを確かめる前に数える）
- 答えは `Cache-Control: private, no-store`・`X-Robots-Tag: noindex, nofollow`・`Referrer-Policy: no-referrer`
- お客さんの個人情報は扱わない（出すのは商品の情報と、在庫の具合〔あり・なし〕だけ。数は出さない）
- 準備中の店は、核の関所が先に「準備中です」の画面へ送ります（302）。パスワードを入れた人にもフィードは出しません（404）

## これからの候補（今回は作らない）

（商品の画面の構造化データ〔JSON-LD の `Product`。価格・在庫・レビューの平均〕は、核が出すようになりました。ADR 0061）

- 種類を選んだ状態の商品の画面（`?variant=` など）へのリンク: 今は種類の行も商品の画面へ向きます。Google は、行の種類が選ばれた画面を勧めます（核の商品の画面に、種類を選んで開く口が要る）
- GTIN（JAN など）: 核の種類に列が要る
- 準備中の店でも 404 で答える: 今は核の関所が先に「準備中です」の画面へ送る
- Meta ピクセルの商品の ID との一致: Meta の動的広告は、カタログの `id` とピクセルの `content_ids` が同じであることを求めます。種類の無い商品は商品の ID でそろっていますが、種類のある商品はカタログが種類の ID、ピクセル（`plugin-meta-pixel`）が商品の ID です
