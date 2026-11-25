<?php

declare(strict_types=1);

namespace App\Storefront\Http\Controllers;

use App\Core\Plugin\Manifest\AppManifest;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * App のスクリプトのファイルを、ショップと同じオリジンから配る（ADR 0034 の 1）。配るのは、店が使っている App の、
 * マニフェストで宣言した assets/ の中の .js だけ。経路の形（英小文字の名前と .js）・宣言・実体の道筋（assets/ の中のふつうのファイル）を
 * 3 重に確かめ、ディレクトリの外へ出る道筋（..・%2F・リンク）を断る。中身は審査した App のコードで、Web の処理からは変えられない（ADR 0006）
 */
final class AppAssetController
{
    // ?v= が今の中身の印と合えば長く持たせる（中身が変われば URL が変わる）。合わなければ短く
    private const int LONG = 31536000;

    private const int SHORT = 300;

    public function __invoke(Request $request, PluginRegistry $registry, PluginStates $states, string $plugin, string $file): Response
    {
        $script = $registry->has($plugin) ? $registry->get($plugin)->app()->script($file) : null;
        abort_if($script === null || ! $states->enabled($plugin), 404);

        // 登録の後に置き換えられていないか（assets/ の外を指すリンクなど）を、配るたびに確かめ直す
        $root = realpath(dirname($script->path));
        $real = realpath($script->path);
        abort_unless(
            $root !== false && $real !== false && basename($root) === AppManifest::ASSETS && str_starts_with($real, $root.DIRECTORY_SEPARATOR)
                && is_file($real) && str_ends_with($real, '.js') && (int) filesize($real) <= AppManifest::MAX_SCRIPT_BYTES,
            404,
        );

        $content = (string) file_get_contents($real);
        $version = substr(hash('sha256', $content), 0, 12);

        $response = response()->noContent(200)
            ->setEtag($version)
            ->setPublic()
            ->setMaxAge($request->query('v') === $version ? self::LONG : self::SHORT);

        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response->setContent($content)->withHeaders([
            'Content-Type' => 'text/javascript; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            // ほかのサイトからこのファイルを読み込ませない
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }
}
