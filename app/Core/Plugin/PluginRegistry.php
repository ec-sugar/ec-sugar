<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Core\Plugin\Manifest\AppManifest;
use App\Core\Plugin\Manifest\InvalidAppManifestException;
use App\Core\Plugin\Manifest\PackageManifest;
use App\Core\Plugin\Sections\AppSection;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use JsonException;

/**
 * 入っているプラグインの登録簿（ADR 0025）。プラグイン API のバージョンが合わないプラグインは受け付けない（更新でプラグインが壊れたまま動かさない）
 */
final class PluginRegistry
{
    // 核がプラグインに約束していること（定義の形・拡張点）のバージョン。変えるときは ADR と一緒に上げる。
    // 核の口（ADR 0034）は足しただけで、今までのプラグインはそのまま動くので 1 のまま（宣言しない App には何も起きない）
    public const int API_VERSION = 1;

    /** composer.json の大きさの上限（バイト） */
    private const int MAX_MANIFEST_BYTES = 65536;

    /** @var array<string, PluginDefinition> */
    private array $plugins = [];

    public function register(PluginDefinition $plugin): void
    {
        if (preg_match('/\A[a-z][a-z0-9-]{1,31}\z/', $plugin->name) !== 1) {
            throw new InvalidArgumentException("プラグインの名前が不正です: {$plugin->name}");
        }

        if ($plugin->pluginApi !== self::API_VERSION) {
            throw new InvalidArgumentException("プラグイン {$plugin->name} はプラグイン API {$plugin->pluginApi} 用です（核は ".self::API_VERSION.'）');
        }

        if (isset($this->plugins[$plugin->name])) {
            throw new InvalidArgumentException("プラグインが二重に登録されています: {$plugin->name}");
        }

        // 贈り物の口（ADR 0055）は 1 つの App だけ（注文の入力の「贈り物として送る」を 2 つ並べない）
        if ($plugin->gift !== null && $this->giftPlugin() !== null) {
            throw new InvalidArgumentException("プラグイン {$plugin->name}: 贈り物の口は {$this->giftPlugin()->name} が持っています");
        }

        // 注文の入力の「お知らせのメールを受け取る」も 1 つの App だけ（同じ切り替えを 2 つ並べない）
        if ($plugin->checkoutOptIn !== null && $this->optInPlugin() !== null) {
            throw new InvalidArgumentException("プラグイン {$plugin->name}: 注文の入力のお知らせの口は {$this->optInPlugin()->name} が持っています");
        }

        // 検索に載せるショップのページの頭（ADR 0072）は、核の経路と自由なページの予約語・ほかの App の頭と重ならない 1 つの言葉
        if ($plugin->shopPages !== null) {
            $prefix = $plugin->shopPages->prefix();

            if (preg_match('/\A[a-z][a-z0-9]*(-[a-z0-9]+)*\z/', $prefix) !== 1 || strlen($prefix) > 32 || in_array($prefix, PluginRoutes::reservedPrefixes(), true)) {
                throw new InvalidArgumentException("プラグイン {$plugin->name}: ショップのページの頭 {$prefix} は使えません（英小文字・数字・ハイフンで、核の経路と重ならない言葉）");
            }

            foreach ($this->plugins as $other) {
                if ($other->shopPages?->prefix() === $prefix) {
                    throw new InvalidArgumentException("プラグイン {$plugin->name}: ショップのページの頭 {$prefix} は {$other->name} が使っています");
                }
            }
        }

        // 予約販売の口（ADR 0073）も 1 つの App だけ（発売日と予約の数の決まりを 2 つ持たない）
        if ($plugin->preorder !== null && $this->preorderPlugin() !== null) {
            throw new InvalidArgumentException("プラグイン {$plugin->name}: 予約販売の口は {$this->preorderPlugin()->name} が持っています");
        }

        // 年齢の確かめの口（ADR 0077）も 1 つの App だけ（商品の年齢と会員が確かめた年齢の決まりを 2 つ持たない）
        if ($plugin->ageCheck !== null && $this->ageCheckPlugin() !== null) {
            throw new InvalidArgumentException("プラグイン {$plugin->name}: 年齢の確認の口は {$this->ageCheckPlugin()->name} が持っています");
        }

        // 注文に料金の行を足す口（ADR 0078）も 1 つの App だけ（注文の入力の欄と行の種類を 2 つの App で取り合わない）
        if ($plugin->orderCharges !== null && $this->chargePlugin() !== null) {
            throw new InvalidArgumentException("プラグイン {$plugin->name}: 注文の料金の口は {$this->chargePlugin()->name} が持っています");
        }

        // 会員の特典の口（ADR 0079）も 1 つの App だけ（会員価格と見せない商品の決まりを 2 つ持たない）
        if ($plugin->memberBenefits !== null && $this->memberBenefitsPlugin() !== null) {
            throw new InvalidArgumentException("プラグイン {$plugin->name}: 会員の特典の口は {$this->memberBenefitsPlugin()->name} が持っています");
        }

        // ショップの一覧の並びと絞り込みの口も 1 つの App だけ（一覧に並べ替えと絞り込みを 2 組並べない）
        if ($plugin->catalogBrowse !== null && $this->catalogBrowsePlugin() !== null) {
            throw new InvalidArgumentException("プラグイン {$plugin->name}: 一覧の並びと絞り込みの口は {$this->catalogBrowsePlugin()->name} が持っています");
        }

        $this->plugins[$plugin->name] = $plugin->manifest === null ? $plugin->withApp(AppManifest::none()) : $this->withManifest($plugin, $plugin->manifest);

        // プラグインの表の移行も、店の DB の移行（tenants:migrate と店を作る時）に流す。使わない店でも表はあり、空のまま（ADR 0025 の結果）
        if ($plugin->tenantMigrations !== null) {
            $path = realpath($plugin->tenantMigrations);

            if ($path === false || ! is_dir($path)) {
                throw new InvalidArgumentException("プラグイン {$plugin->name}: 移行のフォルダがありません");
            }

            $paths = (array) config('tenancy.migration_parameters.--path', []);

            if (! in_array($path, $paths, true)) {
                config(['tenancy.migration_parameters.--path' => [...$paths, $path]]);
            }
        }
    }

    /**
     * composer.json の extra.ec-sugar を読み、核の口の宣言（ADR 0034）を確かめて入れる。形が違えば登録しない（アプリが起動しない）。
     * App が $app を自分で渡しても使わない（確かめていない宣言を通さない）
     */
    private function withManifest(PluginDefinition $plugin, string $path): PluginDefinition
    {
        $size = is_file($path) ? filesize($path) : false;

        if ($size === false || $size > self::MAX_MANIFEST_BYTES) {
            throw new InvalidArgumentException("プラグイン {$plugin->name}: composer.json が無いか、大きすぎます");
        }

        try {
            $package = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException("プラグイン {$plugin->name}: composer.json を読めません（{$e->getMessage()}）", previous: $e);
        }

        // 新しいキー ec-sugar が無ければ旧名のキー sugar-ec を読む（両方あれば新しい方だけ）
        $key = PackageManifest::key($package) ?? PackageManifest::KEY;
        $manifest = PackageManifest::of($package);

        if (! is_array($manifest) || ($manifest['type'] ?? null) !== 'plugin' || ($manifest['name'] ?? null) !== $plugin->name || ($manifest['plugin_api'] ?? null) !== $plugin->pluginApi) {
            throw new InvalidArgumentException("プラグイン {$plugin->name}: composer.json の extra.{$key} の type・name・plugin_api が登録と違います");
        }

        try {
            $app = AppManifest::fromArray($manifest, dirname($path), "extra.{$key}");
        } catch (InvalidAppManifestException $e) {
            throw new InvalidArgumentException("プラグイン {$plugin->name}: composer.json の extra.{$key}.{$e->getMessage()}", previous: $e);
        }

        return $plugin->withApp($app->sections === [] ? $app : $app->withSections($this->prepareSections($plugin, $app, dirname($path))));
    }

    /**
     * App の区画（ADR 0040）に App の名前と係を入れる。外から埋め込む区画の係が無い・ほかの App と種類の名前が重なる・
     * 描く Blade の場所（パッケージの resources/views）が無いときは登録しない。Blade の名前の場所（app-{名前}）は核が付ける
     *
     * @return array<string, AppSection>
     */
    private function prepareSections(PluginDefinition $plugin, AppManifest $app, string $root): array
    {
        $out = [];

        foreach ($app->sections as $type => $section) {
            if ($section->embed !== null && $plugin->sectionHandler === null) {
                throw new InvalidArgumentException("プラグイン {$plugin->name}: 外から埋め込むセクション {$type} には、セクションの係（sectionHandler）を渡す");
            }

            foreach ($this->plugins as $other) {
                if (isset($other->app()->sections[$type])) {
                    throw new InvalidArgumentException("プラグイン {$plugin->name}: セクションの種類 {$type} は {$other->name} と重なっています");
                }
            }

            $out[$type] = $section->withPlugin($plugin->title, $plugin->sectionHandler);
        }

        $views = realpath($root.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views');

        if ($views === false || ! is_dir($views)) {
            throw new InvalidArgumentException("プラグイン {$plugin->name}: セクションを描く Blade の場所（resources/views）がありません");
        }

        // Laravel が起きている時だけ（単体のテストで登録簿だけを使うときは付けない）
        if (app()->bound('view')) {
            View::addNamespace(AppSection::viewNamespaceOf($plugin->name), $views);
        }

        return $out;
    }

    /**
     * 入っている App の区画（種類 => 宣言。App の名前の順、App の中は宣言の順）
     *
     * @return array<string, AppSection>
     */
    public function sections(): array
    {
        $out = [];

        foreach ($this->all() as $plugin) {
            $out += $plugin->app()->sections;
        }

        return $out;
    }

    /**
     * 贈り物の口（ADR 0055）を持つ App（入っていなければ null）
     */
    public function giftPlugin(): ?PluginDefinition
    {
        foreach ($this->plugins as $plugin) {
            if ($plugin->gift !== null) {
                return $plugin;
            }
        }

        return null;
    }

    /**
     * 注文の入力の「お知らせのメールを受け取る」の口を持つ App（入っていなければ null）
     */
    public function optInPlugin(): ?PluginDefinition
    {
        foreach ($this->plugins as $plugin) {
            if ($plugin->checkoutOptIn !== null) {
                return $plugin;
            }
        }

        return null;
    }

    /**
     * 予約販売の口（ADR 0073）を持つ App（入っていなければ null）
     */
    public function preorderPlugin(): ?PluginDefinition
    {
        foreach ($this->plugins as $plugin) {
            if ($plugin->preorder !== null) {
                return $plugin;
            }
        }

        return null;
    }

    /**
     * 年齢の確かめの口（ADR 0077）を持つ App（入っていなければ null）
     */
    public function ageCheckPlugin(): ?PluginDefinition
    {
        foreach ($this->plugins as $plugin) {
            if ($plugin->ageCheck !== null) {
                return $plugin;
            }
        }

        return null;
    }

    /**
     * 注文に料金の行を足す口（ADR 0078）を持つ App（入っていなければ null）
     */
    public function chargePlugin(): ?PluginDefinition
    {
        foreach ($this->plugins as $plugin) {
            if ($plugin->orderCharges !== null) {
                return $plugin;
            }
        }

        return null;
    }

    /**
     * 会員の特典の口（ADR 0079）を持つ App（入っていなければ null）
     */
    public function memberBenefitsPlugin(): ?PluginDefinition
    {
        foreach ($this->plugins as $plugin) {
            if ($plugin->memberBenefits !== null) {
                return $plugin;
            }
        }

        return null;
    }

    /**
     * ショップの一覧の並びと絞り込みの口を持つ App（入っていなければ null）
     */
    public function catalogBrowsePlugin(): ?PluginDefinition
    {
        foreach ($this->plugins as $plugin) {
            if ($plugin->catalogBrowse !== null) {
                return $plugin;
            }
        }

        return null;
    }

    public function has(string $name): bool
    {
        return isset($this->plugins[$name]);
    }

    public function get(string $name): PluginDefinition
    {
        return $this->plugins[$name] ?? throw new InvalidArgumentException("入っていないプラグインです: {$name}");
    }

    /**
     * @return list<PluginDefinition> 名前の順
     */
    public function all(): array
    {
        $plugins = $this->plugins;
        ksort($plugins);

        return array_values($plugins);
    }
}
