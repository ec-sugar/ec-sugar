<?php

declare(strict_types=1);

namespace EcSugar\PluginContact;

use Illuminate\Support\Facades\Validator;

/**
 * お客さんがショップのフォームに書いた欄を確かめる（名前・メール・種類・本文）。HTML として扱わない（出すときはいつもエスケープ）が、
 * 見た目と中身をずらす文字（制御文字・文字の向きを変える印・幅の無い空白・BOM）は断る（商品レビューと同じ決まり）。
 * 前後の空白を落とし、改行は \n に、本文のタブは空白にそろえる（貼り付けた文を断らない）。長さは名前 50・本文 2,000・メール 254 文字まで
 */
final readonly class ContactInput
{
    public const int NAME_MAX = 50;

    public const int BODY_MAX = 2000;

    public const int EMAIL_MAX = 254;

    // 1 行の欄で断る文字: 制御文字（改行を含む）・文字の向きを変える印（LRM・RLM・ALM・埋め込み・上書き・分離）・幅の無い空白と BOM。
    // 絵文字をつなぐ幅の無い接続子（U+200D）はつないだ絵文字に要るので通す
    private const string LINE_UNSAFE = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{061C}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]/u';

    // 本文で断る文字: 改行（\n）のほかは 1 行の欄と同じ
    private const string BLOCK_UNSAFE = '/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}-\x{009F}\x{061C}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]/u';

    private function __construct(
        public ContactKind $kind,
        public string $name,
        public string $email,
        public string $body,
    ) {}

    /**
     * 確かめて形をそろえる。合わなければ欄の名前 => 一言。$memberEmail があれば（ログインした会員）メールの欄は使わずそれを使う
     *
     * @return self|array<string, string>
     */
    public static function from(mixed $kind, mixed $name, mixed $email, mixed $body, ?string $memberEmail = null): self|array
    {
        $kind = is_string($kind) ? ContactKind::tryFrom($kind) : null;
        $name = self::text($name);
        $body = self::text(is_string($body) ? str_replace("\t", ' ', $body) : $body);
        $email = $memberEmail ?? self::text($email);

        $errors = array_filter([
            'kind' => $kind === null ? 'お問い合わせの種類を選んでください。' : null,
            'name' => self::problem($name, self::NAME_MAX, false, 'お名前'),
            'email' => $memberEmail === null ? self::emailProblem($email) : null,
            'body' => self::problem($body, self::BODY_MAX, true, 'お問い合わせの内容'),
        ]);

        if ($errors !== [] || $kind === null || ! is_string($name) || ! is_string($email) || ! is_string($body)) {
            return $errors === [] ? ['body' => '文字で入力してください。'] : $errors;
        }

        return new self($kind, $name, $email, $body);
    }

    /**
     * 前後の空白を落とし、改行を \n にそろえる。文字でない・壊れた文字は null（後で断る）
     */
    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return '';
        }

        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        // 前後の空白は全角の空白も落とす（trim の文字の並びはバイトで比べるので、多バイトの文字は正規表現で）
        return (string) preg_replace('/\A[\s\x{3000}]+|[\s\x{3000}]+\z/u', '', str_replace(["\r\n", "\r"], "\n", $value));
    }

    private static function problem(?string $value, int $max, bool $multiline, string $label): ?string
    {
        return match (true) {
            $value === null => '文字で入力してください。',
            $value === '' => "{$label}を入力してください。",
            mb_strlen($value) > $max => "{$label}は ".number_format($max).' 文字以内で入力してください。',
            preg_match($multiline ? self::BLOCK_UNSAFE : self::LINE_UNSAFE, $value) !== 0 => $multiline ? "{$label}に使えない文字が入っています。" : "{$label}に改行や使えない文字が入っています。",
            default => null,
        };
    }

    /**
     * 注文の入力・会員のログインと同じ決まり（email:rfc）に、送れる形（FILTER_VALIDATE_EMAIL）も足す（受け付けのメールを送れないアドレスを残さない）
     */
    private static function emailProblem(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return 'メールアドレスを入力してください。';
        }

        $rfc = Validator::make(['email' => $email], ['email' => ['string', 'max:'.self::EMAIL_MAX, 'email:rfc']]);

        return $rfc->fails() || filter_var($email, FILTER_VALIDATE_EMAIL) === false ? 'メールアドレスの形式が正しくありません。ご確認ください。' : null;
    }
}
