<?php

declare(strict_types=1);

namespace App\Core\Mail;

use RuntimeException;
use Throwable;

/**
 * 失敗した処理の記録に渡す、メールアドレスを伏せた例外（RedactedFailedJobs）。
 * 記録の部品は例外を文字列にして書くので、文字列にした形（クラス・文・場所・呼び出しの跡）を、伏せた形に置き換える
 */
final class RedactedJobFailure extends RuntimeException
{
    private readonly string $text;

    public function __construct(Throwable $original)
    {
        parent::__construct(RedactedFailedJobs::redact($original->getMessage()), (int) $original->getCode());
        $this->text = RedactedFailedJobs::redact((string) $original);
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
