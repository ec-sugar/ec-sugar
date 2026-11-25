<?php

declare(strict_types=1);

namespace EcSugar\PluginContact;

use App\Core\Crypto\BlindIndex;
use App\Core\Event\EventStore;
use App\Core\Listing\Models\Listing;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Plugin\AdminPage\AdminActionRefused;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\RateLimit\Throttle;
use App\Core\Transaction\Models\Transaction;
use App\Storefront\Access\ClientSource;
use Carbon\CarbonImmutable;
use EcSugar\PluginContact\Jobs\SendContactMail;
use EcSugar\PluginContact\Models\ContactInquiry;
use EcSugar\PluginContact\Models\ContactReply;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * お問い合わせを受け付ける（お客さん）・返信する・状態を変える・消す（店の人）・古いものを片付ける（定期チェック）。表を書くのはここだけ。
 * どれも連鎖のロック（EventStore::lock()）の後にその行のロックを取り、同じトランザクションでイベントを残す
 * （contact.received・contact.replied・contact.status_changed・contact.deleted・contact.purged）。
 * イベントの中身は ID と種類・状態だけ（名前・メール・本文・返信の文は入れない。ADR 0010）。メールの仕事に載せるのも ID だけ
 *
 * - 回数の上限（受け付け）: 接続元ごとに 1 時間 SOURCE_HOURLY 回、同じメールへ（接続元をまたいで）1 日 EMAIL_DAILY 回、店全体で 1 日 SHOP_DAILY 件。
 *   数えるキーには店の ID を入れ、メールは索引で数える（平文を鍵に入れない）。どれかを超えたら受け付けず、しばらくしてからと伝える
 * - 返信: 1 件に REPLIES_MAX 通まで、1 件に 1 時間 REPLIES_HOURLY 通まで、同じ文を続けて送らない（押し直しで同じメールを 2 通送らない）
 */
final class ContactInquiries
{
    /** お客さんの操作の記録の actor（個人情報を入れない） */
    public const string CUSTOMER = 'storefront';

    /** 定期チェックの actor */
    public const string SYSTEM = 'system:contact';

    public const int SOURCE_HOURLY = 5;

    public const int EMAIL_DAILY = 5;

    public const int SHOP_DAILY = 300;

    public const int REPLY_MAX = 2000;

    public const int REPLIES_MAX = 20;

    public const int REPLIES_HOURLY = 10;

    /** 同じ文の返信を続けて送らない間（分） */
    public const int SAME_REPLY_MINUTES = 10;

    /** 済んでから消すまで（設定の値 => 日） */
    public const array RETENTION_DAYS = ['half_year' => 182, 'one_year' => 365, 'three_years' => 1095];

    public const string RETENTION_DEFAULT = 'one_year';

    /** 1 回の定期チェックで消す数（残りは次の回） */
    public const int PURGE_BATCH = 500;

    public function __construct(
        private readonly EventStore $events,
        private readonly PluginSettings $settings,
    ) {}

    /**
     * お客さんのお問い合わせを受け付ける。付ける商品と注文は、呼ぶ側が確かめたもの（商品はショップに出ている・注文は署名の合う道筋）。
     * ロックの中で、商品と注文がこの店にあるかを確かめ直す
     *
     * @throws ContactRefused 回数の上限
     */
    public function receive(ContactInput $input, ?string $listingId, ?string $orderId, ?CustomerAccount $member, ?string $ip): ContactInquiry
    {
        $this->allow($input->email, $ip);

        return DB::transaction(function () use ($input, $listingId, $orderId, $member): ContactInquiry {
            $this->events->lock();
            $listingId = $listingId === null ? null : Listing::query()->whereKey($listingId)->value('id');
            $orderId = $orderId === null ? null : Transaction::query()->whereKey($orderId)->where('kind', 'sale')->value('id');

            $inquiry = ContactInquiry::query()->create([
                'kind' => $input->kind,
                'listing_id' => is_string($listingId) ? $listingId : null,
                'transaction_id' => is_string($orderId) ? $orderId : null,
                'customer_account_id' => $member?->id,
                'name' => $input->name,
                'email' => $input->email,
                'body' => $input->body,
                'status' => ContactStatus::New,
            ]);
            $this->events->append('contact.received', 'contact_inquiry', $inquiry->id, [
                'kind' => $input->kind->value,
                'listing_id' => $inquiry->listing_id,
                'transaction_id' => $inquiry->transaction_id,
                'member' => $member !== null,
            ], self::CUSTOMER);

            $id = $inquiry->id;
            DB::afterCommit(static function () use ($id): void {
                SendContactMail::dispatch($id, SendContactMail::RECEIVED);
                SendContactMail::dispatch($id, SendContactMail::SHOP);
            });

            return $inquiry;
        });
    }

    /**
     * 店の人が返信する（メールで送り、送った文を残す）。新着なら対応中にする
     *
     * @throws AdminRecordNotFound
     * @throws ValidationException 返信の数・回数・同じ文（欄 reply）
     */
    public function reply(string $id, string $body, string $actor): ContactReply
    {
        $key = 'contact-reply|'.(string) tenant()?->getTenantKey().'|'.strtolower($id);

        return DB::transaction(function () use ($id, $body, $actor, $key): ContactReply {
            $this->events->lock();
            $inquiry = $this->locked($id);
            $replies = ContactReply::query()->where('inquiry_id', $inquiry->id)->orderByDesc('created_at')->orderByDesc('id')->get();

            if ($replies->count() >= self::REPLIES_MAX) {
                throw ValidationException::withMessages(['reply' => '1 件のお問い合わせに送れる返信は '.self::REPLIES_MAX.' 通までです。続きは新しいお問い合わせとして受けてください。']);
            }

            $last = $replies->first();

            if ($last instanceof ContactReply && $last->body === $body && $last->created_at->gt(CarbonImmutable::now()->subMinutes(self::SAME_REPLY_MINUTES))) {
                throw ValidationException::withMessages(['reply' => '同じ内容の返信を送ったばかりです。届いたかどうかは、少し時間をおいてからお客さんにご確認ください。']);
            }

            // 数えるのは送ると決めた後（ほかの理由で断った分は数えない）。先に数えてから比べる
            if (! Throttle::allow($key, self::REPLIES_HOURLY, 3600)) {
                throw ValidationException::withMessages(['reply' => '短い間に返信が続いています。しばらくしてから、もう一度お試しください。']);
            }
            $now = CarbonImmutable::now();
            $reply = ContactReply::query()->create(['inquiry_id' => $inquiry->id, 'body' => $body]);
            $inquiry->forceFill([
                'last_replied_at' => $now,
                'status' => $inquiry->status === ContactStatus::New ? ContactStatus::Open : $inquiry->status,
            ])->save();
            $this->events->append('contact.replied', 'contact_inquiry', $inquiry->id, ['reply_id' => $reply->id], $actor);

            $replyId = $reply->id;
            DB::afterCommit(static fn () => SendContactMail::dispatch($replyId, SendContactMail::REPLY));

            return $reply;
        });
    }

    /**
     * 状態を変える（新着・対応中・済み）。$from にない今の状態なら断る（ほかの人が先に変えた）
     *
     * @param  list<ContactStatus>  $from
     *
     * @throws AdminRecordNotFound
     * @throws AdminActionRefused
     */
    public function changeStatus(string $id, array $from, ContactStatus $to, string $actor): void
    {
        DB::transaction(function () use ($id, $from, $to, $actor): void {
            $this->events->lock();
            $inquiry = $this->locked($id);

            if (! in_array($inquiry->status, $from, true)) {
                throw new AdminActionRefused('このお問い合わせは、ほかの人が先に更新しました。画面を再読み込みしてください。');
            }

            $before = $inquiry->status;
            $inquiry->forceFill(['status' => $to, 'closed_at' => $to === ContactStatus::Closed ? CarbonImmutable::now() : null])->save();
            $this->events->append('contact.status_changed', 'contact_inquiry', $inquiry->id, ['from' => $before->value, 'to' => $to->value], $actor);
        });
    }

    /**
     * 消す（名前・メール・本文・返信ごと。お客さんから消してほしいと頼まれた時など）。元に戻せない
     *
     * @throws AdminRecordNotFound
     */
    public function delete(string $id, string $actor): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $this->events->lock();
            $inquiry = $this->locked($id);
            $inquiry->delete();
            $this->events->append('contact.deleted', 'contact_inquiry', $inquiry->id, [], $actor);
        });
    }

    /**
     * 済んでから店の決めた期間（設定の「済んだお問い合わせを消すまで」）がたったお問い合わせを、返信ごと消す。消した数を返す
     */
    public function purge(CarbonImmutable $now): int
    {
        $cutoff = $now->subDays(self::retentionDays($this->settings->publicValues('contact')['retention'] ?? null));
        $ids = ContactInquiry::query()->where('status', ContactStatus::Closed->value)->where('closed_at', '<', $cutoff)
            ->orderBy('closed_at')->limit(self::PURGE_BATCH)->pluck('id');
        $count = 0;

        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id, $cutoff): int {
                $this->events->lock();
                // ロックの中で確かめ直す（同じ時に対応中へ戻された分は消さない）
                $inquiry = ContactInquiry::query()->whereKey($id)->where('status', ContactStatus::Closed->value)->where('closed_at', '<', $cutoff)->lockForUpdate()->first();

                if ($inquiry === null) {
                    return 0;
                }

                $inquiry->delete();
                $this->events->append('contact.purged', 'contact_inquiry', $inquiry->id, [], self::SYSTEM);

                return 1;
            });
        }

        return $count;
    }

    /**
     * 設定の値の日数（知らない値・空は 1 年）
     */
    public static function retentionDays(mixed $value): int
    {
        return self::RETENTION_DAYS[is_string($value) && isset(self::RETENTION_DAYS[$value]) ? $value : self::RETENTION_DEFAULT];
    }

    /**
     * 受け付けの回数の上限（数えてから比べ、超えていれば断る）
     *
     * @throws ContactRefused
     */
    private function allow(string $email, ?string $ip): void
    {
        $shop = (string) tenant()?->getTenantKey();
        $keys = [
            ["contact|{$shop}|source|".ClientSource::of($ip), self::SOURCE_HOURLY, 3600],
            ["contact|{$shop}|email|".BlindIndex::of('email', $email), self::EMAIL_DAILY, 86_400],
            ["contact|{$shop}|shop", self::SHOP_DAILY, 86_400],
        ];

        // 先に数えてから上限と比べる（核の Throttle。狭い上限から数え、越えた所で止める）
        $over = Throttle::firstOver($keys);

        if ($over !== null) {
            throw new ContactRefused($over === "contact|{$shop}|shop"
                ? 'いまはお問い合わせを受け付けられません。時間をおいてお試しください。'
                : '短い間に何度も送信されています。しばらくしてから、もう一度お試しください。');
        }
    }

    /**
     * お問い合わせの行のロック（無ければ AdminRecordNotFound）
     */
    private function locked(string $id): ContactInquiry
    {
        $inquiry = ContactInquiry::query()->whereKey(strtolower($id))->lockForUpdate()->first();

        if ($inquiry === null) {
            throw new AdminRecordNotFound;
        }

        return $inquiry;
    }
}
