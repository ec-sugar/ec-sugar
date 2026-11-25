<?php

declare(strict_types=1);

namespace App\Core\Listing;

use App\Core\Listing\Enums\OfferSide;
use App\Core\Listing\Enums\Pricing;
use Closure;

/**
 * 出品物の種類の定義。プラグインはこれを ListingKinds に登録する。
 * plugin は種類を足した App の名前（その App を使う店でだけ、管理画面で選べる）。
 * cartRefusal はカートに入れさせない種類の断りの文（チケットのように、App の 1 品の注文の画面だけで買うもの）。null ならカートに入る
 * stockless は在庫の数を使わない種類（予約の品のように、売れる数を App が別に持つもの）。ショップでは在庫の具合を見ずに「在庫あり」として出し、
 * 管理画面のホームの「在庫切れの商品」にも数えない
 * digital は送らない品（ダウンロードで渡すファイルなど。ADR 0084）。カートに入り、今の注文の流れで買えるが、送料・お届け先・在庫を使わない。
 * 1 回の注文で 1 点まで（同じファイルを 2 つ買う意味が無い）。受渡は digital で、物と一緒の注文では送る品の受渡と分ける。stockless と一緒に使う
 * inPerson は役務（設置の代行・作業の請け負いなど）。送らない品と同じく送料・お届け先・在庫を使わず 1 回の注文で 1 点までだが、受渡は in_person で、
 * 店の人が管理画面の注文の「提供済みにする」で済みにする（SaleOrderService::deliverInPerson）。ほかの種類の品と同じ注文には入れない（済ませ方が違うため）。stockless と一緒に使う
 * sellable は今売れる商品を App が答える口（ダウンロードの商品は、置いたファイルがある時だけ。見直し）。商品の ID の並びを受け、売れる ID の並びを返す。
 * null なら種類の決まりだけで売れる。足した App を使っていない店では、核がこの種類を売らない（Catalog）
 */
final readonly class ListingKindDefinition
{
    public function __construct(
        public string $kind,
        public OfferSide $offerSide,
        public Pricing $pricing,
        public string $label,
        public ?string $plugin = null,
        public ?string $cartRefusal = null,
        public bool $stockless = false,
        public bool $digital = false,
        public bool $inPerson = false,
        /** @var (Closure(list<string>): list<string>)|null */
        public ?Closure $sellable = null,
    ) {}
}
