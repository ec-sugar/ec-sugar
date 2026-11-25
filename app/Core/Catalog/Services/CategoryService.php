<?php

declare(strict_types=1);

namespace App\Core\Catalog\Services;

use App\Core\Catalog\Models\Category;
use App\Core\Event\EventStore;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingImage;
use App\Core\Shop\Seo\SeoText;
use App\Core\Shop\Services\RedirectService;
use App\Core\Theme\Services\ThemeCategoryUsage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * 商品カテゴリを作る・変える・動かす・並べる・消す、商品に付ける。カテゴリを変えるのはここだけ。
 *
 * - 書き込みは EventStore::lock() を先に取る（ADR 0012）。木の形（循環・深さ）は、このロックの中で確かめるので、同時に動かしても崩れない
 * - 深さは 3 段まで（Category::MAX_DEPTH）。子があるカテゴリは消せない（DB でも restrict）
 * - テーマの区画の値（ページの編集で選んだカテゴリ）で使っているカテゴリは消せない。区画の値は外部キーで守れないので、
 *   消すのと同じロックの中で数える（ThemeCategoryUsage。区画を書く側も EventStore::lock() を先に取るので、数えた後に足されない）
 * - 形が違えば ValidationException（管理画面ではそのまま 422）
 */
final class CategoryService
{
    public const int MAX_PER_LISTING = 10;

    /** 断るときの文に挙げる「使っている所」の数（多いときは「ほか N か所」） */
    private const int USAGES_SHOWN = 5;

    public function __construct(
        private readonly EventStore $events,
        private readonly ThemeCategoryUsage $themeUsage,
        private readonly RedirectService $redirects,
    ) {}

    /**
     * @param  array{name?: mixed, slug?: mixed, parent_id?: mixed}  $input
     */
    public function create(array $input, ?string $actor = null): Category
    {
        $data = $this->validate($input, [
            'name' => ['required', 'string', 'max:40', 'not_regex:/[\r\n]/'],
            'slug' => ['required', 'string', 'max:60', 'regex:/\A[a-z0-9]+(-[a-z0-9]+)*\z/'],
            'parent_id' => ['nullable', 'ulid'],
        ]);

        return $this->write(function () use ($data, $actor): Category {
            $parent = $data['parent_id'] === null ? null : $this->find($data['parent_id'], 'parent_id');

            if ($parent !== null && $this->depthOf($parent) + 1 > Category::MAX_DEPTH) {
                throw ValidationException::withMessages(['parent_id' => 'カテゴリは '.Category::MAX_DEPTH.' 階層までです。']);
            }

            $this->assertSlugFree($data['slug']);
            $category = Category::create([
                'parent_id' => $parent?->id,
                'name' => trim($data['name']),
                'slug' => $data['slug'],
                'position' => Category::query()->where('parent_id', $parent?->id)->count(),
            ]);
            $this->events->append('category.created', 'category', $category->id, ['parent_id' => $parent?->id, 'slug' => $category->slug], $actor);

            return $category;
        });
    }

    /**
     * 名前・slug・代表の写真・検索の結果に出す題と説明（ADR 0061）を変える（渡した項目だけ）。
     * slug を変えたら、古い URL から新しい URL への転送を自動で足す（ADR 0061 の 11。上限に達していれば足さない）
     *
     * @param  array{name?: mixed, slug?: mixed, image_id?: mixed, seo_title?: mixed, seo_description?: mixed}  $input
     */
    public function update(Category $category, array $input, ?string $actor = null): Category
    {
        $data = $this->validate($input, [
            'name' => ['sometimes', 'required', 'string', 'max:40', 'not_regex:/[\r\n]/'],
            'slug' => ['sometimes', 'required', 'string', 'max:60', 'regex:/\A[a-z0-9]+(-[a-z0-9]+)*\z/'],
            'image_id' => ['sometimes', 'nullable', 'ulid'],
            ...SeoText::rules(),
        ]);

        return $this->write(function () use ($category, $data, $actor): Category {
            $locked = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('name', $data)) {
                $locked->name = trim($data['name']);
            }

            $movedFrom = null;

            if (array_key_exists('slug', $data) && $data['slug'] !== $locked->slug) {
                $this->assertSlugFree($data['slug']);
                $movedFrom = $locked->slug;
                $locked->slug = $data['slug'];
            }

            foreach (['seo_title', 'seo_description'] as $field) {
                if (array_key_exists($field, $data)) {
                    $locked->setAttribute($field, SeoText::clean($data[$field]));
                }
            }

            if (array_key_exists('image_id', $data)) {
                if ($data['image_id'] !== null && ! ListingImage::query()->whereKey($data['image_id'])->exists()) {
                    throw ValidationException::withMessages(['image_id' => '写真が見つかりません。']);
                }

                $locked->image_id = $data['image_id'];
            }

            $changed = array_keys($locked->getDirty());

            if ($changed !== []) {
                $locked->save();
                $this->events->append('category.updated', 'category', $locked->id, ['changed' => $changed], $actor);
            }

            if ($movedFrom !== null) {
                $this->redirects->followMove("/categories/{$movedFrom}", "/categories/{$locked->slug}", $actor);
            }

            return $locked;
        });
    }

    /**
     * 親を変える（null で一番上へ）。自分の下へは動かせない。動かした先の兄弟の最後に並ぶ
     */
    public function move(Category $category, ?string $parentId, ?string $actor = null): Category
    {
        return $this->write(function () use ($category, $parentId, $actor): Category {
            $locked = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
            $parent = $parentId === null ? null : $this->find($parentId, 'parent_id');

            if ($parent?->id === $locked->parent_id) {
                return $locked;
            }

            if ($parent !== null && in_array($locked->id, [$parent->id, ...$this->ancestorIds($parent)], true)) {
                throw ValidationException::withMessages(['parent_id' => 'そのカテゴリ自身や、その下の階層のカテゴリの下へは移動できません。']);
            }

            $depth = ($parent === null ? 0 : $this->depthOf($parent)) + $this->heightOf($locked);

            if ($depth > Category::MAX_DEPTH) {
                throw ValidationException::withMessages(['parent_id' => 'カテゴリは '.Category::MAX_DEPTH.' 階層までです（下の階層のカテゴリも含めて）。']);
            }

            $from = $locked->parent_id;
            $locked->parent_id = $parent?->id;
            $locked->position = Category::query()->where('parent_id', $parent?->id)->count();
            $locked->save();
            $this->renumber($from);
            $this->events->append('category.moved', 'category', $locked->id, ['from' => $from, 'to' => $parent?->id], $actor);

            return $locked;
        });
    }

    /**
     * @param  list<string>  $ids  この親の子をすべて、並べたい順に
     */
    public function reorder(?string $parentId, array $ids, ?string $actor = null): void
    {
        $this->write(function () use ($parentId, $ids, $actor): void {
            $current = Category::query()->where('parent_id', $parentId)->lockForUpdate()->pluck('id')->all();
            $given = $ids;
            sort($current);
            sort($given);

            if ($current !== $given || count(array_unique($ids)) !== count($ids)) {
                throw ValidationException::withMessages(['ids' => '並べ替えには、同じ親のカテゴリをすべて 1 回ずつ指定してください。']);
            }

            if ($ids === []) {
                return;
            }

            foreach ($ids as $position => $id) {
                Category::query()->whereKey($id)->update(['position' => $position]);
            }

            $this->events->append('category.reordered', 'category', $parentId ?? $ids[0], ['parent_id' => $parentId, 'order' => $ids], $actor);
        });
    }

    /**
     * 子があるカテゴリと、テーマの区画で使っているカテゴリは消せない。商品からは外れる
     */
    public function delete(Category $category, ?string $actor = null): void
    {
        $this->write(function () use ($category, $actor): void {
            $locked = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();

            if (Category::query()->where('parent_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['category' => '下の階層にカテゴリがあるため削除できません。先に下のカテゴリを移動するか削除してください。']);
            }

            $this->assertNotInThemes($locked);
            $listings = DB::table('listing_categories')->where('category_id', $locked->id)->count();
            $locked->delete();
            $this->renumber($locked->parent_id);
            $this->events->append('category.deleted', 'category', $locked->id, ['parent_id' => $locked->parent_id, 'slug' => $locked->slug, 'listings' => $listings], $actor);
        });
    }

    /**
     * 商品のカテゴリをまとめて置き換える。主カテゴリは付けたものの中から（省略すると最初のもの）
     *
     * @param  list<string>  $categoryIds
     */
    public function assign(Listing $listing, array $categoryIds, ?string $primaryId = null, ?string $actor = null): void
    {
        $data = $this->validate(['categories' => $categoryIds, 'primary' => $primaryId], [
            'categories' => ['present', 'array', 'max:'.self::MAX_PER_LISTING],
            'categories.*' => ['ulid', 'distinct'],
            'primary' => ['nullable', 'ulid', 'in:'.implode(',', $categoryIds)],
        ]);
        /** @var list<string> $ids */
        $ids = array_values($data['categories']);
        $primary = $ids === [] ? null : ($data['primary'] ?? $ids[0]);

        $this->write(function () use ($listing, $ids, $primary, $actor): void {
            Listing::query()->whereKey($listing->id)->lockForUpdate()->firstOrFail();

            if (Category::query()->whereIn('id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['categories' => 'カテゴリが見つかりません。']);
            }

            $before = DB::table('listing_categories')->where('listing_id', $listing->id)->orderBy('category_id')->get(['category_id', 'is_primary'])
                ->map(static fn (object $row): array => [(string) $row->category_id, (bool) $row->is_primary])->all();
            $after = array_map(static fn (string $id): array => [$id, $id === $primary], $ids);
            usort($after, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

            if ($before === $after) {
                return;
            }

            DB::table('listing_categories')->where('listing_id', $listing->id)->delete();
            DB::table('listing_categories')->insert(array_map(static fn (string $id): array => [
                'listing_id' => $listing->id, 'category_id' => $id, 'is_primary' => $id === $primary,
            ], $ids));
            $this->events->append('listing.categories_changed', 'listing', $listing->id, ['categories' => $ids, 'primary' => $primary], $actor);
        });
    }

    /**
     * 木（一番上から、並び順に）。商品の数は付いている商品（公開の状態は問わない）
     *
     * @return list<array{id: string, name: string, slug: string, image_id: string|null, seo_title: string|null, seo_description: string|null, listings: int, children: list<mixed>}>
     */
    public function tree(): array
    {
        $all = Category::query()->withCount('listings')->orderBy('position')->get()->groupBy(static fn (Category $c): string => $c->parent_id ?? '');
        $build = static function (string $parent) use (&$build, $all): array {
            return $all->get($parent, collect())->map(static fn (Category $c): array => [
                'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'image_id' => $c->image_id,
                'seo_title' => $c->seo_title, 'seo_description' => $c->seo_description,
                'listings' => (int) $c->getAttribute('listings_count'), 'children' => $build($c->id),
            ])->values()->all();
        };

        return $build('');
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $work
     * @return T
     */
    private function write(\Closure $work): mixed
    {
        try {
            return DB::transaction(function () use ($work): mixed {
                $this->events->lock();

                return $work();
            });
        } catch (UniqueConstraintViolationException) {
            // ロックの外から同じ slug が入った場合の最後の守り
            throw ValidationException::withMessages(['slug' => 'この slug はすでに使われています。']);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validate(array $input, array $rules): array
    {
        $data = Validator::make($input, $rules, SeoText::messages(), ['name' => '名前', 'parent_id' => '親', 'categories' => 'カテゴリ', 'primary' => '主カテゴリ', ...SeoText::attributes()])->validate();

        return $data + (array_key_exists('parent_id', $rules) ? ['parent_id' => null] : []);
    }

    private function find(string $id, string $field): Category
    {
        return Category::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw ValidationException::withMessages([$field => 'カテゴリが見つかりません。']);
    }

    /**
     * テーマの区画で使っていれば断る（使っている所を店の人に見せる）
     */
    private function assertNotInThemes(Category $category): void
    {
        $usages = $this->themeUsage->places($category->id);

        if ($usages === []) {
            return;
        }

        $places = array_column($usages, 'place');
        $shown = implode('・', array_slice($places, 0, self::USAGES_SHOWN));
        $rest = count($places) - self::USAGES_SHOWN;
        $message = "このカテゴリは{$shown}".($rest > 0 ? "（ほか {$rest} か所）" : '').'で使われています。ページの編集でセクションからこのカテゴリを外してから削除してください。';

        if (in_array(true, array_column($usages, 'published_only'), true)) {
            $message .= '「公開中だけ」と書かれた場所は、下書きではすでに外してあるため、公開すると使われなくなります。';
        }

        throw ValidationException::withMessages(['category' => $message]);
    }

    private function assertSlugFree(string $slug): void
    {
        if (Category::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['slug' => 'この slug はすでに使われています。']);
        }
    }

    /**
     * 一番上を 1 段目とした深さ
     */
    private function depthOf(Category $category): int
    {
        return count($this->ancestorIds($category)) + 1;
    }

    /**
     * 親から一番上までの ID（木が壊れていても止まるよう、深さの上限で打ち切る）
     *
     * @return list<string>
     */
    private function ancestorIds(Category $category): array
    {
        $ids = [];
        $parentId = $category->parent_id;

        while ($parentId !== null && count($ids) <= Category::MAX_DEPTH) {
            $ids[] = $parentId;
            $parentId = Category::query()->whereKey($parentId)->value('parent_id');
        }

        return $ids;
    }

    /**
     * 自分を 1 とした、下へ何段あるか
     */
    private function heightOf(Category $category): int
    {
        $height = 1;
        $level = [$category->id];

        while ($level !== [] && $height <= Category::MAX_DEPTH) {
            $level = Category::query()->whereIn('parent_id', $level)->pluck('id')->all();
            $height += $level === [] ? 0 : 1;
        }

        return $height;
    }

    private function renumber(?string $parentId): void
    {
        foreach (Category::query()->where('parent_id', $parentId)->orderBy('position')->orderBy('id')->pluck('id') as $position => $id) {
            Category::query()->whereKey($id)->update(['position' => $position]);
        }
    }
}
