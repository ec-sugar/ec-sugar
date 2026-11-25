<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Models\Tenant;
use App\Storefront\Postal\PostalLookup;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * 郵便番号から住所。外の API（zipcloud）は本物を呼ばず、返り方を作って試す。止まっても・おかしな中身でも、注文の入力を止めない。
 */
final class PostalTest extends TestCase
{
    use DatabaseTruncation;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite(); // 「見つからない」はテーマの画面。ビルドの成果物に頼らない（CI の PHP のテストはビルドしない）
        Cache::store()->flush();
        $this->tenant = Tenant::create(['id' => 'pst', 'name' => '店']);
        $this->tenant->domains()->create(['domain' => 'pst.localhost']);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        $this->tenant->delete();
        parent::tearDown();
    }

    public function test_an_address_is_found_cached_and_only_the_code_is_sent(): void
    {
        Http::fake(['zipcloud.ibsnet.co.jp/*' => Http::response(['status' => 200, 'message' => null, 'results' => [
            ['address1' => '東京都', 'address2' => '渋谷区', 'address3' => '神宮前', 'kana1' => 'ﾄｳｷｮｳﾄ', 'prefcode' => '13', 'zipcode' => '1500001'],
        ]])]);

        $this->lookup('1500001')->assertOk()
            ->assertJsonPath('addresses', [['prefecture' => '東京都', 'city' => '渋谷区', 'town' => '神宮前']]);

        // 2 回目は覚えた答え。テストのキャッシュ（array）はテナントの出入りで作り直されるので、1 つのテナントの中で確かめる（本番の Redis はまたいで残る）
        tenancy()->initialize($this->tenant);
        $lookup = app(PostalLookup::class);
        $first = $lookup->find('1500001');
        $this->assertEquals($first, $lookup->find('1500001'));
        $this->assertSame('神宮前', $first[0]->town ?? null);
        tenancy()->end();

        // 外へは 2 回（最初の画面の呼び出しと、テナントに入り直したあとの 1 回）。送るのは郵便番号だけ
        Http::assertSentCount(2);
        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://zipcloud.ibsnet.co.jp/api/search?zipcode=1500001');
    }

    public function test_failures_and_strange_answers_give_an_empty_list(): void
    {
        $cases = [
            '見つからない' => Http::response(['status' => 200, 'message' => null, 'results' => null]),
            '止まっている' => Http::response('Service Unavailable', 503),
            'JSON でない' => Http::response('<html>error</html>', 200),
            'スクリプト入り' => Http::response(['results' => [['address1' => '東京都', 'address2' => '<script>alert(1)</script>', 'address3' => '']]]),
            '知らない都道府県' => Http::response(['results' => [['address1' => 'ニュー東京', 'address2' => '渋谷区', 'address3' => '']]]),
            '長すぎる' => Http::response(['results' => [['address1' => '東京都', 'address2' => str_repeat('あ', 101), 'address3' => '']]]),
            '形が違う' => Http::response(['results' => 'oops']),
        ];

        foreach ($cases as $name => $response) {
            Cache::store()->flush();
            Http::fake(['zipcloud.ibsnet.co.jp/*' => $response]);
            $this->assertSame([], $this->lookup('1000001')->assertOk()->json('addresses'), $name);
        }

        // つながらない・遅い
        Cache::store()->flush();
        Http::fake(['zipcloud.ibsnet.co.jp/*' => static fn () => throw new ConnectionException('timeout')]);
        $this->assertSame([], $this->lookup('1000001')->assertOk()->json('addresses'));
    }

    public function test_only_seven_digits_and_a_limited_number_of_requests(): void
    {
        Http::fake(['zipcloud.ibsnet.co.jp/*' => Http::response(['results' => null])]);

        foreach (['150-0001', '15000011', 'abcdefg', '１５００００１', '150000'] as $code) {
            $this->get('http://pst.localhost/postal/'.rawurlencode($code))->assertNotFound();
            tenancy()->end();
        }
        Http::assertNothingSent();

        for ($i = 0; $i < 30; $i++) {
            $this->lookup(sprintf('100%04d', $i));
        }
        $this->lookup('1009999')->assertStatus(429);
    }

    /**
     * @return TestResponse<Response>
     */
    private function lookup(string $code): TestResponse
    {
        $response = $this->getJson("http://pst.localhost/postal/{$code}");
        tenancy()->end();

        return $response;
    }
}
