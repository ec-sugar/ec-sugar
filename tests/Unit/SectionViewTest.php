<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Storefront\View\SectionView;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * テーマが読む区画の中身（テーマ API 2）。宣言に無い欄の名前は、?? の形でも書き間違いとして止める
 */
final class SectionViewTest extends TestCase
{
    public function test_declared_fields_read_as_values_and_typos_are_loud(): void
    {
        $hero = new SectionView('hero', ['heading' => null, 'body' => '文'], []);

        $this->assertSame('既定', $hero['heading'] ?? '既定');
        $this->assertSame('文', $hero['body']);
        $this->assertFalse(isset($hero['heading']));
        $this->assertFalse($hero->isEmpty());

        $this->expectException(LogicException::class);
        $unused = $hero['headline'] ?? '既定';
    }

    public function test_reading_a_typo_without_a_default_is_loud_too(): void
    {
        $this->expectException(LogicException::class);
        (new SectionView('hero', ['heading' => null], []))['headline'];
    }
}
