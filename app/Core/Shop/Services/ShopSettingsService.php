<?php

declare(strict_types=1);

namespace App\Core\Shop\Services;

use App\Core\Event\EventStore;
use App\Core\Shop\Models\ShopSettings;
use App\Core\Shop\ShippingPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * 店の設定を読む・変える。変えるのはここだけ。持つのは店の事実（注文の案内・特商法の表記など。送料の表は ShippingMethods。ADR 0064）だけで、
 * 見せ方（お知らせの帯・ヒーロー・安心の一行・トップの区画）はテーマの区画の値（ThemeContentService。ADR 0028）。
 * 書き込みは EventStore::lock() → 設定の行のロックの順（ADR 0012）。
 */
final class ShopSettingsService
{
    private const string NO_LINE_BREAK = 'not_regex:/[\r\n]/';

    /** 改行（\r と \n）のほかの制御文字 */
    private const string NO_CONTROL = 'not_regex:/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/';

    public const int MAX_LEGAL_ITEMS = 20;

    /** プライバシーポリシーの本文の上限（文字。ふつうの店のものは 3,000〜10,000 字ほど。ADR 0094） */
    public const int MAX_PRIVACY_POLICY = 20000;

    public function __construct(
        private readonly EventStore $events,
        private readonly ShippingMethods $shipping,
    ) {}

    /**
     * 0 円の品を売るか。既定は売らない（他人宛ての注文のメールと在庫の空にする悪用を防ぐ）
     */
    public function sellsFreeItems(): bool
    {
        return (bool) (ShopSettings::query()->value('sell_free_items') ?? false);
    }

    public function setSellFreeItems(bool $sell, ?string $actor = null): void
    {
        DB::transaction(function () use ($sell, $actor): void {
            $this->events->lock();
            $settings = ShopSettings::query()->lockForUpdate()->first() ?? ShopSettings::create([]);

            if ($settings->sell_free_items === $sell) {
                return;
            }

            $settings->sell_free_items = $sell;
            $settings->save();
            $this->events->append('shop.free_items_updated', 'shop_settings', $settings->id, ['sell' => $sell], $actor);
        });
    }

    /**
     * 解析・広告のタグの同意の帯を出すか（ADR 0034 の 4 の置き換え）。既定は出さない（行が無い店も出さない）。
     * 出さない店では、タグは最初から読み込み、外部送信の公表だけ。出す店では、受け入れたお客さんにだけタグを読み込む
     */
    public function showsConsentBanner(): bool
    {
        return (bool) (ShopSettings::query()->value('consent_banner') ?? false);
    }

    /**
     * 同意の帯を出すかを変える。お客さんの情報を外へ送る始まり方が変わるので、記録に残す（値だけ）
     */
    public function setConsentBanner(bool $show, ?string $actor = null): void
    {
        DB::transaction(function () use ($show, $actor): void {
            $this->events->lock();
            $settings = ShopSettings::query()->lockForUpdate()->first() ?? ShopSettings::create([]);

            if ($settings->consent_banner === $show) {
                return;
            }

            $settings->consent_banner = $show;
            $settings->save();
            $this->events->append('shop.consent_banner_updated', 'shop_settings', $settings->id, ['show' => $show], $actor);
        });
    }

    /**
     * 注文の案内（届く目安と返品の条件）。未設定は null
     *
     * @return array{delivery: string|null, returns: string|null}
     */
    public function orderTerms(): array
    {
        $terms = ShopSettings::query()->first()?->order_terms;
        $text = static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? $value : null;

        return ['delivery' => $text($terms['delivery'] ?? null), 'returns' => $text($terms['returns'] ?? null)];
    }

    /**
     * @param  array<string, mixed>  $input  {delivery, returns}
     */
    public function setOrderTerms(array $input, ?string $actor = null): void
    {
        $data = Validator::make($input, [
            'delivery' => ['present', 'nullable', 'string', 'max:200'],
            'returns' => ['present', 'nullable', 'string', 'max:500'],
        ], attributes: ['delivery' => '届く目安', 'returns' => '返品・交換の条件'])->validate();
        $terms = self::trimmed(['delivery' => $data['delivery'], 'returns' => $data['returns']]);

        DB::transaction(function () use ($terms, $actor): void {
            $this->events->lock();
            $settings = ShopSettings::query()->lockForUpdate()->first()
                ?? ShopSettings::create([]);

            if ($settings->order_terms == $terms) {
                return;
            }

            $settings->order_terms = $terms;
            $settings->save();
            // 文言は記録に入れず、変わったことだけ
            $this->events->append('shop.order_terms_updated', 'shop_settings', $settings->id, ['changed' => true], $actor);
        });
    }

    /**
     * 特定商取引法に基づく表記の項目（並び順）。未設定は空。形の崩れた行は出さない
     *
     * @return list<array{label: string, value: string}>
     */
    public function legal(): array
    {
        $items = [];

        foreach (ShopSettings::query()->first()->legal ?? [] as $row) {
            if (is_array($row) && is_string($row['label'] ?? null) && is_string($row['value'] ?? null)) {
                $items[] = ['label' => $row['label'], 'value' => $row['value']];
            }
        }

        return $items;
    }

    /**
     * 表記をまとめて置き換える。形が違えば ValidationException。中身は暗号化して置き、記録には項目の数だけ
     *
     * @param  array<string, mixed>  $input  {items: list<{label, value}>}
     */
    public function setLegal(array $input, ?string $actor = null): void
    {
        $data = Validator::make($input, [
            'items' => ['present', 'array', 'list', 'max:'.self::MAX_LEGAL_ITEMS],
            'items.*' => ['array:label,value'],
            'items.*.label' => ['required', 'string', 'max:30', self::NO_LINE_BREAK, self::NO_CONTROL],
            'items.*.value' => ['required', 'string', 'max:1000', self::NO_CONTROL],
        ], attributes: ['items' => '表記の項目', 'items.*.label' => '項目の名前', 'items.*.value' => '項目の内容'])->validate();

        $items = [];
        foreach ($data['items'] as $row) {
            // 改行は \n にそろえる（店頭は改行を保って出す）
            $items[] = ['label' => trim($row['label']), 'value' => trim(str_replace(["\r\n", "\r"], "\n", $row['value']))];
        }

        DB::transaction(function () use ($items, $actor): void {
            $this->events->lock();
            $settings = ShopSettings::query()->lockForUpdate()->first()
                ?? ShopSettings::create([]);

            if (($settings->legal ?? []) === $items) {
                return;
            }

            $settings->legal = $items;
            $settings->save();
            // 中身（所在地・電話など）は記録に入れない
            $this->events->append('shop.legal_updated', 'shop_settings', $settings->id, ['items' => count($items)], $actor);
        });
    }

    /**
     * プライバシーポリシーの本文（ADR 0094）。店の人が書いた素の文（見出しと箇条書きだけの短い書き方。ショップは PrivacyPolicyView で読む）。未設定は null
     */
    public function privacyPolicy(): ?string
    {
        $text = ShopSettings::query()->first()?->privacy_policy;

        return is_string($text) && trim($text) !== '' ? $text : null;
    }

    /**
     * 本文があるか（ショップのフッターのリンクを出すか）。暗号を解かずに列だけを見る
     */
    public function hasPrivacyPolicy(): bool
    {
        return ShopSettings::query()->whereNotNull('privacy_policy')->exists();
    }

    /**
     * 本文を置き換える。空（null・空白だけ）は外す（ショップの /privacy は「見つかりません」に戻る）。形が違えば ValidationException。
     * 中身は暗号化して置き、記録には文字の数だけ（問い合わせ先の名前・住所を記録に入れない）
     *
     * @param  array<string, mixed>  $input  {body: string|null}
     */
    public function setPrivacyPolicy(array $input, ?string $actor = null): void
    {
        // 改行は \n に、タブは空白にそろえる（文書のソフトから貼った文を断らない）。ほかの制御文字は断る
        if (is_string($input['body'] ?? null)) {
            $input['body'] = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $input['body']);
        }

        $data = Validator::make($input, [
            'body' => ['present', 'nullable', 'string', 'max:'.self::MAX_PRIVACY_POLICY, self::NO_CONTROL],
        ], attributes: ['body' => 'プライバシーポリシーの本文'])->validate();
        $body = is_string($data['body']) && trim($data['body']) !== '' ? trim($data['body']) : null;

        DB::transaction(function () use ($body, $actor): void {
            $this->events->lock();
            $settings = ShopSettings::query()->lockForUpdate()->first()
                ?? ShopSettings::create([]);

            if ($settings->privacy_policy === $body) {
                return;
            }

            $settings->privacy_policy = $body;
            $settings->save();
            $this->events->append('shop.privacy_updated', 'shop_settings', $settings->id, ['chars' => $body === null ? 0 : mb_strlen($body)], $actor);
        });
    }

    /**
     * 送料を全国一律にする（運営の近道。ADR 0064 の 5）: いちばん上の配送の方法の 47 県を同じ額にし、無料の線を置く。
     * 表そのものを変えるのは ShippingMethods（管理画面の設定 › 配送）。形が違えば ValidationException
     *
     * @param  array<string, mixed>  $input  {fee, free_over}
     */
    public function setShipping(array $input, ?string $actor = null): ShippingPolicy
    {
        $data = Validator::make($input, [
            'fee' => ['required', 'integer', 'min:0', 'max:'.ShippingMethods::MAX_FEE],
            'free_over' => ['present', 'nullable', 'integer', 'min:1', 'max:'.ShippingMethods::MAX_FREE_OVER],
        ], attributes: ['fee' => '送料', 'free_over' => '送料無料になる金額'])->validate();

        $policy = new ShippingPolicy((int) $data['fee'], $data['free_over'] === null ? null : (int) $data['free_over']);
        $this->shipping->setFlat($policy->fee, $policy->freeOver, $actor);

        return $policy;
    }

    /**
     * 前後の空白を除き、空の文字は「無し」にする
     */
    private static function trimmed(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::trimmed(...), $value);
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }
}
