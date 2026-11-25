# 0093 外に約束する口を決め、壊すときは API のバージョンを上げて CHANGELOG で知らせる

状態: **採用**（2026-11-11。作ることは運営が決めた〔変更履歴と互換の約束〕。中身は今のコードと文書から書いた）。迷ったところは安全で単純な既定を置き、末尾の「運営に確かめたいこと」に挙げた。答えで直す。
ADR 0006（更新）・0025（App）・0028（テーマ）・0035（GitHub とタグ）・0089（受け手）の上に置く。置き換えるものは無い

## 要点（先に）

1. **約束するのは、外の人が書く・登録する・打つ口だけ**: テーマ API（今 **2**）・プラグイン API（今 **1**）・`ec-sugar` コマンドと文書に書いた `php artisan` のコマンド・インストーラーの答えのファイルのキー・設定のファイル（env）の項目の名前・外のサービスに登録する URL・ショップの公開の URL。**約束しないのは**管理画面の SPA の内側の API・DB の表の形・核のクラスの中身・画面とログの文
2. **バージョンは semver のタグ `vX.Y.Z`**（署名つき）。タグはまだ 1 つも無い（2026-11-11）。**最初のタグは `v1.0.0`**（2026-11-12 の [ADR 0098](0098-開発用と公開用のリポジトリを分け、リリースごとに許したものだけを書き出す.md) で、先行公開 `v0.1.0` からパッケージを配る 1.0 に置き換わった）。それまでの main は「未リリース」
3. **壊す変更は二段**（足す → 古いものを残して非推奨にする → 次のマイナーで消す）。テーマやプラグインの約束を壊すときは API の番号を上げ、前の番号もその間は受け付ける。非推奨は CHANGELOG と、古い形を読んだ時のアプリのログの警告で知らせる
4. **変更は CHANGELOG.md に、リリースごとに日本語で**。PR を作るときに「未リリース」の節に足し、リリースで見出しにバージョンと日付を付ける
5. マイグレーションは前へだけ（戻すときは新しいマイグレーションで）。セキュリティの直しは既定で自動（今の決まりのまま。署名つきの更新ができるまで本番の自動の更新は切る）

## 文脈

- 互換のための仕組みは、すでにばらばらにある: テーマとプラグインの登録簿は API の番号が合わないものを断る（`ThemeRegistry::API_VERSION`・`PluginRegistry::API_VERSION`）、マニフェストは旧キー `extra.sugar-ec` も読む（`PackageManifest`。ADR 0025 の「作って決めたこと」）、設定のファイルは前の名前 `SUGAR_EC_…` も読む（install.md）、マイグレーションは列を消す移行を見張る（`MigrationsExpandContractTest`。ADR 0006）。ところが「何を外の人に約束しているか」の一覧が無く、どこまで変えてよいかを作る人が毎回考えていた
- 外の人は 3 種類: テーマを作る人、App を作る人、EC-SUGAR を入れて使う人（サーバーを持つ運営）。さらに、店の人とお客さんにとっての互換（ショップの URL・外のサービスに登録した受付の URL）がある
- リリースのタグはまだ無い（`git tag` は空）。本番は main のコミットを `ec-sugar update` で取っている。開発の決まりと ADR 0035 は「先行公開を `v0.1.0` に」「本番へ出すのは署名つきのタグ」と決めている。核のバージョン `App\Core\Version::CORE` は `0.1.0`
- 変わったことの記録は開発の記録と PR だけで、入れて使う人が「このバージョンで何が変わったか」を読むまとめが無かった
- CONCEPT 6.3 は「拡張点の集合を Plugin API vN として semver で」と書いたが、今は整数で、付け足しでは上げない形で動いている（CONCEPT の追記）

## 決めたこと

### 1. 約束するもの

| 対象 | 約束の中身 | 正本 |
|---|---|---|
| **テーマ API**（今のバージョン **2**、`ThemeRegistry::API_VERSION`） | マニフェスト `extra.ec-sugar` の `type`・`name`・`theme_api`・`core`。`theme.json` の書き方（書けるキー `theme_api`・`sections`・`pages`・`motion`・`screenshot`、欄の種類と核の上限、区画・パターン・ブロック・見本・ワイヤーの絵）。テーマが持つ画面の名前と、Blade に渡す値（`$sections`・`$shop`・`$theme` ほか、テーマ API 2 の付け足しの節の値と部品）。核が付ける・読む印（`data-section`・動きの印・上書きしてよい CSS の変数） | [docs/design/theme-api.md](../design/theme-api.md) |
| **プラグイン API**（今のバージョン **1**、`PluginRegistry::API_VERSION`） | `PluginRegistry::register()` と `PluginDefinition` の名前つきの引数。マニフェスト `extra.ec-sugar`（`type`・`name`・`plugin_api`・`core`・`category`・`settings`・`disclosure`・`storefront`・`sections`。旧キー `extra.sugar-ec` も読む）。差し込みの口（`App\Core\Plugin\Contracts` のインターフェース・App の区画・管理画面の App の画面〔ADR 0051〕）。経路の置き方と印（`storefrontRoutes` が `/p/<名前>` の下・`navigation-only`・`secret-link`・`throttle:…`）と核の守りの部品（`SecretLinks`・`Throttle`）。受け手（`EventReceivers::notice`・`on`、核の知らせのクラス、記録の種類の名前と今ある payload の鍵、届き方の約束の表。ADR 0089）。プラグインの表のマイグレーションの決まり（名前の頭・核の表を変えない） | [plugins/README.md](../../plugins/README.md)・[making-apps.md](../development/making-apps.md)・ADR 0025・0051・0089 |
| **決済の口**（プラグイン API 1 に含む） | App が触ってよい決済の 18 種類。口（`App\Core\Payment\Contracts` の `PaymentDriver`・`StoredPaymentDriver`・`PaymentCredentials`・`PaymentCredentialStore`・`PaymentWebhookReceiver`・`StoredCardHolder`）、サービス（`Services\PaymentService`・`StoredPayments`・`PaymentRefunds`・`ReturnSettlement`）、値と例外（`PaymentUnavailableException`・`RefundDeclined`・`StoredCharge`・`DirectMethod`・`Enums\ReturnReason`）、記録（`Events\PaymentConfirmed`・`PaymentAwaiting`・`RefundSettled`）、モデル（`Models\PaymentAttempt`・`OrderReturn`・`Refund`）、処理（`Jobs\DetachStoredCards`）。**決済代行の具象（`Stripe\…`・`StripeCredentials`・`StripeConnectService`）は約束しない**（ADR 0107 の段 3b で `plugins/payment-stripe` へ移す） | ADR 0026・0045・0107 |
| **運用のコマンド** | `ec-sugar` のサブコマンドと引数（`status`・`backup`・`restore`・`restore-check`・`update`・`rollback`・`logs`・`operator`・`deploy-key`・`check-front`・`firewall`・`report`・`admin-ips`・`watch`・`install-units`・`shops`）。インストーラー `deploy/install.sh` とその `--answers`。文書に使い方を書いた `php artisan` のコマンド（`operator`・`shop:create`・`shop:sample`・`shop:demo`・`demo:shop`・`storefront:theme`・`storefront:import-theme-content`・`make:plugin`） | [docs/install.md](../install.md)・[making-apps.md](../development/making-apps.md)・ADR 0086（デモの店のコマンド） |
| **答えのファイルのキー** | `MODE`・`ADMIN_HOST`・`ADMIN_ALLOWED_IPS`・`SHOP_HOST`・`SHOP_NAME`・`SHOPS`・`SHOP_DOMAIN`・`SHOP_ID`・`SAMPLES`・`OPERATOR_EMAIL`・`OPERATOR_NAME`・`BIND`・`LAN_CIDR`・`UPDATE_BRANCH`・`AUTO_UPDATE`・`TIMEZONE`・`FRONT`・`PROXY_GROUP`・`PROXY_GROUP_SHARED_OK`・`TUNNEL_TOKEN_FILE`・`TUNNEL_WATCH_TOKEN_FILE`・`CLOUDFLARE_TOKEN_FILE`・`TLS_CERT`・`TLS_KEY` の名前と意味 | docs/install.md の「答えのファイル」 |
| **設定のファイル（env）の項目** | `deploy/production.env.example` に載る項目の名前と意味（`EC_SUGAR_…`。前の名前 `SUGAR_EC_…` も読む）。答えから作る項目は答えが正で、env を直に書き換える使い方は約束しない（install.md のとおり、流し直すと答えの値に戻る） | deploy/production.env.example・docs/install.md |
| **外のサービスに登録する URL** | 店のホストの `/webhooks/stripe`（店の鍵の Stripe）、管理画面でない中央のホストの `/webhooks/stripe-connect`（Stripe Connect。ADR 0022）、App「LINE」の `/p/line/webhook`・`/p/line/callback`。管理画面のホスト（パスキーが結びつく） | 各 ADR・管理画面の案内 |
| **ショップの公開の URL** | 商品・カテゴリ・自由なページ・カート・ご注文の確認・会員・特商法の表記・`/sitemap.xml`・`/robots.txt` と、App のショップのページ（`/p/<名前>`・お知らせの `/news` など）の道筋。お客さんのブックマーク・検索・メールの中のリンクがこれを指す。変えるときは前の URL から転送する | routes/tenant.php・theme-api.md |

### 2. 約束しないもの

- **管理画面の SPA が使う API**（`/api/…`）。SPA と同じイメージで一緒に配る内側の口で、外から呼ぶ使い方を想定しない。theme-api.md の「管理画面の API」の節も、編集の画面のための説明で約束ではない
- **DB の表と列の形**（中央と店の DB）。外の人は DB を直に読まない・書かない（App は自分の名前の表だけを持ち、核のデータは核のサービスを通す）
- **核のクラスの中身**（`app/Core`・`app/Admin`・`app/Storefront` のサービス・モデル・名前空間。上の表に挙げた口を除く）。自社の App とテーマはこのリポジトリにあるので、核と一緒に直す
- **画面とログの文**（管理画面・ショップ・メールの文言と見た目、`ec-sugar status` などの表示の形、ログの文）。機械で読む前提にしない
- **内部のコマンド**（定期の処理の `*:run`・`operations:*`・`events:relay` など、スケジューラと運用の道具が呼ぶもの。文書で手で流す例を挙げたものも、約束は引数の無い形だけ）
- **テストの部品**（`tests/Support`）と開発の道具（Makefile・`make doctor`）

### 3. バージョンの付け方

- **semver、タグ `vX.Y.Z`**（ADR 0035）。タグは署名つき（`git tag -s`）で、`v*` のタグは消せない・書き換えられない（ルールセット）
- **最初のタグは `v1.0.0`**（`App\Core\Version::CORE` と同じ。ADR 0098 で `v0.1.0` から置き換わった）。それまでの main は CHANGELOG の「未リリース」。リリースするときは `Version::CORE` を同じ番号にそろえ、CHANGELOG の見出しを付けてからタグを付ける
- 番号の上げ方:
  - **パッチ**（0.1.0 → 0.1.1）: 直しと守りだけ（セキュリティの自動の更新が入れてよいもの）
  - **マイナー**（0.1 → 0.2）: 追加と変更。**0.x の間は壊す変更もマイナー**
  - **メジャー**（1.0 から）: 壊す変更
- **テーマ API・プラグイン API の番号は整数で、核のバージョンとは別**。付け足し（新しい口・新しく渡す値・任意の新しいキー）では上げない（今までの「テーマ API 2 の付け足し」と同じ）。今のテーマや App が書き直さないと動かなくなる変更でだけ上げ、上げたリリースは壊す変更として番号を上げる
- マニフェストの `core`（例 `^0.1`）は今は管理画面に見せるだけで確かめていない。更新の前に確かめるのは

### 4. 壊す変更の扱い

- 約束の口を変えるときは**二段**: 新しい形を足す → 古い形も読み続け、CHANGELOG で非推奨と消すバージョンを知らせる → **次のマイナーリリース以降で**消す（少なくとも 1 つのマイナーの間は両方が動く）。マイグレーションの expand / contract と同じ考え方。今の例は旧キー `extra.sugar-ec` と前の名前 `SUGAR_EC_…`
- **API の番号を上げるとき**は、前の番号のテーマ・App も同じ間は受け付ける。今の登録簿は 1 つの番号だけを受けるので、初めて上げる時に「前の番号を読む道」を作る（その時の ADR に書く）
- **非推奨の知らせ方**: (1) CHANGELOG の「変更」に「非推奨」と、消すバージョンを書く (2) 動いているサーバーでは、古い形を読んだ時に**アプリのログへ警告**（登録の時に 1 つの古い形につき 1 回。お客さんと店の人の画面には出さない）。今ある 2 つ（`extra.sugar-ec`・`SUGAR_EC_…`）にはまだ警告を出していない。消す前のリリースで足す
- **公開の前（v0.1.0 の前）**は、約束の口も 1 回で変えてよい（テーマ API 1 → 2 を区切り 2c で 1 回で変えた例。ADR 0006 の列を消す移行と同じ扱い）。ただし CHANGELOG の「壊す変更」に書く
- **セキュリティの直しは例外**: 守るために、約束の口でも予告なしに狭めることがある（危ない値を断る・経路に守りを足す）。その時も CHANGELOG の「守り」と「壊す変更」に書く

### 5. データとマイグレーション

- **マイグレーションは前へだけ**。戻すときは `down` でなく、直す新しいマイグレーションを足す（本番の `ec-sugar rollback` はイメージだけを戻して DB はそのままなので、前のバージョンが新しい DB の上で動ける形にしておく）
- 公開の日から列や表を消す・名前を変える変更は二段（足す → 次のバージョンで消す）。見張りは `MigrationsExpandContractTest` と `tests/Support/launched-on.txt`（ADR 0006 の「作って決めたこと」）

### 6. セキュリティの直しと更新

- 今の決まりのまま: **セキュリティの直しは既定で自動**、マイナー・メジャーは任意（CONCEPT 7.3・ADR 0006）。ただし署名つきの更新ができるまで、本番の自動の更新は既定で切る（ADR 0006 の 2026-10-31 の追記。`AUTO_UPDATE`）
- セキュリティの直しはパッチで出す。CHANGELOG の「守り」には、直したバージョンが出た後に、入れて使う人が判断できるだけを書く（直す前の弱点の詳しい中身・攻め方は書かない）

### 7. CHANGELOG

- リポジトリの直下の **CHANGELOG.md**。日本語・利用者（テーマと App を作る人・入れて使う人・店の人）に向けた言葉（docs/design/terms.md）で、PR ごとでなくリリースごと。分類は **追加・変更・直し・守り・壊す変更**
- **利用者に見える変更を入れる PR は、同じ PR で「未リリース」の節に 1 行足す**（変更を入れるときの完了条件）。テスト・CI・内輪の文書だけの変更は書かない
- 他社の製品名・秘密・個人情報・内部のホスト名を書かない（terms.md・ADR 0035 の本文の決まりと同じ）

### 8. 見張り

- `tests/Unit/CompatibilityPromiseTest.php` が、テーマ API とプラグイン API の今の番号（登録簿の定数）が、この ADR の要点と、テーマ API の文書の見出し（「第 N バージョン」）に書いた数字と同じかを確かめる。番号を上げて約束の文書を直し忘れると落ちる

## 結果

- 得たもの: テーマと App を作る人・入れて使う人が、何に頼ってよいかと、変わる時にどう知らされるかを 1 か所で読める。作る人は、変える前に「約束の口か」を表で確かめられる
- 失ったもの・気をつけること: 約束の口を変えるたびに、二段の手間（古い形を読み続ける・警告・消す時期）が掛かる。約束の口は公開の前からなるべく増やさない（口を足すときは、上の表の対象のどれに入るかを決める）。CHANGELOG は書き忘れると意味が無いので、PR の完了条件と確かめで拾う

## 運営に確かめたいこと（既定で進めた。違えば直す）

1. **最初のタグ**（**決まった 2026-11-12、ADR 0098**: パッケージを配る `v1.0.0`。先行公開 `v0.1.0` は無くなった）: 先行公開の日に `v0.1.0` を付ける（既定）か、今の本番（2026-11-11 の main）に `v0.1.0` を付けて始めるか
2. **0.x の間の番号**: 壊す変更はマイナー、直しと守りはパッチ（既定）でよいか
3. **古い形を読む期間**: 「次のマイナーまで」（既定）で足りるか。外の人のテーマや App が出たら長くする（例 2 つのマイナー・90 日）か
4. **旧キー `extra.sugar-ec` を消す時期**: 外のパッケージはまだ無い。v0.1.0 でも読み、v0.2.0 で消す（既定。その前に警告を足す）か、v0.1.0 の前に消すか。前の名前 `SUGAR_EC_…` は install.md のとおり公開の前に消す
5. **非推奨の警告の出し先**: アプリのログだけ（既定）か、`ec-sugar status` と運営の様子にも出すか
6. **前のバージョンが取ったバックアップを戻せること**（`ec-sugar restore`）を約束に入れるか。今は確かめるテストが無いので入れていない（既定）。入れるなら で確かめを足す
7. **ショップの公開の URL** を約束に入れた（変えるなら転送）。これでよいか
