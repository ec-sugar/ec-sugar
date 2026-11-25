<?php

declare(strict_types=1);

namespace EcSugar\PluginAgeCheck\Http;

use App\Core\Plugin\AgeCheck\AgeRule;
use App\Storefront\View\StorefrontView;
use EcSugar\PluginAgeCheck\AgeCheckRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * 年齢の門（ADR 0077）: 年齢の確かめが要る商品の画面をはじめて開いた人に「20 歳以上ですか」を聞く画面。店の設定で使う時だけ（核の商品の画面がここへ送る）。
 * 「はい」で答えを Cookie にだけ置いて商品の画面へ戻す（記録は残さない）。「いいえ」はお求めいただけない旨を出す。
 * これはお客さんの申告で、売ってよいかは注文の時に生年月日と同意で確かめる（核の AgeChecks）。戻り先は同じショップの中の道筋だけ
 */
final class AgeGateController
{
    /** 答えを覚えておく日数 */
    private const int REMEMBER_DAYS = 30;

    public function __construct(private readonly StorefrontView $view) {}

    public function show(Request $request): Response
    {
        [$age, $return] = self::target($request);

        return $this->view->renderApp('age-check::gate', ['age' => $age, 'return' => $return])->header('X-Robots-Tag', StorefrontView::NO_INDEX);
    }

    public function answer(Request $request): RedirectResponse|Response
    {
        [$age, $return] = self::target($request);

        if ($request->input('answer') !== 'yes') {
            return $this->view->renderApp('age-check::refused', ['age' => $age])->header('X-Robots-Tag', StorefrontView::NO_INDEX);
        }

        return redirect($return)->withCookie(cookie(AgeCheckRules::GATE_COOKIE, (string) $age, self::REMEMBER_DAYS * 24 * 60, httpOnly: true, sameSite: 'lax'));
    }

    /**
     * 聞く年齢（選べる年齢でなければ既定）と戻り先（同じショップの中の道筋だけ。ほかはトップ）
     *
     * @return array{0: int, 1: string}
     */
    private static function target(Request $request): array
    {
        $age = $request->input('age');
        $age = is_string($age) && ctype_digit($age) && AgeRule::isAge((int) $age) ? (int) $age : AgeRule::DEFAULT_AGE;
        $return = $request->input('return');
        $return = is_string($return) && preg_match('~\A/[A-Za-z0-9/_\-]{0,200}\z~', $return) === 1 && ! str_starts_with($return, '//') ? $return : '/';

        return [$age, $return];
    }
}
