<?php

declare(strict_types=1);

namespace EcSugar\PluginCatalogSort;

use App\Core\Event\EventStore;
use App\Core\Listing\Models\Listing;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\CatalogBrowse;
use EcSugar\PluginCatalogSort\Models\CatalogSortItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 一覧の並びとタグを足す・直す・入れ替える・外す。書くのはここだけ（モデルを直に書き換えない）。
 * どれも DB のトランザクションの中で、最初に連鎖のロック（EventStore::lock()）を取り、その後に行のロック（lockForUpdate）を取り、
 * 状態を変えたのと同じトランザクションでイベント（catalog_sort.created・updated・moved・removed）を残す（conventions.md の 3、ADR 0012）。
 * イベントの中身は商品の ID・タグの数・変えた欄の名前だけ（タグの文そのものは入れない）。
 * 呼ぶのは管理画面の App の画面（CatalogSortAdminPage）だけで、欄の形は核が先に確かめてから渡す（AdminForms）
 */
final class CatalogSortItems
{
    /** 1 つの店で並びを決められる商品の数（核が一覧で使う数と同じ。これを超えた分は並びが効かない） */
    public const int MAX_ITEMS = CatalogBrowse::MAX_ORDER;

    /** 1 つの商品に付けられるタグの数（表の CHECK と同じ） */
    public const int MAX_TAGS = 5;

    /** タグ 1 つの長さ（核の選択肢の名前の上限に収める） */
    public const int TAG_MAX = CatalogBrowse::LABEL_MAX;

    /** タグの欄に入れられる文の長さ（カンマ区切り） */
    public const int TAGS_INPUT_MAX = 150;

    /** まだ商品が無い店の、選べない選択肢の値（商品の ID と重ならない） */
    public const string NO_LISTING = 'none';

    public function __construct(private readonly EventStore $events) {}

    /**
     * 並びの最後に商品を足す
     *
     * @param  array<string, mixed>  $values  listing・tags（管理画面からは、核が形を確かめた後の値）
     */
    public function create(array $values, string $actor): string
    {
        return DB::transaction(function () use ($values, $actor): string {
            $this->events->lock();

            if (CatalogSortItem::query()->count() >= self::MAX_ITEMS) {
                throw ValidationException::withMessages(['values' => '並びを決められるのは '.self::MAX_ITEMS.' 点までです。使わない商品を一覧から外してから追加してください。']);
            }

            $listingId = self::listingId($values);

            if (CatalogSortItem::query()->where('listing_id', $listingId)->exists()) {
                throw ValidationException::withMessages(['listing' => 'この商品はすでに一覧にあります。順番は、商品ごとの画面の「上へ」「下へ」で変えてください。']);
            }

            $item = CatalogSortItem::query()->create([
                'listing_id' => $listingId,
                'tags' => self::tags($values),
                'position' => (int) CatalogSortItem::query()->max('position') + 1,
            ]);
            $this->events->append('catalog_sort.created', 'catalog_sort_item', $item->id, ['listing_id' => $item->listing_id, 'tags' => count($item->tags)], $actor);

            return $item->id;
        });
    }

    /**
     * 商品とタグを直す（並びは move で変える）
     *
     * @param  array<string, mixed>  $values
     */
    public function update(string $id, array $values, string $actor): void
    {
        DB::transaction(function () use ($id, $values, $actor): void {
            $this->events->lock();
            $item = self::locked($id);
            $listingId = self::listingId($values);
            $tags = self::tags($values);

            if ($listingId !== $item->listing_id && CatalogSortItem::query()->where('listing_id', $listingId)->exists()) {
                throw ValidationException::withMessages(['listing' => 'この商品はすでに一覧にあります。']);
            }

            $changed = array_values(array_filter([
                $listingId === $item->listing_id ? null : 'listing_id',
                $tags === $item->tags ? null : 'tags',
            ]));

            if ($changed === []) {
                return;
            }

            $item->forceFill(['listing_id' => $listingId, 'tags' => $tags])->save();
            $this->events->append('catalog_sort.updated', 'catalog_sort_item', $item->id, ['changed' => $changed, 'listing_id' => $item->listing_id, 'tags' => count($tags)], $actor);
        });
    }

    /**
     * 1 つ上（up）か下（down）の商品と並びを入れ替える。端なら何もしない
     */
    public function move(string $id, string $direction, string $actor): void
    {
        DB::transaction(function () use ($id, $direction, $actor): void {
            $this->events->lock();
            $item = self::locked($id);
            $up = $direction === 'up';
            $neighbor = CatalogSortItem::query()
                ->where('position', $up ? '<' : '>', $item->position)
                ->orderBy('position', $up ? 'desc' : 'asc')
                ->lockForUpdate()
                ->first();

            if ($neighbor === null) {
                return;
            }

            // 並びの一意はトランザクションの終わりに確かめる（DEFERRABLE）ので、2 行をそのまま入れ替えてよい
            [$item->position, $neighbor->position] = [$neighbor->position, $item->position];
            $item->save();
            $neighbor->save();
            $this->events->append('catalog_sort.moved', 'catalog_sort_item', $item->id, ['direction' => $up ? 'up' : 'down', 'swapped_with' => $neighbor->id], $actor);
        });
    }

    /**
     * 一覧から外す（商品そのものは消さない。ショップの並びは新着順に戻る）
     */
    public function remove(string $id, string $actor): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $this->events->lock();
            $item = self::locked($id);
            $item->delete();
            $this->events->append('catalog_sort.removed', 'catalog_sort_item', $item->id, ['listing_id' => $item->listing_id], $actor);
        });
    }

    /**
     * 連鎖のロックの後に、行のロックを取って読む（無ければ管理画面では 404）
     */
    private static function locked(string $id): CatalogSortItem
    {
        return CatalogSortItem::query()->whereKey($id)->lockForUpdate()->first() ?? throw new AdminRecordNotFound;
    }

    /**
     * 選んだ商品の ID（書く時にも店にあるかを確かめ直す。ほかの店の商品は、この店の文脈では見つからない）
     *
     * @param  array<string, mixed>  $values
     */
    private static function listingId(array $values): string
    {
        $listingId = is_string($values['listing'] ?? null) ? strtolower($values['listing']) : '';

        if ($listingId === self::NO_LISTING) {
            throw ValidationException::withMessages(['listing' => 'まず商品を作ってください。並びを決められるのは、この店の商品だけです。']);
        }

        if (preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $listingId) !== 1 || ! Listing::query()->whereKey($listingId)->exists()) {
            throw ValidationException::withMessages(['listing' => 'この商品は見つかりませんでした。選び直してください。']);
        }

        return $listingId;
    }

    /**
     * タグの欄（カンマ区切り）を、短い言葉の並びにする。重なりは 1 つに、数と長さと使える文字を確かめる
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private static function tags(array $values): array
    {
        $input = is_string($values['tags'] ?? null) ? $values['tags'] : '';

        if (mb_strlen($input) > self::TAGS_INPUT_MAX) {
            throw ValidationException::withMessages(['tags' => 'タグは全部で '.self::TAGS_INPUT_MAX.' 文字までです。']);
        }

        $tags = [];

        foreach (preg_split('/[,、]/u', $input) ?: [] as $part) {
            $tag = trim($part);

            if ($tag === '') {
                continue;
            }

            // HTML・属性を抜け出す文字と制御文字は断る（ショップの絞り込みに出る言葉。核も同じ形で確かめる）
            if (mb_strlen($tag) > self::TAG_MAX || preg_match('/[<>"\'`\\\\]|\p{C}/u', $tag) === 1) {
                throw ValidationException::withMessages(['tags' => 'タグは 1 つ '.self::TAG_MAX.' 文字までで、記号（< > " \' ` \\）は使えません。']);
            }

            $tags[$tag] = $tag;
        }

        if (count($tags) > self::MAX_TAGS) {
            throw ValidationException::withMessages(['tags' => 'タグは 1 つの商品に '.self::MAX_TAGS.' 個までです。']);
        }

        return array_values($tags);
    }
}
