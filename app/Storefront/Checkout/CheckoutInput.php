<?php

declare(strict_types=1);

namespace App\Storefront\Checkout;

use App\Core\Fulfillment\DeliveryChoice;
use App\Core\Fulfillment\Enums\DeliverySlot;
use App\Core\Order\Services\OrderFields;
use App\Core\Plugin\AgeCheck\AgeAnswer;
use App\Core\Plugin\Gift\GiftNote;
use App\Core\Shop\Shipping\ShippingDestination;
use App\Storefront\Postal\PostalPrefectures;
use App\Storefront\Postal\Prefectures;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 注文の入力: ご依頼主とお届け先。検査して形をそろえる（電話と郵便番号は数字だけに）。
 * 確定するまではサーバーのセッション（暗号化して保存）に置き、DB には書かない。確定のときに暗号化した列に入れる（ADR 0010）。
 * 贈り物（ADR 0055）を選ぶと、お届け先の代わりに差出人の表示名（空ならご依頼主のお名前）とメッセージを持つ（お届け先は受け取る人が後で入れる）。
 * 贈り物を選べる店か（App を使っているか）は、受け口が確かめる。
 * お届けの日と時間帯の希望（ADR 0059）はここでは形だけを確かめ、選べる範囲かは受け口と確定が店の決まりで確かめる。贈り物では持たない
 * 「お知らせのメールを受け取る」は、選んだ時だけ真（opt_in。選べる店かは受け口が確かめ、選べなければ偽にする）
 * 注文の項目（ADR 0066）はここでは形だけ（欄の ID => 文字）をそろえ、必須・長さ・選択肢の中かは受け口と確定が店の決まりで確かめる（OrderFields::check）。贈り物でも聞く
 * ポイントを使う（ADR 0068）は形だけ（使わない・全部・数を入れる）。会員か・残高を超えないかは受け口が、使える数は確かめの画面と確定がカートの計算で決める
 * 支払いの選び方（ADR 0074）は形だけ（stripe〔カード・コンビニ・PayPay は決済代行の画面で選ぶ〕・bank_transfer・cod）。店が使うか・この注文で選べるかは PaymentOptions が決める
 * ギフトカード（ADR 0076）はカードの ID だけを持つ（コードそのものはセッションにも置かない）。コードからカードを探して ID を入れるのは受け口（回数の上限つき）
 * 年齢の確かめ（ADR 0077）は答え（AgeAnswer）だけを持つ。生年月日は受け口が満年齢を数えて捨て、ここにもセッションにも置かない
 * App の料金の欄（ラッピング・のしなど。ADR 0078）は形だけ（欄の鍵 => 文字）。選べるか・値段は App が店の設定で決める（OrderCharges::quote）。贈り物でも聞く
 */
final readonly class CheckoutInput
{
    public const array ADDRESS_FIELDS = ['name', 'phone', 'postal', 'prefecture', 'city', 'line1', 'line2'];

    private const array NAMES = ['name' => 'お名前', 'email' => 'メールアドレス', 'phone' => '電話番号', 'postal' => '郵便番号', 'prefecture' => '都道府県', 'city' => '市区町村', 'line1' => '番地', 'line2' => '建物名・部屋番号'];

    private const array MESSAGES = ['phone.regex' => ':attribute は 0 で始まる電話番号を入力してください（ハイフンは無くてもかまいません）。', 'regex' => ':attribute の形式が正しくありません。もう一度確認してください。'];

    /**
     * @param  array{name: string, email: string, phone: string, postal: string, prefecture: string, city: string, line1: string, line2: string|null}  $buyer
     * @param  array{name: string, phone: string, postal: string, prefecture: string, city: string, line1: string, line2: string|null}|null  $recipient  null ならご依頼主と同じ（贈り物なら使わない）
     * @param  ?GiftNote  $gift  贈り物として送る（お届け先は受け取る人が入れる）
     * @param  ?DeliveryChoice  $delivery  お届けの日と時間帯の希望（指定なしは null）
     * @param  bool  $optIn  お知らせのメールを受け取る（本人が選んだ時だけ真）
     * @param  array<string, string>  $fields  注文の項目の値（欄の ID => 値。確かめた後は OrderFields::check の答え）
     * @param  'none'|'all'|'some'  $pointsMode  ポイントを使わない・全部使う・数を入れて使う（ADR 0068）
     * @param  int  $pointsAmount  数を入れて使う時の数
     * @param  string  $payment  支払いの選び方（stripe・bank_transfer・cod）
     * @param  ?string  $giftCardId  使うギフトカード（ADR 0076。受け口がコードから探した ID）
     * @param  ?AgeAnswer  $age  年齢の確かめの答え（ADR 0077。年齢の要る品のある時だけ。生年月日は持たない）
     * @param  array<string, string>  $charges  App の料金の欄の値（ADR 0078。欄の鍵 => 文字）
     */
    public function __construct(
        public array $buyer,
        public ?array $recipient,
        public ?GiftNote $gift = null,
        public ?DeliveryChoice $delivery = null,
        public bool $optIn = false,
        public array $fields = [],
        public string $pointsMode = 'none',
        public int $pointsAmount = 0,
        public string $payment = self::PAY_STRIPE,
        public ?string $giftCardId = null,
        public ?AgeAnswer $age = null,
        public array $charges = [],
    ) {}

    /** App の料金の欄の数と、1 つの値の長さの上限（入力の形の守り。中身は App が確かめる） */
    public const int CHARGES_MAX = 8;

    public const int CHARGE_VALUE_MAX = 200;

    /** 支払いの選び方: 決済代行の画面で選ぶ（カード・コンビニ払い・PayPay） */
    public const string PAY_STRIPE = 'stripe';

    public const array PAYMENTS = [self::PAY_STRIPE, 'bank_transfer', 'cod'];

    /**
     * 支払いの選び方を替えた写し（選べない時に外す）
     */
    public function withPayment(string $payment): self
    {
        return new self($this->buyer, $this->recipient, $this->gift, $this->delivery, $this->optIn, $this->fields, $this->pointsMode, $this->pointsAmount, $payment, $this->giftCardId, $this->age, $this->charges);
    }

    /** 1 回に使えるポイントの数の上限（入力の形の守り。実際は残高と合計まで） */
    public const int MAX_POINTS = 100_000_000;

    /**
     * 使いたいポイント（カートの計算に渡す。全部なら PHP_INT_MAX。使わないなら 0）
     */
    public function pointsRequested(): int
    {
        return match ($this->pointsMode) {
            'all' => PHP_INT_MAX,
            'some' => $this->pointsAmount,
            default => 0,
        };
    }

    /**
     * ポイントを使わない入力（ゲスト・使わない店で送られてきた時）
     */
    public function withoutPoints(): self
    {
        return new self($this->buyer, $this->recipient, $this->gift, $this->delivery, $this->optIn, $this->fields, 'none', 0, $this->payment, $this->giftCardId, $this->age, $this->charges);
    }

    /**
     * お届けの希望を替えた写し（店が指定を受けていない時に外す）
     */
    public function withDelivery(?DeliveryChoice $delivery): self
    {
        return new self($this->buyer, $this->recipient, $this->gift, $delivery, $this->optIn, $this->fields, $this->pointsMode, $this->pointsAmount, $this->payment, $this->giftCardId, $this->age, $this->charges);
    }

    /**
     * 注文の項目の値を替えた写し（店の決まりで確かめて形をそろえた答えに。ADR 0066）
     *
     * @param  array<string, string>  $fields
     */
    public function withFields(array $fields): self
    {
        return new self($this->buyer, $this->recipient, $this->gift, $this->delivery, $this->optIn, $fields, $this->pointsMode, $this->pointsAmount, $this->payment, $this->giftCardId, $this->age, $this->charges);
    }

    /**
     * 年齢の確かめの答えを替えた写し（ADR 0077。null は答えていない）
     */
    public function withAge(?AgeAnswer $age): self
    {
        return new self($this->buyer, $this->recipient, $this->gift, $this->delivery, $this->optIn, $this->fields, $this->pointsMode, $this->pointsAmount, $this->payment, $this->giftCardId, $age, $this->charges);
    }

    /**
     * 送る品の無い注文（送らない品〔ダウンロードなど〕だけ）の写し: お届け先はご依頼主と同じにし、贈り物とお届けの希望を外す（どれも送る品のためのもの）。
     * 年齢の確かめの答え（ADR 0077）と App の料金の欄の値（ADR 0078）はお届け先と関わらないので持ち越す
     */
    public function withoutShipping(): self
    {
        return new self($this->buyer, null, null, null, $this->optIn, $this->fields, $this->pointsMode, $this->pointsAmount, $this->payment, $this->giftCardId, $this->age, $this->charges);
    }

    /**
     * 使うお届けの希望（贈り物では持たない。ADR 0059 の 7）
     */
    public function deliveryChoice(): ?DeliveryChoice
    {
        return $this->gift === null ? $this->delivery : null;
    }

    /**
     * お届け日を選んだか（時間帯だけの指定は数えない）。お届け日を選んだ注文には後から払う手段を出さない（ADR 0058・0059）
     */
    public function datedDelivery(): bool
    {
        return $this->deliveryChoice()?->date !== null;
    }

    /**
     * 「お知らせのメールを受け取る」を外した入力（選べない店で選ばれていた時）
     */
    public function withoutOptIn(): self
    {
        return new self($this->buyer, $this->recipient, $this->gift, $this->delivery, false, $this->fields, $this->pointsMode, $this->pointsAmount, $this->payment, $this->giftCardId, $this->age, $this->charges);
    }

    /**
     * App の料金の欄の値を替えた写し（ADR 0078。使わない店・選べない時に外す）
     *
     * @param  array<string, string>  $charges
     */
    public function withCharges(array $charges): self
    {
        return new self($this->buyer, $this->recipient, $this->gift, $this->delivery, $this->optIn, $this->fields, $this->pointsMode, $this->pointsAmount, $this->payment, $this->giftCardId, $this->age, $charges);
    }

    /**
     * 使うギフトカードを替えた写し（ADR 0076。null は使わない）
     */
    public function withGiftCard(?string $giftCardId): self
    {
        return new self($this->buyer, $this->recipient, $this->gift, $this->delivery, $this->optIn, $this->fields, $this->pointsMode, $this->pointsAmount, $this->payment,
            $giftCardId !== null && preg_match('/\A[0-9A-Za-z]{26}\z/', $giftCardId) === 1 ? $giftCardId : null, $this->age, $this->charges);
    }

    /**
     * 形が違えば ValidationException（項目の名前は buyer.name など、フォームの名前と同じ）。
     * 郵便番号と都道府県の照らし合わせ（ADR 0064 の作って決めたこと）は、お客さんが入れた時だけ（$checkPostal）。
     * セッション・定期便の届け先から戻す時（fromArray）は照らさない（入れた時に照らし済み・前に保存した届け先で定期便の回を止めない）
     *
     * @param  array<string, mixed>  $input
     */
    public static function validate(array $input, bool $checkPostal = true): self
    {
        $gift = filter_var(data_get($input, 'gift.on', false), FILTER_VALIDATE_BOOLEAN);
        // 選んだ時だけ（1・true・on）。無い・ほかの値は選んでいない
        $optIn = filter_var($input['opt_in'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $same = $gift || filter_var($input['same_as_buyer'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $fields = self::fieldValues($input['fields'] ?? null);
        $charges = self::chargeValues($input['charges'] ?? null);
        $rules = ['buyer' => ['required', 'array'], ...self::addressRules('buyer'), 'buyer.email' => ['required', 'string', 'max:254', 'email:rfc'],
            // ポイント（ADR 0068）: 使わない・全部・数を入れる。数は「数を入れる」の時だけ
            'points' => ['nullable', 'array'],
            'points.mode' => ['nullable', 'string', 'in:none,all,some'],
            'points.amount' => ['exclude_unless:points.mode,some', 'required', 'integer', 'min:1', 'max:'.self::MAX_POINTS],
            // 支払いの選び方（ADR 0074）。無ければ決済代行の画面で選ぶ
            'payment' => ['nullable', 'string', 'in:'.implode(',', self::PAYMENTS)],
        ];

        if (! $same) {
            $rules = [...$rules, 'recipient' => ['required', 'array'], ...self::addressRules('recipient')];
        }

        if ($gift) {
            // 改行をそろえて前後の空白を取ってから確かめる（長さは見える文字で数える）
            foreach (['sender', 'message'] as $key) {
                $value = data_get($input, "gift.{$key}");
                data_set($input, "gift.{$key}", is_string($value) ? GiftNote::normalize($value) : null);
            }

            $rules = [...$rules,
                'gift' => ['required', 'array'],
                'gift.sender' => ['nullable', 'string', self::giftRule(true)],
                'gift.message' => ['nullable', 'string', self::giftRule(false)],
            ];
        }

        // お届けの希望: 形だけ（暦にある日・知っている時間帯）。贈り物では受けない（住所も届く日も受け取る人しだい）
        if (! $gift) {
            $rules = [...$rules,
                'delivery' => ['nullable', 'array'],
                'delivery.date' => ['nullable', 'string', 'date_format:Y-m-d'],
                'delivery.slot' => ['nullable', 'string', Rule::enum(DeliverySlot::class)],
            ];
        }

        $attributes = ['gift.sender' => '差出人のお名前', 'gift.message' => 'メッセージ', 'delivery.date' => 'お届け日', 'delivery.slot' => 'お届けの時間帯', 'points.mode' => 'ポイント', 'points.amount' => '使うポイント', 'payment' => 'お支払い方法'];
        foreach (self::NAMES as $key => $label) {
            $attributes["buyer.{$key}"] = $label;
            $attributes["recipient.{$key}"] = "お届け先の{$label}";
        }

        $validator = Validator::make($input, $rules, self::MESSAGES, $attributes);

        if ($checkPostal) {
            $validator->after(self::postalMatches($same ? ['buyer'] : ['buyer', 'recipient']));
        }

        $data = $validator->validate();
        $buyer = self::clean($data['buyer'], true);
        $pointsMode = (string) ($data['points']['mode'] ?? 'none');
        $pointsMode = in_array($pointsMode, ['all', 'some'], true) ? $pointsMode : 'none';
        $pointsAmount = $pointsMode === 'some' ? (int) $data['points']['amount'] : 0;
        $payment = is_string($data['payment'] ?? null) ? $data['payment'] : self::PAY_STRIPE;

        if ($gift) {
            // 差出人の表示名の既定はご依頼主のお名前（長ければ表示名の上限で切る）
            $sender = (string) ($data['gift']['sender'] ?? '');
            $sender = $sender === '' ? mb_substr((string) $buyer['name'], 0, GiftNote::SENDER_MAX) : $sender;
            $problem = GiftNote::problem($sender, true);

            if ($problem !== null) {
                throw ValidationException::withMessages(['gift.sender' => $problem]);
            }

            return new self($buyer, null, new GiftNote($sender, (string) ($data['gift']['message'] ?? '')), null, $optIn, $fields, $pointsMode, $pointsAmount, $payment, charges: $charges);
        }

        return new self($buyer, $same ? null : self::clean($data['recipient'], false), null, DeliveryChoice::of($data['delivery']['date'] ?? null, $data['delivery']['slot'] ?? null), $optIn, $fields, $pointsMode, $pointsAmount, $payment, charges: $charges);
    }

    /**
     * 会員の届け先（ADR 0044 の 3）。注文の入力のお届け先と同じ決まりで確かめ、同じ形にそろえる。形が違えば ValidationException（項目の名前は address.name など）
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|null>
     */
    public static function address(array $input): array
    {
        $attributes = [];
        foreach (self::NAMES as $key => $label) {
            $attributes["address.{$key}"] = $label;
        }

        $data = Validator::make($input, ['address' => ['required', 'array'], ...self::addressRules('address')], self::MESSAGES, $attributes)
            ->after(self::postalMatches(['address']))->validate();

        return self::clean($data['address'], false);
    }

    /**
     * @param  array<string, mixed>  $data  セッションから戻すとき
     */
    public static function fromArray(array $data): ?self
    {
        try {
            $gift = $data['gift'] ?? null;
            $giftCard = $data['gift_card_id'] ?? null;

            return self::validate(checkPostal: false, input: [
                'buyer' => $data['buyer'] ?? null,
                'recipient' => $data['recipient'] ?? null,
                'same_as_buyer' => ($data['recipient'] ?? null) === null,
                'gift' => is_array($gift) ? ['on' => '1', 'sender' => $gift['sender'] ?? null, 'message' => $gift['message'] ?? null] : null,
                'delivery' => is_array($data['delivery'] ?? null) ? $data['delivery'] : null,
                'opt_in' => ($data['opt_in'] ?? false) === true,
                'fields' => $data['fields'] ?? null,
                'points' => is_array($data['points'] ?? null) ? $data['points'] : null,
                'payment' => is_string($data['payment'] ?? null) ? $data['payment'] : null,
                'charges' => $data['charges'] ?? null,
            ])->withGiftCard(is_string($giftCard) ? $giftCard : null)->withAge(AgeAnswer::fromArray($data['age'] ?? null));
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * お届け先（ご依頼主と同じなら、ご依頼主の住所）
     *
     * @return array{name: string, phone: string, postal: string, prefecture: string, city: string, line1: string, line2: string|null}
     */
    public function shipTo(): array
    {
        if ($this->recipient !== null) {
            return $this->recipient;
        }

        $buyer = $this->buyer;
        unset($buyer['email']);

        return $buyer;
    }

    /**
     * 送料を決めるお届け先（ADR 0064）: 贈り物はいちばん高い額（お届け先は受け取る人が後で入れる。ADR 0055 の 7）、ほかはお届け先の都道府県
     */
    public function destination(): ShippingDestination
    {
        return $this->gift !== null ? ShippingDestination::highest() : ShippingDestination::prefecture((string) $this->shipTo()['prefecture']);
    }

    /**
     * @return array{buyer: array<string, string|null>, recipient: array<string, string|null>|null, gift: array{sender: string, message: string}|null, delivery: array{date: string|null, slot: string|null}|null, opt_in: bool, fields: array<string, string>, points: array{mode: string, amount: int|null}, payment: string, gift_card_id: string|null, age: array{age: int, level: int, consent: bool, recipient: bool, version: string}|null, charges: array<string, string>}
     */
    public function toArray(): array
    {
        return ['buyer' => $this->buyer, 'recipient' => $this->recipient, 'gift' => $this->gift === null ? null : ['sender' => $this->gift->sender, 'message' => $this->gift->message], 'delivery' => $this->delivery?->toArray(), 'opt_in' => $this->optIn, 'fields' => $this->fields,
            'points' => ['mode' => $this->pointsMode, 'amount' => $this->pointsMode === 'some' ? $this->pointsAmount : null], 'payment' => $this->payment, 'gift_card_id' => $this->giftCardId, 'age' => $this->age?->toArray(), 'charges' => $this->charges];
    }

    public static function postalLabel(string $postal): string
    {
        return substr($postal, 0, 3).'-'.substr($postal, 3);
    }

    /**
     * 注文の項目の値の形だけをそろえる（ADR 0066）: 欄の ID（ULID）=> 文字だけを残し、ほかの形（入れ子の配列・数の鍵）は捨てる。数は欄の上限まで。
     * 中身（必須・長さ・選択肢の中か・使う欄か）は店の決まりで OrderFields::check が確かめる
     *
     * @return array<string, string>
     */
    private static function fieldValues(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $id => $value) {
            if (is_string($id) && preg_match('/\A[0-9A-Za-z]{26}\z/', $id) === 1 && is_string($value) && count($out) < OrderFields::MAX_TOTAL) {
                $out[strtolower($id)] = $value;
            }
        }

        return $out;
    }

    /**
     * App の料金の欄の値の形だけをそろえる（ADR 0078）: 欄の鍵（英小文字・数字・下線）=> 文字だけを残し、長すぎる値・入れ子は捨てる。数は上限まで。
     * 改行を \n にそろえて前後の空白を取る。中身（選べるか・名入れの長さと文字）は App が確かめる
     *
     * @return array<string, string>
     */
    private static function chargeValues(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $key => $value) {
            if (is_string($key) && preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $key) === 1 && is_string($value) && mb_check_encoding($value, 'UTF-8')
                && mb_strlen($value) <= self::CHARGE_VALUE_MAX && count($out) < self::CHARGES_MAX) {
                $out[$key] = GiftNote::normalize($value);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string|null>
     */
    private static function clean(array $values, bool $withEmail): array
    {
        $out = [];

        foreach ([...self::ADDRESS_FIELDS, ...($withEmail ? ['email'] : [])] as $key) {
            $value = $values[$key] ?? null;
            $value = is_string($value) ? trim($value) : null;
            $out[$key] = match ($key) {
                'phone', 'postal' => preg_replace('/\D/', '', (string) $value),
                'email' => mb_strtolower((string) $value),
                'line2' => $value === '' ? null : $value,
                default => (string) $value,
            };
        }

        return $out;
    }

    /**
     * 郵便番号の上 3 桁から引いた都道府県と、選んだ都道府県が合うか（ADR 0064 の作って決めたこと。PostalPrefectures の手元の表）。
     * 送料は都道府県で決まるので、合わない組み合わせ（別の県の安い送料）で注文できないように断る。形の誤りが先にあれば照らさない。照らせない番号は通す
     *
     * @param  list<string>  $prefixes  住所の欄の頭（buyer・recipient・address）
     * @return Closure(\Illuminate\Validation\Validator): void
     */
    private static function postalMatches(array $prefixes): Closure
    {
        return static function (\Illuminate\Validation\Validator $validator) use ($prefixes): void {
            foreach ($prefixes as $prefix) {
                if ($validator->errors()->hasAny(["{$prefix}.postal", "{$prefix}.prefecture"])) {
                    continue;
                }

                $postal = data_get($validator->getData(), "{$prefix}.postal");
                $prefecture = data_get($validator->getData(), "{$prefix}.prefecture");

                if (is_string($postal) && is_string($prefecture) && ! PostalPrefectures::matches($postal, $prefecture)) {
                    $validator->errors()->add("{$prefix}.postal", PostalPrefectures::MISMATCH);
                }
            }
        };
    }

    /**
     * 贈り物の差出人とメッセージの決まり（GiftNote と同じ。空は既定に任せる）
     */
    private static function giftRule(bool $sender): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($sender): void {
            $problem = is_string($value) && $value !== '' ? GiftNote::problem($value, $sender) : null;

            if ($problem !== null) {
                $fail($problem);
            }
        };
    }

    /**
     * @return array<string, list<string>>
     */
    private static function addressRules(string $prefix): array
    {
        $line = 'not_regex:/[\r\n\t]/';

        return [
            "{$prefix}.name" => ['required', 'string', 'max:60', $line],
            // 日本の電話番号は 10 桁（固定）か 11 桁（携帯など）。区切りのハイフンは任意
            "{$prefix}.phone" => ['required', 'string', 'regex:/\A(?=(?:-?\d){10,11}\z)0\d{1,4}-?\d{1,4}-?\d{3,4}\z/'],
            "{$prefix}.postal" => ['required', 'string', 'regex:/\A\d{3}-?\d{4}\z/'],
            "{$prefix}.prefecture" => ['required', 'string', 'in:'.implode(',', Prefectures::ALL)],
            "{$prefix}.city" => ['required', 'string', 'max:50', $line],
            "{$prefix}.line1" => ['required', 'string', 'max:100', $line],
            "{$prefix}.line2" => ['nullable', 'string', 'max:100', $line],
        ];
    }
}
