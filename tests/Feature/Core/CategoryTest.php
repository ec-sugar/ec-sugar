<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Catalog\Models\Category;
use App\Core\Catalog\Services\CategoryService;
use App\Core\Event\Models\DomainEvent;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\Seller;
use App\Models\Tenant;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 商品カテゴリ。木の形（深さ 3 段・循環なし）、並び、商品との付け外し（主カテゴリ 1 つ）。
 */
final class CategoryTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<Tenant> */
    private array $tenants = [];

    private CategoryService $categories;

    protected function setUp(): void
    {
        parent::setUp();
        $this->categories = app(CategoryService::class);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        foreach ($this->tenants as $tenant) {
            $tenant->delete();
        }

        parent::tearDown();
    }

    public function test_a_tree_of_up_to_three_levels_in_order(): void
    {
        $this->inside('cta', function (): void {
            $tops = $this->create('トップス', 'tops');
            $bottoms = $this->create('ボトムス', 'bottoms');
            $knit = $this->create('ニット', 'knit', $tops);
            $shirt = $this->create('シャツ', 'shirts', $tops);
            $cardigan = $this->create('カーディガン', 'cardigan', $knit);

            $this->assertSame([0, 1], [$tops->position, $bottoms->position]);
            $this->assertSame([0, 1], [$knit->position, $shirt->position]);

            // 4 段目は作れない
            $this->assertRefused(fn () => $this->create('薄手', 'thin', $cardigan), 'parent_id');

            // 並べ替えは、同じ親の子をすべて 1 回ずつ
            $this->categories->reorder($tops->id, [$shirt->id, $knit->id]);
            $this->assertSame(['shirts', 'knit'], array_column($this->categories->tree()[0]['children'], 'slug'));
            $this->assertRefused(fn () => $this->categories->reorder($tops->id, [$shirt->id]), 'ids');
            $this->assertRefused(fn () => $this->categories->reorder($tops->id, [$shirt->id, $shirt->id]), 'ids');
            $this->assertRefused(fn () => $this->categories->reorder($tops->id, [$shirt->id, $bottoms->id]), 'ids');
            $this->categories->reorder(null, [$bottoms->id, $tops->id]);
            $this->assertSame(['bottoms', 'tops'], array_column($this->categories->tree(), 'slug'));

            // 名前と slug。slug は店の中で重ならない、形が決まっている
            $this->categories->update($shirt, ['name' => '  シャツ・ブラウス  ', 'slug' => 'shirts-blouses']);
            $this->assertSame('シャツ・ブラウス', $shirt->fresh()?->name);
            $this->assertRefused(fn () => $this->categories->update($shirt, ['slug' => 'knit']), 'slug');
            foreach (['Tops', 'tops_1', '-tops', 'tops-', 'ト', '../x', str_repeat('a', 61)] as $slug) {
                $this->assertRefused(fn () => $this->create('x', $slug), 'slug');
            }
            $this->assertRefused(fn () => $this->create("1 行目\n2 行目", 'two-lines'), 'name');
            $this->assertRefused(fn () => $this->create('', 'empty'), 'name');
            $this->assertRefused(fn () => $this->create('x', 'unknown-parent', null, strtolower((string) Str::ulid())), 'parent_id');

            $this->assertSame(
                ['category.created', 'category.created', 'category.created', 'category.created', 'category.created', 'category.reordered', 'category.reordered', 'category.updated'],
                DomainEvent::query()->where('type', 'like', 'category.%')->orderBy('sequence')->pluck('type')->all(),
            );
        });
    }

    public function test_moving_keeps_the_tree_without_cycles_or_too_many_levels(): void
    {
        $this->inside('ctb', function (): void {
            $tops = $this->create('トップス', 'tops');
            $knit = $this->create('ニット', 'knit', $tops);
            $cardigan = $this->create('カーディガン', 'cardigan', $knit);
            $outer = $this->create('アウター', 'outer');
            $coat = $this->create('コート', 'coat', $outer);

            // 自分の下へは動かせない（循環）
            $this->assertRefused(fn () => $this->categories->move($tops, $cardigan->id), 'parent_id');
            $this->assertRefused(fn () => $this->categories->move($tops, $tops->id), 'parent_id');
            // 下に何も無いカテゴリを自分の下へ（深さの確認では止まらない。循環の確認で断る）
            $this->assertRefused(fn () => $this->categories->move($coat, $coat->id), 'parent_id');
            // 下も含めて 3 段を超える先へは動かせない（トップス > ニット > カーディガン を コート の下へ）
            $this->assertRefused(fn () => $this->categories->move($tops, $coat->id), 'parent_id');
            $this->assertRefused(fn () => $this->categories->move($knit, $coat->id), 'parent_id');

            // ニット（下に 1 段）をアウターの下へ: アウター > ニット > カーディガン
            $this->categories->move($knit, $outer->id);
            $knit->refresh();
            $this->assertSame($outer->id, $knit->parent_id);
            $this->assertSame(1, $knit->position);
            // 一番上へ戻す
            $this->categories->move($knit, null);
            $this->assertSame(['tops', 'outer', 'knit'], array_column($this->categories->tree(), 'slug'));

            // DB でも、自分を親にはできない
            $this->assertRejectedByDatabase(fn () => DB::table('categories')->where('id', $tops->id)->update(['parent_id' => $tops->id]));
        });
    }

    public function test_deleting_needs_no_children_and_listings_lose_the_category(): void
    {
        $this->inside('ctc', function (): void {
            $tops = $this->create('トップス', 'tops');
            $knit = $this->create('ニット', 'knit', $tops);
            $shirt = $this->create('シャツ', 'shirts', $tops);
            $listing = $this->listing('ニット');
            $this->categories->assign($listing, [$knit->id, $tops->id]);

            $this->assertRefused(fn () => $this->categories->delete($tops), 'category');
            // DB でも、子があるカテゴリは消せない
            $this->assertRejectedByDatabase(fn () => DB::table('categories')->where('id', $tops->id)->delete(), '23503');

            $this->categories->delete($knit);
            $this->assertNull(Category::query()->find($knit->id));
            $this->assertSame(0, $shirt->fresh()?->position, '残りが詰めて並ぶ');
            $this->assertSame([$tops->id], $listing->categories()->pluck('categories.id')->all());
            $event = DomainEvent::query()->where('type', 'category.deleted')->sole();
            $this->assertSame(1, $event->payload['listings']);
        });
    }

    public function test_a_listing_has_categories_with_one_primary(): void
    {
        $this->inside('ctd', function (): void {
            $tops = $this->create('トップス', 'tops');
            $knit = $this->create('ニット', 'knit', $tops);
            $sale = $this->create('セール', 'sale');
            $listing = $this->listing('ベージュのニット');

            // 主を省くと最初のもの
            $this->categories->assign($listing, [$knit->id, $sale->id]);
            $this->assertSame($knit->id, $this->primaryOf($listing));
            $this->categories->assign($listing, [$knit->id, $sale->id], $sale->id);
            $this->assertSame($sale->id, $this->primaryOf($listing));
            // 同じ内容なら記録しない
            $this->categories->assign($listing, [$sale->id, $knit->id], $sale->id);
            $this->assertSame(2, DomainEvent::query()->where('type', 'listing.categories_changed')->count());

            $this->assertRefused(fn () => $this->categories->assign($listing, [$knit->id], $sale->id), 'primary');
            $this->assertRefused(fn () => $this->categories->assign($listing, [$knit->id, $knit->id]), 'categories.1');
            $this->assertRefused(fn () => $this->categories->assign($listing, [strtolower((string) Str::ulid())]), 'categories');
            $this->assertRefused(fn () => $this->categories->assign($listing, ['1 or 1=1']), 'categories.0');
            $many = [];
            for ($i = 0; $i < CategoryService::MAX_PER_LISTING + 1; $i++) {
                $many[] = $this->create("c{$i}", "c{$i}")->id;
            }
            $this->assertRefused(fn () => $this->categories->assign($listing, $many), 'categories');
            $this->assertSame($sale->id, $this->primaryOf($listing), '断ったときは前のまま');

            // 外す
            $this->categories->assign($listing, []);
            $this->assertSame(0, $listing->categories()->count());

            // DB でも、主カテゴリは 1 つまで
            $this->categories->assign($listing, [$knit->id]);
            $this->assertRejectedByDatabase(fn () => DB::table('listing_categories')->insert(['listing_id' => $listing->id, 'category_id' => $sale->id, 'is_primary' => true]), '23505');
        });
    }

    public function test_categories_stay_inside_their_tenant(): void
    {
        $knitId = $this->inside('cte', fn (): string => $this->create('ニット', 'knit')->id);

        $this->inside('ctf', function () use ($knitId): void {
            // 同じ slug を別の店で使える。別の店のカテゴリの ID は、この店には無い
            $this->create('ニット', 'knit');
            $this->assertRefused(fn () => $this->create('子', 'child', null, $knitId), 'parent_id');
            $this->assertRefused(fn () => $this->categories->assign($this->listing('x'), [$knitId]), 'categories');
        });
    }

    private function create(string $name, string $slug, ?Category $parent = null, ?string $parentId = null): Category
    {
        return $this->categories->create(['name' => $name, 'slug' => $slug, 'parent_id' => $parentId ?? $parent?->id], 'test');
    }

    private function listing(string $title): Listing
    {
        return Listing::create(['seller_id' => Seller::query()->value('id'), 'kind' => 'product', 'title' => $title, 'unit_amount' => 4900, 'status' => ListingStatus::Active]);
    }

    private function primaryOf(Listing $listing): ?string
    {
        $id = DB::table('listing_categories')->where('listing_id', $listing->id)->where('is_primary', true)->value('category_id');

        return $id === null ? null : (string) $id;
    }

    private function assertRefused(Closure $action, string $field): void
    {
        try {
            $action();
            $this->fail("{$field} が断られなかった");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), (string) json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
    }

    private function assertRejectedByDatabase(Closure $write, string $code = '23514'): void
    {
        try {
            DB::transaction(static fn () => $write());
        } catch (QueryException $e) {
            $this->assertSame($code, $e->getCode(), $e->getMessage());

            return;
        }

        $this->fail('DB が書き込みを拒まなかった');
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function inside(string $id, Closure $work): mixed
    {
        $tenant = Tenant::create(['id' => $id, 'name' => "店 {$id}"]);
        $this->tenants[] = $tenant;
        tenancy()->initialize($tenant);

        try {
            return $work();
        } finally {
            tenancy()->end();
        }
    }
}
