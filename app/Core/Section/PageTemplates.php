<?php

declare(strict_types=1);

namespace App\Core\Section;

use Closure;
use Illuminate\Validation\ValidationException;

/**
 * ページの雛形（ページを作るのが得意でない人でも、良いページを組めるように）。
 * 選ぶと区画がよい並びで入り、写真と文を差し替えるだけで 1 ページになる。雛形は核が持ち、どのテーマでも同じ 7 つ（7 つ目の「サービスと料金」は ）。
 *
 * - 行は「核の区画」（種類・パターン・見本の値）か「テーマの役目」（SectionRole。ヒーロー・カテゴリ・商品の並び・安心の一行）で書く。
 *   テーマの区画の名前はテーマごとに違うので、役目はテーマの宣言（theme.json の role）から、そのページに置ける最初の種類を選ぶ
 *   （パターンは宣言の最初、値はテーマの見本）。無ければ核の区画で代わりにし（SectionRole::fallback）、代わりも置けなければ飛ばす
 * - 核の区画の行も、そのページに置けない種類・無いパターン・1 つのページに置ける数（limit）を越える分は飛ばす（飛ばした名前は skipped）
 * - 値は区画の見本（example・example_blocks）に、雛形ごとの上書きを重ねたもの。写真は入れず、文は「ここに〜を書きます」の形。
 *   作り話の実績・お客さんの声・数を書かない。最後はいつもの保存（ThemeContentService::savePage → SectionValues::validatePage）が
 *   宣言どおりかを確かめる（上限・置けない種類も同じ確かめを通る）
 * - 作り直すとき（build）、外枠が読む区画（お知らせの帯・フッターなど、ページに描く部品の無い区画）は今の並びから残す
 *
 * @phpstan-import-type Placed from SectionValues
 *
 * @phpstan-type Row array{type: string, pattern: string, hidden: bool, values: array<string, mixed>, items: list<array<string, mixed>>, blocks: list<array{type: string, values: array<string, mixed>}>, label: string, wireframe: string|null, from: string}
 */
final class PageTemplates
{
    /**
     * 作り手・物語の年表の行（「年表」のパターンで出る。「写真と長文」でも、後でパターンを替えたときのために入れておく）。
     * 年は作り話の年にしない（見本と分かる形）
     */
    private const array MILESTONES = [
        ['type' => 'milestone', 'values' => ['when' => '〇〇〇〇 年', 'title' => 'ここに出来事を書きます（はじまりなど）', 'text' => 'ここに説明を書きます。']],
        ['type' => 'milestone', 'values' => ['when' => '〇〇〇〇 年', 'title' => 'ここに出来事を書きます', 'text' => 'ここに説明を書きます。']],
        ['type' => 'milestone', 'values' => ['when' => '今', 'title' => 'ここに今のことを書きます', 'text' => 'ここに説明を書きます。']],
    ];

    /** 名前（キー）=> 画面の名前・使いどころ・区画の行。名前は管理画面と API が使う（消さない・変えない） */
    private const array TEMPLATES = [
        'shop_top' => [
            'label' => 'トップ（雑貨の店）',
            'use' => 'はじめての人に、店の雰囲気・品ぞろえ・安心をひと通り見せる',
            'rows' => [
                ['role' => 'hero'],
                ['role' => 'categories'],
                ['role' => 'products'],
                ['type' => 'basic_story', 'pattern' => 'longform', 'values' => [
                    'kicker' => '私たちについて',
                    'heading' => 'ここにお店の紹介の見出しを書きます',
                ], 'blocks' => self::MILESTONES],
                ['type' => 'basic_testimonials', 'pattern' => 'cards'],
                ['role' => 'promises'],
            ],
        ],
        'single_product' => [
            'label' => '1 つの商品を売るページ',
            'use' => '主力の商品 1 つを、理由・写真・声・質問まで 1 ページで伝える',
            'rows' => [
                ['type' => 'basic_product', 'pattern' => 'split'],
                ['type' => 'basic_features', 'pattern' => 'three', 'values' => ['heading' => 'ここに特長の見出しを書きます（選ばれる理由など）']],
                ['type' => 'basic_gallery', 'pattern' => 'grid', 'values' => ['heading' => 'ここに写真の見出しを書きます（使っている様子など）']],
                ['type' => 'basic_testimonials', 'pattern' => 'scroll'],
                ['type' => 'basic_table', 'pattern' => 'photo'],
                ['type' => 'basic_faq', 'pattern' => 'list'],
                ['type' => 'basic_cta', 'pattern' => 'band'],
            ],
        ],
        'brand_story' => [
            'label' => 'ブランドストーリー',
            'use' => '作り手・歩み・こだわりを伝えて、ファンになってもらう',
            'rows' => [
                ['role' => 'hero'],
                ['type' => 'basic_story', 'pattern' => 'longform', 'values' => ['kicker' => '私たちについて', 'heading' => 'ここに作り手の思いの見出しを書きます'], 'blocks' => self::MILESTONES],
                ['type' => 'basic_numbers', 'pattern' => 'row'],
                ['type' => 'basic_story', 'pattern' => 'timeline', 'values' => [
                    'kicker' => '歩み',
                    'heading' => 'ここに歩みの見出しを書きます',
                    'body' => 'ここに、これまでの歩みの前置きを書きます。',
                ], 'blocks' => self::MILESTONES],
                ['type' => 'basic_gallery', 'pattern' => 'masonry', 'values' => ['heading' => 'ここに写真の見出しを書きます（工房の様子など）']],
                ['type' => 'basic_cta', 'pattern' => 'photo', 'values' => [
                    'heading' => 'ここに呼びかけの見出しを書きます',
                    'body' => 'ここに短い説明を書きます。後ろに敷く写真も選べます。',
                    'button_label' => '商品を見る',
                    'button_link' => '/products',
                ]],
            ],
        ],
        'seasonal' => [
            'label' => '季節の特集',
            'use' => 'セールや季節のおすすめを、写真と商品の並びでまとめる',
            'rows' => [
                ['role' => 'hero'],
                ['type' => 'basic_text', 'pattern' => 'center', 'values' => [
                    'heading' => 'ここに特集の見出しを書きます',
                    'body' => "ここに特集の説明を書きます。\n季節のおすすめや、期間・セールの決まりなど。",
                ]],
                ['type' => 'basic_products', 'pattern' => 'default', 'values' => ['heading' => 'ここに商品の並びの見出しを書きます']],
                ['type' => 'basic_image_text', 'pattern' => 'image_left', 'values' => [
                    'heading' => 'ここに読みものの見出しを書きます',
                    'body' => 'ここに写真の横に出す文を書きます。使い方や選び方など。',
                ]],
                ['type' => 'basic_products', 'pattern' => 'default', 'values' => ['heading' => 'ここに 2 つ目の商品の並びの見出しを書きます']],
                ['type' => 'basic_cta', 'pattern' => 'band', 'values' => [
                    'heading' => 'ここに呼びかけの見出しを書きます',
                    'body' => 'ここに短い説明を書きます（特集の期間など）。',
                    'button_label' => 'すべての商品を見る',
                    'button_link' => '/products',
                ]],
            ],
        ],
        'faq' => [
            'label' => 'よくある質問',
            'use' => '送料・お届け・返品などの答えを 1 ページにまとめる',
            'rows' => [
                ['type' => 'basic_text', 'pattern' => 'left', 'values' => [
                    'heading' => 'よくある質問',
                    'body' => 'ここに、このページで答えていることを短く書きます。',
                ]],
                ['type' => 'basic_faq', 'pattern' => 'side', 'values' => [
                    'heading' => 'ここに質問のまとまりの名前を書きます（ご注文とお届けなど）',
                    'body' => 'ここに短い説明を書きます。',
                ], 'blocks' => [
                    ['type' => 'question', 'values' => ['question' => '送料はいくらですか？', 'answer' => 'ここに回答を書きます。']],
                    ['type' => 'question', 'values' => ['question' => '届くまで何日かかりますか？', 'answer' => 'ここに回答を書きます。']],
                    ['type' => 'question', 'values' => ['question' => '支払いの方法は何がありますか？', 'answer' => 'ここに回答を書きます。']],
                    ['type' => 'question', 'values' => ['question' => '返品・交換はできますか？', 'answer' => 'ここに回答を書きます。']],
                    ['type' => 'question', 'values' => ['question' => '贈り物の包装はできますか？', 'answer' => 'ここに回答を書きます。']],
                ]],
                ['type' => 'basic_cta', 'pattern' => 'band', 'values' => [
                    'heading' => 'ここに、ほかに聞きたいことがある人への案内を書きます',
                    'body' => 'ここに問い合わせの方法を書きます。',
                    'button_label' => '商品を見る',
                    'button_link' => '/products',
                ]],
            ],
        ],
        'how_to' => [
            'label' => 'ご注文の流れ・使い方',
            'use' => '買い方やお手入れの仕方を、順を追って説明する',
            'rows' => [
                ['type' => 'basic_text', 'pattern' => 'left', 'values' => [
                    'heading' => 'ご注文の流れ',
                    'body' => 'ここに、このページで説明することを短く書きます。',
                ]],
                ['type' => 'basic_steps', 'pattern' => 'row', 'values' => ['heading' => 'ここに流れの見出しを書きます'], 'blocks' => [
                    ['type' => 'step', 'values' => ['title' => '商品を選ぶ', 'text' => 'ここに説明を書きます。']],
                    ['type' => 'step', 'values' => ['title' => 'カートに入れる', 'text' => 'ここに説明を書きます。']],
                    ['type' => 'step', 'values' => ['title' => 'お支払い', 'text' => 'ここに説明を書きます。']],
                    ['type' => 'step', 'values' => ['title' => 'お届け', 'text' => 'ここに説明を書きます。']],
                ]],
                ['type' => 'basic_features', 'pattern' => 'list', 'values' => ['heading' => 'ここに使い方・お手入れの見出しを書きます'], 'blocks' => [
                    ['type' => 'feature', 'values' => ['icon' => 'droplets', 'title' => 'ここに要点を書きます', 'text' => 'ここに説明を書きます。']],
                    ['type' => 'feature', 'values' => ['icon' => 'sun', 'title' => 'ここに要点を書きます', 'text' => 'ここに説明を書きます。']],
                    ['type' => 'feature', 'values' => ['icon' => 'clock', 'title' => 'ここに要点を書きます', 'text' => 'ここに説明を書きます。']],
                    ['type' => 'feature', 'values' => ['icon' => 'package', 'title' => 'ここに要点を書きます', 'text' => 'ここに説明を書きます。']],
                ]],
                ['type' => 'basic_faq', 'pattern' => 'list'],
                ['type' => 'basic_cta', 'pattern' => 'band'],
            ],
        ],
        // : 設置の代行・相談などサービスを売る店（公式の店を作って、値段の表とサービス向けの絵が無かった）
        'service' => [
            'label' => 'サービスと料金',
            'use' => '設置の代行や相談などのサービスを、特長・流れ・料金・質問で 1 ページにまとめる',
            'rows' => [
                ['role' => 'hero'],
                ['type' => 'basic_features', 'pattern' => 'three', 'values' => ['heading' => 'ここにサービスの特長の見出しを書きます'], 'blocks' => [
                    ['type' => 'feature', 'values' => ['icon' => 'wrench', 'title' => 'ここに特長を書きます（作業の範囲など）', 'text' => 'ここに説明を書きます。']],
                    ['type' => 'feature', 'values' => ['icon' => 'message_circle', 'title' => 'ここに特長を書きます（相談の受け方など）', 'text' => 'ここに説明を書きます。']],
                    ['type' => 'feature', 'values' => ['icon' => 'headset', 'title' => 'ここに特長を書きます（終わった後のサポートなど）', 'text' => 'ここに説明を書きます。']],
                ]],
                ['type' => 'basic_steps', 'pattern' => 'row', 'values' => ['heading' => 'ここに流れの見出しを書きます（お申し込みからの流れなど）'], 'blocks' => [
                    ['type' => 'step', 'values' => ['title' => 'ご相談', 'text' => 'ここに説明を書きます。']],
                    ['type' => 'step', 'values' => ['title' => 'お見積もり', 'text' => 'ここに説明を書きます。']],
                    ['type' => 'step', 'values' => ['title' => 'お申し込み', 'text' => 'ここに説明を書きます。']],
                    ['type' => 'step', 'values' => ['title' => 'ご利用の開始', 'text' => 'ここに説明を書きます。']],
                ]],
                ['type' => 'basic_pricing', 'pattern' => 'featured', 'values' => ['heading' => 'ここに料金の見出しを書きます']],
                ['type' => 'basic_faq', 'pattern' => 'list', 'blocks' => [
                    ['type' => 'question', 'values' => ['question' => 'どのくらいの期間がかかりますか？', 'answer' => 'ここに回答を書きます。']],
                    ['type' => 'question', 'values' => ['question' => '支払いの方法は何がありますか？', 'answer' => 'ここに回答を書きます。']],
                    ['type' => 'question', 'values' => ['question' => '申し込んだ後に内容を変えられますか？', 'answer' => 'ここに回答を書きます。']],
                ]],
                ['type' => 'basic_cta', 'pattern' => 'band', 'values' => [
                    'heading' => 'ここに呼びかけの見出しを書きます（ご相談の案内など）',
                    'body' => 'ここに短い説明を書きます。',
                    'button_label' => 'サービスを見る',
                    'button_link' => '/products',
                ]],
            ],
        ],
    ];

    /**
     * 雛形の名前（宣言の順）
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::TEMPLATES);
    }

    /**
     * 雛形をそのテーマのそのページに合わせて読む。知らない名前は null
     *
     * @param  array<string, int>  $taken  ページにもう置いてある数（種類 => 数。作り直すときに残す区画。1 つのページに置ける数に数える）
     * @return array{rows: list<Row>, skipped: list<string>}|null
     */
    public static function resolve(string $name, SectionSchema $schema, PageSpec $page, array $taken = []): ?array
    {
        $template = self::TEMPLATES[$name] ?? null;

        if ($template === null) {
            return null;
        }

        $rows = [];
        $skipped = [];
        $counts = $taken;

        foreach ($template['rows'] as $row) {
            [$type, $pattern, $values, $blocks, $from, $label] = self::choose($row, $schema, $page);
            $spec = $page->allows($type) ? $schema->section($type) : null;

            if ($spec === null || $pattern === null || ! $spec->hasPattern($pattern) || ($spec->limit !== null && ($counts[$type] ?? 0) >= $spec->limit)) {
                $skipped[] = $label;

                continue;
            }

            $counts[$type] = ($counts[$type] ?? 0) + 1;
            $rows[] = [
                'type' => $type,
                'pattern' => $pattern,
                'hidden' => false,
                // 見本の値に、雛形ごとの上書きを重ねる（雛形が null を書いた欄は空にする）
                'values' => array_replace($spec->example['values'] ?? [], $values),
                'items' => $spec->example['items'] ?? [],
                'blocks' => $blocks ?? $spec->exampleBlocks,
                'label' => $spec->label,
                'wireframe' => $spec->wireframes[$pattern] ?? null,
                'from' => $from,
            ];
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /**
     * 管理画面に渡す雛形の一覧（そのテーマのそのページで、どの区画がどのパターンで入るか。値は渡さない）
     *
     * @return list<array{name: string, label: string, use: string, rows: list<array{type: string, pattern: string, label: string, wireframe: string|null, from: string}>, skipped: list<string>}>
     */
    public static function catalog(SectionSchema $schema, PageSpec $page): array
    {
        $out = [];

        foreach (self::TEMPLATES as $name => $template) {
            $resolved = self::resolve($name, $schema, $page);
            assert($resolved !== null);
            $out[] = [
                'name' => $name,
                'label' => $template['label'],
                'use' => $template['use'],
                'rows' => array_map(static fn (array $row): array => [
                    'type' => $row['type'], 'pattern' => $row['pattern'], 'label' => $row['label'], 'wireframe' => $row['wireframe'], 'from' => $row['from'],
                ], $resolved['rows']),
                'skipped' => $resolved['skipped'],
            ];
        }

        return $out;
    }

    /**
     * 雛形で作り直した並び（保存に渡す形。ID の無い区画は新しく足す区画）。知らない名前は null。
     * 今の並び（$current）のうち、$keep が真を返す区画（外枠が読むお知らせの帯・フッターなど）は残す:
     * 今の並びで最初に描く区画より前にあったものは先頭へ、後ろにあったものは最後へ
     *
     * @param  list<Placed>  $current
     * @param  Closure(Placed): bool  $keep
     * @return list<array<string, mixed>>|null
     *
     * @throws ValidationException 雛形の区画が 1 つもこのページに置けない
     */
    public static function build(string $name, SectionSchema $schema, PageSpec $page, array $current, Closure $keep): ?array
    {
        $head = [];
        $tail = [];
        $taken = [];
        $drawn = false;
        foreach ($current as $section) {
            if (! $keep($section)) {
                $drawn = true;

                continue;
            }

            $taken[$section['type']] = ($taken[$section['type']] ?? 0) + 1;
            if ($drawn) {
                $tail[] = $section;
            } else {
                $head[] = $section;
            }
        }

        // 残す区画も 1 つのページに置ける数（limit）に数える（残したお知らせの帯と同じ種類を雛形が足して、保存で断られないように）
        $resolved = self::resolve($name, $schema, $page, $taken);

        if ($resolved === null) {
            return null;
        }

        // 1 つも置けなければ断る（白紙に置き換えてしまわないように）
        if ($resolved['rows'] === []) {
            throw ValidationException::withMessages(['template' => 'この雛形のセクションは、このテーマのこのページには置けません。ほかの雛形を選んでください。']);
        }

        $rows = array_map(static fn (array $row): array => [
            'type' => $row['type'], 'pattern' => $row['pattern'], 'hidden' => false, 'values' => $row['values'], 'items' => $row['items'], 'blocks' => $row['blocks'],
        ], $resolved['rows']);

        return [...$head, ...$rows, ...$tail];
    }

    /**
     * 行の種類・パターン・値の上書き・ブロック（null は見本のまま）・出どころ（theme テーマの役目の区画・fallback 役目の代わりの核の区画・
     * core 核の区画）・飛ばしたときの名前
     *
     * @param  array<string, mixed>  $row
     * @return array{string, string|null, array<string, mixed>, list<array{type: string, values: array<string, mixed>}>|null, string, string}
     */
    private static function choose(array $row, SectionSchema $schema, PageSpec $page): array
    {
        if (isset($row['role'])) {
            $role = SectionRole::from($row['role']);
            $type = $schema->typeFor($role, $page);

            if ($type !== null) {
                return [$type, $schema->section($type)?->defaultPattern(), [], null, 'theme', $role->label()];
            }

            // テーマに役目の区画が無い（置けない）ときは、核の区画で代わりにする
            $fallback = $role->fallback();

            return [$fallback['type'], $fallback['pattern'], $fallback['values'] ?? [], null, 'fallback', $role->label()];
        }

        $type = (string) $row['type'];

        return [$type, (string) $row['pattern'], $row['values'] ?? [], $row['blocks'] ?? null, 'core', CoreSections::section($type)->label ?? $type];
    }
}
