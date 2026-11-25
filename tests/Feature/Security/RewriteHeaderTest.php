<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\OperatorSession;
use Tests\TestCase;

/**
 * 経路を書き換えるヘッダ（X-Original-URL / X-Rewrite-URL）を信用しないこと（攻撃テストで ZAP が指摘した「403 回避」の誤検知の裏取り）。
 * これを信用すると、公開の経路に保護された経路の名前を書いて認可（ログイン・権限の確認）をすり抜けられる。
 * IIS 向けのこのヘッダを Laravel / Symfony は見ないが、将来それを見る部品が混ざったら気づけるように固定する。
 */
final class RewriteHeaderTest extends TestCase
{
    use DatabaseTruncation;
    use OperatorSession;

    private const array REWRITE_HEADERS = [
        'X-Original-URL' => '/injected/elsewhere',
        'X-Rewrite-URL' => '/injected/elsewhere',
        'X-Original-Url' => '/injected/elsewhere',
    ];

    public function test_rewrite_headers_do_not_change_the_resolved_path(): void
    {
        Route::middleware('web')->get('/_rw_probe', static fn (Request $request): array => [
            'path' => $request->path(),
            'uri' => $request->getRequestUri(),
        ]);

        foreach (self::REWRITE_HEADERS as $header => $value) {
            $this->getJson('http://localhost/_rw_probe', [$header => $value])
                ->assertOk()
                ->assertJson(['path' => '_rw_probe', 'uri' => '/_rw_probe']);
        }
    }

    public function test_rewrite_headers_cannot_reach_a_protected_route_from_a_public_one(): void
    {
        $this->configureAdminHost();
        $this->withoutVite();

        // ログインしていない運営が、公開のログイン画面に「本当は /api/tenants を見たい」と書いても、保護された API には届かない
        foreach (self::REWRITE_HEADERS as $header => $value) {
            $this->get('http://'.config('admin.domain').'/login', [$header => '/api/tenants'])
                ->assertOk()
                ->assertSee('id="app"', false);
            $this->getJson('http://'.config('admin.domain').'/api/tenants', [$header => '/login'])
                ->assertUnauthorized();
        }

        // 念のため、正しくログインした運営でも、書き換えヘッダで届く経路は変わらない（ログインは別の経路の話にならない）
        $this->enrolledOperator();
        $this->getJson('http://'.config('admin.domain').'/api/tenants', ['X-Original-URL' => '/api/members'])
            ->assertOk()
            ->assertJsonStructure(['tenants']);
    }
}
