<?php

declare(strict_types=1);

namespace EcSugar\PluginBlog;

use App\Core\Event\EventStore;
use App\Core\Listing\Description\ProductDescription;
use App\Core\Media\Models\ShopMedia;
use App\Core\Plugin\AdminPage\AdminActionRefused;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\Services\AdminForms;
use App\Core\Plugin\Services\ContentAccess;
use Carbon\CarbonImmutable;
use EcSugar\PluginBlog\Models\BlogCategory;
use EcSugar\PluginBlog\Models\BlogPost;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 記事を足す・直す・公開する・予約する・取り下げる・消す（ADR 0072）。書くのはここだけ。
 * どれも連鎖のロック（EventStore::lock()）の後にその行のロックを取り、同じトランザクションでイベントを残す
 * （blog.post_created・blog.post_updated・blog.post_published・blog.post_unpublished・blog.post_deleted・blog.category_created）。
 * イベントの中身は ID と状態・日時・変えた欄の名前だけ（題と本文は入れない。文は表にあり、記録には「何を変えたか」だけを残す）。
 * 呼ぶのは管理画面の App の画面（BlogAdminPage）だけで、値の形（長さ・改行・写真の有無・ブロック・日時の形）は核が先に確かめてから渡す（AdminForms）。
 * 有料の記事の「読める条件（鍵）」は、核（ContentAccess）が見るのと同じ形でここでも確かめる（弾かれる値を保存させない）
 */
final class BlogPosts
{
    /** 1 つの店に置ける記事の数（ホストする店でも表が膨らまないように） */
    public const int MAX_POSTS = 1000;

    /** 種類の数（最初の 2 つを含む） */
    public const int MAX_CATEGORIES = 10;

    public const int TITLE_MAX = 100;

    public const int SLUG_MAX = 60;

    public const int EXCERPT_MAX = 200;

    public const int CATEGORY_MAX = 20;

    /** URL の名前の形（英小文字・数字・ハイフン。ハイフンは間だけ） */
    public const string SLUG = '/\A[a-z0-9]+(-[a-z0-9]+)*\z/';

    /** 記事の URL の名前に使えない言葉（/news の下の App の経路と、これから足しそうな名前。DB の制約にも同じ一覧） */
    public const array RESERVED_SLUGS = ['feed', 'preview', 'page', 'category', 'index'];

    public function __construct(private readonly EventStore $events) {}

    /**
     * @param  array<string, bool|string|null>  $values  title・slug・category・new_category・excerpt・image・body・publish_at（核が形を確かめた後）
     */
    public function create(array $values, string $actor): string
    {
        return DB::transaction(function () use ($values, $actor): string {
            $this->events->lock();

            if (BlogPost::query()->count() >= self::MAX_POSTS) {
                throw ValidationException::withMessages(['values' => '記事は '.self::MAX_POSTS.' 本までです。不要な記事を削除してから追加してください。']);
            }

            $row = $this->row($values, null, $actor);
            $post = BlogPost::query()->create($row + ['status' => BlogPost::DRAFT]);
            $this->events->append('blog.post_created', 'blog_post', $post->id, ['category_id' => $post->category_id, 'status' => $post->status], $actor);

            return $post->id;
        });
    }

    /**
     * @param  array<string, bool|string|null>  $values
     */
    public function update(string $id, array $values, string $actor): void
    {
        DB::transaction(function () use ($id, $values, $actor): void {
            $this->events->lock();
            $post = BlogPost::query()->whereKey($id)->lockForUpdate()->first() ?? throw new AdminRecordNotFound;
            $row = $this->row($values, $post, $actor);

            // 予約中の記事の予約の日時を直したら、出る日時も動かす（空・今より前にはできない。取り下げてから直す）
            if ($post->isScheduled() && ! self::sameMoment($post->publish_at, $row['publish_at'])) {
                if ($row['publish_at'] === null || ! $row['publish_at']->isFuture()) {
                    throw ValidationException::withMessages(['publish_at' => '予約中の記事の予約の日時は、今より先の日時にしてください（今すぐ出すときは、取り下げてから「公開する」）。']);
                }

                $row['published_at'] = $row['publish_at'];
            }

            $changed = [];

            foreach ($row as $key => $value) {
                $before = $post->getAttribute($key);
                $same = $value instanceof CarbonImmutable || $before instanceof CarbonImmutable
                    ? self::sameMoment($before instanceof CarbonImmutable ? $before : null, $value instanceof CarbonImmutable ? $value : null)
                    : ProductDescription::canonical($before) === ProductDescription::canonical($value);

                if (! $same) {
                    $changed[] = $key;
                }
            }

            if ($changed === []) {
                return;
            }

            $post->fill($row)->save();
            $this->events->append('blog.post_updated', 'blog_post', $post->id, ['changed' => $changed, 'status' => $post->status], $actor);
        });
    }

    /**
     * 今すぐ公開する（下書きだけ）
     */
    public function publish(string $id, string $actor): void
    {
        $this->changeState($id, $actor, function (BlogPost $post): array {
            if ($post->status !== BlogPost::DRAFT) {
                throw new AdminActionRefused('この記事は、すでに公開または予約されています。画面を再読み込みしてください。');
            }

            return ['status' => BlogPost::PUBLISHED, 'published_at' => CarbonImmutable::now()->startOfMinute()];
        });
    }

    /**
     * 予約の日時に公開する（下書きで、予約の日時が今より先のときだけ）。その時が来ると、表示の時の判定でショップに出る（毎分の処理は要らない）
     */
    public function schedule(string $id, string $actor): void
    {
        $this->changeState($id, $actor, function (BlogPost $post): array {
            if ($post->status !== BlogPost::DRAFT) {
                throw new AdminActionRefused('この記事は、すでに公開または予約されています。画面を再読み込みしてください。');
            }

            if ($post->publish_at === null || ! $post->publish_at->isFuture()) {
                throw new AdminActionRefused('予約の日時が入っていないか、すでに過ぎています。「直す」で今より先の日時を入れてください。');
            }

            return ['status' => BlogPost::PUBLISHED, 'published_at' => $post->publish_at];
        });
    }

    /**
     * 取り下げる（公開・予約から下書きへ。ショップ・サイトマップ・Atom・区画から消える）
     */
    public function unpublish(string $id, string $actor): void
    {
        $this->changeState($id, $actor, function (BlogPost $post): array {
            if ($post->status !== BlogPost::PUBLISHED) {
                throw new AdminActionRefused('この記事は下書きです。画面を再読み込みしてください。');
            }

            return ['status' => BlogPost::DRAFT, 'published_at' => null];
        });
    }

    public function delete(string $id, string $actor): void
    {
        DB::transaction(function () use ($id, $actor): void {
            $this->events->lock();
            $post = BlogPost::query()->whereKey($id)->lockForUpdate()->first() ?? throw new AdminRecordNotFound;
            $post->delete();
            $this->events->append('blog.post_deleted', 'blog_post', $post->id, ['category_id' => $post->category_id, 'status' => $post->status], $actor);
        });
    }

    /**
     * @param  callable(BlogPost): array{status: string, published_at: ?CarbonImmutable}  $next
     */
    private function changeState(string $id, string $actor, callable $next): void
    {
        DB::transaction(function () use ($id, $actor, $next): void {
            $this->events->lock();
            $post = BlogPost::query()->whereKey($id)->lockForUpdate()->first() ?? throw new AdminRecordNotFound;
            $row = $next($post);
            $post->fill($row)->save();
            $scheduled = $row['published_at']?->isFuture() ?? false;
            $this->events->append($row['status'] === BlogPost::PUBLISHED ? 'blog.post_published' : 'blog.post_unpublished', 'blog_post', $post->id, [
                'published_at' => $row['published_at']?->utc()->format(AdminForms::DATETIME_FORMAT),
                'scheduled' => $scheduled,
            ], $actor);
        });
    }

    /**
     * 欄の値を表の行にする。URL の名前・種類・写真は書く時に確かめ直す（選んだ後に消された写真・種類を指さない）。
     * 新しい種類（new_category）があれば、同じ名前の種類を使うか、無ければ足す（10 個まで）
     *
     * @param  array<string, bool|string|null>  $values
     * @return array{category_id: string, title: string, slug: string, excerpt: ?string, image_id: ?string, body: list<array<string, mixed>>, access_keys: list<string>, paid_body: list<array<string, mixed>>, publish_at: ?CarbonImmutable}
     */
    private function row(array $values, ?BlogPost $current, string $actor): array
    {
        $errors = [];
        $slug = (string) ($values['slug'] ?? '');

        if (preg_match(self::SLUG, $slug) !== 1 || strlen($slug) > self::SLUG_MAX) {
            $errors['slug'] = 'URL の末尾は、英小文字・数字・ハイフン（-）で '.self::SLUG_MAX.' 文字までです（ハイフンは最初と最後と続けては使えません）。';
        } elseif (in_array($slug, self::RESERVED_SLUGS, true)) {
            $errors['slug'] = '「'.$slug.'」は URL の末尾に使えません。ほかの名前にしてください。';
        } elseif (BlogPost::query()->where('slug', $slug)->when($current !== null, static fn ($q) => $q->whereKeyNot($current?->id))->exists()) {
            $errors['slug'] = 'この URL の末尾は、ほかの記事が使っています。';
        }

        // 一覧の写真が今の店の写真にあることは核が確かめる（写真の欄。表の外部キーでも守る）。本文のブロックの写真は、ここで店の写真にあるかを確かめる
        $body = AdminForms::blocksOf($values['body'] ?? null);
        $paidBody = AdminForms::blocksOf($values['paid_body'] ?? null);
        $mediaIds = ProductDescription::mediaIds([...$body, ...$paidBody]);
        $image = is_string($values['image'] ?? null) ? $values['image'] : null;
        $found = $mediaIds === [] ? [] : ShopMedia::query()->whereKey($mediaIds)->pluck('id')->all();

        if (array_diff($mediaIds, $found) !== []) {
            $errors['body'] = '本文の写真に、ショップの「写真」に登録されていないものがあります。選び直してください。';
        }

        // 無料の分と有料の分を合わせてブロックの上限（核は欄ごとに数えるので、合計はここで。DB の CHECK でも守る）
        if (count($body) + count($paidBody) > ProductDescription::MAX_BLOCKS) {
            $errors['paid_body'] = '本文と「ここから有料」を合わせて '.ProductDescription::MAX_BLOCKS.' ブロックまでです。';
        }

        $accessKeys = self::accessKeys($values['access_keys'] ?? null, $errors);

        // 読める条件が無い「ここから有料」は、誰にも出せない（ただ読めなくなるだけ）ので置かせない
        if ($paidBody !== [] && $accessKeys === [] && ! isset($errors['access_keys'])) {
            $errors['access_keys'] = '「ここから有料」を入れるときは、「読める条件（鍵）」も入れてください（空のままだと、誰も読めなくなります）。';
        }

        $category = $this->category($values, $errors, $actor);
        $publishAt = is_string($values['publish_at'] ?? null) ? CarbonImmutable::parse($values['publish_at'])->utc() : null;

        if ($errors !== [] || $category === null) {
            throw ValidationException::withMessages($errors === [] ? ['category' => '種類を選んでください。'] : $errors);
        }

        return [
            'category_id' => $category,
            'title' => (string) ($values['title'] ?? ''),
            'slug' => $slug,
            'excerpt' => is_string($values['excerpt'] ?? null) ? $values['excerpt'] : null,
            'image_id' => $image,
            'body' => $body,
            'access_keys' => $accessKeys,
            'paid_body' => $paidBody,
            'publish_at' => $publishAt,
        ];
    }

    /**
     * 「読める条件」の欄（1 行に 1 つ）を鍵の一覧にする。形は核（ContentAccess）と同じで、**核に弾かれる値は保存させない**
     * （保存できたのに誰も読めない記事を作らない）
     *
     * @param  array<string, string>  $errors
     * @return list<string>
     */
    private static function accessKeys(mixed $value, array &$errors): array
    {
        $keys = [];

        foreach (explode("\n", is_string($value) ? $value : '') as $line) {
            $key = trim($line);

            if ($key === '') {
                continue;
            }

            if (! ContentAccess::validKey($key)) {
                $errors['access_keys'] = '読める条件は「種類:名前」の形で入れてください（種類は英小文字・数字・ハイフンで 32 文字まで、名前は英数字・ドット・アンダースコア・ハイフンで 64 文字まで。例: membership:standard）。';

                return [];
            }

            if (! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        if (count($keys) > BlogPaywall::MAX_KEYS) {
            $errors['access_keys'] = '読める条件は '.BlogPaywall::MAX_KEYS.' 個までです。';

            return [];
        }

        return $keys;
    }

    /**
     * 記事の種類の ID。新しい種類の名前があれば、同じ名前の種類か、足した種類（誤りは $errors に）
     *
     * @param  array<string, bool|string|null>  $values
     * @param  array<string, string>  $errors
     */
    private function category(array $values, array &$errors, string $actor): ?string
    {
        $name = is_string($values['new_category'] ?? null) ? trim($values['new_category']) : '';

        if ($name === '') {
            $id = is_string($values['category'] ?? null) ? strtolower($values['category']) : '';

            if (! BlogCategory::query()->whereKey($id)->exists()) {
                $errors['category'] = 'この種類は見つかりませんでした。選び直してください。';

                return null;
            }

            return $id;
        }

        if (mb_strlen($name) > self::CATEGORY_MAX) {
            $errors['new_category'] = '種類の名前は '.self::CATEGORY_MAX.' 文字までです。';

            return null;
        }

        $same = BlogCategory::query()->where('name', $name)->value('id');

        if (is_string($same)) {
            return $same;
        }

        if ($errors !== []) {
            return null;
        }

        if (BlogCategory::query()->count() >= self::MAX_CATEGORIES) {
            $errors['new_category'] = '種類は '.self::MAX_CATEGORIES.' 個までです。今ある種類から選んでください。';

            return null;
        }

        $category = BlogCategory::query()->create([
            'name' => $name,
            'slug' => self::nextCategorySlug(),
            'position' => (int) BlogCategory::query()->max('position') + 1,
        ]);
        $this->events->append('blog.category_created', 'blog_category', $category->id, ['slug' => $category->slug], $actor);

        return $category->id;
    }

    /**
     * 足した種類の絞り込みの名前（category-3 から空いている番号）。名前は日本語のことが多いので、URL には番号の名前を使う
     */
    private static function nextCategorySlug(): string
    {
        $used = BlogCategory::query()->pluck('slug')->all();

        for ($n = 3; ; $n++) {
            if (! in_array("category-{$n}", $used, true)) {
                return "category-{$n}";
            }
        }
    }

    private static function sameMoment(?CarbonImmutable $a, ?CarbonImmutable $b): bool
    {
        return $a === null || $b === null ? $a === $b : $a->equalTo($b);
    }
}
