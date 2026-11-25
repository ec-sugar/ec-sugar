<?php

declare(strict_types=1);

namespace EcSugar\PluginProductCsv;

use App\Core\Media\MediaQuotaExceeded;
use App\Core\Media\MediaRateLimited;
use App\Core\Media\Services\MediaQuota;
use EcSugar\PluginProductCsv\Models\ProductImport;
use Throwable;

/**
 * 商品の CSV の反映の後半、写真の段。商品を全部入れた後に、キューの仕事（ProductImports::runApply）から呼ぶ。
 *
 * - 写真は 1 枚ずつ、核の写真のサービス（ListingImageService。連鎖のロックとイベント）で入れる。1 枚ごとに進み具合（photo_next と photos）を書く
 * - 1 回の仕事は ProductImports::$jobSeconds で区切る（1 枚の取りに行きは 15 秒まで。続きは次の仕事）
 * - 写真の 1 分の回数（MediaQuota）に触れそうなら、走らせ役を眠らせずに、空くまで遅らせた続きの仕事を出す（ほかの仕事を止めない）
 * - 置き場の量の上限（店の合計・その日の量）に触れたら止める（入った写真は残り、「もう一度取り込む」で残りだけを入れる）
 * - 1 枚が取れない・画像でないなどは、その写真だけを飛ばして理由を残す（商品の取り込みは止めない）
 *
 * @phpstan-import-type Entry from ProductCsvPhotos
 * @phpstan-import-type Photo from ProductCsvPhotos
 *
 * @phpstan-type Results array{total: int, added: int, kept: int, failed: int, problems: list<array{line: int, column: string, message: string}>, problems_total: int}
 * @phpstan-type Outcome array{outcome: 'done'}|array{outcome: 'gone'}|array{outcome: 'continue', delay: int}|array{outcome: 'stop', line: int|null, reason: string}
 */
final class ProductImportPhotos
{
    public function __construct(
        private readonly ProductCsvReader $reader,
        private readonly ProductCsvPhotos $photos,
        private readonly MediaQuota $quota,
    ) {}

    /**
     * 写真が 1 枚でもあるか（商品の段の終わりに、写真の段へ進むか決める）
     *
     * @param  array<string, string>  $created
     */
    public function any(string $csv, array $created): bool
    {
        return $this->entries($csv, $created) !== [];
    }

    /**
     * 写真の段で入れる写真（商品の CSV の順・列の順）。商品は ID・品番と、この取り込みで作った商品名で引く（商品は全部入った後）
     *
     * @param  array<string, string>  $created  この取り込み（と前の取り込み）で作った商品（商品名 => ID）
     * @return list<Entry>
     */
    public function entries(string $csv, array $created): array
    {
        $entries = [];

        foreach (ProductCsvGroups::read($this->reader, $csv, $created)['groups'] as $group) {
            $first = $group['rows'][0];
            /** @var list<Photo> $photos */
            $photos = $first['values']['photos'] ?? [];

            if ($group['listing_id'] === null || $photos === []) {
                continue;
            }

            foreach ($photos as $index => $photo) {
                $entries[] = [
                    'line' => $first['line'],
                    'column' => $photo['column'],
                    'listing_id' => $group['listing_id'],
                    'ref' => $photo['ref'],
                    'problem' => $photo['problem'],
                    'last' => $index === count($photos) - 1,
                ];
            }
        }

        return $entries;
    }

    /**
     * @return Outcome
     */
    public function run(ProductImport $import, float $until): array
    {
        if ($import->csv === null) {
            return ['outcome' => 'gone'];
        }

        $entries = $this->entries($import->csv, $import->created);
        /** @var Results $results */
        $results = ($import->photos ?? []) + self::empty();
        $results['total'] = count($entries);
        $start = (int) $import->photo_next;

        for ($index = $start; $index < count($entries); $index++) {
            // 1 回に少なくとも 1 枚は進める（締め切りを過ぎていても）
            if ($index > $start && microtime(true) >= $until) {
                return $this->save($import, $index, $results) ? ['outcome' => 'continue', 'delay' => 0] : ['outcome' => 'gone'];
            }

            $wait = $this->quota->secondsUntil(1);

            if ($wait > 0) {
                return $this->save($import, $index, $results) ? ['outcome' => 'continue', 'delay' => $wait] : ['outcome' => 'gone'];
            }

            $entry = $entries[$index];

            try {
                $results[$this->photos->place($entry, $import->archive_files, $import->archive, $import->actor) === 'added' ? 'added' : 'kept']++;
            } catch (PhotoSkipped $e) {
                $results['failed']++;
                $results['problems_total']++;

                if (count($results['problems']) < ProductCsvImporter::MAX_ERRORS_SHOWN) {
                    $results['problems'][] = ['line' => $entry['line'], 'column' => self::header($entry['column']), 'message' => $e->getMessage()];
                }
            } catch (MediaRateLimited) {
                // ほかの人が同じ店で写真を上げていた。少し待って同じ写真から
                return $this->save($import, $index, $results) ? ['outcome' => 'continue', 'delay' => 60] : ['outcome' => 'gone'];
            } catch (MediaQuotaExceeded $e) {
                $this->save($import, $index, $results);

                return ['outcome' => 'stop', 'line' => $entry['line'], 'reason' => '「'.self::header($entry['column']).'」: '.$e->getMessage()];
            } catch (Throwable $e) {
                report($e);
                $this->save($import, $index, $results);

                return ['outcome' => 'stop', 'line' => $entry['line'], 'reason' => '写真の登録中に予期しないエラーが起きて止まりました。少し待ってから「もう一度取り込む」を押してください。'];
            }

            if ($entry['last']) {
                $this->arrange($import, $entries, $index);
            }

            // 進み具合（と、まだ動いている印の updated_at）を 1 枚ごとに。止まった扱いにされていたら、そこでやめる
            if (! $this->save($import, $index + 1, $results)) {
                return ['outcome' => 'gone'];
            }
        }

        return ['outcome' => 'done'];
    }

    /**
     * @return Results
     */
    public static function empty(int $total = 0): array
    {
        return ['total' => $total, 'added' => 0, 'kept' => 0, 'failed' => 0, 'problems' => [], 'problems_total' => 0];
    }

    /**
     * 1 つの商品の写真を入れ終えたら、CSV の順に並べる（並べ替えに失敗しても写真は入っているので、止めずに記録だけ）
     *
     * @param  list<Entry>  $entries
     */
    private function arrange(ProductImport $import, array $entries, int $last): void
    {
        $mine = [];

        for ($index = $last; $index >= 0 && $entries[$index]['line'] === $entries[$last]['line']; $index--) {
            array_unshift($mine, $entries[$index]);
        }

        try {
            $this->photos->arrange($entries[$last]['listing_id'], $mine, $import->archive_files, $import->actor);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  Results  $results
     */
    private function save(ProductImport $import, int $next, array $results): bool
    {
        $saved = ProductImport::query()->whereKey($import->id)->where('status', 'running')->update([
            'photo_next' => $next,
            'photos' => json_encode($results, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]) === 1;

        event(new ProductImportProgressed($import->id, $import->applied_rows));

        return $saved;
    }

    private static function header(string $column): string
    {
        return ProductCsvFormat::ecSugar()->headerOf($column);
    }
}
