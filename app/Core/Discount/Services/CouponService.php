<?php

declare(strict_types=1);

namespace App\Core\Discount\Services;

use App\Core\Discount\Enums\CouponKind;
use App\Core\Discount\Models\Coupon;
use App\Core\Discount\Models\CouponRedemption;
use App\Core\Event\EventStore;
use App\Core\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * クーポンと自動で効く割引（ADR 0054・0065）。値引きの額はここでだけ決める（カート・確かめの画面・注文の確定が同じ答えを使う）。
 * 自動の割引も同じ表・同じ照らし方・同じ数え方で、どれを効かせるかは AutomaticDiscounts が決める。
 *
 * - 照らす（evaluate）: 状態・期間・会員だけ・最低の購入額・対象・対象の数・全体と 1 人の回数。額は対象の行の小計から（定率は円未満を切り捨て、上限つき）
 * - 使う（redeem）: 注文を作るトランザクションの中で、台帳のロックの後にクーポンの行を錠で取り、回数を数え直してから記録する（同時の注文で上限を超えない）
 * - 戻す（release）: 払われずに消えた注文。払った後の取消では戻さない
 * - 作る・直す・止める・再開（管理画面）: 台帳のロックの後にクーポンの行を錠で取り、イベントを同じトランザクションで残す
 */
final class CouponService
{
    public const string UNKNOWN = 'このクーポンは使えません。コードを確認してください。';

    public const string USED_UP = 'このクーポンは、使える回数の上限に達しました。';

    public const string USED_BY_YOU = 'このクーポンは、すでにご利用済みです。';

    /** 店の人が決める欄（コードと method は作る時だけ。label は自動の割引だけ） */
    public const array FIELDS = ['name', 'label', 'kind', 'value', 'max_discount', 'target', 'target_ids', 'min_subtotal', 'min_quantity', 'starts_at', 'ends_at', 'usage_limit', 'per_customer_limit', 'members_only', 'status'];

    /** 同時に使っている自動の割引の数の上限（カートを見るたびに全部を照らすので、計算の量を抑える。ADR 0065） */
    public const int MAX_AUTOMATIC = 20;

    public const string TOO_MANY_AUTOMATIC = '同時に使える自動の割引は 20 件までです。使っていない自動の割引を停止してから、もう一度お試しください。';

    public const string LABEL_REQUIRED = '自動の割引には、お客さんに表示する名前を 40 文字以内で入力してください。';

    /** 一度でも使われたら変えない欄（使った記録の額・割り振りと合わなくなるため） */
    public const array FIXED_ONCE_USED = ['kind', 'value', 'max_discount', 'target', 'target_ids'];

    public const string LOCKED = 'このクーポンはすでに注文に使われたため、値引きの種類・額・対象は変更できません。変えたいときは、このクーポンを停止して新しいクーポンを作ってください。';

    public function __construct(private readonly EventStore $events) {}

    /**
     * 入れられたコードを持つ形にそろえる（前後の空白・全角を除き、大文字に）。形が合わなければ null
     */
    public static function normalize(string $raw): ?string
    {
        $code = strtoupper(mb_convert_kana(trim($raw), 'as'));

        return preg_match('/\A[A-Z0-9]+(-[A-Z0-9]+)*\z/', $code) === 1 && strlen($code) >= 3 && strlen($code) <= 32 ? $code : null;
    }

    public function find(?string $raw): ?Coupon
    {
        $code = $raw === null ? null : self::normalize($raw);

        return $code === null ? null : Coupon::query()->where('code', $code)->first();
    }

    /**
     * @param  list<array{key: string, listingId: string, amount: int, quantity?: int}>  $lines  今買える行（amount はオプションを含む行の小計、quantity は数）
     * @param  string|list<string>|null  $customerAccountIds  分かっていれば 1 人の回数を見る台帳（会員がログインしていれば会員とメールの両方の台帳で数える）
     * @param  DiscountContext|null  $context  まとめて読んだ数（無ければここで読む）
     */
    public function evaluate(Coupon $coupon, array $lines, string|array|null $customerAccountIds, bool $member, ?CarbonImmutable $now = null, ?DiscountContext $context = null): CouponOutcome
    {
        [$problems, $eligible] = $this->check($coupon, $lines, self::ids($customerAccountIds), $member, $now ?? CarbonImmutable::now(), false, $context);

        return self::outcome($coupon, $lines, $problems, $eligible);
    }

    /**
     * 照らした答えと、次に届く割引の一言のための不足を 1 度に（ADR 0065。理由を全部見るので、まとめて読んだ数 $context と使う）。
     * 不足は、足りないのが「数」か「額」のどちらか 1 つだけのときだけ（ほかの条件で使えない・もう使える・両方足りないなら null）。base は今の対象の行の小計
     *
     * @param  list<array{key: string, listingId: string, amount: int, quantity?: int}>  $lines
     * @param  string|list<string>|null  $customerAccountIds
     * @return array{0: CouponOutcome, 1: array{quantity: int|null, amount: int|null, base: int, eligible: list<string>}|null}
     */
    public function inspect(Coupon $coupon, array $lines, string|array|null $customerAccountIds, bool $member, CarbonImmutable $now, ?DiscountContext $context = null): array
    {
        [$problems, $eligible] = $this->check($coupon, $lines, self::ids($customerAccountIds), $member, $now, true, $context);
        $keys = array_keys($problems);
        $short = $keys === ['min_quantity'] || $keys === ['min_subtotal'] ? [
            'quantity' => $keys === ['min_quantity'] ? (int) $coupon->min_quantity - self::quantityOf($lines, $eligible) : null,
            'amount' => $keys === ['min_subtotal'] ? (int) $coupon->min_subtotal - array_sum(array_column($lines, 'amount')) : null,
            'base' => self::baseOf($lines, $eligible),
            'eligible' => $eligible,
        ] : null;

        return [self::outcome($coupon, $lines, $problems, $eligible), $short];
    }

    /**
     * 照らす割引の数を、1 回でまとめて読む（使った数・そのお客さんが使った数・カートの商品のカテゴリ）。割引の数に比例してクエリを増やさない
     *
     * @param  iterable<Coupon>  $coupons
     * @param  list<array{key: string, listingId: string, amount: int, quantity?: int}>  $lines
     * @param  string|list<string>|null  $customerAccountIds
     */
    public function context(iterable $coupons, array $lines, string|array|null $customerAccountIds): DiscountContext
    {
        $ids = [];
        $categories = false;
        foreach ($coupons as $coupon) {
            $ids[] = $coupon->id;
            $categories = $categories || $coupon->target === 'categories';
        }

        $accounts = self::ids($customerAccountIds);
        $usedBy = $ids === [] || $accounts === [] ? [] : CouponRedemption::query()->whereIn('coupon_id', $ids)->whereIn('customer_account_id', $accounts)
            ->whereIn('status', CouponRedemption::COUNTED)->groupBy('coupon_id')->selectRaw('coupon_id, count(*) AS used')->pluck('used', 'coupon_id')
            ->map(static fn (mixed $used): int => (int) $used)->all();
        $map = [];

        if ($categories && $lines !== []) {
            foreach (DB::table('listing_categories')->whereIn('listing_id', array_values(array_unique(array_column($lines, 'listingId'))))->get(['listing_id', 'category_id']) as $row) {
                $map[(string) $row->listing_id][] = (string) $row->category_id;
            }
        }

        return new DiscountContext($this->usedCounts($ids), $usedBy, $map);
    }

    /**
     * @param  list<array{key: string, listingId: string, amount: int, quantity?: int}>  $lines
     * @param  array<string, string>  $problems
     * @param  list<string>  $eligible
     */
    private static function outcome(Coupon $coupon, array $lines, array $problems, array $eligible): CouponOutcome
    {
        if ($problems !== []) {
            return CouponOutcome::rejected($coupon, (string) reset($problems));
        }

        return CouponOutcome::applies($coupon, self::amountFor($coupon, self::baseOf($lines, $eligible)), $coupon->kind === CouponKind::FreeShipping, $eligible);
    }

    /**
     * 1 人の回数を数える台帳の ID（重なりと空を除く）
     *
     * @param  string|list<string>|null  $ids
     * @return list<string>
     */
    private static function ids(string|array|null $ids): array
    {
        return array_values(array_unique(array_filter((array) $ids, static fn (string $id): bool => $id !== '')));
    }

    /**
     * 対象の行の小計（base）からの値引きの額（定額は小計まで、定率は円未満を切り捨てて上限まで、送料無料は 0）
     */
    public static function amountFor(Coupon $coupon, int $base): int
    {
        return match ($coupon->kind) {
            CouponKind::Fixed => min($coupon->value, $base),
            CouponKind::Percent => min(intdiv($base * $coupon->value, 100), $coupon->max_discount ?? PHP_INT_MAX),
            CouponKind::FreeShipping => 0,
        };
    }

    /**
     * 使えない理由を、見る順に（状態・期間・会員だけ・最低の購入額・対象・数・全体と 1 人の回数）。$all でなければ最初の 1 つで止める。
     * 文はお客さんに出す。自動の割引は名前で言う（「割引「秋のセール」は…」）
     *
     * @param  list<array{key: string, listingId: string, amount: int, quantity?: int}>  $lines
     * @param  list<string>  $customerAccountIds
     * @return array{0: array<string, string>, 1: list<string>} [理由の印 => 文, 対象の行の鍵]
     */
    private function check(Coupon $coupon, array $lines, array $customerAccountIds, bool $member, CarbonImmutable $now, bool $all, ?DiscountContext $context = null): array
    {
        $what = $coupon->isAutomatic() ? '割引「'.$coupon->label.'」は' : 'このクーポンは';
        $problems = [];
        $eligible = [];

        if ($coupon->status !== 'active') {
            $problems['status'] = $coupon->isAutomatic() ? $what.'、現在ご利用いただけません。' : self::UNKNOWN;
        }

        if ($coupon->starts_at !== null && $now->lt($coupon->starts_at)) {
            $problems['starts_at'] = $what.'、まだ使えません（'.$coupon->starts_at->timezone('Asia/Tokyo')->format('n月j日 H:i').'から）。';
        }

        if ($coupon->ends_at !== null && $now->gte($coupon->ends_at)) {
            $problems['ends_at'] = $what.'、利用期間が終了しました。';
        }

        if ($coupon->members_only && ! $member) {
            $problems['members_only'] = $what.'会員の方だけが使えます。ログインしてからお使いください。';
        }

        if (! $all && $problems !== []) {
            return [$problems, []];
        }

        if ($coupon->min_subtotal !== null && array_sum(array_column($lines, 'amount')) < $coupon->min_subtotal) {
            $problems['min_subtotal'] = $what.'、商品の合計が '.Money::format($coupon->min_subtotal).' 以上で使えます。';
        }

        if (! $all && $problems !== []) {
            return [$problems, []];
        }

        $eligible = $this->eligible($coupon, $lines, $context);

        if ($eligible === []) {
            $problems['target'] = $coupon->isAutomatic() ? '割引「'.$coupon->label.'」の対象の商品が、カートにありません。' : 'このクーポンの対象の商品が、カートにありません。';
        }

        if ($coupon->min_quantity !== null && self::quantityOf($lines, $eligible) < $coupon->min_quantity) {
            $problems['min_quantity'] = $what.'、対象の商品を '.$coupon->min_quantity.' 点以上で使えます。';
        }

        if (! $all && $problems !== []) {
            return [$problems, $eligible];
        }

        if ($coupon->usage_limit !== null && ($context !== null ? ($context->used[$coupon->id] ?? 0) : $this->used($coupon)) >= $coupon->usage_limit) {
            $problems['usage_limit'] = $coupon->isAutomatic() ? $what.'、使える回数の上限に達しました。' : self::USED_UP;
        }

        if ($customerAccountIds !== [] && $coupon->per_customer_limit !== null && ($all || $problems === [])
            && ($context !== null ? ($context->usedBy[$coupon->id] ?? 0) : $this->usedBy($coupon, $customerAccountIds)) >= $coupon->per_customer_limit) {
            $problems['per_customer_limit'] = $coupon->isAutomatic() ? $what.'、すでにご利用済みです。' : self::USED_BY_YOU;
        }

        return [$problems, $eligible];
    }

    /**
     * @param  list<array{key: string, listingId: string, amount: int, quantity?: int}>  $lines
     * @param  list<string>  $eligible
     */
    private static function baseOf(array $lines, array $eligible): int
    {
        $base = 0;
        foreach ($lines as $line) {
            if (in_array($line['key'], $eligible, true)) {
                $base += $line['amount'];
            }
        }

        return $base;
    }

    /**
     * 対象の行の数の合計（まとめ買いの条件。ADR 0065）
     *
     * @param  list<array{key: string, listingId: string, amount: int, quantity?: int}>  $lines
     * @param  list<string>  $eligible
     */
    private static function quantityOf(array $lines, array $eligible): int
    {
        $quantity = 0;
        foreach ($lines as $line) {
            if (in_array($line['key'], $eligible, true)) {
                $quantity += $line['quantity'] ?? 1;
            }
        }

        return $quantity;
    }

    /**
     * 注文にクーポンを使った記録を残す（注文を作るトランザクションの中、台帳のロックの後で呼ぶ）。
     * クーポンの行を錠で取り、照らし直す（同時の注文で回数の上限を超えない・確かめた後に止められた）。使えなければ CouponRejected
     *
     * @param  list<array{key: string, listingId: string, amount: int, quantity?: int}>  $lines
     * @param  string|list<string>|null  $customerAccountIds  1 人の回数を数える台帳（会員とメール）。記録には最初の ID を残す
     * @param  array<int|string, int>  $allocations  明細の位置 => 値引きの額
     */
    public function redeem(string $transactionId, Coupon $coupon, array $lines, string|array|null $customerAccountIds, bool $member, int $expectedAmount, array $allocations, int $waivedShipping, string $actor): CouponRedemption
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('クーポンの記録は注文を作るトランザクションの中で');
        }

        $locked = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
        $outcome = $this->evaluate($locked, $lines, $customerAccountIds, $member);

        if (! $outcome->ok()) {
            throw new CouponRejected((string) $outcome->reason);
        }

        if ($outcome->discount + ($outcome->shippingWaived ? $waivedShipping : 0) !== $expectedAmount || array_sum($allocations) !== $outcome->discount) {
            throw new CouponRejected('クーポンの内容が変わりました。カートを確認してください。');
        }

        $redemption = CouponRedemption::create([
            'coupon_id' => $locked->id,
            'transaction_id' => $transactionId,
            // 記録はいちばん前の台帳（会員がログインしていれば会員の台帳。メールを書き換えても同じ人として数える）
            'customer_account_id' => self::ids($customerAccountIds)[0] ?? null,
            'amount' => $expectedAmount,
            'allocations' => $allocations,
            'status' => 'reserved',
        ]);

        $this->events->append('coupon.redeemed', 'transaction', $transactionId, ['coupon_id' => $locked->id, 'amount' => $expectedAmount], $actor);

        return $redemption;
    }

    /**
     * 払われずに消えた注文のクーポンを戻す（呼ぶ側が台帳のロックを持つ）。無ければ何もしない
     */
    public function release(string $transactionId, ?string $actor): void
    {
        $redemption = CouponRedemption::query()->where('transaction_id', $transactionId)->where('status', 'reserved')->lockForUpdate()->first();

        if ($redemption === null) {
            return;
        }

        $redemption->update(['status' => 'released']);
        $this->events->append('coupon.released', 'transaction', $transactionId, ['coupon_id' => $redemption->coupon_id], $actor);
    }

    /**
     * 支払いが届いた注文のクーポンを「使った」に（数え方は変わらない。払う前と後を見分けるため）
     */
    public function markUsed(string $transactionId): void
    {
        CouponRedemption::query()->where('transaction_id', $transactionId)->where('status', 'reserved')->update(['status' => 'used', 'updated_at' => now()]);
    }

    /**
     * クーポン・自動の割引を作る（管理画面。ADR 0054 の 3・8、ADR 0065）。値の幅は管理画面の入口（CouponRequest）で確かめ、DB の制約が 2 枚目。
     * コードの形と重なり・対象の ID がこの店にあるか・自動の割引の数の上限はここで（錠の中で）確かめる。断るときは ValidationException（管理画面では 422）
     *
     * @param  array<string, mixed>  $values
     */
    public function create(array $values, string $actor): Coupon
    {
        $automatic = ($values['method'] ?? Coupon::CODE) === Coupon::AUTOMATIC;
        // 自動の割引はコードを持たない（入れられても捨てる）。クーポンは今までどおりコードが要る
        $code = ! $automatic && is_string($values['code'] ?? null) ? self::normalize($values['code']) : null;

        if (! $automatic && $code === null) {
            throw ValidationException::withMessages(['code' => 'コードは英数字とハイフンで 3〜32 文字にしてください（ハイフンは先頭・末尾・続けては使えません）。']);
        }

        $values = array_intersect_key($values, array_flip(self::FIELDS));
        $values['label'] = $automatic ? self::label($values['label'] ?? null) : null;

        return DB::transaction(function () use ($values, $code, $automatic, $actor): Coupon {
            $this->events->lock();

            if ($code !== null && Coupon::query()->where('code', $code)->exists()) {
                throw ValidationException::withMessages(['code' => '同じコードのクーポンがあります。別のコードにしてください。']);
            }

            $this->assertTargetsExist((string) ($values['target'] ?? 'all'), $values['target_ids'] ?? [], []);

            $coupon = new Coupon(['method' => $automatic ? Coupon::AUTOMATIC : Coupon::CODE, 'code' => $code] + $values);
            $this->assertAutomaticRoom($coupon, 'method');
            $coupon->save();
            $this->events->append('coupon.created', 'coupon', $coupon->id, ['kind' => $coupon->kind->value, 'method' => $coupon->method], $actor);

            return $coupon;
        });
    }

    /**
     * クーポンを直す（管理画面。コードと method は変えない）。一度でも注文に使われたクーポンは、値引きの種類・値・上限の額・対象を変えない
     * （使った記録の額と割り振りが、クーポンの今の内容と合わなくなるため。ADR 0054 の作って決めたこと）。期間・条件・回数・メモは変えられる。
     * 自動の割引の名前（label）も変えられる（作った注文の値引きの行には、その時の名前が写してある）。
     * 注文の確定（redeem）と同じ台帳のロックの中で使われたかを見るので、確かめた直後に使われて食い違うことはない
     *
     * @param  array<string, mixed>  $values
     */
    public function update(string $couponId, array $values, string $actor): Coupon
    {
        return DB::transaction(function () use ($couponId, $values, $actor): Coupon {
            $this->events->lock();
            $coupon = Coupon::query()->whereKey($couponId)->lockForUpdate()->firstOrFail();
            $values = array_intersect_key($values, array_flip(array_diff(self::FIELDS, ['status'])));

            if (! $coupon->isAutomatic()) {
                unset($values['label']);
            } elseif (array_key_exists('label', $values)) {
                $values['label'] = self::label($values['label']);
            }

            if ($this->everUsed($coupon)) {
                foreach (self::FIXED_ONCE_USED as $field) {
                    if (array_key_exists($field, $values) && ! self::same($coupon, $field, $values[$field])) {
                        throw ValidationException::withMessages([$field => self::LOCKED]);
                    }
                }
            }

            $this->assertTargetsExist((string) ($values['target'] ?? $coupon->target), $values['target_ids'] ?? $coupon->target_ids, $coupon->target_ids);

            $coupon->fill($values);
            $changed = array_keys($coupon->getDirty());

            if ($changed === []) {
                return $coupon;
            }

            // 終わった自動の割引の期間を延ばすと、また「使っている」に数える
            $this->assertAutomaticRoom($coupon, 'ends_at');
            $coupon->save();
            sort($changed);
            $this->events->append('coupon.updated', 'coupon', $coupon->id, ['fields' => $changed], $actor);

            return $coupon;
        });
    }

    /**
     * 止める（active → paused）・再開する（paused → active）。止めたクーポンはショップで「コードを確かめてください」と断り、止めた自動の割引は効かない。
     * 今と同じ状態なら何もしない（イベントも残さない）。自動の割引の再開は、使っている自動の割引が上限より少ない時だけ
     */
    public function setStatus(string $couponId, string $status, string $actor): Coupon
    {
        if (! in_array($status, ['active', 'paused'], true)) {
            throw new InvalidArgumentException('クーポンの状態は active か paused');
        }

        return DB::transaction(function () use ($couponId, $status, $actor): Coupon {
            $this->events->lock();
            $coupon = Coupon::query()->whereKey($couponId)->lockForUpdate()->firstOrFail();

            if ($coupon->status === $status) {
                return $coupon;
            }

            $coupon->status = $status;
            $this->assertAutomaticRoom($coupon, 'status');
            $coupon->save();
            $this->events->append($status === 'paused' ? 'coupon.paused' : 'coupon.resumed', 'coupon', $coupon->id, [], $actor);

            return $coupon;
        });
    }

    /**
     * 自動の割引を「使っている」（止めていない・終わっていない。始まる前も含む）にするとき、ほかに使っているものが上限に達していれば断る。
     * 呼ぶ側が台帳のロックを持つ（同時に作っても上限を超えない）
     */
    private function assertAutomaticRoom(Coupon $coupon, string $field): void
    {
        if (! $coupon->isAutomatic() || $coupon->status !== 'active' || ($coupon->ends_at !== null && $coupon->ends_at->lte(CarbonImmutable::now()))) {
            return;
        }

        $others = self::liveAutomatic()->when($coupon->exists, static fn ($q) => $q->whereKeyNot($coupon->id))->count();

        if ($others >= self::MAX_AUTOMATIC) {
            throw ValidationException::withMessages([$field => self::TOO_MANY_AUTOMATIC]);
        }
    }

    /**
     * 使っている自動の割引（止めていない・終わっていない。始まる前も含む）
     *
     * @return Builder<Coupon>
     */
    public static function liveAutomatic(?CarbonImmutable $now = null): Builder
    {
        $now ??= CarbonImmutable::now();

        return Coupon::query()->where('method', Coupon::AUTOMATIC)->where('status', 'active')
            ->where(static fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    /**
     * 自動の割引の名前をそろえる（前後の空白を除く）。空・40 文字を超える・制御文字は断る
     */
    private static function label(mixed $raw): string
    {
        $label = is_string($raw) ? trim($raw) : '';

        if ($label === '' || mb_strlen($label) > 40 || preg_match('/[\p{Cc}\p{Cf}]/u', $label) === 1) {
            throw ValidationException::withMessages(['label' => self::LABEL_REQUIRED]);
        }

        return $label;
    }

    /**
     * 使われた数（払う前と払った後。払われずに消えた注文は数えない）をクーポンごとに
     *
     * @param  list<string>  $couponIds
     * @return array<string, int>
     */
    public function usedCounts(array $couponIds): array
    {
        if ($couponIds === []) {
            return [];
        }

        return CouponRedemption::query()->whereIn('coupon_id', $couponIds)->whereIn('status', CouponRedemption::COUNTED)
            ->groupBy('coupon_id')->selectRaw('coupon_id, count(*) AS used')->pluck('used', 'coupon_id')
            ->map(static fn (mixed $used): int => (int) $used)->all();
    }

    /**
     * 一度でも注文に使われたか（払われずに消えた注文も含む。記録が残っているので）
     */
    public function everUsed(Coupon $coupon): bool
    {
        return CouponRedemption::query()->where('coupon_id', $coupon->id)->exists();
    }

    /**
     * 対象の ID がこの店のカテゴリ・商品か（店の DB の中だけを見るので、ほかの店の ID は「見つからない」になる）。
     * 前から選んでいた ID（$kept）は確かめない（後で消したカテゴリが残っていても、ほかの欄を直せるように）
     *
     * @param  list<string>  $kept
     */
    private function assertTargetsExist(string $target, mixed $ids, array $kept): void
    {
        $table = match ($target) {
            'categories' => 'categories',
            'listings' => 'listings',
            default => null,
        };

        if ($table === null || ! is_array($ids)) {
            return;
        }

        $fresh = array_values(array_diff(array_filter($ids, 'is_string'), $kept));

        if ($fresh !== [] && DB::table($table)->whereIn('id', $fresh)->count() !== count(array_unique($fresh))) {
            throw ValidationException::withMessages(['target_ids' => $target === 'categories' ? '選んだカテゴリが見つかりません。画面を読み込み直してください。' : '選んだ商品が見つかりません。画面を読み込み直してください。']);
        }
    }

    private static function same(Coupon $coupon, string $field, mixed $value): bool
    {
        $now = $coupon->getAttribute($field);

        return match ($field) {
            'kind' => ($now instanceof CouponKind ? $now->value : $now) === ($value instanceof CouponKind ? $value->value : $value),
            'target_ids' => is_array($value) && self::sorted($coupon->target_ids) === self::sorted($value),
            default => $now === $value,
        };
    }

    /**
     * @param  array<mixed>  $ids
     * @return list<mixed>
     */
    private static function sorted(array $ids): array
    {
        $ids = array_values($ids);
        sort($ids);

        return $ids;
    }

    private function used(Coupon $coupon): int
    {
        return CouponRedemption::query()->where('coupon_id', $coupon->id)->whereIn('status', CouponRedemption::COUNTED)->count();
    }

    /**
     * 1 人が使った数（会員がログインしていれば、会員の台帳とメールの台帳の両方で数える。注文のメールを書き換えても越えられない）
     *
     * @param  list<string>  $customerAccountIds
     */
    private function usedBy(Coupon $coupon, array $customerAccountIds): int
    {
        return CouponRedemption::query()->where('coupon_id', $coupon->id)->whereIn('customer_account_id', $customerAccountIds)->whereIn('status', CouponRedemption::COUNTED)->count();
    }

    /**
     * @param  list<array{key: string, listingId: string, amount: int, quantity?: int}>  $lines
     * @return list<string>
     */
    private function eligible(Coupon $coupon, array $lines, ?DiscountContext $context = null): array
    {
        $ids = array_values(array_filter($coupon->target_ids, 'is_string'));
        $listings = match (true) {
            $coupon->target === 'listings' => $ids,
            // カテゴリは、その商品が入っているカテゴリで見る（子のカテゴリは含めない。店が子も選ぶ）。まとめて読んであればそれを使う
            $coupon->target === 'categories' && $context !== null => array_keys(array_filter($context->categories, static fn (array $categories): bool => array_intersect($categories, $ids) !== [])),
            $coupon->target === 'categories' => DB::table('listing_categories')->whereIn('category_id', $ids)->whereIn('listing_id', array_column($lines, 'listingId'))->pluck('listing_id')->all(),
            default => null,
        };

        $keys = [];
        foreach ($lines as $line) {
            if ($line['amount'] > 0 && ($listings === null || in_array($line['listingId'], $listings, true))) {
                $keys[] = $line['key'];
            }
        }

        return $keys;
    }
}
