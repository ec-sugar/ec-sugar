<?php

declare(strict_types=1);

namespace Tests\Unit;

use EcSugar\PluginShippingCsv\Parcel;
use PHPUnit\Framework\TestCase;

/**
 * 配送の CSV の備考・記事の欄（App「配送の CSV」）: 年齢確認済み（ADR 0077）と App が注文に足した料金の内容（ラッピング・のし。ADR 0078）を
 * 両方とも落とさずに入れる。年齢確認済みは運送会社の受け取りの時の確かめに使うので先頭に（取り込みで 2 つが同じ欄を取り合った）
 */
final class ShippingCsvRemarkTest extends TestCase
{
    public function test_the_age_and_the_wrapping_share_the_remark_with_the_age_first(): void
    {
        $this->assertSame('', self::parcel(null, '')->remark());
        $this->assertSame('年齢確認済20歳以上', self::parcel(20, '')->remark());
        $this->assertSame('ラッピング: リボン／のし: 御祝・外のし', self::parcel(null, 'ラッピング: リボン／のし: 御祝・外のし')->remark());
        $this->assertSame('年齢確認済20歳以上／ラッピング: リボン／のし: 御祝・外のし', self::parcel(20, 'ラッピング: リボン／のし: 御祝・外のし')->remark());
    }

    private static function parcel(?int $age, string $note): Parcel
    {
        return new Parcel('A-1', 'tx', 'f', [], [], null, $age, $note);
    }
}
