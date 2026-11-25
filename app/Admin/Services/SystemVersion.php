<?php

declare(strict_types=1);

namespace App\Admin\Services;

use DateTimeImmutable;

/**
 * 動いている EC-SUGAR のバージョン（ADR 0031）。イメージを作るときに焼き込んだコミットの印と日時を、形を確かめてから返す。
 * 運営にだけ見せる（ログインの前の誰でも見られる所には出さない）。
 */
final class SystemVersion
{
    /**
     * @return array{revision: ?string, short: ?string, date: ?string}
     */
    public static function present(): array
    {
        $revision = config('app.revision');
        $revision = is_string($revision) && preg_match('/\A[0-9a-f]{40}\z/', $revision) === 1 ? $revision : null;

        $date = config('app.revision_date');
        $date = $revision !== null && is_string($date) && DateTimeImmutable::createFromFormat(DATE_ATOM, $date) !== false ? $date : null;

        return ['revision' => $revision, 'short' => $revision === null ? null : substr($revision, 0, 12), 'date' => $date];
    }
}
