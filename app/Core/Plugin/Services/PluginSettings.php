<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use App\Core\Event\EventStore;
use App\Core\Media\Models\ShopMedia;
use App\Core\Plugin\Manifest\SettingSpec;
use App\Core\Plugin\Manifest\SettingType;
use App\Core\Plugin\Models\PluginSetting;
use App\Core\Plugin\PluginNotInstalled;
use App\Core\Plugin\PluginRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 店の App の設定（ADR 0034 の 3）。店の文脈の中だけで使う。値はマニフェストの宣言どおりにサーバーで確かめる
 * （形の合わない ID・スクリプトの文字・長すぎる文は断る）。変えるときは EventStore::lock() を先に取り、
 * plugin.settings_changed を同じトランザクションで残す。記録には変えた項目の名前だけを残し、値（特に秘密）は残さない。
 * 秘密の項目は店の鍵で暗号化して置き、管理画面には「入っているか」だけ、ショップには何も渡さない
 */
final class PluginSettings
{
    public function __construct(
        private readonly EventStore $events,
        private readonly PluginRegistry $registry,
    ) {}

    /**
     * 管理画面に渡す今の値。秘密の項目は値を出さず、入っているかだけ。
     * complete は入れるべき項目（required）がそろっているか（App の画面の札で「設定がそろっています」を出す）
     *
     * @return array{values: array<string, bool|string|null>, secrets: array<string, bool>, complete: bool, changed_at: ?CarbonImmutable}
     */
    public function forAdmin(string $plugin): array
    {
        $specs = $this->specs($plugin);
        $row = PluginSetting::query()->where('plugin', $plugin)->first();
        $secrets = $row->secrets ?? [];
        $values = [];
        $set = [];
        $complete = true;

        foreach ($specs as $key => $spec) {
            if ($spec->secret) {
                $set[$key] = is_string($secrets[$key] ?? null);
            } else {
                $values[$key] = $this->effective($spec, $row->values ?? []);
            }

            if ($spec->required && ! ($spec->secret ? $set[$key] : $values[$key] !== null)) {
                $complete = false;
            }
        }

        return ['values' => $values, 'secrets' => $set, 'complete' => $complete, 'changed_at' => $row?->changed_at];
    }

    /**
     * ショップに渡してよい値（秘密の項目は入らない。既定で埋める）。入れるべき項目が欠けていれば null（その App は差し込まない）。
     * 秘密の列は読まない（復号もしない）
     *
     * @param  array<string, bool|string>|null  $stored  すでに読んだ値（無ければ読む）
     * @return array<string, bool|string>|null
     */
    public function publicValues(string $plugin, ?array $stored = null): ?array
    {
        $stored ??= PluginSetting::query()->where('plugin', $plugin)->value('values');
        $stored = is_array($stored) ? $stored : [];
        $out = [];

        foreach ($this->specs($plugin) as $key => $spec) {
            // 秘密の項目はショップに渡さない（サーバーの中だけで使う。保存のときに入れるべきものは確かめている）
            if ($spec->secret) {
                continue;
            }

            $value = $this->effective($spec, $stored);

            if ($value === null) {
                if ($spec->required) {
                    return null;
                }

                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * 店の全部の App の、保存した秘密でない値（1 回で読む。秘密の列は読まない）
     *
     * @return array<string, array<string, bool|string>>
     */
    public function storedValues(): array
    {
        $out = [];

        foreach (PluginSetting::query()->get(['plugin', 'values']) as $row) {
            $out[$row->plugin] = $row->values;
        }

        return $out;
    }

    /**
     * 秘密の項目の値（サーバーの中だけで使う。後の段のサーバーから送る形のため）。入っていなければ null
     */
    public function secret(string $plugin, string $key): ?string
    {
        $spec = $this->specs($plugin)[$key] ?? null;

        if ($spec === null || ! $spec->secret) {
            return null;
        }

        $secrets = PluginSetting::query()->where('plugin', $plugin)->first()->secrets ?? [];

        return is_string($secrets[$key] ?? null) ? $secrets[$key] : null;
    }

    /**
     * 値を変える。書かなかった項目はそのまま。文と選ぶは null か空で消す。秘密は null か空なら変えない
     * （画面は中身を持たない。要求の空の文字は Laravel が null にする）。秘密を消すのは $clear に名前を書いたときだけ。
     * 何も変わらなければ記録も残さない
     *
     * @param  array<mixed>  $input  項目の名前 => 値
     * @param  array<mixed>  $clear  消す秘密の項目の名前
     *
     * @throws ValidationException 宣言に合わない値・知らない項目・入れるべき項目が欠ける
     */
    public function update(string $plugin, array $input, ?string $actor = null, array $clear = []): void
    {
        $specs = $this->specs($plugin);

        if ($specs === []) {
            throw ValidationException::withMessages(['values' => 'この App には設定の項目がありません。']);
        }

        $changes = $this->validate($specs, $input);

        foreach ($clear as $key) {
            if (! is_string($key) || ! ($specs[$key] ?? null)?->secret) {
                throw ValidationException::withMessages(['clear' => '削除できるのは秘密の項目だけです。']);
            }

            $changes[$key] = null;
        }

        DB::transaction(function () use ($plugin, $specs, $changes, $actor): void {
            $this->events->lock();
            $row = PluginSetting::query()->lockForUpdate()->where('plugin', $plugin)->first();
            $values = $row->values ?? [];
            $secrets = $row->secrets ?? [];
            $changed = [];

            foreach ($changes as $key => $value) {
                $spec = $specs[$key];
                $current = $spec->secret ? ($secrets[$key] ?? null) : ($values[$key] ?? null);

                if ($current === $value) {
                    continue;
                }

                $changed[] = $key;

                if ($spec->secret) {
                    $secrets[$key] = $value;
                } else {
                    $values[$key] = $value;
                }
            }

            // 宣言から消えた項目は残さない。null は持たない（無いのと同じ）
            $values = array_filter(array_intersect_key($values, $specs), static fn (mixed $v): bool => $v !== null);
            $secrets = array_filter(array_intersect_key($secrets, $specs), static fn (mixed $v): bool => is_string($v));
            $missing = [];

            foreach ($specs as $key => $spec) {
                if ($spec->required && ($spec->secret ? ! isset($secrets[$key]) : $this->effective($spec, $values) === null)) {
                    $missing["values.{$key}"] = "「{$spec->label}」を入力してください。";
                }
            }

            if ($missing !== []) {
                throw ValidationException::withMessages($missing);
            }

            if ($changed === []) {
                return;
            }

            sort($changed);
            $row ??= new PluginSetting(['plugin' => $plugin]);
            $row->fill(['values' => $values, 'secrets' => $secrets === [] ? null : $secrets, 'changed_at' => CarbonImmutable::now()])->save();
            // 記録には変えた項目の名前だけ（値は残さない。秘密の中身を連鎖に入れない）
            $this->events->append('plugin.settings_changed', 'plugin_setting', $row->id, ['plugin' => $plugin, 'changed' => $changed], $actor);
        });
    }

    /**
     * @return array<string, SettingSpec>
     */
    private function specs(string $plugin): array
    {
        if (! $this->registry->has($plugin)) {
            throw new PluginNotInstalled;
        }

        return $this->registry->get($plugin)->app()->settings;
    }

    /**
     * @param  array<string, SettingSpec>  $specs
     * @param  array<mixed>  $input
     * @return array<string, bool|string|null> 変える項目 => 新しい値（null は消す）
     */
    private function validate(array $specs, array $input): array
    {
        $errors = [];
        $changes = [];

        if (count($input) > count($specs)) {
            throw ValidationException::withMessages(['values' => '項目が多すぎます。']);
        }

        foreach ($input as $key => $value) {
            $spec = is_string($key) ? ($specs[$key] ?? null) : null;

            if ($spec === null) {
                $errors['values'] = '使えない項目があります。';

                continue;
            }

            // 秘密は、空なら今の値のまま（画面は中身を持っていないので、変えないときは送らないか空で送る）
            if ($spec->secret && ($value === '' || $value === null)) {
                continue;
            }

            $problem = $spec->problem($value);

            // 写真は、この店の写真にあるものだけ（ほかの店の写真の ID を入れさせない。店の DB だけを探す）
            if ($problem === null && $spec->type === SettingType::Media && is_string($value) && $value !== '' && ! ShopMedia::query()->whereKey(strtolower($value))->exists()) {
                $problem = '「写真」の一覧から選んでください。';
            }

            if ($problem !== null) {
                $errors["values.{$key}"] = $problem;

                continue;
            }

            $changes[$key] = $value === '' ? null : ($spec->type === SettingType::Media && is_string($value) ? strtolower($value) : $value);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        /** @var array<string, bool|string|null> $changes */
        return $changes;
    }

    /**
     * 保存した値か、無ければ宣言の既定（切り替えは既定が無ければ切）
     *
     * @param  array<string, mixed>  $stored
     */
    private function effective(SettingSpec $spec, array $stored): bool|string|null
    {
        $value = $stored[$spec->key] ?? null;

        return match ($spec->type) {
            SettingType::Toggle => is_bool($value) ? $value : (is_bool($spec->default) ? $spec->default : false),
            SettingType::Choice => is_string($value) && isset($spec->options[$value]) ? $value : (is_string($spec->default) ? $spec->default : null),
            SettingType::Text, SettingType::Media => is_string($value) && $value !== '' ? $value : null,
        };
    }
}
