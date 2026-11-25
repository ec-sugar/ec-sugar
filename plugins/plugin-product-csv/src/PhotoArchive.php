<?php

declare(strict_types=1);

namespace EcSugar\PluginProductCsv;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Normalizer;
use RuntimeException;
use ZipArchive;

/**
 * 商品の CSV と一緒に上げる写真の ZIP（docs/design/product-csv.md の「写真」）。上げた要求の中で展開し、反映のキューの仕事が読む。
 *
 * - ZIP は 90 MB まで（入口の nginx と Cloudflare の 1 回の要求の上限 100 MB に収める）・中のファイルは 2,500 個まで・展開した合計は 300 MB まで
 *   （ZIP 爆弾: 書いてある大きさを信じず、読みながら数えて超えたら全部を断る）・1 枚は 10 MB まで
 * - ZIP の中の名前は道筋として使わない（extractTo を使わない）。置くファイルの名前はこちらが決める番号だけ。名前は CSV と照らすためだけに使い、
 *   `..`・絶対の道筋・ドライブ名・制御文字の名前、シンボリックリンク、暗号化したファイル、同じ名前の 2 つ目は「使えない理由」つきで置かない
 * - 中身は JPEG・PNG・WebP として読めるものだけを置く（入れるときは ImageProcessor で作り直す）。macOS が足す __MACOSX/・._*・.DS_Store は黙って飛ばす
 * - 置き場は写真の置き場（media）の tenants/{店}/imports/{archive}/。店を消すと店の写真と一緒に消える。取り込みが済んだ・断った・古くなったら消す
 * - 展開の途中で断ったときは、書いたファイルを消す
 *
 * 中身の一覧（archive_files）: ZIP の中の名前（NFC にそろえた UTF-8） => ['file' => 置いたファイルの番号, 'digest' => 中身の SHA-256] か ['problem' => 使えない理由]
 */
final class PhotoArchive
{
    /** ZIP の大きさの上限 */
    public const int MAX_BYTES = 90 * 1024 * 1024;

    /** 中のファイルの数（フォルダも数える） */
    public const int MAX_ENTRIES = 2_500;

    /** 展開した合計の上限（ZIP 爆弾よけ。テストで小さくする） */
    public static int $maxTotal = 300 * 1024 * 1024;

    /** 1 枚の上限（画像の処理と同じ） */
    public const int MAX_IMAGE = 10 * 1024 * 1024;

    private const int MAX_NAME = 255;

    /**
     * 展開して置く。断るときは ValidationException（photos の欄）で、何も残さない
     *
     * @return array<string, array{file: string, digest: string}|array{problem: string}>
     *
     * @throws ValidationException
     */
    public function extract(string $zipPath, string $archive): array
    {
        $size = @filesize($zipPath);

        if ($size === false || $size === 0) {
            throw self::reject('ZIP のファイルを読めません。');
        }

        if ($size > self::MAX_BYTES) {
            throw self::reject('ZIP が大きすぎます。1 回に '.intdiv(self::MAX_BYTES, 1024 * 1024).'MB までです。分けて取り込むか、写真の URL を使ってください。');
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw self::reject('ZIP のファイルとして読めません（壊れているか、ZIP ではありません）。');
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw self::reject('ZIP の中のファイルが多すぎます。1 回に '.number_format(self::MAX_ENTRIES).' 個までです。');
            }

            return $this->place($zip, $archive);
        } catch (ValidationException $e) {
            $this->delete($archive);

            throw $e;
        } finally {
            $zip->close();
        }
    }

    /**
     * 置いた写真を一時のファイルに写して道筋を返す（使い終わったら呼ぶ側が消す）
     *
     * @throws RuntimeException 置いたファイルが無い
     */
    public function copyOut(string $archive, string $file): string
    {
        $stream = $this->disk()->readStream($this->directory($archive).'/'.self::fileName($file));

        if (! is_resource($stream)) {
            throw new RuntimeException('ZIP から置いた写真が見つかりません。');
        }

        $path = self::temporary();
        $out = fopen($path, 'wb');

        try {
            if ($out === false || stream_copy_to_stream($stream, $out) === false) {
                throw new RuntimeException('ZIP から置いた写真を読めません。');
            }
        } finally {
            fclose($stream);

            if ($out !== false) {
                fclose($out);
            }
        }

        return $path;
    }

    public function delete(string $archive): void
    {
        $this->disk()->deleteDirectory($this->directory($archive));
    }

    /**
     * CSV に書いた名前から、中身の一覧の 1 つを探す（そのままの名前 → フォルダを除いた名前が 1 つだけ当たるとき）
     *
     * @param  array<string, array{file?: string, digest?: string, problem?: string}>  $files
     * @return array{file?: string, digest?: string, problem?: string}|null
     */
    public static function lookup(array $files, string $name): ?array
    {
        $name = self::normalize($name);

        if (isset($files[$name])) {
            return $files[$name];
        }

        $found = array_values(array_filter($files, static fn (string $entry): bool => basename($entry) === $name, ARRAY_FILTER_USE_KEY));

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * 名前を比べられる形にする（\ を / に、頭の ./ を外し、NFC にそろえる。macOS の ZIP は名前を NFD で書く）
     */
    public static function normalize(string $name): string
    {
        $name = str_replace('\\', '/', trim($name));

        while (str_starts_with($name, './')) {
            $name = substr($name, 2);
        }

        $normalized = Normalizer::normalize($name, Normalizer::FORM_C);

        return is_string($normalized) ? $normalized : $name;
    }

    /**
     * 名前が道筋として危なくないか（ZIP の中の名前と CSV に書いた名前の両方に使う）。駄目なら理由
     */
    public static function nameProblem(string $name): ?string
    {
        if ($name === '' || strlen($name) > self::MAX_NAME || preg_match('/[\x00-\x1f\x7f]/', $name) === 1 || ! mb_check_encoding($name, 'UTF-8')) {
            return 'ファイル名が使えない形です（'.self::MAX_NAME.' バイトまで。改行や見えない文字は使えません）。';
        }

        if (str_starts_with($name, '/') || preg_match('#\A[A-Za-z]:#', $name) === 1 || in_array('..', explode('/', $name), true)) {
            return 'ファイル名に「..」や、「/」で始まる書き方は使えません（ZIP の中のファイル名をそのまま書いてください）。';
        }

        return null;
    }

    /**
     * @return array<string, array{file: string, digest: string}|array{problem: string}>
     *
     * @throws ValidationException
     */
    private function place(ZipArchive $zip, string $archive): array
    {
        $files = [];
        $total = 0;
        $placed = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $raw = $zip->getNameIndex($index, ZipArchive::FL_ENC_RAW);
            $stat = $zip->statIndex($index, ZipArchive::FL_ENC_RAW);

            if (! is_string($raw) || $stat === false) {
                throw self::reject('ZIP の中のファイルの一覧を読めません。');
            }

            $decoded = self::decode($raw);
            $name = $decoded === null ? null : self::normalize($decoded);

            // フォルダと、macOS が足すもの
            if ($name !== null && (str_ends_with($name, '/') || self::isJunk($name))) {
                continue;
            }

            $problem = match (true) {
                $name === null => 'ファイル名の文字コードを読み取れません。',
                default => self::nameProblem($name),
            };
            $key = $name ?? '#'.$index;

            if ($problem === null && isset($files[$key])) {
                $problem = 'ZIP の中に同じ名前のファイルが 2 つあります。';
                $key .= '#'.$index;
            }

            $problem ??= self::entryProblem($zip, $index, $stat);

            if ($problem !== null) {
                $files[$key] = ['problem' => $problem];

                continue;
            }

            [$file, $digest, $bytes, $problem] = $this->copyEntry($zip, $index, $archive, ++$placed, self::$maxTotal - $total);
            $total += $bytes;
            $files[$key] = $problem === null ? ['file' => $file, 'digest' => $digest] : ['problem' => $problem];
        }

        return $files;
    }

    /**
     * 読む前に分かる使えない理由（シンボリックリンク・暗号化・書いてある大きさ）
     *
     * @param  array<string, mixed>  $stat
     */
    private static function entryProblem(ZipArchive $zip, int $index, array $stat): ?string
    {
        $system = 0;
        $attributes = 0;

        if ($zip->getExternalAttributesIndex($index, $system, $attributes) && $system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0o170000) === 0o120000) {
            return 'シンボリックリンクは使えません。';
        }

        if ((int) ($stat['encryption_method'] ?? 0) !== ZipArchive::EM_NONE) {
            return 'パスワード付きの（暗号化した）ファイルは使えません。';
        }

        if ((int) ($stat['size'] ?? 0) > self::MAX_IMAGE) {
            return '大きすぎます（1 枚 '.intdiv(self::MAX_IMAGE, 1024 * 1024).' MB まで）。';
        }

        return null;
    }

    /**
     * 1 つを読みながら一時のファイルに書き（大きさを数える）、画像なら置き場に置く
     *
     * @return array{0: string, 1: string, 2: int, 3: string|null} 置いたファイルの番号・中身のハッシュ・読んだ大きさ・使えない理由
     *
     * @throws ValidationException 展開した合計が上限を超えた（ZIP 爆弾）
     */
    private function copyEntry(ZipArchive $zip, int $index, string $archive, int $number, int $budget): array
    {
        $stream = $zip->getStreamIndex($index);

        if ($stream === false) {
            return ['', '', 0, 'ZIP の中のファイルを読めません。'];
        }

        $path = self::temporary();
        $out = fopen($path, 'wb');
        $bytes = 0;

        try {
            if ($out === false) {
                throw new RuntimeException('一時保存先に書けません');
            }

            while (! feof($stream)) {
                $chunk = fread($stream, 65_536);

                if ($chunk === false) {
                    return ['', '', $bytes, 'ZIP の中のファイルを読めません（壊れています）。'];
                }

                $bytes += strlen($chunk);

                if ($bytes > $budget) {
                    throw self::reject('ZIP を展開すると大きすぎます（合計 '.intdiv(self::$maxTotal, 1024 * 1024).'MB まで）。');
                }

                if ($bytes > self::MAX_IMAGE) {
                    return ['', '', $bytes, '大きすぎます（1 枚 '.intdiv(self::MAX_IMAGE, 1024 * 1024).' MB まで）。'];
                }

                fwrite($out, $chunk);
            }

            fclose($out);
            $out = false;
            $info = $bytes > 0 ? @getimagesize($path) : false;

            if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
                return ['', '', $bytes, '画像として読めません（JPEG・PNG・WebP だけ）。'];
            }

            $file = sprintf('%05d', $number);
            $digest = (string) hash_file('sha256', $path);
            $in = fopen($path, 'rb');

            if ($in === false) {
                throw new RuntimeException('一時保存先を読めません');
            }

            try {
                $this->disk()->writeStream($this->directory($archive).'/'.self::fileName($file), $in);
            } finally {
                if (is_resource($in)) {
                    fclose($in);
                }
            }

            return [$file, $digest, $bytes, null];
        } finally {
            fclose($stream);

            if (is_resource($out)) {
                fclose($out);
            }

            @unlink($path);
        }
    }

    /**
     * ZIP の中の名前の文字を UTF-8 にする（UTF-8 でなければ、日本の Windows の ZIP の Shift_JIS〔CP932〕として読む）
     */
    private static function decode(string $raw): ?string
    {
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        if (mb_check_encoding($raw, 'CP932')) {
            $converted = mb_convert_encoding($raw, 'UTF-8', 'CP932');

            return mb_check_encoding($converted, 'UTF-8') ? $converted : null;
        }

        return null;
    }

    private static function isJunk(string $name): bool
    {
        $base = basename($name);

        return str_starts_with($name, '__MACOSX/') || str_starts_with($base, '._') || in_array($base, ['.DS_Store', 'Thumbs.db', 'desktop.ini'], true);
    }

    private function directory(string $archive): string
    {
        $tenant = tenant()?->getTenantKey();

        if (! is_string($tenant) || preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $tenant) !== 1) {
            throw new LogicException('写真の ZIP は店の文脈の中でだけ扱う');
        }

        if (preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $archive) !== 1) {
            throw new LogicException('保存先の名前の形が違う');
        }

        return "tenants/{$tenant}/imports/{$archive}";
    }

    private static function fileName(string $file): string
    {
        if (preg_match('/\A[0-9]{5}\z/', $file) !== 1) {
            throw new LogicException('置いたファイルの名前の形が違う');
        }

        return $file.'.img';
    }

    private static function temporary(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ecimg');

        if ($path === false) {
            throw new RuntimeException('一時保存先を作れません');
        }

        return $path;
    }

    private function disk(): Filesystem
    {
        return Storage::disk('media');
    }

    private static function reject(string $message): ValidationException
    {
        return ValidationException::withMessages(['photos' => $message]);
    }
}
