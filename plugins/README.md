# plugins/

自社プラグイン（買取・仕事・定期・店舗・マーケット、各種ドライバ）の置き場所。
外部プラグインと同じ機構（composer パッケージ ＋ マニフェスト）で作る。docs/CONCEPT.md 6 章。

- 1 プラグイン = 1 ディレクトリ = 1 composer パッケージ（`ec-sugar/plugin-<name>`。店頭のテーマは `ec-sugar/theme-<name>`、決まりは docs/design/theme-api.md）
- ルートの composer.json から path リポジトリで参照する
- 核のテーブルには直接書かない。自分の名前空間のテーブルだけ
- 管理画面からはインストールできない。composer / CLI のみ（7.2）
- マニフェストは composer.json の `extra.ec-sugar`。旧名 SUGAR-EC のころのキー `extra.sugar-ec` も当面は読む（`ec-sugar` が無いときだけ。ADR 0025 の「作って決めたこと」）。新しく作るときは `ec-sugar` で書く
- ライセンス: 公式テーマ（`theme-*`）と見本の App（`plugin-faq`）は MIT（各ディレクトリの LICENSE）、ほかの App は今は核と同じ `AGPL-3.0-only`。公開の口だけを使うテーマと App は作った人が好きなライセンスで配れる（[LICENSE-EXCEPTION.md](../LICENSE-EXCEPTION.md)、ADR 0033）
- 核が App に約束していること（プラグイン API。今は 1）と、変わる時の知らせ方（付け足しでは上げない・壊すときは番号を上げて前の番号も次のマイナーまで読む・[CHANGELOG](../CHANGELOG.md) の「壊す変更」）は [ADR 0093](../docs/adr/0093-外に約束する口を決め、壊すときはAPIのバージョンを上げてCHANGELOGで知らせる.md)

## 新しい App を作る（ひな形から）

既存の App を写さずに、ひな形のコマンドから始める（写すと、その App だけの処理まで持ち込む）。

```sh
php artisan make:plugin stamp-card --category=selling --title=スタンプカード
```

`plugins/plugin-stamp-card/`（composer.json とマニフェスト・登録・店の DB の表・書く係・管理画面の App の画面・ショップの `/p/stamp-card`・README）と、テスト・管理画面のヘルプを出す。上書きはしない。
ルートの composer.json・一覧のテスト・ヘルプの目次は手で足す（コマンドの最後に出る）。手順と、中身を作るときに守ることは [docs/development/making-apps.md](../docs/development/making-apps.md)。

## App を作る人へ: 守りの書き方（ADR 0081）

守りは核が掛ける。App は経路に宣言を書き、核の部品を呼ぶだけにする（自分で同じ守りを書かない。書くと `SharedGuardsTest` が落ちる）。段階ごとの表は docs/development/conventions.md の 4b。

- **ショップの経路**は `storefrontRoutes` に書く。核が `/p/<名前>` の下に `storefront`・`public`・`plugin:<名前>` を付けて置く（使わない店では 404）
- **URL に署名か合い言葉を持つ画面**（確かめ・停止のリンク・注文の画面・プレビューなど）は `->middleware('secret-link')` を付ける。どの応答（エラー・転送も）にも、核が「参照元を渡さない・残さない・検索に載せない」を掛ける。コントローラーで `Referrer-Policy`・`no-store`・`noindex` を書かない。道筋に `{token}` などを持つのに宣言が無いと `RouteAuthorizationTest` が落ちる
- **会員の個人情報（名前・メール・住所・注文）を出す画面と、そこへの送信**には `->middleware('navigation-only')` を付ける。タグの JS から読ませないため（ADR 0088。下の「タグの App」の節）。付けた経路は `NavigationOnlyRoutesTest` の一覧にも足す（例はひな形の ServiceProvider のコメント）
- **署名つきの URL** は `SecretLinks::signed('storefront.plugin.<名前>.<経路>', [引数], now()->addHours(…))` で作り（名前は文字で書く。店の ID が入り、期限は 31 日まで）、`SecretLinks::valid($request)` で確かめる。署名が要るだけの画面なら `->middleware('secret-link:signed')` にすれば確かめも核がする（合わなければ 404）
- **ショップで書き込む経路**（POST）には `->middleware('throttle:<名前>')`（`storefront-cart`・`storefront-member` など）を付ける。付けないなら `RouteAudit::UNTHROTTLED_WRITES` に理由を書く
- **コントローラーやサービスの中の回数の上限**は `Throttle::allow(Throttle::shopKey('<App の名前>-<何>', …), 上限, 秒)`。いくつか重ねるなら `Throttle::firstOver([[キー, 上限, 秒], …])` に狭い上限から渡す。`RateLimiter::tooManyAttempts`・`hit` を直に使わない
- 必須の守りを、核のイベントの購読や App の口（フック）に任せない（順が決まらず、App を止めると動かない）。App が口で足すのは知らせ・集計・表示の付け足しだけ

## 核の記録と知らせを受ける（ADR 0089）

支払いが確かになった・発送した・取り消したなど、核の出来事の後に App が仕事をするときは、サービスプロバイダの `boot` で**名前のある受け手**を足す。
核の送り出し役が、書き込みのコミットの後に届け、プロセスが落ちても毎分の見回り（`events:relay`）が届け直す。

```php
use App\Core\Event\Models\DomainEvent;
use App\Core\Event\Outbox\EventReceivers;
use App\Core\Payment\Events\PaymentConfirmed;

$receivers = $this->app->make(EventReceivers::class);

// 核の知らせで受ける（PaymentConfirmed・PaymentAwaiting・PaymentLapsed・FulfillmentShipped・RefundSettled）。
// 2 つ目の引数は元の記録（いつ起きたか。テストで event() から直に出した時は null）
$receivers->notice('stamp-card.paid', PaymentConfirmed::class, static function (PaymentConfirmed $e, ?DomainEvent $event): void {
    app(StampCards::class)->paid($e->transactionId, $event?->occurred_at);
}, app: 'stamp-card');

// 記録の種類で受ける（transaction.phase_changed・inventory.received など）
$receivers->on('stamp-card.cancelled', ['transaction.phase_changed'], static function (DomainEvent $event): void {
    if (($event->payload['to'] ?? null) === 'cancel') {
        app(StampCards::class)->cancelled($event->aggregate_id);
    }
}, app: 'stamp-card');
```

- **名前**は `<App の名前>.<何をするか>`。どこまで流したかの印の鍵なので変えない（変えると、5 分より前の記録は流さずに数え直す）
- **`app:`** を付けると、店でその App を使っている間に起きた記録だけ届く。止めた後も前の注文の後始末をする受け手（払った人へ渡す・返金で止める）は付けない
- **冪等に書く**: 同じ記録が 2 回届いても同じ結果にする（錠の中で今の状態を確かめてから進める・送った記録の一意を先に作る）。メール・Slack・LINE は「送った」の印で 1 回だけにする
- **続けられない時は例外を投げる**。握りつぶして `report()` だけにすると届け直さない。自分に関係の無い記録・もう済んだことは、何もせずに戻る（例外にすると 5 回で止まって要対応に出る）
- 時刻を比べるときは「今」でなく記録の時刻（`$event->occurred_at`）で比べる（遅れて届くことがある）
- 要求の文脈（Cookie・セッション）は無いことがある。要るものだけは同じプロセスの中だけの `DomainEventRecorded` で受け、届かなければ何もしない形にする
- テストで受け手を動かすときは、知らせを `event(new PaymentConfirmed($id))` と直に出すか、受け手を直に呼ぶ（`app(EventReceivers::class)->get('<名前>')->handler`）。受け手が何もしなかった形は `EventRelay::muting(['<名前>'], fn () => …)`、コミットの直後に落ちた形は `EventRelay::pausing(fn () => …)`

**届き方の約束**

| こと | 約束 |
|---|---|
| いつ | 書き込みのコミットの後。ふつうはすぐ、落ちたら 1 分ほどで見回りが |
| 何回 | 少なくとも 1 回。2 回届くことがある（落ちた後・デプロイの前後・運営の流し直し） |
| 失敗したら | 1・5・30・120 分の間を空けて届け直す。5 回落ちたら止めて、運営の様子の要対応に出る。運営が直してから「流し直す」 |
| 順序 | 同じ集約（同じ取引など）の記録は、その受け手には記録の順。違う集約の間・違う受け手の間の順は約束しない |
| 止めた App | 止めている間の記録は届かない。使うに戻しても、止めていた間の分は届かない |
| 店の文脈 | 記録の店の文脈の中で呼ばれる。受け手から積んだキューの仕事にも、その店と処理の ID が戻る |
| 処理の ID | 元の記録を書いた処理の ID の下で呼ばれる（ADR 0087） |

## 決済に触る（ADR 0026・ADR 0107 の段 3a）

支払いや返金に触る App は、**決済代行の名前を知らずに**書く。約束している 18 種類は [ADR 0093](../docs/adr/0093-外に約束する口を決め、壊すときはAPIのバージョンを上げてCHANGELOGで知らせる.md) の「決済の口」の行にある。よく使うのは次の 3 つ。

| したいこと | 使うもの |
|---|---|
| 店が支払いを受けられるか・本物の鍵か試しの鍵かを知る | `App\Core\Payment\Contracts\PaymentCredentialStore` を注入し、`credentials()` が `null` でないかを見る。モードは `credentials()?->mode()`（`'live'` か `'test'`） |
| 預けたカードで請求する（定期便・抽選販売など） | `App\Core\Payment\Services\StoredPayments`。まだ使うカードがあるなら `Contracts\StoredCardHolder` を実装して札 `StoredCardHolder::TAG` で登録する |
| 払われた・返金されたを受ける | 受け手で `Events\PaymentConfirmed`・`PaymentAwaiting`・`RefundSettled`（届き方は「核の記録と知らせを受ける」の節） |

**決済代行の具象（`Stripe` の名前が付くクラス・`Stripe\` の部品）は使わない。** 核の `ArchitectureTest` が、口の向こう側以外から読み込むことを機械で止めている。鍵そのもの（秘密鍵・Webhook の署名の鍵）は App からは読めない。

別の決済代行のドライバを作るときは、`Contracts\PaymentDriver`（＋預けたカードを扱うなら `StoredPaymentDriver`）と `Contracts\PaymentWebhookReceiver` を実装し、自分のサービスプロバイダから `App\Core\Payment\PaymentDrivers::register('<名前>', <ドライバ>::class, <受け口>::class)` を呼ぶ。どれを使うかは設定 `payment.driver`（入っているドライバが 1 つなら省ける）。鍵の置き場と管理画面の設定の画面をドライバ側で持つ仕組みは 1.0 の後（ADR 0107 の段 3b）。

## 1 回のご注文の決まり（上限と下限。ADR 0109）

「この商品は 1 回のご注文で 2 点まで」「ご注文は商品の合計 ¥3,000 から」のような決まりを足すときは `Contracts\PurchaseLimitProvider` を実装し、`PluginDefinition` の `purchaseLimits:` で登録する。**App は決まりを答えるだけで、守るのは核**（カートの行に理由を出し、数の入れ物の上限を合わせ、注文の確定の錠の中で読み直して断る）。

```php
public function perOrder(array $listingIds): array
{
    // 商品の ID => 1 回のご注文の上限（1 以上）。上限の無い商品は入れない。
    // カートの商品をまとめて聞かれるので、1 回の読み出しで答える
    return Limit::query()->whereIn('listing_id', $listingIds)->pluck('per_order', 'listing_id')->all();
}

public function minimumSubtotal(): int
{
    // 商品の合計の下限（円。無ければ 0）
    return (int) $this->settings->get('minimum_amount', 0);
}
```

- 上限は商品ごとに数える（種類・オプション違いで何行に分けても合わせて数える）。人ごとの制限（「1 人 1 点」）ではなく、注文を分ければまた買える
- 下限は、今買える商品の合計（値引き・送料・ポイントの前）で比べる。カートを通る注文だけ
- **例外を投げる・0 以下の上限・負の下限を答えると、核はその店のご注文を受けない**（決まりが分からないまま売らない）。読めない時に「決まり無し」を返して逃げない。壊れた行は保存の時に断り、DB の CHECK でも守る
- 核は答えを絞る（頼んでいない商品は捨てる。上限は 999、下限は 1 億円まで）
- 仕組みが作る注文（定期便の 2 回目から・抽選の当選）には掛からない

この口の最初の使い手は公式の App「購入の制限」（`plugins/plugin-purchase-limits`）。

## セット商品（在庫を別の品から引く）

複数の商品をまとめて 1 つの値段で売るような、**自分の在庫を持たず別の品から引く商品**を作るときは `Contracts\StockComponentsProvider` を実装し、`PluginDefinition` の `stockComponents:` で登録する。

```php
public function stockComponents(array $listingIds): array
{
    // 商品の ID => 種類の ID（listing_variants.id）=> 1 つあたりの数。
    // 自分の在庫を持つ商品は鍵を入れないか空の配列（聞かれていない商品を入れても核が捨てる）
    return ['01J…セットの商品' => ['01J…コーヒーの種類' => 2, '01J…カップの種類' => 1]];
}
```

**まとめて聞かれる**のは、ショップの一覧が商品ごとに App を呼ばないため（1 回 200 商品まで）。

核はこの口の答えで次の 4 つを決めるので、どれも食い違うことがない。

| いつ | 何を決めるか |
|---|---|
| ショップの一覧・商品の画面 | 買えるか（残りいくつか） |
| カートの注文・1 件の注文 | どの拠点から出すか |
| 取引の確定 | どの品をいくつ押さえるか |
| 取消・受渡 | どの品を解く・出庫するか |

**その商品そのものの在庫は見られない。** 店の人が「ショップに出す上限の数」のような意味のない在庫を入れる必要はない（ADR 0108）。

**気をつけること**:

- **1 つでも足りなければ丸ごと断られる**（核が同じトランザクションの中で押さえるので、途中で足りなくなれば全部戻る）。部分的に押さえた状態は残らない
- **この口は落ちてはいけない。** 例外を投げると取引が止まる（在庫を引かずに売るより安全な側へ倒している）。ほかの口のように「落ちたら無いものとして扱う」ではない
- **セットの中にセットは入れられない**（核は 1 段だけ辿る）。App が入れ子を断ること
- 引ける品は 1 商品につき 20 まで。数は 1 以上の整数
- **数えるのは拠点ごと**（`Core\Inventory\Services\ComponentStock`）。構成する品が別々の拠点に散っていると 1 つも作れない扱いになるので、同じ拠点に置いてもらう
- **構成する品が売れない状態なら売らない**（消した・下書き・種類を止めた）。店が非公開にした品を、まとめた商品の形で売ってしまわないため
- **売り切れの見せ方はこの口ではしない。** 構成する品が足りない時に商品の画面や札を売り切れにするのは `ListingVisibilityProvider`の `unsellableListings()`

この口の最初の使い手は公式の App「セット商品」（`plugins/plugin-bundle`）。入れ子の断り方・作れるセットの数の数え方・管理画面の形は `plugin-bundle/README.md` が見本になる。

## 有料のコンテンツを売る・出す

記事や動画を「買った人だけ・購読している人だけ」に見せるときは、**見せる側と売る側を直につながない**。核の口を通す。

```
見せる側の App（お知らせ・ブログなど）  →  核（ContentAccess）  →  売る側の App が答える
```

| したいこと | 使うもの |
|---|---|
| この人に見せてよいか | `App\Core\Plugin\Services\ContentAccess` を注入し、`any($accountId, $keys)`（1 つでも持っていれば真）か `held(...)`（持っている鍵の一覧） |
| 権利を持っているかに答える | `Contracts\ContentAccessProvider` を実装し、`PluginDefinition` の `contentAccess:` で登録する |

鍵は `<種類>:<名前>` の形の文字列（例 `membership:standard`・`post:01J…`）。核は中身を解釈せず、文字が合うかだけを見る。種類は英小文字で始め 32 文字まで、名前は英数で始め 64 文字まで。形の違う鍵は核が聞く前に落とす。

**App は自分で正規表現を持たず `ContentAccess::validKey($key)` に聞く**（核と App で形がずれると、保存できたのに誰にも読めない鍵ができる）。

**答える側の決まり**:

- **真を返すことは「本文を送ってよい」という意味**。期限・解約・返金を必ず見る（購読が切れた翌日・返金した後は偽）。**迷ったら偽**
- 頼まれていない鍵を返しても核が捨てる（App の誤りで別のものを開けない）
- 落ちたら「持っていない」として扱われ、ショップは止まらない（記録は残る）
- 聞かれるのは**その店で App を使っている時だけ**。ログインしていない人には聞かれない

**見せる側の決まり**:

- **権利の無い人に本文を 1 バイトも送らない。** 隠すのではなく、サーバーが組み立てる段階で落とす（HTML・JSON・プレビュー・`/sitemap.xml`・一覧の区画は抜き書きだけ）
- **切る場所は 1 か所にまとめる**（出口ごとに書くと、新しい出口を足した時に書き漏らす）。見本は App「お知らせ・ブログ」の `BlogPaywall`: 有料のブロックを別の列に持ち、記事の画面・一覧・区画・Atom・サイトマップ・検索の説明・プレビューがどれもそこを通る（ADR 0072 の「有料の記事」）
- **無料の分は誰にでも出す**（検索から来る入口になる）。答える App が 1 つも無い・止まっている・落ちた店では「誰も持っていない」＝有料の分は誰にも出ない（閉じる側へ倒す）

## App の一生とデータを消す口（ADR 0092）

App の一生は「入れる（composer）→ 店で使う・止める → 店で App のデータを消す → バージョンを上げる → composer から外す」。段ごとに App がしてよいことと核が保証することは ADR 0092 の表。要点:

- 止めてもデータは消さない（もう一度使えば戻る）。消すのは、店のオーナーが App の画面の「この App のデータを消す」（止めている時だけ・直前の再確認の後）か `php artisan plugin:purge <店> <名前>` を選んだ時だけ
- 表は消さない（移行は全部の店に共通）。消すのは、その店の App の表の行と、App の置き場のファイルと、App の設定（核が消す）
- **composer から外す前に**、データを消したい店で `plugin:purge` を流す（外した後はコードが無いので消せない。表と行は残り、状態の行は「入っていない」と扱われる）
- 移行は前へだけ（列を消す・名前を変える移行は書かない）。プラグイン API のバージョンが合わない App は登録で断る

データを消せるようにする App は、`PluginDefinition` に口（`purger:`。`App\Core\Plugin\Contracts\PluginDataPurger`）を渡す。渡さない App は消せない（画面にボタンが出ない）。

```php
final class StampCardDataPurger implements PluginDataPurger
{
    // 今は消せない理由（お客さんがまだ使う物が残っている等）。消せるなら null。連鎖のロックの中で呼ばれる
    public function refusal(): ?string
    {
        return null;
    }

    // 自分の表の行を全部消す（子の表から）。表の名前は App の頭で始める（核が確かめ、違えば消さずに落とす）
    public function purgeRows(): array
    {
        return [
            'stamp_card_stamps' => StampCardStamp::query()->delete(),
            'stamp_card_items' => StampCardItem::query()->delete(),
        ];
    }

    // コミットの後: この店の置き場のうち、どの行も指さないファイルを消す（落ちても、もう一度消すと拾う）。ファイルが無ければ 0
    public function purgeFiles(): int
    {
        return 0;
    }
}
```

- 核（`PluginDataPurge`）が、店の文脈・止めていること・オーナー・デモの店でないことを確かめ、連鎖のロックの中で `refusal` → `purgeRows` を呼び、`plugin.purged` を残す。App は自分で記録を足さない・核の表に触れない・表を消さない
- ファイルを持つ App は、行を消した後の `purgeFiles` で、自分の置き場の**この店の場所だけ**（`tenants/<店>/…`）を見る。ほかの店の場所を見ない（`plugin-downloads` の `DownloadDataPurger`）
- 払ったお客さんが受け取る物（ダウンロードのリンクなど）が残っているうちは `refusal` で断る
- 口のある App: よくある質問（`faq`）・ダウンロード販売（`downloads`）・公開の予約と販売期間（`sale-period`）・セット商品（`bundle`）・購入の制限（`purchase-limits`）。足したら ADR 0092 の「口のある App」の表に書く

## App の目的（`category`。ADR 0025 の「作って決めたこと」）

管理画面の App の画面は、App を目的ごとのまとまりに札で並べる。App は composer.json の `extra.ec-sugar` に目的を 1 つ書く。

```json
"extra": {
    "ec-sugar": {
        "type": "plugin",
        "name": "ga4",
        "plugin_api": 1,
        "core": "^1.0",
        "category": "analytics"
    }
}
```

- 書けるのは核が決めた言葉だけ（`App\Core\Plugin\Manifest\AppCategory`）。画面の並びもこの順

  | 言葉 | 画面の名前 | 向いている App |
  |---|---|---|
  | `analytics` | アクセス解析 | GA4・Clarity |
  | `marketing` | 集客と広告 | 広告の計測タグ・SNS・メールでのお知らせ |
  | `page` | ページの部品 | 動画・地図・よくある質問など、ページに区画を足す App |
  | `selling` | 売り方 | 定期便・予約・買取・まとめ買い |
  | `products` | 商品と在庫 | 商品と在庫の管理を助ける App |
  | `payment` | お支払い | お支払いの方法を増やす App |
  | `shipping` | 配送 | 配送と送料 |
  | `customers` | お客さん | 会員・レビュー・問い合わせ |
  | `operations` | 店の運営 | 会計・帳票・スタッフの仕事 |
  | `other` | そのほか | ほかに入らない App（書かなければこれ） |

- **知らない言葉（打ち間違い・大文字・並び・空）は登録で断る**（アプリが起動しない。CI で気づく）。黙って「そのほか」に落とさない
- 書かなければ「そのほか」。マニフェストを渡さないプラグイン（`PluginDefinition` の `manifest` が無い）も「そのほか」なので、目的を書くなら `manifest: __DIR__.'/../composer.json'` を渡す（よくある質問の見本がこの形）
- 言葉を足したいときは核の変更（`AppCategory` に足し、ADR 0025 の「作って決めたこと」に書く）。消す・意味を変えると今の App のマニフェストが読めなくなるので、しない

## 全部の App に自動で当たる確かめ

App を足すと、何も書き足さなくても次のテストが当たる（データの提供者が `plugins/` の composer.json から App を拾う。App ごとに 1 件ずつ失敗が分かる）。

- `tests/Feature/Plugin/AppDeclarationsTest.php`（宣言とコードだけ。DB を使わない）
  - 登録簿の App と `plugins/` の App が同じ一覧で、登録に渡した composer.json がそのパッケージのもの
  - composer.json の `extra.ec-sugar` を読み直した宣言（目的 `category`・設定の型と secret・公表の目的・差し込み・区画）が、登録簿の宣言と同じ（`manifest` の渡し忘れで落ちる）
  - 名前に secret・token・password・webhook・api_key・private が入る設定に `secret` の印がある。秘密は文（text）の項目だけ
  - ショップの経路（`/p/<名前>/…`・検索に載せるページの頭の下・名前が `storefront.plugin.<名前>.` で始まるもの）に `storefront`・`public`・`plugin:<名前>` の印がちょうどある（使っていない店では 404。ADR 0025）。止めても開くと宣言した経路（`keptStorefrontRoutes`。ダウンロード販売の受け取り）だけは plugin の印が無い
  - 管理画面の操作（`new AdminAction(…)`）の名前・画面の名前・確かめの文が、お金の言葉（返金・ポイント・残高・値段・代金・入金・請求・解約など）に当たるなら `money: true` がある
- `tests/Feature/Plugin/AppContractTest.php`（店と運営の側から）
  - 使っていない店: App のショップの経路（使う店の関所で止まったことまで見る。止めても開くと宣言した経路だけは関所を通らずに届く）・スクリプトのファイル・管理画面の App の画面と操作の API（一覧・1 件・操作・足す・直す・読む・書き出し・取り込み・注文の書類・商品の画面の App の欄）が 404。メニューと注文の書類のボタンにも出ない
  - ほかの店: ほかの店に作った App の画面の 1 件の ID で、見る・操作する・直すが 404
  - 権限: 閲覧の段階は 1 件の操作・足す・直す・読む・書き出し・取り込みが 403。`money` の操作は運用の段階で 403、お金の段階でも直前の再確認の前は 423。断った後の 1 件は前と同じ
  - 秘密の設定: 店の鍵で暗号化して置き、管理画面には「入っているか」だけ、ショップと記録（イベント）には中身を出さない

画面（ADR 0051）を持つ App を足したら、`tests/Feature/Plugin/AppContractRecords.php` に「その App の画面の 1 件を今の店に 1 つ作る」を足す（無いとテストが「足す」と落ちる）。決まりに当たるが正しいもの（お金の言葉に当たるがお金を動かさない操作など）と、見つけた不具合の候補（お金の印の付け忘れ・1 件の画面が壊れている App）は `tests/Support/AppContract.php` の一覧に理由つきで書く。直したら一覧から消す（直ったのに残るとテストが落ちる）。

## タグの App（ADR 0034、docs/design/tag-apps.md）

お店の人が有効にして ID を入れると、同意したお客さんのふつうの画面と購入の完了の画面にだけ、核がスクリプトを差し込み、CSP に宣言した宛先を足す。Trusted Types の既定の関所は核が作る（App は作らない）。

差し込んだタグの JavaScript は、個人情報の画面（マイページ・注文の入力と確かめ・ご注文の状況・App の会員の画面と申し込みの流れ）を fetch・枠・別の窓のどれでも読めず、fetch で送ることもできない（ADR 0088。経路の印 `navigation-only`）。App を作るときは、会員の画面・個人情報を出す画面と、その送信先に `->middleware('navigation-only')` を付ける（付け忘れの多くは `NavigationOnlyRoutesTest` が落とす。一覧にも足す）。

- `plugin-ga4` — Google アナリティクス 4（同意の種類は解析）
- `plugin-google-ads` — Google 広告のコンバージョン計測（広告）
- `plugin-clarity` — Microsoft Clarity（解析）
- `plugin-meta-pixel` — Meta ピクセル（広告）
- `plugin-lineyahoo-ads` — LINEヤフー広告の計測タグ（広告）
- `plugin-x-pixel` — X ピクセル（広告）
- `plugin-tiktok-pixel` — TikTok ピクセル（広告）
- `plugin-microsoft-uet` — Microsoft 広告の UET（広告）
- `plugin-pinterest-tag` — Pinterest タグ（広告）
- `plugin-smartnews-ads` — SmartNews 広告のピクセル（広告）
- `plugin-tag-sample` — 見本（宛先は実在しない `.test`。開発とテストだけで入れる）

## 区画の App（ADR 0040、docs/design/app-sections.md）

お店の人が有効にすると、ページの編集の画面の「＋ 区画を足す」に区画が出る。外の埋め込みは、同意を待たずに誰にでも最初から枠を出す（2026-10-30 に置き換え。守りは CSP・sandbox・宛先の確かめ、描くのは核の部品。ADR 0040 の 3）。

- `plugin-video` — 動画（YouTube・Vimeo）
- `plugin-google-map` — 地図（Google マップ。キーの要らない埋め込み）

目的が同じ「ページの部品」の `plugin-faq`（よくある質問）は区画でなく、商品の画面の説明の下に差し込む App（いちばん小さい見本。核の口だけで作っている）。

## フィードの App（ほかのサービスが読みに来る一覧の URL）

お店の人が有効にして URL を作ると、Google や Meta がその URL を定期的に読みに来て、ショップの商品を取り込む。URL は推測されないトークンつきの公開の経路（`/p/<App の名前>/…`）で、店の人が作り直せる。出すのは核のショップの窓口（`Catalog`）が出す商品だけ。

- `plugin-product-feed` — 商品フィード（Google Merchant Center と Meta のカタログ。RSS 2.0 の XML）
- `plugin-newsletter` — メルマガ・一斉メール（受け取ると選んだお客さんへ店からのお知らせを一斉に送る。ダブルオプトイン・特定電子メール法の表示・ワンクリックの配信停止。使い方は `plugin-newsletter/README.md`）
- `plugin-cart-reminder` — カゴ落ちのお知らせ（払われずに消えた注文のお客さんへ、商品をカートに戻せるリンクをメールで 1 回。お知らせのメールの受け取りに同意した人だけ。ADR 0067。使い方は `plugin-cart-reminder/README.md`）
- `plugin-referral` — 友だち紹介（会員ごとの紹介のリンクから来た人が会員になり、最初の注文が発送されたら、紹介した人と紹介された人の両方にポイント。ポイントを使う店だけ。ADR 0075。使い方は `plugin-referral/README.md`）

## 売り方の App（`category: selling`）

- `plugin-gift` — 贈り物（住所を受け取る人が入れる。期限で自動で返金する。ADR 0055。使い方は `plugin-gift/README.md`）
- `plugin-downloads` — ダウンロード販売（PDF・音楽・動画などのファイルをふだんのカートで売り、払いが確かになったら期限と回数のある署名つきのリンクで渡す。開けるのは照会か会員の本人だけ。ADR 0084。使い方は `plugin-downloads/README.md`）
- `plugin-services` — サービスの販売（設置の代行・作業の請け負いなど物を送らない役務を、オプションつきでふだんのカートで売る。受渡は役務〔in_person〕で、作業を終えたら注文の画面の「提供済みにする」。ほかの扱いの品と同じ注文にしない。ADR 0091。使い方は `plugin-services/README.md`）
- `plugin-preorder` — 予約販売（発売日の前の商品を在庫によらず予約の数で受け、発売日にまとめて送る。発売日を変えると予約したお客さんへ知らせる。ADR 0073。使い方は `plugin-preorder/README.md`）
- `plugin-age-check` — 年齢確認（お酒・たばこなど、年齢の要る商品の注文で生年月日と同意を確かめる。生年月日は残さず、注文に「年齢確認済み」。ADR 0077。使い方は `plugin-age-check/README.md`）
- `plugin-wrapping` — ラッピング・のし（注文に 1 つ、ラッピングの種類とのしの表書き・掛け方・名入れを選ぶ。料金は注文の調整の行〔10%〕、名入れは暗号化。核の「App が注文に料金の行を足す口」の最初の使い手。ADR 0078。使い方は `plugin-wrapping/README.md`）
- `plugin-sale-period` — 公開の予約と販売期間（商品ごとに「この日時から出す」「この日時で終わる」を決める。前はショップに出さず、後は売り切れでカートに入らない。核の口は「今は出さない・今は売らない」〔ListingVisibilityProvider〕と売り方の押さえ。使い方は `plugin-sale-period/README.md`）
- `plugin-bundle` — セット商品（複数の品をまとめて 1 つの値段で売る。セットは在庫を持たず、売れるたびに構成の品の在庫から引く。1 セットも作れないセットはショップに出さない。核の口は「在庫をどの品から引くか」〔StockComponentsProvider〕と「今は出さない」〔ListingVisibilityProvider〕。使い方は `plugin-bundle/README.md`）
- `plugin-purchase-limits` — 購入の制限（商品ごとの「1 回のご注文で何点まで」と、店の「商品の合計が ◯ 円以上から」。上限は商品の画面の App の欄、下限は App の設定で決める。核の口は「1 回のご注文の決まり」〔PurchaseLimitProvider〕で、カートの行・注文の入力・注文の確定で断るのは核。使い方は `plugin-purchase-limits/README.md`）

## 店の運営の App（`category: operations`）

- `plugin-order-print` — 納品書・領収書
- `plugin-shop-notify` — 店への知らせ（注文が入った・取り消された・在庫が少なくなったを、Slack の Incoming Webhook〔`https://hooks.slack.com/services/…` だけ〕か店のメールへ。個人情報は載せない。ADR 0060。使い方は `plugin-shop-notify/README.md`）

## 商品と在庫の App（`category: products`）

- `plugin-catalog-sort` — 並び順と絞り込み（ショップの商品一覧の並びを店が手で決める「おすすめ順」と、お客さんが使う絞り込み〔価格の帯・タグ・在庫あり〕。核の口 `CatalogBrowseHandler` に並びと絞り込みを渡すだけで、読むのも描くのも核とテーマ。使い方は `plugin-catalog-sort/README.md`）
