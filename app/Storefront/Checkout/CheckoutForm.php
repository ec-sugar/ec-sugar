<?php

declare(strict_types=1);

namespace App\Storefront\Checkout;

use App\Core\Plugin\OrderCharge\ChargeField;
use App\Core\Plugin\OrderCharge\ChargeForm;
use App\Storefront\Postal\Prefectures;
use Illuminate\Support\ViewErrorBag;

/**
 * 注文の入力の画面に渡す形（テーマ API の一部）。値は「入れ直した値 → セッションに置いた入力（会員なら、いつもの届け先）→ 空」の順に出す。
 */
final readonly class CheckoutForm
{
    /**
     * @param  array<string, mixed>  $old  直前に送った値（誤りで戻ったとき）
     * @param  array<string, mixed>  $saved  セッションに置いた入力（確認の画面から戻ったとき）。会員なら、いつもの届け先と選んだ届け先
     * @param  list<array{id: string, label: string, url: string, selected: bool}>  $addresses  会員の登録した届け先
     * @param  bool  $giftAvailable  贈り物として送れる店か（贈り物の App を使っている。ADR 0055）
     * @param  ?DeliveryOptions  $delivery  お届け日時の選択肢（店が受けていなければ null）
     * @param  array{label: string, note: string}|null  $optInOffer  「お知らせのメールを受け取る」の文字と一言（App を使う店だけ）
     * @param  list<array{id: string, name: string, description: string|null, type: string, options: list<string>, required: bool, maxLength: int|null}>  $orderFields  店が足した注文の項目（ADR 0066。使う欄を並びの順に）
     * @param  array{balance: int, expiresAt: string|null}|null  $pointsOffer  ポイントを使える会員の残高と期限（ADR 0068。使えなければ null）
     * @param  ?PaymentOffer  $paymentOffer  支払いの選び方（ADR 0074。銀行振込・代引きを使わない店では選び方を出さない）
     * @param  array{last4: string, balance: int, expiresAt: string}|null  $giftCardInUse  入れて使っているギフトカード（ADR 0076。無ければ null）
     * @param  bool  $giftCardOffered  ギフトカードの欄を出すか（店が 1 枚でも発行していれば。App をやめた後も、残ったカードは使える）
     * @param  array{age: int, remembered: int}|null  $ageCheck  年齢の確かめ（ADR 0077。年齢の要る品のあるカートだけ。会員が前に確かめた年齢つき）
     * @param  ?ChargeForm  $chargeForm  App の料金の欄（ラッピング・のしなど。ADR 0078。使わない店・選べるものが無ければ null）
     */
    public function __construct(
        private array $old,
        private array $saved,
        private ViewErrorBag $errors,
        private array $addresses = [],
        private bool $giftAvailable = false,
        private ?DeliveryOptions $delivery = null,
        private ?array $optInOffer = null,
        private array $orderFields = [],
        private ?array $pointsOffer = null,
        private ?PaymentOffer $paymentOffer = null,
        private ?array $giftCardInUse = null,
        private bool $giftCardOffered = false,
        private ?array $ageCheck = null,
        private ?ChargeForm $chargeForm = null,
    ) {}

    /**
     * App の料金の欄（ラッピング・のしなど。ADR 0078）。核の部品 <x-storefront::charge-fields> が見る。使わない店・選べるものが無ければ null（何も出さない）。
     * 欄ごとに、フォームの名前（inputName）・欄の id（inputId）・説明か誤りの id（hintId）・入れた値（value）・誤り（error）を添える。
     * 選択肢は 値 => 画面の名前（値が空の選択肢は「選ばない」）。名前と説明は App が店の設定から作った文で、文字のまま出す
     *
     * @return array{title: string, note: string|null, error: string|null, fields: list<array{key: string, type: string, label: string, help: string|null, options: array<string, string>, maxLength: int, inputName: string, inputId: string, hintId: string, value: string, error: string|null}>}|null
     */
    public function charges(): ?array
    {
        if ($this->chargeForm === null) {
            return null;
        }

        return [
            'title' => $this->chargeForm->title,
            'note' => $this->chargeForm->note,
            'error' => $this->error('charges._form'),
            'fields' => array_map(function (ChargeField $field): array {
                $key = 'charges.'.$field->key;

                return ['key' => $field->key, 'type' => $field->type, 'label' => $field->label, 'help' => $field->help, 'options' => $field->options, 'maxLength' => $field->maxLength,
                    'inputName' => $this->name($key), 'inputId' => $this->id($key), 'hintId' => $this->id($key).'-hint', 'value' => $this->value($key), 'error' => $this->error($key)];
            }, $this->chargeForm->fields),
        ];
    }

    /**
     * 年齢の確かめの欄（ADR 0077）。核の部品 <x-storefront::age-check> が見る。年齢の要る品が無ければ null（何も出さない）。
     * 同意の欄は、入れ直した値 → セッションの答えの順に選んだ形で出す。生年月日は戻さない
     */
    public function ageOffer(): ?AgeOffer
    {
        if ($this->ageCheck === null) {
            return null;
        }

        $age = $this->ageCheck['age'];
        $retry = array_key_exists('buyer', $this->old);
        $saved = is_array($this->saved['age'] ?? null) ? $this->saved['age'] : [];
        $errors = [];
        foreach (['birthdate', 'consent', 'recipient'] as $key) {
            $message = $this->error("age.{$key}");
            if ($message !== null) {
                $errors[$key] = $message;
            }
        }

        return new AgeOffer($age, $this->ageCheck['remembered'] < $age, $this->giftAvailable,
            $retry ? filter_var(data_get($this->old, 'age.consent', false), FILTER_VALIDATE_BOOLEAN) : ($saved['consent'] ?? false) === true && ($saved['age'] ?? 0) >= $age,
            $retry ? filter_var(data_get($this->old, 'age.recipient', false), FILTER_VALIDATE_BOOLEAN) : ($saved['recipient'] ?? false) === true && ($saved['age'] ?? 0) >= $age,
            $errors);
    }

    /**
     * 支払いの選び方（ADR 0074）。核の部品 <x-storefront::payment-choice> が見る。銀行振込・代引きを使わない店は null（何も出さない）
     */
    public function paymentOffer(): ?PaymentOffer
    {
        return $this->paymentOffer?->hasChoice() === true ? $this->paymentOffer : null;
    }

    /**
     * 選び方に出さなかった手段の添え書き（店が銀行振込・代金引換を会員だけにした時のゲスト。ADR 0085）。無ければ null
     */
    public function paymentNote(): ?string
    {
        return $this->paymentOffer?->note;
    }

    /**
     * 選んでいる支払いの選び方。入れ直した値 → セッションに置いた入力 → 選べる最初の手段
     */
    public function payment(): string
    {
        $value = array_key_exists('buyer', $this->old) ? data_get($this->old, 'payment') : data_get($this->saved, 'payment');
        $value = is_string($value) ? $value : CheckoutInput::PAY_STRIPE;

        return $this->paymentOffer?->resolve($value) ?? $value;
    }

    /**
     * 支払いの選び方の誤り（選べない手段を選んだ。無ければ null）
     */
    public function paymentError(): ?string
    {
        return $this->error('payment');
    }

    /**
     * ギフトカードの欄を出すか（ADR 0076）。核の部品 <x-storefront::gift-card-fields> が見る
     */
    public function giftCardOffered(): bool
    {
        return $this->giftCardOffered || $this->giftCardInUse !== null;
    }

    /**
     * 入れて使っているギフトカード（末尾 4 文字・残高・期限。無ければ null）。コードそのものは画面に出さない
     *
     * @return array{last4: string, balance: int, expiresAt: string}|null
     */
    public function giftCardInUse(): ?array
    {
        return $this->giftCardInUse;
    }

    /**
     * ギフトカードの欄の誤り（見つからない・使えない・回数を超えた。無ければ null）
     */
    public function giftCardError(): ?string
    {
        return $this->error('gift_card.code');
    }

    /**
     * ポイントを使える会員の残高と期限（ADR 0068）。核の部品 <x-storefront::points-fields> が見る。ゲスト・使わない店・残高 0 は null（欄を出さない）
     *
     * @return array{balance: int, expiresAt: string|null}|null
     */
    public function pointsOffer(): ?array
    {
        return $this->pointsOffer;
    }

    /**
     * ポイントの欄の誤り（数が残高を超えた・形が違う。無ければ null）
     */
    public function pointsError(): ?string
    {
        return $this->error('points.amount') ?? $this->error('points.mode');
    }

    /**
     * 選んでいる使い方（none・all・some）。入れ直した値 → セッションに置いた入力の順。最初は使わない
     */
    public function pointsMode(): string
    {
        $mode = array_key_exists('buyer', $this->old) ? data_get($this->old, 'points.mode') : data_get($this->saved, 'points.mode');

        return in_array($mode, ['all', 'some'], true) ? $mode : 'none';
    }

    /**
     * 店が足した注文の項目（ADR 0066。のしの有無・用途・ご要望など）。核の部品 <x-storefront::order-fields> が見る。無ければ空（何も出さない）。
     * 欄ごとに、フォームの名前（inputName）・欄の id（inputId）・説明か誤りの id（hintId）・入れた値（value。チェックは '1' か '0'）・誤り（error）を添える
     *
     * @return list<array{id: string, name: string, description: string|null, type: string, options: list<string>, required: bool, maxLength: int|null, inputName: string, inputId: string, hintId: string, value: string, error: string|null}>
     */
    public function orderFields(): array
    {
        return array_map(function (array $field): array {
            $key = 'fields.'.$field['id'];

            return [...$field, 'inputName' => $this->name($key), 'inputId' => $this->id($key), 'hintId' => $this->id($key).'-hint', 'value' => $this->value($key), 'error' => $this->error($key)];
        }, $this->orderFields);
    }

    /**
     * お届け日時の選択肢（ADR 0059）。核の部品 <x-storefront::delivery-fields> が見る。店が受けていなければ null（欄を出さない）
     */
    public function delivery(): ?DeliveryOptions
    {
        return $this->delivery;
    }

    /**
     * 「お知らせのメールを受け取る」の文字と一言。核の部品 <x-storefront::opt-in> が見る。使っていない店では null で、何も出さない
     *
     * @return array{label: string, note: string}|null
     */
    public function optInOffer(): ?array
    {
        return $this->optInOffer;
    }

    /**
     * 「お知らせのメールを受け取る」を選んでいるか。最初は選んでいない（同意は本人が選ぶ）。入れ直した値 → セッションに置いた入力の順
     */
    public function optIn(): bool
    {
        if (array_key_exists('buyer', $this->old)) {
            return filter_var($this->old['opt_in'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        return ($this->saved['opt_in'] ?? false) === true;
    }

    /**
     * 贈り物として送れるか（ADR 0055）。核の部品 <x-storefront::gift-fields> が見る。使っていない店では何も出さない
     */
    public function giftAvailable(): bool
    {
        return $this->giftAvailable;
    }

    /**
     * 「贈り物として送る」を選んでいるか（入れ直した値 → セッションに置いた入力の順）
     */
    public function gift(): bool
    {
        if (array_key_exists('buyer', $this->old)) {
            return filter_var(data_get($this->old, 'gift.on', false), FILTER_VALIDATE_BOOLEAN);
        }

        return is_array($this->saved['gift'] ?? null);
    }

    /**
     * 会員の登録した届け先（ADR 0044 の 3）。選ぶとお届け先に入る。会員でなければ空。核の部品 <x-storefront::saved-addresses> が出す
     *
     * @return list<array{id: string, label: string, url: string, selected: bool}>
     */
    public function addresses(): array
    {
        return $this->addresses;
    }

    /**
     * @param  string  $key  buyer.name のように
     */
    public function value(string $key): string
    {
        $value = data_get($this->old, $key) ?? data_get($this->saved, $key);

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        // セッションに置いた郵便番号は数字だけなので、見やすい形に
        return '';
    }

    public function sameAsBuyer(): bool
    {
        if (array_key_exists('buyer', $this->old)) {
            return filter_var($this->old['same_as_buyer'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        return ($this->saved['recipient'] ?? null) === null;
    }

    /**
     * 入力欄の id（buyer.name → buyer-name）
     */
    public function id(string $key): string
    {
        return str_replace('.', '-', $key);
    }

    /**
     * フォームの名前（buyer.name → buyer[name]）
     */
    public function name(string $key): string
    {
        $parts = explode('.', $key);

        return array_shift($parts).implode('', array_map(static fn (string $p): string => "[{$p}]", $parts));
    }

    public function error(string $key): ?string
    {
        $message = $this->errors->first($key);

        return $message === '' ? null : $message;
    }

    public function hasErrors(): bool
    {
        return $this->errors->any();
    }

    /**
     * @return list<string>
     */
    public function prefectures(): array
    {
        return Prefectures::ALL;
    }
}
