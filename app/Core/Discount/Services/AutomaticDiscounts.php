<?php

declare(strict_types=1);

namespace App\Core\Discount\Services;

use App\Core\Discount\Enums\CouponKind;
use App\Core\Discount\Models\Coupon;
use App\Core\Money\Money;
use App\Core\Plugin\MemberBenefits;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;

/**
 * 自動で効く割引（ADR 0065）。コードを入れなくても、条件（期間・対象・最低の購入額・対象の数・会員だけ・回数）に合えば効く。
 * 照らし方・数え方・割り振りはクーポンと同じ（CouponService）。ここは「どれを効かせるか」と、ショップに見せる一言・名前だけを決める。
 *
 * - 値打ちは「送料を含めた最終の合計がいくら下がるか」（値引きの無い時の合計 − その割引を効かせた時の合計）。値引きの後の小計で送料を見積もり直すので、
 *   送料無料の線を下回って送料がかかる分も入る。送料無料の種類も同じ物差し
 * - 1 つの注文に値引きは 1 つ。効く自動の割引のうち値打ちがいちばん大きいもの（同じなら先に作った方）
 * - 入れたコードのクーポンは、自動の割引より値打ちが大きい時だけ使う（同じなら自動の割引。お客さんの 1 人 1 回のクーポンを同じ額のために使わせない）。
 *   使わない時は理由（CODE_NOT_BETTER）を出し、コードはカートに残す
 * - 値打ちが 0 以下の割引は効かせない（自動もクーポンも。クーポンなら理由 CODE_NO_SAVING を出し、コードはカートに残す）
 * - カートを見るたびと注文の確定（錠の中）で決め直す。注文の確定は確かめの画面の指紋で、効く値引きが変わったら確定しない
 * - 会員価格（ADR 0079。App「会員ランク」が会員ごとに決める定率の割引）も同じ候補に入る。自動の割引より先に照らすので、同じ値打ちなら会員価格
 */
final class AutomaticDiscounts
{
    public const string CODE_NOT_BETTER = '自動の割引が適用されているため、クーポンは使われていません。';

    public const string CODE_NO_SAVING = 'このクーポンを使ってもお支払いの合計が下がらないため（値引きの後に送料がかかるなど）、クーポンは使われていません。';

    /**
     * 入れたコードを使わない知らせのうち、誤りでないもの（自動の割引が効いている・合計が下がらない）。文の中で「クーポンは使っていません」と言い切っている
     */
    public static function isSkipNotice(?string $notice): bool
    {
        return $notice === self::CODE_NOT_BETTER || $notice === self::CODE_NO_SAVING;
    }

    public function __construct(
        private readonly CouponService $coupons,
        private readonly MemberBenefits $benefits,
    ) {}

    /**
     * 使っている自動の割引（止めていない・終わっていない。始まる前も含む）を作った順に。上限（CouponService::MAX_AUTOMATIC）は作る時に守る
     *
     * @return Collection<int, Coupon>
     */
    public function live(?CarbonImmutable $now = null): Collection
    {
        return CouponService::liveAutomatic($now)->orderBy('created_at')->orderBy('id')->limit(CouponService::MAX_AUTOMATIC)->get();
    }

    /**
     * 効かせる値引きを 1 つ決める。照らすのに要る数（使った数・1 人の回数・カテゴリ）は 1 度にまとめて読み、割引ごとにクエリを増やさない
     *
     * @param  list<array{key: string, listingId: string, amount: int, quantity?: int}>  $lines  今買える行（CartService::couponLines）
     * @param  Coupon|null  $code  カートに入れたコードのクーポン
     * @param  list<string>  $customerAccountIds  1 人の回数を数える台帳（会員がログインしていれば会員とメールの両方）
     * @param  Closure(list<array{key: string, listingId: string, amount: int, quantity?: int}>, int, list<string>, bool): int  $totalOf
     *                                                                                                                                    その行に値引き（額・対象の行・送料無料か）を効かせた時の最終の合計（送料を含む）。読み込み済みの送料の表で、クエリを使わずに計算する
     * @param  string|null  $memberAccountId  ログインした会員の台帳（会員価格を App に聞く。ADR 0079）
     */
    public function choose(array $lines, ?Coupon $code, array $customerAccountIds, bool $member, Closure $totalOf, ?string $memberAccountId = null): DiscountChoice
    {
        $now = CarbonImmutable::now();
        $live = $this->live($now);
        // 会員価格（ADR 0079）: 表に無い候補。数える回数が無いので、まとめて読む数（context）には入れない
        $memberPrice = $this->benefits->discountFor($memberAccountId);
        $candidates = $memberPrice === null ? $live : collect([Coupon::memberPrice($memberPrice)])->concat($live);
        $context = $this->coupons->context($code === null ? $live : $live->concat([$code]), $lines, $customerAccountIds);
        $base = $totalOf($lines, 0, [], false);
        $value = static fn (CouponOutcome $outcome): int => $base - $totalOf($lines, $outcome->discount, $outcome->eligible, $outcome->shippingWaived);

        $best = null;
        $bestValue = 0;
        $shortfalls = [];

        foreach ($candidates as $discount) {
            [$outcome, $short] = $this->coupons->inspect($discount, $lines, $customerAccountIds, $member, $now, $context);

            if (! $outcome->ok()) {
                if ($short !== null) {
                    $shortfalls[] = [$discount, $short];
                }

                continue;
            }

            // 作った順に見るので、同じ値打ちなら先に作った方が残る。値打ちが 0 以下（最初の $bestValue）は効かせない
            $worth = $value($outcome);

            if ($worth > $bestValue) {
                $best = $outcome;
                $bestValue = $worth;
            }
        }

        $applied = $best;
        $appliedValue = $bestValue;
        $notice = null;

        if ($code !== null) {
            $outcome = $this->coupons->evaluate($code, $lines, $customerAccountIds, $member, $now, $context);
            $worth = $outcome->ok() ? $value($outcome) : 0;

            if (! $outcome->ok()) {
                $notice = $outcome->reason;
            } elseif ($best !== null && $worth <= $bestValue) {
                $notice = self::CODE_NOT_BETTER;
            } elseif ($worth <= 0) {
                $notice = self::CODE_NO_SAVING;
            } else {
                $applied = $outcome;
                $appliedValue = $worth;
            }
        }

        return new DiscountChoice($applied, $notice, $this->hint($shortfalls, $lines, $appliedValue, $totalOf));
    }

    /**
     * 商品の画面に出す、その商品が対象で今使える自動の割引の名前（作った順）。始まる前・回数の上限は出さない。会員だけの割引はログインした会員にだけ。
     * ログインした会員がもう 1 人の回数を使い切った割引も出さない（ゲストは誰か分からないので出す）。最低の購入額・数の条件のある割引も出す（名前に条件を書いてもらう）
     *
     * @return list<string>
     */
    public function forListing(string $listingId, ?string $memberAccountId): array
    {
        // 会員価格（ADR 0079）は全品が対象なので、ログインした会員に特典があれば先頭に
        $memberPrice = $this->benefits->discountFor($memberAccountId);
        $now = CarbonImmutable::now();
        $discounts = $this->live($now)->filter(static fn (Coupon $d): bool => ($d->starts_at === null || $d->starts_at->lte($now)) && (! $d->members_only || $memberAccountId !== null))->values();

        $labels = $memberPrice === null ? [] : [$memberPrice->label];

        if ($discounts->isEmpty()) {
            return $labels;
        }

        $context = $this->coupons->context($discounts, [['key' => $listingId, 'listingId' => $listingId, 'amount' => 1, 'quantity' => 1]], $memberAccountId);

        foreach ($discounts as $discount) {
            if ($discount->usage_limit !== null && ($context->used[$discount->id] ?? 0) >= $discount->usage_limit) {
                continue;
            }

            if ($memberAccountId !== null && $discount->per_customer_limit !== null && ($context->usedBy[$discount->id] ?? 0) >= $discount->per_customer_limit) {
                continue;
            }

            $targeted = match ($discount->target) {
                'listings' => in_array($listingId, $discount->target_ids, true),
                'categories' => array_intersect($discount->target_ids, $context->categories[$listingId] ?? []) !== [],
                default => true,
            };

            if ($targeted) {
                $labels[] = (string) $discount->label;
            }
        }

        return $labels;
    }

    /**
     * 次に届く自動の割引の一言。足りないのが数か額のどちらかだけの割引のうち、届いた時のカートで（送料を含めた）値打ちが 0 より大きく、
     * 今の値引きより得になるもののいちばん近い 1 つ（数が足りないものを先に、少ない順。次に額が足りないものを少ない順。同じなら得な方、さらに同じなら先に作った方）。
     * 届いた時のカートは、額が足りなければ対象の最初の行にその額を、数が足りなければ対象の行の 1 点の平均の額でその数を足した形で見積もる
     *
     * @param  list<array{0: Coupon, 1: array{quantity: int|null, amount: int|null, base: int, eligible: list<string>}}>  $shortfalls  今は数か額だけが足りない自動の割引（作った順）
     * @param  list<array{key: string, listingId: string, amount: int, quantity?: int}>  $lines
     * @param  Closure(list<array{key: string, listingId: string, amount: int, quantity?: int}>, int, list<string>, bool): int  $totalOf
     */
    private function hint(array $shortfalls, array $lines, int $appliedValue, Closure $totalOf): ?string
    {
        $best = null;

        foreach ($shortfalls as [$discount, $short]) {
            $reached = self::reached($lines, $short);
            $base = 0;
            foreach ($reached as $line) {
                $base += in_array($line['key'], $short['eligible'], true) ? $line['amount'] : 0;
            }

            $discountAmount = CouponService::amountFor($discount, $base);
            $worth = $totalOf($reached, 0, [], false) - $totalOf($reached, $discountAmount, $short['eligible'], $discount->kind === CouponKind::FreeShipping);

            if ($worth <= 0 || $worth <= $appliedValue) {
                continue;
            }

            $rank = [$short['quantity'] === null ? 1 : 0, $short['quantity'] ?? (int) $short['amount'], -$worth];

            if ($best === null || $rank < $best['rank']) {
                $best = ['rank' => $rank, 'label' => (string) $discount->label, 'quantity' => $short['quantity'], 'amount' => (int) $short['amount']];
            }
        }

        if ($best === null) {
            return null;
        }

        return $best['quantity'] !== null
            ? 'あと '.$best['quantity'].' 点で「'.$best['label'].'」'
            : 'あと '.Money::format($best['amount']).' で「'.$best['label'].'」';
    }

    /**
     * 届いた時のカートの見積もり（対象の最初の行に、足りない額か「1 点の平均 × 足りない数」を足す）
     *
     * @param  list<array{key: string, listingId: string, amount: int, quantity?: int}>  $lines
     * @param  array{quantity: int|null, amount: int|null, base: int, eligible: list<string>}  $short
     * @return list<array{key: string, listingId: string, amount: int, quantity?: int}>
     */
    private static function reached(array $lines, array $short): array
    {
        $count = 0;
        foreach ($lines as $line) {
            $count += in_array($line['key'], $short['eligible'], true) ? ($line['quantity'] ?? 1) : 0;
        }

        $add = $short['quantity'] !== null ? $short['quantity'] * intdiv($short['base'], max(1, $count)) : (int) $short['amount'];

        foreach ($lines as $index => $line) {
            if (in_array($line['key'], $short['eligible'], true)) {
                $lines[$index]['amount'] += $add;
                $lines[$index]['quantity'] = ($line['quantity'] ?? 1) + ($short['quantity'] ?? 0);

                break;
            }
        }

        return $lines;
    }
}
