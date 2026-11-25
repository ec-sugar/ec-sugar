# 0026 決済は核の口を通し、Stripe は最初のドライバにする

状態: 採用（2026-10-28）。ADR 0019（最初の決済代行は Stripe Checkout）・0022（SaaS 版は Stripe Connect）を置き換えず、その上に決済の抽象の口を足す。

## 文脈

決済を核から切り分けたい（2026-10-28。基本の機能のうち、プラグインに切り分けるべきものの 1 つ）。
ねらいは「核を薄く保ち、決済代行を差し替えられるようにする」こと。いまは Stripe が核のサービスに直結していて、
`PaymentService` と `PaymentRefunds` が `StripeCheckout`（具象）と `StripeCredentials`（具象）を型で参照している。
GMO-PG などを足すときに核を触らずに済むよう、核と決済代行の間に口（インターフェース）を置く。

決済ドライバは信頼されたコード（自社または審査済み）で、プロセス内で動く（速い）。切り分けは安全のためではなく、
核を薄く・差し替え可能にするため（プラグインの脆弱性の話は ADR 0005・0025、隔離は将来課題）。

## 決定

- **核に決済の口を置く**（`App\Core\Payment\Contracts`）。核のサービスはこの口だけを見て、Stripe の具象を型で参照しない:
  - `PaymentDriver`: `openCheckout` / `retrieveCheckout` / `refund` / `retrieveRefund`（返金の状態を問い合わせ直す。ADR 0027 の定期の確かめ直しが使う。2026-10-31 に一覧へ足した）/ `expireCheckout`
  - `PaymentCredentials`: 鍵の抽象（核に見せるのは `account(): ?string` だけ。鍵そのものは見せない）
  - `CheckoutSnapshot`: 支払いの画面の状態を正規化した値（状態・支払い済みか・金額・通貨・取引の参照・支払いの ID）
- **Stripe はこの口の最初のドライバ**（`StripeDriver`）。中で今の `StripeCheckout` を使う。核のサービスへは DI で差す
- **店ごとの接続（Stripe Connect）は共通の口に入れない**。Stripe 固有の仕組みなので、Stripe ドライバだけの任意の口にする（GMO-PG などに無い概念で核を汚さない）
- **区切りを分ける**。区切り 1: ふるまいを変えずに口を切る（核が Stripe の具象を型参照しない状態にし、テストで担保）。区切り 2: Stripe の実装と鍵の取り出し（`PaymentGatewayService`・`StripeConnectService`・`StripeWebhook`）を `plugins/` のドライバのパッケージへ物理的に出す。区切り 3: 接続の口を Stripe ドライバの任意機能に整理（2026-10-31: 区切り 1 は済、区切り 2・3 はまだ〔〕。Stripe の実装は今も核の `app/Core/Payment/Stripe` にある）
- 台帳（Ledger）は決済代行に依存しない（ADR 0019 のまま）。確定は Webhook（ADR 0019 のまま）

## 結果

- 得たもの: 核が決済代行を意識しなくなる。2 つ目の決済代行を足すとき、核のサービスとテストを触らずにドライバを足せる見通し。口の契約がテストで固定される
- 失ったもの・気をつけること: 抽象の層が 1 枚増える（ただしプロセス内のメソッド呼び出し 1 回で、速度は実質変わらない）。区切り 1 では Stripe の鍵の取り出し（`PaymentGatewayService`）はまだ核に残る（区切り 2 で移す）。接続（Connect）は当面 Stripe 固有のまま
