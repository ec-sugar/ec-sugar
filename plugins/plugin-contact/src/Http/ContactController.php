<?php

declare(strict_types=1);

namespace EcSugar\PluginContact\Http;

use App\Core\Listing\Models\Listing;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Transaction\Models\Transaction;
use App\Storefront\Catalog\Catalog;
use App\Storefront\Members\Member;
use App\Storefront\View\StorefrontView;
use EcSugar\PluginContact\BotGuard;
use EcSugar\PluginContact\ContactInput;
use EcSugar\PluginContact\ContactInquiries;
use EcSugar\PluginContact\ContactKind;
use EcSugar\PluginContact\ContactLinks;
use EcSugar\PluginContact\ContactRefused;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ショップのお問い合わせのフォーム（App の画面。JavaScript を使わないフォーム）。
 * /p/contact（そのまま）・/p/contact/product/{商品}（商品の画面の「この商品について問い合わせる」から。ショップに出ている商品だけ付ける）・
 * /p/contact/order/{注文}（ご注文の画面の「この注文について問い合わせる」から。署名と期限と店の ID の合う道筋だけ注文を付ける）。
 * 送ると受け付けの画面（/p/contact/sent）へ。ボットよけ（見えない欄・書き始めの時刻）で落としたものにも同じ画面を返す（落としたことを教えない）
 */
final class ContactController
{
    private const string DONE = 'contact_done';

    private const string BASE = '/p/contact';

    public function __construct(
        private readonly StorefrontView $view,
        private readonly ContactInquiries $inquiries,
    ) {}

    public function show(Request $request): Response
    {
        return $this->render($request, null, null, null);
    }

    public function showProduct(Request $request, string $listingId): Response
    {
        $listing = self::listing($listingId);

        // 出ていない商品（下書き・終了・ほかの店の ID）は付けずに、ふつうのフォームを出す
        return $this->render($request, $listing, null, null);
    }

    public function showOrder(Request $request, string $orderId): Response
    {
        $order = self::order($request, $orderId);

        // 署名つきの道筋。参照元を渡さない・残さない・検索に載せないは、経路の宣言（secret-link）で核がまとめて掛ける
        return $this->render($request, null, $order, $order === null ? 'リンクの有効期限が切れたため、ご注文の情報を添えられませんでした。お手数ですが、ご注文番号をお問い合わせの内容に書いてお送りください。' : null);
    }

    public function submit(Request $request): RedirectResponse
    {
        return $this->receive($request, null, null, self::BASE);
    }

    public function submitProduct(Request $request, string $listingId): RedirectResponse
    {
        $listing = self::listing($listingId);

        return $this->receive($request, $listing, null, $listing === null ? self::BASE : ContactLinks::product($listing->id));
    }

    public function submitOrder(Request $request, string $orderId): RedirectResponse
    {
        $order = self::order($request, $orderId);

        // 署名の合わない道筋（書き換え・期限切れ・ほかの店）からは受け付けない（注文を黙って外さない）。書いた文は残して、ふつうのフォームへ
        if ($order === null) {
            return redirect(self::BASE)->withInput(self::kept($request))
                ->with('cart_error', 'リンクの有効期限が切れたため、ご注文の情報を添えられませんでした。お手数ですが、ご注文番号をお問い合わせの内容に書いてお送りください。');
        }

        return $this->receive($request, null, $order, ContactLinks::order($order->id));
    }

    public function sent(Request $request): Response|RedirectResponse
    {
        if ($request->session()->get(self::DONE) !== true) {
            return redirect(self::BASE);
        }

        return $this->view->renderApp('contact::done');
    }

    private function receive(Request $request, ?Listing $listing, ?Transaction $order, string $back): RedirectResponse
    {
        // ボットよけ: 落としたものも、受け付けた時と同じ画面へ（何も残さず、何も送らない）
        if (! BotGuard::human($request->input(BotGuard::WEBSITE), $request->input(BotGuard::STARTED))) {
            return redirect(self::BASE.'/sent')->with(self::DONE, true);
        }

        $member = $this->member($request);
        $input = ContactInput::from($request->input('kind'), $request->input('name'), $request->input('email'), $request->input('body'), $member?->email);

        if (is_array($input)) {
            return redirect($back)->withErrors($input)->withInput(self::kept($request));
        }

        try {
            $this->inquiries->receive($input, $listing?->id, $order?->id, $member, $request->ip());
        } catch (ContactRefused $refused) {
            return redirect($back)->withInput(self::kept($request))->with('cart_error', $refused->getMessage());
        }

        return redirect(self::BASE.'/sent')->with(self::DONE, true);
    }

    /**
     * フォームを描く。$notice はフォームの上の一言（注文を付けられなかった時など）
     */
    private function render(Request $request, ?Listing $listing, ?Transaction $order, ?string $notice): Response
    {
        $old = (array) $request->session()->getOldInput();
        $kind = match (true) {
            is_string($old['kind'] ?? null) && ContactKind::tryFrom($old['kind']) !== null => $old['kind'],
            $order !== null => ContactKind::Order->value,
            $listing !== null => ContactKind::Product->value,
            default => ContactKind::Other->value,
        };

        return $this->view->renderApp('contact::form', [
            'action' => match (true) {
                $order !== null => ContactLinks::order($order->id),
                $listing !== null => ContactLinks::product($listing->id),
                default => self::BASE,
            },
            'listing' => $listing,
            'orderNumber' => $order === null ? null : strtoupper($order->id),
            'kinds' => array_combine(array_map(static fn (ContactKind $k): string => $k->value, ContactKind::cases()), array_map(static fn (ContactKind $k): string => $k->label(), ContactKind::cases())),
            'memberEmail' => $this->member($request)?->email,
            'old' => [
                'kind' => $kind,
                'name' => self::cut($old['name'] ?? null, ContactInput::NAME_MAX),
                'email' => self::cut($old['email'] ?? null, ContactInput::EMAIL_MAX),
                'body' => self::cut($old['body'] ?? null, ContactInput::BODY_MAX),
            ],
            'notice' => $notice,
            'stamp' => BotGuard::stamp(),
            'limits' => ['name' => ContactInput::NAME_MAX, 'body' => ContactInput::BODY_MAX, 'email' => ContactInput::EMAIL_MAX],
        ]);
    }

    /**
     * 誤りで戻すときに残す欄（見えない欄と書き始めの印は残さない）
     *
     * @return array<string, mixed>
     */
    private static function kept(Request $request): array
    {
        return $request->only('kind', 'name', 'email', 'body');
    }

    private static function cut(mixed $value, int $max): string
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') ? mb_substr($value, 0, $max) : '';
    }

    /**
     * ショップに出ている商品（出ていなければ null）
     */
    private static function listing(string $listingId): ?Listing
    {
        $listingId = strtolower($listingId);

        return app(Catalog::class)->isPublished($listingId) ? Listing::query()->find($listingId) : null;
    }

    /**
     * 署名つきの道筋で来た、この店の物販の注文（署名が合わない・無ければ null）
     */
    private static function order(Request $request, string $orderId): ?Transaction
    {
        return ContactLinks::signed($request) ? Transaction::query()->whereKey(strtolower($orderId))->where('kind', 'sale')->first() : null;
    }

    private function member(Request $request): ?CustomerAccount
    {
        $member = $request->user('customer');

        return $member instanceof Member && $member->belongsHere() ? $member->account : null;
    }
}
