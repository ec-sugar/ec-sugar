<?php

declare(strict_types=1);

namespace App\Storefront\Apps;

use InvalidArgumentException;

/**
 * 差し込むスクリプト 1 つ（ADR 0034 の 1）。核が <script src defer> の要素を作り、値は data- の印だけで渡す。
 * src は同じオリジンの App のファイルの経路だけ、印の名前は data- と英小文字・数字・ハイフンだけ（値はエスケープして出す）
 */
final readonly class AppTag
{
    /**
     * @param  array<string, string>  $data  印の名前 => 値
     */
    public function __construct(
        public string $plugin,
        public string $src,
        public array $data,
    ) {
        if (! str_starts_with($src, '/apps/') || str_starts_with($src, '//')) {
            throw new InvalidArgumentException('差し込めるのは同じオリジンの App のファイルだけです');
        }

        foreach (array_keys($data) as $name) {
            if (preg_match('/\Adata-[a-z][a-z0-9-]{0,63}\z/', $name) !== 1) {
                throw new InvalidArgumentException("印の名前が不正です: {$name}");
            }
        }
    }
}
