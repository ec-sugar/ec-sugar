<?php

declare(strict_types=1);

namespace App\Core\Listing\Services;

use App\Core\Event\EventStore;
use App\Core\Listing\Description\ProductDescription;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Events\ListingPriceChanged;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Core\Media\Models\ShopMedia;
use App\Core\Shop\Models\ShippingMethod;
use App\Core\Shop\Seo\SeoText;
use App\Core\Tax\TaxClass;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * 出品物の登録と編集。変更はここを通し、ドメインイベントを残す。
 *
 * - 編集は楽観ロック: 呼び出し側が読んだバージョン（version）を渡し、今のバージョンと違えば StaleListingException（後から保存した方を拒む）
 * - 出品者・種類・通貨は作った後に変えない（取引や在庫が前提にしているため）
 * - 書き込みは EventStore::lock() を先に取ってから行ロック（ADR 0012）
 * - 説明はブロックの並び・仕様の表・短い説明（ADR 0032）。形は ProductDescription が確かめ、description にはブロックの文の写しを書く。
 *   description だけを渡されたら（前の形）、1 つの段落にする。説明の写真は同じ店のショップの写真にあるものだけ（消されないよう、行を押さえてから指す）
 */
final class ListingService
{
    // 作った後に変えてよい項目
    public const array EDITABLE = ['title', 'description', 'unit_amount', 'sku', 'status', 'description_blocks', 'specs', 'summary', 'seo_title', 'seo_description', 'tax_class', 'shipping_method_id', 'free_shipping'];

    // 送料に関わる項目（ADR 0064）。変えたら定期便の約束の見直しを出す
    private const array SHIPPING_FIELDS = ['shipping_method_id', 'free_shipping'];

    // 説明の項目。長くなりうるので、記録には「変わった」ことだけを残す（検索の結果に出す説明も。ADR 0061）
    private const array DESCRIPTION_FIELDS = ['description', 'description_blocks', 'specs', 'summary', 'seo_description'];

    public function __construct(private readonly EventStore $events) {}

    /**
     * @param  array{title: string, description?: string|null, unit_amount?: int|null, sku?: string|null, status?: ListingStatus, description_blocks?: mixed, specs?: mixed, summary?: mixed, seo_title?: mixed, seo_description?: mixed, tax_class?: TaxClass}  $data
     *
     * @throws ValidationException 説明の形が違う・写真がショップの写真に無い
     */
    public function create(Seller $seller, string $kind, array $data, ?string $actor = null): Listing
    {
        $this->assertEditable($data);
        $data = self::prepareDescription($data);

        return DB::transaction(function () use ($seller, $kind, $data, $actor): Listing {
            $this->events->lock();
            $this->holdPhotos($data);
            $this->assertShippingMethod($data);

            // 品番は種類が持つ（ADR 0036）。作った商品のただ 1 つの種類へ入れる
            $sku = $data['sku'] ?? null;
            unset($data['sku']);

            $listing = new Listing(['seller_id' => $seller->id, 'kind' => $kind] + $data);
            $listing->save();

            if ($sku !== null) {
                $listing->variants()->firstOrFail()->update(['sku' => $sku]);
            }

            $this->events->append('listing.created', 'listing', $listing->id, [
                'seller_id' => $listing->seller_id,
                'kind' => $listing->kind,
                'title' => $listing->title,
                'unit_amount' => $listing->unit_amount,
                'currency' => $listing->currency,
                'sku' => $listing->sku,
                'status' => $listing->status->value,
                'tax_class' => $listing->tax_class->value,
            ], $actor);

            return $listing;
        });
    }

    /**
     * @param  array<string, mixed>  $changes  EDITABLE の項目だけ
     *
     * @throws ValidationException 説明の形が違う・写真がショップの写真に無い
     */
    public function update(Listing $listing, int $expectedVersion, array $changes, ?string $actor = null): Listing
    {
        $this->assertEditable($changes);
        // 前の形（description だけ）を送ってきたか。デプロイの前から開いたままの古い管理画面など。
        // その文は、ロックの中で今の写しと比べてから確かめる（移行の後も列には元の文が残り、今は断る文字を含みうるので、先に厳しく確かめると読んだまま送り返しただけで断ってしまう）
        $textOnly = array_key_exists('description', $changes) && ! array_key_exists('description_blocks', $changes);
        $rawText = $textOnly ? $changes['description'] : null;

        if ($textOnly) {
            unset($changes['description']);
        }

        $changes = self::prepareDescription($changes);

        return DB::transaction(function () use ($listing, $expectedVersion, $changes, $actor, $textOnly, $rawText): Listing {
            $this->events->lock();

            $locked = Listing::query()->whereKey($listing->id)->lockForUpdate()->firstOrFail();

            if ($locked->version !== $expectedVersion) {
                throw new StaleListingException($locked->version);
            }

            // 前の形の説明が今の写しと同じ（読んだまま送り返した）なら、変えたことにしない。1 段落に作り直すと、見出しや写真の構造がつぶれる。
            // 比べるのは生の文で、厳しい確かめ（fromText）の前に。違うときだけ確かめて 1 つの段落にする
            if ($textOnly && ! self::sameText($rawText, $locked->description)) {
                $prepared = self::prepareDescription(['description' => $rawText]);

                if ($prepared['description'] !== $locked->description) {
                    $changes += $prepared;
                }
            }

            $this->holdPhotos($changes);
            $this->assertShippingMethod($changes);
            $skuDiff = $this->changeSoleSku($locked, $changes);

            // 同じ中身（jsonb が返す項目の順だけ違う）は変えたことにしない
            foreach (['description_blocks', 'specs'] as $field) {
                if (array_key_exists($field, $changes) && ProductDescription::canonical($locked->getAttribute($field)) === ProductDescription::canonical($changes[$field])) {
                    unset($changes[$field]);

                    // ブロックが同じなら、その写し（description）も書かない。写しだけを書くと、DB のトリガーが
                    // 「前のバージョンのアプリが説明を書いた」と見なして、ブロックを 1 つの段落にまとめてしまう
                    if ($field === 'description_blocks') {
                        unset($changes['description']);
                    }
                }
            }

            $locked->fill($changes);
            $dirty = array_keys($locked->getDirty());

            // 何も変わらないならバージョンも進めず、記録も残さない
            if ($dirty === [] && $skuDiff === null) {
                return $locked;
            }

            $diff = $skuDiff === null ? [] : ['sku' => $skuDiff];

            foreach ($dirty as $field) {
                // 説明は長文になりうるので、変わったことだけを残す
                $diff[$field] = in_array($field, self::DESCRIPTION_FIELDS, true)
                    ? ['changed' => true]
                    : ['from' => self::plain($locked->getOriginal($field)), 'to' => self::plain($locked->getAttribute($field))];
            }

            $locked->version++;
            $locked->save();

            $this->events->append('listing.updated', 'listing', $locked->id, ['version' => $locked->version, 'changes' => $diff], $actor);

            if (in_array('unit_amount', $dirty, true) || array_intersect(self::SHIPPING_FIELDS, $dirty) !== []) {
                event(new ListingPriceChanged($locked->id, $actor ?? 'system'));
            }

            return $locked;
        });
    }

    /**
     * 商品を消す（ゴミ箱へ。行は残し、ショップと管理画面の一覧から外れる）。読んだバージョンと違えば StaleListingException。
     * 取引の行は名前と額を写して持ち、消した商品も引けるので記録は残る。注文に使われた商品を消すかは呼び出し側が決める
     *
     * @throws StaleListingException
     */
    public function delete(Listing $listing, int $expectedVersion, ?string $actor = null): void
    {
        DB::transaction(function () use ($listing, $expectedVersion, $actor): void {
            $this->events->lock();

            $locked = Listing::query()->whereKey($listing->id)->lockForUpdate()->firstOrFail();

            if ($locked->version !== $expectedVersion) {
                throw new StaleListingException($locked->version);
            }

            $locked->delete();
            $this->events->append('listing.deleted', 'listing', $locked->id, ['version' => $locked->version], $actor);
        });
    }

    /**
     * 品番の変更を、軸の無い商品のただ 1 つの種類へ写す（品番は種類が持つ。ADR 0036）。changes から sku を外す。
     * 軸のある商品は種類ごとに品番を持つので、商品の品番としては変えられない。
     *
     * @param  array<string, mixed>  $changes
     * @return array{from: string|null, to: string|null}|null 変わったときだけ
     */
    private function changeSoleSku(Listing $locked, array &$changes): ?array
    {
        if (! array_key_exists('sku', $changes)) {
            return null;
        }

        $sku = $changes['sku'];
        unset($changes['sku']);
        $variant = $locked->soleVariant();

        if ($variant === null) {
            throw ValidationException::withMessages(['sku' => '種類のある商品の品番は、種類ごとに入力してください。']);
        }

        if ($variant->sku === $sku) {
            return null;
        }

        $from = $variant->sku;
        $variant->sku = is_string($sku) ? $sku : null;
        $variant->save();

        return ['from' => $from, 'to' => $variant->sku];
    }

    /**
     * 配送の方法はこの店の表にあるものだけ（ロックで押さえ、保存の間に消されないように。ADR 0064）
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function assertShippingMethod(array $data): void
    {
        $id = $data['shipping_method_id'] ?? null;

        if ($id !== null && ! ShippingMethod::query()->whereKey($id)->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['shipping_method_id' => 'この配送の方法は見つかりません。画面を読み込み直してください。']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertEditable(array $data): void
    {
        $unknown = array_diff(array_keys($data), self::EDITABLE);

        if ($unknown !== []) {
            throw new InvalidArgumentException('変えられない項目です: '.implode(', ', $unknown));
        }
    }

    /**
     * 前の形の説明の文が、今の写しと同じか（入口で前後の空白が落ちることがあるので、前後の空白だけは見ない）
     */
    private static function sameText(mixed $sent, ?string $current): bool
    {
        if ($sent === $current) {
            return true;
        }

        return is_string($sent) && $current !== null && trim($sent) === trim($current);
    }

    /**
     * 説明の項目を確かめてそろえる（ロックの外で先に）。ブロックを渡されたら description はその写し、description だけなら 1 つの段落に
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function prepareDescription(array $data): array
    {
        if (array_key_exists('description_blocks', $data)) {
            $data['description_blocks'] = ProductDescription::validateBlocks($data['description_blocks']);
            $data['description'] = ProductDescription::plainText($data['description_blocks']);
        } elseif (array_key_exists('description', $data)) {
            if ($data['description'] !== null && ! is_string($data['description'])) {
                throw new InvalidArgumentException('説明は文字で渡します');
            }

            $data['description_blocks'] = ProductDescription::fromText($data['description']);
            $data['description'] = ProductDescription::plainText($data['description_blocks']);
        }

        if (array_key_exists('specs', $data)) {
            $data['specs'] = ProductDescription::validateSpecs($data['specs']);
        }

        if (array_key_exists('summary', $data)) {
            $data['summary'] = ProductDescription::validateSummary($data['summary']);
        }

        // 検索の結果に出す題と説明（ADR 0061）。空は「自動」（null）。1 行で上限まで
        $seo = array_intersect_key($data, array_flip(['seo_title', 'seo_description']));

        if ($seo !== []) {
            Validator::make($seo, SeoText::rules(), SeoText::messages(), SeoText::attributes())->validate();

            foreach ($seo as $field => $value) {
                $data[$field] = SeoText::clean($value);
            }
        }

        return $data;
    }

    /**
     * 説明の写真が、この店のショップの写真にあることを確かめ、消されないよう行を押さえる。
     * 消す側（ShopMediaService::remove）も EventStore::lock() を先に取るので順番になる。ほかの店の写真は、この店の DB に無いので「無い」になる
     *
     * @param  array<string, mixed>  $data  prepareDescription の後
     *
     * @throws ValidationException
     */
    private function holdPhotos(array $data): void
    {
        $blocks = $data['description_blocks'] ?? [];
        $ids = is_array($blocks) ? ProductDescription::mediaIds($blocks) : [];

        if ($ids === []) {
            return;
        }

        $found = ShopMedia::query()->whereIn('id', $ids)->lockForUpdate()->pluck('id')->all();
        $missing = array_diff($ids, $found);

        if ($missing === []) {
            return;
        }

        $errors = [];

        foreach ($blocks as $index => $block) {
            if (($block['type'] ?? null) === 'image' && in_array($block['media_id'] ?? null, $missing, true)) {
                $errors["description_blocks.{$index}.media_id"] = '選んだ写真が「写真」の一覧にありません。写真を選び直してください。';
            }
        }

        throw ValidationException::withMessages($errors);
    }

    private static function plain(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
