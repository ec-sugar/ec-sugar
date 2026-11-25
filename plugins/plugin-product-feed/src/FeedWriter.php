<?php

declare(strict_types=1);

namespace EcSugar\PluginProductFeed;

use XMLWriter;

/**
 * フィードの行を RSS 2.0 の XML（Google の g: の名前空間）に書く。Google も Meta もこの形を読む（README の「仕様の出どころ」）。
 * 文は XMLWriter が書く（&・<・> はエスケープされ、HTML として扱われない）。XML に書けない文字は FeedBuilder が先に外している。
 * 行き先ごとの違いは在庫の言葉・g: を付けない項目・Google だけの identifier_exists・Meta だけのそのほかの軸（FeedFormat）
 */
final class FeedWriter
{
    public const string NAMESPACE = 'http://base.google.com/ns/1.0';

    /**
     * @param  string  $shopName  店の名前（チャンネルの題）
     * @param  string  $shopUrl  ショップの入口の URL
     */
    public function write(FeedFormat $format, FeedResult $result, string $shopName, string $shopUrl): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('rss');
        $xml->writeAttribute('version', '2.0');
        $xml->writeAttribute('xmlns:g', self::NAMESPACE);
        $xml->startElement('channel');
        $name = FeedBuilder::text($shopName, 150);
        $xml->writeElement('title', $name === '' ? 'Products' : $name);
        $xml->writeElement('link', $shopUrl);
        $xml->writeElement('description', $name === '' ? 'Products' : "{$name} の商品");

        foreach ($result->items as $item) {
            $this->item($xml, $format, $item);
        }

        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private function item(XMLWriter $xml, FeedFormat $format, FeedItem $item): void
    {
        $xml->startElement('item');
        $xml->writeElement('g:id', $item->id);

        if ($item->itemGroupId !== null) {
            $xml->writeElement('g:item_group_id', $item->itemGroupId);
        }

        $xml->writeElement('g:title', $item->title);
        $xml->writeElement('g:description', $item->description);
        $xml->writeElement('g:link', $item->link);
        $xml->writeElement('g:image_link', $item->imageLink);

        foreach ($item->additionalImageLinks as $link) {
            $xml->writeElement($format->element('additional_image_link'), $link);
        }

        $xml->writeElement('g:availability', $format->availability($item->inStock, $item->availabilityDate !== null));

        // 予約を受けている予約の商品（ADR 0073）は発売日を添える（Google と Meta の preorder の決まり）
        if ($item->inStock && $item->availabilityDateText() !== null) {
            $xml->writeElement('g:availability_date', (string) $item->availabilityDateText());
        }
        $xml->writeElement('g:price', $item->priceText());
        $xml->writeElement('g:condition', $item->condition);

        if ($item->brand !== null) {
            $xml->writeElement('g:brand', $item->brand);
        }

        if ($item->mpn !== null) {
            $xml->writeElement('g:mpn', $item->mpn);
        }

        // GTIN（JAN など）は核にまだ列が無いので出さない。GTIN が無く、ブランドと MPN がそろわない商品は「ID が無い」と Google に伝える
        if ($format === FeedFormat::Google && ($item->brand === null || $item->mpn === null)) {
            $xml->writeElement('g:identifier_exists', 'no');
        }

        if ($item->color !== null) {
            $xml->writeElement($format->element('color'), $item->color);
        }

        if ($item->size !== null) {
            $xml->writeElement($format->element('size'), $item->size);
        }

        if ($format === FeedFormat::Meta) {
            foreach ($item->attributes as $label => $value) {
                $xml->startElement('additional_variant_attribute');
                $xml->writeElement('label', $label);
                $xml->writeElement('value', $value);
                $xml->endElement();
            }
        }

        if ($item->googleProductCategory !== null) {
            $xml->writeElement('g:google_product_category', $item->googleProductCategory);
        }

        $xml->endElement();
    }
}
