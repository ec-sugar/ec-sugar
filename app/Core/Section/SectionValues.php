<?php

declare(strict_types=1);

namespace App\Core\Section;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 区画の値を宣言（SectionSchema）どおりに確かめ、決まった形にそろえる（ADR 0028 の 2）。
 *
 * 形: { 区画: { hidden: bool, values: { 欄: 値 }, items: [ { 欄: 値 } ] } }。宣言した欄はすべて入れ、空は null（カテゴリの並びは []）。
 * - validate: 保存のとき。違うところを道筋（sections.hero.values.heading）ごとに ValidationException で返す
 * - sanitize: 読むとき。崩れた値（テーマを更新して宣言が変わった、など）は捨てて空にする（ショップを止めない）
 *
 * 値の種類ごとの決まり:
 * - 文: 前後の空白を除き、空なら null。制御文字は断る（複数行は改行だけ通す。改行は \n にそろえる）
 * - 写真: ショップの写真の ID（ULID、小文字にそろえる）。あるかどうかは保存の側がロックの中で確かめる
 * - リンク: 店の中の道筋（/ で始まり // で始まらない）か https の URL（利用者名・パスワードを含まない）
 * - 商品の並び: { source: new|category|pick, category: ID|null, ids: [ID] }（pick は 1 つ以上、max まで）
 * - カテゴリの並び: ID の並び（重ならない、max まで）
 * - 選択肢: 宣言した値のどれか
 * - HTML（html・richtext）: 保存のときは SectionHtml::clean で決まったタグと属性だけに整えた HTML をしまう（大きすぎる・深すぎるものは断る）。
 *   読むときは文字列であることと大きさだけを見る（描く前に SectionResolver が SectionHtml::render でもう 1 度整える）
 *
 * ページの並び（ADR 0039）: [ { id, type, pattern, hidden, values, items, blocks: [ { type, values } ] } ]。
 * - validatePage: 保存のとき。置けない種類・無いパターン・無いブロック・知らない欄は断る（道筋は sections.0.values.heading）。
 *   ID が無い区画は新しく足した区画として ID を付ける。ID は 1 つのページの中で重ならない
 * - sanitizePage: 読むとき。置けなくなった種類・ID の崩れた区画は外し、無くなったパターンは最初のパターンに戻す。
 *   ページをまだ保存していなければ、テーマの最初の並びを空の値で返す
 *
 * @phpstan-type Section array{hidden: bool, values: array<string, mixed>, items: list<array<string, mixed>>}
 * @phpstan-type Block array{type: string, values: array<string, mixed>}
 * @phpstan-type Placed array{id: string, type: string, pattern: string, hidden: bool, values: array<string, mixed>, items: list<array<string, mixed>>, blocks: list<Block>}
 */
final class SectionValues
{
    /** 並びの中の区画の ID（新しく足した区画は小文字の ULID。最初の並びと今の書き方からの写しは種類の名前） */
    public const string SECTION_ID = '/\A[a-z0-9][a-z0-9_-]{0,39}\z/';

    private const int MAX_ERRORS = 50;

    private const string ULID = '/\A[0-9a-hjkmnp-tv-z]{26}\z/';

    /** 外の URL に使える文字（RFC 3986 の文字から ' を除く。" < > ` 空白は通さない。テーマのエスケープに頼り切らない） */
    private const string EXTERNAL_URL = '#\Ahttps://[A-Za-z0-9\-._~:/?\#\[\]@!$&()*+,;=%]+\z#';

    private const string INTERNAL_PATH = '#\A/(?![/\\\\])[A-Za-z0-9\-._~/%?=&\#+]*\z#';

    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param  string|null  $schemaPath  テーマの宣言の見本を確かめているとき、その区画の道筋（最初の誤りで宣言の誤りとして止める）
     */
    private function __construct(private readonly bool $strict, private readonly ?string $schemaPath = null) {}

    /**
     * @return array<string, array{hidden: bool, values: array<string, mixed>, items: list<array<string, mixed>>}>
     *
     * @throws ValidationException
     */
    public static function validate(SectionSchema $schema, mixed $input): array
    {
        $walker = new self(strict: true);
        $values = $walker->sections($schema, $input);

        if ($walker->errors !== []) {
            throw ValidationException::withMessages($walker->errors);
        }

        return $values;
    }

    /**
     * @return array<string, array{hidden: bool, values: array<string, mixed>, items: list<array<string, mixed>>}>
     */
    public static function sanitize(SectionSchema $schema, mixed $stored): array
    {
        return (new self(strict: false))->sections($schema, $stored);
    }

    /**
     * テーマの宣言の見本（theme.json の example・example_blocks）を、店の人の値と同じ決まりで確かめる（登録のとき。SectionSchema から）。
     * 最初の誤りで InvalidSectionSchemaException を投げる（登録は Laravel が起きる前にも走るので、ValidationException を作らない）。
     * 道筋は {$path}.example.heading・{$path}.example.items.0.title・{$path}.example_blocks.0.values.label の形
     *
     * @param  array<array-key, mixed>  $values  欄の見本
     * @param  mixed  $items  繰り返しの見本
     * @param  mixed  $blocks  ブロックの見本
     * @return array{values: array<string, mixed>, items: list<array<string, mixed>>, blocks: list<Block>}
     *
     * @throws InvalidSectionSchemaException
     */
    public static function validateExample(SectionSpec $spec, array $values, mixed $items, mixed $blocks, string $path): array
    {
        $walker = new self(strict: true, schemaPath: $path);
        $section = $walker->section($spec, ['values' => $values, 'items' => $items], 'example');

        return ['values' => $section['values'], 'items' => $section['items'], 'blocks' => $walker->blocks($spec, $blocks, 'example_blocks')];
    }

    /**
     * 値の中のショップの写真の ID（保存の前に、あるかを確かめるため）
     *
     * @param  array<string, array{hidden: bool, values: array<string, mixed>, items: list<array<string, mixed>>}>  $values  validate か sanitize の結果
     * @return list<string>
     */
    public static function imageIds(SectionSchema $schema, array $values): array
    {
        $ids = [];

        foreach ($values as $key => $section) {
            $spec = $schema->section($key);

            if ($spec === null) {
                continue;
            }

            foreach ([$section['values'], ...$section['items']] as $i => $row) {
                foreach ($i === 0 ? $spec->fields : $spec->itemFields as $field) {
                    if ($field->type === FieldType::Image && is_string($row[$field->key] ?? null)) {
                        $ids[] = $row[$field->key];
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * ページの並びを確かめる（保存のとき）
     *
     * @return list<Placed>
     *
     * @throws ValidationException
     */
    public static function validatePage(SectionSchema $schema, PageSpec $page, mixed $input): array
    {
        $walker = new self(strict: true);
        $sections = $walker->page($schema, $page, $input);

        if ($walker->errors !== []) {
            throw ValidationException::withMessages($walker->errors);
        }

        return $sections;
    }

    /**
     * しまってある並びを今の宣言で読み直す（読むとき）。null（まだ保存していないページ）はテーマの最初の並び
     *
     * @return list<Placed>
     */
    public static function sanitizePage(SectionSchema $schema, PageSpec $page, mixed $stored): array
    {
        return $stored === null ? self::defaultPage($schema, $page) : (new self(strict: false))->page($schema, $page, $stored);
    }

    /**
     * テーマの最初の並び（値は空）。ID は種類の名前（2 つ目からは「種類-2」）にして、読むたびに同じにする
     *
     * @return list<Placed>
     */
    public static function defaultPage(SectionSchema $schema, PageSpec $page): array
    {
        $walker = new self(strict: false);
        $out = [];
        $seen = [];

        foreach ($page->default as $entry) {
            $spec = $schema->section($entry['type']);

            if ($spec === null) {
                continue;
            }

            $seen[$spec->key] = ($seen[$spec->key] ?? 0) + 1;
            $out[] = ['id' => $seen[$spec->key] === 1 ? $spec->key : "{$spec->key}-{$seen[$spec->key]}", 'type' => $spec->key, 'pattern' => $entry['pattern']]
                + $walker->section($spec, [], 'sections') + ['blocks' => []];
        }

        return $out;
    }

    /**
     * 種類ごとの最初の区画を、今の形（{ 種類: { hidden, values, items } }）で読む（$sections->get と、今の形で読む ThemeContentService::draft のため）
     *
     * @param  list<Placed>  $sections
     * @return array<string, Section>
     */
    public static function firstOfEachType(array $sections): array
    {
        $out = [];

        foreach ($sections as $section) {
            $out[$section['type']] ??= ['hidden' => $section['hidden'], 'values' => $section['values'], 'items' => $section['items']];
        }

        return $out;
    }

    /**
     * 今の形（種類 => 値）で受け取った値を、並びに写す（今の値を写すコマンド・入れたときのサンプル・見本の店の seeder のため。
     * 管理画面の「種類 => 値」の口は で外した。中身はページの編集の画面〔ページの並びの口〕だけで入れる）。
     * - 決まった並び: 宣言の順に 1 つずつ。送らなかった区画は空（今までどおり、保存は丸ごと置き換え）
     * - 並びのあるページ: 今の並びの、その種類の最初の区画の値を置き換える（ID・パターン・ブロックはそのまま）。
     *   送らなかった種類の区画は外し、並びに無い種類は後ろに足す。同じ種類の 2 つ目からはそのまま。
     *   核の汎用の区画（basic_*）は今の形では送れない（テーマの宣言の種類だけ）ので、送らなくても外さない（トップにも置けるようになった。
     *   今の呼び手はバージョンの無い店で最初の並びに書くだけだが、すでにある並びに書いても、ページの編集の画面で足した核の区画を消さないよう残す）
     *
     * @param  array<string, Section>  $keyed  validate の結果
     * @param  list<Placed>  $current  今の並び（sanitizePage の結果）
     * @return list<array<string, mixed>> validatePage に渡す形
     */
    public static function fromKeyed(SectionSchema $schema, PageSpec $page, array $keyed, array $current): array
    {
        $out = [];
        $used = [];

        foreach ($current as $section) {
            $type = $section['type'];

            if (! array_key_exists($type, $keyed)) {
                if ($page->fixed) {
                    $out[] = ['hidden' => false, 'values' => [], 'items' => []] + $section;
                } elseif (str_starts_with($type, CoreSections::PREFIX)) {
                    $out[] = $section;
                }

                continue;
            }

            $out[] = isset($used[$type]) ? $section : $keyed[$type] + $section;
            $used[$type] = true;
        }

        foreach ($keyed as $type => $value) {
            $spec = $schema->section($type);

            if (! isset($used[$type]) && $spec !== null) {
                $out[] = ['type' => $type, 'pattern' => $spec->defaultPattern()] + $value;
            }
        }

        return $out;
    }

    /**
     * 並びの中のショップの写真の ID（区画の欄・繰り返し・ブロック）
     *
     * @param  list<Placed>  $sections  validatePage か sanitizePage の結果
     * @return list<string>
     */
    public static function pageImageIds(SectionSchema $schema, array $sections): array
    {
        $ids = [];

        foreach ($sections as $section) {
            $spec = $schema->section($section['type']);

            if ($spec === null) {
                continue;
            }

            $ids = [...$ids, ...self::imageIds($schema, [$spec->key => $section])];

            foreach ($section['blocks'] as $block) {
                $blockSpec = $spec->blocks[$block['type']] ?? null;

                foreach ($blockSpec === null ? [] : $blockSpec->fields as $field) {
                    if ($field->type === FieldType::Image && is_string($block['values'][$field->key] ?? null)) {
                        $ids[] = $block['values'][$field->key];
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * 並びの中の HTML の欄（html・richtext）に書いた店の写真の ID を、欄の道筋ごとに（保存の前に、この店の置き場にあるかを
     * ほかの写真と同じロックの中で確かめるため。無ければその欄の道筋で断る）。写真の無い欄は入れない
     *
     * @param  list<Placed>  $sections  validatePage の結果（HTML は整えた形）
     * @return array<string, array{label: string, ids: list<string>}> 道筋（sections.0.values.html）=> 欄の名前と写真の ID
     */
    public static function pageHtmlImageIds(SectionSchema $schema, array $sections): array
    {
        $out = [];
        $collect = static function (array $fields, array $values, string $path) use (&$out): void {
            foreach ($fields as $field) {
                $value = $values[$field->key] ?? null;

                if ($field->type->isHtml() && is_string($value)) {
                    $ids = SectionHtml::mediaIds($value);

                    if ($ids !== []) {
                        $out["{$path}.{$field->key}"] = ['label' => $field->label, 'ids' => $ids];
                    }
                }
            }
        };

        foreach ($sections as $i => $section) {
            $spec = $schema->section($section['type']);

            if ($spec === null) {
                continue;
            }

            $collect($spec->fields, $section['values'], "sections.{$i}.values");

            foreach ($section['items'] as $j => $item) {
                $collect($spec->itemFields, $item, "sections.{$i}.items.{$j}");
            }

            foreach ($section['blocks'] as $j => $block) {
                $collect($spec->blocks[$block['type']]->fields ?? [], $block['values'], "sections.{$i}.blocks.{$j}.values");
            }
        }

        return $out;
    }

    /**
     * 並びの中のカテゴリの ID を、欄の道筋ごとに（保存の前に、この店にあるかを確かめるため。無ければその欄の道筋で断る）。
     * ID の場所は categoryUses と同じく欄の種類で決める: カテゴリの並び（categories）の ID（道筋は sections.0.values.list）と、
     * 商品の並び（products）の出どころが「カテゴリから」のときの category（道筋は sections.0.values.products.category）。カテゴリの無い欄は入れない
     *
     * @param  list<Placed>  $sections  validatePage の結果（ID は小文字にそろえた形）
     * @return array<string, array{label: string, ids: list<string>}> 道筋 => 欄の名前とカテゴリの ID
     */
    public static function pageCategoryIds(SectionSchema $schema, array $sections): array
    {
        $out = [];
        $collect = static function (array $fields, array $values, string $path) use (&$out): void {
            foreach ($fields as $field) {
                $value = $values[$field->key] ?? null;

                if ($field->type === FieldType::Categories && is_array($value) && $value !== []) {
                    $out["{$path}.{$field->key}"] = ['label' => $field->label, 'ids' => array_values(array_filter($value, 'is_string'))];
                } elseif ($field->type === FieldType::Products && is_array($value) && ($value['source'] ?? null) === 'category' && is_string($value['category'] ?? null)) {
                    $out["{$path}.{$field->key}.category"] = ['label' => $field->label, 'ids' => [$value['category']]];
                }
            }
        };

        foreach ($sections as $i => $section) {
            $spec = $schema->section($section['type']);

            if ($spec === null) {
                continue;
            }

            $collect($spec->fields, $section['values'], "sections.{$i}.values");

            foreach ($section['items'] as $j => $item) {
                $collect($spec->itemFields, $item, "sections.{$i}.items.{$j}");
            }

            foreach ($section['blocks'] as $j => $block) {
                $collect($spec->blocks[$block['type']]->fields ?? [], $block['values'], "sections.{$i}.blocks.{$j}.values");
            }
        }

        return $out;
    }

    /**
     * しまってある並び（読み直す前の値）の中で、そのカテゴリを指す区画の名前を並びの順に（カテゴリを消す前に確かめる）。
     * ID の場所は欄の種類で決める: カテゴリの並び（categories）の中の ID と、商品の並び（products）の出どころが「カテゴリから」のときの category。
     * 区画の欄・繰り返し・ブロックのどれも見る。値の文字列を種類を見ずに探さない（文の欄にたまたま同じ文字があっても数えない）。
     *
     * 読み直し（sanitizePage）は通さない: そのページに今は置けない種類（止めた App の区画など）も、しまってある限りまた出うるので数える。
     * 宣言に無い種類（外したテーマの固有の区画など）は欄の種類が分からないので数えない
     *
     * @return list<string> 区画の種類の名前（同じ名前の区画が 2 つあれば 2 回）
     */
    public static function categoryUses(SectionSchema $schema, mixed $stored, string $categoryId): array
    {
        if (! is_array($stored) || ! array_is_list($stored)) {
            return [];
        }

        $id = strtolower($categoryId);
        $points = static function (array $fields, mixed $row) use ($id): bool {
            if (! is_array($row)) {
                return false;
            }

            foreach ($fields as $field) {
                $value = $row[$field->key] ?? null;

                $hit = match ($field->type) {
                    FieldType::Categories => is_array($value) && in_array($id, array_map(static fn (mixed $v): string => is_string($v) ? strtolower($v) : '', $value), true),
                    FieldType::Products => is_array($value) && ($value['source'] ?? null) === 'category'
                        && is_string($value['category'] ?? null) && strtolower($value['category']) === $id,
                    default => false,
                };

                if ($hit) {
                    return true;
                }
            }

            return false;
        };

        $labels = [];

        foreach ($stored as $entry) {
            $spec = is_array($entry) && is_string($entry['type'] ?? null) ? $schema->section($entry['type']) : null;

            if ($spec === null) {
                continue;
            }

            $items = is_array($entry['items'] ?? null) ? $entry['items'] : [];
            $blocks = is_array($entry['blocks'] ?? null) ? $entry['blocks'] : [];
            $hit = $points($spec->fields, $entry['values'] ?? null);

            foreach ($items as $item) {
                $hit = $hit || $points($spec->itemFields, $item);
            }

            foreach ($blocks as $block) {
                $blockSpec = is_array($block) && is_string($block['type'] ?? null) ? ($spec->blocks[$block['type']] ?? null) : null;
                $hit = $hit || ($blockSpec !== null && $points($blockSpec->fields, $block['values'] ?? null));
            }

            if ($hit) {
                $labels[] = $spec->label;
            }
        }

        return $labels;
    }

    /**
     * @return list<Placed>
     */
    private function page(SectionSchema $schema, PageSpec $page, mixed $input): array
    {
        if (! is_array($input) || ! array_is_list($input)) {
            $this->fail('sections', 'セクションの並びの形式が正しくありません。');

            return $this->strict ? [] : self::defaultPage($schema, $page);
        }

        if (count($input) > PageSpec::MAX_SECTIONS) {
            $this->fail('sections', '1 つのページに置けるセクションは '.PageSpec::MAX_SECTIONS.' 個までです。');
            $input = array_slice($input, 0, PageSpec::MAX_SECTIONS);
        }

        $out = [];
        $ids = [];
        $counts = [];

        foreach ($input as $i => $entry) {
            $path = "sections.{$i}";

            if (! is_array($entry) || ($entry !== [] && array_is_list($entry))) {
                $this->fail($path, 'セクションの形式が正しくありません。');

                continue;
            }

            foreach (array_diff(array_map('strval', array_keys($entry)), ['id', 'type', 'pattern', 'hidden', 'values', 'items', 'blocks']) as $unknown) {
                $this->fail("{$path}.{$unknown}", '使えない項目です。');
            }

            $type = $entry['type'] ?? null;
            $spec = is_string($type) && $page->allows($type) ? $schema->section($type) : null;

            if ($spec === null) {
                $this->fail("{$path}.type", 'このページに置けないセクションです。');

                continue;
            }

            $pattern = $entry['pattern'] ?? null;
            if ($pattern === null || (! $this->strict && (! is_string($pattern) || ! $spec->hasPattern($pattern)))) {
                // 選ばなかった・テーマの更新で無くなったパターンは、最初のパターン
                $pattern = $spec->defaultPattern();
            } elseif (! is_string($pattern) || ! $spec->hasPattern($pattern)) {
                $this->fail("{$path}.pattern", "「{$spec->label}」に無いパターンです。");

                continue;
            }

            $id = $entry['id'] ?? null;
            if ($id === null && $this->strict) {
                // 新しく足した区画
                $id = strtolower((string) Str::ulid());
            } elseif (! is_string($id) || preg_match(self::SECTION_ID, $id) !== 1) {
                $this->fail("{$path}.id", 'セクションの ID の形式が正しくありません。');

                continue;
            } elseif (isset($ids[$id])) {
                $this->fail("{$path}.id", 'セクションの ID が重複しています。');

                continue;
            }

            $counts[$spec->key] = ($counts[$spec->key] ?? 0) + 1;
            if ($spec->limit !== null && $counts[$spec->key] > $spec->limit) {
                $this->fail("{$path}.type", "「{$spec->label}」は 1 つのページに {$spec->limit} 個までです。");

                continue;
            }

            $ids[$id] = true;
            $body = $this->section($spec, array_intersect_key($entry, ['hidden' => true, 'values' => true, 'items' => true]), $path);

            // App の区画の係が、欄の値をもう一段確かめる（動画の URL の形など。ADR 0040）。保存のときだけ。宣言に無い欄の名前は無視する
            if ($this->strict && $spec->check !== null) {
                foreach (($spec->check)($body['values']) as $field => $message) {
                    if (isset($spec->fields[$field])) {
                        $this->fail("{$path}.values.{$field}", mb_substr($message, 0, 200));
                    }
                }
            }
            $blocks = $this->blocks($spec, $entry['blocks'] ?? [], "{$path}.blocks");

            // 核の区画の、ブロックをまたぐ決まり（値段の表のおすすめの印は 1 つまで、など。CoreSections::blockRules）。保存のときだけ
            if ($this->strict) {
                foreach (CoreSections::blockRules($spec->key, $blocks) as $where => $message) {
                    $this->fail("{$path}.{$where}", $message);
                }
            }
            $out[] = ['id' => $id, 'type' => $spec->key, 'pattern' => $pattern] + $body + ['blocks' => $blocks];
        }

        return $page->fixed ? $this->fixed($schema, $page, $out) : $out;
    }

    /**
     * 決まった並びのページ: 宣言の順に 1 つずつ。保存のときは並びを変えたら断り、読むときは宣言の順に並べ直す
     *
     * @param  list<Placed>  $sections
     * @return list<Placed>
     */
    private function fixed(SectionSchema $schema, PageSpec $page, array $sections): array
    {
        if ($this->strict && array_column($sections, 'type') !== array_column($page->default, 'type')) {
            $this->fail('sections', 'このテーマのページは、セクションの追加・削除・並べ替えはできません。');
        }

        $byType = [];
        foreach ($sections as $section) {
            $byType[$section['type']] ??= $section;
        }

        return array_map(static fn (array $empty): array => $byType[$empty['type']] ?? $empty, self::defaultPage($schema, $page));
    }

    /**
     * @return list<Block>
     */
    private function blocks(SectionSpec $spec, mixed $blocks, string $path): array
    {
        if ($blocks === [] || $blocks === null) {
            return [];
        }

        if (! is_array($blocks) || ! array_is_list($blocks)) {
            $this->fail($path, 'ブロックの並びの形式が正しくありません。');

            return [];
        }

        if (! $spec->hasBlocks()) {
            $this->fail($path, "「{$spec->label}」にはブロックがありません。");

            return [];
        }

        if (count($blocks) > $spec->maxBlocks) {
            $this->fail($path, "「{$spec->label}」のブロックは {$spec->maxBlocks} 個までです。");
            $blocks = array_slice($blocks, 0, $spec->maxBlocks);
        }

        $out = [];
        foreach ($blocks as $i => $block) {
            $type = $block['type'] ?? null;
            $blockSpec = is_string($type) ? ($spec->blocks[$type] ?? null) : null;

            if ($blockSpec === null || ! is_array($block)) {
                $this->fail("{$path}.{$i}.type", "「{$spec->label}」に置けないブロックです。");

                continue;
            }

            foreach (array_diff(array_map('strval', array_keys($block)), ['type', 'values']) as $unknown) {
                $this->fail("{$path}.{$i}.{$unknown}", '使えない項目です。');
            }

            $values = $block['values'] ?? [];
            if (! is_array($values) || ($values !== [] && array_is_list($values))) {
                $this->fail("{$path}.{$i}.values", '欄の形式が正しくありません。');
                $values = [];
            }

            $out[] = ['type' => $blockSpec->key, 'values' => $this->row($blockSpec->fields, $values, "{$path}.{$i}.values")];
        }

        return $out;
    }

    /**
     * @return array<string, array{hidden: bool, values: array<string, mixed>, items: list<array<string, mixed>>}>
     */
    private function sections(SectionSchema $schema, mixed $input): array
    {
        if (! is_array($input) || ($input !== [] && array_is_list($input))) {
            $this->fail('sections', 'セクションの形式が正しくありません。');

            return [];
        }

        $out = [];

        foreach ($input as $key => $section) {
            $spec = is_string($key) ? $schema->section($key) : null;

            if ($spec === null) {
                $this->fail("sections.{$key}", 'このテーマに無いセクションです。');

                continue;
            }

            $out[$key] = $this->section($spec, $section, "sections.{$key}");
        }

        // 宣言の順にそろえる
        return array_intersect_key(array_merge(array_fill_keys(array_keys($schema->sections), null), $out), $out);
    }

    /**
     * @return array{hidden: bool, values: array<string, mixed>, items: list<array<string, mixed>>}
     */
    private function section(SectionSpec $spec, mixed $section, string $path): array
    {
        $empty = ['hidden' => false, 'values' => $this->row($spec->fields, [], $path), 'items' => []];

        if (! is_array($section) || ($section !== [] && array_is_list($section))) {
            $this->fail($path, 'セクションの形式が正しくありません。');

            return $empty;
        }

        foreach (array_diff(array_map('strval', array_keys($section)), ['hidden', 'values', 'items']) as $unknown) {
            $this->fail("{$path}.{$unknown}", '使えない項目です。');
        }

        $hidden = $section['hidden'] ?? false;
        if (! is_bool($hidden)) {
            $this->fail("{$path}.hidden", '表示・非表示の指定が正しくありません。');
            $hidden = false;
        }

        $values = $section['values'] ?? [];
        if (! is_array($values) || ($values !== [] && array_is_list($values))) {
            $this->fail("{$path}.values", '欄の形式が正しくありません。');
            $values = [];
        }

        $items = $section['items'] ?? [];
        if (! is_array($items) || ! array_is_list($items)) {
            $this->fail("{$path}.items", '繰り返し項目の形式が正しくありません。');
            $items = [];
        } elseif (! $spec->hasItems() && $items !== []) {
            $this->fail("{$path}.items", 'このセクションには繰り返し項目がありません。');
            $items = [];
        } elseif (count($items) > $spec->maxItems) {
            $this->fail("{$path}.items", "「{$spec->label}」は {$spec->maxItems} 個までです。");
            $items = array_slice($items, 0, $spec->maxItems);
        }

        $rows = [];
        foreach ($items as $i => $item) {
            if (! is_array($item) || ($item !== [] && array_is_list($item))) {
                $this->fail("{$path}.items.{$i}", '繰り返し項目の形式が正しくありません。');

                continue;
            }

            $rows[] = $this->row($spec->itemFields, $item, "{$path}.items.{$i}");
        }

        return ['hidden' => $hidden, 'values' => $this->row($spec->fields, $values, "{$path}.values"), 'items' => $rows];
    }

    /**
     * @param  array<string, FieldSpec>  $fields
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>
     */
    private function row(array $fields, array $input, string $path): array
    {
        foreach (array_diff_key($input, $fields) as $unknown => $_) {
            $this->fail("{$path}.{$unknown}", '使えない欄です。');
        }

        $out = [];

        foreach ($fields as $key => $field) {
            $out[$key] = $this->field($field, $input[$key] ?? null, "{$path}.{$key}");
        }

        return $out;
    }

    private function field(FieldSpec $field, mixed $value, string $path): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return $field->empty();
        }

        $label = "「{$field->label}」";

        return match ($field->type) {
            FieldType::Text, FieldType::Textarea => $this->text($field, $value, $path, $label),
            FieldType::Image => $this->ulid($value) ?? $this->fail($path, "{$label}の写真の指定が正しくありません。"),
            FieldType::Link => $this->link($value) ?? $this->fail($path, "{$label}は、ショップ内のページ（/products など）か、https:// で始まる URL（".FieldType::LINK_MAX.' 文字まで）を入力してください。'),
            FieldType::Products => $this->products($field, $value, $path, $label),
            FieldType::Categories => $this->categories($field, $value, $path, $label),
            FieldType::Choice => is_string($value) && array_key_exists($value, $field->options) ? $value : $this->fail($path, "{$label}は選択肢から選んでください。"),
            FieldType::Html, FieldType::Richtext => $this->html($field, $value, $path, $label),
        };
    }

    /**
     * HTML の欄。保存のときは整えた HTML を返す（外したものがあっても断らない。管理画面は整えた結果と外したものを
     * 確かめの API で見せる）。大きさは整えた後の形で見る（SectionHtml::clean。しまった形をそのまま送り返されても同じ数になり、
     * しまう形が描くときの上限を越えるものは断る）。読むときは整えずに返す（描くときに整える。整え直すと保存済みの値の形が読むたびに変わる）
     */
    private function html(FieldSpec $field, mixed $value, string $path, string $label): ?string
    {
        if (! is_string($value)) {
            return $this->fail($path, "{$label}は文字で入力してください。");
        }

        if (! $this->strict) {
            return mb_check_encoding($value, 'UTF-8') && mb_strlen($value) <= SectionHtml::MAX_STORED_CHARS ? $value : null;
        }

        $clean = SectionHtml::clean($value, (int) $field->max);

        if ($clean->refused !== null) {
            return $this->fail($path, "{$label}: {$clean->refused}");
        }

        return $clean->html === '' ? null : $clean->html;
    }

    private function text(FieldSpec $field, mixed $value, string $path, string $label): ?string
    {
        if (! is_string($value)) {
            return $this->fail($path, "{$label}は文字で入力してください。");
        }

        $multiline = $field->type === FieldType::Textarea;
        $text = trim($multiline ? str_replace(["\r\n", "\r"], "\n", $value) : $value);

        // 制御文字（C0・DEL・C1）は断る。複数行の文だけ改行を通す。壊れた UTF-8 もここで断る
        if (preg_match($multiline ? '/[^\P{Cc}\n]/u' : '/\p{Cc}/u', $text) !== 0) {
            return $this->fail($path, $multiline ? "{$label}に使えない文字が入っています。" : "{$label}は改行の無い 1 行にしてください。");
        }

        if (mb_strlen($text) > (int) $field->max) {
            return $this->fail($path, "{$label}は {$field->max} 文字までです。");
        }

        return $text === '' ? null : $text;
    }

    private function ulid(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $id = strtolower($value);

        return preg_match(self::ULID, $id) === 1 ? $id : null;
    }

    private function link(mixed $value): ?string
    {
        return self::safeLink($value);
    }

    /**
     * リンクの決まり（区画のリンクの欄と、区画の HTML の href。SectionHtmlAttributes）: 店の中の道筋か https の URL。合わなければ null
     */
    public static function safeLink(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > FieldType::LINK_MAX) {
            return null;
        }

        $link = trim($value);

        if (preg_match(self::INTERNAL_PATH, $link) === 1) {
            return $link;
        }

        $parts = parse_url($link);

        // https だけ。利用者名・パスワード付き（https://shop@evil.example）は見た目と行き先が違うので断る
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])
            || filter_var($link, FILTER_VALIDATE_URL) === false || preg_match(self::EXTERNAL_URL, $link) !== 1) {
            return null;
        }

        return $link;
    }

    /**
     * @return array{source: string, category: string|null, ids: list<string>}|null
     */
    private function products(FieldSpec $field, mixed $value, string $path, string $label): ?array
    {
        if (! is_array($value) || array_diff(array_map('strval', array_keys($value)), ['source', 'category', 'ids']) !== []) {
            return $this->fail($path, "{$label}の形式が正しくありません。");
        }

        $source = $value['source'] ?? null;
        if (! is_string($source) || ! in_array($source, $field->sources, true)) {
            return $this->fail("{$path}.source", "{$label}の取得元を選んでください。");
        }

        $category = null;
        if ($source === 'category') {
            $category = $this->ulid($value['category'] ?? null) ?? $this->fail("{$path}.category", "{$label}のカテゴリを選んでください。");

            if ($category === null) {
                return null;
            }
        }

        $ids = [];
        if ($source === 'pick') {
            $ids = $this->idList($value['ids'] ?? null, (int) $field->max, "{$path}.ids", "{$label}の商品");

            if ($ids === null || $ids === []) {
                return $ids === [] ? $this->fail("{$path}.ids", "{$label}の商品を 1 つ以上選んでください。") : null;
            }
        }

        return ['source' => $source, 'category' => $category, 'ids' => $ids];
    }

    /**
     * @return list<string>
     */
    private function categories(FieldSpec $field, mixed $value, string $path, string $label): array
    {
        return $this->idList($value, (int) $field->max, $path, "{$label}のカテゴリ") ?? [];
    }

    /**
     * @return list<string>|null
     */
    private function idList(mixed $value, int $max, string $path, string $label): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return $this->fail($path, "{$label}の形式が正しくありません。");
        }

        $ids = [];
        foreach ($value as $id) {
            $ulid = $this->ulid($id);

            if ($ulid === null) {
                return $this->fail($path, "{$label}の指定が正しくありません。");
            }

            $ids[$ulid] = $ulid;
        }

        if (count($ids) !== count($value)) {
            $this->fail($path, "{$label}が重複しています。");
        }

        if (count($ids) > $max) {
            $this->fail($path, "{$label}は {$max} 個までです。");
            $ids = array_slice($ids, 0, $max, true);
        }

        return array_values($ids);
    }

    /**
     * 厳しいときは誤りを残す。どちらでも値は空（null）にする
     */
    private function fail(string $path, string $message): null
    {
        if ($this->schemaPath !== null) {
            $where = str_starts_with($path, 'example.values.') ? 'example.'.substr($path, strlen('example.values.')) : $path;

            throw new InvalidSectionSchemaException("{$this->schemaPath}.{$where}", "見本の値が宣言の決まりに合わない（{$message}）");
        }

        if ($this->strict) {
            $this->errors[$path] ??= $message;

            // 知らない名前を大量に送られても、誤りの一覧を作り続けない
            if (count($this->errors) >= self::MAX_ERRORS) {
                throw ValidationException::withMessages($this->errors);
            }
        }

        return null;
    }
}
