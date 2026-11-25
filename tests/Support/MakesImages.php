<?php

declare(strict_types=1);

namespace Tests\Support;

use GdImage;

/**
 * テスト用の画像を作る。攻撃に使われる形（EXIF の差し込み、縦横の偽り）もここで作る。
 */
trait MakesImages
{
    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height, (int) imagecolorallocate($image, 20, 40, 160));

        return $this->encode($image, 'jpeg');
    }

    /**
     * @param  'jpeg'|'png'|'gif'  $format
     */
    private function encode(GdImage $image, string $format): string
    {
        ob_start();
        match ($format) {
            'jpeg' => imagejpeg($image, null, 90),
            'png' => imagepng($image),
            'gif' => imagegif($image),
        };

        return (string) ob_get_clean();
    }

    /**
     * JPEG の頭（SOI）の直後に EXIF（APP1）を差し込む。説明の文字（0x010E）と向き（0x0112）の 2 項目
     */
    private function withExif(string $jpeg, string $description, int $orientation): string
    {
        $text = $description."\0";
        $tiff = 'II'.pack('v', 42).pack('V', 8)
            .pack('v', 2)
            .pack('vvVV', 0x010E, 2, strlen($text), 8 + 2 + 2 * 12 + 4)
            .pack('vvV', 0x0112, 3, 1).pack('vv', $orientation, 0)
            .pack('V', 0)
            .$text;
        $payload = "Exif\0\0".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
    }

    /**
     * 縦横だけを宣言した PNG（中身は壊れている）
     */
    private function pngHeader(int $width, int $height): string
    {
        $ihdr = pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr)).pack('N', 4).'IDAT'.'xxxx'.pack('N', 0);
    }
}
