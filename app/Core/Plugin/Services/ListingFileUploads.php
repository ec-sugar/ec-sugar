<?php

declare(strict_types=1);

namespace App\Core\Plugin\Services;

use App\Core\ByteSize;
use App\Core\Listing\Models\Listing;
use App\Core\Plugin\Contracts\ListingFilesHandler;
use App\Core\Plugin\Contracts\ListingPanelScope;
use App\Core\Plugin\ListingFile;
use App\Core\Plugin\ListingFileUpload;
use App\Core\Plugin\ListingPanelNotFound;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Normalizer;

/**
 * 商品の画面の App の欄のファイルを受け取る（ADR 0084。ListingFilesHandler）。大きなファイル（500 MB まで）を、
 * 入口の上限（nginx の 12m・Cloudflare の 100MB）より小さい切れ端（8 MB）に分けて受ける:
 *
 * 1. start: 名前・大きさ・拡張子を確かめ（数・1 つの上限・店の合計・同時に上げる数も）、上げる途中の記録（<ID>.json）と空のファイル（<ID>.part）を作る
 * 2. chunk: 前から順に足す（受けた大きさ〔ファイルの大きさ〕と offset が合う時だけ。上げる途中のファイルの錠〔flock〕の中で。始めた運営だけ）
 * 3. finish: 大きさがそろったら、中身の型（finfo の判定）が拡張子の宣言と合うか・危ない形でないかを確かめ、App に渡す（App が自分の場所へ移す）
 *
 * 置き場は downloads（写真と別の場所。外から直接は見えない）。上げる途中のファイルは uploads/<店>/<ID>.part と記録の <ID>.json、店の境界は場所の頭（店の ID）。
 * 記録をキャッシュに置かないのは、店の文脈のキャッシュが要求ごとに作り直される置き方（テストの array）でも消えないようにするため
 * 断る形（実行できる形・HTML・SVG・スクリプト）は App が宣言しても受けない。名前は表示用にそろえて App に渡す（置く名前は App が作り直す）
 */
final class ListingFileUploads
{
    /** 実行できる・ブラウザが動かす形（拡張子）。App が宣言しても受けない */
    public const array REFUSED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'inc',
        'html', 'htm', 'xhtml', 'shtml', 'xht', 'mht', 'mhtml', 'svg', 'svgz', 'xml', 'xsl', 'js', 'mjs', 'cjs', 'css', 'swf',
        'exe', 'com', 'bat', 'cmd', 'msi', 'msp', 'dll', 'scr', 'hta', 'cpl', 'lnk', 'reg', 'jar', 'apk', 'app', 'dmg', 'pkg', 'deb', 'rpm',
        'sh', 'bash', 'zsh', 'ps1', 'psm1', 'vbs', 'vbe', 'wsf', 'wsh', 'py', 'pl', 'rb', 'cgi', 'asp', 'aspx', 'jsp', 'htaccess',
    ];

    /** 実行できる・ブラウザが動かす中身の型。App が宣言しても受けない */
    public const array REFUSED_MIMES = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/xml', 'application/xml', 'text/javascript', 'application/javascript', 'application/x-javascript',
        'text/x-php', 'application/x-php', 'application/x-httpd-php', 'text/x-shellscript', 'application/x-sh', 'text/x-python', 'text/x-perl',
        'application/x-dosexec', 'application/x-msdownload', 'application/x-executable', 'application/x-sharedlib', 'application/x-mach-binary',
        'application/x-elf', 'application/java-archive', 'application/x-shockwave-flash', 'application/vnd.android.package-archive',
    ];

    /** 表示の名前の長さ */
    public const int NAME_MAX = 200;

    private const string ID_PATTERN = '/\A[0-9a-hjkmnp-tv-z]{26}\z/';

    public function __construct(private readonly ListingPanels $panels) {}

    /**
     * この商品のファイルの欄（ファイルを置く App でなければ null）
     *
     * @return array{items: list<array<string, mixed>>, max_files: int, max_bytes: int, chunk_bytes: int, accept: list<string>, used_bytes: int, quota_bytes: int}|null
     */
    public function present(?ListingFilesHandler $handler, Listing $listing): ?array
    {
        if ($handler === null || ! self::applies($handler, $listing)) {
            return null;
        }

        return [
            'items' => array_map(static fn (ListingFile $f): array => $f->toArray(), $handler->files($listing)),
            'max_files' => self::maxFiles($handler),
            'max_bytes' => self::maxBytes($handler),
            'chunk_bytes' => self::chunkBytes(),
            'accept' => array_keys(self::accepts($handler)),
            'used_bytes' => $handler->usedBytes(),
            'quota_bytes' => self::quotaBytes(),
        ];
    }

    /**
     * 上げ始める。名前・大きさ・拡張子・数・店の合計・同時に上げる数を確かめ、上げる記録の ID を返す
     *
     * @return array{upload: string, chunk_bytes: int}
     *
     * @throws ValidationException
     */
    public function start(string $plugin, Listing $listing, mixed $name, mixed $size, string $operator): array
    {
        $handler = $this->handler($plugin, $listing);
        $clean = self::cleanName($name);
        $extension = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
        $bytes = is_int($size) ? $size : (is_string($size) && preg_match('/\A[1-9][0-9]{0,11}\z/', $size) === 1 ? (int) $size : 0);

        $problem = match (true) {
            $clean === '' => 'ファイルの名前を確認してください（拡張子のある名前で、'.self::NAME_MAX.' 文字まで）。',
            $extension === '' || in_array($extension, self::REFUSED_EXTENSIONS, true) || ! array_key_exists($extension, self::accepts($handler)) => 'この形式のファイルはアップロードできません（アップロードできるのは '.implode('・', array_keys(self::accepts($handler))).'）。',
            $bytes < 1 => 'ファイルが空です。',
            $bytes > self::maxBytes($handler) => 'ファイルが大きすぎます（1 つ '.ByteSize::human(self::maxBytes($handler)).' まで）。',
            count($handler->files($listing)) >= self::maxFiles($handler) => 'この商品にアップロードできるファイルは '.self::maxFiles($handler).' 個までです。使わないファイルを削除してからアップロードしてください。',
            $handler->usedBytes() + $bytes > self::quotaBytes() => 'ファイルの保存容量がいっぱいです（店の合計 '.ByteSize::human(self::quotaBytes()).' まで）。使わないファイルを削除してからアップロードしてください。',
            default => null,
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['file' => $problem]);
        }

        $disk = self::disk();
        $folder = self::folder();

        // 同時に上げる数を数えて作るまでを、店ごとの錠の中で（同時に始めても上限を越えない。錠は店の DB の上で、その店の中だけ）
        $id = DB::transaction(function () use ($disk, $folder, $plugin, $listing, $clean, $extension, $bytes, $operator): string {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['listing-file-uploads']);
            $this->sweep($disk, $folder);
            $max = max(1, (int) config('downloads.uploads_at_once'));

            if (count(array_filter($disk->files($folder), static fn (string $f): bool => str_ends_with($f, '.json'))) >= $max) {
                throw ValidationException::withMessages(['file' => '同時にアップロードできるのは '.$max.' 個までです。アップロードが終わってから、もう一度お試しください。']);
            }

            $id = strtolower((string) Str::ulid());
            $disk->put("{$folder}/{$id}.part", '');
            $disk->put("{$folder}/{$id}.json", (string) json_encode([
                'plugin' => $plugin, 'listing' => $listing->id, 'name' => $clean, 'extension' => $extension, 'bytes' => $bytes, 'operator' => $operator, 'created' => CarbonImmutable::now()->getTimestamp(),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $id;
        });

        return ['upload' => $id, 'chunk_bytes' => self::chunkBytes()];
    }

    /**
     * 切れ端を足す（前から順に。受けた大きさと offset が合う時だけ）。受けた合計を返す
     *
     * @throws ValidationException
     * @throws ListingPanelNotFound 上げる記録が無い・期限切れ・ほかの商品か運営の記録
     */
    public function chunk(string $plugin, Listing $listing, string $upload, mixed $offset, mixed $chunk, string $operator): int
    {
        $this->handler($plugin, $listing);

        if (! $chunk instanceof UploadedFile || ! $chunk->isValid()) {
            throw ValidationException::withMessages(['chunk' => 'ファイルの一部を受け取れませんでした。もう一度お試しください。']);
        }

        $at = is_string($offset) && preg_match('/\A(0|[1-9][0-9]{0,11})\z/', $offset) === 1 ? (int) $offset : -1;

        return $this->locked($upload, function (array $state, $handle) use ($plugin, $listing, $at, $chunk, $operator): int {
            $this->assertOwn($state, $plugin, $listing, $operator);
            $size = (int) $chunk->getSize();

            if ($at !== $state['received']) {
                throw ValidationException::withMessages(['offset' => 'ファイルの一部の順番が違います（'.$state['received'].' バイトまで受け取っています）。']);
            }

            if ($size < 1 || $size > self::chunkBytes() || $state['bytes'] < $state['received'] + $size) {
                throw ValidationException::withMessages(['chunk' => 'ファイルの一部のサイズが正しくありません。']);
            }

            $in = fopen((string) $chunk->getRealPath(), 'rb');

            if ($in === false) {
                throw new LogicException('切れ端を開けませんでした');
            }

            try {
                // 開き方は書き足しでない（r+b）ので、終わりへ動いてから書く
                fseek($handle, 0, SEEK_END);
                $written = stream_copy_to_stream($in, $handle);
                fflush($handle);
            } finally {
                fclose($in);
            }

            if ($written !== $size) {
                throw new LogicException('切れ端を書き足せませんでした');
            }

            return $state['received'] + $size;
        });
    }

    /**
     * 上げ終える: 大きさ・中身の型を確かめて App に渡す。断ったら上げる途中のファイルを捨てる
     *
     * @throws ValidationException
     * @throws ListingPanelNotFound
     */
    public function finish(string $plugin, Listing $listing, string $upload, string $operator): void
    {
        $handler = $this->handler($plugin, $listing);

        $this->locked($upload, function (array $state) use ($handler, $plugin, $listing, $upload, $operator): void {
            $this->assertOwn($state, $plugin, $listing, $operator);
            $disk = self::disk();
            $path = self::folder()."/{$upload}.part";

            if ($state['received'] !== $state['bytes'] || ! $disk->exists($path) || $disk->size($path) !== $state['bytes']) {
                throw ValidationException::withMessages(['file' => 'ファイルを全部受け取れていません（'.$state['received'].' / '.$state['bytes'].' バイト）。アップロードし直してください。']);
            }

            try {
                $mime = self::detect($disk->path($path));
                $allowed = self::accepts($handler)[$state['extension']] ?? [];

                if ($mime === null || in_array($mime, self::REFUSED_MIMES, true) || ! in_array($mime, $allowed, true)) {
                    throw ValidationException::withMessages(['file' => 'ファイルの内容が拡張子（.'.$state['extension'].'）と合いません。ファイルを確認してからアップロードし直してください。']);
                }

                $hash = hash_file('sha256', $disk->path($path));
                $handler->store($listing, new ListingFileUpload($path, $state['name'], $state['extension'], $mime, $state['bytes'], is_string($hash) ? $hash : ''), $operator);
            } finally {
                // 移さなかった（断った・落ちた）ら捨てる（移した後は無いので何もしない）。上げる記録も消す（同じ ID でもう一度は受けない）
                $disk->delete([$path, self::folder()."/{$upload}.json"]);
            }
        });
    }

    /**
     * 上げるのをやめる（画面で取り消した・途中で落ちた）
     */
    public function abort(string $plugin, Listing $listing, string $upload, string $operator): void
    {
        $this->handler($plugin, $listing);

        $this->locked($upload, function (array $state) use ($plugin, $listing, $upload, $operator): void {
            $this->assertOwn($state, $plugin, $listing, $operator);
            self::disk()->delete([self::folder()."/{$upload}.part", self::folder()."/{$upload}.json"]);
        });
    }

    public function delete(string $plugin, Listing $listing, string $fileId, string $actor): void
    {
        if (preg_match(self::ID_PATTERN, $fileId) !== 1) {
            throw new ListingPanelNotFound;
        }

        $this->handler($plugin, $listing)->delete($listing, $fileId, $actor);
    }

    /**
     * ファイルを置く App の係（使っていない・欄もファイルも無い・この商品に出さない App は 404）
     */
    public function handler(string $plugin, Listing $listing): ListingFilesHandler
    {
        $definition = $this->panels->definition($plugin);
        $handler = $definition->listingFiles;

        if ($handler === null || ! self::applies($handler, $listing)) {
            throw new ListingPanelNotFound;
        }

        return $handler;
    }

    /**
     * 表示の名前にそろえる: 道筋の区切りの前を捨て、制御文字・向きを変える印を除き、NFC にして前後の空白を取る。拡張子が無い・長すぎるなら空
     */
    public static function cleanName(mixed $name): string
    {
        if (! is_string($name) || ! mb_check_encoding($name, 'UTF-8') || strlen($name) > 1000) {
            return '';
        }

        $base = preg_replace('#\A.*[/\\\\]#su', '', $name) ?? '';
        $base = preg_replace('/[\p{C}]/u', '', $base) ?? '';
        $base = trim((string) (Normalizer::normalize($base, Normalizer::FORM_C) ?: $base));

        if ($base === '' || $base[0] === '.' || mb_strlen($base) > self::NAME_MAX || ! str_contains($base, '.')) {
            return '';
        }

        return $base;
    }

    /**
     * 中身の型（MIME。ファイルの頭の判定）
     */
    public static function detect(string $path): ?string
    {
        $info = finfo_open(FILEINFO_MIME_TYPE);

        if ($info === false) {
            return null;
        }

        $mime = finfo_file($info, $path);

        return is_string($mime) && $mime !== '' ? strtolower($mime) : null;
    }

    public static function disk(): Filesystem
    {
        return Storage::disk('downloads');
    }

    public static function quotaBytes(): int
    {
        return max(1, (int) config('downloads.shop_quota_mb')) * 1024 * 1024;
    }

    public static function chunkBytes(): int
    {
        return max(1, (int) config('downloads.chunk_mb')) * 1024 * 1024;
    }

    /**
     * 店の上げる途中のファイルの場所（店の ID が頭。店の境界）
     */
    private static function folder(): string
    {
        $shop = tenant()?->getTenantKey();

        if (! is_string($shop) || preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $shop) !== 1) {
            throw new LogicException('ファイルは店の文脈の中でだけ受け取る');
        }

        return "uploads/{$shop}";
    }

    private static function hours(): int
    {
        return max(1, (int) config('downloads.upload_hours'));
    }

    private static function maxFiles(ListingFilesHandler $handler): int
    {
        return max(1, min(10, $handler->maxFiles()));
    }

    private static function maxBytes(ListingFilesHandler $handler): int
    {
        return max(1, min($handler->maxBytes(), max(1, (int) config('downloads.max_file_mb')) * 1024 * 1024));
    }

    /**
     * App が宣言した形のうち、核が断る形を除いたもの
     *
     * @return array<string, list<string>>
     */
    private static function accepts(ListingFilesHandler $handler): array
    {
        $out = [];

        foreach ($handler->accepts() as $extension => $mimes) {
            if (preg_match('/\A[a-z0-9]{1,10}\z/', $extension) === 1 && ! in_array($extension, self::REFUSED_EXTENSIONS, true)) {
                $out[$extension] = array_values(array_diff($mimes, self::REFUSED_MIMES));
            }
        }

        return $out;
    }

    /**
     * 今の店の、期限を過ぎた上げる途中のファイルを捨てる（毎日の掃除から。App「ダウンロード販売」の downloads:purge）
     */
    public function sweepShop(): void
    {
        DB::transaction(function (): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['listing-file-uploads']);
            $this->sweep(self::disk(), self::folder());
        });
    }

    /**
     * 期限を過ぎた上げる途中のファイルを捨てる（記録の無いファイル・ファイルの無い記録・記録の作った時刻が古いもの）
     */
    private function sweep(Filesystem $disk, string $folder): void
    {
        $limit = CarbonImmutable::now()->subHours(self::hours())->getTimestamp();
        $files = $disk->files($folder);

        foreach ($files as $file) {
            $base = substr($file, 0, -5);

            if (str_ends_with($file, '.part')) {
                if (! in_array($base.'.json', $files, true)) {
                    $disk->delete($file);
                }

                continue;
            }

            $state = json_decode((string) $disk->get($file), true);
            $created = is_array($state) && is_int($state['created'] ?? null) ? $state['created'] : 0;

            if ($created < $limit || ! in_array($base.'.part', $files, true)) {
                $disk->delete([$file, $base.'.part']);
            }
        }
    }

    /**
     * 上げる途中のファイルの錠（flock）の中で（同じ上げる記録の切れ端を 2 つ同時に送っても、片方は待たずに断り、順に 1 つだけ足す）。
     * 受けた大きさは上げる途中のファイルの大きさ
     *
     * @template T
     *
     * @param  callable(array{plugin: string, listing: string, name: string, extension: string, bytes: int, received: int, operator: string}, resource): T  $work
     * @return T
     */
    private function locked(string $upload, callable $work): mixed
    {
        if (preg_match(self::ID_PATTERN, $upload) !== 1) {
            throw new ListingPanelNotFound;
        }

        $disk = self::disk();
        $meta = self::folder()."/{$upload}.json";
        $part = self::folder()."/{$upload}.part";

        if (! $disk->exists($meta) || ! $disk->exists($part)) {
            throw new ListingPanelNotFound;
        }

        $state = json_decode((string) $disk->get($meta), true);

        if (! is_array($state) || ! is_string($state['plugin'] ?? null) || ! is_string($state['listing'] ?? null) || ! is_string($state['name'] ?? null)
            || ! is_string($state['extension'] ?? null) || ! is_int($state['bytes'] ?? null) || ! is_string($state['operator'] ?? null) || ! is_int($state['created'] ?? null)
            || $state['created'] < CarbonImmutable::now()->subHours(self::hours())->getTimestamp()) {
            throw new ListingPanelNotFound;
        }

        $path = $disk->path($part);
        // 作らずに開く（r+b。消された後に空のファイルを作り直さない）
        $handle = @fopen($path, 'r+b');

        if ($handle === false) {
            throw new ListingPanelNotFound;
        }

        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw ValidationException::withMessages(['chunk' => 'ファイルの前の部分を受信中です。少し待ってから、もう一度お試しください。']);
            }

            // 錠を取る間に、終えた・やめた・掃除した（ファイルを消して同じ名前で作り直された）なら、もう受けない: 記録がまだあり、開いたファイルが今の場所のファイルと同じか
            clearstatcache();
            $opened = fstat($handle);
            $current = @stat($path);

            if (! is_file($disk->path($meta)) || $opened === false || $current === false || $opened['ino'] !== $current['ino'] || $opened['dev'] !== $current['dev']) {
                throw new ListingPanelNotFound;
            }

            return $work([
                'plugin' => $state['plugin'], 'listing' => $state['listing'], 'name' => $state['name'], 'extension' => $state['extension'], 'bytes' => $state['bytes'],
                'received' => $opened['size'], 'operator' => $state['operator'],
            ], $handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param  array{plugin: string, listing: string, operator: string}  $state
     */
    private function assertOwn(array $state, string $plugin, Listing $listing, string $operator): void
    {
        if ($state['plugin'] !== $plugin || $state['listing'] !== $listing->id || ! hash_equals($state['operator'], $operator)) {
            throw new ListingPanelNotFound;
        }
    }

    private static function applies(ListingFilesHandler $handler, Listing $listing): bool
    {
        return ! $handler instanceof ListingPanelScope || $handler->appliesTo($listing);
    }
}
