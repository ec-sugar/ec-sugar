<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers;

use App\Admin\Http\Middleware\AdminSecurityHeaders;
use App\Admin\Models\Operator;
use App\Admin\Services\OperatorAccess;
use App\Models\Tenant;
use App\Storefront\View\StorefrontUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * 管理画面の SPA の殻（HTML と、ビルド済みの JS / CSS への参照だけ）。
 *
 * 殻にはデータも秘密も入れない。画面に出すものは、ログインを確かめた API からだけ取る。
 * 殻はキャッシュさせない（ビルドを差し替えたら次の読み込みで新しい JS を指す）。
 *
 * ページの編集の画面（/tenants/{店}/theme/pages/{ページ}。ADR 0039 区切り 2）の殻だけ、その店のショップのオリジンを
 * 枠（iframe）の読み込み先として CSP に足す（真ん中の本物のプレビュー）。足すのはログイン中で、その店に入れる運営のときだけ
 * （ほかの店・ログインしていない人には、店のアドレスも枠も許さない）。SPA は画面を移る時に殻を読み直さないので、
 * 編集の画面へは文書ごと読み直して入る（resources/admin/router.js）
 */
final class ShellController
{
    private const string EDITOR = '#\Atenants/([a-z0-9][a-z0-9_-]{0,62})/theme/pages/[a-z][a-z0-9_-]{0,31}\z#';

    public function __invoke(Request $request, OperatorAccess $access): Response
    {
        $origin = $this->previewOrigin($request, $access);

        if ($origin !== null) {
            AdminSecurityHeaders::allowFrame($request, $origin);
        }

        return response()->view('admin')->header('Cache-Control', 'no-store');
    }

    private function previewOrigin(Request $request, OperatorAccess $access): ?string
    {
        $page = $request->route('page');

        if (! is_string($page) || preg_match(self::EDITOR, $page, $match) !== 1) {
            return null;
        }

        $operator = Auth::guard('operator')->user();

        if (! $operator instanceof Operator || ! $operator->isActive() || $access->levelFor($operator, $match[1]) === null) {
            return null;
        }

        $tenant = Tenant::query()->find($match[1]);

        return $tenant instanceof Tenant ? StorefrontUrls::of($tenant, '') : null;
    }
}
