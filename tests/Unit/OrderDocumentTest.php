<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Plugin\OrderDocument\OrderDocument;
use App\Core\Plugin\OrderLink;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 注文の書類の口（ADR 0051 の作って決めたこと）の値の形。お客さんのリンクの行き先は、その App のショップの経路だけ
 */
final class OrderDocumentTest extends TestCase
{
    public function test_a_link_goes_only_under_the_apps_own_shop_path(): void
    {
        $link = new OrderLink('order-print', '領収書', '/p/order-print/receipt/01jbq0000000000000000000zz?expires=1&shop=opa&signature=ab12');
        $this->assertSame('領収書', $link->label);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function badLinks(): array
    {
        return [
            'ほかの App の経路' => ['/p/subscription/account', '領収書'],
            '外のサイト' => ['https://evil.example/p/order-print/x', '領収書'],
            'ホストを指す' => ['//evil.example/p/order-print/x', '領収書'],
            '上の階へ' => ['/p/order-print/../../account', '領収書'],
            '二重の区切り' => ['/p/order-print//x', '領収書'],
            '引用符' => ['/p/order-print/x?a="b', '領収書'],
            'javascript' => ['javascript:alert(1)', '領収書'],
            '空の文字' => ['/p/order-print/x', ''],
            '改行の文字' => ['/p/order-print/x', "領収\n書"],
            '長すぎる文字' => ['/p/order-print/x', str_repeat('あ', 21)],
        ];
    }

    #[DataProvider('badLinks')]
    public function test_other_destinations_and_labels_are_refused(string $path, string $label): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OrderLink('order-print', $label, $path);
    }

    public function test_a_document_has_a_short_name_and_label(): void
    {
        $this->assertTrue((new OrderDocument('delivery_note', '納品書', bulk: true))->bulk);

        foreach ([['Receipt', '領収書'], ['receipt-1', '領収書'], ['receipt', ''], ['receipt', str_repeat('あ', 21)]] as [$key, $label]) {
            try {
                new OrderDocument($key, $label);
                $this->fail("断るはずの書類: {$key}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
