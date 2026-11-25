<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * 入口の Caddy が「この名前の証明書を取ってよいか」を聞くときの名前（ADR 0031）。
 *
 * Caddy は TLS の握手で届いた名前（SNI）を小文字にして ?domain= で渡す。ホスト名の形だけを受ける:
 * 小文字の英数字とハイフンのラベルを点でつなぎ、2 つ以上、末尾は英字で始まるラベル（IP の住所・localhost・ワイルドカード・
 * ポート・道筋・末尾の点は断る）。ラベルは 63 文字まで、全体は 253 文字まで。
 * 断るときは本文の無い 422（Caddy は 2xx のほかはすべて「取らない」と読む。ログインの画面へ送り返さない）。
 */
final class TlsAllowedRequest extends FormRequest
{
    public const string HOST_PATTERN = '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D';

    public function authorize(): bool
    {
        return true; // 誰が聞けるかは経路のミドルウェア（RequireTlsAskEntry。内側の入口の印）
    }

    /**
     * 検査するのは届いたままの名前。全体の前処理（TrimStrings）が端の空白や NUL を落とした後の値で確かめると、
     * Caddy が聞いた名前と違う名前に「取ってよい」と答えることになる
     */
    protected function prepareForValidation(): void
    {
        parse_str((string) $this->server->get('QUERY_STRING', ''), $raw);

        $this->merge(['domain' => $raw['domain'] ?? null]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:253', 'regex:'.self::HOST_PATTERN],
        ];
    }

    public function domain(): string
    {
        return (string) $this->validated('domain');
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response('', 422));
    }
}
