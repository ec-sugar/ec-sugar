<?php

declare(strict_types=1);

namespace App\Core\Listing\Description;

use Illuminate\Validation\ValidationException;

/**
 * 商品の説明（ADR 0032）: 説明のブロックの並び・仕様の表・短い説明の形を確かめる。中身は HTML でなく決まった種類のブロック（JSON）で持ち、表示はテーマが描く。
 *
 * - ブロックの種類は 6 つだけ: 段落・見出し（2 段）・箇条書き・写真・表・注意書き。知らない種類・知らない項目は断る
 * - 文は素の文字だけ（HTML は文字のまま。描くときに必ずエスケープする）。壊れた UTF-8・制御文字（C0・DEL・C1）・文字の向きを変える印は断る。複数行の文だけ改行とタブを通す
 * - 長さ・個数に上限（下の定数。DB の検査も個数を守る）。写真は ID の形だけをここで見て、同じ店のショップの写真にあるかは ListingService がロックの中で確かめる
 * - 保存のとき（validate*）は違うところを道筋ごとに断り、読むとき（read*）は崩れた値を捨てる（DB の値を誰かが直に書いても、ショップを壊さない）。
 *   ただし文の中の断る文字（壊れた UTF-8・制御文字・向きを変える印）と長すぎる文は、読むときは「その文字だけ外す・切り詰める」でブロックを残す
 *   （Windows や Office から貼った前の説明が、移行の後にショップや管理画面から消えないように）
 */
final class ProductDescription
{
    public const int MAX_BLOCKS = 100;

    /** 段落・注意書きの文 */
    public const int MAX_TEXT = 5_000;

    /** ブロックの文の合計（1 つの商品の説明の重さを抑える） */
    public const int MAX_TOTAL_TEXT = 50_000;

    public const int MAX_HEADING = 200;

    public const int MAX_LIST_ITEMS = 50;

    public const int MAX_LIST_ITEM = 500;

    public const int MAX_TABLE_ROWS = 20;

    public const int MAX_TABLE_COLUMNS = 6;

    public const int MAX_CELL = 200;

    public const int MAX_CAPTION = 200;

    /** 写真のブロックの数（ページの重さを抑える） */
    public const int MAX_IMAGES = 30;

    public const int MAX_SPECS = 30;

    public const int MAX_SPEC_LABEL = 50;

    public const int MAX_SPEC_VALUE = 500;

    public const int MAX_SUMMARY = 200;

    /** 前の形の説明（listings.description）に写す文の長さ。前のバージョンの管理画面の上限と同じ */
    public const int MAX_PLAIN_TEXT = 5_000;

    public const array TYPES = ['paragraph', 'heading', 'list', 'image', 'table', 'note'];

    public const array HEADING_LEVELS = [2, 3];

    public const array LIST_STYLES = ['bullet', 'number'];

    public const array NOTE_TONES = ['info', 'warning'];

    private const int MAX_ERRORS = 50;

    private const string ULID = '/\A[0-9a-hjkmnp-tv-z]{26}\z/';

    /** 文字の向きを変える印（U+202A〜202E・U+2066〜2069）。見た目と中身を食い違わせられるので断る */
    private const string BIDI = '/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /** @var array<string, string> */
    private array $errors = [];

    private int $total = 0;

    private function __construct(private readonly bool $strict) {}

    /**
     * ブロックのエディタに渡す上限（鍵の名前は画面が読む形。上限を足すときはここだけ直す）。
     * ブロックの欄（App の欄・DescriptionEditor）はこれだけ、商品の画面は下の listingLimits()
     *
     * @return array<string, int>
     */
    public static function blockLimits(): array
    {
        return [
            'blocks' => self::MAX_BLOCKS,
            'text' => self::MAX_TEXT,
            'total_text' => self::MAX_TOTAL_TEXT,
            'heading' => self::MAX_HEADING,
            'list_items' => self::MAX_LIST_ITEMS,
            'list_item' => self::MAX_LIST_ITEM,
            'table_rows' => self::MAX_TABLE_ROWS,
            'table_columns' => self::MAX_TABLE_COLUMNS,
            'cell' => self::MAX_CELL,
            'caption' => self::MAX_CAPTION,
            'images' => self::MAX_IMAGES,
        ];
    }

    /**
     * 商品の画面に渡す上限（管理画面の API の description_limits）。ブロックの上限に、商品だけの欄（仕様の表 SpecEditor・短い説明）を足す
     *
     * @return array<string, int>
     */
    public static function listingLimits(): array
    {
        return self::blockLimits() + [
            'specs' => self::MAX_SPECS,
            'spec_label' => self::MAX_SPEC_LABEL,
            'spec_value' => self::MAX_SPEC_VALUE,
            'summary' => self::MAX_SUMMARY,
        ];
    }

    /**
     * 説明のブロックを確かめ、決まった形にそろえる（項目の順・前後の空白・改行）
     *
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException 道筋（description_blocks.3.text など）ごとの誤り
     */
    public static function validateBlocks(mixed $input): array
    {
        $walker = new self(strict: true);
        $blocks = $walker->blocks($input);
        $walker->throwIfFailed();

        return $blocks;
    }

    /**
     * 保存してある説明のブロックを、描ける形のものだけにする（崩れたブロックは捨てる）
     *
     * @return list<array<string, mixed>>
     */
    public static function readBlocks(mixed $stored): array
    {
        return (new self(strict: false))->blocks($stored);
    }

    /**
     * 仕様の表（項目と値の組の並び）
     *
     * @return list<array{label: string, value: string}>
     *
     * @throws ValidationException
     */
    public static function validateSpecs(mixed $input): array
    {
        $walker = new self(strict: true);
        $specs = $walker->specs($input);
        $walker->throwIfFailed();

        return $specs;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public static function readSpecs(mixed $stored): array
    {
        return (new self(strict: false))->specs($stored);
    }

    /**
     * 一覧に出す短い説明（1 行。空は null）
     *
     * @throws ValidationException
     */
    public static function validateSummary(mixed $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $walker = new self(strict: true);
        $summary = $walker->text($input, 'summary', '短い説明', self::MAX_SUMMARY, multiline: false);
        $walker->throwIfFailed();

        return $summary === '' ? null : $summary;
    }

    /**
     * 保存してある短い説明（形が違えば null）
     */
    public static function readSummary(mixed $stored): ?string
    {
        $summary = (new self(strict: false))->text($stored, 'summary', '短い説明', self::MAX_SUMMARY, multiline: false);

        return $summary === null || $summary === '' ? null : $summary;
    }

    /**
     * 前の形の説明の文（1 つの段落）をブロックにする。空なら空の並び
     *
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public static function fromText(?string $text): array
    {
        if ($text === null) {
            return [];
        }

        $walker = new self(strict: true);
        $text = $walker->text($text, 'description', '説明', self::MAX_TEXT, multiline: true);
        $walker->throwIfFailed();

        return $text === null || $text === '' ? [] : [['type' => 'paragraph', 'text' => $text]];
    }

    /**
     * ブロックの文をつないだ素の文（listings.description に写す。前のバージョンへ戻したときの説明と、検索エンジン向けの説明の元）
     *
     * @param  list<array<string, mixed>>  $blocks  確かめた形
     */
    public static function plainText(array $blocks): ?string
    {
        $parts = [];

        foreach ($blocks as $block) {
            $parts[] = match ($block['type']) {
                'paragraph', 'heading', 'note' => $block['text'],
                'list' => implode("\n", array_map(static fn (string $item): string => '・'.$item, $block['items'])),
                'table' => implode("\n", array_map(static fn (array $row): string => implode(' / ', array_filter($row, static fn (string $c): bool => $c !== '')), $block['rows'])),
                'image' => $block['caption'] ?? '',
                default => '',
            };
        }

        $text = trim(implode("\n\n", array_filter($parts, static fn (string $part): bool => trim($part) !== '')));

        return $text === '' ? null : mb_substr($text, 0, self::MAX_PLAIN_TEXT);
    }

    /**
     * 写真のブロックが指す写真の ID（重なりなし）
     *
     * @param  list<array<string, mixed>>  $blocks  確かめた形
     * @return list<string>
     */
    public static function mediaIds(array $blocks): array
    {
        $ids = [];

        foreach ($blocks as $block) {
            if ($block['type'] === 'image' && is_string($block['media_id'] ?? null)) {
                $ids[$block['media_id']] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * 比べるための形（JSON の項目の順に頼らない。jsonb は項目の順を変えて返す）
     */
    public static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function blocks(mixed $input): array
    {
        if (! is_array($input) || ! array_is_list($input)) {
            $this->fail('description_blocks', '説明のブロックの並びの形式が正しくありません。');

            return [];
        }

        if (count($input) > self::MAX_BLOCKS) {
            $this->fail('description_blocks', '説明のブロックは '.self::MAX_BLOCKS.' 個までです。');

            return [];
        }

        $blocks = [];
        $images = 0;

        foreach ($input as $index => $block) {
            $path = "description_blocks.{$index}";
            $normalized = $this->block($block, $path);

            if ($normalized === null) {
                continue;
            }

            if ($normalized['type'] === 'image' && ++$images > self::MAX_IMAGES) {
                $this->fail($path, '写真のブロックは '.self::MAX_IMAGES.' 個までです。');

                continue;
            }

            $blocks[] = $normalized;
        }

        if ($this->total > self::MAX_TOTAL_TEXT) {
            $this->fail('description_blocks', '説明の文が長すぎます（合わせて '.number_format(self::MAX_TOTAL_TEXT).' 文字まで）。');

            return $this->strict ? [] : $blocks;
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function block(mixed $block, string $path): ?array
    {
        if (! is_array($block) || array_is_list($block) || ! is_string($block['type'] ?? null) || ! in_array($block['type'], self::TYPES, true)) {
            return $this->fail($path, 'ブロックの種類が正しくありません（段落・見出し・箇条書き・写真・表・注意書きのどれか）。');
        }

        $type = $block['type'];
        $allowed = match ($type) {
            'paragraph' => ['type', 'text'],
            'heading' => ['type', 'level', 'text'],
            'list' => ['type', 'style', 'items'],
            'image' => ['type', 'media_id', 'caption'],
            'table' => ['type', 'header', 'caption', 'rows'],
            'note' => ['type', 'tone', 'text'],
        };

        // 知らない項目は断る（決めた形の外のものを保存しない）
        foreach (array_keys($block) as $key) {
            if (! in_array($key, $allowed, true)) {
                return $this->fail($path, 'ブロックに使えない項目があります。');
            }
        }

        return match ($type) {
            'paragraph' => $this->required(['type' => 'paragraph', 'text' => $this->text($block['text'] ?? null, "{$path}.text", '段落の文', self::MAX_TEXT, multiline: true)], "{$path}.text", '段落の文'),
            'heading' => $this->heading($block, $path),
            'list' => $this->listBlock($block, $path),
            'image' => $this->image($block, $path),
            'table' => $this->table($block, $path),
            'note' => $this->note($block, $path),
        };
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function heading(array $block, string $path): ?array
    {
        $level = $block['level'] ?? 2;

        if (! is_int($level) || ! in_array($level, self::HEADING_LEVELS, true)) {
            return $this->fail("{$path}.level", '見出しの大きさは「大」か「小」から選んでください。');
        }

        return $this->required(['type' => 'heading', 'level' => $level, 'text' => $this->text($block['text'] ?? null, "{$path}.text", '見出し', self::MAX_HEADING, multiline: false)], "{$path}.text", '見出し');
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function listBlock(array $block, string $path): ?array
    {
        $style = $block['style'] ?? 'bullet';

        if (! is_string($style) || ! in_array($style, self::LIST_STYLES, true)) {
            return $this->fail("{$path}.style", '箇条書きの種類は「記号」か「番号」から選んでください。');
        }

        $items = $block['items'] ?? null;

        if (! is_array($items) || ! array_is_list($items) || $items === []) {
            return $this->fail("{$path}.items", '箇条書きには 1 行以上入力してください。');
        }

        if (count($items) > self::MAX_LIST_ITEMS) {
            return $this->fail("{$path}.items", '箇条書きは '.self::MAX_LIST_ITEMS.' 行までです。');
        }

        $clean = [];

        foreach ($items as $i => $item) {
            $text = $this->text($item, "{$path}.items.{$i}", '箇条書きの行', self::MAX_LIST_ITEM, multiline: false);

            if ($text === '') {
                $this->fail("{$path}.items.{$i}", '箇条書きの空の行を削除するか、文を入力してください。');
            } elseif ($text !== null) {
                $clean[] = $text;
            }
        }

        if ($clean === []) {
            return null;
        }

        return ['type' => 'list', 'style' => $style, 'items' => $clean];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function image(array $block, string $path): ?array
    {
        $id = $block['media_id'] ?? null;
        $id = is_string($id) ? strtolower($id) : null;

        if ($id === null || preg_match(self::ULID, $id) !== 1) {
            return $this->fail("{$path}.media_id", '写真を「写真」の一覧から選んでください。');
        }

        $caption = $this->optional($block['caption'] ?? null, "{$path}.caption", '写真の説明', self::MAX_CAPTION);

        return ['type' => 'image', 'media_id' => $id, 'caption' => $caption];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function table(array $block, string $path): ?array
    {
        $header = $block['header'] ?? false;

        if (! is_bool($header)) {
            return $this->fail("{$path}.header", '表の見出しの行は「使う」か「使わない」で選んでください。');
        }

        $rows = $block['rows'] ?? null;

        if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
            return $this->fail("{$path}.rows", '表には 1 行以上入力してください。');
        }

        if (count($rows) > self::MAX_TABLE_ROWS) {
            return $this->fail("{$path}.rows", '表は '.self::MAX_TABLE_ROWS.' 行までです。');
        }

        $width = null;
        $clean = [];
        $filled = false;

        foreach ($rows as $r => $row) {
            if (! is_array($row) || ! array_is_list($row) || $row === [] || count($row) > self::MAX_TABLE_COLUMNS) {
                return $this->fail("{$path}.rows.{$r}", '表の列は 1〜'.self::MAX_TABLE_COLUMNS.' 列です。');
            }

            $width ??= count($row);

            if (count($row) !== $width) {
                return $this->fail("{$path}.rows.{$r}", '表の行ごとの列の数をそろえてください。');
            }

            $cells = [];

            foreach ($row as $c => $cell) {
                $text = $this->text($cell, "{$path}.rows.{$r}.{$c}", '表のセル', self::MAX_CELL, multiline: false);
                $cells[] = $text ?? '';
                $filled = $filled || ($text !== null && $text !== '');
            }

            $clean[] = $cells;
        }

        if (! $filled) {
            return $this->fail("{$path}.rows", '表が空です。文を入力するか、ブロックを削除してください。');
        }

        $caption = $this->optional($block['caption'] ?? null, "{$path}.caption", '表のタイトル', self::MAX_CAPTION);

        return ['type' => 'table', 'header' => $header, 'caption' => $caption, 'rows' => $clean];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function note(array $block, string $path): ?array
    {
        $tone = $block['tone'] ?? 'info';

        if (! is_string($tone) || ! in_array($tone, self::NOTE_TONES, true)) {
            return $this->fail("{$path}.tone", '注意書きの種類は「お知らせ」か「ご注意」から選んでください。');
        }

        return $this->required(['type' => 'note', 'tone' => $tone, 'text' => $this->text($block['text'] ?? null, "{$path}.text", '注意書きの文', self::MAX_TEXT, multiline: true)], "{$path}.text", '注意書きの文');
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function specs(mixed $input): array
    {
        if (! is_array($input) || ! array_is_list($input)) {
            $this->fail('specs', '仕様の表の形式が正しくありません。');

            return [];
        }

        if (count($input) > self::MAX_SPECS) {
            $this->fail('specs', '仕様の表は '.self::MAX_SPECS.' 行までです。');

            return [];
        }

        $specs = [];

        foreach ($input as $i => $row) {
            if (! is_array($row) || array_diff(array_keys($row), ['label', 'value']) !== []) {
                $this->fail("specs.{$i}", '仕様の表の行の形式が正しくありません。');

                continue;
            }

            $label = $this->text($row['label'] ?? null, "specs.{$i}.label", '仕様の項目', self::MAX_SPEC_LABEL, multiline: false);
            $value = $this->text($row['value'] ?? null, "specs.{$i}.value", '仕様の値', self::MAX_SPEC_VALUE, multiline: true);

            if ($label === '' || $value === '') {
                $this->fail("specs.{$i}", '仕様の表は項目と値の両方を入力してください（使わない行は削除してください）。');

                continue;
            }

            if ($label !== null && $value !== null) {
                $specs[] = ['label' => $label, 'value' => $value];
            }
        }

        return $specs;
    }

    /**
     * 文を確かめてそろえる（前後の空白を除き、改行は \n に）。形が違えば null、空（null も）なら ''
     */
    private function text(mixed $value, string $path, string $label, int $max, bool $multiline): ?string
    {
        // 空の文字は管理画面の入口（ConvertEmptyStringsToNull）で null になって届くので、空として扱う（表の空のますなど）
        if ($value === null) {
            return '';
        }

        if (! is_string($value)) {
            return $this->fail($path, "{$label}は文字で入力してください。");
        }

        // 読むときは、断る文字だけを外して残す（ブロックごと捨てない）
        if (! $this->strict) {
            return $this->salvage($value, $max, $multiline);
        }

        // 壊れた UTF-8 は、ほかの確かめより先に断る（preg の /u も断るが、意味をはっきりさせる）
        if (! mb_check_encoding($value, 'UTF-8')) {
            return $this->fail($path, "{$label}に読めない文字が入っています。");
        }

        $text = trim($multiline ? str_replace(["\r\n", "\r"], "\n", $value) : $value);

        // 制御文字（C0・DEL・C1）は断る。複数行の文だけ改行とタブを通す
        if (preg_match($multiline ? '/[^\P{Cc}\n\t]/u' : '/\p{Cc}/u', $text) !== 0) {
            return $this->fail($path, $multiline ? "{$label}に使えない文字が入っています。" : "{$label}は改行の無い 1 行にしてください。");
        }

        if (preg_match(self::BIDI, $text) !== 0) {
            return $this->fail($path, "{$label}に、文字の向きを変える制御文字が入っています。削除してから保存してください。");
        }

        $length = mb_strlen($text);

        if ($length > $max) {
            return $this->fail($path, "{$label}は ".number_format($max).' 文字までです。');
        }

        $this->total += $length;

        return $text;
    }

    /**
     * 読むときの文: 壊れた UTF-8 は置き換え、制御文字（複数行なら改行とタブ以外。1 行なら改行も）と向きを変える印は外し、長すぎれば切り詰める
     */
    private function salvage(string $value, int $max, bool $multiline): string
    {
        $text = mb_scrub($value, 'UTF-8');
        $text = $multiline ? str_replace(["\r\n", "\r"], "\n", $text) : $text;
        $text = (string) preg_replace($multiline ? '/[^\P{Cc}\n\t]/u' : '/\p{Cc}/u', '', $text);
        $text = trim((string) preg_replace(self::BIDI, '', $text));
        $text = mb_substr($text, 0, $max);
        $this->total += mb_strlen($text);

        return $text;
    }

    private function optional(mixed $value, string $path, string $label, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = $this->text($value, $path, $label, $max, multiline: false);

        return $text === '' ? null : $text;
    }

    /**
     * 文の要るブロック（段落・見出し・注意書き）で、文が空・形違いなら断る
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function required(array $block, string $path, string $label): ?array
    {
        if ($block['text'] === null) {
            return null; // 誤りは text() が残した
        }

        if ($block['text'] === '') {
            return $this->fail($path, "{$label}が空です。文を入力するか、ブロックを削除してください。");
        }

        return $block;
    }

    private function throwIfFailed(): void
    {
        if ($this->errors !== []) {
            throw ValidationException::withMessages($this->errors);
        }
    }

    /**
     * 厳しいときは誤りを残す。どちらでも値は空（null）にする
     */
    private function fail(string $path, string $message): null
    {
        if ($this->strict) {
            $this->errors[$path] ??= $message;

            // 誤りの多い入力を送られても、誤りの一覧を作り続けない
            if (count($this->errors) >= self::MAX_ERRORS) {
                throw ValidationException::withMessages($this->errors);
            }
        }

        return null;
    }
}
