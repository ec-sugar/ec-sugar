<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Api;

use App\Admin\Http\TenantActor;
use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Core\Plugin\Manifest\AppCategory;
use App\Core\Plugin\Manifest\AppManifest;
use App\Core\Plugin\Manifest\ConsentKind;
use App\Core\Plugin\Manifest\Disclosure;
use App\Core\Plugin\Manifest\PageKind;
use App\Core\Plugin\Manifest\SettingSpec;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Sections\AppSection;
use App\Core\Plugin\Services\PluginDataPurge;
use App\Core\Plugin\Services\PluginSettings;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Shop\Services\ShopSettingsService;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * 店のプラグイン（EnterTenant の後。ADR 0025）。入っているプラグインと、この店で使うかどうか。
 * 変えるのは運用の段階で、直前の再確認の後（経路の印）。入れる・外すは composer だけ。
 * App の設定（ADR 0034 の 3）も同じ段階で変える。欄はマニフェストの宣言から画面が作り、値はサーバーが宣言どおりに確かめる。
 * 秘密の項目は値を返さない（入っているかだけ）。
 * App のデータを消す（ADR 0092）のはオーナーだけで、直前の再確認の後（経路の印）。止めた店で、消す口を持つ App だけ
 */
final class TenantPluginController
{
    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly PluginStates $states,
        private readonly PluginSettings $settings,
        private readonly ShopSettingsService $shop,
        private readonly PluginDataPurge $purge,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->present());
    }

    public function update(Request $request, string $plugin): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $this->states->set($plugin, (bool) $data['enabled'], TenantActor::of($request));

        return response()->json($this->present());
    }

    /**
     * App の設定を変える。書かなかった項目はそのまま、秘密は空なら変えず、clear に書いたときだけ消す（PluginSettings）
     */
    public function settings(Request $request, string $plugin): JsonResponse
    {
        $data = $request->validate([
            'values' => ['present', 'array', 'max:'.AppManifest::MAX_SETTINGS],
            'clear' => ['sometimes', 'array', 'max:'.AppManifest::MAX_SETTINGS],
            'clear.*' => ['string', 'max:32'],
        ]);
        $this->settings->update($plugin, $data['values'], TenantActor::of($request), $data['clear'] ?? []);

        return response()->json($this->present());
    }

    /**
     * この店の App のデータを消す（ADR 0092）。断るのは PluginDataPurge（使っている・口が無い・デモの店・App が断る）。
     * 誰がどの店のどの App を消したかを中央の監査ログに残す（中身は App の名前と数だけ）
     */
    public function purge(Request $request, string $plugin, AuditLog $audit): JsonResponse
    {
        $operator = $request->user('operator');

        if (! $operator instanceof Operator) {
            throw new LogicException('ログインした運営の経路です');
        }

        $result = $this->purge->purge($plugin, TenantActor::of($request));
        $audit->record('tenant.plugin_purged', $operator, ['plugin' => $plugin, 'rows' => $result->total(), 'files' => $result->files], (string) Tenant::current()->getTenantKey(), $request->ip());

        return response()->json($this->present() + ['purged' => $result->toArray()]);
    }

    /**
     * @return array{plugins: list<array<string, mixed>>, categories: list<array{key: string, label: string, description: string}>, consent_banner: bool}
     */
    private function present(): array
    {
        $states = $this->states->all();

        return [
            // この店が同意の帯を出すか。画面で「いつ動くか」を見せるため
            'consent_banner' => $this->shop->showsConsentBanner(),
            // App の目的の言葉。画面はこの順にまとまりを並べる（言葉と並びは核が決め、画面に写さない）
            'categories' => array_map(static fn (AppCategory $c): array => ['key' => $c->value, 'label' => $c->label(), 'description' => $c->description()], AppCategory::cases()),
            'plugins' => array_map(fn (PluginDefinition $p): array => $this->presentPlugin($p, $states), $this->registry->all()),
        ];
    }

    /**
     * @param  array<string, array{enabled: bool, changed_at: CarbonImmutable|null}>  $states
     * @return array<string, mixed>
     */
    private function presentPlugin(PluginDefinition $p, array $states): array
    {
        $app = $p->app();
        $settings = $app->settings === [] ? null : $this->settings->forAdmin($p->name);

        return [
            'name' => $p->name,
            'title' => $p->title,
            'description' => $p->description,
            // 目的（マニフェストの category。書かなければ other）
            'category' => $app->category->value,
            'enabled' => $states[$p->name]['enabled'],
            'changed_at' => $states[$p->name]['changed_at']?->toIso8601String(),
            // データを消す口を持つか（ADR 0092。持つ App だけ、止めた時にオーナーへ「この App のデータを消す」を出す）
            'purgeable' => $p->purger !== null,
            // 核の口の宣言（画面で「何をするか」を見せる。値ではない）
            'app' => [
                'pages' => self::pages($app),
                // スクリプトと区画（ADR 0040）の分を合わせた同意の種類
                'consent' => array_map(static fn (ConsentKind $k): array => ['kind' => $k->value, 'label' => $k->label()], $app->allConsent()),
                // App が足す区画の名前（ページの編集の画面で足せる）
                'sections' => array_values(array_map(static fn (AppSection $s): string => $s->spec->label, $app->sections)),
                'disclosure' => array_map(static fn (Disclosure $d): array => ['company' => $d->company, 'data' => $d->data, 'purpose' => $d->purpose, 'policy' => $d->policy], $app->disclosures),
            ],
            'settings' => $settings === null ? null : [
                'schema' => array_map(static fn (SettingSpec $s): array => $s->toArray(), $app->settings),
                'values' => (object) $settings['values'],
                'secrets' => (object) $settings['secrets'],
                // 入れるべき項目がそろっているか（そろうまでショップでは動かない）
                'complete' => $settings['complete'],
                'changed_at' => $settings['changed_at']?->toIso8601String(),
            ],
        ];
    }

    /**
     * App のスクリプトを差し込む画面の種類（重ならないように）
     *
     * @return list<string>
     */
    private static function pages(AppManifest $app): array
    {
        $pages = [];

        foreach ($app->scripts as $script) {
            foreach ($script->pages as $kind) {
                $pages[$kind->value] = true;
            }
        }

        return array_values(array_filter(array_map(static fn (PageKind $k): string => $k->value, PageKind::cases()), static fn (string $k): bool => isset($pages[$k])));
    }
}
