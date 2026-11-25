<?php

declare(strict_types=1);

namespace EcSugar\PluginProductCsv;

use App\Core\Catalog\Models\Category;
use App\Core\Catalog\Services\CategoryService;
use App\Core\Csv\CsvCells;
use App\Core\Event\EventStore;
use App\Core\Event\Models\DomainEvent;
use App\Core\Inventory\Enums\AdjustmentReason;
use App\Core\Inventory\Models\InventoryStock;
use App\Core\Inventory\Models\Location;
use App\Core\Inventory\Services\InsufficientStockException;
use App\Core\Inventory\Services\InventoryOperations;
use App\Core\Listing\Description\ProductDescription;
use App\Core\Listing\Enums\ListingStatus;
use App\Core\Listing\Enums\VariantStatus;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingOption;
use App\Core\Listing\Models\ListingVariant;
use App\Core\Listing\Models\Seller;
use App\Core\Listing\Services\AddonService;
use App\Core\Listing\Services\ListingService;
use App\Core\Listing\Services\StaleListingException;
use App\Core\Listing\Services\VariantService;
use App\Core\Tax\TaxClass;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * 商品の CSV の取り込み（確かめと反映）。店の文脈の中で、キューの仕事（ProductImports）から呼ぶ。決まりは docs/design/product-csv.md。
 *
 * - 確かめ（check）: 商品ごとに短いトランザクションを開いて本当に書いてみて、毎回巻き戻す（何も残らない。afterCommit の処理も消える）。
 *   ある商品で断られても次の商品へ進み、誤りの行をまとめて返す。商品どうしは独立（品番の重なりはファイルの中で先に見る）なので、
 *   1 つずつ巻き戻しても、反映したときと同じ数になる
 * - 反映（apply）: 商品 1 つずつ、核のサービスの短いトランザクションで書いて確定する。連鎖のロック（ADR 0012）を持つのは
 *   1 つの商品の間だけで、商品の間にはショップの注文などが割り込める。途中で断られたら、そこで止めて止まった行と理由を返す
 *   （入った商品は残る。商品は ID・品番で合わせ、新しい商品は記録の created で合わせるので、同じ CSV を入れ直すと残りだけが入る）
 * - 変えるのは核のサービスだけ（ListingService・VariantService・AddonService・CategoryService・InventoryOperations）。記録（イベント）と楽観ロックもそれぞれのまま
 * - 商品の行の決め方: 商品ID → 品番 → 前の取り込みで作った商品名 → 商品名ごとに新しい商品（ProductCsvGroups）
 * - 消すことはしない（CSV に無い商品も種類もそのまま）。今の商品の選ぶ項目（軸）の名前と順は変えない（値を足すのはよい）
 * - 在庫は「この数にする」。出品者の最初の拠点の数を、棚卸しの調整として合わせる
 * - 写真（写真1〜写真10の列）は、確かめでは数と入れない理由を見るだけ（ProductCsvPhotos::review。取りに行かない）。
 *   反映では商品を全部入れた後に、写真の段（ProductImportPhotos）が 1 枚ずつ入れる
 *
 * @phpstan-import-type Group from ProductCsvGroups
 * @phpstan-import-type Row from ProductCsvGroups
 *
 * @phpstan-type Failure array{line: int, column: string, message: string}
 * @phpstan-type Counts array{create: int, update: int, skip: int, error: int}
 *
 * @phpstan-import-type Files from ProductCsvPhotos
 * @phpstan-import-type Photo from ProductCsvPhotos
 *
 * @phpstan-type PhotoReport array{add: int, keep: int, warnings: list<array{line: int, column: string, message: string}>, warnings_total: int}
 * @phpstan-type Report array{format: string, rows: int, counts: Counts, products: array{create: int, update: int}, extra_variants: int, errors: list<array{line: int, column: string|null, message: string}>, errors_total: int, photos: PhotoReport}
 * @phpstan-type Done array{kinds: list<'create'|'update'|'skip'>, extra: int, listing_id: string, title: string|null, created: bool}
 */
final class ProductCsvImporter
{
    /** 返す誤りの行の数（それより多いときは数だけ） */
    public const int MAX_ERRORS_SHOWN = 200;

    private int $line = 0;

    private string $column = '';

    /** 新しい商品を登録する出品者（最初に作った出品者。1 回の取り込みの中で 1 度だけ読む） */
    private ?Seller $seller = null;

    /** @var array<string, Location|null> 出品者 => 在庫の拠点（1 回の取り込みの中で 1 度だけ読む） */
    private array $locations = [];

    public function __construct(
        private readonly EventStore $events,
        private readonly ListingService $listings,
        private readonly VariantService $variants,
        private readonly AddonService $addons,
        private readonly CategoryService $categories,
        private readonly InventoryOperations $inventory,
        private readonly ProductCsvReader $reader,
        private readonly ProductCsvPhotos $photos,
    ) {}

    /**
     * 確かめる（何も変えない）。$fromLine の行で始まる商品から、$until（microtime）を過ぎるまで。続きがあれば next にその商品の最初の行
     *
     * @param  array<string, string>  $created  前の取り込みで作った商品（商品名 => ID）
     * @param  Files|null  $files  一緒に上げた写真の ZIP の中身（上げていなければ null）
     * @return array{report: Report, next: int|null} report はこの回に見た商品の分だけ（記録の側で足し合わせる）
     *
     * @throws ValidationException ファイルの形が違う（file の欄）
     */
    public function check(string $csv, string $actor, array $created = [], int $fromLine = 0, ?float $until = null, ?array $files = null): array
    {
        ['format' => $format, 'rows' => $rows, 'groups' => $groups, 'has_options' => $hasOptions] = $this->parse($csv, $created, $fromLine);
        $counts = ['create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];
        $products = ['create' => 0, 'update' => 0];
        $extra = 0;
        $errors = [];
        $next = null;
        $photos = ['add' => 0, 'keep' => 0, 'warnings' => []];

        // 1 回に少なくとも商品 1 つは進める（締め切りを過ぎていても。進まない続きの仕事を出し続けない）
        foreach ($groups as $index => $group) {
            if ($index > 0 && $until !== null && microtime(true) >= $until) {
                $next = $group['rows'][0]['line'];

                break;
            }

            $found = self::errorsOf($group);

            if ($found === []) {
                DB::beginTransaction();

                try {
                    $this->events->lock();
                    $done = $this->processGroup($group, $hasOptions, $actor);
                    /** @var list<Photo> $wanted */
                    $wanted = $group['rows'][0]['values']['photos'] ?? [];
                    // 写真は書いた商品（新しい商品も、巻き戻す前のこの中ではある）の今の写真と照らす
                    $review = $wanted === [] ? null : $this->photos->review($wanted, $group['rows'][0]['line'], $done['listing_id'], $files);
                } catch (Throwable $e) {
                    $found[] = $this->failureOf($e);
                } finally {
                    DB::rollBack();
                }
            }

            if (isset($done) && $found === []) {
                foreach ($done['kinds'] as $kind) {
                    $counts[$kind]++;
                }

                $extra += $done['extra'];
                $products[$done['created'] ? 'create' : 'update'] += $done['created'] || array_diff($done['kinds'], ['skip']) !== [] ? 1 : 0;

                if (isset($review)) {
                    $photos['add'] += $review['add'];
                    $photos['keep'] += $review['keep'];
                    array_push($photos['warnings'], ...$review['warnings']);
                }
            } else {
                array_push($errors, ...$found);
                $counts['error'] += count($group['rows']);
            }

            unset($done, $review);
        }

        usort($errors, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        return ['report' => [
            'format' => $format->name,
            'rows' => $rows,
            'counts' => $counts,
            'products' => $products,
            'extra_variants' => $extra,
            'errors' => array_map(static fn (array $e): array => [
                'line' => $e['line'],
                'column' => $e['column'] === '' ? null : $format->headerOf($e['column']),
                'message' => $e['message'],
            ], array_slice($errors, 0, self::MAX_ERRORS_SHOWN)),
            'errors_total' => count($errors),
            'photos' => [
                'add' => $photos['add'],
                'keep' => $photos['keep'],
                'warnings' => array_map(static fn (array $w): array => ['line' => $w['line'], 'column' => $format->headerOf($w['column']), 'message' => $w['message']],
                    array_slice($photos['warnings'], 0, self::MAX_ERRORS_SHOWN)),
                'warnings_total' => count($photos['warnings']),
            ],
        ], 'next' => $next];
    }

    /**
     * 反映する。$fromLine の行で始まる商品から、商品 1 つずつ短いトランザクションで確定し、そのたびに $progress を呼ぶ（記録の進み具合を書く）。
     * $until（microtime）を過ぎたら、次の商品の最初の行を next に返して止める（続きは次のキューの仕事）。
     * 断られたら、そこで止めて failure（止まった行と理由）を返す
     *
     * @param  array<string, string>  $created  前の取り込みとこの取り込みで作った商品（商品名 => ID）
     * @param  Closure(Group, Done): void  $progress  商品 1 つを確定した後に（トランザクションの外で）
     * @return array{failure: Failure|null, next: int|null}
     *
     * @throws ValidationException ファイルの形が違う（file の欄）
     */
    public function apply(string $csv, string $actor, array $created, int $fromLine, ?float $until, Closure $progress): array
    {
        ['groups' => $groups, 'has_options' => $hasOptions] = $this->parse($csv, $created, $fromLine);

        // 確かめで誤りが無かったファイルでも、その後に店の中身が変わっていれば（品番がほかの商品に付いたなど）、書く前に止める
        foreach ($groups as $group) {
            $found = self::errorsOf($group);

            if ($found !== []) {
                return ['failure' => $found[0], 'next' => null];
            }
        }

        foreach ($groups as $index => $group) {
            if ($index > 0 && $until !== null && microtime(true) >= $until) {
                return ['failure' => null, 'next' => $group['rows'][0]['line']];
            }

            try {
                $done = DB::transaction(function () use ($group, $hasOptions, $actor): array {
                    $this->events->lock();

                    return $this->processGroup($group, $hasOptions, $actor);
                });
            } catch (Throwable $e) {
                return ['failure' => $this->failureOf($e), 'next' => null];
            }

            $progress($group, $done);
        }

        return ['failure' => null, 'next' => null];
    }

    /**
     * @param  array<string, string>  $created
     * @return array{format: ProductCsvFormat, rows: int, groups: list<Group>, has_options: bool} groups は最初の行が $fromLine 以上の商品だけ
     */
    private function parse(string $csv, array $created, int $fromLine): array
    {
        [$this->seller, $this->locations] = [null, []];

        return ProductCsvGroups::read($this->reader, $csv, $created, $fromLine);
    }

    /**
     * 行の確かめ（ProductCsvRows）と行の並べ方（ProductCsvGroups）で見つかった誤り
     *
     * @param  Group  $group
     * @return list<Failure>
     */
    private static function errorsOf(array $group): array
    {
        $found = [];

        foreach ($group['rows'] as $row) {
            foreach ($row['errors'] as $error) {
                $found[] = ['line' => $row['line'], 'column' => $error['column'], 'message' => $error['message']];
            }
        }

        if ($group['error'] !== null) {
            $found[] = $group['error'];
        }

        return $found;
    }

    /**
     * 核のサービスの断りを、今の行の誤りにする。知らない例外は投げ直す（全部を巻き戻して 500。半端には入らない）
     *
     * @return Failure
     */
    private function failureOf(Throwable $e): array
    {
        return match (true) {
            $e instanceof RowRejected => ['line' => $e->row, 'column' => $e->column, 'message' => $e->getMessage()],
            $e instanceof ValidationException => ['line' => $this->line, 'column' => $this->columnOf($e), 'message' => self::firstMessage($e)],
            $e instanceof InsufficientStockException => ['line' => $this->line, 'column' => 'stock', 'message' => '在庫を、注文で確保している数より少なくはできません。'],
            $e instanceof UniqueConstraintViolationException => ['line' => $this->line, 'column' => 'sku', 'message' => 'この品番は、ほかの種類がもう使っています。'],
            // 連鎖のロックの中で読み直しているので起きないはず。起きたら行の誤りとして止める
            $e instanceof StaleListingException => ['line' => $this->line, 'column' => '', 'message' => '取り込みの途中で、この商品がほかの操作で変更されました。もう一度取り込んでください。'],
            $e instanceof InvalidArgumentException => ['line' => $this->line, 'column' => $this->column, 'message' => $e->getMessage()],
            default => throw $e,
        };
    }

    /**
     * 1 つの商品を作る・直す。行ごとの結果（create・update・skip）を返す
     *
     * @param  Group  $group
     * @return Done 行ごとの結果・CSV に無いのにできた種類の数・商品
     */
    private function processGroup(array $group, bool $hasOptions, string $actor): array
    {
        $first = $group['rows'][0]['values'];
        $this->at($group['rows'][0]['line'], '');
        $created = $group['listing_id'] === null;
        $mark = $this->sequence();

        if ($created) {
            $listing = $this->create($first, $actor);
        } else {
            $listing = Listing::query()->whereKey($group['listing_id'])->lockForUpdate()->first();

            if ($listing === null || $listing->kind !== 'product') {
                throw new RowRejected($this->line, 'listing_id', 'この商品ID の商品が見つかりません（削除した商品や、ほかの店の商品は更新できません）。');
            }

            $changes = [];

            foreach (['title', 'unit_amount', 'status', 'summary', 'description', 'tax_class'] as $key) {
                if (! array_key_exists($key, $first)) {
                    continue;
                }

                // 商品名・価格・状態・税率は、空なら変えない（空にはできない）
                if ($first[$key] === null && in_array($key, ['title', 'unit_amount', 'status', 'tax_class'], true)) {
                    continue;
                }

                $changes[$key] = match ($key) {
                    'status' => ListingStatus::from((string) $first[$key]),
                    'tax_class' => TaxClass::from((string) $first[$key]),
                    default => $first[$key],
                };
            }

            if ($changes !== []) {
                $this->column = 'title';
                $listing = $this->listings->update($listing, $listing->version, $changes, $actor);
            }
        }

        // 新しい商品の空のカテゴリ・オプションは、何もしない（もともと無い）
        if (array_key_exists('categories', $first) && ! ($created && $first['categories'] === [])) {
            $this->assignCategories($listing, $first['categories'], $actor);
        }

        if (array_key_exists('addons', $first) && ! ($created && $first['addons'] === [])) {
            $this->defineAddons($listing, $first['addons'], $actor);
        }

        $productChanged = $this->sequence() !== $mark;
        $before = ListingVariant::query()->where('listing_id', $listing->id)->pluck('id')->all();
        $variants = $this->defineOptions($listing, $created, $group['rows'], $hasOptions, $actor);
        // CSV に無い組み合わせも、値を足すと種類ができる（在庫 0・販売中）。確かめの画面で数を知らせる
        $added = ListingVariant::query()->where('listing_id', $listing->id)->whereNotIn('id', $before)->count();
        $extra = $added - count(array_unique(array_map(static fn (ListingVariant $v): string => $v->id, array_filter($variants, static fn (ListingVariant $v): bool => ! in_array($v->id, $before, true)))));
        $kinds = [];

        foreach ($group['rows'] as $index => $row) {
            $this->at($row['line'], '');
            $variant = $variants[$index];
            $rowMark = $this->sequence();
            $this->updateVariant($listing, $variant, $row['values'], $actor);

            if (($row['values']['stock'] ?? null) !== null) {
                $this->setStock($listing, $variant, (int) $row['values']['stock'], $actor);
            }

            $kinds[] = match (true) {
                $created || ! in_array($variant->id, $before, true) => 'create',
                $this->sequence() !== $rowMark || ($index === 0 && $productChanged) => 'update',
                default => 'skip',
            };
        }

        return ['kinds' => $kinds, 'extra' => $extra, 'listing_id' => $listing->id, 'title' => $listing->title, 'created' => $created];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function create(array $values, string $actor): Listing
    {
        if (! is_string($values['title'] ?? null)) {
            throw new RowRejected($this->line, 'title', '新しい商品には商品名が要ります。');
        }

        if (! is_int($values['unit_amount'] ?? null)) {
            throw new RowRejected($this->line, 'unit_amount', '新しい商品には価格が要ります。');
        }

        $this->seller ??= Seller::query()->orderBy('created_at')->orderBy('id')->first();
        $seller = $this->seller ?? throw new RowRejected($this->line, '', '商品を登録する販売者がありません。');
        $data = ['title' => $values['title'], 'unit_amount' => $values['unit_amount'], 'status' => ListingStatus::from((string) ($values['status'] ?? ListingStatus::Draft->value))];

        foreach (['summary', 'description'] as $key) {
            if (($values[$key] ?? null) !== null) {
                $data[$key] = $values[$key];
            }
        }

        // 税率（ADR 0063）。無ければ標準
        if (($values['tax_class'] ?? null) !== null) {
            $data['tax_class'] = TaxClass::from((string) $values['tax_class']);
        }

        $this->column = 'title';

        return $this->listings->create($seller, 'product', $data, $actor);
    }

    /**
     * 選ぶ項目と値を合わせ、行ごとの種類を返す（行の順）。今の商品は、項目の名前と順を変えず、無い値だけを後ろに足す
     *
     * @param  non-empty-list<array{line: int, values: array<string, mixed>, errors: list<array{column: string, message: string}>}>  $rows
     * @return list<ListingVariant>
     */
    private function defineOptions(Listing $listing, bool $created, array $rows, bool $hasOptions, string $actor): array
    {
        if (! $hasOptions) {
            // 選ぶ項目の列が無い形: 品番で種類を探す。見つからなければ、軸の無い商品のただ 1 つの種類（新しい商品は 1 行だけ）
            if ($created && count($rows) > 1) {
                throw new RowRejected($rows[1]['line'], 'sku', '種類のある新しい商品には、選ぶ項目の列が要ります。');
            }

            return array_map(function (array $row) use ($listing): ListingVariant {
                $this->at($row['line'], 'sku');
                $sku = $row['values']['sku'] ?? null;
                $variant = is_string($sku) ? ListingVariant::query()->where('listing_id', $listing->id)->where('sku', $sku)->first() : null;

                return $variant ?? $listing->soleVariant()
                    ?? throw new RowRejected($row['line'], 'sku', 'どの種類の行か分かりません。選ぶ項目の列か、登録済みの種類の品番を入れてください。');
            }, $rows);
        }

        /** @var list<array{0: string, 1: string}> $wantedOptions */
        $wantedOptions = $rows[0]['values']['options'] ?? [];

        // 新しい商品で選ぶ項目が無い: ただ 1 つの種類（行は 1 つ。同じ組み合わせの 2 行は ProductCsvGroups が断る）
        if ($created && $wantedOptions === []) {
            return [ListingVariant::query()->where('listing_id', $listing->id)->sole()];
        }

        $current = ListingOption::query()->where('listing_id', $listing->id)->orderBy('position')->with('values')->get();
        $names = array_column($wantedOptions, 0);
        $this->at($rows[0]['line'], 'option1_name');
        $currentNames = $current->pluck('name')->all();

        if (! $created && $currentNames !== $names) {
            throw new RowRejected($rows[0]['line'], 'option1_name', $currentNames === []
                ? 'この商品には選ぶ項目がありません。項目を追加するときは、商品の画面で追加してください（CSV でできるのは、登録済みの項目に値を追加することだけです）。'
                : '選ぶ項目は、登録済みの商品と同じ名前・同じ順にしてください（登録済み: '.implode(' / ', $currentNames).'）。項目を変えるときは、商品の画面で変更してください。');
        }

        // 値の並び: 今の値の後ろに、CSV の順で無い値を足す
        $values = [];

        foreach ($current as $index => $option) {
            $values[$index] = $option->values->pluck('label')->all();
        }

        foreach ($rows as $row) {
            foreach ($row['values']['options'] ?? [] as $index => [, $label]) {
                $values[$index] ??= [];

                if (! in_array($label, $values[$index], true)) {
                    $values[$index][] = $label;
                }
            }
        }

        $options = array_map(static fn (string $name, int $index): array => ['name' => $name, 'values' => $values[$index] ?? []], $names, array_keys($names));
        $now = $current->map(static fn (ListingOption $o): array => ['name' => $o->name, 'values' => $o->values->pluck('label')->all()])->all();

        if ($options !== $now) {
            $this->variants->define($listing, $this->version($listing), $options, $actor);
        }

        // 種類を値の名前の組み合わせで引く（名前は ID から。「 / 」を含む値でも取り違えない）
        $labels = ListingOption::query()->where('listing_id', $listing->id)->with('values')->get()
            ->flatMap(static fn (ListingOption $o) => $o->values->pluck('label', 'id'))->all();
        $byLabels = [];

        foreach (ListingVariant::query()->where('listing_id', $listing->id)->get() as $variant) {
            $byLabels[ProductCsvGroups::key(array_map(static fn (string $id): string => (string) ($labels[$id] ?? ''), $variant->valueIds()))] = $variant;
        }

        return array_map(static fn (array $row): ListingVariant => $byLabels[ProductCsvGroups::key(array_column($row['values']['options'] ?? [], 1))]
            ?? throw new RowRejected($row['line'], 'option1_value', '種類が見つかりません。'), $rows);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function updateVariant(Listing $listing, ListingVariant $variant, array $values, string $actor): void
    {
        $changes = [];

        if (array_key_exists('sku', $values)) {
            $changes['sku'] = $values['sku'];
        }

        if (array_key_exists('variant_amount', $values)) {
            $changes['unit_amount'] = $values['variant_amount'];
        }

        if (($values['variant_status'] ?? null) !== null) {
            $changes['status'] = VariantStatus::from((string) $values['variant_status']);
        }

        // 変わらない項目は渡さない（サービスも変わらなければ何もしないが、読み直しを減らす）
        $changes = array_filter($changes, static fn (mixed $value, string $key): bool => $value !== $variant->getAttribute($key), ARRAY_FILTER_USE_BOTH);

        if ($changes === []) {
            return;
        }

        $this->column = array_key_exists('sku', $changes) ? 'sku' : 'variant_amount';
        $this->variants->update($variant, $this->version($listing), $changes, $actor);
    }

    /**
     * 在庫を「この数にする」（出品者の最初の拠点で。差を棚卸しの調整として記録する）
     */
    private function setStock(Listing $listing, ListingVariant $variant, int $target, string $actor): void
    {
        $this->column = 'stock';

        if (! array_key_exists($listing->seller_id, $this->locations)) {
            $locationId = ProductCsvCatalog::locationIdFor($listing->seller_id);
            $this->locations[$listing->seller_id] = $locationId === null ? null : Location::query()->find($locationId);
        }

        $location = $this->locations[$listing->seller_id];

        if ($location === null) {
            throw new RowRejected($this->line, 'stock', '在庫を置く拠点が登録されていません。');
        }

        $current = (int) InventoryStock::query()->where('variant_id', $variant->id)->where('location_id', $location->id)->value('quantity');

        if ($target !== $current) {
            $this->inventory->adjust($variant, $location, $target - $current, AdjustmentReason::Stocktake, $actor);
        }
    }

    /**
     * @param  list<string>  $slugs
     */
    private function assignCategories(Listing $listing, array $slugs, string $actor): void
    {
        $this->column = 'categories';
        $ids = Category::query()->whereIn('slug', $slugs)->pluck('id', 'slug');
        $missing = array_values(array_diff($slugs, $ids->keys()->all()));

        if ($missing !== []) {
            throw new RowRejected($this->line, 'categories', 'カテゴリが見つかりません: '.implode('・', array_map(CsvCells::preview(...), $missing)).'（先にカテゴリの画面で作ってください）');
        }

        $this->categories->assign($listing, array_map(static fn (string $slug): string => (string) $ids[$slug], $slugs), null, $actor);
    }

    /**
     * @param  list<mixed>  $addons
     */
    private function defineAddons(Listing $listing, array $addons, string $actor): void
    {
        $this->column = 'addons';

        if (ProductDescription::canonical(ProductCsvCatalog::addonsOf($listing->id)) === ProductDescription::canonical($addons)) {
            return;
        }

        $this->addons->define($listing, $this->version($listing), $addons, $actor);
    }

    private function version(Listing $listing): int
    {
        return (int) Listing::query()->whereKey($listing->id)->value('version');
    }

    private function sequence(): int
    {
        return (int) DomainEvent::query()->max('sequence');
    }

    private function at(int $line, string $column): void
    {
        $this->line = $line;
        $this->column = $column;
    }

    /**
     * サービスの誤りの欄の名前を、CSV の列の鍵に写す
     */
    private function columnOf(ValidationException $e): string
    {
        $field = (string) array_key_first($e->errors());

        return match (true) {
            str_starts_with($field, 'description') => 'description',
            str_starts_with($field, 'summary') => 'summary',
            str_starts_with($field, 'addons') => 'addons',
            str_starts_with($field, 'options') => 'option1_name',
            str_starts_with($field, 'categor') => 'categories',
            $field === 'title' => 'title',
            $field === 'sku' => 'sku',
            default => $this->column,
        };
    }

    private static function firstMessage(ValidationException $e): string
    {
        $messages = $e->errors();
        $first = reset($messages);

        return is_array($first) && is_string($first[0] ?? null) ? $first[0] : '値が正しくありません。';
    }
}
