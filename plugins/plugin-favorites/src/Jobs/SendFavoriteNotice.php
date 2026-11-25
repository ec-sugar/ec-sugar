<?php

declare(strict_types=1);

namespace EcSugar\PluginFavorites\Jobs;

use App\Core\Mail\SenderFacts;
use App\Core\Money\Money;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Plugin\Services\MarketingConsents;
use App\Core\Plugin\Services\PluginStates;
use App\Models\Tenant;
use App\Storefront\Catalog\Catalog;
use App\Storefront\View\StorefrontUrls;
use Carbon\CarbonImmutable;
use EcSugar\PluginFavorites\FavoriteNotices;
use EcSugar\PluginFavorites\Favorites;
use EcSugar\PluginFavorites\Mail\FavoriteNoticeMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * お気に入りの知らせを 1 通送る（キュー。店の文脈は stancl/tenancy がつなぐ）。仕事に載せるのは記録の ID だけで、
 * 宛先は送る時に会員の台帳の暗号化された値から読む（キューにも失敗の記録にも平文のメールを残さない。ADR 0010）。
 *
 * - 取る: queued の記録だけを 1 文の条件つきの更新で sent にしてから送る（同じ記録の仕事が 2 つ動いても 1 通。送れなかったら failed）。試し直さない
 * - 送る前に確かめ直す: App を使っている・会員がいて退会していない・お知らせのメールの受け取りに同意している・まだ知らせを選んでいる・
 *   載せる商品がいまショップに出ている・送り主の表示（特商法の表記）がそろっている。どれかが違えば送らない（skipped）
 */
final class SendFavoriteNotice implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $noticeId) {}

    public function handle(MarketingConsents $consents, PluginStates $states, Favorites $favorites, Catalog $catalog): void
    {
        $tenant = tenant();
        $notice = DB::table('favorites_notices')->where('id', $this->noticeId)->where('status', 'queued')->first();

        if (! $tenant instanceof Tenant || $notice === null) {
            return;
        }

        $account = CustomerAccount::query()->find($notice->customer_account_id);
        $settings = $favorites->settings((string) $notice->customer_account_id);
        /** @var list<array{listing_id: string, kind: string, before: int, after: int}> $items */
        $items = json_decode((string) $notice->items, true, 4, JSON_THROW_ON_ERROR);
        // 選びをやめた知らせの商品と、いまショップに出ていない商品は載せない
        $items = array_values(array_filter($items, static fn (array $i): bool => (bool) ($settings[$i['kind']] ?? false)));
        $cards = [];

        foreach ($catalog->picked(array_values(array_unique(array_column($items, 'listing_id')))) as $card) {
            $cards[$card->id] = $card;
        }

        $items = array_values(array_filter($items, static fn (array $i): bool => isset($cards[$i['listing_id']])));
        $sender = SenderFacts::current();
        $email = $account?->email;

        if (! $states->enabled('favorites') || ! $account instanceof CustomerAccount || $account->disabled_at !== null || ! is_string($email)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false || ! $consents->consents($email) || $items === [] || $sender->missing() !== []) {
            DB::table('favorites_notices')->where('id', $this->noticeId)->where('status', 'queued')->update(['status' => 'skipped', 'updated_at' => CarbonImmutable::now()]);

            return;
        }

        $stop = FavoriteNotices::token();
        $now = CarbonImmutable::now();
        $claimed = DB::table('favorites_notices')->where('id', $this->noticeId)->where('status', 'queued')
            ->update(['status' => 'sent', 'sent_at' => $now, 'unsubscribe_hash' => FavoriteNotices::hash($stop), 'updated_at' => $now]);

        if ($claimed !== 1) {
            return;
        }

        $url = static fn (string $path): string => (string) StorefrontUrls::of($tenant, $path);

        try {
            Mail::to($email)->send(new FavoriteNoticeMail(
                (string) $tenant->getAttribute('name'),
                array_map(static fn (array $i): array => [
                    'title' => $cards[$i['listing_id']]->title,
                    'kind' => $i['kind'],
                    'before' => $i['kind'] === 'price_drop' ? Money::format($i['before'], $cards[$i['listing_id']]->currency) : null,
                    'after' => $i['kind'] === 'price_drop' ? Money::format($i['after'], $cards[$i['listing_id']]->currency) : null,
                    'url' => $url('/products/'.$i['listing_id']),
                ], $items),
                $url('/p/favorites/list'),
                $url('/p/favorites/unsubscribe/'.$stop),
                $sender,
            ));
        } catch (Throwable $e) {
            // 失敗の記録は例外の種類だけ（送る仕組みの例外の文には宛先が入りうる。ADR 0010）
            Log::warning('お気に入りの通知を送れなかった', ['notice' => $this->noticeId, 'error' => $e::class]);
            DB::table('favorites_notices')->where('id', $this->noticeId)->where('status', 'sent')->update(['status' => 'failed', 'unsubscribe_hash' => null, 'sent_at' => null, 'updated_at' => CarbonImmutable::now()]);
        }
    }
}
