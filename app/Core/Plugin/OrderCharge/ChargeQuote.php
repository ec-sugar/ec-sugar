<?php

declare(strict_types=1);

namespace App\Core\Plugin\OrderCharge;

use App\Core\Money\Money;
use InvalidArgumentException;

/**
 * App の料金の見積もり（ADR 0078）。注文に足す行（種類・名前・税込みの額）と、確かめの画面に出す要約（名前と値）と、
 * 決めた値の要約（digest。確かめの画面の指紋に入る。確かめの後に店が選択肢や値段を変えたら変わるように、使った設定も入れる）。
 *
 * - 行の種類は英小文字・数字・下線で、核の行の種類（送料・値引き・ポイント・オプション・代引き手数料など）は使えない。額は 0〜1,000,000 円の整数。
 *   額 0 の行も注文に残す（のし「無料」など。管理画面・納品書・ご注文の状況に何を選んだかが出る）。名前に個人情報を入れない（ご注文の状況とメールにも出る）
 * - 要約はお客さん本人の確かめの画面にだけ出す（名入れの文字を入れてよい）
 */
final readonly class ChargeQuote
{
    public const int LINES_MAX = 4;

    public const int SUMMARY_MAX = 6;

    public const int AMOUNT_MAX = 1_000_000;

    /**
     * 核と公式の App が作る行の種類（1 つの一覧。App の料金の行には使えない。OrderCharges::shownAsCharge もこの一覧で決める）。
     * 代引き手数料（cod_fee）はご注文の状況で料金の行として出すので CHARGE_KINDS にも入る。核が新しい種類を作ったらここへ足す（OrderChargeShapeTest が見張る）
     */
    public const array CORE_KINDS = ['shipping', 'discount', 'points', 'addon', 'cod_fee', 'price_agreed', 'gift_card'];

    /** 核の種類のうち、ご注文の状況とメールで料金の行として出すもの */
    public const array CHARGE_KINDS = ['cod_fee'];

    /** App が使えない種類（核の種類と、核が後で使うかもしれない言葉） */
    public const array RESERVED_KINDS = [...self::CORE_KINDS, 'fee', 'tax', 'refund', 'payment'];

    /** @var list<array{kind: string, title: string, amount: int}> */
    public array $lines;

    /** @var list<array{name: string, value: string}> */
    public array $summary;

    /**
     * App の答えをそのまま受け、形（鍵がそろう・種類と名前は文字・額は整数）を確かめてから持つ
     *
     * @param  array<mixed>  $lines  [{kind, title, amount}]
     * @param  array<mixed>  $summary  [{name, value}]
     */
    public function __construct(
        array $lines = [],
        array $summary = [],
        public string $digest = '',
    ) {
        if (! array_is_list($lines) || ! array_is_list($summary) || count($lines) > self::LINES_MAX || count($summary) > self::SUMMARY_MAX) {
            throw new InvalidArgumentException('料金の行は '.self::LINES_MAX.' 行、要約は '.self::SUMMARY_MAX.' 個までの並びです');
        }

        $kinds = [];
        $checked = [];
        foreach ($lines as $line) {
            if (! is_array($line) || array_keys($line) !== ['kind', 'title', 'amount'] || ! is_string($line['kind']) || ! is_string($line['title']) || ! is_int($line['amount'])) {
                throw new InvalidArgumentException('料金の行は kind（文字）・title（文字）・amount（整数）の 3 つだけです');
            }

            if (preg_match('/\A[a-z][a-z0-9_]{1,31}\z/', $line['kind']) !== 1 || in_array($line['kind'], self::RESERVED_KINDS, true) || isset($kinds[$line['kind']])) {
                throw new InvalidArgumentException('料金の行の種類が違います（核の種類と重ねず、1 つの見積もりで重ねない）');
            }

            if (! ChargeField::fits($line['title'], 100) || $line['amount'] < 0 || $line['amount'] > self::AMOUNT_MAX) {
                throw new InvalidArgumentException('料金の行の名前か額の形が違います');
            }

            $kinds[$line['kind']] = true;
            $checked[] = ['kind' => $line['kind'], 'title' => $line['title'], 'amount' => $line['amount']];
        }

        $rows = [];
        foreach ($summary as $row) {
            if (! is_array($row) || array_keys($row) !== ['name', 'value'] || ! ChargeField::fits($row['name'], 40) || ! ChargeField::fits($row['value'], 200)) {
                throw new InvalidArgumentException('料金の要約は name と value の 2 つの文字だけです');
            }

            $rows[] = ['name' => (string) $row['name'], 'value' => (string) $row['value']];
        }

        if (preg_match('/\A[A-Za-z0-9:_.-]{0,128}\z/', $digest) !== 1) {
            throw new InvalidArgumentException('料金の要約（digest）の形が違います');
        }

        $this->lines = $checked;
        $this->summary = $rows;
    }

    public static function none(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function total(): int
    {
        return array_sum(array_column($this->lines, 'amount'));
    }

    /**
     * 確かめの画面の指紋に入れる形（行の種類・額・名前と、App の要約。何も選んでいなければ空）
     */
    public function fingerprint(): string
    {
        if ($this->lines === [] && $this->digest === '') {
            return '';
        }

        return hash('sha256', implode("\n", array_map(static fn (array $l): string => $l['kind'].':'.$l['amount'].':'.$l['title'], $this->lines))."\n".$this->digest);
    }

    /**
     * 行の額の見せ方（「¥330」。0 円は「無料」）
     */
    public static function amountLabel(int $amount): string
    {
        return $amount === 0 ? '無料' : Money::format($amount);
    }
}
