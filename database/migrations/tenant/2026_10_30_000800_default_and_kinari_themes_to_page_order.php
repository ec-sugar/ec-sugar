<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 角砂糖（default）と kinari を区画の並びのテーマにした（ADR 0039 区切り 4）ときの、今の値の写し。
 *
 * 前は決まった並び（theme.json に pages が無い）で、読むときに宣言の順に並べ直し、しまっていない種類は空の区画として描いていた。
 * 並びのテーマでは、しまってある順のまま・しまってある区画だけを描くので、そのままだとトップの見た目が変わる:
 * - 角砂糖の新着（今まではテーマの画面が区画の外に描いていた）は、新しい種類 products になった。しまってある並びには無い
 * - kinari の新着（new_arrivals）とフッター（footer）も新しい種類。よく選ばれている商品は、季節の特集より前に描く
 *
 * そこで、トップの並びをしまってあるバージョン（下書きと公開のどちらも）について、
 * - 区画を新しい最初の並びの種類の順に並べ直す（決まった並びの間は店の人が並べ替えられなかったので、店の選んだ順を壊さない）。
 *   最初の並びに無い種類の区画は後ろに、元の順のまま
 * - 最初の並びの種類でしまっていないものは、空の区画（ID は種類の名前、パターンは default）として足す（決まった並びの読み方と同じ）
 * 下書きと公開に同じ写しをするので「公開していない変更」の印は変わらない。改訂は進めない（店の人の変更ではない）。
 * トップをまだ保存していないバージョンは触らない（読むときに新しい最初の並びになる）。
 * 並び順はこの移行の中に書き写す（後でテーマの宣言が変わっても、この移行の意味を変えない）。
 *
 * down は何もしない（前のアプリは決まった並びとして宣言の順に読み直し、知らない種類の区画は読み捨てるので、写した形のままでも前と同じに描く）
 */
return new class extends Migration
{
    /** テーマ => 新しいトップの最初の並びの種類の順（各テーマの theme.json の pages.home.default と同じ） */
    private const array ORDERS = [
        'default' => ['announcement', 'hero', 'products', 'categories', 'promises', 'footer'],
        'kinari' => ['announcement', 'hero', 'categories', 'looks', 'new_arrivals', 'favorites', 'seasonal', 'promises', 'footer'],
    ];

    public function up(): void
    {
        foreach (DB::table('theme_versions')->whereIn('theme', array_keys(self::ORDERS))->orderBy('id')->get(['id', 'theme', 'draft', 'published']) as $row) {
            $order = self::ORDERS[(string) $row->theme];
            $draft = self::rewrite($row->draft, $order);
            $published = $row->published === null ? null : self::rewrite($row->published, $order);

            if ($draft !== null || $published !== null) {
                DB::table('theme_versions')->where('id', $row->id)->update(array_filter(['draft' => $draft, 'published' => $published], static fn (?string $v): bool => $v !== null));
            }
        }
    }

    public function down(): void {}

    /**
     * しまってある値のトップの並びを写す。変えなければ null
     *
     * @param  list<string>  $order
     */
    private static function rewrite(mixed $json, array $order): ?string
    {
        // 空の対応（{}）と空の並び（[]）を取り違えないように、対応はオブジェクトのまま読む
        $document = is_string($json) ? json_decode($json) : null;

        if (! $document instanceof stdClass || ! ($document->pages ?? null) instanceof stdClass || ! is_array($document->pages->home ?? null)) {
            return null;
        }

        $home = array_values(array_filter($document->pages->home, static fn (mixed $s): bool => $s instanceof stdClass && is_string($s->type ?? null) && is_string($s->id ?? null)));
        $rank = array_flip($order);
        $known = array_values(array_filter($home, static fn (stdClass $s): bool => isset($rank[$s->type])));
        $others = array_values(array_filter($home, static fn (stdClass $s): bool => ! isset($rank[$s->type])));
        // 同じ種類の中は元の順のまま（usort は安定）
        usort($known, static fn (stdClass $a, stdClass $b): int => $rank[$a->type] <=> $rank[$b->type]);

        $ids = array_fill_keys(array_map(static fn (stdClass $s): string => $s->id, $home), true);
        $types = array_fill_keys(array_map(static fn (stdClass $s): string => $s->type, $home), true);
        $out = [];

        foreach ($order as $type) {
            if (! isset($types[$type])) {
                $id = $type;
                for ($n = 2; isset($ids[$id]); $n++) {
                    $id = "{$type}-{$n}";
                }
                $ids[$id] = true;
                $out[] = (object) ['id' => $id, 'type' => $type, 'pattern' => 'default', 'hidden' => false, 'values' => new stdClass, 'items' => [], 'blocks' => []];

                continue;
            }

            foreach ($known as $section) {
                if ($section->type === $type) {
                    $out[] = $section;
                }
            }
        }

        $out = [...$out, ...$others];

        if ($out === $document->pages->home) {
            return null;
        }

        $document->pages->home = $out;

        return (string) json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
};
