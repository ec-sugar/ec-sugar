<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use App\Core\Listing\Models\Listing;
use App\Core\Plugin\Contracts\ListingPanelHandler;
use App\Core\Plugin\Contracts\ListingPanelScope;
use App\Core\Plugin\ListingPanelNotFound;
use App\Core\Plugin\Manifest\AppManifest;
use App\Core\Plugin\Manifest\SettingSpec;
use App\Core\Plugin\Manifest\SettingType;
use App\Core\Plugin\PluginDefinition;
use App\Core\Plugin\PluginRegistry;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 管理画面の商品の画面の App の欄（ADR 0050）。店の文脈の中だけで使う。
 * 出すのは、この店で使っている App のうち欄の係（ListingPanelHandler）を持つものだけ。使っていない・欄の無い App は無いのと同じ（404）。
 *
 * 核が受け持つのは、欄の宣言の確かめ（文・切り替え・選ぶだけ。秘密は出さない）と、値の形の確かめ（App の設定と同じ決まり。SettingSpec::problem）。
 * 書かなかった欄は今の値で埋め、全部の欄がそろった値を係に渡す（「1 つ以上」のような欄をまたぐ決まりを係が確かめられるように）。
 * 欄をまたぐ決まり・保存（連鎖のロック・行のロック・イベント）は係が受け持つ
 */
final class ListingPanels
{
    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly PluginStates $states,
    ) {}

    /**
     * この商品に出す App の欄（App の名前の順）
     *
     * @return list<array{plugin: string, title: string, note: ?string, fields: list<array<string, mixed>>, values: array<string, bool|string|null>, files: array<string, mixed>|null}>
     */
    public function forListing(Listing $listing): array
    {
        $out = [];

        foreach ($this->registry->all() as $plugin) {
            if ($plugin->listingPanel !== null && $this->states->enabled($plugin->name) && self::applies($plugin->listingPanel, $listing)) {
                $out[] = $this->present($plugin, $plugin->listingPanel, $listing);
            }
        }

        return $out;
    }

    /**
     * 1 つの App の欄の値を保存して、保存した後の欄を返す
     *
     * @param  array<mixed>  $input  欄の名前 => 値
     * @return array{plugin: string, title: string, note: ?string, fields: list<array<string, mixed>>, values: array<string, bool|string|null>, files: array<string, mixed>|null}
     *
     * @throws ListingPanelNotFound 入っていない・使っていない・欄の無い App
     * @throws ValidationException 欄に合わない値（欄の名前つき。values.<名前>）
     */
    public function write(string $plugin, Listing $listing, array $input, string $actor): array
    {
        $definition = $this->definition($plugin);
        /** @var ListingPanelHandler $handler */
        $handler = $definition->listingPanel;

        if (! self::applies($handler, $listing)) {
            throw new ListingPanelNotFound;
        }

        $specs = $this->specs($definition, $handler);
        $values = $this->check($specs, $input, $handler->read($listing));

        try {
            $handler->write($listing, $values, $actor);
        } catch (ValidationException $e) {
            // 係の誤りは欄の名前で来る。画面がほかの誤りと同じ所に出せるよう values. を付けてそろえる
            throw ValidationException::withMessages(self::prefixed($e->errors()));
        }

        return $this->present($definition, $handler, $listing);
    }

    /**
     * 使っている App で、欄の係を持つものだけ（ほかは同じ答えの 404）
     */
    public function definition(string $plugin): PluginDefinition
    {
        if (! $this->registry->has($plugin)) {
            throw new ListingPanelNotFound;
        }

        $definition = $this->registry->get($plugin);

        if ($definition->listingPanel === null || ! $this->states->enabled($plugin)) {
            throw new ListingPanelNotFound;
        }

        return $definition;
    }

    /**
     * @return array{plugin: string, title: string, note: ?string, fields: list<array<string, mixed>>, values: array<string, bool|string|null>, files: array<string, mixed>|null}
     */
    private function present(PluginDefinition $plugin, ListingPanelHandler $handler, Listing $listing): array
    {
        $specs = $this->specs($plugin, $handler);
        $read = $handler->read($listing);
        $values = [];

        // 宣言した欄の値だけを返す（係が余分に返したものは出さない）。形の合わない値は空にする
        foreach ($specs as $key => $spec) {
            $value = $read[$key] ?? null;
            $values[$key] = $value !== null && $spec->problem($value) === null ? $value : ($spec->type === SettingType::Toggle ? false : null);
        }

        // 案内は文字だけ（画面は文字として描く）。長すぎる・壊れた文字は App の作り間違いとして止める
        $note = $handler->note($listing);

        if ($note !== null && ($note === '' || ! mb_check_encoding($note, 'UTF-8') || mb_strlen($note) > ListingPanelHandler::NOTE_MAX)) {
            throw new LogicException("App {$plugin->name}: 商品の画面の案内は 1〜".ListingPanelHandler::NOTE_MAX.' 文字');
        }

        return [
            'plugin' => $plugin->name,
            'title' => $plugin->title,
            'note' => $note,
            'fields' => array_values(array_map(static fn (SettingSpec $s): array => ['key' => $s->key] + $s->toArray(), $specs)),
            'values' => $values,
            // App が置くファイル（売るファイルなど）。置かない App は null
            'files' => app(ListingFileUploads::class)->present($plugin->listingFiles, $listing),
        ];
    }

    /**
     * 係の欄の宣言を確かめる（App の作り間違いなので、合わなければ開発の誤りとして止める）
     *
     * @return array<string, SettingSpec>
     */
    private function specs(PluginDefinition $plugin, ListingPanelHandler $handler): array
    {
        $fields = $handler->fields();
        $out = [];

        if ($fields === [] || count($fields) > AppManifest::MAX_SETTINGS) {
            throw new LogicException("App {$plugin->name}: 商品の画面の欄は 1〜".AppManifest::MAX_SETTINGS.' 個');
        }

        foreach ($fields as $spec) {
            $wrong = match (true) {
                preg_match('/\A[a-z][a-z0-9_]{0,31}\z/', $spec->key) !== 1 => '名前は英小文字・数字・_ の 32 文字まで',
                isset($out[$spec->key]) => '名前が重なっています',
                // 商品の画面の欄は店の人が見る値だけ（秘密は App の設定に置く）
                $spec->secret => '秘密の欄は出せません',
                $spec->type === SettingType::Media => '写真の欄は App の設定だけで使えます',
                $spec->type === SettingType::Text && ($spec->pattern === null || $spec->max === null || $spec->max < 1 || $spec->max > SettingType::TEXT_CAP) => '文の欄には形の決まりと長さの上限が要ります',
                $spec->type === SettingType::Choice && ($spec->options === [] || count($spec->options) > AppManifest::MAX_OPTIONS) => '選ぶ欄の選択肢は 1〜'.AppManifest::MAX_OPTIONS.' 個',
                default => null,
            };

            if ($wrong !== null) {
                throw new LogicException("App {$plugin->name}: 商品の画面の欄 {$spec->key}: {$wrong}");
            }

            $out[$spec->key] = $spec;
        }

        return $out;
    }

    /**
     * 値の形を確かめ、書かなかった欄は今の値で埋める
     *
     * @param  array<string, SettingSpec>  $specs
     * @param  array<mixed>  $input
     * @param  array<string, bool|string|null>  $current
     * @return array<string, bool|string|null>
     */
    private function check(array $specs, array $input, array $current): array
    {
        if (count($input) > count($specs)) {
            throw ValidationException::withMessages(['values' => '項目が多すぎます。']);
        }

        $errors = [];
        $values = [];

        foreach ($input as $key => $value) {
            if (! is_string($key) || ! isset($specs[$key])) {
                $errors['values'] = '使えない項目があります。';
            }
        }

        foreach ($specs as $key => $spec) {
            $value = array_key_exists($key, $input) ? $input[$key] : ($current[$key] ?? null);
            // 空の文字は無いのと同じ（要求の空の文字は Laravel が null にする）
            $value = $value === '' ? null : $value;
            $problem = match (true) {
                $spec->type === SettingType::Toggle && $value === null => 'オン・オフの値が正しくありません。',
                $value === null => $spec->required ? ($spec->type === SettingType::Choice ? "「{$spec->label}」を選んでください。" : "「{$spec->label}」を入力してください。") : null,
                default => $spec->problem($value),
            };

            if ($problem !== null) {
                $errors["values.{$key}"] = $problem;

                continue;
            }

            /** @var bool|string|null $value */
            $values[$key] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    /**
     * 欄を決まった商品だけに出す係（ListingPanelScope）なら、その商品に出すか
     */
    private static function applies(ListingPanelHandler $handler, Listing $listing): bool
    {
        return ! $handler instanceof ListingPanelScope || $handler->appliesTo($listing);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @return array<string, array<int, string>>
     */
    private static function prefixed(array $errors): array
    {
        $out = [];

        foreach ($errors as $key => $messages) {
            $out[$key === 'values' || str_starts_with($key, 'values.') ? $key : "values.{$key}"] = $messages;
        }

        return $out;
    }
}
