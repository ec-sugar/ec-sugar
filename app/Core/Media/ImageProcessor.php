<?php

declare(strict_types=1);

namespace App\Core\Media;

use GdImage;

/**
 * 受け取った画像を作り直す。アップロードはそのまま保存せず、必ずここを通す。
 *
 * - 形式は中身で判定し、JPEG・PNG・WebP だけを受け付ける（拡張子や送られてきた種類は信じない）
 * - 大きさ・縦横・画素数に上限を付ける（展開すると巨大になる画像でメモリを使い切らせない）
 * - GD で一度ほどいて描き直し、WebP で書き出す。位置情報などのメタデータ、後ろに付けた PHP や HTML、
 *   画像に見せかけた別のファイルは、描き直した画素には残らない
 * - JPEG の EXIF の向き（スマホの写真）を画素に反映してから捨てる
 * - JPEG の走査（scan）の数に上限を付ける（プログレッシブ JPEG の走査を何万回も重ねた画像は、小さく縦横も上限の中でも、
 *   ほどくのに分単位で処理を占有する。第 2 回）
 */
final class ImageProcessor
{
    public const int MAX_BYTES = 10 * 1024 * 1024;

    public const int MAX_SIDE = 8000;

    public const int MAX_PIXELS = 25_000_000;

    /** JPEG の走査の数の上限（ふつうのプログレッシブ JPEG は 10 前後） */
    public const int MAX_JPEG_SCANS = 64;

    // 書き出す大きさ（長い辺の最大）。large は商品の詳細、medium は一覧のカード
    public const array VARIANTS = ['large' => 1600, 'medium' => 800];

    // ショップの写真（ヒーローなど横に大きく出す。ADR 0028 区切り 1）
    public const array SHOP_VARIANTS = ['large' => 2000, 'medium' => 1000];

    private const array TYPES = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];

    private const int QUALITY = 82;

    /**
     * @param  array<string, int>  $variants  名前 => 長い辺の上限（既定は商品の写真の VARIANTS）
     * @return array<string, ImageVariant> 名前 => 作り直した画像
     */
    public function process(string $path, array $variants = self::VARIANTS): array
    {
        $size = @filesize($path);

        if ($size === false || $size === 0) {
            throw new InvalidImageException('画像を読み込めませんでした。');
        }

        if ($size > self::MAX_BYTES) {
            throw new InvalidImageException('画像が大きすぎます（10MB まで）。');
        }

        $info = @getimagesize($path);
        $sniffed = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if ($info === false || ! isset(self::TYPES[$info[2]]) || $sniffed !== self::TYPES[$info[2]]) {
            throw new InvalidImageException('JPEG・PNG・WebP の画像を選んでください。');
        }

        [$width, $height] = $info;

        if ($width < 1 || $height < 1 || $width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS) {
            throw new InvalidImageException('画像の縦横が大きすぎます（長い辺 8000px・2500 万画素まで）。');
        }

        $bytes = (string) file_get_contents($path);

        // 走査の始まりの印（FF DA）を数える。符号化された中身では FF の後に 00 が入る決まりなので中身の途中には出ない
        // （メタデータに偶然出ても、断る側に倒れるだけ）
        if ($info[2] === IMAGETYPE_JPEG && substr_count($bytes, "\xFF\xDA") > self::MAX_JPEG_SCANS) {
            throw new InvalidImageException('画像を読み込めませんでした（JPEG の作りが複雑すぎます）。');
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            throw new InvalidImageException('画像が壊れているか、読み込めない形式です。');
        }

        // GD の画像は使い終わると自動で片付く（PHP 8.0 から。imagedestroy は 8.5 で非推奨）
        if ($info[2] === IMAGETYPE_JPEG) {
            $image = $this->orient($image, $path);
        }

        $result = [];

        foreach ($variants as $name => $longest) {
            $result[$name] = $this->encode($image, $longest);
        }

        return $result;
    }

    /**
     * EXIF の向き（1〜8）を画素に反映する。読めない・無いときはそのまま
     */
    private function orient(GdImage $image, string $path): GdImage
    {
        $exif = function_exists('exif_read_data') ? @exif_read_data($path) : false;
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3, 4 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, -90, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };

        if (! $rotated instanceof GdImage) {
            return $image;
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($rotated, IMG_FLIP_HORIZONTAL);
        }

        return $rotated;
    }

    private function encode(GdImage $source, int $longest): ImageVariant
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, $longest / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        // 透明を保ったまま描き直す（PNG・WebP の透過）
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        $ok = imagewebp($canvas, null, self::QUALITY);
        $bytes = (string) ob_get_clean();

        if (! $ok || $bytes === '') {
            throw new InvalidImageException('画像を書き出せませんでした。');
        }

        return new ImageVariant($bytes, $targetWidth, $targetHeight);
    }
}
