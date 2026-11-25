<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Admin\Models\Operator;
use App\Core\Event\Models\DomainEvent;
use App\Core\Listing\Models\Seller;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\OperatorSession;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 管理画面の商品カテゴリ。検査は CategoryService が持ち、ここでは API として通ることと境界を確かめる。
 */
final class CategoryAdminTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;
    use WithTenants;

    private Operator $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAdminHost();
    }

    protected function tearDown(): void
    {
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_the_tree_is_managed_through_the_api(): void
    {
        $this->tenant('caa');
        $this->signIn();
        $base = '/api/tenants/caa/categories';

        $this->send('GET', $base)->assertOk()->assertJsonPath('categories', [])->assertJsonPath('max_depth', 3);
        $tops = $this->add('caa', 'トップス', 'tops');
        $knit = $this->add('caa', 'ニット', 'knit', $tops);
        $outer = $this->add('caa', 'アウター', 'outer');

        $this->send('GET', $base)->assertJsonPath('categories.0.slug', 'tops')->assertJsonPath('categories.0.children.0.slug', 'knit')
            ->assertJsonPath('categories.1.slug', 'outer');

        // 入力の誤りは 422（検査はサービス）
        $this->send('POST', $base, ['name' => 'x', 'slug' => 'Tops!'])->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->send('POST', $base, ['name' => 'x', 'slug' => 'tops'])->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->send('POST', $base, ['name' => '', 'slug' => 'empty'])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->send('PATCH', "{$base}/{$knit}", ['name' => 'ニット・セーター'])->assertOk()->assertJsonPath('categories.0.children.0.name', 'ニット・セーター');
        $this->send('POST', "{$base}/{$tops}/move", ['parent_id' => $knit])->assertStatus(422)->assertJsonValidationErrors('parent_id');
        $this->send('POST', "{$base}/{$knit}/move", ['parent_id' => $outer])->assertOk()->assertJsonPath('categories.1.children.0.slug', 'knit');
        $this->send('POST', "{$base}/order", ['parent_id' => null, 'ids' => [$outer, $tops]])->assertOk()->assertJsonPath('categories.0.slug', 'outer');
        $this->send('POST', "{$base}/order", ['parent_id' => null, 'ids' => [$outer]])->assertStatus(422)->assertJsonValidationErrors('ids');

        $this->send('DELETE', "{$base}/{$outer}")->assertStatus(422)->assertJsonValidationErrors('category');
        $this->send('DELETE', "{$base}/{$knit}")->assertOk();
        $this->send('DELETE', "{$base}/{$knit}")->assertNotFound();

        $this->inside('caa', function (): void {
            $event = DomainEvent::query()->where('type', 'category.created')->orderBy('sequence')->firstOrFail();
            $this->assertSame('operator:'.$this->operator->id, $event->actor);
        });
    }

    public function test_a_listing_gets_categories_and_shows_them_in_its_detail(): void
    {
        $this->tenant('cab');
        $this->signIn();
        $tops = $this->add('cab', 'トップス', 'tops');
        $knit = $this->add('cab', 'ニット', 'knit', $tops);
        $listing = $this->listing('cab');
        $path = "/api/tenants/cab/listings/{$listing}/categories";

        $this->send('PUT', $path, ['categories' => [$tops, $knit], 'primary' => $knit])->assertOk()
            ->assertJsonPath('listing.categories', [['id' => $knit, 'name' => 'ニット', 'primary' => true], ['id' => $tops, 'name' => 'トップス', 'primary' => false]]);
        $this->send('GET', "/api/tenants/cab/listings/{$listing}")->assertJsonPath('listing.categories.0.primary', true);
        $this->send('GET', '/api/tenants/cab/categories')->assertJsonPath('categories.0.listings', 1);

        $this->send('PUT', $path, ['categories' => [$tops], 'primary' => $knit])->assertStatus(422)->assertJsonValidationErrors('primary');
        $this->send('PUT', $path, ['categories' => 'tops', 'primary' => null])->assertStatus(422)->assertJsonValidationErrors('categories');
        $this->send('PUT', $path, ['categories' => [['nested' => 1]], 'primary' => null])->assertStatus(422);
        $this->send('PUT', $path, ['categories' => [], 'primary' => null])->assertOk()->assertJsonPath('listing.categories', []);
    }

    public function test_categories_stay_inside_their_tenant_and_need_a_signed_in_operator(): void
    {
        $this->tenant('cac');
        $this->tenant('cad');
        $this->signIn();
        $knit = $this->add('cac', 'ニット', 'knit');
        $listing = $this->listing('cad');

        // 別の店からは、ID を知っていても触れない
        $this->send('PATCH', "/api/tenants/cad/categories/{$knit}", ['name' => 'x'])->assertNotFound();
        $this->send('DELETE', "/api/tenants/cad/categories/{$knit}")->assertNotFound();
        $this->send('POST', '/api/tenants/cad/categories', ['name' => '子', 'slug' => 'child', 'parent_id' => $knit])->assertStatus(422)->assertJsonValidationErrors('parent_id');
        $this->send('PUT', "/api/tenants/cad/listings/{$listing}/categories", ['categories' => [$knit], 'primary' => null])->assertStatus(422);
        // 同じ slug を別の店で使える
        $this->add('cad', 'ニット', 'knit');

        $this->operator->update(['disabled_at' => now()]);
        $this->send('POST', '/api/tenants/cac/categories', ['name' => 'x', 'slug' => 'x'])->assertUnauthorized();
        $this->sessionId = null;
        $this->send('GET', '/api/tenants/cac/categories')->assertUnauthorized();
        $this->send('DELETE', "/api/tenants/cac/categories/{$knit}")->assertUnauthorized();
        $this->inside('cac', fn () => $this->assertSame(1, DomainEvent::query()->where('type', 'like', 'category.%')->count()));
    }

    private function add(string $tenant, string $name, string $slug, ?string $parent = null): string
    {
        $tree = $this->send('POST', "/api/tenants/{$tenant}/categories", ['name' => $name, 'slug' => $slug, 'parent_id' => $parent])->assertCreated()->json('categories');
        $this->assertIsArray($tree);
        $find = static function (array $nodes) use (&$find, $slug): ?string {
            foreach ($nodes as $node) {
                if ($node['slug'] === $slug) {
                    return $node['id'];
                }
                if (($id = $find($node['children'])) !== null) {
                    return $id;
                }
            }

            return null;
        };

        return (string) $find($tree);
    }

    private function listing(string $tenant): string
    {
        $sellerId = $this->inside($tenant, fn (): string => (string) Seller::query()->value('id'));

        return (string) $this->send('POST', "/api/tenants/{$tenant}/listings", ['seller_id' => $sellerId, 'kind' => 'product', 'title' => 'ニット', 'unit_amount' => 4900])
            ->assertCreated()->json('listing.id');
    }

    private function signIn(): void
    {
        [$this->operator] = $this->enrolledOperator();
    }
}
