<?php

declare(strict_types=1);

namespace EcSugar\PluginOrderPrint;

use App\Core\Transaction\Models\Transaction;
use App\Http\PrintPage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 領収書の印刷用のページ（店の人とお客さんで同じ形）。GET は宛名の欄だけ、POST（「領収書を出す」）で回数を数えてから領収書を描く。
 * 店の人は但し書きも 1 枚ごとに変えられ、宛名の最初の値は注文者の名前。お客さんの宛名の最初の値は空
 * （ご注文の画面に名前を出さない決まりと同じ。打ち間違えたメールの持ち主に、他人の名前を見せない）
 */
final class ReceiptPage
{
    public function __construct(private readonly ReceiptIssues $issues) {}

    /**
     * @param  string  $action  フォームの送信先（今の URL。署名つきのリンクなら署名ごと）
     * @param  callable(string): string  $logoUrl  ロゴの写真の ID から、このホストで読める URL を作る
     */
    public function render(Request $request, Transaction $transaction, bool $staff, string $actor, string $action, callable $logoUrl): Response
    {
        $nonce = PrintPage::nonce($request);

        if (! $this->issues->issuable($transaction)) {
            return self::refused($nonce, (new ReceiptRefused)->getMessage(), 409);
        }

        $issuer = Issuer::current();
        $sheet = OrderSheet::of($transaction, $staff);
        $to = $staff ? ($sheet->customerName ?? '') : '';
        $proviso = $issuer->proviso;
        $error = null;
        $number = null;

        if ($request->isMethod('POST')) {
            $to = ReceiptInput::line($request->input('to'));
            // お客さんは但し書きを変えられない（送られてきても読まない）
            $proviso = $staff ? ReceiptInput::line($request->input('proviso')) : $issuer->proviso;

            if ($to === null || $proviso === null || $proviso === '') {
                $error = '宛名と但し書きは、改行なしの '.ReceiptInput::MAX.' 文字以内で入れてください（但し書きは空にできません）。';
            } else {
                try {
                    $number = $this->issues->issue($transaction, $actor);
                } catch (ReceiptRefused $refused) {
                    return self::refused($nonce, $refused->getMessage(), 409);
                }
            }
        }

        return response()->view('order-print::receipt', [
            'nonce' => $nonce,
            'title' => '領収書 '.$sheet->number,
            'staff' => $staff,
            'action' => $action,
            // 断った入力は、入れたまま欄に戻す（文字でなければ空）
            'to' => $to ?? self::raw($request, 'to'),
            'proviso' => $proviso ?? self::raw($request, 'proviso'),
            'error' => $error,
            'issuedBefore' => $this->issues->count($transaction) - ($number === null ? 0 : 1),
            'number' => $number,
            'sheet' => $sheet,
            'issuer' => $issuer,
            'logoUrl' => $issuer->logoMediaId === null ? null : $logoUrl($issuer->logoMediaId),
            'issuedOn' => OrderSheet::today(),
        ], $error === null ? 200 : 422);
    }

    private static function raw(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) && mb_check_encoding($value, 'UTF-8') ? mb_substr($value, 0, 200) : '';
    }

    public static function refused(string $nonce, string $message, int $status): Response
    {
        return response()->view('order-print::refused', ['nonce' => $nonce, 'title' => '領収書', 'message' => $message], $status);
    }
}
