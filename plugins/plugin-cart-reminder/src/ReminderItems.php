<?php

declare(strict_types=1);

namespace EcSugar\PluginCartReminder;

use App\Core\Plugin\MemberBenefits;
use App\Core\Transaction\Enums\LineType;
use App\Core\Transaction\Models\Transaction;
use App\Core\Transaction\Models\TransactionLine;
use App\Storefront\Catalog\Catalog;

/**
 * 消えた注文の商品（種類ごと）と、それが今ショップで買えるか（ADR 0067）。買えるかは核のショップの窓口（Catalog::purchasableVariants）で決め、
 * 決まりを App の中に写して持たない（公開中・販売中の種類・在庫が 1 つ以上）。値段は注文の時のものを使わない（メールにも出さない）
 */
final class ReminderItems
{
    public function __construct(private readonly Catalog $catalog) {}

    /**
     * 注文の商品の行を種類ごとにまとめたもの（オプションの行は除く。$addons は、オプションを付けていた種類か）
     *
     * @return array<string, array{quantity: int, title: string, addons: bool}> 種類の ID ごと
     */
    public function ordered(string $transactionId): array
    {
        $lines = TransactionLine::query()->where('transaction_id', $transactionId)->where('line_type', LineType::Item)->orderBy('position')->get();
        $positions = [];
        $out = [];

        foreach ($lines as $line) {
            if ($line->parent_position !== null || $line->variant_id === null) {
                continue;
            }

            $positions[$line->position] = $line->variant_id;
            $out[$line->variant_id] ??= ['quantity' => 0, 'title' => (string) $line->description, 'addons' => false];
            $out[$line->variant_id]['quantity'] += max(0, (int) $line->quantity);
        }

        // オプションの行（名入れ・ギフト包装など）が付いていた種類
        foreach (TransactionLine::query()->where('transaction_id', $transactionId)->whereNotNull('parent_position')->pluck('parent_position') as $parent) {
            $variant = $positions[(int) $parent] ?? null;

            if ($variant !== null) {
                $out[$variant]['addons'] = true;
            }
        }

        return $out;
    }

    /**
     * メールに載せる商品（今ショップで買えるものだけ）。名前は今の名前（種類の名前つき）、写真はショップの写真の道筋（無ければ null）
     *
     * @return list<array{title: string, photo: string|null}>
     */
    public function forMail(string $transactionId): array
    {
        $ordered = $this->ordered($transactionId);
        // 送る仕事はキューで、ログインした会員がいない。店の在庫として読み（ADR 0079）、会員だけ・ランクだけの商品はその注文の会員に見せてよい時だけ載せる
        $catalog = $this->catalog->forShop();
        $buyable = $catalog->purchasableVariants(array_keys($ordered));
        $account = Transaction::query()->whereKey($transactionId)->value('customer_account_id');
        $benefits = app(MemberBenefits::class);
        $out = [];

        foreach (array_keys($ordered) as $variantId) {
            $item = $buyable[$variantId] ?? null;

            if ($item === null || $item['available'] < 1 || $benefits->hides($item['listingId'], is_string($account) ? $account : null)) {
                continue;
            }

            // 会員だけの商品の写真は、メールでは開けない（写真の経路はゲストとして読む）ので載せない
            $out[] = ['title' => $item['card']->title, 'photo' => $catalog->forEveryone($item['listingId']) ? $item['card']->photo?->url('medium') : null];
        }

        return $out;
    }
}
