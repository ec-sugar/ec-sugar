<?php

declare(strict_types=1);

namespace App\Storefront\Apps;

use App\Core\IsolatedCopy;
use App\Core\Plugin\Manifest\ConsentKind;
use App\Core\Plugin\Manifest\Disclosure;
use App\Core\Plugin\Manifest\PageKind;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\AgentSettings;
use App\Core\Shop\Services\ShopSettingsService;
use Illuminate\Http\Request;

/**
 * ショップの画面に、どの App のスクリプトを差し込むかを決める（ADR 0034）。決めるのはサーバーで、次のすべてを満たす App だけ:
 *   - 店がその App を使っている（入っていない・止めた App は差し込まない）
 *   - 入れるべき設定がそろっている
 *   - 画面の種類が App の宣言に入っている（個人情報の画面は、App が何と言っても差し込まない）
 *   - 同意の帯を出す店（店の設定）では、App の同意の種類にお客さんが全部同意している（同意の Cookie。決めていなければ差し込まない）。
 *     既定の「帯を出さない」店では、帯も同意の Cookie の確かめも無く差し込む（外部送信の公表だけ。日本の外部送信規律が求めるのは公表）
 * 店の文脈の中だけで使う。
 *
 * App の区画（ADR 0040）: 同意の帯には、同意の種類を書いた区画を持つ App（使っていて設定がそろったもの）の種類も入れる（動画・地図は種類が無いので聞かない）。
 * 枠の宛先（frame-src）は、その応答で出した App の区画の分だけを呼ぶ側（StorefrontView）が渡し、ふつうの画面と購入の完了の画面の CSP に足す
 */
final class StorefrontApps
{
    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly PluginStates $states,
        private readonly PluginSettings $settings,
        private readonly ShopSettingsService $shop,
        private readonly AgentSettings $agents,
    ) {}

    /**
     * この店が同意の帯を出すか（既定は出さない）。出さない店では、タグも区画も同意を待たない
     */
    public function asksConsent(): bool
    {
        return $this->shop->showsConsentBanner();
    }

    /**
     * @param  list<string>  $frames  この応答で出す App の区画が枠に読み込む宛先（ADR 0040。CSP の frame-src に足す）
     */
    public function forPage(Request $request, PageKind $kind, ?PurchaseData $purchase = null, array $frames = []): AppsView
    {
        $used = $this->used();
        $active = $this->active($used);
        $asks = $this->asksConsent();
        // 帯を出さない店では、聞く種類は無い（帯も「Cookie の設定」のリンクも出さない）
        $needed = $asks ? $this->consentKinds($this->consenting($used, $active)) : [];
        $disclosureUrl = $this->disclosures($used) === [] ? null : route('storefront.disclosure', absolute: false);
        $consentUrl = $needed === [] ? null : route('storefront.consent', absolute: false);

        if ($kind === PageKind::Private) {
            // 個人情報の画面: スクリプトも同意の帯も出さない。CSP も足さない（フッターのリンクだけ）
            return new AppsView($kind, disclosureUrl: $disclosureUrl, consentUrl: $consentUrl);
        }

        // 帯を出さない店では同意の Cookie を読まない（前に帯を出していた頃の「同意しない」も見ない。店が帯を出さないと決めたため）
        $decisions = $asks ? ConsentCookie::read($request) : [];
        $tags = [];
        $csp = [];

        // 隔離した写し（ADR 0082）では、タグの App を差し込まない（写しを開いた人のブラウザから、本番の解析・広告の計測へ送らない）
        foreach (IsolatedCopy::enabled() ? [] : $active as ['plugin' => $plugin, 'values' => $values]) {
            $app = $plugin->app();

            foreach ($asks ? $app->consent : [] as $consent) {
                if (($decisions[$consent->value] ?? false) !== true) {
                    continue 2;
                }
            }

            $injected = false;

            foreach ($app->scripts as $script) {
                if (! $script->on($kind)) {
                    continue;
                }

                $data = ['data-sugar-app' => $plugin->name, 'data-sugar-page' => $kind->value];

                foreach ($values as $key => $value) {
                    $data['data-setting-'.str_replace('_', '-', $key)] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
                }

                if ($kind === PageKind::Purchase && $purchase !== null) {
                    $data += $purchase->attributes();
                }

                $src = route('storefront.apps.asset', ['plugin' => $plugin->name, 'file' => $script->file], absolute: false).'?v='.self::version($script->path);
                $tags[] = new AppTag($plugin->name, $src, $data);
                $injected = true;
            }

            if ($injected) {
                foreach ($app->csp as $directive => $sources) {
                    $csp[$directive] = array_values(array_unique([...($csp[$directive] ?? []), ...$sources]));
                }
            }
        }

        // App の区画の枠の宛先（区画を出したときだけ、宣言の宛先だけ。動画・地図は誰にでも最初から枠を描く）
        if ($frames !== []) {
            $csp['frame-src'] = array_values(array_unique([...($csp['frame-src'] ?? []), ...$frames]));
        }

        $banner = $needed === [] ? null : new ConsentBanner(
            $needed,
            array_diff(array_map(static fn (ConsentKind $k): string => $k->value, $needed), array_keys($decisions)) !== [],
            self::returnPath($request),
        );

        return new AppsView($kind, $tags, $csp, $banner, $disclosureUrl, $consentUrl);
    }

    /**
     * 公表のページに出す行（その店で使っている App の分だけ。名前の順）
     *
     * @return list<array{title: string, disclosure: Disclosure, agent?: true}>
     */
    public function disclosureRows(): array
    {
        return $this->disclosures($this->used());
    }

    /**
     * この店で同意が要る種類（使っていて、設定のそろった App の分）と、その App の名前。帯を出さない店では無い
     *
     * @return list<array{kind: ConsentKind, apps: list<string>}>
     */
    public function consentChoices(): array
    {
        if (! $this->asksConsent()) {
            return [];
        }

        $used = $this->used();
        $active = $this->consenting($used, $this->active($used));
        $out = [];

        foreach ($this->consentKinds($active) as $kind) {
            $apps = array_values(array_map(static fn (PluginDefinition $p): string => $p->title, array_filter($active, static fn (PluginDefinition $p): bool => in_array($kind, $p->app()->allConsent(), true))));
            $out[] = ['kind' => $kind, 'apps' => $apps];
        }

        return $out;
    }

    /**
     * 同じオリジンの、今の画面の道筋（決めた後に戻る先）。おかしなものはトップに
     */
    public static function returnPath(Request $request): string
    {
        $path = $request->getRequestUri();

        return self::safePath($path) ?? '/';
    }

    public static function safePath(mixed $path): ?string
    {
        return is_string($path) && strlen($path) <= 500 && preg_match('~\A/(?![/\\\\])[^\s\\\\]*\z~', $path) === 1 ? $path : null;
    }

    /**
     * 店が使っている App のうち、核の口を使うもの（スクリプトか公表のあるもの。区画で外から埋め込む App は公表を必ず持つ）
     *
     * @return list<PluginDefinition>
     */
    private function used(): array
    {
        $states = $this->states->all();

        return array_values(array_filter($this->registry->all(), static fn (PluginDefinition $p): bool => ($states[$p->name]['enabled'] ?? false)
            && ($p->app()->scripts !== [] || $p->app()->disclosures !== [])));
    }

    /**
     * 使っていて、スクリプトがあり、入れるべき設定がそろった App と、ショップに渡す値
     *
     * @param  list<PluginDefinition>  $used
     * @return list<array{plugin: PluginDefinition, values: array<string, bool|string>}>
     */
    private function active(array $used): array
    {
        $stored = $this->settings->storedValues();
        $out = [];

        foreach ($used as $plugin) {
            if ($plugin->app()->scripts === []) {
                continue;
            }

            $values = $this->settings->publicValues($plugin->name, $stored[$plugin->name] ?? []);

            if ($values !== null) {
                $out[] = ['plugin' => $plugin, 'values' => $values];
            }
        }

        return $out;
    }

    /**
     * 同意を聞く App: 差し込める App（スクリプト）と、使っていて設定のそろった区画の App（ADR 0040）
     *
     * @param  list<PluginDefinition>  $used
     * @param  list<array{plugin: PluginDefinition, values: array<string, bool|string>}>  $active
     * @return list<PluginDefinition>
     */
    private function consenting(array $used, array $active): array
    {
        $out = [];

        foreach (array_column($active, 'plugin') as $plugin) {
            $out[$plugin->name] = $plugin;
        }

        foreach ($used as $plugin) {
            if ($plugin->app()->sections !== [] && ! isset($out[$plugin->name]) && $this->settings->publicValues($plugin->name) !== null) {
                $out[$plugin->name] = $plugin;
            }
        }

        return array_values($out);
    }

    /**
     * @param  list<PluginDefinition>  $plugins
     * @return list<ConsentKind> 決まった順（解析・広告）
     */
    private function consentKinds(array $plugins): array
    {
        $kinds = [];

        foreach ($plugins as $plugin) {
            // スクリプトの同意は差し込める App の分、区画の同意は区画の App の分（allConsent は両方を合わせたもの）
            foreach ($plugin->app()->allConsent() as $kind) {
                $kinds[$kind->value] = true;
            }
        }

        return array_values(array_filter(ConsentKind::cases(), static fn (ConsentKind $k): bool => isset($kinds[$k->value])));
    }

    /**
     * @param  list<PluginDefinition>  $used
     * @return list<array{title: string, disclosure: Disclosure, agent?: true}>
     */
    private function disclosures(array $used): array
    {
        $rows = [];

        foreach ($used as $plugin) {
            foreach ($plugin->app()->disclosures as $disclosure) {
                $rows[] = ['title' => $plugin->title, 'disclosure' => $disclosure];
            }
        }

        // 店が AI エージェントにお客さんの情報を渡す（ADR 0101）: ショップを見た時ではなく、店の人がエージェントに頼んだ時に送られる。送信先の案内は店のプライバシーポリシー
        if ($this->agents->sharesCustomerData()) {
            $rows[] = ['title' => 'AI エージェント（ショップの業務の補助）', 'agent' => true, 'disclosure' => new Disclosure(
                'ショップが利用する AI エージェントのサービスの事業者',
                'お客さまの名前・メールアドレス・電話番号・住所・ご注文の内容と履歴',
                'ショップの担当者が AI エージェント（AI のアシスタント）を使って、ご注文の確認・発送・お問い合わせへの対応などの業務を行うため',
                route('storefront.privacy'),
            )];
        }

        return $rows;
    }

    /**
     * ファイルの中身から作るバージョンの印（変われば URL が変わり、長く持たせたキャッシュを使い直さない）
     */
    private static function version(string $path): string
    {
        static $cache = [];
        $key = $path.'|'.(string) @filemtime($path);

        return $cache[$key] ??= substr((string) @hash_file('sha256', $path), 0, 12);
    }
}
