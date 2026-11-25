<?php

declare(strict_types=1);

namespace App\Core\Plugin\OrderDocument;

use InvalidArgumentException;

/**
 * App が出す注文の書類の種類 1 つ（納品書・領収書など）。管理画面の注文の画面にボタンで出し、押すと新しいタブで印刷用のページを開く。
 * bulk は、注文の一覧で何件か選んで 1 つのページに続けて出せるか
 */
final readonly class OrderDocument
{
    public const int LABEL_MAX = 20;

    public function __construct(
        public string $key,
        public string $label,
        public bool $bulk = false,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $key) !== 1) {
            throw new InvalidArgumentException("注文の書類の名前「{$key}」は英小文字で始まる英小文字・数字・_ の 32 文字まで");
        }

        if ($label === '' || mb_strlen($label) > self::LABEL_MAX || preg_match('/\p{C}/u', $label) === 1) {
            throw new InvalidArgumentException("注文の書類「{$key}」の名前は 1〜".self::LABEL_MAX.' 文字の 1 行で');
        }
    }
}
