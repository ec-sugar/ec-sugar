<?php

declare(strict_types=1);

namespace EcSugar\PluginProductCsv;

use EcSugar\PluginProductCsv\Jobs\ApplyProductImport;
use EcSugar\PluginProductCsv\Jobs\CheckProductImport;
use EcSugar\PluginProductCsv\Models\ProductImport;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * 商品の CSV の取り込みの記録を進める（docs/design/product-csv.md）。店の文脈の中で、管理画面の受け口とキューの仕事から呼ぶ。
 *
 * 上げる → 確かめ（キュー）→ checked か rejected → 反映を頼む（queued）→ 反映（キュー。running → done か stopped）。止まったものは「もう一度取り込む」で
 * 同じ CSV の新しい記録を作り、確かめからやり直す（入った分は「変わらない」になる）。
 *
 * 写真: CSV と一緒に上げた ZIP は上げた要求の中で展開して置き（PhotoArchive）、記録に置き場の名前と中身の一覧を残す。反映は商品を全部入れた後に
 * 写真の段（ProductImportPhotos）へ進み、1 枚ずつ入れる。ZIP の置き場は、済んだ・断った・古くなった（確かめたまま 1 日・止まったまま 1 日）ら消す。
 *
 * @phpstan-import-type Report from ProductCsvImporter
 *
 * 記録（product_imports）は取り込みの仕事の進み具合で、商品・在庫の状態ではない。状態の書き換えは「今の状態がこれなら」の 1 文の更新で取り合い、
 * 反映（待ち・進行中）が店に 1 つだけなのは部分の一意の索引で DB でも守る。連鎖のロックとイベントは使わない（conventions.md の 3 の例外。
 * 商品・在庫・写真の変更は核のサービスがそれぞれイベントに残す）
 */
final class ProductImports
{
    /** 進行中のまま、この分だけ進み具合が書かれなければ、処理が途中で終わったと見なす（商品 1 つは数秒で書ける） */
    public const int STALE_RUNNING_MINUTES = 15;

    /** 反映の待ちのまま、この分だけ始まらなければ止める（キューの走らせ役が止まっている） */
    public const int STALE_QUEUED_MINUTES = 60;

    /**
     * 1 回のキューの仕事で確かめ・反映する時間（秒）。過ぎたら続きの仕事を出す。走らせ役の時間の上限（本番 75 秒）と送り直しまでの時間（90 秒）より短く。
     * 写真の取りに行きは 1 枚 15 秒までなので、45 秒 + 15 秒 + 作り直しでも上限に収まる。テストで 0 にすると、1 回に商品（写真）1 つずつ進む
     */
    public static float $jobSeconds = 45.0;

    /** 確かめたまま反映しない記録の CSV（と、止まった記録の写真の ZIP）を残す時間 */
    public const int KEEP_CHECKED_HOURS = 24;

    public function __construct(
        private readonly ProductCsvImporter $importer,
        private readonly ProductCsvReader $reader,
        private readonly ProductImportPhotos $photos,
        private readonly PhotoArchive $archives,
    ) {}

    /**
     * 上げたファイルを記録して、確かめの仕事を出す。ファイルの形（大きさ・文字・見出し・行の数・写真の数）はここで確かめて、合わなければ記録を作らない。
     * 写真の ZIP（$zipPath）も一緒なら、ここで展開して置く（ZIP が駄目なら何も残さずに 422）
     *
     * @throws ValidationException
     */
    public function upload(string $bytes, string $actor, ?string $zipPath = null): ProductImport
    {
        $csv = ProductCsvReader::text($bytes);
        $read = $this->reader->read($csv);
        $photoKeys = ProductCsvPhotos::keys();
        $cells = 0;

        foreach ($read['rows'] as $row) {
            foreach ($photoKeys as $key) {
                $cells += ($row['values'][$key] ?? '') === '' ? 0 : 1;
            }
        }

        if ($cells > ProductCsvPhotos::MAX_PER_IMPORT) {
            throw ValidationException::withMessages(['file' => '写真が多すぎます。1 回に '.number_format(ProductCsvPhotos::MAX_PER_IMPORT).' 枚までです。分けて取り込んでください。']);
        }

        if ($zipPath !== null && array_intersect($photoKeys, $read['columns']) === []) {
            throw ValidationException::withMessages(['photos' => 'CSV に写真の列（写真1〜写真10）がありません。ZIP の中のファイル名を写真の列に書いてください。']);
        }

        $this->tidy();
        [$archive, $files] = [null, null];

        if ($zipPath !== null) {
            // 置き場の名前はこちらが決める（ULID。DB の CHECK でも形を守る）
            $archive = strtolower((string) Str::ulid());
            $files = $this->archives->extract($zipPath, $archive);
        }

        try {
            return $this->startCheck([
                'actor' => $actor,
                'digest' => hash('sha256', $bytes),
                'format' => $read['format']->name,
                'rows' => count($read['rows']),
                'csv' => $csv,
                'archive' => $archive,
                'archive_files' => $files,
            ]);
        } catch (Throwable $e) {
            if ($archive !== null) {
                $this->archives->delete($archive);
            }

            throw $e;
        }
    }

    /**
     * 止まった取り込みを、同じ CSV でもう一度（新しい記録で確かめから）。前の取り込みで作った新しい商品は、商品名で合わせて二重に作らない。
     * 写真の ZIP の置き場は新しい記録へ渡す（前の記録からは外す。消すのは新しい記録が終わったとき）
     */
    public function retry(ProductImport $stopped, string $actor): ProductImport
    {
        if ($stopped->status !== 'stopped' || $stopped->csv === null) {
            throw new ImportRefused('「もう一度取り込む」は、途中で止まった取り込みにだけ使えます。');
        }

        $this->tidy();

        $import = $this->startCheck([
            'previous_id' => $stopped->id,
            'actor' => $actor,
            'digest' => $stopped->digest,
            'format' => $stopped->format,
            'rows' => $stopped->rows,
            'csv' => $stopped->csv,
            'created' => $stopped->created,
            'archive' => $stopped->archive,
            'archive_files' => $stopped->archive_files,
        ]);
        $stopped->fill(['archive' => null, 'archive_files' => null])->save();

        return $import;
    }

    /**
     * 確かめ（キューの仕事から）。商品ごとに書いてみて巻き戻し、数と誤りの行（と写真の数・入れない写真）を記録に足していく。
     * 1 回の仕事は JOB_SECONDS ほどで区切り、続きがあれば next_line を書いて続きの仕事を出す
     */
    public function runCheck(string $id): void
    {
        $import = ProductImport::query()->find($id);

        if ($import === null || $import->status !== 'checking' || $import->csv === null) {
            return;
        }

        try {
            $result = $this->importer->check($import->csv, $import->actor, $import->created, $import->next_line ?? 0, microtime(true) + self::$jobSeconds, $import->archive_files);
        } catch (ValidationException $e) {
            $this->reject($import, (string) collect($e->errors())->flatten()->first());

            return;
        }

        /** @var Report|null $before */
        $before = $import->report;
        $report = self::merge($before, $result['report']);

        if ($result['next'] !== null) {
            $import->fill(['report' => $report, 'next_line' => $result['next']])->save();
            CheckProductImport::dispatch($import->id);

            return;
        }

        $ok = $report['errors_total'] === 0;
        $import->fill([
            'status' => $ok ? 'checked' : 'rejected',
            'report' => $report,
            'next_line' => null,
            'csv' => $ok ? $import->csv : null,
            'finished_at' => $ok ? null : now(),
        ])->save();

        if (! $ok) {
            $this->discardArchive($import);
        }
    }

    /**
     * 反映を頼む（管理画面から）。確かめで誤りが無く、変わる行（か足す写真）があり、ほかに反映（待ち・進行中）が無いときだけ
     *
     * @throws ImportRefused
     */
    public function queue(ProductImport $import): ProductImport
    {
        $this->tidy();
        /** @var array{counts?: array{create?: int, update?: int}, errors_total?: int, photos?: array{add?: int}}|null $report */
        $report = $import->report;

        if ($import->status !== 'checked' || ($report['errors_total'] ?? 1) !== 0) {
            throw new ImportRefused('反映できるのは、チェックが終わってエラーの無い取り込みだけです。');
        }

        // 写真だけを足す取り込み（止まった写真の入れ直しも）は、行が全部「変わらない」でも反映できる
        if (($report['counts']['create'] ?? 0) + ($report['counts']['update'] ?? 0) + ($report['photos']['add'] ?? 0) === 0) {
            throw new ImportRefused('変更のある行がありません。');
        }

        if (ProductImport::query()->whereIn('status', ProductImport::APPLYING)->exists()) {
            throw new ImportRefused('ほかの取り込みを反映している途中です。終わってから反映してください。');
        }

        try {
            $claimed = ProductImport::query()->whereKey($import->id)->where('status', 'checked')->update(['status' => 'queued', 'updated_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            // 同時に押した 2 つ目（部分の一意の索引が断った）
            throw new ImportRefused('ほかの取り込みを反映している途中です。終わってから反映してください。');
        }

        if ($claimed !== 1) {
            throw new ImportRefused('この取り込みは、すでに反映を始めています。');
        }

        ApplyProductImport::dispatch($import->id);

        return $import->refresh();
    }

    /**
     * 反映（キューの仕事から）。前半は商品 1 つずつ確定し、そのたびに進み具合を書く。断られたら止まった行と理由を書いて止める。
     * 商品が全部入ったら、後半の写真の段（ProductImportPhotos）へ進む（写真が無ければ済み）。
     * 1 回の仕事は JOB_SECONDS ほどで区切り、続きがあれば next_line（商品）か photo_next（写真）を書いて続きの仕事を出す（記録は進行中のまま）
     */
    public function runApply(string $id): void
    {
        // 最初の仕事は待ち → 進行中に。続きの仕事は進行中のまま受け取る（next_line か photo_next がある）
        ProductImport::query()->whereKey($id)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
        $import = ProductImport::query()->find($id);

        if ($import === null || $import->status !== 'running' || $import->csv === null) {
            return;
        }

        $until = microtime(true) + self::$jobSeconds;

        if ($import->photo_next === null) {
            if (! $this->applyProducts($import, $until)) {
                return;
            }

            try {
                $any = $this->photos->any($import->csv, $import->created);
            } catch (Throwable $e) {
                report($e);
                $this->stop($import, null, '予期しないエラーで止まりました。少し待ってから「もう一度取り込む」を押してください。');

                return;
            }

            if (! $any) {
                $this->finish($import);

                return;
            }

            // 写真の段へ（止まった扱いにされていたら進まない）
            if (ProductImport::query()->whereKey($import->id)->where('status', 'running')->update(['photo_next' => 0, 'next_line' => null, 'updated_at' => now()]) !== 1) {
                return;
            }

            $import->refresh();
        }

        try {
            $result = $this->photos->run($import, $until);
        } catch (Throwable $e) {
            report($e);
            $result = ['outcome' => 'stop', 'line' => null, 'reason' => '写真の登録中に予期しないエラーが起きて止まりました。少し待ってから「もう一度取り込む」を押してください。'];
        }

        match ($result['outcome']) {
            'done' => $this->finish($import),
            'continue' => self::continueLater($import->id, $result['delay']),
            'stop' => $this->stop($import->refresh(), $result['line'], $result['reason']),
            'gone' => null,
        };
    }

    /**
     * キューの仕事が使い切って落ちた（時間切れなど）
     */
    public function failed(string $id, string $phase): void
    {
        $import = ProductImport::query()->find($id);

        if ($import === null) {
            return;
        }

        if ($phase === 'check' && $import->status === 'checking') {
            $this->reject($import, 'ファイルのチェックの途中で止まりました。もう一度ファイルを選んでください。');
        } elseif ($phase === 'apply' && in_array($import->status, ProductImport::APPLYING, true)) {
            $this->stop($import, null, '反映の途中で止まりました（処理が時間内に終わりませんでした）。「もう一度取り込む」を押すと、残りを反映できます。');
        }
    }

    /**
     * 画面に出す形（CSV の中身と ZIP の中身の一覧は出さない）
     *
     * @return array<string, mixed>
     */
    public static function present(ProductImport $import): array
    {
        return [
            'id' => $import->id,
            'previous_id' => $import->previous_id,
            'status' => $import->status,
            'format' => $import->format,
            'rows' => $import->rows,
            'report' => self::ordered($import->report),
            'applied' => $import->applied === null ? null : self::pick($import->applied, ['create', 'update', 'skip']),
            'applied_rows' => $import->applied_rows,
            // 写真: ZIP を一緒に上げたか・反映の後半（写真の段）にいるか・写真の反映の結果
            'has_archive' => $import->archive !== null,
            'photo_phase' => $import->photo_next !== null && $import->status === 'running',
            'photos_done' => $import->photo_next,
            'photos' => $import->photos === null ? null : self::pick($import->photos, ['total', 'added', 'kept', 'failed', 'problems_total'])
                + ['problems' => $import->photos['problems'] ?? []],
            'stopped_line' => $import->stopped_line,
            'stopped_reason' => $import->stopped_reason,
            // もう一度取り込めるか（止まって、CSV が残っている）
            'retryable' => $import->status === 'stopped' && $import->csv !== null,
            'created_at' => $import->getAttribute('created_at')?->toIso8601String(),
            'started_at' => $import->started_at?->toIso8601String(),
            'finished_at' => $import->finished_at?->toIso8601String(),
        ];
    }

    /**
     * 反映の前半（商品）。全部入ったら true。止まった・続きの仕事を出したら false
     */
    private function applyProducts(ProductImport $import, float $until): bool
    {
        $applied = $import->applied ?? ['create' => 0, 'update' => 0, 'skip' => 0];
        $created = $import->created;

        try {
            $result = $this->importer->apply((string) $import->csv, $import->actor, $created, $import->next_line ?? 0, $until,
                function (array $group, array $done) use ($import, &$applied, &$created): void {
                    foreach ($done['kinds'] as $kind) {
                        $applied[$kind]++;
                    }

                    if ($done['created'] && $done['title'] !== null) {
                        $created[$done['title']] = $done['listing_id'];
                    }

                    // 進み具合（と、まだ動いている印の updated_at）を、商品ごとに書く
                    $import->fill(['applied' => $applied, 'applied_rows' => $import->applied_rows + count($group['rows']), 'created' => $created])->save();
                    event(new ProductImportProgressed($import->id, $import->applied_rows));
                });
        } catch (Throwable $e) {
            report($e);
            $result = ['failure' => ['line' => 0, 'column' => '', 'message' => '予期しないエラーで止まりました。少し待ってから「もう一度取り込む」を押してください。'], 'next' => null];
        }

        if ($result['failure'] !== null) {
            $this->stop($import, $result['failure']['line'] > 0 ? $result['failure']['line'] : null, $this->reason($import, $result['failure']));

            return false;
        }

        if ($result['next'] !== null) {
            // 止まった扱いにされた後に遅れて終わった仕事は、記録を書き換えない（今も running のときだけ）
            if (ProductImport::query()->whereKey($import->id)->where('status', 'running')->update(['next_line' => $result['next'], 'updated_at' => now()]) === 1) {
                ApplyProductImport::dispatch($import->id);
            }

            return false;
        }

        return true;
    }

    /**
     * 済み（今も進行中のときだけ）。CSV と写真の ZIP の置き場を消す
     */
    private function finish(ProductImport $import): void
    {
        $done = ProductImport::query()->whereKey($import->id)->where('status', 'running')
            ->update(['status' => 'done', 'csv' => null, 'next_line' => null, 'photo_next' => null, 'finished_at' => now(), 'updated_at' => now()]);

        if ($done === 1) {
            $this->discardArchive($import);
        }
    }

    /**
     * 続きの仕事を出す。$delay 秒の後に（写真の 1 分の回数が空くまで。走らせ役を眠らせて、ほかの仕事を止めない）
     */
    private static function continueLater(string $id, int $delay): void
    {
        $job = ApplyProductImport::dispatch($id);

        if ($delay > 0) {
            $job->delay(now()->addSeconds($delay));
        }
    }

    /**
     * 写真の ZIP の置き場を消し、記録から外す
     */
    private function discardArchive(ProductImport $import): void
    {
        if ($import->archive === null) {
            return;
        }

        $this->archives->delete($import->archive);
        ProductImport::query()->whereKey($import->id)->update(['archive' => null, 'archive_files' => null]);
        $import->forceFill(['archive' => null, 'archive_files' => null])->syncOriginal();
    }

    /**
     * 画面に出す報告。数の鍵の順をそろえる（jsonb は鍵の順を並べ替えて返す）
     *
     * @param  array<string, mixed>|null  $report
     * @return array<string, mixed>|null
     */
    private static function ordered(?array $report): ?array
    {
        if ($report === null) {
            return null;
        }

        $report['counts'] = self::pick((array) ($report['counts'] ?? []), ['create', 'update', 'skip', 'error']);
        $report['products'] = self::pick((array) ($report['products'] ?? []), ['create', 'update']);
        $photos = (array) ($report['photos'] ?? []);
        $report['photos'] = self::pick($photos, ['add', 'keep', 'warnings_total']) + ['warnings' => array_values((array) ($photos['warnings'] ?? []))];

        return $report;
    }

    /**
     * @param  array<mixed>  $values
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    private static function pick(array $values, array $keys): array
    {
        $picked = [];

        foreach ($keys as $key) {
            $picked[$key] = (int) ($values[$key] ?? 0);
        }

        return $picked;
    }

    /**
     * 確かめの回ごとの結果を足し合わせる（誤りの行と入れない写真は MAX_ERRORS_SHOWN まで）
     *
     * @param  Report|null  $before
     * @param  Report  $now
     * @return Report
     */
    private static function merge(?array $before, array $now): array
    {
        if ($before === null) {
            return $now;
        }

        // 鍵ごとに足す（jsonb は鍵の順を並べ替えて返すので、並びで足さない）
        $sum = static function (array $a, array $b): array {
            foreach ($b as $key => $value) {
                $a[$key] = (int) ($a[$key] ?? 0) + (int) $value;
            }

            return $a;
        };
        $photos = $before['photos'];

        return [
            'format' => $now['format'],
            'rows' => $now['rows'],
            'counts' => $sum($before['counts'], $now['counts']),
            'products' => $sum($before['products'], $now['products']),
            'extra_variants' => $before['extra_variants'] + $now['extra_variants'],
            'errors' => array_slice([...$before['errors'], ...$now['errors']], 0, ProductCsvImporter::MAX_ERRORS_SHOWN),
            'errors_total' => $before['errors_total'] + $now['errors_total'],
            'photos' => [
                'add' => $photos['add'] + $now['photos']['add'],
                'keep' => $photos['keep'] + $now['photos']['keep'],
                'warnings' => array_slice([...$photos['warnings'], ...$now['photos']['warnings']], 0, ProductCsvImporter::MAX_ERRORS_SHOWN),
                'warnings_total' => $photos['warnings_total'] + $now['photos']['warnings_total'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function startCheck(array $attributes): ProductImport
    {
        $import = ProductImport::query()->create($attributes + ['status' => 'checking']);
        CheckProductImport::dispatch($import->id);

        return $import->refresh();
    }

    private function reject(ProductImport $import, string $reason): void
    {
        $import->fill(['status' => 'rejected', 'csv' => null, 'stopped_reason' => mb_substr($reason, 0, 500), 'finished_at' => now()])->save();
        $this->discardArchive($import);
    }

    private function stop(ProductImport $import, ?int $line, string $reason): void
    {
        $import->fill(['status' => 'stopped', 'stopped_line' => $line, 'stopped_reason' => mb_substr($reason, 0, 500), 'finished_at' => now()])->save();
    }

    /**
     * @param  array{line: int, column: string, message: string}  $failure
     */
    private function reason(ProductImport $import, array $failure): string
    {
        $format = collect(ProductCsvFormat::formats())->first(static fn (ProductCsvFormat $f): bool => $f->name === $import->format) ?? ProductCsvFormat::ecSugar();

        return $failure['column'] === '' ? $failure['message'] : '「'.$format->headerOf($failure['column']).'」: '.$failure['message'];
    }

    /**
     * 片付け: 動いていない反映を止め（走らせ役が落ちた・始まらない）、確かめたまま古くなった記録と、断った・済んだ記録の CSV を消す。
     * 写真の ZIP の置き場は、古くなった記録と、止まったまま 1 日たった記録（もう一度取り込めなくなる）の分を消す
     */
    private function tidy(): void
    {
        ProductImport::query()->where('status', 'running')->where('updated_at', '<', now()->subMinutes(self::STALE_RUNNING_MINUTES))->get()
            ->each(fn (ProductImport $i) => $this->stop($i, null, '反映の途中で止まりました（処理が途中で終わりました）。「もう一度取り込む」を押すと、残りを反映できます。'));
        ProductImport::query()->where('status', 'queued')->where('updated_at', '<', now()->subMinutes(self::STALE_QUEUED_MINUTES))->get()
            ->each(fn (ProductImport $i) => $this->stop($i, null, '反映が始まりませんでした。少し待ってから「もう一度取り込む」を押してください。'));
        ProductImport::query()->whereIn('status', ['checking', 'checked'])->where('updated_at', '<', now()->subHours(self::KEEP_CHECKED_HOURS))->whereNotNull('archive')->get()
            ->each(fn (ProductImport $i) => $this->discardArchive($i));
        ProductImport::query()->whereIn('status', ['checking', 'checked'])->where('updated_at', '<', now()->subHours(self::KEEP_CHECKED_HOURS))
            ->update(['status' => 'expired', 'csv' => null, 'updated_at' => now()]);
        ProductImport::query()->where('status', 'stopped')->where('updated_at', '<', now()->subHours(self::KEEP_CHECKED_HOURS))->whereNotNull('archive')->get()
            ->each(function (ProductImport $i): void {
                $this->discardArchive($i);
                $i->fill(['csv' => null])->save();
            });
    }
}
