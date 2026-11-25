<?php

declare(strict_types=1);

namespace App\Core\Listing\Addons;

use App\Core\Crypto\TenantCipher;
use App\Core\Listing\Enums\AddonKind;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingAddon;
use App\Core\Tax\TaxClass;
use InvalidArgumentException;

/**
 * お客さんが選んだオプション（ADR 0036）。いつも今のオプションの定義と照らして作る（カートや画面から来た値を信じない）。
 *
 * - 足す額はサーバーで定義から計算する。入れた文字は改行・制御文字・文字の向きを変える印を断る
 * - 選ばなかった任意のオプションは並びに入れない。選び方の印（key）は順と中身から作り、同じ選び方なら同じ印（店の鍵つき）
 * - 入れた文字は個人情報になりうる。並びは暗号化して持ち（カートの行・取引の行）、記録とイベントとタグの App には渡さない
 */
final readonly class AddonSelection
{
    /**
     * @param  list<array{id: string, name: string, kind: string, value: string, amount: int, tax_class?: string|null}>  $items  value は選んだ選択肢・入れた文字・'yes'
     */
    private function __construct(public array $items) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * 画面から来た値（オプションの id → 選んだ選択肢の番号・入れた文字・'1'）を、その商品の今の定義と照らす。
     *
     * @param  array<mixed>  $input
     *
     * @throws InvalidArgumentException お客さんに見せてよい言葉で
     */
    public static function resolve(Listing $listing, array $input): self
    {
        $items = [];
        $addons = ListingAddon::query()->where('listing_id', $listing->id)->orderBy('position')->get();

        // この商品に無いオプションを送ってきたら断る（別の商品のオプションの額を混ぜられないように）
        foreach ($input as $id => $value) {
            if ($value !== null && $value !== '' && ! $addons->contains('id', (string) $id)) {
                throw new InvalidArgumentException('この商品に無いオプションが選ばれています。');
            }
        }

        foreach ($addons as $addon) {
            $raw = $input[$addon->id] ?? null;
            $given = is_string($raw) || is_int($raw) ? trim((string) $raw) : '';

            if ($given === '') {
                if ($addon->required) {
                    throw new InvalidArgumentException("「{$addon->name}」を選んでください。");
                }

                continue;
            }

            $items[] = match ($addon->kind) {
                AddonKind::Choice => self::choice($addon, $given),
                AddonKind::Text => self::text($addon, $given),
                AddonKind::Toggle => self::toggle($addon, $given),
            };
        }

        return new self($items);
    }

    /**
     * 保存した並び（暗号を解いたもの）から戻す。注文のときは resolve で今の定義と照らし直すこと
     *
     * @param  array<mixed>|null  $stored
     */
    public static function fromStored(?array $stored): self
    {
        $items = [];

        foreach ($stored ?? [] as $item) {
            if (is_array($item) && is_string($item['id'] ?? null) && is_string($item['name'] ?? null) && is_string($item['kind'] ?? null) && is_string($item['value'] ?? null) && is_int($item['amount'] ?? null)) {
                $items[] = ['id' => $item['id'], 'name' => $item['name'], 'kind' => $item['kind'], 'value' => $item['value'], 'amount' => $item['amount'],
                    'tax_class' => is_string($item['tax_class'] ?? null) && TaxClass::tryFrom($item['tax_class']) !== null ? $item['tax_class'] : null];
            }
        }

        return new self($items);
    }

    /**
     * 照らし直しに渡す形（オプションの id → 値。選ぶは選択肢の名前で持っているので番号に直す側で扱う）
     *
     * @return array<string, string>
     */
    public function asInput(): array
    {
        $input = [];

        foreach ($this->items as $item) {
            $input[$item['id']] = $item['kind'] === 'choice' ? 'label:'.$item['value'] : $item['value'];
        }

        return $input;
    }

    /**
     * 1 点あたりの足す額
     */
    public function amount(): int
    {
        return array_sum(array_column($this->items, 'amount'));
    }

    /**
     * 1 点あたりの足す額を税率ごとに（税区分の無いオプションは商品の率。軽減の商品に付ける別料金の包装は標準にできる）
     *
     * @return array<int, int> 率（basis point）→ 額
     */
    public function amountsByRate(int $productRateBp): array
    {
        $byRate = [];

        foreach ($this->items as $item) {
            $rate = self::rateOf($item, $productRateBp);
            $byRate[$rate] = ($byRate[$rate] ?? 0) + $item['amount'];
        }

        return $byRate;
    }

    /**
     * 1 つのオプションの率（税区分の無いものは商品の率）
     *
     * @param  array{tax_class?: string|null}  $item
     */
    public static function rateOf(array $item, int $productRateBp): int
    {
        $class = is_string($item['tax_class'] ?? null) ? TaxClass::tryFrom($item['tax_class']) : null;

        return $class?->rateBp() ?? $productRateBp;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * 同じ選び方かを見る印（店の鍵つき。入れた文字そのものは DB に平文で残さない）。選んでいなければ空の文字
     */
    public function key(): string
    {
        return $this->items === [] ? '' : app(TenantCipher::class)->index(json_encode(array_map(
            static fn (array $item): array => [$item['id'], $item['value']], $this->items), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * 画面に出す短い説明（「ギフト包装：あり、名入れ：入力あり」）。入れた文字は出さない（見せるのは注文の詳細だけ）
     */
    public function summary(): string
    {
        return implode('、', array_map(static fn (array $item): string => $item['name'].'：'.match ($item['kind']) {
            'choice' => $item['value'],
            'text' => '入力あり',
            default => 'あり',
        }, $this->items));
    }

    /**
     * @return array{id: string, name: string, kind: string, value: string, amount: int, tax_class: string|null}
     */
    private static function choice(ListingAddon $addon, string $given): array
    {
        $choices = $addon->choices ?? [];
        $picked = null;

        // 番号（画面のフォーム）か、名前（保存した選び方の照らし直し）で選ぶ
        if (str_starts_with($given, 'label:')) {
            $label = substr($given, 6);

            foreach ($choices as $choice) {
                if ($choice['label'] === $label) {
                    $picked = $choice;
                }
            }
        } elseif (ctype_digit($given) && isset($choices[(int) $given])) {
            $picked = $choices[(int) $given];
        }

        if ($picked === null) {
            throw new InvalidArgumentException("「{$addon->name}」の選択肢が見つかりません。選び直してください。");
        }

        return ['id' => $addon->id, 'name' => $addon->name, 'kind' => 'choice', 'value' => (string) $picked['label'], 'amount' => (int) $picked['amount'], 'tax_class' => $addon->tax_class];
    }

    /**
     * 付けるは '1'（保存した選び方の照らし直しは 'yes'）だけ。隠した欄の '0' などを「付けた」にしない
     *
     * @return array{id: string, name: string, kind: string, value: string, amount: int, tax_class: string|null}
     */
    private static function toggle(ListingAddon $addon, string $given): array
    {
        if ($given !== '1' && $given !== 'yes') {
            throw new InvalidArgumentException("「{$addon->name}」の選択内容が正しくありません。選び直してください。");
        }

        return ['id' => $addon->id, 'name' => $addon->name, 'kind' => 'toggle', 'value' => 'yes', 'amount' => $addon->amount, 'tax_class' => $addon->tax_class];
    }

    /**
     * @return array{id: string, name: string, kind: string, value: string, amount: int, tax_class: string|null}
     */
    private static function text(ListingAddon $addon, string $given): array
    {
        $max = $addon->max_length ?? ListingAddon::MAX_TEXT_LENGTH;

        if (mb_strlen($given) > $max) {
            throw new InvalidArgumentException("「{$addon->name}」は {$max} 文字までです。");
        }

        // 改行・制御文字・見えない書式の文字（文字の向きを変える印など）は断る
        if (preg_match('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', $given) === 1 || ! mb_check_encoding($given, 'UTF-8')) {
            throw new InvalidArgumentException("「{$addon->name}」に使えない文字が入っています。");
        }

        return ['id' => $addon->id, 'name' => $addon->name, 'kind' => 'text', 'value' => $given, 'amount' => $addon->amount, 'tax_class' => $addon->tax_class];
    }
}
