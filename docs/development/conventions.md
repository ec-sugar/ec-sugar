# 書き方の決まり

EC-SUGAR のコードの書き方の決まり。設計の約束（[CONCEPT](../CONCEPT.md) の 7.2）と ADR を、コードを書くときの形に直したもの。コードを書く前に、触る範囲の節を読む。
決まりの多くはテストや静的解析が自動で監視している（各項目の末尾に書いた）。検査に引っかかったら、検査を緩めずにコードを直す。

## 1. データ

- 金額は整数（最小通貨単位。円なら 1 円）。浮動小数は使わない。消費税は税率ごとに 1 回だけ丸める
- テナント DB のモデルは ULID 主キー（`HasUlids`）、時刻は `timestampTz`
- 個人情報の列は `TenantEncrypted` / `TenantEncryptedJson` キャストで暗号化し、検索が要る列はブラインドインデックス（`HasBlindIndexes`）。平文の個人情報を新しい列やイベントの payload に入れない（ADR 0010）
- 規則は DB でも守る（制約・トリガー）。アプリの検査をすり抜けても DB が拒む形にする（例: 在庫が負にならない、仕訳の貸借、追記専用の表）
- 自由記述の理由をイベントに残さない。理由は列挙型（例: `AdjustmentReason`）

## 2. テナント

- テナントのデータに触るコードは必ずテナント文脈（tenancy）の中で動かす。中央 DB からテナント DB を直接なめない
- 分離は DB の権限で担保する（テナントごとの専用ロール、中央アプリは `ec_app` で非 superuser）
- 管理画面からテナントに入る経路は `EnterTenant`（`/api/tenants/{tenant}/…`）の下だけ。AI エージェントの MCP のエンドポイント（`POST /mcp`）だけは `AuthenticateAgent` の下で、鍵が決めた店に入る（ADR 0100。`RouteAudit` の規則 16 が監視する）。変更の actor は `TenantActor::of($request)`（ADR 0014）
- **テナントの経路では、controller の引数でモデルを結びつけない**。`SubstituteBindings` は `EnterTenant` より前に動き、中央 DB を引く。ID は文字列で受け取り、テナント文脈の中で探す（`RouteAudit` が監視する）
- **管理画面の外から店の文脈に入る道**（ショップの要求・定期の処理・決済代行の Webhook・CLI のコマンド。2026-10-31 に今のコードから書いた）:
  - 入り方は 3 つだけ: ショップの要求はホストの名前から（`InitializeTenancyByDomain`）、店を順に回る定期の処理は `tenancy()->runForMultiple(…)`、1 店に入る処理（Webhook・`operations:collect`・CLI）は `tenancy()->initialize($tenant)`。`initialize` で入ったら、**必ず `try … finally { tenancy()->end(); }` で閉じる**（例外の後に、次の店・次の要求へ文脈を残さない。`StripeWebhook`・`CollectOperationsCommand`）。文脈の中からさらに別の店に入らない
  - どの店に入るかは、入る前に中央で決める（Webhook は中央の一意の対応から、定期の処理は店の一覧から）。入った後も、店の側の値で確認し直す（例: Connect の通知は、店の側でも同じ連結アカウントに接続しているか）
  - 中では**店の書き込みをしてよい**。決まりは管理画面からと同じ（下の 3: チェーンのロック・サービス経由・同じトランザクションのイベント）
  - 中では**中央の表を読んで店の中の判断に使わない**（入った店そのものの `tenant()` の値〔名前・ドメイン〕は除く）。中央に触れるのは決まった口だけ: 監査ログ（`AuditLog`）・Stripe Connect の店の対応（`StripeConnectedAccount`、ADR 0022）・運営の数のキャッシュ（`ShopDigests`。件数と金額だけ、ADR 0042）。口を足すときは ADR に書く
  - イベントの actor（誰がしたか）: 人が実行するコマンドは `cli:<名前>`（`cli:shop-sample`、ログインの利用者の名前を付ける `cli:<利用者>`）、定期の処理は `system:<名前>`（`system:refunds`・`system:expiry`・`system:connect`）、決済代行の通知は `webhook:<決済代行>`、ショップのお客さんの操作は `storefront`、AI エージェントの鍵がすぐ反映した変更は `agent:<鍵の ID>`（ADR 0101。誰の鍵かは中央の鍵の表と監査ログ）。actor に個人情報を入れない

## 3. 書き込みとイベント

- 書き込む操作は、最初に `EventStore::lock()`（チェーンのロック）を取ってから行ロックを取る（デッドロック回避。ADR 0012）
  - 例外は店頭のカートだけ（ADR 0021）: 取引の前の下書きなので、チェーンのロックもイベントも使わず、そのカートの行のロックで守る。注文の確定からは必ずこの決まりに戻る
  - もう 1 つの例外は、定期チェックの順番のためだけの印（`checkout_orders.expiry_checked_at`・`refunds.checked_at`。払われた注文に残った支払いの画面の定期チェックの `payment_attempts.leftover_checked_at`）: 定期の確認が「最後に見た時刻」を 1 行の 1 列だけ書く。状態は変えず、イベントにも残さない。足すときは同じく「状態でない・1 文で 1 行」に限る
  - **例外にしてよい条件**（3 つとも満たすときだけ。2026-10-31 に ADR 0012 と一緒に書いた）: ① 取引・在庫・お金などの**状態でない**（下書き・定期チェックの印・送った記録）② **お金と在庫に触れない**（Ledger・在庫の数・返金・支払いの行を書かない）③ **イベントが要らない**（監査や帳票の源泉にならない。後から「誰が何を変えたか」を問われない）。防御はその行のロック（`FOR UPDATE`）か一意の制約で掛ける。満たさないものは、遅くてもチェーンのロックとイベントの決まりに従う
  - **今の例外の一覧**（2026-10-31 にコードで確認した。足したらここに書く）: 店頭のカート（`carts`・`cart_lines`、ADR 0021）、定期チェックの時刻のフラグ（上の 3 つ）、送ったメールの記録（`sent_mails`。同じ注文の同じ通知を 1 回だけにするフラグで、行のロックで守る。`SendOrderMail`）、会員のログインのコード（`customer_login_codes`。確認する時は行のロックで試した数を数える。`CustomerLogin`）と会員のセッション（`customer_sessions`。Cookie のトークンのハッシュと期限・再確認の時刻・入り方〔`via`。ADR 0070〕。`CustomerSessions`）、パスキーで入った時の数え上げと最後に使った時刻（`customer_passkeys` の `credential`・`last_used_at`。行のロックで。`CustomerPasskeys::verify`。ADR 0044）。定期便の「もうすぐ次の回」の通知を送ったフラグ（`subscription_rounds.noticed_at`。状態ではない時刻のフラグを 1 文の条件つきの更新で。`RoundProcessor`。2026-11-03 第 2 回で足した）。App「再入荷のお知らせ」の登録（`restock_requests`。お客さんが依頼した通知の予約で、公開の受付から誰でも作れる行のため追記だけの記録に積まない。行のロック・部分の一意の索引・1 文の条件つきの更新で守る。`RestockRequests`・`RestockNotifier`。2026-11-04、ADR 0025 の作って決めたこと）、商品の CSV の取り込みの記録（`product_imports`。確認・反映の仕事の状態と進み具合。「今の状態がこれなら」の 1 文の更新で取り合い、反映は店に 1 つだけを部分の一意の索引で守る。商品・在庫の変更そのものはコアのサービスがチェーンのロックとイベントで行う。`ProductImports`。2026-11-04）。App「店への通知」の送った通知の記録（`shop_notify_notices`。同じ通知を同じ宛先へ 1 回だけにするフラグで、一意の制約と行のロックで守る。`SendOrderMail` の `sent_mails` と同じ形）と、在庫が少ないと通知した種類の印（`shop_notify_stock_marks`。1 文の「無ければ入れる」で付け、数が戻ったら消す）。どちらも `ShopNotices`・`LowStock`（2026-11-05、ADR 0060）。App「メルマガ・一斉メール」の購読と送った記録（`newsletter_subscribers`・`newsletter_deliveries`。購読はお客さんが依頼した通知の予約で、公開の受付から誰でも作れる行。同意の記録は行そのもの。送った記録は「送ったか」のフラグ。行のロック・部分の一意の索引・1 文の条件つきの更新で守る。配信〔`newsletter_campaigns`〕は店の人の操作なので例外にしない。`NewsletterSubscribers`・`NewsletterDispatcher`。2026-11-05）。App「カゴ落ちのお知らせ」の送った記録（`cart_reminders`。払われずに消えた注文ごとに 1 行の「送ったか」のフラグで、注文ごとの一意の制約・行のロック・1 文の条件つきの更新で守り、同じメールの 7 日の決まりはメールの索引ごとの `pg_advisory_xact_lock` の中で確認する。取引・在庫・お金には触れず、カートを作り直すのはコアの `CartService`。`CartReminders`・`ReminderSender`・`CartRestore`。2026-11-05、ADR 0067）。App「お気に入り」の印と通知（`favorites_items`・`favorites_settings`・`favorites_prices`・`favorites_notices`。印は会員が付けたもので、状態でもお金と在庫でもなく監査の源泉でもない。会員ごとの `pg_advisory_xact_lock` の中で数えて足し〔1 人 500 件〕、会員 × 商品の一意の制約で二重を拒む。前の日の値段のスナップショットは日に 1 回だけ書き換え、送った記録は会員 × 日の一意の制約と 1 文の条件つきの更新で守る。`Favorites`・`FavoriteNotices`・`SendFavoriteNotice`。2026-11-05、ADR 0071）。App「おすすめのセクション」の売れ筋の数え（`recommendations_ranks`。注文から数え直した結果のコピーで、店ごとのロックの中で丸ごと入れ替える。`Rankings`。同）。App「予約」の前日の通知と取消の通知を送ったフラグ（`booking_reservations.reminded_at`・`cancel_noticed_at`。同じ予約の同じ通知を 1 回だけにするフラグで、予約の行のロックの中で見て、送れたら付ける。`SendOrderMail` の `sent_mails` と同じ形。予約・枠・来店の記録はチェーンのロックとイベントで、例外にしない。`SendBookingMail`。2026-11-05、ADR 0056）。もう 1 つ、App「予約」のお客さんの取消（先払い）と仕組みの取消（`CancelBookingOrder`）の `booking.cancelled` は、コアの注文の取消（返金を決済代行へロックの外で依頼する 3 段階の手順）と同じトランザクションに入れられないので、取消の確定の後のトランザクションで残す（取消そのものはコアの `order.cancelled` が同じトランザクションで残す。当日払いで払う前は同じトランザクションで残す）。App「デジタルチケット」の受付で読んだ QR の値の印（`tickets_scans`。同じ値の 2 度目を断るためのフラグで、チケットの行のロックと〔チケット・30 秒の番号〕の一意の索引で守る。受け付けた時に使った時刻を入れる）と、QR を出したセッションの記録・同時表示の記録（`tickets_views`・`tickets_alerts`。監視の記録で、1 文の「無ければ入れる・あれば最後の時刻を直す」と、同じチケットの通知はチケットの行のロックの中で 10 分に 1 つ）。どれもお金と在庫に触れず、状態ではない。受付・無効・発行・注文・設定は例外にしない（`TicketReception`・`TicketDisplay`。2026-11-05、ADR 0069）。App「LINE 連携」の友だちのフラグと送った記録（`line_links.friend`・`friend_at_ms` と `line_messages`。友だちのフラグは「送ってよい相手か」の印で、LINE の Webhook の通知の時刻が新しい時だけ 1 文の条件つきの更新で変える。送った記録は同じ注文の同じ通知を 1 回だけにする「送ったか」のフラグで、一意の索引と `insertOrIgnore` で守る。LINE を接続する・外すはログインの手段の足し引きなので例外にしない。`LineLinks`・`LineNotifier`。2026-11-05、ADR 0070）。App「会員ランク」の上がった通知を送ったフラグ（`member_ranks_changes.notice`・`noticed_at`。同じ変わりの通知を 1 回だけにするフラグで、1 文の条件つきの更新で守る。ランクそのもの・手の上げ下げ・会員だけの商品は会員価格＝お金に効くので例外にしない。`SendRankNotice`。2026-11-05、ADR 0079）。App「抽選販売」の申し込みの `updated_at` だけの書き換え（Stripe に届く前に断られた当選の請求を、1 時間ごとに依頼し直す間を空けるための時刻のフラグ。状態は変えず、ロックの中で書く。`LotteryCharges::apply`）と、毎分の処理が最後に見た時刻のフラグ（`lottery_entries.checked_at`。定期チェックの順番のためだけに 1 文で書き、`updated_at` も変えない。`LotteryRunner`）。2026-11-06、ADR 0080。抽選・申し込み・当選の請求の状態は例外にしない。ショップの注文の接続元とメールの印（`checkout_orders` の `source_index`・`source_wide_index`・`email_mark`。店の鍵の HMAC で、入金待ちとメールの 1 時間の確定の数を数えるためだけ。注文の確定のロックの中で付け、払える期限のいちばん長いもの＋1 日＋猶予〔`UnpaidOrderLimits::markSeconds`、今は 32 日〕を過ぎたら期限切れの定期チェックが 1 文の更新で空にする。状態でもお金と在庫でもなく、監査の源泉でもない。`UnpaidOrderLimits`・`ExpireUnpaidOrdersCommand`。2026-11-06、ADR 0085）。デモの店（ADR 0086）の止めたメールのコピー（`demo_mails`。来訪者の受け箱に置く見本のコピーで、状態でもお金でもなく監査の源泉にもならない。受け箱ごと・店ごとの数の上限で古いものを消す。`DemoMails`）と、デモの店を作るスクリプトの手順のフラグ（`demo_steps`。再実行で二重に作らないための目印。中身そのものはコアのサービスがチェーンのロックとイベントで作る。`DemoShopBuilder`）。2026-11-06。記録の受信側ごとの印と届け直しの行（`event_receiver_positions`・`event_retries`。「どこまで配信したか」と「届け直すか・止めたか」の印で、状態でもお金と在庫でもない。1 店で 1 本だけが配信する〔店の DB のセッションのアドバイザリロック〕中で書く。`EventRelay`。2026-11-06、ADR 0089）。店が送ったメールの記録（`mail_records`。送る口が書く「送ったか」の記録で、状態でもお金と在庫でもなく監査の源泉でもない。1 文の更新で直し、送る所がトランザクションの中なら同じ設定の別の接続で書く。`MailRecords`。2026-11-11、ADR 0095）。パスキーと届け先を足す・消すは例外にしない（チェーンのロックとイベント）。会員になった印（お客さんの記録の `member_since`）は状態なので例外にしない
- ドメインイベントは永続化する（監査・帳票・通知・AI 評価データの源泉）。書くのは `EventStore::append` だけ、状態の変更と同じ DB トランザクションで
- **記録の後の仕事（メール・通知・発行・App の処理）は、名前のある受信側で受ける**（ADR 0089）。`EventReceivers::on()`（記録の種類）か `notice()`（コアの通知 `PaymentConfirmed` など）で登録し、送り出し役（`EventRelay`）がコミットの後に届け、落ちたら毎分の定期チェック（`events:relay`）が届け直す
  - **受信側は冪等に書く**: 同じ記録が 2 回届いても同じ結果（メール 2 通・ポイント 2 回・チケット 2 枚・請求 2 回にしない）。「今の状態がこれなら」をロックの中で確認してから進める、送った記録の一意（`sent_mails` など）を先に作る、のどちらか
  - 続けられない時は**例外を投げる**（握りつぶして `report()` だけにすると届け直さない）。来なくてよい記録（自分の注文でない・もう済んだ）は何もせずに戻る（例外にしない。5 回で止まって要対応に出てしまう）
  - 時刻を比べる受信側は「今」でなく記録の時刻（`occurred_at`。`notice()` の 2 つ目の引数）で比べる（届け直しで遅れて届くため）
  - 要求の文脈（Cookie・セッション）が要るものだけは、同じプロセスの中だけの `DomainEventRecorded` で受ける（届かないことがある。届かなければ何もしない形に）
  - コアの通知を出すのは `EventStore::notice()`（状態の変更と同じトランザクション）。`event(new PaymentConfirmed(…))` で直に出さない（テストで受信側を模擬で動かす時だけ）
- 状態はサービス経由でだけ変える。モデルを直接書き換えない
  - 取引・明細・仕訳・配送: `TransactionService` / `LedgerService` / `FulfillmentService`
  - 人が行う商品・在庫の変更: `ListingService`（バージョンによる楽観ロック）/ `InventoryOperations`
- 人の操作のイベントには actor を残す（運営は `operator:<id>`、CLI は `cli:<名前>`）

## 3b. 処理の追跡 ID（ログとキュー。ADR 0087）

- 処理の ID（trace ID、ULID の小文字）は Laravel の Context のキー `trace_id` に置く。決めるのは決まった所だけ: 要求は `AssignTraceId`（いちばん外側）、定期の処理は処理ごと（`ScheduledTaskStarting`）、CLI はコマンドの始まり（`CommandStarting`）、キューの仕事は積んだ時の ID が戻り、無ければ仕事ごと（`CoreServiceProvider`）。**外から来る `X-Request-Id` などで決めない**
- 自分で ID を作って配らない・`Context::flush()` を本番のコードで呼ばない（持ち越しが切れる）。決まった ID の下で動かす時は `TraceId::within($id, fn)`（後で積んだものを送り出す処理など）
- **キューの仕事に ID や店の ID を引数で渡さない**。積む時の Context と店の文脈が、処理する時に戻る（店の ID は stancl/tenancy、ID は Laravel）。仕事の中で店に入り直さない
- ログには Context の `trace_id` と、店の文脈の中なら `tenant_id` が自動で付く。**Context に個人情報・秘密・自由な文を足さない**（全部のログの行と、キューの仕事の中身に載る）。足してよいのは ID と店の ID のように、意味を持たない識別子だけ
- 記録の表に処理の ID を残すときは `trace_id` の列（空でよい・ULID の形の制約・値のある行だけの索引）を足し、モデルに `RecordsTrace`（作る時にコピーする）。ハッシュチェーンの本文には入れない（`EventStore`・`AuditLog` は本文の外に添える）。今コピーしている表: `domain_events`・`audit_events`・`incidents.last_trace_id`・`sent_mails`・`mail_records`・`checkout_plans`・`refunds`・`webhook_events`
- 外のサービス（Stripe・Slack・LINE など）へ ID を送らない（メタデータ・見出しに載せない）。接続するのはこちらの記録の中だけ

## 4. 経路と API

- 経路を足すときは、ログインを求める（`auth:…`）か公開のマーク（`->middleware('public')` と理由のコメント）のどちらかを必ず付ける（`RouteAuthorizationTest` が全経路を検査する）
- 生年月日など、残さない値を受ける画面では、引数なしの `withInput()` を使わない（`withInput($request->except('age.birthdate'))` のように除く）。例外の道を通らない戻しでは `dontFlash` が効かず、入れ直しの値としてセッションに残る（ADR 0077）
- 入力は FormRequest で検査する。件数・長さ・数の範囲に上限を付ける。並べ替えなど列の名前は利用者の入力から組み立てず、決まった候補の写像にする
- 管理画面の受付で、壊れた文字（不正な UTF-8）と NUL 文字は 422 にする（`RejectMalformedInput`）。店頭の API にも入れる
- コアの規則違反・重複は 500 にせず 422 に、同時編集の衝突は 409 に変換する

## 4b. セキュリティの段階（ADR 0081）

防御は「用意された部品を、新しい機能を作る人が呼ぶ」から、「対象の機能にはコアが自動で掛ける。掛からない書き方はテストが落とす」へ進めた（2026-11-06、外のレビューの総評「既存の対策を新機能で適用し忘れた種類の問題は、共通処理や自動検査で防ぐ」）。
要求は下の段階を順に通る。必須の防御は**ミドルウェアかサービス**で強制する。実行の順が決まらないイベントやフック（App の口）には任せない。App が足すのは通知・集計などの付け足しだけ。

| 段階 | コアが自動で掛けるもの | 宣言で掛かるもの（経路に書く） | 自分で書くもの |
|---|---|---|---|
| 受付 | 防御ヘッダと CSP（`SecurityHeaders`・`StorefrontSecurityHeaders`・`AdminSecurityHeaders`）、本文の大きさ（nginx 12m。大きな本文は決めた経路だけ）、壊れた文字と NUL（`RejectMalformedInput`）、セッションの群の CSRF | 回数の上限 `throttle:<名前>`（ショップで書き込む経路は必須）、公開のマーク `public` か `auth:…` | コントローラーの中の細かい回数の上限（メールごと・接続元 × メールなど）はコアの `Throttle` で |
| 店の特定 | ホストから店へ（`InitializeTenancyByDomain`）、中央のホストを断る、管理画面は `EnterTenant` の下だけ（MCP は `AuthenticateAgent`）。App の経路は `/p/<名前>` の下に `plugin:<名前>` を付けてコアが置く（`PluginRoutes`） | — | 経路の引数でモデルを結びつけない（ID は文字列で受け、店の中で探す） |
| 認証・認可 | 管理画面の接続元の制限（`admin` の群）、店の API の運営の確認と権限の段階の照合（`EnterTenant`） | `auth:…`・`tenant-level:<段階>`。**秘密のパス** `secret-link:signed` はコアが署名（期限・店の ID）を確認する | 署名か会員のどちらかで開く画面・パスのトークンの照合（`SecretLinks::valid`・App の表） |
| 入力検証 | 壊れた文字（受付で） | — | FormRequest かコントローラーの検査（件数・長さ・数の範囲の上限。列の名前は候補の写像） |
| 処理 | 書き込みの決まり（チェーンのロック・サービス経由・同じトランザクションのイベント。3 章） | — | 決まりに沿った書き方。例外にする時は 3 章の条件 |
| 応答 | ショップの画面の CSP と App のタグの差し込み（個人情報の画面には差し込まない。書き忘れは差し込まない側に倒れる） | **秘密のパス** `secret-link`: 参照元を渡さない（`no-referrer`）・残さない（`no-store, private`）・検索に載せない（`noindex`）をどの応答にも（403・404・419・429・転送も） | 応答ごとに秘密を持つ時と持たない時がある経路だけ `StorefrontSecurityHeaders::withoutReferrer`（公開の写真の経路のプレビュー） |

**新しい経路・機能で自動で効く防御**（何もしなくても掛かる。外す書き方はテストが落とす）:

- 防御ヘッダ・CSP・本文の大きさ・壊れた文字・CSRF・店の特定と中央のホストの拒否・App の経路の `plugin:<名前>`
- 秘密のパスの 3 つ（`no-referrer`・`no-store`・`noindex`）: 宣言した経路のどの応答にも。署名つきの URL の行き先・パスにトークン（`{token}` など）を持つ経路・Laravel の `signed` を通る経路は、宣言が無いと全部の経路を調べるテストが落ちる
- 署名つきの URL の防御: 作るのはコアの `SecretLinks::signed` だけで、行き先の宣言・店の ID・期限（31 日まで）を部品が守る。確認するのも `SecretLinks::valid` だけ
- 回数の上限の数え方: 数えるのはコアの `Throttle` だけ（先に数えてから比べる。同時の要求でも上限を越えない）。足し算は `AtomicRateLimiter`（Laravel の RateLimiter と差し替え。経路の `throttle:` も同じ）

**自分で付けないといけない防御**（コアが知らないので書く。付け忘れの多くはテストが落とす）:

- 経路の `public`（理由のコメントつき）か `auth:…`（`RouteAudit` の規則 1〜9）
- 秘密のパスの宣言 `->middleware('secret-link')`（`secret-link:signed` なら確認もコア）（規則 10〜12）
- ショップで書き込む経路の `throttle:<名前>`（規則 13。数えなくてよい経路は `RouteAudit::UNTHROTTLED_WRITES` に理由つきで）と、コントローラーの中の細かい上限（`Throttle::firstOver` に狭いものから渡す。店の中のキーは `Throttle::shopKey`）
- CSRF を外すのは、トークンか署名で確認する経路だけ（規則 15。`RouteAudit::WITHOUT_CSRF` に理由つきで）
- 入力の上限（件数・長さ・数の範囲）・個人情報の暗号化・イベントの actor（1〜3 章）。自動では監視できないので、見直しの目で見る

監視は `tests/Support/RouteAudit.php`（全部の経路を一度に調べる。`RouteAuthorizationTest`）と `tests/Unit/SharedGuardsTest.php`（部品の外で `tooManyAttempts`・`RateLimiter::hit`・署名つきの URL・`Referrer-Policy` を書いていないか、大きな本文を受ける所を、コードから静的に探す）。例外の一覧に、もう要らないものが残ると落ちる。

## 5. 管理画面の画面

- 見た目の決まりは [docs/design/admin-ui.md](../design/admin-ui.md)。色はトークンだけ、Tailwind のクラスは `components/ui` の部品の中、画面（`pages/`）は並べ方だけ（`AdminShellTest` が監視する）
- 画面の経路は `resources/admin/router.js` と `routes/admin.php` の殻の経路の両方に足す
- `v-html`・`innerHTML`・`:style`・外部の URL・localStorage は使わない（CSP と Trusted Types で止まる。ADR 0016）
- 本番で Node.js を常駐させない。Node.js はビルドの時だけ（ADR 0015）

## 5b. 店頭

- 店頭の仕組みは `app/Storefront`、見た目はテーマ（`plugins/theme-*`）。テーマは DB に触らず、店頭が渡すデータだけを出す（[docs/design/theme-api.md](../design/theme-api.md)。`ThemeTest` が検査する）
- 店頭の経路は 'storefront' ミドルウェア群の下に足す（防御ヘッダ → テナントの特定 → 中央のホストを断る → 壊れた文字を断る → セッション・CSRF）。公開のマークと理由を付け、モデルを結びつけない（`RouteAudit` の規則 8）
- **個人情報を出す経路（読む画面も送る受付も）には印 `navigation-only` を付ける**（ADR 0088）。ブラウザが画面を開く要求のほか（fetch・XHR・枠）は中身の無い 403 になり、App のタグの JavaScript が同じオリジンから読めない・送れない。会員のガード（`auth:customer`）の経路・パスに `account` の区切りを持つ経路・`{orderId}` を持つ経路は付け忘れると `NavigationOnlyRoutesTest` が落ちる。ほかで個人情報を出す経路（申し込みの流れ・会員のメールを出すフォーム）も付け、同じテストの一覧に足す。App の経路も同じ（経路に `->middleware('navigation-only')`）
  - マークの付いた画面の中でコアの JavaScript が同じ画面の一部を fetch で読む時だけ `navigation-only:page-key` にし、画面の鍵（`PageKey::forPage(request())` を `data-sugar-page-key` に書き、見出し `X-Sugar-Page-Key` で添える）で通す。足すときはテストの一覧の `page-key` にも書く。ふつうの画面（タグを差し込む画面）から fetch する部品（カート・郵便番号・お気に入りのハート）はマークの付いた経路に送らない
- 店頭に出すのは公開中・固定価格・売る側の商品だけ。在庫の数そのものは出さない（在庫あり・残りわずか・売り切れ）
- 店頭の CSP は `config/storefront.php`。外部のスクリプトを読み込まない（支払いは Stripe の画面へ移る形。ADR 0019）

## 6. 依存とビルド

- **部品どうしの依存の向き**（2026-11-01、運営の判断。設計を簡素に保つため。`tests/Unit/ArchitectureTest.php` が監視する）: コア（`app/Core`）が一番下で、管理画面（`app/Admin`）とショップ（`app/Storefront`）はその上に並ぶ。コアは管理画面・ショップ・Web のエンドポイント（`app/Http`）を知らない（例外なし。両方が使うものはコアに置く。テーマのレジストリは 2026-11-01 にショップからコアへ移した）。管理画面とショップは互いを直に使わない（今ある例外はテストの一覧に理由つきで書いてあり、増えると落ちる）。コアのコンテキストどうしで新しく互いに頼り合うと落ちる（今ある 9 組はモデルどうしのつながりが中心。取引と支払いの頼り合いは で無くした）。**注文の流れ（`Core\Order`）はコアのいちばん上の層**: 取消・返金の定期チェックのように取引・支払い・配送をまたいで進める処理はここに置き、下の層（取引・支払い・配送など）からは頼らない。定期便の毎回の請求もここに乗せる。減ったらテストの一覧からも消す
- **文脈は責務で割る。「その他」を作らない**（ADR 0107 の段階 2。`ArchitectureTest` の `SPLIT_EDGES_ALLOWED` が監視する）: `Core\Shop` は店そのもの（設定・公開の状態・送料・SEO と転送・配送日・AI エージェントの設定）だけで、セクションとページは `Core\Section`、テーマの中身は `Core\Theme`、入れたときのサンプルとデモの店は `Core\Sample`、写真の保存先と上限は `Core\Media` に置く。**店からこの 3 つ（セクション・テーマ・サンプル）を使わない**（例外なし。逆向きは可）。文脈をまたいで束ねる読み取り（開設の手順のような画面のための集計）はコアに置かず、呼ぶ側（`app/Admin`・`app/Storefront`）へ寄せる
- 大きくなったファイル（600 行を超える）は、次に触る時に役ごとに分ける。新しく作る時は、1 つのクラスに 1 つの役

- 新しいパッケージは、保守状況・公開から 7 日以上・既知の脆弱性・署名を見てから入れる。バージョンは固定する
  - PHP の拡張は本番のイメージ（Dockerfile）・CI・手元でそろえる（`EnvironmentParityTest` が手元と CI の PHP を検査する）。npm も手元と CI で同じバージョン（11.21.1。2026-10-27 に署名の確認の不具合で上げた）
  - composer: `roave/security-advisories` が既知の脆弱なバージョンを拒む。対象 PHP は本番イメージに合わせて `platform.php` で固定（8.5.12）。手元の PHP も同じ以上に保つ（`brew upgrade php`。PHP 本体の脆弱性は composer audit では検知されない）
  - npm: `save-exact`、`npm ci --ignore-scripts` だけで入れる。`npm audit`（`ops/dev/npm-audit.sh`）と `npm audit signatures`
  - **直った版がまだ無い npm の advisory だけ、理由と見直しの日つきで `security/npm-audit-accepted.json` に載せれば通せる**（2026-11-18、運営の判断。picomatch の ReDoS が最初の例）。載せる条件は 3 つとも: ① 上げれば直る版が出ていない（`npm audit fix` で直るものは出たら検査が落ちる） ② **本番に届かない面**である（EC-SUGAR は本番で Node を動かさない。ADR 0015。ビルドの時だけ通る穴がこれに当たる） ③ `review_by`（見直しの日）を書く。過ぎたら検査が落ちる。もう出なくなった ID を残しておくと、それも落ちる。**検査そのものは緩めない**（載せていない advisory は 1 件でも落ちる）
- GitHub Actions はコミットの SHA で固定する。Dependabot は公開から 7 日待つ
- Docker のポートは必ず `127.0.0.1:` に束ねる（Docker は ufw を迂回する）

## 7. テスト

- **機能ごとにテストを書く**: 正常系に加え、失敗系（不正な入力・権限なし・期限切れ・再送・改竄）、同時実行、境界（テナント・出品者・運営）
- **全テストは手元で実行しない**。手元（macOS）で `make test`・`make test-serial`・`make check`・`make e2e` を叩くと `ops/dev/no-local-test.sh` が拒否し（歯止めと `make test-remote` は開発機ごとの決まりで、`ops/dev/local.mk` があるときだけ効く。公開のツリーには無い）、`make test-work` も FILES が 2 つ以上かフォルダなら拒否する。make を通さない `php artisan test` などは PreToolUse の hook（`ops/dev/hooks/block-local-tests.py`）が拒否する。実行するのは CI（CI のランナー）かホストの docker（`make test-remote`。docs/development/remote-tests.md）で、手元は触った 1 ファイルだけ。理由はホストが 18 GB で、全テストを実行するとスワップが埋まってマシンごと止まり作業が飛ぶため（2026-11-18 に 3 回、11-19 に 1 回）。CI と `make check-docker` のコンテナの中では歯止めは働かない。逃げ道は `ALLOW_LOCAL_TEST=1`（CI が止まっている時だけ）
- 外部の仕組みは本物の検証を通す部品で試す（例: `tests/Support/SoftwareAuthenticator`、ブラウザのテストは Chrome の仮想の認証器）
- テストは Postgres で回す（SQLite は使わない。ADR 0008）。PHP のテストの中では Laravel が CSRF の検査を止めるので、CSRF はブラウザのテストで確認する
- テストを足したら、わざと壊して落ちることを確認する（効いていない検査を作らない）
- **公式テーマの共通の画面は、見た目（class）を除くと 4 テーマで同じ markup に保つ**（`tests/Unit/ThemeSharedMarkupTest.php`）。購入まわりと商品の画面（9 枚）はコピーが 4 枚あるが、黙って食い違うと「既定テーマだけ定義していない色を使う」類の不具合になる（実際になった）。テーマごとに分けたくなったら、テストの `SHARED` から外して理由を書く。コピーを 1 枚にするのは ADR 0028 の段階 3
- **決済代行の印の形（鍵・支払いの画面・連結の口座の頭）をドライバの外で持たない**（`ArchitectureTest` が文字列も監視する）。テストのモードは `payment_attempts.test_mode`、支払いの突き合わせは `provider` と `provider_ref` をそのまま使う。番号の書き方で判断すると、ADR 0107 の段階 3b で別のドライバの店が黙って誤答する
- **タスクの一覧（`docs/tasks/backlog.md`）の形は CI が監視する**（`tests/Unit/BacklogFormatTest.php`）: 済には `2026-11-19` の形の日付を書く（`11-19` は読めない）／印が全部済んだら見出しに `（2026-11-19、PR 123。済）` を足して済にする／**節の中に `###` の見出しを置かない**（太字の行にする。置くとそこから後が「そのタスクの本文」になり、リリース 1.0 の R の項目がまとめて読まれなくなる）。この一覧は進み具合の画面の元なので、形が壊れると画面が黙って嘘をつく
- **Risky は 0 を保つ**（`phpunit.xml` の `failOnRisky="true"`）。Risky は「テストは実行されたが実行のされ方が信用できない」印で、溜めると合図にならなくなる
- **テストの外（`setUpBeforeClass` / `tearDownAfterClass`）でアプリを起動したら、`HandleExceptions::flushState($case)` で閉じる**。`$app->flush()` だけでは起動時に積まれたエラー・例外ハンドラが PHP に残り、PHPUnit は以降自分のハンドラを入れられなくなる（`Runner\ErrorHandler::enable` は積まれていると何もせずに戻る）。その worker の残り全部が Risky になり、**警告と非推奨の検知も止まる**。テストの中で起こす分は Laravel が毎回片付けるので要らない
- PHP のテストの環境変数: phpunit.xml の `force="true"` はシェルから渡した値に負ける。秘密の鍵と外へ出る設定（Stripe の鍵と送信先・本番の鍵を許すか・メールの送り方・外のサービスの鍵・S3 の鍵）は `tests/Support/TestEnvironmentGuard::PINNED` が、アプリを作る前に外から何が渡されても空・決まった値へ上書きする（足すときはここへ。phpunit.xml で force するものも同じ値で載せる。`TestEntryGuardTest` が監視する）。DB・店の DB の頭・写真の保存先（`DB_DATABASE`・`TENANT_DB_PREFIX`・`MEDIA_DIRECTORY`）は外から渡して作業ごとに分けるもので、上書きせず、テスト用でなければ止める
- PHP のテストは並列で実行する（`make test` ＝ `php artisan test --parallel`。ADR 0057）。並べた各々は `ec_test_p<番号>`（作業ごとなら `ec_test_<文字>p<番号>`）の中央の DB・店の DB の頭・写真の保存先を使い、`tests/bootstrap.php` が paratest の番号から決める。並べても揺れないように、テストは決まった名前のファイルや保存先を他のテストと分け合わない（使うなら写真の保存先のように番号で分かれる場所にする。`Storage::fake` は Laravel が番号を付ける）。1 つのファイルだけ・1 つの処理で順にのときは `php artisan test --compact <ファイル>`・`make test-serial`
- テストで作る店の DB は、マイグレーションを実行し終えたテンプレートから複製する（`Tests\Support\TemplatedTenantDatabaseManager`。店のロールと権限は本番と同じ形で、`TenantDatabaseTemplateTest` が一から作った店と比べる）。店を作る流れ（DB・マイグレーション・初めの行）はそのまま動く。マイグレーションを一から実行する道そのものを確認したいテストは `protected bool $tenantDatabasesFromTemplate = false;` にする。店のマイグレーションを足したら、次の実行で型が作り直される（マイグレーションのファイルの指紋で見分ける）
- **全部の App に自動で当たる確認**（App を足しても書き足さずに当たる。App ごとのテストとは別）: `tests/Feature/Plugin/AppContractTest.php` は App ごとに、①使っていない店で App のショップの経路が使う店の関門（`plugin:<名前>`）で 404 になり、止めても開くと宣言した経路（`keptStorefrontRoutes`）だけが関門を通らずに届く。管理画面の App の画面・操作の API も 404 ②ほかの店の記録の ID で App の画面の 1 件・操作・直すが 404 ③閲覧の段階は App の画面の操作が 403、`money` の操作は運用の段階でも 403・会計の段階でも直前の再確認の前は 423 で、断った操作で記録が変わらない ④秘密の設定は店の鍵で暗号化して置き、管理画面の応答・ショップ・記録に中身を出さない、を確認する。`AppDeclarationsTest.php` は DB を使わずに、レジストリと `plugins/` が同じ一覧・マニフェストの宣言（目的・設定の型・秘密・公表・差し込み）がレジストリと同じ・秘密らしい名前の設定に secret の印・ショップの経路に `storefront`・`public`・`plugin:<名前>` の印（止めても開く経路だけは plugin の印が無い）・お金の言葉に当たる操作に `money` の印、を確認する。画面（ADR 0051）を持つ App を足したら `AppContractRecords.php` に画面の 1 件の作り方を足す。決まりに当たるが正しいものと、見つけた不具合の候補は `tests/Support/AppContract.php` の一覧に理由つきで書く（直したら消す。残るとテストが落ちる）。店はクラスで 1 回だけ作って App ごとに使う・使わないを切り替える（速さのため。App 39 個で、手元の 1 つの処理で 26 秒ほど）
- ブラウザのテストは、プルリクエストでは大事な流れだけを実行し、スクリーンショットと細かい画面は main に入った後と毎日 1 回の `e2e-full` で実行する（ADR 0057）。夜だけにする場面は `test('…', { tag: '@nightly' }, async (…) => {` とカードを付ける。カードの無い場面は毎回流れる。場面を足したら、毎回実行するか夜だけにするかをカードで決める。毎回の分だけを実行するのは `npm run e2e:pr`
- 応答に個人情報が入っていないことを確認するときは、JSON を読み解いてから探す。Laravel の JSON は日本語を `\uXXXX` で送るので、生の文字列からは日本語の名前や住所を見つけられない（で見逃しかけた）
- ブラウザのテスト（`tests/e2e`）は場面ごとに独立させる。運営は `operator`、テナントは `makeTenant()` で場面ごとに作り、他の場面の結果に頼らない。CSP 違反の監視は全場面に自動で掛かる（`tests/e2e/support/fixtures.js`）。場面は並べて同時に動くので、他の場面が作ったデータが一覧に混ざっても壊れない確認の方法にする（自分の ID で絞る）
- 手元の PHP の版・拡張・vendor に左右されずに検査するときは `make check-docker`（本番と同じ PHP と拡張のコンテナで pint・phpstan〔CI と同じ `phpstan.ci.neon`〕・PHP のテスト。`TESTS=<ファイル>` で絞る）。手元と CI で phpstan の結果が違うときは、まずこれで確認する
- 1 つだけ実行する: `npm run e2e -- -g "場面の名前"`。不安定さを疑うときは `--repeat-each=5`
- 画面を変えたら `E2E_SCREENSHOTS=1 npm run e2e -- -g 写し` でスクリーンショットを撮り（`test-results/screens/`）、目で見る

## 8. 文書

- 設計上の決定は `docs/adr/` に 1 件 1 ファイル。決定を覆すときは新しい ADR で「置き換え」
- 境界をまたぐ機能を足したら、脅威モデル（`docs/security/threat-model.md`）の表を更新する
