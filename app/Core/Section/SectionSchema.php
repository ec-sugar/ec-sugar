<?php

declare(strict_types=1);

namespace App\Core\Section;

/**
 * テーマが宣言する区画の全体（theme.json の sections と pages。ADR 0028 の 2・ADR 0039）。
 * 核は宣言の形を確かめるだけで、どんな区画があるかは決めない。知らない書き方（打ち間違い）は断る。
 * 上限は核の決まり（FieldType::cap など）を越えられない。
 *
 * sections は区画の「種類」の宣言（キーが種類の名前）。pages はページごとに置ける種類と最初の並び。
 * pages を書かない今の書き方のテーマは、宣言した区画を宣言の順に 1 つずつ置いた「決まった並び」のトップとして読む（PageSpec の fixed）
 *
 * 自由なページ（ADR 0039 区切り 3）: pages.page は店の人が作るページの雛形。書かなければ核の汎用の区画（CoreSections）の雛形。
 * 区画の種類は、テーマの宣言に無ければ核の汎用の区画（basic_ で始まる名前）からも探す（ページの types に入っている時だけ置ける。
 * トップにも置ける）
 *
 * App の区画（ADR 0040。名前は app_ で始まる）: 店で使う App の区画を withApps で足した宣言は、決まった並びでないどのページにも置ける
 * （ページの types の後ろに足す）。足すのは店の文脈の中（AppSectionCatalog）で、テーマの宣言そのものは店に関係なく同じ
 */
final readonly class SectionSchema
{
    public const int MAX_SECTIONS = 30;

    /** 1 つの区画の種類のパターンの数 */
    public const int MAX_PATTERNS = 6;

    /** 1 つの区画の種類に置けるブロックの種類の数 */
    public const int MAX_BLOCK_TYPES = 10;

    /** 1 つの区画の中のブロックの数（核の上限） */
    public const int MAX_BLOCKS = 20;

    public const int MAX_FIELDS = 20;

    public const int MAX_ITEMS = 12;

    /** 選択肢の欄の選択肢の数。核の特長の絵（SectionIcons）もこの数まで（でサービス向けの絵を足して 20 → 30。広げるだけなので今のテーマは書き直さなくてよい） */
    public const int MAX_OPTIONS = 30;

    /** App の区画の名前の頭（テーマは宣言できない。ADR 0040） */
    public const string APP_PREFIX = 'app_';

    private const string KEY = '/\A[a-z][a-z0-9_]{0,31}\z/';

    /** @var array<string, PageSpec> */
    public array $pages;

    /**
     * @param  array<string, SectionSpec>  $sections  宣言の順
     * @param  array<string, PageSpec>|null  $pages  ページの宣言（null なら今の書き方: 決まった並びのトップ）
     * @param  PageSpec|null  $declaredFreePage  テーマが宣言した自由なページの雛形（pages.page。null なら核の汎用の区画の雛形を使う）
     * @param  array<string, SectionSpec>  $apps  店で使う App の区画（種類 => 宣言。ADR 0040）
     * @param  array<string, string>  $appTitles  App の区画の種類 => App の名前（編集の画面の「App」の印）
     */
    public function __construct(public array $sections, ?array $pages = null, private ?PageSpec $declaredFreePage = null, private array $apps = [], private array $appTitles = [])
    {
        $this->pages = $pages ?? ['home' => self::fixedHome($sections)];
    }

    /**
     * 自由なページの雛形（テーマの pages.page。書かなければ核の汎用の区画の雛形）
     */
    public function freePage(): PageSpec
    {
        return $this->declaredFreePage ?? CoreSections::freePage();
    }

    /**
     * テーマが自由なページの雛形を宣言したか（しなければ核の汎用の区画で作る）
     */
    public function declaresFreePage(): bool
    {
        return $this->declaredFreePage !== null;
    }

    /**
     * pages を書かないテーマのトップ: 宣言した区画を宣言の順に 1 つずつ、パターンは 1 つ（default）
     *
     * @param  array<string, SectionSpec>  $sections
     */
    private static function fixedHome(array $sections): PageSpec
    {
        return new PageSpec('home', array_keys($sections),
            array_map(static fn (SectionSpec $s): array => ['type' => $s->key, 'pattern' => $s->defaultPattern()], array_values($sections)), fixed: true);
    }

    /**
     * 店で使う App の区画を足した宣言（ADR 0040）。決まった並びでないページの types の後ろに、App の区画の種類を足す
     *
     * @param  array<string, SectionSpec>  $apps  種類 => 宣言
     * @param  array<string, string>  $titles  種類 => App の名前
     */
    public function withApps(array $apps, array $titles = []): self
    {
        return new self($this->sections, $this->pages, $this->declaredFreePage, $apps, $titles);
    }

    /**
     * App の区画か（店で使う App の分。足していなければ偽）
     */
    public function isApp(string $key): bool
    {
        return isset($this->apps[$key]);
    }

    /**
     * ページの宣言。自由なページ（page-{ID}）は雛形から作る（そのページが店にあるかは呼ぶ側が確かめる）。
     * 店で使う App の区画は、決まった並びでないページの types の後ろに足す
     */
    public function page(string $name): ?PageSpec
    {
        $page = $this->pages[$name] ?? (PageSpec::freeId($name) === null ? null : $this->freePage()->forFreePage($name));

        if ($page === null || $page->fixed || $this->apps === []) {
            return $page;
        }

        return $page->withTypes([...$page->types, ...array_values(array_diff(array_keys($this->apps), $page->types))]);
    }

    /**
     * テーマが宣言した区画か（核の汎用の区画は含めない）
     */
    public function has(string $key): bool
    {
        return isset($this->sections[$key]);
    }

    /**
     * 区画の種類の宣言（テーマの宣言に無ければ、核の汎用の区画から。店で使う App の区画も）
     */
    public function section(string $key): ?SectionSpec
    {
        return $this->sections[$key] ?? CoreSections::section($key) ?? $this->apps[$key] ?? null;
    }

    /**
     * その役目（ヒーローなど）を持つテーマの区画のうち、そのページに置ける最初の種類（ページの types の順）。無ければ null。
     * 核の区画と App の区画は役目を持たない（役目はテーマの宣言だけ）
     */
    public function typeFor(SectionRole $role, PageSpec $page): ?string
    {
        foreach ($page->types as $type) {
            if (($this->sections[$type] ?? null)?->role === $role) {
                return $type;
            }
        }

        return null;
    }

    /**
     * そのページに置ける種類の宣言（管理画面に渡す。テーマの宣言に、ページが使う核の汎用の区画を足したもの）
     *
     * @return array<string, array<string, mixed>>
     */
    public function toArrayFor(PageSpec $page): array
    {
        $out = $this->toArray();

        foreach ($page->types as $type) {
            if (! isset($out[$type]) && ($spec = CoreSections::section($type) ?? $this->apps[$type] ?? null) !== null) {
                // App の区画には App の名前を添える（編集の画面の「App」の印）
                $out[$type] = $spec->toArray() + (isset($this->appTitles[$type]) ? ['app' => $this->appTitles[$type]] : []);
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $sections  theme.json の sections
     *
     * @throws InvalidSectionSchemaException
     */
    public static function fromArray(array $sections, string $path = 'sections'): self
    {
        if ($sections !== [] && array_is_list($sections)) {
            throw new InvalidSectionSchemaException($path, 'セクションの名前をキーにした形で書く');
        }

        if (count($sections) > self::MAX_SECTIONS) {
            throw new InvalidSectionSchemaException($path, 'セクションは '.self::MAX_SECTIONS.' 個まで');
        }

        $specs = [];

        foreach ($sections as $key => $section) {
            $key = self::key($key, $path);
            $specs[$key] = self::parseSection($key, $section, "{$path}.{$key}");
        }

        return new self($specs);
    }

    /**
     * theme.json の sections と pages（pages は書かなくてよい。書かなければ決まった並びのトップ）
     *
     * @param  array<mixed>  $sections
     *
     * @throws InvalidSectionSchemaException
     */
    public static function fromManifest(array $sections, mixed $pages = null): self
    {
        $schema = self::fromArray($sections);

        // basic_ で始まる名前は核の汎用の区画のために、app_ で始まる名前は App の区画（ADR 0040）のために取ってある
        // （同じ名前で別の欄を宣言すると、核や App の部品が読み違える）
        foreach (array_keys($schema->sections) as $type) {
            if (str_starts_with($type, CoreSections::PREFIX) || str_starts_with($type, self::APP_PREFIX)) {
                throw new InvalidSectionSchemaException("sections.{$type}", CoreSections::PREFIX.'・'.self::APP_PREFIX.' で始まる名前は本体と App のセクションのために取ってある');
            }
        }

        // HTML の欄（html・richtext）は核の区画「自由な HTML」「見たまま書ける文」だけが使う。テーマの部品が整えた HTML の
        // 出し方（HtmlString）を取り違えないよう、今はテーマに宣言させない（使えるようにするときは、テーマ API の約束として決めてから）
        foreach ($schema->sections as $type => $spec) {
            $fields = [...array_values($spec->fields), ...array_values($spec->itemFields)];
            foreach ($spec->blocks as $block) {
                $fields = [...$fields, ...array_values($block->fields)];
            }

            foreach ($fields as $field) {
                if ($field->type->isHtml()) {
                    throw new InvalidSectionSchemaException("sections.{$type}", "{$field->type->value} の欄は本体のセクションだけが使える（{$field->key}）");
                }
            }
        }

        if ($pages === null) {
            return $schema;
        }

        $pages = self::object($pages, 'pages', [...array_keys(PageSpec::PAGES), PageSpec::FREE]);

        if (! array_key_exists('home', $pages)) {
            throw new InvalidSectionSchemaException('pages', 'pages を書くなら home（トップ）を書く');
        }

        $specs = [];
        $free = null;
        foreach ($pages as $name => $page) {
            if ($name === PageSpec::FREE) {
                $free = self::parsePage($schema, $name, $page, "pages.{$name}")->forFreePage(PageSpec::FREE);
            } else {
                $specs[$name] = self::parsePage($schema, $name, $page, "pages.{$name}");
            }
        }

        // どのページにも置けない種類は、値をしまう場所が無い（書き間違い）
        $placed = array_merge(...array_values(array_map(static fn (PageSpec $p): array => $p->types, [...$specs, ...($free === null ? [] : [$free])])));
        foreach (array_keys($schema->sections) as $type) {
            if (! in_array($type, $placed, true)) {
                throw new InvalidSectionSchemaException("sections.{$type}", 'どのページの types にも無いセクション');
            }
        }

        return new self($schema->sections, $specs, $free);
    }

    /**
     * 管理画面に渡すページの形
     *
     * @return array<string, array<string, mixed>>
     */
    public function pagesToArray(): array
    {
        return array_map(static fn (PageSpec $p): array => $p->toArray(), $this->pages);
    }

    private static function parsePage(self $schema, string $name, mixed $page, string $path): PageSpec
    {
        $page = self::object($page, $path, ['types', 'default']);
        $types = $page['types'] ?? null;

        if (! is_array($types) || $types === [] || ! array_is_list($types) || count($types) !== count(array_unique($types, SORT_REGULAR))) {
            throw new InvalidSectionSchemaException("{$path}.types", '置けるセクションの種類の名前の並び（1 つ以上、重ならない）');
        }

        foreach ($types as $type) {
            // どのページにも、核の汎用の区画（basic_*）を置ける（自由なページの雛形は区切り 3 から。トップは から）
            if (! is_string($type) || ! ($schema->has($type) || CoreSections::section($type) !== null)) {
                throw new InvalidSectionSchemaException("{$path}.types", '宣言に無いセクション: '.(is_string($type) ? $type : '?'));
            }
        }

        $default = $page['default'] ?? [];
        if (! is_array($default) || ! array_is_list($default) || count($default) > PageSpec::MAX_SECTIONS) {
            throw new InvalidSectionSchemaException("{$path}.default", '最初の並び（{type, pattern} の並び、'.PageSpec::MAX_SECTIONS.' 個まで）');
        }

        $entries = [];
        $counts = [];
        foreach ($default as $i => $entry) {
            $entry = self::object($entry, "{$path}.default.{$i}", ['type', 'pattern']);
            $type = $entry['type'] ?? null;
            $spec = is_string($type) && in_array($type, $types, true) ? $schema->section($type) : null;

            if ($spec === null) {
                throw new InvalidSectionSchemaException("{$path}.default.{$i}.type", 'このページの types にあるセクション');
            }

            $pattern = $entry['pattern'] ?? $spec->defaultPattern();
            if (! is_string($pattern) || ! $spec->hasPattern($pattern)) {
                throw new InvalidSectionSchemaException("{$path}.default.{$i}.pattern", "セクション {$spec->key} に宣言したパターン");
            }

            $counts[$spec->key] = ($counts[$spec->key] ?? 0) + 1;
            if ($spec->limit !== null && $counts[$spec->key] > $spec->limit) {
                throw new InvalidSectionSchemaException("{$path}.default", "セクション {$spec->key} は {$spec->limit} 個まで");
            }

            $entries[] = ['type' => $spec->key, 'pattern' => $pattern];
        }

        /** @var list<string> $types */
        return new PageSpec($name, $types, $entries);
    }

    /**
     * 管理画面に渡す形
     *
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (SectionSpec $s): array => $s->toArray(), $this->sections);
    }

    private static function parseSection(string $key, mixed $section, string $path): SectionSpec
    {
        $section = self::object($section, $path, ['label', 'fields', 'items', 'patterns', 'blocks', 'max_blocks', 'limit', 'example', 'example_blocks', 'wireframe', 'role']);
        $label = self::label($section['label'] ?? null, "{$path}.label");
        $fields = self::fields($section['fields'] ?? [], "{$path}.fields");

        $items = $section['items'] ?? null;
        $itemFields = [];
        $maxItems = 0;
        $itemLabel = null;

        if ($items !== null) {
            $items = self::object($items, "{$path}.items", ['label', 'max', 'fields']);
            $itemLabel = array_key_exists('label', $items) ? self::label($items['label'], "{$path}.items.label") : null;
            $maxItems = self::limit($items['max'] ?? null, self::MAX_ITEMS, "{$path}.items.max", required: true);
            $itemFields = self::fields($items['fields'] ?? [], "{$path}.items.fields");

            if ($itemFields === []) {
                throw new InvalidSectionSchemaException("{$path}.items.fields", '繰り返しの組には欄が 1 つ以上いる');
            }
        }

        [$patterns, $wireframes] = array_key_exists('patterns', $section) ? self::patterns($section['patterns'], "{$path}.patterns") : [[SectionSpec::DEFAULT_PATTERN => '標準'], []];
        // 区画に書いたワイヤーの絵は、絵を書いていないパターンの絵（パターンを宣言しない区画は、これだけで書く）
        if (array_key_exists('wireframe', $section)) {
            $fallback = self::wireframe($section['wireframe'], "{$path}.wireframe");
            $own = $wireframes;
            $wireframes = [];
            foreach (array_keys($patterns) as $pattern) {
                $wireframes[$pattern] = $own[$pattern] ?? $fallback;
            }
        }

        $blocks = array_key_exists('blocks', $section) ? self::blocks($section['blocks'], "{$path}.blocks") : [];
        if (array_key_exists('max_blocks', $section) && $blocks === []) {
            throw new InvalidSectionSchemaException("{$path}.max_blocks", 'max_blocks は blocks を書いたセクションだけに');
        }
        $maxBlocks = $blocks === [] ? 0 : (self::limit($section['max_blocks'] ?? null, self::MAX_BLOCKS, "{$path}.max_blocks") ?? self::MAX_BLOCKS);
        $limit = self::limit($section['limit'] ?? null, PageSpec::MAX_SECTIONS, "{$path}.limit");

        if ($fields === [] && $itemFields === [] && $blocks === []) {
            throw new InvalidSectionSchemaException($path, '欄が 1 つも無い');
        }

        // 役目（ページの雛形がテーマの区画を選ぶのに使う）。核の決まった名前だけ
        $role = null;
        if (array_key_exists('role', $section)) {
            $role = is_string($section['role']) ? SectionRole::tryFrom($section['role']) : null;
            if ($role === null) {
                throw new InvalidSectionSchemaException("{$path}.role", '役目は '.implode('・', array_column(SectionRole::cases(), 'value')).' のどれか');
            }
        }

        $spec = new SectionSpec($key, $label, $fields, $itemFields, (int) $maxItems, $itemLabel, $patterns, $blocks, $maxBlocks, $limit, wireframes: $wireframes, role: $role);

        return array_key_exists('example', $section) || array_key_exists('example_blocks', $section)
            ? self::example($spec, $section['example'] ?? null, $section['example_blocks'] ?? null, $path)
            : $spec;
    }

    /**
     * 見本（example・example_blocks）を、店の人の値と同じ決まり（形・長さ・リンクの形・ブロックの種類・数の上限・知らない欄）で確かめて付ける。
     * - example は欄の名前 => 値（values と同じ形）。繰り返しのある区画は items に見本の並びを書く（繰り返しだけの区画は並びをそのまま書いてもよい）
     * - example_blocks は [{ type, values }] の並び
     * - 写真・選んだ商品・カテゴリは書けない（見本で店の写真や商品を勝手に選ばない。どの店にも無い ID になる）。商品の並びは新着（{"source": "new"}）だけ
     */
    private static function example(SectionSpec $spec, mixed $example, mixed $blocks, string $path): SectionSpec
    {
        if ($blocks !== null && ! $spec->hasBlocks()) {
            throw new InvalidSectionSchemaException("{$path}.example_blocks", 'example_blocks は blocks を書いたセクションだけに');
        }

        if ($blocks !== null && (! is_array($blocks) || ! array_is_list($blocks))) {
            throw new InvalidSectionSchemaException("{$path}.example_blocks", '[{ type, values }] の並びで書く');
        }

        $values = [];
        $items = [];

        if (is_array($example) && $example !== [] && array_is_list($example)) {
            // 繰り返しだけの区画は、見本の並びをそのまま書いてよい
            if (! $spec->hasItems()) {
                throw new InvalidSectionSchemaException("{$path}.example", '欄の名前 => 値 の {} の形で書く（並びは繰り返しのあるセクションだけ）');
            }
            $items = $example;
        } elseif (is_array($example) || $example === null) {
            $values = $example ?? [];

            if ($spec->hasItems() && array_key_exists('items', $values)) {
                $items = $values['items'];
                unset($values['items']);
            }
        } else {
            throw new InvalidSectionSchemaException("{$path}.example", '欄の名前 => 値 の {} の形で書く');
        }

        $checked = SectionValues::validateExample($spec, $values, $items, $blocks ?? [], $path);

        self::exampleHasNoShopData($spec->fields, $checked['values'], "{$path}.example");
        foreach ($checked['items'] as $i => $item) {
            self::exampleHasNoShopData($spec->itemFields, $item, "{$path}.example.items.{$i}");
        }
        foreach ($checked['blocks'] as $i => $block) {
            self::exampleHasNoShopData($spec->blocks[$block['type']]->fields, $block['values'], "{$path}.example_blocks.{$i}.values");
        }

        $hasValues = $example !== null;

        return $spec->withExample($hasValues ? ['values' => $checked['values'], 'items' => $checked['items']] : null, $checked['blocks']);
    }

    /**
     * 見本に店のデータ（写真・選んだ商品・カテゴリの ID）を入れていないか
     *
     * @param  array<string, FieldSpec>  $fields
     * @param  array<string, mixed>  $row
     */
    private static function exampleHasNoShopData(array $fields, array $row, string $path): void
    {
        foreach ($fields as $key => $field) {
            $value = $row[$key] ?? null;
            $shopData = match ($field->type) {
                FieldType::Image => $value !== null,
                FieldType::Categories => $value !== [],
                FieldType::Products => is_array($value) && $value['source'] !== 'new',
                // HTML の見本に店の写真を書かない（整えた後の形で見る。写真は店の写真の置き場の道筋だけが残る）
                FieldType::Html, FieldType::Richtext => is_string($value) && str_contains($value, '<img'),
                default => false,
            };

            if ($shopData) {
                throw new InvalidSectionSchemaException("{$path}.{$key}", '見本に写真・商品・カテゴリは選ばない（選ぶのは店の人。商品の並びは {"source": "new"} だけ）');
            }
        }
    }

    /**
     * パターンの名前 => { label, wireframe（任意） }
     *
     * @return array{array<string, string>, array<string, string>} 名前 => 画面の名前、名前 => ワイヤーの絵（書いたものだけ）
     */
    private static function patterns(mixed $patterns, string $path): array
    {
        if (! is_array($patterns) || $patterns === [] || array_is_list($patterns) || count($patterns) > self::MAX_PATTERNS) {
            throw new InvalidSectionSchemaException($path, 'パターンの名前 => { label } の形で 1〜'.self::MAX_PATTERNS.' 個');
        }

        $labels = [];
        $wireframes = [];
        foreach ($patterns as $name => $pattern) {
            $name = self::key($name, $path);
            $pattern = self::object($pattern, "{$path}.{$name}", ['label', 'wireframe']);
            $labels[$name] = self::label($pattern['label'] ?? null, "{$path}.{$name}.label");

            if (array_key_exists('wireframe', $pattern)) {
                $wireframes[$name] = self::wireframe($pattern['wireframe'], "{$path}.{$name}.wireframe");
            }
        }

        return [$labels, $wireframes];
    }

    /**
     * ワイヤーの絵の名前（核の語彙だけ。テーマは絵を持ち込まない）
     */
    private static function wireframe(mixed $name, string $path): string
    {
        if (! is_string($name) || ! Wireframe::known($name)) {
            throw new InvalidSectionSchemaException($path, 'ワイヤーフレームは本体の語彙の名前（docs/design/theme-api.md の一覧）');
        }

        return $name;
    }

    /**
     * @return array<string, BlockSpec>
     */
    private static function blocks(mixed $blocks, string $path): array
    {
        if (! is_array($blocks) || $blocks === [] || array_is_list($blocks) || count($blocks) > self::MAX_BLOCK_TYPES) {
            throw new InvalidSectionSchemaException($path, 'ブロックの種類の名前 => { label, fields } の形で 1〜'.self::MAX_BLOCK_TYPES.' 個');
        }

        $out = [];
        foreach ($blocks as $name => $block) {
            $name = self::key($name, $path);
            $block = self::object($block, "{$path}.{$name}", ['label', 'fields']);
            $fields = self::fields($block['fields'] ?? [], "{$path}.{$name}.fields");

            if ($fields === []) {
                throw new InvalidSectionSchemaException("{$path}.{$name}.fields", 'ブロックには欄が 1 つ以上いる');
            }

            $out[$name] = new BlockSpec($name, self::label($block['label'] ?? null, "{$path}.{$name}.label"), $fields);
        }

        return $out;
    }

    /**
     * @return array<string, FieldSpec>
     */
    private static function fields(mixed $fields, string $path): array
    {
        if (! is_array($fields) || ($fields !== [] && array_is_list($fields))) {
            throw new InvalidSectionSchemaException($path, '欄の名前をキーにした形で書く');
        }

        if (count($fields) > self::MAX_FIELDS) {
            throw new InvalidSectionSchemaException($path, '欄は '.self::MAX_FIELDS.' 個まで');
        }

        $specs = [];

        foreach ($fields as $key => $field) {
            $key = self::key($key, $path);
            $specs[$key] = self::field($key, $field, "{$path}.{$key}");
        }

        return $specs;
    }

    private static function field(string $key, mixed $field, string $path): FieldSpec
    {
        $field = self::object($field, $path, ['type', 'label', 'max', 'options', 'from', 'help']);
        $type = is_string($field['type'] ?? null) ? FieldType::tryFrom($field['type']) : null;

        if ($type === null) {
            throw new InvalidSectionSchemaException("{$path}.type", '欄の種類は '.implode('・', array_column(FieldType::cases(), 'value')).' のどれか');
        }

        $label = self::label($field['label'] ?? null, "{$path}.label");
        $help = array_key_exists('help', $field) ? self::text($field['help'], 100, "{$path}.help") : null;

        $cap = $type->cap();
        if (array_key_exists('max', $field) && $cap === null) {
            throw new InvalidSectionSchemaException("{$path}.max", "{$type->value} には max を書かない");
        }
        $max = $cap === null ? null : (self::limit($field['max'] ?? null, $cap, "{$path}.max") ?? $cap);

        if (array_key_exists('options', $field) !== ($type === FieldType::Choice)) {
            throw new InvalidSectionSchemaException("{$path}.options", 'options は choice だけに、choice には必ず書く');
        }
        $options = $type === FieldType::Choice ? self::options($field['options'], "{$path}.options") : [];

        if (array_key_exists('from', $field) && $type !== FieldType::Products) {
            throw new InvalidSectionSchemaException("{$path}.from", 'from は products だけに書く');
        }
        $sources = $type === FieldType::Products ? self::sources($field['from'] ?? FieldType::PRODUCT_SOURCES, "{$path}.from") : [];

        return new FieldSpec($key, $type, $label, $max, $options, $sources, $help);
    }

    /**
     * @return array<string, string>
     */
    private static function options(mixed $options, string $path): array
    {
        if (! is_array($options) || $options === [] || array_is_list($options) || count($options) > self::MAX_OPTIONS) {
            throw new InvalidSectionSchemaException($path, '値 => 名前 の形で 1〜'.self::MAX_OPTIONS.' 個');
        }

        $out = [];

        foreach ($options as $value => $label) {
            $out[self::key($value, $path)] = self::label($label, "{$path}.{$value}");
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function sources(mixed $sources, string $path): array
    {
        if (! is_array($sources) || $sources === [] || ! array_is_list($sources)) {
            throw new InvalidSectionSchemaException($path, '取得元（'.implode('・', FieldType::PRODUCT_SOURCES).'）の並び');
        }

        foreach ($sources as $source) {
            if (! in_array($source, FieldType::PRODUCT_SOURCES, true)) {
                throw new InvalidSectionSchemaException($path, '取得元は '.implode('・', FieldType::PRODUCT_SOURCES).' のどれか');
            }
        }

        return array_values(array_unique($sources));
    }

    /**
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private static function object(mixed $value, string $path, array $allowed): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidSectionSchemaException($path, '{} の形で書く');
        }

        $unknown = array_diff(array_map('strval', array_keys($value)), $allowed);
        if ($unknown !== []) {
            throw new InvalidSectionSchemaException($path, '知らない書き方: '.implode('・', $unknown));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    private static function key(int|string $key, string $path): string
    {
        if (! is_string($key) || preg_match(self::KEY, $key) !== 1) {
            throw new InvalidSectionSchemaException($path, "名前「{$key}」は英小文字で始まる英小文字・数字・_ の 32 文字まで");
        }

        return $key;
    }

    private static function label(mixed $label, string $path): string
    {
        return self::text($label, 30, $path);
    }

    private static function text(mixed $text, int $max, string $path): string
    {
        if (! is_string($text) || trim($text) === '' || mb_strlen($text) > $max || preg_match('/\p{Cc}/u', $text) !== 0) {
            throw new InvalidSectionSchemaException($path, "1〜{$max} 文字の 1 行の文");
        }

        return trim($text);
    }

    private static function limit(mixed $value, int $cap, string $path, bool $required = false): ?int
    {
        if ($value === null && ! $required) {
            return null;
        }

        if (! is_int($value) || $value < 1 || $value > $cap) {
            throw new InvalidSectionSchemaException($path, "1〜{$cap} の整数");
        }

        return $value;
    }
}
