<?php

declare(strict_types=1);

namespace App\Storefront\View;

use App\Core\Section\CoreSections;

/**
 * 核の区画「比べる表」（basic_table）の列と行を決める（核の部品 basic_table.blade.php が使う）。
 * 列の見出しは区画の欄 col1〜col5、行はブロック row（行の見出し label と c1〜c5）。見出しも中身も空の列は出さず、
 * 見出しも中身も空の行も出さない（表の欄の種類を足さずに、核の文の欄だけで表を作るため）
 */
final class SectionTable
{
    /**
     * 出す列の番号（1〜5。見出しか、どれかの行の中身がある列だけ、左から）
     *
     * @return list<int>
     */
    public static function columns(SectionView $section): array
    {
        $rows = self::rows($section);
        $columns = [];

        for ($i = 1; $i <= CoreSections::TABLE_COLUMNS; $i++) {
            if ($section["col{$i}"] !== null || array_filter($rows, static fn (SectionView $row): bool => $row["c{$i}"] !== null) !== []) {
                $columns[] = $i;
            }
        }

        return $columns;
    }

    /**
     * 出す行（行の見出しか、どれかの列に中身のあるブロックだけ）
     *
     * @return list<SectionView>
     */
    public static function rows(SectionView $section): array
    {
        return array_values(array_filter($section->blocks, static function (SectionView $row): bool {
            if ($row->key !== 'row') {
                return false;
            }

            for ($i = 1; $i <= CoreSections::TABLE_COLUMNS; $i++) {
                if ($row["c{$i}"] !== null) {
                    return true;
                }
            }

            return $row['label'] !== null;
        }));
    }

    /**
     * 列の見出しの段を出すか（左上のますか、どれかの列の見出しがあるとき）
     */
    public static function hasHead(SectionView $section): bool
    {
        if ($section['corner'] !== null) {
            return true;
        }

        foreach (self::columns($section) as $i) {
            if ($section["col{$i}"] !== null) {
                return true;
            }
        }

        return false;
    }
}
