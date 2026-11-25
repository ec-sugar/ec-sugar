<?php

declare(strict_types=1);

namespace App\Core\Mail;

/**
 * 送るメールに付ける記録の札（ADR 0095）。メールのクラス（RecordsMail）が作り、送る直前（MessageSending）に送るメールへ結ぶ。
 *
 * - kind: <core か App の名前>.<何のメールか>（英小文字・数字・ハイフン・下線。例 core.order_paid・restock.available）
 * - transactionId: 関係する注文（無ければ空）
 * - bulk: 一斉の配信（メルマガの本番の配信）。送れなかった分だけを残す
 */
final readonly class MailTag
{
    public const string KIND_PATTERN = '/\A[a-z][a-z0-9-]{0,31}\.[a-z][a-z0-9_-]{0,31}\z/';

    public function __construct(
        public string $kind,
        public ?string $transactionId = null,
        public bool $bulk = false,
    ) {}

    public function valid(): bool
    {
        return preg_match(self::KIND_PATTERN, $this->kind) === 1
            && ($this->transactionId === null || preg_match('/\A[0-9a-z]{26}\z/', $this->transactionId) === 1);
    }
}
