# App を作る手順（ひな形から）

新しい App（プラグイン）は、既存の App をコピーせずに、公式のひな形から始める（2026-11-06。外の提案「既存の App をコピーすると、その機能だけの処理まで持ち込む。公式のひな形で人にも AI にも実装のばらつきを減らす」）。
ひな形は今の App の作り方（[plugins/README.md](../../plugins/README.md)・ADR 0025・ADR 0051・[conventions.md](conventions.md)）に沿った最小の形で、出したそのままでテストと監視（`RouteAuthorizationTest`・`ArchitectureTest`・`PluginMigrationsTest`・`PluginListTest`・`AppManifestTest`・`NavigationOnlyRoutesTest`。全部の App の共通のテスト `AppContractTest` は 2 の 4 を足してから）が通る。

## 1. ひな形を出す

```sh
php artisan make:plugin stamp-card --category=selling --title=スタンプカード
```

- **名前**: 英小文字で始まり、英小文字・数字とハイフンで 24 文字まで（先頭・末尾・続けてのハイフンは不可）。入っている App・画面の名前空間（テーマの `theme-*` など）・表の名前（`<名前>_items`）・ファイルと重なると断る。表とイベントの頭は、ハイフンを `_` にしたもの（`stamp_card`）
- **目的**（`--category`）: マニフェストの `category` と同じ言葉（`analytics`・`marketing`・`page`・`selling`・`products`・`payment`・`shipping`・`customers`・`operations`・`other`。既定は `other`。意味は plugins/README.md）
- **表示名**（`--title`）: 管理画面に出す名前。40 文字まで。生成するコードを壊す記号（引用符・`\`・`<>`・`&`・`{}`・`@`・`$`・改行など）は断る。省くと名前のまま
- 上書きはしない（行き先に 1 つでもファイルがあれば、何も書かずに止まる）。本番（`APP_ENV=production`）では動かない。worktree など `.env` の無い所では `APP_ENV=local` を付ける

出るもの:

| ファイル | 中身 |
|---|---|
| `plugins/plugin-<名前>/composer.json` | path のパッケージ（`ec-sugar/plugin-<名前>`、名前空間 `EcSugar\Plugin<名前>`）とマニフェスト `extra.ec-sugar`（`type`・`name`・`plugin_api`・`core`・`category`） |
| `src/<名前>ServiceProvider.php` | レジストリへの登録（`PluginDefinition`: 名前・表示名・説明・マニフェスト・店の DB のマイグレーション・ショップの経路・App の画面）とショップの Blade の名前空間 |
| `database/migrations/…_create_<頭>_items_table.php` | 店の DB の表（ULID・`timestampTz`・状態の CHECK・空でない名前の CHECK）。個人情報の列の書き方（暗号化のキャストと HMAC の索引）はコメントの例 |
| `src/Models/<名前>Item.php`・`src/<名前>Status.php` | モデルと状態（暗号化・ブラインドインデックスの書き方はコメントの例） |
| `src/<名前>Items.php` | 書くハンドラー。どれも `DB::transaction` → `EventStore::lock()` → 行のロック → 状態の変更 → 同じトランザクションのイベント（`<頭>.created` など。中身は ID と状態だけ） |
| `src/<名前>AdminPage.php` | 管理画面の App の画面（`AdminPageHandler`: 絞り込み・列・行・1 件・操作）と足す・直すの欄（`AdminFormHandler`）。お金の操作の例（`money: true`） |
| `src/Http/<名前>Controller.php`・`resources/views/index.blade.php` | ショップの `/p/<名前>`（テーマの外枠の中に App の Blade を描く） |
| `tests/Feature/Plugin/<名前>AppTest.php` | うまくいく流れ・使っていない店では 404・ほかの店の記録は 404・運用の段階でない人は 403・お金の操作は「会計」の段階で直前の再確認の後だけ（403・423） |
| `resources/admin/help/<名前>.js`・`plugins/plugin-<名前>/README.md` | 管理画面のヘルプ（App の画面の「説明を見る」の行き先）と App の説明 |

## 2. 手で足す（コマンドの最後にも出る）

コマンドは新しいファイルしか書かない（ほかのファイルを書き換えない）。次を手で足す:

1. ルートの `composer.json` の `require` に `"ec-sugar/plugin-<名前>": "^0.1"` を足し、`composer update ec-sugar/plugin-<名前>`（path のパッケージ。symlink で入る）
2. `tests/Feature/Admin/PluginListTest.php` の入っているパッケージの一覧に `'ec-sugar/plugin-<名前>'` を名前の順で
3. `tests/Feature/Plugin/AppManifestTest.php` の App の目的の一覧に `'<名前>' => '<目的>'` を名前の順で
4. `tests/Feature/Plugin/AppContractRecords.php` の `contractRecord` に、画面の 1 件の作り方を 1 行（コマンドが出す行をそのまま。無いと全部の App の共通のテスト `AppContractTest` が「足す」と落ちる）
5. `resources/admin/navigation.js` の `HELP_PAGES` に 1 行と、`resources/admin/help/index.js` の読み込みと `CONTENT`（3 か所がそろわないとブラウザのテスト help-data.spec.js が落ちる）
6. `plugins/README.md` の App の一覧（目的のまとまりの下）
7. `php artisan test tests/Feature/Plugin/<名前>AppTest.php` で、ひな形のまま通ることを確認する

## 3. 中身を作る

ひな形の「記録・受付を終える・精算済みにする」は形の見本。App の中身に合わせて、表・欄・操作・画面を書き換える。そのとき守ること（詳しくは conventions.md）:

- **書き込み**: 状態を変えるのは書くハンドラー（`…Items` のようなサービス）だけ。最初に `EventStore::lock()`、次に行のロック、同じトランザクションでイベント。イベントの中身に文・個人情報を入れない。例外（カート・送った記録の印など）にしてよい条件は conventions.md の 3
- **コアの記録・通知を受ける**（支払いが確定した・発送した・取消など）: `EventReceivers` の名前のある受信側で受ける（`DomainEventRecorded` を `Event::listen` しない。届かないことがある）。少なくとも 1 回届くので冪等に書き、続けられない時は例外を投げる。書き方と届き方の約束は [plugins/README.md の「コアの記録と通知を受ける」](../../plugins/README.md)・ADR 0089
- **データ**: 表の名前は App の頭で始め、コアの表は作らない・変えない（参照の外部キーはよい）。金額は整数。個人情報は平文の列にしない（`TenantEncrypted`・`HasBlindIndexes`。ADR 0010）。列を消す・名前を変えるマイグレーションは書かない
- **管理画面**: 中身だけを返し、描くのはコア（ADR 0051）。一覧と 1 件に名前・メール・住所・電話を出さない（お客さんはリンク）。見るのは閲覧・書くのは運用の段階（コアの経路が確認する）。取り消せない操作は `danger: true`、お金に触れる操作は `money: true`（コアが会計の段階と直前の再確認を確認する）
- **ショップ**: 経路は `storefrontRoutes` に渡す（コアが `/p/<名前>` の下に `storefront`・`public`・`plugin:<名前>` の印を付ける。使っていない店では 404）。公開にする理由をコメントに書く。会員だけの画面は `auth:customer` を付けずに（コアが付ける `public` と両方になり `RouteAuthorizationTest` が落ちる）、コントローラーで `$request->user('customer')` が `Member` で `belongsHere()` かを確認し、違えばログインの画面へ送る（`plugin-member-ranks` の `RankController`）。送る経路には回数の上限（`throttle:…`）。モデルを経路の引数で結びつけない。文は Blade の `{{ }}` で出す。推測されないトークンや署名で開く経路（メールのリンクなど）は `->middleware('secret-link')` で宣言し、署名つきの URL はコアの `SecretLinks::signed`・`SecretLinks::valid` で作って確認する（ADR 0081。書き方は plugins/README.md の「防御の書き方」）
- **メール**: お客さんや店へ送るメールのクラス（Mailable）には `use App\Core\Mail\RecordsMail;` を付け、`recordKind()` で `<App の名前>.<何のメールか>` を返す（例 `restock.restocked`）。送った・送れなかったが「設定 › メールの記録」に出る（ADR 0095）。関係する注文は送る所で `->forTransaction($id)`、一斉の配信は `recordBulk()` で真（送れなかった分だけ残る）。付け忘れは `MailRecordCoverageTest` が落とす
  - 差出人の名前と件名は `RecordsMail::line()`（中身は `App\Core\Mail\MailHeaderText`）を通す。改行やタブをそのまま入れると、ヘッダーをもう 1 本書き足されてしまう
  - お知らせ・宣伝のメール（メルマガ・カゴ落ち・お気に入りなど）の送り主の表示は `App\Core\Mail\SenderFacts` を使う。`current()` で店の「特商法の表記」から読み、`missing()` が空でない時は送らない（特定電子メール法 第 4 条）
- **個人情報の画面**: 会員の個人情報（名前・メール・住所・注文）を出す画面と、そこへの送信には `->middleware('navigation-only')` を付ける。タグの JS から読ませないため（ADR 0088）。ひな形の ServiceProvider のコメントに例がある。パスに `account`・`{orderId}` を持つのに印が無いと `NavigationOnlyRoutesTest` が落ち、付けた経路はそのテストの `MARKED` と ADR 0088 の一覧にも足す
- **定期の処理**を足すときは `PluginTenants::each()` で使う店だけを回し、actor は `system:<名前>`
- **データを消す口**（ADR 0092）: 止めた店でオーナーが App のデータを消せるようにするなら、`PluginDefinition` に `purger: new <名前>DataPurger` を渡す（`App\Core\Plugin\Contracts\PluginDataPurger`。ひな形には入っていない）。`refusal()` は今は消せない理由（払ったお客さんがまだ受け取れる物など）か null、`purgeRows()` は自分の表の行を子の表から全部消して「表の名前 => 数」を返す（表は消さない。名前は App の頭で始める）、`purgeFiles()` はコミットの後に、この店の自分の保存先のうち、どの行も指さないファイルを消す。コアが店の文脈・止めていること・オーナー・記録 `plugin.purged` を受け持つ。書き方の例は [plugins/README.md の「App の一生とデータを消す口」](../../plugins/README.md)、見本は `plugin-faq` の `FaqDataPurger`（行だけ）と `plugin-downloads` の `DownloadDataPurger`（行とファイル）。口を足したら ADR 0092 の「口のある App」の表に書く
- **テスト**: ひな形の 5 つは残し、App の決まり（失敗系・境界）を足す。少なくとも、うまくいく場合とよくある失敗 1 つを書く
- **文書**: App の README・管理画面のヘルプ・plugins/README.md。コアに口を足したら ADR（0025 か 0051 の「作って決めたこと」）、境界をまたぐなら脅威モデル

## ライセンス

- ひな形が出す composer.json の `license` は `proprietary`。自分の App のライセンスは作った人が決めて書き換える
- コアは AGPL v3 だが、**公開の口だけを使う App は、作った人が好きなライセンス（有料・非公開を含む）で配れる**（[LICENSE-EXCEPTION.md](../../LICENSE-EXCEPTION.md)、ADR 0033）。公開の口は、この文書・[plugins/README.md](../../plugins/README.md)・[ADR 0093](../adr/0093-外に約束する口を決め、壊すときはAPIのバージョンを上げてCHANGELOGで知らせる.md) の 1 の表に挙げた口と、ひな形が出すコードと見本の App（plugin-faq）が使うコアの部品（同じ使い方で）。ひな形が出したコードそのものも、好きなライセンスにしてよい
- 例外に入らない書き方: コアのファイルを書き換える・コアの中のサービスを差し替える・コアの DB の表を直に読み書きする・コアのコードをコピーして入れる
- 自社の App（`plugins/`）は、公式テーマ 4 つと plugin-faq が MIT、ほかは今はコアと同じ `AGPL-3.0-only`（App ごとに決める途中）。`tests/Unit/LicenseTest.php` が許す値を監視する

## 作りの決め事（ひな形のコマンド）

- コマンドは `App\Core\Plugin\Console\MakePluginCommand`、書くのは `App\Core\Plugin\Scaffold\AppScaffold`、型は `app/Core/Plugin/Scaffold/stubs/`。型を直したら `tests/Feature/Plugin/MakePluginCommandTest.php`（composer に入れずに中身の形とレジストリが受け付けることを確認する）を実行し、一度は実際に出して composer に入れ、出したテストと監視が通ることを手元で確認する
- 名前の上限をコア（32 文字）より短い 24 文字にしたのは、表（`<頭>_items`）・索引・イベントの集約の名前（`<頭>_item`。コアは 32 文字まで）が上限に収まるようにするため
- PHP の `use` の並びは App の名前で変わるので、出すときに pint と同じ順に並べ直す
