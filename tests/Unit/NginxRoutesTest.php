<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * 拡張子の付いたショップの経路（店の見た目の CSS など）が、nginx で静的のファイルとして 404 にならないこと。
 * nginx の設定は .css・.js などの拡張子を静的のファイルとして扱う（try_files $uri =404）ので、PHP が配る経路は、
 * その頭の場所（location ^~ /<頭>/）を開発用と本番用の両方に置く。11-12 に /theme/style-<印>.css が本番で 404 だった（PHP のテストは nginx を通らない）
 */
final class NginxRoutesTest extends TestCase
{
    private const string STATIC = '/\.(css|js|woff2?|svg|png|jpe?g|webp|ico)$/';

    public function test_every_route_with_a_static_extension_is_sent_to_php_by_both_nginx_configs(): void
    {
        $prefixes = [];

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (preg_match(self::STATIC, $route->uri()) === 1) {
                $prefixes[explode('/', $route->uri())[0]] = $route->uri();
            }
        }

        $this->assertArrayHasKey('theme', $prefixes, '店の見た目の CSS の経路が見つからない（見張りが空回りしていないか）');

        foreach (['docker/nginx/default.conf', 'docker/nginx/prod.conf'] as $file) {
            $conf = (string) file_get_contents(base_path($file));

            foreach ($prefixes as $prefix => $uri) {
                $this->assertMatchesRegularExpression('#location \^~ /'.preg_quote($prefix, '#').'/ \{\s*try_files \$uri /index\.php\?\$query_string;#', $conf, "{$file}: {$uri} が静的のファイルの指定に当たって 404 になる");
            }
        }
    }

    /**
     * PHP が答える /.well-known/ の経路（AI エージェントの OAuth のメタデータ。ADR 0101）は、nginx の静的のファイルだけの場所
     * （location ^~ /.well-known/）に当たらず、PHP へ回る。どの経路も /.well-known/oauth- で始まる
     */
    public function test_well_known_routes_answered_by_php_are_sent_to_php_by_both_nginx_configs(): void
    {
        $uris = [];

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (str_starts_with($route->uri(), '.well-known/')) {
                $uris[] = $route->uri();
            }
        }

        $this->assertContains('.well-known/oauth-authorization-server', $uris, 'OAuth のメタデータの経路が見つからない（見張りが空回りしていないか）');

        foreach ($uris as $uri) {
            $this->assertStringStartsWith('.well-known/oauth-', $uri, "{$uri}: nginx が PHP へ回すのは /.well-known/oauth- で始まるパスだけ");
        }

        foreach (['docker/nginx/default.conf', 'docker/nginx/prod.conf'] as $file) {
            $conf = (string) file_get_contents(base_path($file));
            $this->assertMatchesRegularExpression('#location \^~ /\.well-known/oauth- \{\s*try_files \$uri /index\.php\?\$query_string;#', $conf, "{$file}: OAuth のメタデータが静的のファイルの指定に当たって 404 になる");
        }
    }
}
