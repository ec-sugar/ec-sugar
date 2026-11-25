<?php

declare(strict_types=1);

namespace App\Storefront\Http\Controllers;

use App\Core\Plugin\Manifest\ConsentKind;
use App\Storefront\Apps\ConsentCookie;
use App\Storefront\Apps\StorefrontApps;
use App\Storefront\View\StorefrontView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * 核が描くショップの画面（ADR 0034 の 4・5）: 外部送信に関する公表と、同意（Cookie の設定）。テーマの外枠の中に、核の画面を描く。
 * 同意の帯を出さない店（既定）では、同意の画面は「選ぶものは無い」と出し、同意の受付は何も変えない（聞く種類が無いため）。
 * どちらも個人情報の画面と同じ扱いで、App のスクリプトは差し込まない
 */
final class AppPageController
{
    public function __construct(
        private readonly StorefrontView $view,
        private readonly StorefrontApps $apps,
    ) {}

    public function disclosure(): Response
    {
        // 冒頭の文は、同意の帯を出す店と出さない店（既定）で変える
        return $this->view->renderCore('disclosure', ['rows' => $this->apps->disclosureRows(), 'asksConsent' => $this->apps->asksConsent()]);
    }

    public function consent(Request $request): Response
    {
        return $this->view->renderCore('consent', [
            'choices' => $this->apps->consentChoices(),
            'decisions' => ConsentCookie::read($request),
            'returnPath' => StorefrontApps::safePath($request->query('return')) ?? '/',
        ]);
    }

    /**
     * 同意を決める（帯の「同意する」「同意しない」、設定の画面の「選んで保存する」）。決めるのは、今この店で同意が要る種類だけ
     * （後で別の種類の App を使い始めたら、その種類はもう一度聞く）。前に決めたほかの種類はそのまま残す
     */
    public function updateConsent(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['all', 'none', 'choose'])],
            'grant' => ['sometimes', 'array', 'max:'.count(ConsentKind::cases())],
            'grant.*' => [Rule::in(array_column(ConsentKind::cases(), 'value'))],
            'return' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $grant = is_array($data['grant'] ?? null) ? $data['grant'] : [];
        $decisions = ConsentCookie::read($request);

        foreach ($this->apps->consentChoices() as ['kind' => $kind]) {
            $decisions[$kind->value] = match ($data['decision']) {
                'all' => true,
                'none' => false,
                default => in_array($kind->value, $grant, true),
            };
        }

        // 戻る先は同じオリジンの道筋だけ（ほかのサイトへ移す道にしない）
        $return = StorefrontApps::safePath($data['return'] ?? null) ?? '/';

        return redirect()->to($return, 303)->withCookie(ConsentCookie::make($request, $decisions));
    }
}
