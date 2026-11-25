<?php

declare(strict_types=1);

namespace App\Core\Plugin\Gift;

/**
 * 買う人に見せる贈り物の様子（ADR 0055 の 3）。核の部品 <x-storefront::gift-share> が、注文を受け付けた画面・ご注文の状況・
 * 注文の知らせのメールで描く。中身は App が作る。
 *
 * - status: preparing（お支払いの確認待ち）・waiting（受け取り待ち）・claimed（受け取り済み）・shipped（発送済み）・
 *   cancelling（取り消しの途中）・stopped（返金できず止めた）・cancelled（取り消した）・expired（期限で返金した）
 * - url: 受け取りのリンク（受け取り待ちの間だけ。持つ人は誰でも受け取れるので、App のタグを差し込む画面には渡さない）
 * - manageUrl: 作り直す・取り消すの画面（買う人だけが開ける形の道筋）
 * - expiresOn: 受け取りの期限（「2026年11月18日」）
 */
final readonly class GiftShare
{
    public const array STATUSES = ['preparing', 'waiting', 'claimed', 'shipped', 'cancelling', 'stopped', 'cancelled', 'expired'];

    /** LINE で送る（LINE の共有の画面。文と URL を text に入れる。アプリが無ければブラウザの画面になる） */
    private const string LINE_SHARE = 'https://line.me/R/share?text=';

    public function __construct(
        public string $status,
        public string $statusLabel,
        public ?string $url = null,
        public ?string $manageUrl = null,
        public ?string $expiresOn = null,
        public string $shopName = '',
    ) {
        if (! in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('贈り物の状態が違います');
        }
    }

    public function waiting(): bool
    {
        return $this->status === 'waiting';
    }

    /**
     * LINE で送るリンク（受け取りのリンクがあるときだけ）。JavaScript が無くてもリンクとして使える
     */
    public function lineUrl(): ?string
    {
        if ($this->url === null) {
            return null;
        }

        $text = '贈り物が届いています'.($this->shopName === '' ? '' : '（'.$this->shopName.'）').'。リンクを開いて、お届け先を入力してください。'."\n".$this->url;

        return self::LINE_SHARE.rawurlencode($text);
    }
}
