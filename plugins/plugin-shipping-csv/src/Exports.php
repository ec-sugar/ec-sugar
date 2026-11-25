<?php

declare(strict_types=1);

namespace EcSugar\PluginShippingCsv;

use App\Core\Event\EventStore;
use App\Core\Plugin\AdminPage\AdminCsv;
use App\Core\Plugin\Services\PluginSettings;
use Carbon\CarbonImmutable;
use EcSugar\PluginShippingCsv\Models\ExportedMark;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 発送を待つ注文を、運送会社の送り状ソフトの CSV にする。書き出した箱に「書き出した」の印を付ける（まだ書き出していない注文の絞り込み）。
 * 印を付けるので、連鎖のロックの後に行のロックを取り、同じトランザクションでイベント（shipping_csv.exported。形・件数・どれを選んだかだけ。届け先は入れない）を残す。
 * 誰が・何件を書き出したかの監査ログ（中央）は核が残す（AdminFiles を呼ぶ管理画面の受け口）
 */
final class Exports
{
    /** 注文番号を並べる欄の数の上限 */
    public const int MAX_NUMBERS = 200;

    public function __construct(
        private readonly EventStore $events,
        private readonly PluginSettings $settings,
    ) {}

    /**
     * @param  array<string, bool|string|null>  $values  format・target・numbers・ship_date（核が形を確かめた後）
     */
    public function export(array $values, string $actor): AdminCsv
    {
        $format = CsvFormat::tryFrom((string) ($values['format'] ?? '')) ?? throw ValidationException::withMessages(['format' => '運送会社の形式を選んでください。']);
        $target = in_array($values['target'] ?? null, ['new', 'all', 'numbers'], true) ? (string) $values['target'] : 'new';
        $numbers = $target === 'numbers' ? self::numbers((string) ($values['numbers'] ?? '')) : [];
        $shipDate = self::shipDate($values['ship_date'] ?? null);
        $shipper = Shipper::current();

        if ($format->needsShipper() && ! $shipper->complete()) {
            throw ValidationException::withMessages(['format' => 'ご依頼主（店）の郵便番号・住所・電話番号がそろっていません。App の設定か、特定商取引法の表記に入れてください。']);
        }

        /** @var array<string, bool|string> $settings */
        $settings = $this->settings->publicValues(ShippingCsvServiceProvider::NAME) ?? [];

        return DB::transaction(function () use ($format, $target, $numbers, $shipDate, $shipper, $settings, $actor): AdminCsv {
            $this->events->lock();
            [$parcels, $unknown] = Parcels::pick($target, $numbers);
            // 代金引換の注文は書き出さない（この CSV の送り状は発払い。ADR 0074）。外したことを理由に添える
            $cod = Parcels::cashOnDelivery();
            $codNote = $cod > 0 ? "（代引きの注文 {$cod} 件は入れていません。運送会社の画面で代引きの送り状を作ってください）" : '';

            if ($unknown !== []) {
                $shown = implode('、', array_slice($unknown, 0, 5)).(count($unknown) > 5 ? ' ほか' : '');

                throw ValidationException::withMessages(['numbers' => "発送待ちの注文の中に見つからない注文番号があります: {$shown}{$codNote}"]);
            }

            if ($parcels === []) {
                throw ValidationException::withMessages(['target' => ($target === 'new' ? 'まだ書き出していない発送待ちの注文はありません。' : '発送待ちの注文はありません。').$codNote]);
            }

            $this->mark($parcels, $format);
            $this->events->append('shipping_csv.exported', 'shipping_csv_export', strtolower((string) Str::ulid()), [
                'format' => $format->value,
                'target' => $target,
                'parcels' => count($parcels),
            ], $actor);

            return $format->csv($parcels, $shipper, $settings, $shipDate);
        });
    }

    /**
     * 書き出した印（受渡ごとに最後の日時・形・回数）
     *
     * @param  list<Parcel>  $parcels
     */
    private function mark(array $parcels, CsvFormat $format): void
    {
        $now = CarbonImmutable::now();
        $ids = array_map(static fn (Parcel $p): string => $p->fulfillmentId, $parcels);
        $existing = ExportedMark::query()->whereKey($ids)->lockForUpdate()->get()->keyBy('fulfillment_id');

        foreach ($parcels as $parcel) {
            $mark = $existing->get($parcel->fulfillmentId);

            if ($mark instanceof ExportedMark) {
                $mark->forceFill(['format' => $format->value, 'times' => $mark->times + 1, 'exported_at' => $now])->save();
            } else {
                ExportedMark::query()->create(['fulfillment_id' => $parcel->fulfillmentId, 'transaction_id' => $parcel->transactionId, 'format' => $format->value, 'times' => 1, 'exported_at' => $now]);
            }
        }
    }

    /**
     * 注文番号の欄（1 行に 1 つ。カンマ・空白で区切ってもよい）
     *
     * @return list<string>
     */
    private static function numbers(string $text): array
    {
        $parts = preg_split('/[\s,、，]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            throw ValidationException::withMessages(['numbers' => '注文番号を 1 行に 1 つずつ入れてください。']);
        }

        if (count($parts) > self::MAX_NUMBERS) {
            throw ValidationException::withMessages(['numbers' => '注文番号は '.self::MAX_NUMBERS.' 個までです。分けて書き出してください。']);
        }

        $out = [];

        foreach ($parts as $part) {
            $reference = References::normalize($part) ?? throw ValidationException::withMessages(['numbers' => '注文番号が正しくありません: '.mb_substr($part, 0, 40)]);
            $out[$reference] = true;
        }

        return array_keys($out);
    }

    /**
     * 出荷予定日（空なら今日。日本の日付で、今日から 30 日後まで）
     */
    private static function shipDate(mixed $raw): CarbonImmutable
    {
        $today = CarbonImmutable::now('Asia/Tokyo')->startOfDay();

        if (! is_string($raw) || trim($raw) === '') {
            return $today;
        }

        $value = trim(mb_convert_kana($raw, 'a'));
        $date = preg_match('/\A(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})\z/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            ? CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, 'Asia/Tokyo') : null;

        if ($date === null || $date->lt($today) || $date->gt($today->addDays(30))) {
            throw ValidationException::withMessages(['ship_date' => '出荷予定日は、今日から 30 日後までの日付で入れてください（例: '.$today->format('Y-m-d').'）。']);
        }

        return $date;
    }
}
