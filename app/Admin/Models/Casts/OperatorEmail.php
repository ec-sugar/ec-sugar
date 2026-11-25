<?php

declare(strict_types=1);

namespace App\Admin\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * 運営のメールアドレスの列（ADR 0112）。暗号文（APP_KEY。Laravel の encrypted と同じ形）でも平文でも読める。
 *
 * 暗号化は 2 つのリリースに分ける（更新が失敗してイメージだけが前に戻っても、前のバージョンが DB の上で動けるように。ADR 0093 の 5 節）:
 *   段階 1      : 読むのは両方、書くのは平文。照合は email_index
 *   段階 2（今）: 書くのを暗号文にし、移行で今ある行を暗号化して、平文を DB が断る
 * 段階 2 の移行の後に段階 1 へ戻っても、読むのと探すのは両方の形で動く
 *
 * @implements CastsAttributes<string, string>
 */
final class OperatorEmail implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        // 暗号文（base64 の JSON）には @ が出ない。平文のアドレスにはいつも @ がある
        if (str_contains($value, '@')) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return Crypt::encryptString($value);
    }
}
