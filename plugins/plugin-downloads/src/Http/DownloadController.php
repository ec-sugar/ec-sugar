<?php

declare(strict_types=1);

namespace EcSugar\PluginDownloads\Http;

use App\Core\Party\Services\CustomerMerges;
use App\Core\Plugin\Services\ListingFileUploads;
use App\Core\Transaction\Models\Transaction;
use App\Storefront\Members\Member;
use App\Storefront\Orders\OrderAccess;
use App\Storefront\View\StorefrontView;
use Carbon\CarbonImmutable;
use EcSugar\PluginDownloads\DownloadFiles;
use EcSugar\PluginDownloads\DownloadGrants;
use EcSugar\PluginDownloads\DownloadLinks;
use EcSugar\PluginDownloads\DownloadRefused;
use EcSugar\PluginDownloads\Models\DownloadFile;
use EcSugar\PluginDownloads\Models\DownloadGrant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * お客さんのダウンロード（ADR 0084）。
 *
 * - 注文のダウンロードの画面（/p/downloads/orders/{注文}）: ログインした会員の自分の注文か、署名つきのリンク〔ご注文の確認の画面・メール〕で来て
 *   このセッションでご注文の確認の照会〔注文番号とメール〕を通した人だけ。ほかは、注文があるかを教えずにご注文の確認の照会へ
 * - 押す（POST）: 同じ確かめの後、錠の中で回数を数え、短い期限（5 分）の署名つきの道筋へ送る
 * - ファイル（/p/downloads/file/{リンク}）: 署名・期限・押したセッションで渡す（押した直後のブラウザの普通のダウンロード）。止めた・期限切れ・注文の取消は確かめ直す。
 *   Content-Disposition: attachment・X-Content-Type-Options: nosniff・保存させない・枠の中で開かせない。名前は店の人が上げた時の名前、中身の型は上げた時に判定したもの
 * - マイページの「ダウンロード」（会員だけ）
 */
final class DownloadController
{
    private const string MESSAGE = 'downloads_message';

    public const string LOOKUP = 'ダウンロードには、ご注文番号とメールアドレスでの確認が必要です（会員の方はログインしてください）。確認が済んだら、ご注文の画面の「ダウンロード」からお進みください。';

    public function __construct(
        private readonly StorefrontView $view,
        private readonly DownloadGrants $grants,
    ) {}

    public function order(Request $request, string $orderId): Response|RedirectResponse
    {
        $order = self::access($request, $orderId);

        if ($order === null) {
            return self::refuse();
        }

        $now = CarbonImmutable::now();
        $grants = DownloadGrant::query()->where('transaction_id', $order->id)->orderBy('created_at')->orderBy('id')->get();
        $files = DownloadFile::query()->whereIn('id', $grants->pluck('file_id'))->get()->keyBy('id');
        $items = [];

        foreach ($grants as $grant) {
            $file = $files->get($grant->file_id);

            try {
                $this->grants->assertServable($grant, $now);
                $reason = null;
            } catch (DownloadRefused $e) {
                $reason = $e->getMessage();
            }

            $items[] = [
                'name' => $file === null ? 'ファイル' : $file->name,
                'size' => self::size($file === null ? 0 : $file->bytes),
                'left' => max(0, $grant->max_count - $grant->count),
                'max' => $grant->max_count,
                'expires' => $grant->expires_at->timezone('Asia/Tokyo')->format('Y/m/d H:i'),
                'reason' => $reason,
                // 押す先も署名つき（照会を通した人が会員でなくても押せるように）。期限は画面を開いてから 1 時間
                'action' => $reason === null ? DownloadLinks::fetch($order->id, $grant->id, $now->addHour()) : null,
            ];
        }

        return $this->view->renderApp('downloads::order', [
            'number' => strtoupper($order->id),
            'items' => $items,
            'message' => self::message($request),
            'member' => OrderAccess::member($order),
        ]);
    }

    public function fetch(Request $request, string $orderId, string $grantId): RedirectResponse
    {
        $order = self::access($request, $orderId);

        if ($order === null) {
            return self::refuse();
        }

        $back = DownloadLinks::order($order->id, CarbonImmutable::now()->addHour());
        $grant = DownloadGrant::query()->whereKey(strtolower($grantId))->where('transaction_id', $order->id)->first();

        if ($grant === null) {
            return redirect($back)->with(self::MESSAGE, 'このファイルは見つかりませんでした。');
        }

        try {
            [$grant] = $this->grants->fetch($grant->id, CarbonImmutable::now());
        } catch (DownloadRefused $e) {
            return redirect($back)->with(self::MESSAGE, $e->getMessage());
        }

        return redirect(DownloadLinks::file($grant->id, $request->session()->getId()), 303);
    }

    public function file(Request $request, string $grantId): BinaryFileResponse|SymfonyResponse
    {
        // 署名・期限・店に加えて、押したセッションだけ（道筋を人に渡しても、ほかのブラウザでは受け取れない）
        if (! DownloadLinks::signed($request) || ! DownloadLinks::sameSession($request)) {
            abort(403, 'リンクの有効期限が切れました。ダウンロードのページからもう一度お進みください。');
        }

        $grant = DownloadGrant::query()->find(strtolower($grantId));
        $file = $grant === null ? null : DownloadFile::query()->find($grant->file_id);

        if ($grant === null || $file === null) {
            abort(404);
        }

        try {
            $this->grants->assertServable($grant, CarbonImmutable::now(), counting: false);
        } catch (DownloadRefused $e) {
            abort(410, $e->getMessage());
        }

        // 店の境界: 置き場の場所はこの店の頭の下だけ（表が書き換えられても、ほかの店のファイルを渡さない）
        $disk = ListingFileUploads::disk();

        if (! str_starts_with($file->path, DownloadFiles::folder().'/') || str_contains($file->path, '..') || ! $disk->exists($file->path)) {
            abort(404);
        }

        $response = new BinaryFileResponse($disk->path($file->path), 200, [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ], autoEtag: false, autoLastModified: false);
        // 名前は店の人が上げた時の名前（UTF-8）。英数字だけの古い仕組み向けの名前は download.<拡張子>
        $response->setContentDisposition('attachment', $file->name, 'download.'.$file->extension);

        return $response;
    }

    /**
     * マイページの「ダウンロード」（会員だけ。自分と、店の人がまとめた記録の注文の、新しい 30 件）
     */
    public function account(Request $request): Response|RedirectResponse
    {
        $member = Auth::guard('customer')->user();

        if (! $member instanceof Member || ! $member->belongsHere()) {
            return redirect()->route('storefront.account.login', ['return' => '/p/downloads/account']);
        }

        $orders = Transaction::query()->whereIn('customer_account_id', CustomerMerges::family($member->account))
            ->whereIn('id', DownloadGrant::query()->select('transaction_id'))
            ->orderByDesc('created_at')->limit(30)->get();

        return $this->view->renderApp('downloads::account', [
            'orders' => $orders->map(static fn (Transaction $order): array => [
                'number' => strtoupper($order->id),
                'placedAt' => $order->created_at->timezone('Asia/Tokyo')->format('Y/m/d'),
                'url' => DownloadLinks::order($order->id, CarbonImmutable::now()->addHour()),
            ])->all(),
        ]);
    }

    /**
     * 見てよい注文（会員の自分の注文か、署名つきの道筋で来て照会を通した注文）。ほかは null
     */
    private static function access(Request $request, string $orderId): ?Transaction
    {
        $order = Transaction::query()->where('kind', 'sale')->find(strtolower($orderId));

        if ($order === null) {
            return null;
        }

        return OrderAccess::member($order) || (DownloadLinks::signed($request) && OrderAccess::lookedUp($request, $order->id)) ? $order : null;
    }

    private static function refuse(): RedirectResponse
    {
        return redirect()->route('storefront.orders.lookup')->with('cart_error', self::LOOKUP);
    }

    private static function message(Request $request): ?string
    {
        $message = $request->session()->get(self::MESSAGE);

        return is_string($message) ? $message : null;
    }

    private static function size(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 * 1024 * 1024 => number_format($bytes / (1024 * 1024 * 1024), 1).' GB',
            $bytes >= 1024 * 1024 => number_format($bytes / (1024 * 1024), 1).' MB',
            default => max(1, (int) ceil($bytes / 1024)).' KB',
        };
    }
}
