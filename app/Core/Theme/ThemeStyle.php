<?php

declare(strict_types=1);

namespace App\Core\Theme;

use App\Core\Section\InvalidSectionSchemaException;
use Illuminate\Validation\ValidationException;

/**
 * テーマが店の人に選ばせる見た目の宣言（theme.json の style。ADR 0097）と、店の値（選んだ名前と主な色）の確かめ。
 *
 * - 宣言: 色の組（palettes。2〜6 つ。名前 => { label, colors: { 色の名前: "#rrggbb" } }）・主な色を自由にするか（accent）・
 *   字の組（fonts。1〜4 つ）・角の丸み（radius。1〜3 つ）・テーマが足した色の名前（tokens。8 つまで）。最初の組・字・角が既定
 * - 色の名前は核が知っている名前（CORE_COLORS）か、tokens で足した名前だけ。値は #rrggbb だけ（小文字にそろえる）。
 *   組は canvas・ink・accent を必ず持つ（主な色の読みやすさを地と比べるため）。ほかの書き方は登録で止める（ADR 0028 の宣言と同じ扱い）
 * - 字の組と角の丸みの中身（書体の並び・rem の値）はテーマの CSS が [data-sugar-font]・[data-sugar-radius] で持つ。ここは名前と表示の名前だけ
 * - 店の値は { palette, accent, font, radius }（名前と #rrggbb の 1 色だけ。CSS の値そのものは持たない）
 */
final readonly class ThemeStyle
{
    /** 核が知っている色の名前（テーマの @theme の --color-<名前>） */
    public const array CORE_COLORS = ['canvas', 'band', 'line', 'ink', 'ink-muted', 'ink-soft', 'accent'];

    /** 組に必ず要る色（主な色の読みやすさの計算に使う） */
    public const array REQUIRED_COLORS = ['canvas', 'ink', 'accent'];

    /** 核が主な色から計算して書く色の名前（組にもテーマの tokens にも書けない） */
    public const array COMPUTED_COLORS = ['on-accent', 'accent-hover'];

    /** 組・字・角・足した色の名前の形（CSS の変数の名前と data- の値に出すので、ここで絞る） */
    public const string NAME = '/\A[a-z][a-z0-9-]{0,31}\z/';

    /** 色の値の形（宣言は大文字も受けて小文字にそろえる。店の値も同じ） */
    public const string HEX = '/\A#[0-9a-fA-F]{6}\z/';

    public const int MIN_PALETTES = 2;

    public const int MAX_PALETTES = 6;

    public const int MAX_FONTS = 4;

    public const int MAX_RADIUS = 3;

    public const int MAX_TOKENS = 8;

    /**
     * @param  array<string, array{label: string, colors: array<string, string>}>  $palettes  名前 => 表示の名前と色（最初が既定）
     * @param  bool  $accent  主な色を店の人が自由に決めてよいか
     * @param  array<string, string>  $fonts  名前 => 表示の名前（最初が既定。無ければ選べない）
     * @param  array<string, string>  $radius  名前 => 表示の名前（最初が既定。無ければ選べない）
     * @param  list<string>  $tokens  テーマが組で変えてよいとした色の名前（核の名前のほか）
     */
    public function __construct(
        public array $palettes,
        public bool $accent,
        public array $fonts,
        public array $radius,
        public array $tokens,
    ) {}

    /**
     * theme.json の style を読む。形が違えば InvalidSectionSchemaException（道筋は style.palettes.mono.colors.ink など）
     */
    public static function fromManifest(mixed $style): self
    {
        $style = self::object($style, 'style', ['palettes', 'accent', 'fonts', 'radius', 'tokens']);

        $tokens = $style['tokens'] ?? [];
        if (! is_array($tokens) || ! array_is_list($tokens) || count($tokens) > self::MAX_TOKENS) {
            throw new InvalidSectionSchemaException('style.tokens', '色の名前の並び（'.self::MAX_TOKENS.' つまで）');
        }
        foreach ($tokens as $i => $token) {
            if (! is_string($token) || preg_match(self::NAME, $token) !== 1 || in_array($token, [...self::CORE_COLORS, ...self::COMPUTED_COLORS], true)) {
                throw new InvalidSectionSchemaException("style.tokens.{$i}", '英小文字で始まる英小文字・数字・- の 32 文字まで（本体の色の名前とは別の名前）');
            }
        }
        $tokens = array_values(array_unique($tokens));
        $colorNames = [...self::CORE_COLORS, ...$tokens];

        $palettes = $style['palettes'] ?? null;
        if (! is_array($palettes) || array_is_list($palettes) || count($palettes) < self::MIN_PALETTES || count($palettes) > self::MAX_PALETTES) {
            throw new InvalidSectionSchemaException('style.palettes', '組の名前 => { label, colors } の形で '.self::MIN_PALETTES.'〜'.self::MAX_PALETTES.' つ');
        }

        $out = [];
        foreach ($palettes as $name => $palette) {
            $path = "style.palettes.{$name}";
            $name = self::name($name, $path);
            $palette = self::object($palette, $path, ['label', 'colors']);
            $colors = $palette['colors'] ?? null;

            if (! is_array($colors) || array_is_list($colors)) {
                throw new InvalidSectionSchemaException("{$path}.colors", '色の名前 => "#rrggbb" の形で書く');
            }

            $checked = [];
            foreach ($colors as $color => $value) {
                if (! is_string($color) || ! in_array($color, $colorNames, true)) {
                    throw new InvalidSectionSchemaException("{$path}.colors.{$color}", '色の名前は '.implode('・', self::CORE_COLORS).' か、style.tokens で足した名前だけ');
                }
                if (! is_string($value) || preg_match(self::HEX, $value) !== 1) {
                    throw new InvalidSectionSchemaException("{$path}.colors.{$color}", '色は #rrggbb の形で書く');
                }
                $checked[$color] = strtolower($value);
            }

            $missing = array_diff(self::REQUIRED_COLORS, array_keys($checked));
            if ($missing !== []) {
                throw new InvalidSectionSchemaException("{$path}.colors", '組には '.implode('・', self::REQUIRED_COLORS).' を必ず書く（足りない: '.implode('・', $missing).'）');
            }

            $out[$name] = ['label' => self::label($palette['label'] ?? null, "{$path}.label"), 'colors' => $checked];
        }

        $accent = $style['accent'] ?? false;
        if (! is_bool($accent)) {
            throw new InvalidSectionSchemaException('style.accent', 'true か false');
        }

        return new self(
            $out,
            $accent,
            self::choices($style['fonts'] ?? null, 'style.fonts', self::MAX_FONTS),
            self::choices($style['radius'] ?? null, 'style.radius', self::MAX_RADIUS),
            $tokens,
        );
    }

    /**
     * 何も選んでいないときの値（最初の組・字・角、主な色は組のまま）
     *
     * @return array{palette: string, accent: null, font: string|null, radius: string|null}
     */
    public function defaults(): array
    {
        return [
            'palette' => (string) array_key_first($this->palettes),
            'accent' => null,
            'font' => array_key_first($this->fonts),
            'radius' => array_key_first($this->radius),
        ];
    }

    /**
     * しまってある店の値を、今の宣言で読み直す（描くとき）。宣言から消えた名前・形の崩れた値は既定へ戻す（テーマの更新で組が減っても壊れない）。
     * 主な色を許さなくなったテーマでは、店の色を使わない
     *
     * @return array{palette: string, accent: string|null, font: string|null, radius: string|null}
     */
    public function resolve(mixed $stored): array
    {
        $values = $this->defaults();
        $stored = is_array($stored) ? $stored : [];
        $pick = static fn (mixed $name, array $declared, ?string $default): ?string => is_string($name) && isset($declared[$name]) ? $name : $default;
        $accent = $stored['accent'] ?? null;

        return [
            'palette' => (string) $pick($stored['palette'] ?? null, $this->palettes, $values['palette']),
            'accent' => $this->accent && is_string($accent) && preg_match(self::HEX, $accent) === 1 ? strtolower($accent) : null,
            'font' => $pick($stored['font'] ?? null, $this->fonts, $values['font']),
            'radius' => $pick($stored['radius'] ?? null, $this->radius, $values['radius']),
        ];
    }

    /**
     * 画面から受け取った店の値を確かめる（保存のとき）。名前はこのテーマの宣言にあるものだけ、主な色は #rrggbb だけ（小文字にそろえる）、
     * 主な色を許さないテーマでは null だけ。字・角を宣言しないテーマでは null だけ。違えば道筋つきの ValidationException（style.palette など）
     *
     * @return array{palette: string, accent: string|null, font: string|null, radius: string|null}
     *
     * @throws ValidationException
     */
    public function validate(mixed $input): array
    {
        if (! is_array($input) || array_is_list($input) || array_diff(array_map('strval', array_keys($input)), ['palette', 'accent', 'font', 'radius']) !== []) {
            throw ValidationException::withMessages(['style' => 'デザインの値の形式が正しくありません。']);
        }

        $errors = [];
        $palette = $input['palette'] ?? null;
        if (! is_string($palette) || ! isset($this->palettes[$palette])) {
            $errors['style.palette'] = 'カラーパレットは、このテーマが用意したものから選んでください。';
        }

        $accent = $input['accent'] ?? null;
        if ($accent !== null && ! $this->accent) {
            $errors['style.accent'] = 'このテーマでは、主な色を自由に決められません。カラーパレットから選んでください。';
        } elseif ($accent !== null && (! is_string($accent) || preg_match(self::HEX, $accent) !== 1)) {
            $errors['style.accent'] = '主な色は #1e3a8a のように、# と 6 桁の 0〜9・a〜f で入力してください。';
        }

        foreach (['font' => [$this->fonts, 'フォント'], 'radius' => [$this->radius, '角の丸み']] as $key => [$declared, $label]) {
            $value = $input[$key] ?? null;
            if ($declared === [] ? $value !== null : (! is_string($value) || ! isset($declared[$value]))) {
                $errors["style.{$key}"] = "{$label}は、このテーマが用意したものから選んでください。";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $font = $input['font'] ?? null;
        $radius = $input['radius'] ?? null;

        return [
            'palette' => (string) $palette,
            'accent' => is_string($accent) ? strtolower($accent) : null,
            'font' => is_string($font) ? $font : null,
            'radius' => is_string($radius) ? $radius : null,
        ];
    }

    /**
     * 管理画面に渡す宣言（並びの順を保つため、名前を持った並びで）
     *
     * @return array{palettes: list<array{name: string, label: string, colors: array<string, string>}>, accent: bool, fonts: list<array{name: string, label: string}>, radius: list<array{name: string, label: string}>}
     */
    public function toArray(): array
    {
        $list = static fn (array $choices): array => array_map(static fn (string $name, string $label): array => ['name' => $name, 'label' => $label], array_keys($choices), array_values($choices));

        return [
            'palettes' => array_map(static fn (string $name, array $p): array => ['name' => $name, 'label' => $p['label'], 'colors' => $p['colors']], array_keys($this->palettes), array_values($this->palettes)),
            'accent' => $this->accent,
            'fonts' => $list($this->fonts),
            'radius' => $list($this->radius),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function choices(mixed $choices, string $path, int $max): array
    {
        if ($choices === null) {
            return [];
        }

        if (! is_array($choices) || array_is_list($choices) || count($choices) > $max) {
            throw new InvalidSectionSchemaException($path, "名前 => { label } の形で 1〜{$max} つ");
        }

        $out = [];
        foreach ($choices as $name => $choice) {
            $name = self::name($name, "{$path}.{$name}");
            $choice = self::object($choice, "{$path}.{$name}", ['label']);
            $out[$name] = self::label($choice['label'] ?? null, "{$path}.{$name}.label");
        }

        return $out;
    }

    private static function name(int|string $name, string $path): string
    {
        if (! is_string($name) || preg_match(self::NAME, $name) !== 1) {
            throw new InvalidSectionSchemaException($path, "名前「{$name}」は英小文字で始まる英小文字・数字・- の 32 文字まで");
        }

        return $name;
    }

    private static function label(mixed $label, string $path): string
    {
        if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 20 || preg_match('/\p{Cc}/u', $label) !== 0) {
            throw new InvalidSectionSchemaException($path, '1〜20 文字の 1 行の文');
        }

        return trim($label);
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
}
