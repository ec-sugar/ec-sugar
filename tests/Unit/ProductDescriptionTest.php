<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Listing\Description\ProductDescription;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 商品の説明のブロック・仕様の表・短い説明の形（ADR 0032）。決めた種類と項目だけ、素の文字だけ、上限まで
 */
final class ProductDescriptionTest extends TestCase
{
    private const string ULID_A = '01jbx0000000000000000000aa';

    public function test_every_block_type_is_normalised_into_its_fixed_shape(): void
    {
        $blocks = ProductDescription::validateBlocks([
            ['text' => "  一行目\r\n二行目\t（タブ）\r三行目  ", 'type' => 'paragraph'],
            ['type' => 'heading', 'level' => 3, 'text' => ' 素材について '],
            ['type' => 'heading', 'text' => '大きさ'],
            ['type' => 'list', 'items' => [' 綿 100% ', '日本製'], 'style' => 'number'],
            ['type' => 'list', 'items' => ['点の箇条書き']],
            ['type' => 'image', 'media_id' => strtoupper(self::ULID_A), 'caption' => ' 着た様子 '],
            ['type' => 'image', 'media_id' => self::ULID_A, 'caption' => ''],
            ['type' => 'table', 'header' => true, 'rows' => [['サイズ', '着丈'], ['M', null], ['L', '72']], 'caption' => '寸法（cm）'],
            ['type' => 'note', 'tone' => 'warning', 'text' => '洗濯機は使えません'],
            ['type' => 'note', 'text' => 'お知らせ'],
        ]);

        $this->assertSame([
            ['type' => 'paragraph', 'text' => "一行目\n二行目\t（タブ）\n三行目"],
            ['type' => 'heading', 'level' => 3, 'text' => '素材について'],
            ['type' => 'heading', 'level' => 2, 'text' => '大きさ'],
            ['type' => 'list', 'style' => 'number', 'items' => ['綿 100%', '日本製']],
            ['type' => 'list', 'style' => 'bullet', 'items' => ['点の箇条書き']],
            ['type' => 'image', 'media_id' => self::ULID_A, 'caption' => '着た様子'],
            ['type' => 'image', 'media_id' => self::ULID_A, 'caption' => null],
            ['type' => 'table', 'header' => true, 'caption' => '寸法（cm）', 'rows' => [['サイズ', '着丈'], ['M', ''], ['L', '72']]],
            ['type' => 'note', 'tone' => 'warning', 'text' => '洗濯機は使えません'],
            ['type' => 'note', 'tone' => 'info', 'text' => 'お知らせ'],
        ], $blocks);

        // 同じ写真は 1 つに
        $this->assertSame([self::ULID_A], ProductDescription::mediaIds($blocks));
        $this->assertSame([], ProductDescription::validateBlocks([]));
    }

    public function test_html_is_kept_as_plain_text(): void
    {
        $html = '<script>alert(1)</script><b onclick="x()">太字</b> &amp; <img src=x onerror=alert(1)>';
        $blocks = ProductDescription::validateBlocks([['type' => 'paragraph', 'text' => $html], ['type' => 'heading', 'text' => '<h1>見出し</h1>']]);

        // 取り除きも書き換えもしない（描くときにエスケープする）
        $this->assertSame($html, $blocks[0]['text']);
        $this->assertSame('<h1>見出し</h1>', $blocks[1]['text']);
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function brokenBlocks(): array
    {
        $long = static fn (int $n): string => str_repeat('あ', $n);
        $row = static fn (int $n): array => array_fill(0, $n, 'x');

        return [
            '並びでない' => [['a' => ['type' => 'paragraph', 'text' => 'x']], 'description_blocks'],
            '文字だけ' => ['段落', 'description_blocks'],
            '知らない種類' => [[['type' => 'html', 'text' => '<p>x</p>']], 'description_blocks.0'],
            '種類が無い' => [[['text' => 'x']], 'description_blocks.0'],
            '知らない項目' => [[['type' => 'paragraph', 'text' => 'x', 'html' => '<b>x</b>']], 'description_blocks.0'],
            '並びのブロック' => [[['paragraph', 'x']], 'description_blocks.0'],
            '空の段落' => [[['type' => 'paragraph', 'text' => '   ']], 'description_blocks.0.text'],
            '文が無い段落' => [[['type' => 'paragraph']], 'description_blocks.0.text'],
            '文が null の見出し' => [[['type' => 'heading', 'text' => null]], 'description_blocks.0.text'],
            '文が文字でない' => [[['type' => 'paragraph', 'text' => ['x']]], 'description_blocks.0.text'],
            '段落が長すぎる' => [[['type' => 'paragraph', 'text' => $long(ProductDescription::MAX_TEXT + 1)]], 'description_blocks.0.text'],
            '見出しが 3 段' => [[['type' => 'heading', 'level' => 4, 'text' => 'x']], 'description_blocks.0.level'],
            '見出しが 1' => [[['type' => 'heading', 'level' => 1, 'text' => 'x']], 'description_blocks.0.level'],
            '見出しの段が文字' => [[['type' => 'heading', 'level' => '2', 'text' => 'x']], 'description_blocks.0.level'],
            '見出しに改行' => [[['type' => 'heading', 'text' => "上\n下"]], 'description_blocks.0.text'],
            '見出しが長すぎる' => [[['type' => 'heading', 'text' => $long(ProductDescription::MAX_HEADING + 1)]], 'description_blocks.0.text'],
            '箇条書きが空' => [[['type' => 'list', 'items' => []]], 'description_blocks.0.items'],
            '箇条書きの空の行' => [[['type' => 'list', 'items' => ['a', ' ']]], 'description_blocks.0.items.1'],
            '箇条書きが多すぎる' => [[['type' => 'list', 'items' => $row(ProductDescription::MAX_LIST_ITEMS + 1)]], 'description_blocks.0.items'],
            '箇条書きの形' => [[['type' => 'list', 'style' => 'disc', 'items' => ['a']]], 'description_blocks.0.style'],
            '写真の ID の形' => [[['type' => 'image', 'media_id' => '../../etc/passwd']], 'description_blocks.0.media_id'],
            '写真の ID が無い' => [[['type' => 'image']], 'description_blocks.0.media_id'],
            '写真の説明に改行' => [[['type' => 'image', 'media_id' => self::ULID_A, 'caption' => "a\nb"]], 'description_blocks.0.caption'],
            '表が空' => [[['type' => 'table', 'rows' => [['', ' ']]]], 'description_blocks.0.rows'],
            '表に行が無い' => [[['type' => 'table', 'rows' => []]], 'description_blocks.0.rows'],
            '表の行が多すぎる' => [[['type' => 'table', 'rows' => array_fill(0, ProductDescription::MAX_TABLE_ROWS + 1, ['x'])]], 'description_blocks.0.rows'],
            '表の列が多すぎる' => [[['type' => 'table', 'rows' => [$row(ProductDescription::MAX_TABLE_COLUMNS + 1)]]], 'description_blocks.0.rows.0'],
            '表の列がそろわない' => [[['type' => 'table', 'rows' => [['a', 'b'], ['c']]]], 'description_blocks.0.rows.1'],
            '表のますが長すぎる' => [[['type' => 'table', 'rows' => [[$long(ProductDescription::MAX_CELL + 1)]]]], 'description_blocks.0.rows.0.0'],
            '表の見出しが文字' => [[['type' => 'table', 'header' => 'yes', 'rows' => [['a']]]], 'description_blocks.0.header'],
            '注意書きの種類' => [[['type' => 'note', 'tone' => 'danger', 'text' => 'x']], 'description_blocks.0.tone'],
            'ブロックが多すぎる' => [array_fill(0, ProductDescription::MAX_BLOCKS + 1, ['type' => 'paragraph', 'text' => 'x']), 'description_blocks'],
            '写真が多すぎる' => [array_fill(0, ProductDescription::MAX_IMAGES + 1, ['type' => 'image', 'media_id' => self::ULID_A]), 'description_blocks.'.ProductDescription::MAX_IMAGES],
            '合計が長すぎる' => [array_fill(0, intdiv(ProductDescription::MAX_TOTAL_TEXT, ProductDescription::MAX_TEXT) + 1, ['type' => 'paragraph', 'text' => $long(ProductDescription::MAX_TEXT)]), 'description_blocks'],
            'NUL 文字' => [[['type' => 'paragraph', 'text' => "a\0b"]], 'description_blocks.0.text'],
            '制御文字（C0）' => [[['type' => 'paragraph', 'text' => "a\x1bb"]], 'description_blocks.0.text'],
            '制御文字（DEL）' => [[['type' => 'note', 'text' => "a\x7fb"]], 'description_blocks.0.text'],
            '制御文字（C1）' => [[['type' => 'paragraph', 'text' => "a\u{0085}b"]], 'description_blocks.0.text'],
            '1 行の文にタブ' => [[['type' => 'heading', 'text' => "a\tb"]], 'description_blocks.0.text'],
            '文字の向きを変える印' => [[['type' => 'paragraph', 'text' => "価格\u{202E}0001"]], 'description_blocks.0.text'],
            '文字の向きを変える印（isolate）' => [[['type' => 'list', 'items' => ["a\u{2066}b"]]], 'description_blocks.0.items.0'],
            '壊れた UTF-8' => [[['type' => 'paragraph', 'text' => "a\xC3\x28b"]], 'description_blocks.0.text'],
        ];
    }

    #[DataProvider('brokenBlocks')]
    public function test_blocks_outside_the_fixed_shape_are_refused_with_the_path(mixed $input, string $path): void
    {
        try {
            ProductDescription::validateBlocks($input);
            $this->fail('受け付けてしまった');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($path, $e->errors(), implode(', ', array_keys($e->errors())));
        }
    }

    public function test_the_limits_themselves_are_accepted(): void
    {
        $long = static fn (int $n): string => str_repeat('あ', $n);
        // 1 つずつ（全部を 1 つの説明に入れると、文の合計の上限を越える）
        foreach ([
            ['type' => 'paragraph', 'text' => $long(ProductDescription::MAX_TEXT)],
            ['type' => 'heading', 'text' => $long(ProductDescription::MAX_HEADING)],
            ['type' => 'list', 'items' => array_fill(0, ProductDescription::MAX_LIST_ITEMS, $long(ProductDescription::MAX_LIST_ITEM))],
            ['type' => 'table', 'rows' => array_fill(0, ProductDescription::MAX_TABLE_ROWS, array_fill(0, ProductDescription::MAX_TABLE_COLUMNS, $long(ProductDescription::MAX_CELL)))],
        ] as $block) {
            $this->assertCount(1, ProductDescription::validateBlocks([$block]), $block['type']);
        }

        // 文の合計はちょうどまで
        $this->assertCount(10, ProductDescription::validateBlocks(array_fill(0, intdiv(ProductDescription::MAX_TOTAL_TEXT, ProductDescription::MAX_TEXT), ['type' => 'paragraph', 'text' => $long(ProductDescription::MAX_TEXT)])));

        $this->assertCount(ProductDescription::MAX_BLOCKS, ProductDescription::validateBlocks(array_fill(0, ProductDescription::MAX_BLOCKS, ['type' => 'paragraph', 'text' => 'x'])));
        $this->assertCount(ProductDescription::MAX_IMAGES, ProductDescription::validateBlocks(array_fill(0, ProductDescription::MAX_IMAGES, ['type' => 'image', 'media_id' => self::ULID_A])));
        $this->assertCount(ProductDescription::MAX_SPECS, ProductDescription::validateSpecs(array_fill(0, ProductDescription::MAX_SPECS, ['label' => $long(ProductDescription::MAX_SPEC_LABEL), 'value' => $long(ProductDescription::MAX_SPEC_VALUE)])));
        $this->assertSame($long(ProductDescription::MAX_SUMMARY), ProductDescription::validateSummary($long(ProductDescription::MAX_SUMMARY)));
    }

    public function test_spec_rows_and_the_summary(): void
    {
        $this->assertSame(
            [['label' => '素材', 'value' => '綿 100%'], ['label' => '原産国', 'value' => "日本\n（縫製）"]],
            ProductDescription::validateSpecs([['label' => ' 素材 ', 'value' => '綿 100%'], ['value' => "日本\r\n（縫製）", 'label' => '原産国']]),
        );
        $this->assertSame('春の新作', ProductDescription::validateSummary(' 春の新作 '));
        $this->assertNull(ProductDescription::validateSummary('   '));
        $this->assertNull(ProductDescription::validateSummary(null));

        foreach ([
            'specs' => ['label' => '素材', 'value' => '綿'],
            'specs.0' => [['label' => '素材', 'value' => '']],
            'specs.0.value' => [['label' => '素材', 'value' => ['綿']]],
            'specs.0.label' => [['label' => "素\n材", 'value' => 'x']],
            'specs.1' => [['label' => 'a', 'value' => 'b'], ['label' => 'a', 'value' => 'b', 'html' => '<b>']],
        ] as $path => $input) {
            try {
                ProductDescription::validateSpecs($input);
                $this->fail("{$path} を受け付けてしまった");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($path, $e->errors(), $path);
            }
        }

        foreach (['specs' => array_fill(0, ProductDescription::MAX_SPECS + 1, ['label' => 'a', 'value' => 'b']), 'specs.0.value' => [['label' => 'a', 'value' => str_repeat('a', ProductDescription::MAX_SPEC_VALUE + 1)]]] as $path => $input) {
            try {
                ProductDescription::validateSpecs($input);
                $this->fail("{$path} を受け付けてしまった");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($path, $e->errors(), $path);
            }
        }

        foreach (["一\n行", str_repeat('a', ProductDescription::MAX_SUMMARY + 1), "a\u{202E}b", 3] as $summary) {
            try {
                ProductDescription::validateSummary($summary);
                $this->fail('短い説明を受け付けてしまった');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('summary', $e->errors());
            }
        }
    }

    public function test_plain_text_for_the_old_column_and_reading_stored_values_drops_broken_blocks(): void
    {
        $blocks = ProductDescription::validateBlocks([
            ['type' => 'heading', 'text' => '素材'],
            ['type' => 'paragraph', 'text' => "綿\n麻"],
            ['type' => 'list', 'items' => ['軽い', '涼しい']],
            ['type' => 'image', 'media_id' => self::ULID_A],
            ['type' => 'table', 'rows' => [['M', '', '70']]],
            ['type' => 'note', 'text' => '手洗い'],
        ]);
        $this->assertSame("素材\n\n綿\n麻\n\n・軽い\n・涼しい\n\nM / 70\n\n手洗い", ProductDescription::plainText($blocks));
        $this->assertNull(ProductDescription::plainText([]));
        $this->assertSame(ProductDescription::MAX_PLAIN_TEXT, mb_strlen((string) ProductDescription::plainText(array_fill(0, 3, ['type' => 'paragraph', 'text' => str_repeat('あ', 4000)]))));

        // 前の形の説明は 1 つの段落に
        $this->assertSame([['type' => 'paragraph', 'text' => "一\n二"]], ProductDescription::fromText(" 一\r\n二 "));
        $this->assertSame([], ProductDescription::fromText('  '));
        $this->assertSame([], ProductDescription::fromText(null));

        // 読むときは崩れたものだけ捨てる
        $this->assertSame(
            [['type' => 'paragraph', 'text' => '残る'], ['type' => 'heading', 'level' => 2, 'text' => '見出し']],
            ProductDescription::readBlocks([['type' => 'paragraph', 'text' => '残る'], ['type' => 'script', 'text' => 'x'], 'x', ['type' => 'heading', 'text' => '見出し'], ['type' => 'image', 'media_id' => 'bad']]),
        );
        $this->assertSame([], ProductDescription::readBlocks('壊れた値'));
        $this->assertSame([['label' => 'a', 'value' => 'b']], ProductDescription::readSpecs([['label' => 'a', 'value' => 'b'], ['label' => 'x'], 'y']));
    }

    /**
     * 読むときは、保存のときに断る文字（向きを変える印・制御文字・壊れた UTF-8）を「その文字だけ外す」。ブロックごと捨てない
     * （Windows や Office から貼った説明が、移行の後にショップから消えないように。見直しの指摘 1）
     */
    public function test_reading_strips_the_characters_the_app_would_refuse_instead_of_dropping_the_block(): void
    {
        $this->assertSame(
            [
                ['type' => 'paragraph', 'text' => "説明の文\n2 行目"],
                ['type' => 'heading', 'level' => 2, 'text' => '見出し'],
                ['type' => 'list', 'style' => 'bullet', 'items' => ['ab']],
                ['type' => 'table', 'header' => false, 'caption' => null, 'rows' => [['a?(b', 'c']]],
            ],
            ProductDescription::readBlocks([
                ['type' => 'paragraph', 'text' => "説明\u{202E}の文\u{0007}\n2 行目\u{2069}"],
                ['type' => 'heading', 'text' => "見\u{2066}出し\n"],
                ['type' => 'list', 'items' => ["a\u{0085}b"]],
                ['type' => 'table', 'rows' => [["a\xC3\x28b", "c\u{202A}"]]],
            ]),
        );
        // 長すぎる文は切り詰めて残す
        $this->assertSame(ProductDescription::MAX_HEADING, mb_strlen(ProductDescription::readBlocks([['type' => 'heading', 'text' => str_repeat('あ', 300)]])[0]['text']));
        $this->assertSame('春の新作', ProductDescription::readSummary("春の\u{202E}新作"));
        $this->assertSame([['label' => '産地', 'value' => '日本']], ProductDescription::readSpecs([['label' => "産\u{2067}地", 'value' => "日本\u{0001}"]]));
        // 外したら空になる文のブロックは捨てる（空の段落は描かない）
        $this->assertSame([], ProductDescription::readBlocks([['type' => 'paragraph', 'text' => "\u{202E}"]]));

        // 保存のときは今までどおり断る
        $this->expectException(ValidationException::class);
        ProductDescription::validateBlocks([['type' => 'paragraph', 'text' => "説明\u{202E}の文"]]);
    }

    public function test_canonical_ignores_the_key_order_of_jsonb(): void
    {
        $this->assertSame(
            ProductDescription::canonical([['type' => 'heading', 'level' => 2, 'text' => 'x']]),
            ProductDescription::canonical([['text' => 'x', 'type' => 'heading', 'level' => 2]]),
        );
        $this->assertNotSame(ProductDescription::canonical([['a'], ['b']]), ProductDescription::canonical([['b'], ['a']]));
    }
}
