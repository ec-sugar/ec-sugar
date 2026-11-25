<?php

declare(strict_types=1);

namespace App\Storefront\Checkout;

use App\Core\Order\AgeChecks;
use App\Core\Plugin\AgeCheck\AgeAnswer;
use App\Core\Plugin\AgeCheck\AgeCheckRefused;
use App\Core\Plugin\AgeCheck\AgeRule;
use App\Storefront\Cart\CartView;
use App\Storefront\Members\Member;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 注文の入力の年齢の確かめ（ADR 0077）。年齢の要る品のあるカートで、生年月日（会員が前に確かめていなければ）と同意を受け、
 * 満年齢をサーバーで数えて答え（AgeAnswer）にする。**生年月日はここで捨てる**（答えにもセッションにも入れない）。
 * 足りなければ入力の画面に理由を出す。注文の確定は錠の中で、今の決まりと照らし直す（AgeChecks::guard）
 */
final class CheckoutAgeCheck
{
    public function __construct(private readonly AgeChecks $ages) {}

    /**
     * カートの年齢（要らなければ 0）と、ログインした会員が前に確かめた年齢
     *
     * @return array{age: int, remembered: int}
     *
     * @throws AgeCheckRefused
     */
    public function requirement(CartView $cart, ?string $memberId): array
    {
        $listings = [];
        foreach ($cart->lines as $line) {
            if ($line['purchasable']) {
                $listings[] = (string) $line['listingId'];
            }
        }

        $age = $this->ages->required($listings);

        return ['age' => $age, 'remembered' => $age === 0 ? 0 : $this->ages->remembered($memberId)];
    }

    /**
     * 生年月日を聞かなくてよい年齢（会員が前に確かめた年齢と、このセッションで前に生年月日で確かめた年齢の大きい方）。
     * 確かめの画面から入力の画面へ戻ってお届け先を直す時に、生年月日を入れ直させない
     */
    public static function known(int $remembered, ?AgeAnswer $previous): int
    {
        return max($remembered, $previous->level ?? 0);
    }

    /**
     * 送られた欄から答えを作る。足りなければ ValidationException（age.birthdate・age.consent・age.recipient）。
     * $previous はこのセッションの前の答え（生年月日で確かめた年齢を引き継ぐ。セッションはサーバーの中なので書き換えられない）
     *
     * @throws ValidationException
     */
    public function answer(mixed $raw, int $required, int $remembered, bool $gift, ?AgeAnswer $previous = null): AgeAnswer
    {
        $raw = is_array($raw) ? $raw : [];
        $errors = [];
        $level = ($previous->level ?? 0) >= $required ? (int) $previous?->level : 0;

        // 会員が前にこの年齢以上を確かめた・このセッションで前に確かめたなら、生年月日を聞かない（送られても使わない）
        if (self::known($remembered, $previous) < $required) {
            $today = AgeRule::today();
            $birthdate = $raw['birthdate'] ?? null;
            $problem = AgeRule::birthdateProblem($birthdate, $today);

            if ($problem === null) {
                $years = AgeRule::yearsOn(trim((string) $birthdate), $today);
                $level = AgeRule::levelFor($years);

                if ($years < $required) {
                    $problem = "{$required} 歳未満の方は、この商品をお求めいただけません。";
                }
            }

            // 生年月日はここで捨てる（数えた年齢のうち、選べる年齢のいちばん上だけを残す）
            unset($birthdate, $raw['birthdate']);

            if ($problem !== null) {
                $errors['age.birthdate'] = $problem;
            }
        }

        $consent = filter_var($raw['consent'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $recipient = filter_var($raw['recipient'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $consent) {
            $errors['age.consent'] = "「{$required} 歳以上です」を確認して、チェックを入れてください。";
        }

        if ($gift && ! $recipient) {
            $errors['age.recipient'] = "贈り物を受け取る方も {$required} 歳以上であることを確認して、チェックを入れてください。";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new AgeAnswer($required, $level, true, $gift && $recipient);
    }

    /**
     * 注文の入力の画面の欄（年齢の要る品が無い・App が落ちた時は null。落ちた時は送った時と確定で断る）
     *
     * @param  array<string, mixed>  $saved  セッションに置いた入力
     * @return array{age: int, remembered: int}|null
     */
    public function forForm(Request $request, CartView $cart, array $saved): ?array
    {
        try {
            $need = $this->requirement($cart, self::memberId($request));
        } catch (AgeCheckRefused) {
            return null;
        }

        return $need['age'] === 0 ? null : ['age' => $need['age'], 'remembered' => self::known($need['remembered'], AgeAnswer::fromArray($saved['age'] ?? null))];
    }

    /**
     * 送った入力に答えを入れる（年齢の要る品が無ければ外す）。足りなければ ValidationException、App が落ちたら AgeCheckRefused
     *
     * @param  mixed  $raw  送られた age の欄（生年月日・同意）
     *
     * @throws ValidationException
     * @throws AgeCheckRefused
     */
    public function apply(Request $request, CheckoutInput $input, CartView $cart, mixed $raw, ?AgeAnswer $previous): CheckoutInput
    {
        $need = $this->requirement($cart, self::memberId($request));

        return $input->withAge($need['age'] === 0 ? null : $this->answer($raw, $need['age'], $need['remembered'], $input->gift !== null, $previous));
    }

    /**
     * 確かめの画面の前に: 入力の後にカートへ年齢の要る品を足した・年齢が上がった・贈り物にしたなら、入力の画面で確かめ直してもらう文（よければ null）
     */
    public function problem(Request $request, CheckoutInput $input, CartView $cart): ?string
    {
        try {
            $need = $this->requirement($cart, self::memberId($request));
        } catch (AgeCheckRefused $e) {
            return $e->getMessage();
        }

        if ($need['age'] === 0 || ($input->age !== null && $input->age->satisfies($need['age'], $need['remembered'], $input->gift !== null))) {
            return null;
        }

        return "{$need['age']} 歳以上の方だけがお求めいただける商品があります。生年月日を入力し、「{$need['age']} 歳以上です」にチェックを入れてください。";
    }

    /**
     * ログインした、この店の会員の台帳の ID（会員が確かめた年齢はログインした本人にだけ使う。メールが同じゲストには使わない）
     */
    public static function memberId(Request $request): ?string
    {
        $member = $request->user('customer');

        return $member instanceof Member && $member->belongsHere() ? $member->account->id : null;
    }
}
