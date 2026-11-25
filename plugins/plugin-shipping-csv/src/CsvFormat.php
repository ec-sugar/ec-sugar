<?php

declare(strict_types=1);

namespace EcSugar\PluginShippingCsv;

use App\Core\Fulfillment\Enums\DeliverySlot;
use App\Core\Plugin\AdminPage\AdminCsv;
use App\Core\Plugin\AdminPage\CsvEncoding;
use Carbon\CarbonImmutable;

/**
 * 運送会社の送り状ソフトの取り込みの形。列の並び・項目名・文字コードは各社の公開の仕様に合わせた（出どころと、確かめが要る点は README）。
 * 代金引換は扱わない（その欄は空）。お客さんが選んだお届けの日と時間帯（ADR 0059）は、各社の配達の日・時間帯の欄に各社の区分の値で入れる
 * （クリックポストは郵便受けへの配達で指定が無いので入れない）。
 * App が注文に足した料金の内容（ラッピング・のしと名入れ。ADR 0078）は、B2 クラウドの「記事」とゆうプリR の「備考」に入れる（e飛伝とクリックポストの並びには自由な文の欄が無い）
 */
enum CsvFormat: string
{
    case YamatoB2 = 'yamato_b2';
    case SagawaEhiden = 'sagawa_ehiden';
    case JapanPostYupri = 'japanpost_yupri';
    case ClickPost = 'clickpost';

    public function label(): string
    {
        return match ($this) {
            self::YamatoB2 => 'ヤマト運輸（B2 クラウド）',
            self::SagawaEhiden => '佐川急便（e飛伝）',
            self::JapanPostYupri => '日本郵便（ゆうプリR）',
            self::ClickPost => '日本郵便（クリックポスト）',
        };
    }

    /** ご依頼主（店の住所と電話）が要る形か（クリックポストは申し込みの人の登録の住所を使う） */
    public function needsShipper(): bool
    {
        return $this !== self::ClickPost;
    }

    /**
     * お届けの時間帯の、各社の送り状のソフトの区分の値（ADR 0059）。出どころと確かめが要る点は README。指定なし・クリックポストは空
     */
    public function slotCode(?DeliverySlot $slot): string
    {
        if ($slot === null) {
            return '';
        }

        return match ($this) {
            // B2 クラウドの配達時間帯: 0812 午前中・1416・1618・1820・1921
            self::YamatoB2 => match ($slot) {
                DeliverySlot::Morning => '0812',
                DeliverySlot::From14To16 => '1416',
                DeliverySlot::From16To18 => '1618',
                DeliverySlot::From18To20 => '1820',
                DeliverySlot::From19To21 => '1921',
            },
            // e飛伝の配達指定時間帯: 01 午前中・14・16・18・19（時間帯の始まりの時）
            self::SagawaEhiden => match ($slot) {
                DeliverySlot::Morning => '01',
                DeliverySlot::From14To16 => '14',
                DeliverySlot::From16To18 => '16',
                DeliverySlot::From18To20 => '18',
                DeliverySlot::From19To21 => '19',
            },
            // ゆうプリR の配達時間帯: 51 午前中・53 14〜16 時・54 16〜18 時・55 18〜20 時・57 19〜21 時（いちばん確かでない。README）
            self::JapanPostYupri => match ($slot) {
                DeliverySlot::Morning => '51',
                DeliverySlot::From14To16 => '53',
                DeliverySlot::From16To18 => '54',
                DeliverySlot::From18To20 => '55',
                DeliverySlot::From19To21 => '57',
            },
            self::ClickPost => '',
        };
    }

    /**
     * @param  list<Parcel>  $parcels
     * @param  array<string, bool|string>  $settings  この App の設定（秘密でない値）
     */
    public function csv(array $parcels, Shipper $shipper, array $settings, CarbonImmutable $shipDate): AdminCsv
    {
        $file = str_replace('_', '-', $this->value).'-'.CarbonImmutable::now('Asia/Tokyo')->format('Ymd-His').'.csv';
        [$header, $row] = match ($this) {
            self::YamatoB2 => [self::B2_HEADER, fn (Parcel $p): array => self::b2($p, $shipper, $settings, $shipDate)],
            self::SagawaEhiden => [self::EHIDEN_HEADER, fn (Parcel $p): array => self::ehiden($p, $shipper, $settings, $shipDate)],
            self::JapanPostYupri => [self::YUPRI_HEADER, fn (Parcel $p): array => self::yupri($p, $shipper, $shipDate)],
            self::ClickPost => [self::CLICKPOST_HEADER, fn (Parcel $p): array => self::clickpost($p)],
        };

        return new AdminCsv($file, CsvEncoding::ShiftJis, $header, array_map($row, $parcels));
    }

    // ヤマト運輸 B2 クラウド「外部データから発行」の基本レイアウト（1 行目は項目名。取り込みの設定で読み飛ばす）
    private const array B2_HEADER = [
        'お客様管理番号', '送り状種類', 'クール区分', '伝票番号', '出荷予定日', 'お届け予定日', '配達時間帯', 'お届け先コード',
        'お届け先電話番号', 'お届け先電話番号枝番', 'お届け先郵便番号', 'お届け先住所', 'お届け先アパートマンション名', 'お届け先会社・部門１',
        'お届け先会社・部門２', 'お届け先名', 'お届け先名(ｶﾅ)', '敬称', 'ご依頼主コード', 'ご依頼主電話番号', 'ご依頼主電話番号枝番',
        'ご依頼主郵便番号', 'ご依頼主住所', 'ご依頼主アパートマンション', 'ご依頼主名', 'ご依頼主名(ｶﾅ)', '品名コード１', '品名１', '品名コード２',
        '品名２', '荷扱い１', '荷扱い２', '記事', 'ｺﾚｸﾄ代金引換額（税込)', '内消費税額等', '止置き', '営業所コード', '発行枚数',
        '個数口表示フラグ', '請求先顧客コード', '請求先分類コード', '運賃管理番号',
    ];

    /**
     * @param  array<string, bool|string>  $settings
     * @return list<string>
     */
    private static function b2(Parcel $p, Shipper $s, array $settings, CarbonImmutable $date): array
    {
        return [
            $p->reference, '0', '0', '', $date->format('Y/m/d'), $p->deliveryDate('Y/m/d'), self::YamatoB2->slotCode($p->delivery?->slot), '',
            $p->get('phone'), '', $p->zip(), $p->get('prefecture').$p->get('city').$p->get('line1'), $p->get('line2'), $p->get('company'),
            '', $p->get('name'), '', '様', '', $s->phone, '',
            $s->zip, $s->address, '', $s->name, '', '', $p->itemName(0, 25), '',
            $p->itemName(1, 25), '', '', $p->remark(), '', '', '', '', '1',
            '', Shipper::setting($settings, 'yamato_billing_code') ?? '', '', Shipper::setting($settings, 'yamato_freight_code') ?? '01',
        ];
    }

    // 佐川急便 e飛伝の出荷データの取り込みの基本の並び（1 行目は項目名）
    private const array EHIDEN_HEADER = [
        'お届け先コード取得区分', 'お届け先コード', 'お届け先電話番号', 'お届け先郵便番号', 'お届け先住所１', 'お届け先住所２', 'お届け先住所３',
        'お届け先名称１', 'お届け先名称２', 'お客様管理番号', 'お客様コード', '部署ご担当者コード取得区分', '部署ご担当者コード', '部署ご担当者名称',
        '荷送人電話番号', 'ご依頼主コード取得区分', 'ご依頼主コード', 'ご依頼主電話番号', 'ご依頼主郵便番号', 'ご依頼主住所１', 'ご依頼主住所２',
        'ご依頼主名称１', 'ご依頼主名称２', '荷姿', '品名１', '品名２', '品名３', '品名４', '品名５', '出荷個数', 'スピード指定', 'クール便指定',
        '配達日', '配達指定時間帯', '配達指定時間（時分）', '代引金額', '消費税', '決済種別', '保険金額', '指定シール１', '指定シール２', '指定シール３',
        '営業所受取', 'SRC区分', '営業所受取営業所コード', '元着区分', 'メールアドレス', 'ご不在時連絡先', '出荷日', 'お問合せ送り状No.',
    ];

    /**
     * @param  array<string, bool|string>  $settings
     * @return list<string>
     */
    private static function ehiden(Parcel $p, Shipper $s, array $settings, CarbonImmutable $date): array
    {
        return [
            '', '', $p->get('phone'), $p->zip(), $p->get('prefecture').$p->get('city'), $p->get('line1'), $p->get('line2'),
            $p->get('name'), $p->get('company'), $p->reference, Shipper::setting($settings, 'sagawa_customer_code') ?? '', '', '', '',
            '', '', '', $s->phone, $s->zip, $s->address, '',
            $s->name, '', '', $p->itemName(0, 16), $p->itemName(1, 16), $p->itemName(2, 16), $p->itemName(3, 16), $p->itemName(4, 16), '1', '', '',
            $p->deliveryDate('Ymd'), self::SagawaEhiden->slotCode($p->delivery?->slot), '', '', '', '', '', '', '', '',
            '', '', '', '', '', '', $date->format('Ymd'), '',
        ];
    }

    // 日本郵便 ゆうプリR の外部データの取り込みの並び（1 行目は項目名）
    private const array YUPRI_HEADER = [
        'お客様側管理番号', '発送予定日', '発送予定時間帯', '郵便種別', '支払元', '送り状種別', 'お届け先郵便番号', 'お届け先住所1', 'お届け先住所2',
        'お届け先住所3', 'お届け先名称1', 'お届け先名称2', 'お届け先敬称', 'お届け先電話番号', 'ご依頼主郵便番号', 'ご依頼主住所1', 'ご依頼主住所2',
        'ご依頼主住所3', 'ご依頼主名称1', 'ご依頼主名称2', 'ご依頼主電話番号', '品名', '個数', '配達希望日', '配達時間帯', '代引金額', '備考',
    ];

    /**
     * @return list<string>
     */
    private static function yupri(Parcel $p, Shipper $s, CarbonImmutable $date): array
    {
        return [
            $p->reference, $date->format('Y/m/d'), '', '', '', '', $p->zip(), $p->get('prefecture').$p->get('city'), $p->get('line1'),
            $p->get('line2'), $p->get('name'), $p->get('company'), '様', $p->get('phone'), $s->zip, $s->address, '',
            '', $s->name, '', $s->phone, $p->summary(20), '1', $p->deliveryDate('Y/m/d'), self::JapanPostYupri->slotCode($p->delivery?->slot), '', $p->remark(),
        ];
    }

    // 日本郵便 クリックポストの「まとめ申込」の並び（1 行目は項目名）。注文番号の欄は無い
    private const array CLICKPOST_HEADER = [
        'お届け先郵便番号', 'お届け先氏名', 'お届け先敬称', 'お届け先住所1行目', 'お届け先住所2行目', 'お届け先住所3行目', 'お届け先住所4行目', '内容品',
    ];

    /**
     * @return list<string>
     */
    private static function clickpost(Parcel $p): array
    {
        return [
            $p->zip(), $p->get('name'), '様', $p->get('prefecture').$p->get('city'), $p->get('line1'), $p->get('line2'), $p->get('company'), $p->summary(15),
        ];
    }
}
