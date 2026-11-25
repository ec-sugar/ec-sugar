<?php

declare(strict_types=1);

namespace EcSugar\PluginCartReminder;

use App\Core\Mail\SenderFacts;
use App\Core\Party\Models\Party;
use App\Core\Plugin\Services\MarketingConsents;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Transaction\Models\Transaction;
use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use Carbon\CarbonImmutable;
use EcSugar\PluginCartReminder\Mail\CartReminderMail;
use EcSugar\PluginCartReminder\Models\CartReminder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * お知らせを 1 通送る（送る仕事 SendCartReminder の中身。ADR 0067）。送るか・送らないかもここで決める。
 *
 * - 取る: queued の行だけを、行のロックの中で sending にする（同じ行の仕事が 2 つ動いても 1 通）
 * - 同じメールの決まり（7 日のうちに 1 通・後から注文した）は、メールの索引ごとのロック（pg_advisory_xact_lock）の中で確かめる。
 *   同じメールの別の注文の仕事が同時に動いても、後の方は先の方の sending を見て送らない
 * - 送らない時: App を使うのをやめた・お知らせのメールの受け取りに同意していない（メルマガの App が答える。使われていなければ誰にも送らない）・
 *   後から注文した・7 日のうちに送った・商品が今は 1 つも買えない・送り主の表示（特商法の表記）が足りない・注文にメールが無い
 * - 送れなかった（送り口が断った・つながらない）時は failed にして送り直さない（送れたのに断りに見えた時に 2 通にしない。メルマガと同じ）。
 *   失敗の記録は例外の種類だけ（送る仕組みの例外の文には宛先が入りうる。ADR 0010）
 * - 宛先は注文の買う人の暗号化された値から、送る時に読む（キュー・記録・ログに平文のメールを残さない）
 */
final class ReminderSender
{
    public function __construct(
        private readonly MarketingConsents $consents,
        private readonly PluginStates $states,
        private readonly ReminderItems $items,
    ) {}

    /**
     * @return string 結果（sent・skipped・failed・none〔取れなかった〕）
     */
    public function send(string $reminderId): string
    {
        $tenant = tenant();
        $row = CartReminder::query()->find($reminderId);

        if (! $tenant instanceof Tenant || $row === null || $row->status !== CartReminder::QUEUED) {
            return 'none';
        }

        $order = Transaction::query()->find($row->transaction_id);
        $party = $order?->customer_id === null ? null : Party::query()->find($order->customer_id);
        $email = $party?->email;
        $index = $party?->getAttribute('email_index');

        if ($order === null || ! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false || ! is_string($index)) {
            return self::skip($reminderId, 'no_email') ? 'skipped' : 'none';
        }

        $now = CarbonImmutable::now();
        $sender = SenderFacts::current();

        $prepared = DB::transaction(function () use ($reminderId, $order, $email, $index, $now, $sender): array|string {
            // 同じメールの仕事を 1 つずつに（7 日のうちに 1 通を同時でも守る）
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['cart-reminder|'.$index]);
            $row = CartReminder::query()->whereKey($reminderId)->where('status', CartReminder::QUEUED)->lockForUpdate()->first();

            if ($row === null) {
                return 'none';
            }

            $reason = match (true) {
                ! $this->states->enabled('cart-reminder') => 'disabled',
                CartReminders::orderedLater($order, $index) => 'ordered_later',
                CartReminders::sentRecently($row->id, $index, $now) => 'recent',
                ! $this->consents->consents($email) => 'no_consent',
                default => null,
            };
            // 載せる商品（今ショップで買えるものだけ）。1 つも無ければ送らない
            $items = $reason === null ? $this->items->forMail($order->id) : [];
            $reason ??= match (true) {
                $items === [] => 'unavailable',
                $sender->missing() !== [] => 'no_sender',
                default => null,
            };

            if ($reason !== null) {
                $row->forceFill(['status' => CartReminder::SKIPPED, 'reason' => $reason])->save();

                return 'skipped';
            }

            $token = CartReminders::token();
            $stop = CartReminders::token();
            $row->forceFill([
                'status' => CartReminder::SENDING,
                'token_hash' => CartReminders::hash($token),
                'unsubscribe_hash' => CartReminders::hash($stop),
                'expires_at' => $now->addDays(CartReminders::LINK_DAYS),
            ])->save();

            return ['token' => $token, 'stop' => $stop, 'items' => $items];
        });

        if (is_string($prepared)) {
            return $prepared;
        }

        try {
            Mail::to($email)->send(new CartReminderMail(
                (string) $tenant->getAttribute('name'),
                CartReminders::opening(),
                array_map(static fn (array $item): array => [
                    'title' => $item['title'],
                    'photo' => $item['photo'] === null ? null : (string) StorefrontUrls::of($tenant, $item['photo']),
                ], $prepared['items']),
                (string) StorefrontUrls::of($tenant, '/p/cart-reminder/'.$prepared['token']),
                (string) StorefrontUrls::of($tenant, '/p/cart-reminder/unsubscribe/'.$prepared['stop']),
                $sender,
                CartReminders::LINK_DAYS,
            ));
        } catch (Throwable $e) {
            Log::warning('カゴ落ちのお知らせを送れなかった', ['reminder' => $reminderId, 'error' => $e::class]);
            CartReminder::query()->whereKey($reminderId)->where('status', CartReminder::SENDING)->update([
                'status' => CartReminder::FAILED, 'token_hash' => null, 'unsubscribe_hash' => null, 'expires_at' => null, 'updated_at' => CarbonImmutable::now(),
            ]);

            return 'failed';
        }

        $done = CarbonImmutable::now();
        CartReminder::query()->whereKey($reminderId)->where('status', CartReminder::SENDING)->update(['status' => CartReminder::SENT, 'sent_at' => $done, 'updated_at' => $done]);

        return 'sent';
    }

    private static function skip(string $reminderId, string $reason): bool
    {
        return CartReminder::query()->whereKey($reminderId)->where('status', CartReminder::QUEUED)
            ->update(['status' => CartReminder::SKIPPED, 'reason' => $reason, 'updated_at' => CarbonImmutable::now()]) === 1;
    }
}
