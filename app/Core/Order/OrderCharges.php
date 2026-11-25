<?php

declare(strict_types=1);

namespace App\Core\Order;

use App\Core\Plugin\Contracts\OrderChargeHandler;
use App\Core\Plugin\OrderCharge\ChargeCart;
use App\Core\Plugin\OrderCharge\ChargeField;
use App\Core\Plugin\OrderCharge\ChargeForm;
use App\Core\Plugin\OrderCharge\ChargeQuote;
use App\Core\Plugin\OrderCharge\ChargeRejected;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;
use Throwable;

/**
 * App が注文に足す料金（ADR 0078）の核の側。料金の口（OrderChargeHandler）を持つ App をこの店で使っている時だけ、注文の入力に App の欄を出す。
 *
 * - 値は欄の鍵 => 文字だけを受け、App の今の欄に無い鍵は捨てる。額は App が店の設定で決め、核は形（種類・名前・額の範囲）を ChargeQuote で確かめる
 * - 注文の入力・確かめの画面・確定（錠の中）が同じ見積もりを使う（確かめの画面の指紋に入る）。確かめの後に店が値段・選択肢を変えたら確定しない
 * - App が落ちた: 欄を出さない（入力の画面）。選んだ値があれば断る（選ばずに進んでもらう）。注文の後の備考は空（画面とメールは描く）
 * - 使うのをやめた店でも、注文の行（調整の行）は残る（何を選んだかは行の名前で分かる）
 */
final class OrderCharges
{
    /** App が落ちた・使うのをやめた時に、値を選んでいた入力へ出す理由（欄の鍵の代わりに _form で） */
    public const string UNAVAILABLE = 'この項目は現在お選びいただけません。選択せずにお進みください。';

    public function __construct(
        private readonly PluginRegistry $plugins,
        private readonly PluginStates $states,
    ) {}

    /**
     * この店で使っている料金の口（無ければ null）
     */
    public function handler(): ?OrderChargeHandler
    {
        $plugin = $this->plugins->chargePlugin();

        return $plugin !== null && $this->states->enabled($plugin->name) ? $plugin->orderCharges : null;
    }

    /**
     * 注文の入力に出す欄（使っていない・このカートで選べるものが無い・App が落ちたら null）
     */
    public function form(ChargeCart $cart): ?ChargeForm
    {
        try {
            return $cart->items === [] ? null : $this->handler()?->form($cart);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * 入れた値を見積もる。何も選んでいなければ空の見積もり（App に聞かない）。
     * 使っていない店で値が送られた・App が落ちたら ChargeRejected（_form に理由）
     *
     * @param  array<string, string>  $values  注文の入力の形（CheckoutInput がそろえた鍵 => 文字）
     *
     * @throws ChargeRejected
     */
    public function quote(ChargeCart $cart, array $values): ChargeQuote
    {
        $values = self::chosen($values);

        if ($values === []) {
            return ChargeQuote::none();
        }

        $handler = $this->handler() ?? throw new ChargeRejected(['_form' => self::UNAVAILABLE]);

        try {
            return $handler->quote($cart, $values);
        } catch (ChargeRejected $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw new ChargeRejected(['_form' => self::UNAVAILABLE]);
        }
    }

    /**
     * 注文の確定の中で呼ぶ（連鎖のロックの後。行を足した注文を開いた後）。選んだ値を App に残させる。何も選んでいなければ何もしない
     *
     * @param  array<string, string>  $values
     */
    public function record(string $transactionId, ChargeCart $cart, array $values, string $actor): void
    {
        $values = self::chosen($values);

        if ($values !== []) {
            ($this->handler() ?? throw new ChargeRejected(['_form' => self::UNAVAILABLE]))->placed($transactionId, $cart, $values, $actor);
        }
    }

    /**
     * 注文の備考（納品書・配送の CSV・管理画面の個人情報の入口）。$private が false なら個人情報を入れない。
     * App を入れていない・落ちた・形が違えば空（書類は描く）。使うのをやめた店でも、前の注文の備考は出す
     *
     * @return list<array{name: string, value: string}>
     */
    public function notes(string $transactionId, bool $private): array
    {
        $handler = $this->plugins->chargePlugin()?->orderCharges;

        if ($handler === null) {
            return [];
        }

        try {
            $notes = $handler->notes($transactionId, $private);

            foreach ($notes as $note) {
                if (! ChargeField::fits($note['name'], 40) || ! ChargeField::fits($note['value'], 200)) {
                    return [];
                }
            }

            return array_slice(array_map(static fn (array $n): array => ['name' => $n['name'], 'value' => $n['value']], $notes), 0, ChargeQuote::SUMMARY_MAX);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * 備考を 1 行の文に（「ラッピング: リボン／のし: 御祝・外のし」。無ければ空）。配送の CSV の備考の欄に
     *
     * @param  list<array{name: string, value: string}>  $notes
     */
    public static function line(array $notes): string
    {
        return implode('／', array_map(static fn (array $n): string => $n['name'].': '.$n['value'], $notes));
    }

    /**
     * ご注文の状況とメールで「料金の行」（代引き手数料・App の料金）として出す行の種類か
     */
    public static function shownAsCharge(?string $kind): bool
    {
        // 核の種類（ChargeQuote::CORE_KINDS。送料・値引き・ポイント・オプション・定期便の約束の価格は別に出す）のうち料金のもの（代引き手数料）と、App の種類
        return $kind !== null && (in_array($kind, ChargeQuote::CHARGE_KINDS, true) || ! in_array($kind, ChargeQuote::CORE_KINDS, true));
    }

    /**
     * 選んだ値（空の値は「選ばない」なので除く）
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private static function chosen(array $values): array
    {
        return array_filter($values, static fn (string $v): bool => trim($v) !== '');
    }
}
