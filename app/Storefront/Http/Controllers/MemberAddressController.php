<?php

declare(strict_types=1);

namespace App\Storefront\Http\Controllers;

use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\CustomerAddress;
use App\Core\Party\Services\CustomerAddresses;
use App\Core\Party\Services\CustomerException;
use App\Storefront\Checkout\CheckoutInput;
use App\Storefront\Members\AccountNotice;
use App\Storefront\Members\AddressView;
use App\Storefront\Members\Member;
use App\Storefront\Members\MemberPage;
use App\Storefront\View\StorefrontView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\ViewErrorBag;

/**
 * 会員の届け先（ADR 0044 の 3）。いくつも持て、いつもの届け先を注文の入力に最初から入れる。
 * 会員だけ（auth:customer）。ほかの会員の届け先の ID は、見つからないのと同じ答え。形の確かめは注文の入力と同じ決まり
 */
final class MemberAddressController
{
    public const string ACTOR = 'storefront';

    public function __construct(
        private readonly StorefrontView $view,
        private readonly CustomerAddresses $addresses,
    ) {}

    public function index(Request $request): Response
    {
        $account = $this->account($request);

        return MemberPage::private($this->view->renderCore('account-addresses', [
            'addresses' => $this->addresses->list($account)->map(static fn (CustomerAddress $a): array => AddressView::of($a))->all(),
            'limit' => CustomerAddresses::LIMIT,
            'form' => $this->form($request, null),
        ]));
    }

    public function edit(Request $request, string $addressId): Response|RedirectResponse
    {
        $address = $this->addresses->find($this->account($request), $addressId);

        if ($address === null) {
            return $this->missing();
        }

        return MemberPage::private($this->view->renderCore('account-address', ['address' => AddressView::of($address), 'form' => $this->form($request, $address)]));
    }

    public function store(Request $request): RedirectResponse
    {
        $address = CheckoutInput::address(['address' => $request->input('address')]);

        return $this->attempt(fn () => $this->addresses->add($this->account($request), $address, self::ACTOR), 'お届け先を追加しました。', $this->account($request), 'お届け先を追加しました');
    }

    public function update(Request $request, string $addressId): RedirectResponse
    {
        $address = CheckoutInput::address(['address' => $request->input('address')]);

        return $this->attempt(fn () => $this->addresses->update($this->account($request), $addressId, $address, self::ACTOR), 'お届け先を変更しました。', $this->account($request), 'お届け先を変更しました');
    }

    public function destroy(Request $request, string $addressId): RedirectResponse
    {
        return $this->attempt(fn () => $this->addresses->remove($this->account($request), $addressId, self::ACTOR), 'お届け先を削除しました。');
    }

    public function makeDefault(Request $request, string $addressId): RedirectResponse
    {
        return $this->attempt(fn () => $this->addresses->makeDefault($this->account($request), $addressId, self::ACTOR), 'いつものお届け先に設定しました。');
    }

    /**
     * 入力欄の値（誤りで戻ったときは入れた値、直す画面では今の値）と誤りの文
     *
     * @return array{values: array<string, string>, errors: array<string, string>}
     */
    private function form(Request $request, ?CustomerAddress $address): array
    {
        $old = $request->session()->getOldInput('address');
        $current = $address->address ?? [];
        $values = [];

        foreach (CheckoutInput::ADDRESS_FIELDS as $field) {
            $value = is_array($old) ? ($old[$field] ?? '') : ($current[$field] ?? '');
            $values[$field] = is_string($value) ? $value : '';
        }

        $bag = $request->session()->get('errors');
        $errors = [];

        if ($bag instanceof ViewErrorBag) {
            foreach (CheckoutInput::ADDRESS_FIELDS as $field) {
                $message = $bag->first("address.{$field}");
                if ($message !== '') {
                    $errors[$field] = $message;
                }
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * @param  ?string  $notice  済んだ後に会員のメールへ送る一言（届け先を足した・直した。住所は載せない。ADR 0070 の見直し 中 1）
     */
    private function attempt(callable $work, string $done, ?CustomerAccount $account = null, ?string $notice = null): RedirectResponse
    {
        try {
            $work();
        } catch (CustomerException $e) {
            return redirect()->route('storefront.account.addresses')->with('cart_error', $e->getMessage());
        }

        // 届け先は乗っ取られた時に品物のお届け先になるので、止めずに本人へ事後に知らせる（心当たりが無ければお店へ）
        if ($account !== null && $notice !== null) {
            AccountNotice::send($account, $notice);
        }

        return redirect()->route('storefront.account.addresses')->with('cart_message', $done);
    }

    private function missing(): RedirectResponse
    {
        return redirect()->route('storefront.account.addresses')->with('cart_error', 'そのお届け先は見つかりません。');
    }

    private function account(Request $request): CustomerAccount
    {
        $member = $request->user('customer');

        if (! $member instanceof Member || ! $member->belongsHere()) {
            abort(401);
        }

        return $member->account;
    }
}
