<?php

declare(strict_types=1);

namespace App\Storefront\Themes;

use App\Admin\Models\Operator;
use App\Admin\Services\AuditLog;
use App\Core\Section\Services\ShopPageService;
use App\Core\Theme\Services\ThemeContentService;
use App\Core\Theme\ThemeRegistry;
use App\Models\Tenant;
use InvalidArgumentException;
use Throwable;

/**
 * 店のテーマを変える。入っているテーマの名前からだけ選べる（コードは入れない。CONCEPT 7.2、ADR 0020）。
 * 変えたことは中央の監査ログに残す。コマンドと管理画面の両方がここを通る。区画を宣言したテーマへ切り替えたら、前の見せ方の控え（legacy_storefront_settings）を区画へ写す。
 * 自由なページ（ADR 0039 区切り 3）の中身のうち、核の汎用の区画（basic_*）は新しいテーマの下書きへ写す（新しいテーマにまだ中身が無いページだけ。
 * テーマ固有の区画は写せないので、管理画面は替える前に impact で知らせる。前のテーマの値は消さないので、戻せば元に戻る）
 */
final class ThemeSelector
{
    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly AuditLog $audit,
        private readonly LegacyContentImport $import,
        private readonly ThemeContentService $content,
        private readonly ShopPageService $pages,
    ) {}

    /**
     * テーマを替えると写せない区画がある自由なページ（替える前の知らせ。テナント文脈の中で呼ぶ）。ゴミ箱のページは数えない
     *
     * @return list<array{name: string, dropped: int, copied: int}>
     */
    public function impact(Tenant $tenant, ?string $name): array
    {
        $from = $this->themes->forTenant($tenant);
        $to = $name !== null && $this->themes->has($name) ? $this->themes->get($name) : $this->themes->active();

        if ($from->name === $to->name) {
            return [];
        }

        $plan = $this->content->planFreePageCopy($from->name, $from->schema(), $to->name, $to->schema(), $this->pages->pageNames(withTrashed: false));
        $out = [];

        foreach ($plan as $page => $copy) {
            if ($copy['dropped'] > 0) {
                $out[] = ['name' => $page, 'dropped' => $copy['dropped'], 'copied' => count($copy['sections'])];
            }
        }

        return $out;
    }

    /**
     * @param  string|null  $name  null なら店のテーマを外し、既定のテーマに戻す
     */
    public function change(Tenant $tenant, ?string $name, string $by, ?Operator $operator = null, ?string $ip = null): void
    {
        if ($name !== null && ! $this->themes->has($name)) {
            throw new InvalidArgumentException("入っていないテーマです: {$name}");
        }

        $current = $tenant->getAttribute('theme');
        $from = is_string($current) ? $current : null;
        $before = $this->themes->forTenant($tenant);

        if ($from === $name) {
            return;
        }

        $tenant->setAttribute('theme', $name);
        $tenant->save();
        $this->audit->record('tenant.theme_changed', $operator, ['from' => $from, 'to' => $name, 'by' => $by], (string) $tenant->getTenantKey(), $ip);

        // 区画を宣言したテーマへ初めて切り替えたら、前の見せ方の控えを写して始める（ADR 0028 の 2。バージョンがあれば何もしない）。
        // 名前が変わらない切り替え（テーマを同じ名前で作り直した）では走らないので、そのときは storefront:import-theme-content を走らせる。
        // 写せなくても切り替えは済んでいる（区画は空から始まり、「テーマの中身」で入れられる）ので、知らせだけ残す
        $theme = $this->themes->forTenant($tenant);

        try {
            $tenant->run(fn (): bool => $this->import->import($theme, $by));
        } catch (Throwable $e) {
            report($e);
        }

        // 自由なページの基本の区画を新しいテーマの下書きへ写す（控えの写しの後。写しはバージョンが無いときだけ動くので、先にこちらがバージョンを作らない）。
        // 写せなくても切り替えは済んでいる（新しいテーマのページは最初の並びから）ので、知らせだけ残す
        if ($before->name !== $theme->name) {
            try {
                $tenant->run(fn (): array => $this->content->copyFreePages($before->name, $before->schema(), $theme->name, $theme->schema(), $this->pages->pageNames(), $by));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
