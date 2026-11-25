<?php

declare(strict_types=1);

namespace EcSugar\PluginShippingCsv;

use App\Core\Fulfillment\DeliveryChoice;
use Carbon\CarbonImmutable;

/**
 * 書き出す箱 1 つ（発送を待つ配送の受渡 1 つ）。届け先は受渡の暗号化した列から読んだもの（書き出しの中だけで使い、どこにも置かない）
 */
final readonly class Parcel
{
    /**
     * @param  array<string, string>  $address  name・company・zip・prefecture・city・line1・line2・phone（無い項目は入らない）
     * @param  list<array{name: string, quantity: int}>  $items  中の品（取引の明細の名前と数）
     * @param  ?DeliveryChoice  $delivery  お客さんが選んだお届けの日と時間帯（ADR 0059。指定なしは null）
     * @param  ?int  $verifiedAge  年齢の確かめをした注文の年齢（ADR 0077。備考・記事の欄に「年齢確認済20歳以上」。確かめていない注文は null）
     * @param  string  $note  備考（App が注文に足した料金の内容。「ラッピング: リボン／のし: 御祝・外のし・名入れ 山田」。ADR 0078。無ければ空）。
     *                        名入れも入る（届け先と同じく書き出しの中だけで使う）。長くても切らずにそのまま出す（名入れが欠けないように。欄の長さは送り状のソフトの確かめに任せる）
     */
    public function __construct(
        public string $reference,
        public string $transactionId,
        public string $fulfillmentId,
        public array $address,
        public array $items,
        public ?DeliveryChoice $delivery = null,
        public ?int $verifiedAge = null,
        public string $note = '',
    ) {}

    /**
     * 送り状の備考・記事の欄。年齢の確かめ（運送会社の受け取りの時の確かめ用。B2 クラウドの記事は全角 10 文字までなので短い形で先頭に）と、
     * App が注文に足した料金の内容（$note）を「／」でつなぐ。どちらも無ければ空
     */
    public function remark(): string
    {
        return implode('／', array_filter([$this->verifiedAge === null ? '' : "年齢確認済{$this->verifiedAge}歳以上", $this->note], static fn (string $v): bool => $v !== ''));
    }

    /**
     * お届けの希望日を $format の形で（指定なしは空）
     */
    public function deliveryDate(string $format): string
    {
        $date = $this->delivery?->date;

        return $date === null ? '' : (string) CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Asia/Tokyo')?->format($format);
    }

    public function get(string $key): string
    {
        return $this->address[$key] ?? '';
    }

    /** 郵便番号（数字 7 桁なら 123-4567 の形に） */
    public function zip(): string
    {
        return Shipper::zip($this->get('zip'));
    }

    /** 品の数の合計 */
    public function quantity(): int
    {
        return array_sum(array_column($this->items, 'quantity'));
    }

    /**
     * 品名（$index 番目の品の名前を $max 文字まで。品が $index より少なければ空）
     */
    public function itemName(int $index, int $max): string
    {
        $name = $this->items[$index]['name'] ?? '';

        return mb_strlen($name) > $max ? mb_substr($name, 0, $max) : $name;
    }

    /**
     * 品名を 1 つの欄にまとめる（1 つ目の名前と「ほか N 点」。$max 文字まで）
     */
    public function summary(int $max): string
    {
        $more = count($this->items) - 1;
        $tail = $more > 0 ? " ほか{$more}点" : '';
        $room = max(1, $max - mb_strlen($tail));

        return $this->itemName(0, $room).$tail;
    }
}
