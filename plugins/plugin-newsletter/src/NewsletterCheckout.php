<?php

declare(strict_types=1);

namespace EcSugar\PluginNewsletter;

use App\Core\Crypto\BlindIndex;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Plugin\Contracts\CheckoutOptInHandler;
use App\Core\Plugin\Contracts\MarketingConsent;
use EcSugar\PluginNewsletter\Models\NewsletterSubscriber;

/**
 * 注文の入力の「お知らせのメールを受け取る」（核の口）。切り替えはいつも選んでいない状態から核が出し、選んだ人の注文の確定の後にだけ呼ばれる。
 * 会員（ログインした会員のメールが注文のメールと同じ）はすぐ届ける。ほかは確かめのメールを送る（ダブルオプトイン）。同意の経路は checkout。
 * 同意の記録を持つ App として、ほかの App（「カゴ落ちのお知らせ」など）の「受け取りに同意しているか」「止める」にも答える（核の口 MarketingConsent。ADR 0067）
 */
final class NewsletterCheckout implements CheckoutOptInHandler, MarketingConsent
{
    public function label(): string
    {
        return 'ショップからのお知らせ（新商品・セールなど）をメールで受け取る';
    }

    public function note(): string
    {
        return '配信は、メールのリンクからいつでも停止できます。会員でない方には、登録の確認メールをお送りします。';
    }

    public function accepted(string $email, ?string $memberId, string $transactionId): void
    {
        $member = $memberId === null ? null : CustomerAccount::query()->find($memberId);
        $member = $member !== null && $member->member_since !== null && $member->disabled_at === null && mb_strtolower($member->email) === mb_strtolower(trim($email)) ? $member : null;

        app(NewsletterSubscribers::class)->subscribe($email, $member, 'checkout', null);
    }

    /**
     * 届けている人（確かめ済みで止めていない）のメールか。確かめ待ち・止めた人は同意していないと答える
     */
    public function consents(string $email): bool
    {
        return NewsletterSubscriber::query()->where('email_index', BlindIndex::of('email', $email))->where('status', SubscriberStatus::Active->value)->exists();
    }

    public function withdraw(string $email, string $via): bool
    {
        return app(NewsletterSubscribers::class)->withdraw($email, $via);
    }
}
