<?php

declare(strict_types=1);

namespace App\Core\Section\Services;

use App\Core\Event\EventStore;
use App\Core\Section\Models\ShopPage;
use App\Core\Shop\Seo\SeoText;
use App\Core\Shop\Services\RedirectService;
use App\Core\Theme\Services\ThemeContentService;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 自由なページ（ADR 0039 区切り 3）を作る・変える・並べる・ゴミ箱へ入れる・戻す。ページの住所と題と公開の状態を変えるのはここだけ。
 * 中身（区画の並び）は ThemeContentService が、テーマの値の中に page-{ID} の名前で持つ。
 *
 * - 書き込みは EventStore::lock() を先に取る（ADR 0012）。slug が重ならないことと数の上限は、このロックの中で確かめる（DB の一意の索引が最後の守り）
 * - slug は英小文字・数字・ハイフンの 60 文字まで、予約語（ShopPage::RESERVED_SLUGS）は使えない（DB でも断る）
 * - 形が違えば ValidationException（管理画面ではそのまま 422）
 * - イベントには題（店の人の自由な文）を入れず、slug と変えた項目の名前だけ
 */
final class ShopPageService
{
    public function __construct(
        private readonly EventStore $events,
        private readonly ThemeContentService $content,
        private readonly RedirectService $redirects,
    ) {}

    /**
     * すべてのページの中身の名前（page-{ID}。ゴミ箱のページも。テーマを替えたときに中身を写すため）
     *
     * @return list<string>
     */
    public function pageNames(bool $withTrashed = true): array
    {
        $query = $withTrashed ? ShopPage::withTrashed() : ShopPage::query();

        return $query->orderBy('position')->orderBy('id')->get()->map(static fn (ShopPage $page): string => $page->pageName())->values()->all();
    }

    /**
     * 並び順の一覧（ゴミ箱のページは $trashed が真のときだけ、ゴミ箱のページだけ）
     *
     * @return Collection<int, ShopPage>
     */
    public function list(bool $trashed = false): Collection
    {
        $query = $trashed ? ShopPage::onlyTrashed()->orderByDesc('deleted_at') : ShopPage::query()->orderBy('position')->orderBy('id');

        return $query->get();
    }

    /**
     * ゴミ箱に入っていないページ（無ければ null）
     */
    public function find(string $id): ?ShopPage
    {
        return ShopPage::query()->whereKey(strtolower($id))->first();
    }

    /**
     * ショップに出すページ（公開していて、ゴミ箱に入っていない）
     */
    public function findPublished(string $slug): ?ShopPage
    {
        return ShopPage::query()->where('slug', $slug)->where('status', ShopPage::PUBLISHED)->first();
    }

    /**
     * ショップのメニューに載せるページ（公開していて、メニューに載せる印のあるもの。並び順）
     *
     * @return Collection<int, ShopPage>
     */
    public function navigation(): Collection
    {
        return ShopPage::query()->where('status', ShopPage::PUBLISHED)->where('in_nav', true)->orderBy('position')->orderBy('id')->get();
    }

    /**
     * 作る（最初は下書き。お客さんには出ない）。並びの最後へ
     *
     * @param  array<string, mixed>  $input  title・slug・in_nav
     */
    public function create(array $input, ?string $actor = null): ShopPage
    {
        $data = $this->validate($input, [
            'title' => ['required', ...$this->titleRules()],
            'slug' => ['required', ...$this->slugRules()],
            'in_nav' => ['sometimes', 'boolean'],
        ]);

        return $this->write(function () use ($data, $actor): ShopPage {
            if (ShopPage::withTrashed()->count() >= ShopPage::MAX_PAGES) {
                throw ValidationException::withMessages(['title' => 'ページは '.ShopPage::MAX_PAGES.' 個まで作れます（ゴミ箱のページも数えます）。']);
            }

            $this->assertSlugFree($data['slug']);
            $page = ShopPage::create([
                'title' => trim($data['title']),
                'slug' => $data['slug'],
                'in_nav' => (bool) ($data['in_nav'] ?? false),
                'position' => ShopPage::query()->count(),
                'status' => ShopPage::DRAFT,
            ]);
            $this->events->append('shop_page.created', 'shop_page', $page->id, ['slug' => $page->slug], $actor);

            return $page;
        });
    }

    /**
     * 題・slug・メニューに載せるか・公開の状態・検索の結果に出す題と説明（ADR 0061）を変える（渡した項目だけ）。
     * slug を変えたら、古い URL から新しい URL への転送を自動で足す（ADR 0061 の 11。上限に達していれば足さない）
     *
     * @param  array<string, mixed>  $input
     */
    public function update(string $id, array $input, ?string $actor = null): ShopPage
    {
        $data = $this->validate($input, [
            'title' => ['sometimes', 'required', ...$this->titleRules()],
            'slug' => ['sometimes', 'required', ...$this->slugRules()],
            'in_nav' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'required', Rule::in([ShopPage::DRAFT, ShopPage::PUBLISHED])],
            ...SeoText::rules(),
        ]);

        return $this->write(function () use ($id, $data, $actor): ShopPage {
            $page = $this->lockPage($id);
            $movedFrom = null;

            if (array_key_exists('title', $data)) {
                $page->title = trim($data['title']);
            }

            if (array_key_exists('slug', $data) && $data['slug'] !== $page->slug) {
                $this->assertSlugFree($data['slug']);
                $movedFrom = $page->slug;
                $page->slug = $data['slug'];
            }

            foreach (['seo_title', 'seo_description'] as $field) {
                if (array_key_exists($field, $data)) {
                    $page->setAttribute($field, SeoText::clean($data[$field]));
                }
            }

            if (array_key_exists('in_nav', $data)) {
                $page->in_nav = (bool) $data['in_nav'];
            }

            if (array_key_exists('status', $data)) {
                $page->status = $data['status'];
            }

            $this->saveChanged($page, $actor);

            if ($movedFrom !== null) {
                $this->redirects->followMove("/pages/{$movedFrom}", "/pages/{$page->slug}", $actor);
            }

            return $page;
        });
    }

    /**
     * 公開する（編集の画面の「公開する」。中身の公開と同じトランザクションで呼ぶ）
     */
    public function publish(string $id, ?string $actor = null): ShopPage
    {
        return $this->write(function () use ($id, $actor): ShopPage {
            $page = $this->lockPage($id);
            $page->status = ShopPage::PUBLISHED;
            $this->saveChanged($page, $actor);

            return $page;
        });
    }

    /**
     * 並び替える（ゴミ箱に入っていないページをすべて、並べたい順に）
     *
     * @param  list<string>  $ids
     */
    public function reorder(array $ids, ?string $actor = null): void
    {
        $ids = array_map('strtolower', $ids);

        $this->write(function () use ($ids, $actor): void {
            $current = ShopPage::query()->lockForUpdate()->pluck('id')->all();
            $given = $ids;
            sort($current);
            sort($given);

            if ($current !== $given || count(array_unique($ids)) !== count($ids)) {
                throw ValidationException::withMessages(['ids' => '並べ替えには、ゴミ箱に入っていないページをすべて 1 回ずつ指定してください。']);
            }

            if ($ids === []) {
                return;
            }

            foreach ($ids as $position => $id) {
                ShopPage::query()->whereKey($id)->update(['position' => $position]);
            }

            $this->events->append('shop_page.reordered', 'shop_page', $ids[0], ['order' => $ids], $actor);
        });
    }

    /**
     * ゴミ箱へ（ショップにもメニューにも出なくなる。中身は残り、戻せる）
     */
    public function trash(string $id, ?string $actor = null): void
    {
        $this->write(function () use ($id, $actor): void {
            $page = $this->lockPage($id);
            $page->delete();
            $this->renumber();
            $this->events->append('shop_page.trashed', 'shop_page', $page->id, ['slug' => $page->slug], $actor);
        });
    }

    /**
     * ゴミ箱から戻す（並びの最後へ。同じ slug のページがあれば断る）
     */
    public function restore(string $id, ?string $actor = null): ShopPage
    {
        return $this->write(function () use ($id, $actor): ShopPage {
            $page = ShopPage::onlyTrashed()->whereKey(strtolower($id))->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['page' => 'ゴミ箱にそのページはありません。']);

            $this->assertSlugFree($page->slug);
            $page->position = ShopPage::query()->count();
            $page->restore();
            $this->events->append('shop_page.restored', 'shop_page', $page->id, ['slug' => $page->slug], $actor);

            return $page;
        });
    }

    /**
     * ゴミ箱のページを完全に消す（戻せない）。すべてのテーマ・バージョンの下書きと公開の値からそのページの中身を消し、行を消す。
     * ゴミ箱に入っていないページは消せない（先にゴミ箱へ）。消したページは数の上限（MAX_PAGES）に数えなくなる
     */
    public function purge(string $id, ?string $actor = null): void
    {
        $this->write(function () use ($id, $actor): void {
            $page = ShopPage::withTrashed()->whereKey(strtolower($id))->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['page' => 'ページが見つかりません。']);

            if (! $page->trashed()) {
                throw ValidationException::withMessages(['page' => '完全に削除できるのはゴミ箱のページだけです。先にゴミ箱へ入れてください。']);
            }

            // 中身（テーマの値の page-{ID}）を先に消す。同じトランザクションなので、どちらかだけが残ることはない
            $this->content->forgetPage($page->pageName());
            $page->forceDelete();
            $this->events->append('shop_page.purged', 'shop_page', $page->id, ['slug' => $page->slug], $actor);
        });
    }

    /**
     * @return list<mixed>
     */
    private function titleRules(): array
    {
        return ['string', 'max:'.ShopPage::TITLE_MAX, 'not_regex:/\p{Cc}/u'];
    }

    /**
     * @return list<mixed>
     */
    private function slugRules(): array
    {
        return ['string', 'max:'.ShopPage::SLUG_MAX, 'regex:'.ShopPage::SLUG, Rule::notIn(ShopPage::RESERVED_SLUGS)];
    }

    private function saveChanged(ShopPage $page, ?string $actor): void
    {
        $changed = array_keys($page->getDirty());

        if ($changed === []) {
            return;
        }

        $page->save();
        $this->events->append('shop_page.updated', 'shop_page', $page->id, ['slug' => $page->slug, 'changed' => $changed] + ($page->wasChanged('status') ? ['status' => $page->status] : []), $actor);
    }

    private function lockPage(string $id): ShopPage
    {
        return ShopPage::query()->whereKey(strtolower($id))->lockForUpdate()->first()
            ?? throw ValidationException::withMessages(['page' => 'ページが見つかりません。']);
    }

    private function assertSlugFree(string $slug): void
    {
        if (ShopPage::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['slug' => 'この URL はほかのページで使われています。']);
        }
    }

    private function renumber(): void
    {
        foreach (ShopPage::query()->orderBy('position')->orderBy('id')->pluck('id') as $position => $id) {
            ShopPage::query()->whereKey($id)->update(['position' => $position]);
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function write(Closure $work): mixed
    {
        try {
            return DB::transaction(function () use ($work): mixed {
                $this->events->lock();

                return $work();
            });
        } catch (UniqueConstraintViolationException) {
            // ロックの外から同じ slug が入った場合の最後の守り
            throw ValidationException::withMessages(['slug' => 'この URL はほかのページで使われています。']);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validate(array $input, array $rules): array
    {
        return Validator::make($input, $rules, [
            'slug.regex' => 'URL は英小文字・数字・ハイフン（-）で入力してください（ハイフンは先頭と末尾には使えません）。',
            'slug.not_in' => 'この URL はショップの既存の画面で使われているため使えません。別の名前にしてください。',
            'title.not_regex' => 'タイトルは改行の無い 1 行にしてください。',
            ...SeoText::messages(),
        ], ['title' => 'タイトル', 'slug' => 'URL', 'in_nav' => 'メニューに載せるか', 'status' => '公開の状態', ...SeoText::attributes()])->validate();
    }
}
