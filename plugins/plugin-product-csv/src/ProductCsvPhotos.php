<?php

declare(strict_types=1);

namespace EcSugar\PluginProductCsv;

use App\Core\Csv\CsvCells;
use App\Core\Listing\Models\Listing;
use App\Core\Listing\Models\ListingImage;
use App\Core\Listing\Services\ListingImageService;
use App\Core\Media\InvalidImageException;
use App\Core\Media\MediaQuotaExceeded;
use App\Core\Media\MediaRateLimited;
use App\Core\Media\RemoteImageRefused;
use App\Core\Media\RemoteImages;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;

/**
 * 商品の CSV の写真の列（写真1〜写真10。docs/design/product-csv.md の「写真」）。店の文脈の中で呼ぶ。
 *
 * 値は 3 つのどれか:
 * - この店の写真の公開の URL（書き出した CSV の値。…/media/listings/{写真の ID}/large）: 同じ商品の写真ならそのまま（取りに行かない）、
 *   ほかの商品の写真なら置き場から写す（外へ出ない）
 * - https の URL: サーバーが取りに行く（RemoteImages。内向きの住所・http・大きすぎ・画像でないものは断る）
 * - 一緒に上げた ZIP の中のファイル名（PhotoArchive）
 *
 * - 足すだけで消さない。CSV に無い今の写真はそのまま残し、CSV の写真を CSV の順で前に並べる
 * - 同じ写真を二重に作らない: URL はハッシュ（source_url_digest）で、ZIP と取った中身は元のファイルのハッシュ（source_digest）で、その商品の写真と照らす
 * - 1 枚が取れなくても止めない（PhotoSkipped。行と理由を記録に残す）。置き場の量の上限（MediaQuotaExceeded）は取り込みを止め、
 *   1 分の回数（MediaRateLimited）は待って続ける（ProductImports）
 *
 * @phpstan-type Photo array{column: string, ref: string, problem: string|null}
 * @phpstan-type Entry array{line: int, column: string, listing_id: string, ref: string, problem: string|null, last: bool}
 * @phpstan-type Files array<string, array{file?: string, digest?: string, problem?: string}>
 * @phpstan-type Warning array{line: int, column: string, message: string}
 */
final class ProductCsvPhotos
{
    /** 写真の列の数（1 つの商品の写真の上限と同じ） */
    public const int COLUMNS = ListingImageService::MAX_PER_LISTING;

    /** 1 回の取り込みで書ける写真の数（写真の列の空でない欄の合計） */
    public const int MAX_PER_IMPORT = 2_000;

    /** この店の写真の公開の URL の道筋（店のショップの経路 storefront.media.listing と同じ形） */
    private const string OWN_PATH = '#\A/media/listings/([0-9A-Za-z]{26})/(?:large|medium)\z#';

    public function __construct(
        private readonly ListingImageService $images,
        private readonly RemoteImages $remote,
        private readonly PhotoArchive $archives,
    ) {}

    /**
     * 列の鍵（photo1〜photo10）
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (int $n): string => "photo{$n}", range(1, self::COLUMNS));
    }

    /**
     * 1 つの欄の値を確かめる（DB は見ない）。駄目な値も、商品の取り込みは止めずに写真だけを飛ばすので、理由を付けて返す
     *
     * @return array{ref: string, problem: string|null}
     */
    public static function parse(string $value): array
    {
        if (preg_match('#\A[a-z][a-z0-9+.-]*://#i', $value) === 1) {
            // この店の写真の URL の形なら、ここでは決めない（http のショップでも往復できるように。外の URL かは classify が DB を見て決める）
            return ['ref' => $value, 'problem' => self::ownPath($value) === null ? RemoteImages::problemOf($value) : null];
        }

        $name = PhotoArchive::normalize($value);

        return ['ref' => $name, 'problem' => PhotoArchive::nameProblem($name)];
    }

    /**
     * 確かめ（巻き戻すトランザクションの中で、商品を書いた後に）。足す・付いている写真の数と、入れない写真の理由
     *
     * @param  list<Photo>  $photos
     * @param  Files|null  $files  一緒に上げた ZIP の中身（上げていなければ null）
     * @return array{add: int, keep: int, warnings: list<Warning>}
     */
    public function review(array $photos, int $line, string $listingId, ?array $files): array
    {
        $existing = ListingImage::query()->where('listing_id', $listingId)->get(['id', 'listing_id', 'source_digest', 'source_url_digest']);
        $count = $existing->count();
        $result = ['add' => 0, 'keep' => 0, 'warnings' => []];
        $seen = [];

        foreach ($photos as $photo) {
            $warn = static function (string $message) use (&$result, $line, $photo): void {
                $result['warnings'][] = ['line' => $line, 'column' => $photo['column'], 'message' => $message];
            };
            $source = $this->classify($photo, $files);

            if ($source['kind'] === 'problem') {
                $warn($source['message']);

                continue;
            }

            if (isset($seen[$source['key']])) {
                $warn('同じ写真が「'.$seen[$source['key']].'」にもあります。');

                continue;
            }

            $seen[$source['key']] = ProductCsvFormat::ecSugar()->headerOf($photo['column']);

            $kept = match ($source['kind']) {
                'own' => $source['image']->listing_id === $listingId,
                'url' => $existing->contains('source_url_digest', $source['digest']),
                'zip' => $existing->contains('source_digest', $source['digest']),
            };

            if ($kept) {
                $result['keep']++;

                continue;
            }

            if ($count >= ListingImageService::MAX_PER_LISTING) {
                $warn('写真は 1 つの商品に '.ListingImageService::MAX_PER_LISTING.' 枚までです（登録済みの写真と合わせて超えた分は追加しません）。');

                continue;
            }

            $count++;
            $result['add']++;
        }

        return $result;
    }

    /**
     * 反映（写真の段）: 1 枚を入れる。足したら 'added'、もう付いていたら 'kept'
     *
     * @param  Entry  $entry
     * @param  Files|null  $files
     *
     * @throws PhotoSkipped この写真を飛ばす（理由つき）
     * @throws MediaQuotaExceeded 置き場の量の上限（取り込みを止める）
     * @throws MediaRateLimited 1 分の回数（待って続ける）
     */
    public function place(array $entry, ?array $files, ?string $archive, string $actor): string
    {
        $listing = Listing::query()->whereKey($entry['listing_id'])->where('kind', 'product')->first()
            ?? throw new PhotoSkipped('商品が見つかりません（取り込みの途中で削除された可能性があります）。');
        $source = $this->classify(['column' => $entry['column'], 'ref' => $entry['ref'], 'problem' => $entry['problem']], $files);
        $has = static fn (string $column, string $digest): bool => ListingImage::query()->where('listing_id', $listing->id)->where($column, $digest)->exists();

        if ($source['kind'] === 'problem') {
            throw new PhotoSkipped($source['message']);
        }

        if ($source['kind'] === 'own' && $source['image']->listing_id === $listing->id) {
            return 'kept';
        }

        if (($source['kind'] === 'url' && $has('source_url_digest', $source['digest'])) || ($source['kind'] === 'zip' && $has('source_digest', $source['digest']))) {
            return 'kept';
        }

        $path = match ($source['kind']) {
            'url' => $this->download($entry['ref']),
            'zip' => $this->fromArchive($archive, $source['file']),
            'own' => $this->fromShop($source['image']),
        };

        try {
            $digest = (string) hash_file('sha256', $path);

            // 中身が同じ写真（URL が違う・ほかの商品の写真を写すとき）も二重に作らない
            if ($has('source_digest', $digest)) {
                return 'kept';
            }

            $this->images->add($listing, $path, null, $actor, $source['kind'] === 'url' ? $entry['ref'] : null);

            return 'added';
        } catch (MediaQuotaExceeded|MediaRateLimited $e) {
            throw $e;
        } catch (InvalidImageException $e) {
            throw new PhotoSkipped($e->getMessage());
        } finally {
            @unlink($path);
        }
    }

    /**
     * 1 つの商品の写真を入れ終えたら、CSV の写真を CSV の順で前に並べる（CSV に無い今の写真はその後ろに今の順で）
     *
     * @param  list<Entry>  $entries  この商品の写真（CSV の順）
     * @param  Files|null  $files
     */
    public function arrange(string $listingId, array $entries, ?array $files, string $actor): void
    {
        $listing = Listing::query()->whereKey($listingId)->first();

        if ($listing === null) {
            return;
        }

        $images = ListingImage::query()->where('listing_id', $listingId)->orderBy('position')->orderBy('id')->get(['id', 'source_digest', 'source_url_digest']);
        $current = $images->pluck('id')->all();
        $wanted = [];

        foreach ($entries as $entry) {
            $source = $this->classify(['column' => $entry['column'], 'ref' => $entry['ref'], 'problem' => $entry['problem']], $files);
            $id = match ($source['kind']) {
                'own' => $source['image']->listing_id === $listingId ? $source['image']->id : null,
                'url' => $images->firstWhere('source_url_digest', $source['digest'])?->id,
                'zip' => $images->firstWhere('source_digest', $source['digest'])?->id,
                'problem' => null,
            };

            if (is_string($id) && ! in_array($id, $wanted, true)) {
                $wanted[] = $id;
            }
        }

        $order = [...$wanted, ...array_values(array_diff($current, $wanted))];

        if ($order !== $current) {
            $this->images->reorder($listing, $order, $actor);
        }
    }

    /**
     * 値がどこの写真か（DB と ZIP の中身の一覧を見る。取りには行かない）
     *
     * @param  Photo  $photo
     * @param  Files|null  $files
     * @return array{kind: 'problem', message: string}|array{kind: 'own', key: string, image: ListingImage}|array{kind: 'url', key: string, digest: string}|array{kind: 'zip', key: string, file: string, digest: string}
     */
    private function classify(array $photo, ?array $files): array
    {
        if ($photo['problem'] !== null) {
            return ['kind' => 'problem', 'message' => $photo['problem']];
        }

        $ref = $photo['ref'];

        if (preg_match('#\A[a-z][a-z0-9+.-]*://#i', $ref) === 1) {
            $id = self::ownPath($ref);
            $image = $id === null ? null : ListingImage::query()->whereKey($id)->first();

            if ($image !== null) {
                return ['kind' => 'own', 'key' => 'own:'.$image->id, 'image' => $image];
            }

            // この店に無い写真の URL（ほかの店の書き出しなど）は、外の URL として同じ決まりで確かめる
            $problem = RemoteImages::problemOf($ref);

            return $problem === null
                ? ['kind' => 'url', 'key' => 'url:'.hash('sha256', $ref), 'digest' => hash('sha256', $ref)]
                : ['kind' => 'problem', 'message' => $problem];
        }

        if ($files === null) {
            return ['kind' => 'problem', 'message' => '写真の ZIP が選ばれていません。写真の列にファイル名を書いたときは、写真の ZIP も一緒に選んでください。'];
        }

        $found = PhotoArchive::lookup($files, $ref);

        if ($found === null) {
            return ['kind' => 'problem', 'message' => 'このファイルが ZIP の中に見つかりません（'.CsvCells::preview($ref).'）。'];
        }

        if (isset($found['problem'])) {
            return ['kind' => 'problem', 'message' => $found['problem']];
        }

        if (! isset($found['file'], $found['digest'])) {
            throw new LogicException('ZIP の中身の一覧の形が違う');
        }

        return ['kind' => 'zip', 'key' => 'zip:'.$found['digest'], 'file' => $found['file'], 'digest' => $found['digest']];
    }

    /**
     * 写真の公開の URL の形なら、写真の ID（ホストは見ない。店のドメインを替えても、書き出した CSV で往復できるように。この店の写真かは DB で決める）
     */
    private static function ownPath(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && preg_match(self::OWN_PATH, $path, $m) === 1 ? strtolower($m[1]) : null;
    }

    /**
     * @throws PhotoSkipped
     */
    private function download(string $url): string
    {
        try {
            $body = $this->remote->fetch($url);
        } catch (RemoteImageRefused $e) {
            throw new PhotoSkipped('写真をダウンロードできませんでした: '.$e->getMessage().'。');
        }

        $path = self::temporary();
        file_put_contents($path, $body);

        return $path;
    }

    /**
     * @throws PhotoSkipped
     */
    private function fromArchive(?string $archive, string $file): string
    {
        if ($archive === null) {
            throw new PhotoSkipped('アップロードした ZIP が、保存期間を過ぎて消えています。もう一度、CSV と ZIP を選んでください。');
        }

        try {
            return $this->archives->copyOut($archive, $file);
        } catch (RuntimeException $e) {
            throw new PhotoSkipped($e->getMessage());
        }
    }

    /**
     * この店のほかの商品の写真を、置き場から一時のファイルに写す（外へは出ない。入れるときにもう一度作り直す）
     *
     * @throws PhotoSkipped
     */
    private function fromShop(ListingImage $image): string
    {
        $tenant = (string) tenant()?->getTenantKey();

        try {
            $bytes = Storage::disk('media')->get(ListingImage::path($tenant, $image->listing_id, $image->id, 'large'));
        } catch (RuntimeException) {
            $bytes = null;
        }

        if (! is_string($bytes) || $bytes === '') {
            throw new PhotoSkipped('この店の写真のファイルが見つかりません。');
        }

        $path = self::temporary();
        file_put_contents($path, $bytes);

        return $path;
    }

    private static function temporary(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ecimg');

        if ($path === false) {
            throw new RuntimeException('一時保存先を作れません');
        }

        return $path;
    }
}
