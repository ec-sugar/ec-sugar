<?php

declare(strict_types=1);

namespace App\Core\Plugin\Gift;

use InvalidArgumentException;

/**
 * 贈り物に添える差出人の表示名とメッセージ（ADR 0055 の 2）。注文の入力で確かめた後の値。
 * 文だけ（HTML は文字のまま出す）。表示名は 1 行、メッセージは改行だけを許し、ほかの制御文字と向きの印（文字の並びを逆に見せる印）は断る。
 * 暗号化して持つのは App（店の鍵。ADR 0010）。注文のイベントには入れない
 */
final readonly class GiftNote
{
    public const int SENDER_MAX = 50;

    public const int MESSAGE_MAX = 500;

    /**
     * 改行（\n）のほかの制御文字と、向きの印（LRM・RLM・埋め込み・上書き・分離）、行と段落の区切り（U+2028・U+2029。改行に見えるが \n でない）、
     * 幅の無い文字（U+200B・U+200C・U+FEFF。同じに見える別の文を作れる）。注文の項目の文も同じ決まり（ADR 0066）。
     * 絵文字をつなぐ U+200D（家族・肌の色の組み合わせ）は、贈り物のメッセージで使われるので通す
     */
    public const string UNSAFE = '/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}-\x{009F}\x{061C}\x{200B}\x{200C}\x{200E}\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    public function __construct(
        public string $sender,
        public string $message,
    ) {
        if (self::problem($sender, true) !== null || self::problem($message, false) !== null) {
            throw new InvalidArgumentException('贈り物の差出人かメッセージの形が違います');
        }
    }

    /**
     * 改行を \n にそろえ、前後の空白を取る
     */
    public static function normalize(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * 形が違うときの理由（合えば null）
     */
    public static function problem(string $text, bool $sender): ?string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            return '文字で入力してください。';
        }

        if ($sender && ($text === '' || mb_strlen($text) > self::SENDER_MAX)) {
            return '差出人のお名前は '.self::SENDER_MAX.' 文字以内で入力してください。';
        }

        if (! $sender && mb_strlen($text) > self::MESSAGE_MAX) {
            return 'メッセージは '.self::MESSAGE_MAX.' 文字以内で入力してください。';
        }

        if (($sender && str_contains($text, "\n")) || preg_match(self::UNSAFE, $text) === 1) {
            return $sender ? '差出人のお名前は、改行や特殊な文字（制御文字など）を含まない 1 行で入力してください。' : 'メッセージに使えない特殊な文字（制御文字など）が含まれています。その文字を消してから、もう一度お試しください。';
        }

        return null;
    }
}
