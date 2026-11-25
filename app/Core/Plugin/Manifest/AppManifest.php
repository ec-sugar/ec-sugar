<?php

declare(strict_types=1);

namespace App\Core\Plugin\Manifest;

use App\Core\Plugin\Sections\AppSection;
use App\Core\Plugin\Sections\EmbedRules;
use App\Core\Section\InvalidSectionSchemaException;
use App\Core\Section\SectionSchema;

/**
 * App のマニフェスト（composer.json の extra.ec-sugar。ADR 0034）のうち、核の口に関わるところ。
 * 登録のとき（PluginRegistry）に 1 度だけ読んで確かめ、形が違えば登録で止める（テーマの theme.json と同じく、アプリが起動しない。
 * 入れるのは審査した App だけなので CI で気づく）。知らない書き方（打ち間違い）は断る。HTML やスクリプトの文字を渡す口は無い。
 *
 *   settings   設定の項目の宣言（文字・切り替え・選ぶ・写真）
 *   disclosure 外部送信に関する公表（送信先の事業者・送る情報・目的・その事業者の案内のページ）
 *   storefront ショップへの差し込み: scripts（assets/ の .js と画面の種類）・csp（足す宛先）・consent（同意の種類）
 *   sections   ページに置ける区画（ADR 0040）。宣言はテーマの区画と同じ形（核の SectionSchema が同じ決まりで確かめる）に、
 *              consent（出すのに要る同意の種類。書かなければ要らない）と embed（外から埋め込むときの宛先・sandbox・allow）を足したもの
 *   category   App の目的（AppCategory の言葉を 1 つ。管理画面の App の画面のまとまり）。書かなければ「そのほか」
 */
final readonly class AppManifest
{
    public const int MAX_SCRIPTS = 5;

    public const int MAX_SETTINGS = 20;

    public const int MAX_OPTIONS = 20;

    public const int MAX_DISCLOSURES = 10;

    /** 1 つの App が足せる区画の種類の数 */
    public const int MAX_SECTIONS = 5;

    /** スクリプトのファイルの大きさの上限（バイト） */
    public const int MAX_SCRIPT_BYTES = 262144;

    /** スクリプトを置くディレクトリ（パッケージの根から）。ここの .js だけを配る */
    public const string ASSETS = 'assets';

    private const string KEY = '/\A[a-z][a-z0-9_]{0,31}\z/';

    private const string FILE = '/\A[a-z0-9][a-z0-9_-]{0,63}\.js\z/';

    private const string OPTION = '/\A[a-z0-9][a-z0-9_-]{0,31}\z/';

    /**
     * @param  list<AppScript>  $scripts
     * @param  array<string, list<string>>  $csp  CSP の種類 => 宛先
     * @param  list<ConsentKind>  $consent  差し込む前に要る同意（空なら同意は要らない）
     * @param  array<string, SettingSpec>  $settings  宣言の順
     * @param  list<Disclosure>  $disclosures
     * @param  array<string, AppSection>  $sections  区画の種類 => 宣言（宣言の順。ADR 0040）
     * @param  AppCategory  $category  App の目的（管理画面の App の画面のまとまり）
     */
    public function __construct(
        public array $scripts = [],
        public array $csp = [],
        public array $consent = [],
        public array $settings = [],
        public array $disclosures = [],
        public array $sections = [],
        public AppCategory $category = AppCategory::Other,
    ) {}

    /**
     * 区画に App の名前と係を入れたもの（登録簿が呼ぶ）
     *
     * @param  array<string, AppSection>  $sections
     */
    public function withSections(array $sections): self
    {
        return new self($this->scripts, $this->csp, $this->consent, $this->settings, $this->disclosures, $sections, $this->category);
    }

    /**
     * この App が使う同意の種類（スクリプトと区画の分を合わせて、決まった順）
     *
     * @return list<ConsentKind>
     */
    public function allConsent(): array
    {
        $kinds = [];

        foreach ([$this->consent, ...array_map(static fn (AppSection $s): array => $s->consent, array_values($this->sections))] as $list) {
            foreach ($list as $kind) {
                $kinds[$kind->value] = true;
            }
        }

        return array_values(array_filter(ConsentKind::cases(), static fn (ConsentKind $k): bool => isset($kinds[$k->value])));
    }

    public static function none(): self
    {
        return new self;
    }

    public function script(string $file): ?AppScript
    {
        foreach ($this->scripts as $script) {
            if ($script->file === $file) {
                return $script;
            }
        }

        return null;
    }

    public function setting(string $key): ?SettingSpec
    {
        return $this->settings[$key] ?? null;
    }

    /**
     * @param  array<mixed>  $manifest  composer.json の extra.ec-sugar（旧名のころのキー extra.sugar-ec でもよい。PackageManifest）
     * @param  string  $directory  パッケージの根（スクリプトは その下の assets/ だけ）
     * @param  string  $root  誤りの文で言う、マニフェストそのものの場所（読んだキー）
     *
     * @throws InvalidAppManifestException
     */
    public static function fromArray(array $manifest, string $directory, string $root = 'extra.'.PackageManifest::KEY): self
    {
        $manifest = self::object($manifest, $root, ['type', 'name', 'plugin_api', 'core', 'category', 'settings', 'disclosure', 'storefront', 'sections']);
        $category = array_key_exists('category', $manifest) ? self::category($manifest['category'], 'category') : AppCategory::Other;
        $settings = array_key_exists('settings', $manifest) ? self::settings($manifest['settings'], 'settings') : [];
        $disclosures = array_key_exists('disclosure', $manifest) ? self::disclosures($manifest['disclosure'], 'disclosure') : [];
        [$scripts, $csp, $consent] = array_key_exists('storefront', $manifest) ? self::storefront($manifest['storefront'], 'storefront', $directory) : [[], [], []];

        $sections = array_key_exists('sections', $manifest) ? self::sections($manifest['sections'], 'sections', is_string($manifest['name'] ?? null) ? $manifest['name'] : '') : [];

        // 外へ送る宛先を書いた App は、公表の文を必ず持つ（公表のページから漏れない）
        if ($csp !== [] && $disclosures === []) {
            throw new InvalidAppManifestException('disclosure', '外の宛先（storefront.csp）を書いた App は、外部送信に関する公表を書く');
        }

        foreach ($sections as $type => $section) {
            if ($section->embed !== null && $disclosures === []) {
                throw new InvalidAppManifestException('disclosure', "外から埋め込むセクション（sections.{$type}.embed）を書いた App は、外部送信に関する公表を書く");
            }
        }

        return new self($scripts, $csp, $consent, $settings, $disclosures, $sections, $category);
    }

    /**
     * App の目的。核が決めた言葉（AppCategory）の 1 つだけ。知らない言葉（打ち間違い・大文字・並び）は断る
     */
    private static function category(mixed $value, string $path): AppCategory
    {
        $case = is_string($value) ? AppCategory::tryFrom($value) : null;

        return $case ?? throw new InvalidAppManifestException($path, '目的は '.implode('・', array_column(AppCategory::cases(), 'value')).' のどれか 1 つ（書かなければ other）');
    }

    /**
     * ページに置ける区画（ADR 0040）。種類の名前は app_ ＋ App の名前（- は _）か、その後ろに _ と英小文字・数字。
     * 宣言はテーマの区画と同じ決まりで確かめ（欄は核の 7 種類だけ・知らない書き方は断る）、consent と embed は核の口の決まりで確かめる
     *
     * @return array<string, AppSection>
     */
    private static function sections(mixed $value, string $path, string $plugin): array
    {
        if (! is_array($value) || $value === [] || array_is_list($value) || count($value) > self::MAX_SECTIONS) {
            throw new InvalidAppManifestException($path, 'セクションの種類の名前 => 宣言 の形で 1〜'.self::MAX_SECTIONS.' 個');
        }

        $out = [];

        foreach ($value as $type => $declaration) {
            if (! is_string($type) || preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $type) !== 1 || ! AppSection::belongsTo($type, $plugin)) {
                throw new InvalidAppManifestException($path, '種類の名前「'.$type.'」は '.AppSection::prefixOf($plugin).' か '.AppSection::prefixOf($plugin).'_…（英小文字・数字・_ の 32 文字まで）');
            }

            if (! is_array($declaration) || ($declaration !== [] && array_is_list($declaration))) {
                throw new InvalidAppManifestException("{$path}.{$type}", '{} の形で書く');
            }

            // 同意の種類は任意（書かなければ誰にでも出す）。外から埋め込む区画も同意は要らない（2026-10-30。守りは CSP・sandbox・宛先、
            // 送る内容は公表。公表は下の fromArray で必ず求める）
            $consent = array_key_exists('consent', $declaration) ? self::consent($declaration['consent'], "{$path}.{$type}.consent") : [];
            $embed = array_key_exists('embed', $declaration) ? self::embed($declaration['embed'], "{$path}.{$type}.embed") : null;

            unset($declaration['consent'], $declaration['embed']);

            try {
                $spec = SectionSchema::fromArray([$type => $declaration], $path)->sections[$type];
            } catch (InvalidSectionSchemaException $e) {
                $prefix = $e->path.': ';
                $reason = str_starts_with($e->getMessage(), $prefix) ? substr($e->getMessage(), strlen($prefix)) : $e->getMessage();

                throw new InvalidAppManifestException($e->path, $reason);
            }

            // HTML の欄（html・richtext）は核の区画「自由な HTML」「見たまま書ける文」だけが使う（テーマと同じ。SectionSchema::fromManifest）
            foreach ([...array_values($spec->fields), ...array_values($spec->itemFields), ...array_merge(...array_map(static fn ($block): array => array_values($block->fields), array_values($spec->blocks)))] as $field) {
                if ($field->type->isHtml()) {
                    throw new InvalidAppManifestException("{$path}.{$type}.fields.{$field->key}.type", "{$field->type->value} の欄は本体のセクションだけが使える");
                }
            }

            $out[$type] = new AppSection($plugin, $spec, $consent, $embed);
        }

        return $out;
    }

    private static function embed(mixed $value, string $path): EmbedRules
    {
        $value = self::object($value, $path, ['frame-src', 'links', 'sandbox', 'allow']);
        $sources = [];

        foreach (['frame-src', 'links'] as $key) {
            $list = $value[$key] ?? null;

            if (! is_array($list) || ! array_is_list($list) || count($list) > CspSources::MAX_PER_DIRECTIVE || ($key === 'frame-src' && $list === [])) {
                throw new InvalidAppManifestException("{$path}.{$key}", $key === 'frame-src' ? '枠に読み込む宛先を 1〜'.CspSources::MAX_PER_DIRECTIVE.' 個の並びで書く' : '外で開くリンクの宛先を 0〜'.CspSources::MAX_PER_DIRECTIVE.' 個の並びで書く');
            }

            foreach ($list as $i => $source) {
                $problem = CspSources::problem($source);

                if ($problem !== null) {
                    throw new InvalidAppManifestException("{$path}.{$key}.{$i}", $problem);
                }
            }

            /** @var list<string> $list */
            $sources[$key] = array_values(array_unique($list));
        }

        return new EmbedRules(
            $sources['frame-src'],
            $sources['links'],
            self::tokens($value['sandbox'] ?? null, EmbedRules::SANDBOX, "{$path}.sandbox"),
            self::tokens($value['allow'] ?? null, EmbedRules::ALLOW, "{$path}.allow"),
        );
    }

    /**
     * 核が決めた言葉の中からの並び（sandbox・allow）
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private static function tokens(mixed $value, array $allowed, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidAppManifestException($path, implode('・', $allowed).' の中からの並び（要らなければ []）');
        }

        foreach ($value as $token) {
            if (! is_string($token) || ! in_array($token, $allowed, true)) {
                throw new InvalidAppManifestException($path, '書けるのは '.implode('・', $allowed).' だけ');
            }
        }

        /** @var list<string> $value */
        return array_values(array_unique($value));
    }

    /**
     * @return array{list<AppScript>, array<string, list<string>>, list<ConsentKind>}
     */
    private static function storefront(mixed $value, string $path, string $directory): array
    {
        $value = self::object($value, $path, ['scripts', 'csp', 'consent']);

        if (! array_key_exists('consent', $value)) {
            throw new InvalidAppManifestException("{$path}.consent", '同意の種類（'.implode('・', array_column(ConsentKind::cases(), 'value')).' の並び。要らなければ []）を必ず書く');
        }

        return [
            self::scripts($value['scripts'] ?? null, "{$path}.scripts", $directory),
            array_key_exists('csp', $value) ? self::csp($value['csp'], "{$path}.csp") : [],
            self::consent($value['consent'], "{$path}.consent"),
        ];
    }

    /**
     * @return list<AppScript>
     */
    private static function scripts(mixed $value, string $path, string $directory): array
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value) || count($value) > self::MAX_SCRIPTS) {
            throw new InvalidAppManifestException($path, 'スクリプトを 1〜'.self::MAX_SCRIPTS.' 個の並びで書く');
        }

        $root = realpath($directory.DIRECTORY_SEPARATOR.self::ASSETS);
        $scripts = [];

        foreach ($value as $i => $script) {
            $script = self::object($script, "{$path}.{$i}", ['file', 'pages']);
            $file = $script['file'] ?? null;

            if (! is_string($file) || preg_match(self::FILE, $file) !== 1) {
                throw new InvalidAppManifestException("{$path}.{$i}.file", 'assets/ の中の .js のファイルの名前（英小文字・数字・_・-、ディレクトリは書けない）');
            }

            if (isset($scripts[$file])) {
                throw new InvalidAppManifestException("{$path}.{$i}.file", "{$file} が二重に書かれている");
            }

            // 実体が assets/ の中にある、ふつうのファイル（リンクで外を指していない）だけ
            $real = $root === false ? false : realpath($root.DIRECTORY_SEPARATOR.$file);

            if ($real === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR) || ! is_file($real) || (int) filesize($real) > self::MAX_SCRIPT_BYTES) {
                throw new InvalidAppManifestException("{$path}.{$i}.file", "assets/{$file} が無いか、assets/ の外を指すか、大きすぎる（".(self::MAX_SCRIPT_BYTES / 1024).'KB まで）');
            }

            $pages = $script['pages'] ?? null;

            if (! is_array($pages) || $pages === [] || ! array_is_list($pages)) {
                throw new InvalidAppManifestException("{$path}.{$i}.pages", '画面の種類（'.implode('・', PageKind::declarable()).'）の並び');
            }

            $kinds = [];

            foreach ($pages as $page) {
                // 個人情報の画面（private）は選べない
                if (! is_string($page) || ! in_array($page, PageKind::declarable(), true)) {
                    throw new InvalidAppManifestException("{$path}.{$i}.pages", '画面の種類は '.implode('・', PageKind::declarable()).' のどれか（個人情報の画面には差し込めない）');
                }

                $kinds[$page] = PageKind::from($page);
            }

            $scripts[$file] = new AppScript($file, array_values($kinds), $real);
        }

        return array_values($scripts);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function csp(mixed $value, string $path): array
    {
        $value = self::object($value, $path, CspSources::DIRECTIVES);
        $csp = [];

        foreach ($value as $directive => $sources) {
            if (! is_array($sources) || ! array_is_list($sources) || count($sources) > CspSources::MAX_PER_DIRECTIVE) {
                throw new InvalidAppManifestException("{$path}.{$directive}", '宛先を 0〜'.CspSources::MAX_PER_DIRECTIVE.' 個の並びで書く');
            }

            foreach ($sources as $i => $source) {
                $problem = CspSources::problem($source);

                if ($problem !== null) {
                    throw new InvalidAppManifestException("{$path}.{$directive}.{$i}", $problem);
                }
            }

            /** @var list<string> $sources */
            if ($sources !== []) {
                $csp[$directive] = array_values(array_unique($sources));
            }
        }

        return $csp;
    }

    /**
     * @return list<ConsentKind>
     */
    private static function consent(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidAppManifestException($path, '同意の種類（'.implode('・', array_column(ConsentKind::cases(), 'value')).'）の並び');
        }

        $kinds = [];

        foreach ($value as $kind) {
            $case = is_string($kind) ? ConsentKind::tryFrom($kind) : null;

            if ($case === null) {
                throw new InvalidAppManifestException($path, '同意の種類は '.implode('・', array_column(ConsentKind::cases(), 'value')).' のどれか');
            }

            $kinds[$case->value] = $case;
        }

        return array_values($kinds);
    }

    /**
     * @return array<string, SettingSpec>
     */
    private static function settings(mixed $value, string $path): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value)) || count($value) > self::MAX_SETTINGS) {
            throw new InvalidAppManifestException($path, '項目の名前をキーにした形で、'.self::MAX_SETTINGS.' 個まで');
        }

        $specs = [];

        foreach ($value as $key => $spec) {
            if (! is_string($key) || preg_match(self::KEY, $key) !== 1) {
                throw new InvalidAppManifestException($path, "名前「{$key}」は英小文字で始まる英小文字・数字・_ の 32 文字まで");
            }

            $specs[$key] = self::parseSetting($key, $spec, "{$path}.{$key}");
        }

        return $specs;
    }

    private static function parseSetting(string $key, mixed $value, string $path): SettingSpec
    {
        $value = self::object($value, $path, ['type', 'label', 'help', 'required', 'secret', 'pattern', 'max', 'options', 'default']);
        $type = is_string($value['type'] ?? null) ? SettingType::tryFrom($value['type']) : null;

        if ($type === null) {
            throw new InvalidAppManifestException("{$path}.type", '項目の種類は '.implode('・', array_column(SettingType::cases(), 'value')).' のどれか');
        }

        $label = self::text($value['label'] ?? null, 30, "{$path}.label");
        $help = array_key_exists('help', $value) ? self::text($value['help'], 200, "{$path}.help") : null;
        $required = self::flag($value, 'required', "{$path}.required");
        $secret = self::flag($value, 'secret', "{$path}.secret");

        foreach (['pattern', 'max'] as $only) {
            if (array_key_exists($only, $value) !== ($type === SettingType::Text)) {
                throw new InvalidAppManifestException("{$path}.{$only}", "{$only} は text だけに、text には必ず書く");
            }
        }

        if ($secret && $type !== SettingType::Text) {
            throw new InvalidAppManifestException("{$path}.secret", '秘密にできるのは text だけ');
        }

        if (array_key_exists('options', $value) !== ($type === SettingType::Choice)) {
            throw new InvalidAppManifestException("{$path}.options", 'options は choice だけに、choice には必ず書く');
        }

        $pattern = null;
        $max = null;
        $options = [];

        if ($type === SettingType::Text) {
            $pattern = self::pattern($value['pattern'], "{$path}.pattern");

            if (! is_int($value['max']) || $value['max'] < 1 || $value['max'] > SettingType::TEXT_CAP) {
                throw new InvalidAppManifestException("{$path}.max", '1〜'.SettingType::TEXT_CAP.' の整数');
            }

            $max = $value['max'];
        }

        if ($type === SettingType::Choice) {
            $options = self::options($value['options'], "{$path}.options");
        }

        $default = null;

        if (array_key_exists('default', $value)) {
            $default = $value['default'];
            $ok = match ($type) {
                SettingType::Toggle => is_bool($default),
                SettingType::Choice => is_string($default) && isset($options[$default]),
                SettingType::Text, SettingType::Media => false, // 文と写真の既定は書かない（入れていない ID を勝手に使わない）
            };

            if (! $ok) {
                throw new InvalidAppManifestException("{$path}.default", 'toggle は true・false、choice は選択肢の値。text には書かない');
            }
        }

        /** @var bool|string|null $default */
        return new SettingSpec($key, $type, $label, $help, $required, $secret, $pattern, $max, $options, $default);
    }

    private static function pattern(mixed $pattern, string $path): string
    {
        // 区切りに使う ~ と、制御文字は断る。正規表現として読めることを確かめる（読めないものは値の確かめで全部を断ってしまう）
        if (! is_string($pattern) || $pattern === '' || strlen($pattern) > 200 || str_contains($pattern, '~') || preg_match('/\p{Cc}/u', $pattern) !== 0) {
            throw new InvalidAppManifestException($path, '1〜200 文字の正規表現（~ は使えない）');
        }

        if (@preg_match('~\A(?:'.$pattern.')\z~u', '') === false) {
            throw new InvalidAppManifestException($path, '正規表現として読めない');
        }

        return $pattern;
    }

    /**
     * @return array<string, string>
     */
    private static function options(mixed $options, string $path): array
    {
        if (! is_array($options) || $options === [] || array_is_list($options) || count($options) > self::MAX_OPTIONS) {
            throw new InvalidAppManifestException($path, '値 => 名前 の形で 1〜'.self::MAX_OPTIONS.' 個');
        }

        $out = [];

        foreach ($options as $value => $label) {
            if (! is_string($value) || preg_match(self::OPTION, $value) !== 1) {
                throw new InvalidAppManifestException($path, "値「{$value}」は英小文字・数字・_・- の 32 文字まで");
            }

            $out[$value] = self::text($label, 30, "{$path}.{$value}");
        }

        return $out;
    }

    /**
     * @return list<Disclosure>
     */
    private static function disclosures(mixed $value, string $path): array
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value) || count($value) > self::MAX_DISCLOSURES) {
            throw new InvalidAppManifestException($path, '公表の行を 1〜'.self::MAX_DISCLOSURES.' 個の並びで書く');
        }

        $out = [];

        foreach ($value as $i => $entry) {
            $entry = self::object($entry, "{$path}.{$i}", ['company', 'data', 'purpose', 'policy']);
            $policy = $entry['policy'] ?? null;

            // 事業者の案内のページは https の URL だけ（URL に使える文字だけ。引用符・山かっこ・空白は断る）
            if (! is_string($policy) || strlen($policy) > 500 || ! str_starts_with($policy, 'https://') || preg_match('#\Ahttps://[A-Za-z0-9.-]+(?::[0-9]{1,5})?(?:/[A-Za-z0-9._\~/?\#&=%+:@!$*,;-]*)?\z#', $policy) !== 1 || filter_var($policy, FILTER_VALIDATE_URL) === false) {
                throw new InvalidAppManifestException("{$path}.{$i}.policy", 'https:// で始まる 500 文字までの URL');
            }

            $out[] = new Disclosure(
                self::text($entry['company'] ?? null, 100, "{$path}.{$i}.company"),
                self::text($entry['data'] ?? null, 300, "{$path}.{$i}.data"),
                self::text($entry['purpose'] ?? null, 300, "{$path}.{$i}.purpose"),
                $policy,
            );
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function flag(array $value, string $key, string $path): bool
    {
        if (! array_key_exists($key, $value)) {
            return false;
        }

        return is_bool($value[$key]) ? $value[$key] : throw new InvalidAppManifestException($path, 'true か false');
    }

    /**
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private static function object(mixed $value, string $path, array $allowed): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidAppManifestException($path, '{} の形で書く');
        }

        $unknown = array_diff(array_map('strval', array_keys($value)), $allowed);

        if ($unknown !== []) {
            throw new InvalidAppManifestException($path, '知らない書き方: '.implode('・', $unknown));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    private static function text(mixed $text, int $max, string $path): string
    {
        if (! is_string($text) || trim($text) === '' || mb_strlen($text) > $max || preg_match('/\p{Cc}/u', $text) !== 0) {
            throw new InvalidAppManifestException($path, "1〜{$max} 文字の 1 行の文");
        }

        return trim($text);
    }
}
