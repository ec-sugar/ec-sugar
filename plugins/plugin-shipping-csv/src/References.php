<?php

declare(strict_types=1);

namespace EcSugar\PluginShippingCsv;

use App\Core\Fulfillment\Enums\FulfillmentMethod;
use App\Core\Fulfillment\Models\Fulfillment;
use App\Core\Transaction\Models\Transaction;

/**
 * CSV に書く注文番号（お客様管理番号の欄）。お客さんに伝える注文番号（取引の ID を大文字で）と同じ形。
 * 1 つの注文を 2 つ以上の箱で送る（拠点ごとに受渡が分かれた）注文は、末尾に「-1」「-2」（受渡の作った順）を付けて箱を分ける。
 * 番号は取り消した受渡も数えて付ける（後から取り消しても、ほかの箱の番号がずれない）
 */
final class References
{
    /** ULID（大文字。I・L・O・U は使わない）と、箱の番号（1〜99） */
    private const string PATTERN = '/\A([0-9A-HJKMNP-TV-Z]{26})(?:-([1-9][0-9]?))?\z/';

    /**
     * ファイルの値を注文番号の形にそろえる（全角を半角に、前後の空白を除き、大文字に）。形が違えば null
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = strtoupper((string) preg_replace('/\s+/u', '', mb_convert_kana($raw, 'as')));
        $value = str_replace(['－', 'ー', '‐', '−'], '-', $value);

        return preg_match(self::PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * 受渡の注文番号（注文に配送の受渡が 1 つなら番号だけ、2 つ以上なら末尾に作った順の数）
     *
     * @param  list<string>  $shippingIds  その注文の配送の受渡の ID（作った順。取り消したものも含む）
     */
    public static function of(string $transactionId, array $shippingIds, string $fulfillmentId): string
    {
        $number = strtoupper($transactionId);

        if (count($shippingIds) <= 1) {
            return $number;
        }

        $index = array_search($fulfillmentId, $shippingIds, true);

        return $index === false ? $number : $number.'-'.($index + 1);
    }

    /**
     * 注文番号から、この店の物販の注文とその配送の受渡を探す。見つからなければ理由（RowReason）を返す
     *
     * @return array{0: ?Transaction, 1: ?Fulfillment, 2: ?RowReason}
     */
    public static function resolve(string $reference): array
    {
        preg_match(self::PATTERN, $reference, $m);
        $transaction = isset($m[1]) ? Transaction::query()->where('kind', 'sale')->find(strtolower($m[1])) : null;

        if ($transaction === null) {
            return [null, null, RowReason::NotFound];
        }

        $ids = self::shippingIds($transaction->id);

        if (! isset($m[2])) {
            // 番号だけで、箱が 2 つ以上の注文は、どの箱か決められない
            return count($ids) === 1 ? [$transaction, Fulfillment::query()->findOrFail($ids[0]), null] : [$transaction, null, count($ids) === 0 ? RowReason::NotFound : RowReason::Ambiguous];
        }

        $id = $ids[(int) $m[2] - 1] ?? null;

        return $id === null ? [$transaction, null, RowReason::NotFound] : [$transaction, Fulfillment::query()->findOrFail($id), null];
    }

    /**
     * 注文の配送の受渡の ID（作った順。取り消したものも含む）
     *
     * @return list<string>
     */
    public static function shippingIds(string $transactionId): array
    {
        /** @var list<string> */
        return Fulfillment::query()->where('transaction_id', $transactionId)->where('method', FulfillmentMethod::Shipping->value)
            ->orderBy('created_at')->orderBy('id')->pluck('id')->all();
    }
}
