# plugin-services — サービスの販売

EC-SUGAR 公式の App。設置の代行・作業の請け負いなど、**物を送らないサービス（役務）**を、オプションつきでふだんのカートから売る（[ADR 0091](../../docs/adr/0091-サービス（役務）はふだんのカートで売り、受渡はin_personで店の人が提供済みにする.md)）。

## 使い方

1. 管理画面の「App」で「サービスの販売」を「使う」にする
2. 「商品」で新しい商品を作り、扱いを「サービス」にする。値段・説明・写真はふつうの商品と同じ
3. オプション（「付ける」で＋◯円など）は、商品の画面の「オプション」でふつうの商品と同じに足す
4. お客さんはふだんのカートで買う。送料・お届け先・在庫は使わない（1 回の注文で 1 点まで）
5. 作業を終えたら、注文の画面の「注文の操作」で **「提供済みにする」** を押す。注文は完了になる（元に戻せない）

## 決まり

- サービスは、ほかの扱いの商品と同じ注文にできない。カートに混ざると、サービスの行に「サービスはほかの商品と別にご注文ください」と出る
- 支払いはカード・PayPay・銀行振込（店で使う設定のもの。コンビニ払い・代金引換は出さない）。銀行振込は入金を確かめてから「提供済みにする」
- App を止めた店では新しく売らない（もう受けた注文は「提供済みにする」で済ませられる）
- 予約の App の注文（来店の役務）は、このボタンでは済ませない（予約の画面の「来店済み」で）

## 中身

| ファイル | 中身 |
|---|---|
| `src/ServicesServiceProvider.php` | 商品の扱い「サービス」（`service_item`。`stockless`・`inPerson`）と App の登録 |

核の側: `ListingKindDefinition::$inPerson`・`ListingKinds::inPerson()`・`CartService::SERVICES_MIXED`・`PlaceOrder`（役務の行を `in_person` の受渡に）・`SaleOrderService::deliverService`・`isServiceOrder`・管理画面の `POST /api/tenants/{店}/transactions/{注文}/deliver-service`。

テスト: `tests/Feature/Plugin/ServicesPurchaseTest.php`
