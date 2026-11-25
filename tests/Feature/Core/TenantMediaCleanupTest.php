<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Media\ForeignMediaRootException;
use App\Core\Media\MediaRoot;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\WithTenants;
use Tests\TestCase;

/**
 * 店を本当に作って消す流れ（TenantDeleted → DeleteTenantMedia）で、テストの店の後片付けが開発の写真を消さないことを確かめる。
 */
final class TenantMediaCleanupTest extends TestCase
{
    use DatabaseTruncation;
    use UsesScratchMedia;
    use WithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScratchMedia();
    }

    protected function tearDown(): void
    {
        $this->tearDownScratchMedia();
        $this->deleteTenants();

        parent::tearDown();
    }

    public function test_cleaning_up_a_test_shop_does_not_touch_the_development_photos(): void
    {
        // 開発の置き場（札つき）に、テストと同じ ID の店の写真がある
        $dev = $this->path('dev/media');
        $this->place($dev, MediaRoot::OWNER_FILE, MediaRoot::ownerFor('ec_t_', ''));
        $this->place($dev, 'tenants/mre/shop/h/large.webp');

        // テストの置き場で、同じ ID の店を作って消す
        $testing = $this->useMedia('testing/media-testing', defaultRoot: 'dev/media');
        $this->tenant('mre');
        $this->place($testing, 'tenants/mre/shop/h/large.webp');
        $this->deleteTenants();

        $this->assertDirectoryDoesNotExist("{$testing}/tenants/mre");
        $this->assertFileExists("{$dev}/tenants/mre/shop/h/large.webp");

        // 設定を忘れて開発の置き場を指しても、後片付けは断られ、開発の写真は残る（店と店の DB は消える）
        $this->useMedia('dev/media', defaultRoot: 'dev/media');
        $tenant = $this->tenant('mre');

        try {
            $this->deleteTenants();
            $this->fail('テストの後片付けで、開発の保存先の写真を消せてしまった');
        } catch (ForeignMediaRootException) {
            $this->tenants = [];
        }

        $this->assertFalse(Tenant::query()->whereKey($tenant->id)->exists());
        $this->assertFileExists("{$dev}/tenants/mre/shop/h/large.webp");
    }
}
