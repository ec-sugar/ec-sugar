<?php

declare(strict_types=1);

namespace App\Core\Sample\Services;

use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingImage;
use App\Core\Sample\Models\SampleMark;
use App\Core\Sample\SamplePhotos;
use App\Core\Sample\SampleSet;
use App\Core\Sample\SampleSets;
use App\Core\Shop\Services\ShopSettingsService;
use App\Core\Theme\Models\ThemeVersion;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Closure;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * 入れたときのサンプル: 入れる（`shop:sample`）・数える・まとめて消す（管理画面のホームと `shop:sample --remove`）。
 *
 * - セットは 4 つ（雑貨・お菓子・布もの・植物。SampleSets）。1 つの店に入れるのは 1 つのセットだけ。別のセットへ替えるときは、先にまとめて消す
 * - 断るかどうか（お店の商品がある・別のセットが入っている）と、写真をどこから読むかはここで決め、中身を入れるのは SampleInstaller、消すのは SampleRemover
 * - 写真: 雑貨は配る物の中の絵（欠けていれば止める）。ほかのセットは入れる時に Unsplash から一時フォルダへ取り（SamplePhotos）、
 *   取れなかった写真は無しで入れて知らせる。一時フォルダは入れ終えたら（失敗しても）消す
 * - テーマの切り替えは呼ぶ側（コマンド）が行う（店のテーマは中央の表にあり、店の文脈の外の仕事）
 */
final class ShopSamples
{
    /** CLI から入れたときの記録の actor */
    public const string ACTOR = 'cli:shop-sample';

    /** 雑貨の写真のフォルダ（リポジトリの中。読むだけ） */
    public const string PHOTOS = 'samples/zakka';

    public function __construct(
        private readonly ShopSettingsService $settings,
        private readonly SampleInstaller $installer,
        private readonly SampleRemover $remover,
        private readonly SamplePhotos $photos,
    ) {}

    /**
     * サンプルを入れる。お店の商品（サンプルでないもの）があれば、$force が無いと断る（動いているショップに見本を並べない）。
     * 別のセットのサンプルが残っていれば、$force でも断る（先にまとめて消す）
     *
     * @param  string  $set  セットの名前（SampleSets）
     * @param  string|null  $photos  写真のフォルダ（`<名前>.webp|jpg|jpeg|png`）。無ければ雑貨は database/samples/zakka、ほかは Unsplash から取る
     * @param  (Closure(string, bool): void)|null  $notice  途中の知らせ（文と注意か。写真を取りに行く・待つ・写真が欠けた）を受け取る
     * @return array{added: int, existing: int, missing: array<string, string>} この回に入れた物の数・もう入っていた（か入れ終えた）物の数・入れられなかった写真（名前 => 理由）
     *
     * @throws SamplesRefused お店の商品がある・別のセットが入っている
     * @throws InvalidArgumentException 無いセット
     */
    public function install(string $set = SampleSets::DEFAULT, ?string $photos = null, bool $force = false, ?Closure $notice = null): array
    {
        $this->assertTenant();
        $sample = SampleSets::get($set);
        $other = $this->installedSet();

        if ($other !== null && $other !== $sample->name()) {
            throw new SamplesRefused('このショップには別のサンプル（'.SampleSets::get($other)->label().'）が入っています。入れ替えるときは、先に --remove でまとめて消してから入れてください。');
        }

        $keys = self::keys($sample);
        $marked = SampleMark::query()->whereIn('key', $keys)->count();

        if ($marked === count($keys)) {
            return ['added' => 0, 'existing' => $marked, 'missing' => []];
        }

        if (! $force && $this->hasOwnListings()) {
            throw new SamplesRefused('このショップにはもう商品があります。サンプルを並べると、お客さんにも見えます。入れるときは --force を付けてください。');
        }

        $missing = [];
        $temporary = null;

        if ($photos === null && $sample->bundled()) {
            $photos = database_path('samples/'.$sample->name());
        } elseif ($photos === null) {
            if ($notice !== null) {
                $notice('写真を Unsplash から取っています（数十秒かかります）…', false);
            }

            $temporary = self::temporaryDirectory();
            $photos = $temporary;
            $missing = $this->photos->fetch($sample->photos(), $temporary);
        }

        try {
            $result = $this->installer->run($sample, $photos, $sample->bundled(), $missing, $notice);
        } finally {
            if ($temporary !== null) {
                self::removeDirectory($temporary);
            }
        }

        foreach ($result['missing'] as $name => $reason) {
            if ($notice !== null) {
                $notice("写真 {$name} を入れられませんでした（{$reason}）。この写真なしで入れました", true);
            }
        }

        return ['added' => $result['added'], 'existing' => count($keys) - $result['added'], 'missing' => $result['missing']];
    }

    /**
     * いま残っているサンプルのセットの名前（残っていなければ null）
     */
    public function installedSet(): ?string
    {
        $key = SampleMark::query()->active()->orderBy('created_at')->orderBy('id')->value('key');

        return is_string($key) ? SampleSets::ofKey($key) : null;
    }

    /**
     * 残っているサンプルの数（無ければ null。管理画面のホームが「まとめて消す」を出すか決める）
     *
     * @return array{listings: int, categories: int, photos: int, content: bool, legal: bool}|null
     */
    public function summary(): ?array
    {
        $counts = SampleMark::query()->active()->toBase()->selectRaw('subject_type, count(*) AS n')->groupBy('subject_type')->pluck('n', 'subject_type')
            ->map(static fn (mixed $n): int => (int) $n)->all();

        if ($counts === []) {
            return null;
        }

        return [
            'listings' => $counts['listing'] ?? 0,
            'categories' => $counts['category'] ?? 0,
            'photos' => $counts['shop_media'] ?? 0,
            'content' => ($counts['theme_version'] ?? 0) > 0,
            'legal' => ($counts['shop_settings'] ?? 0) > 0,
        ];
    }

    /**
     * 入れたときのままのサンプル（開設の手順の判定に使う。読むだけ）。
     * 書き換えた物は、まとめて消すときと同じくお店の物として扱う（バージョンか写真が変わった商品・改訂が進んだテーマの中身・変えた表記）。
     * installed はサンプルの商品を入れたことがあるか（消した後も真）、listings・theme_versions は手つかずの物の ID、legal は今の表記が手つかずの見本か
     *
     * @return array{installed: bool, listings: list<string>, theme_versions: list<string>, legal: bool}
     */
    public function untouched(): array
    {
        $listings = [];
        $versions = [];
        $legal = false;

        foreach (SampleMark::query()->active()->whereIn('subject_type', ['listing', 'theme_version', 'shop_settings'])->get() as $mark) {
            if ($mark->subject_type === 'listing' && is_string($mark->subject_id)) {
                $version = Listing::query()->whereKey($mark->subject_id)->value('version');

                if ($version !== null && $version === ($mark->snapshot['version'] ?? null) && self::imageIds($mark->subject_id) === ($mark->snapshot['images'] ?? null)) {
                    $listings[] = $mark->subject_id;
                }
            } elseif ($mark->subject_type === 'theme_version' && is_string($mark->subject_id)) {
                $revision = ThemeVersion::query()->whereKey($mark->subject_id)->value('revision');

                if ($revision !== null && $revision === ($mark->snapshot['revision'] ?? null)) {
                    $versions[] = $mark->subject_id;
                }
            } elseif (isset($mark->snapshot['legal'])) {
                $legal = $legal || self::legalHash($this->settings->legal()) === $mark->snapshot['legal'];
            }
        }

        return [
            'installed' => SampleMark::query()->where('subject_type', 'listing')->whereNotNull('subject_id')->exists(),
            'listings' => $listings,
            'theme_versions' => $versions,
            'legal' => $legal,
        ];
    }

    /**
     * 印の付いたサンプルをまとめて消す（SampleRemover。全部を 1 つのトランザクションで）
     *
     * @return array{removed: int, kept: list<array{type: string, title: string|null, reason: string}>}
     */
    public function remove(string $actor): array
    {
        $this->assertTenant();

        return $this->remover->remove($actor);
    }

    /**
     * セットで入れた物を、お店の物にする（印を「残した」で閉じる。サンプルとしては数えず、まとめて消す対象にもしない）。
     * デモの店（ADR 0086）がサンプルの仕組みで中身を入れた後に呼ぶ。閉じた印は残るので、流し直しても二重に入れない
     *
     * @return int 閉じた印の数
     */
    public function adopt(SampleSet $set): int
    {
        $this->assertTenant();

        if ($set->name() === SampleSets::DEFAULT || preg_match('/\A[a-z][a-z0-9]*\z/', $set->name()) !== 1) {
            throw new LogicException('お店の物にできるのは、名前の付いた印のセットだけです');
        }

        $prefix = $set->name().'_';

        return SampleMark::query()->active()->where('key', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%')
            ->update(['ended_at' => CarbonImmutable::now(), 'outcome' => 'kept', 'updated_at' => CarbonImmutable::now()]);
    }

    /**
     * 印の名前の一覧（入れる順）
     *
     * @return list<string>
     */
    public static function keys(SampleSet $set): array
    {
        return [
            ...array_map(static fn (string $slug): string => SampleSets::key($set, 'category', $slug), array_keys($set->categories())),
            ...array_map(static fn (string $key): string => SampleSets::key($set, 'listing', $key), array_keys($set->listings())),
            SampleSets::key($set, 'theme', $set->theme()), SampleSets::key($set, 'legal'), SampleSets::key($set, 'shipping'),
        ];
    }

    /**
     * 商品の写真の ID（入れたときと同じかを比べる）
     *
     * @return list<string>
     */
    public static function imageIds(string $listingId): array
    {
        return ListingImage::query()->where('listing_id', $listingId)->orderBy('id')->pluck('id')->all();
    }

    /**
     * @param  list<array{label: string, value: string}>  $items
     */
    public static function legalHash(array $items): string
    {
        return hash('sha256', (string) json_encode($items, JSON_UNESCAPED_UNICODE));
    }

    /**
     * お店の商品（サンプルの印の無い商品）があるか
     */
    private function hasOwnListings(): bool
    {
        return Listing::query()
            ->whereNotIn('id', SampleMark::query()->where('subject_type', 'listing')->whereNotNull('subject_id')->select('subject_id'))
            ->exists();
    }

    /**
     * 取った写真の一時フォルダ（この回だけ。自分だけが読める）
     */
    private static function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/ec-sugar-samples-'.bin2hex(random_bytes(8));

        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('写真の一時フォルダを作れませんでした');
        }

        return $directory;
    }

    private static function removeDirectory(string $directory): void
    {
        foreach (glob("{$directory}/*") ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }

    private function assertTenant(): void
    {
        if (! tenant() instanceof Tenant) {
            throw new LogicException('サンプルは店の文脈の中でだけ扱う');
        }
    }
}
