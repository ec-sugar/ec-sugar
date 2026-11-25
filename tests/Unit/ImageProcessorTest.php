<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Media\ImageProcessor;
use App\Core\Media\InvalidImageException;
use GdImage;
use PHPUnit\Framework\TestCase;
use Tests\Support\MakesImages;

/**
 * 写真の作り直し。攻撃に使われる形のファイルを実際に作って試す。
 */
final class ImageProcessorTest extends TestCase
{
    use MakesImages;

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_a_large_photo_becomes_two_webp_sizes(): void
    {
        $variants = (new ImageProcessor)->process($this->file($this->jpeg(3000, 1500)));

        $this->assertSame(['large', 'medium'], array_keys($variants));
        $this->assertSame([1600, 800], [$variants['large']->width, $variants['large']->height]);
        $this->assertSame([800, 400], [$variants['medium']->width, $variants['medium']->height]);

        foreach ($variants as $variant) {
            $this->assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->buffer($variant->bytes));
        }
    }

    public function test_a_small_photo_is_not_enlarged_and_png_transparency_is_kept(): void
    {
        $png = imagecreatetruecolor(40, 30);
        imagealphablending($png, false);
        imagesavealpha($png, true);
        imagefill($png, 0, 0, (int) imagecolorallocatealpha($png, 255, 0, 0, 127));

        $variants = (new ImageProcessor)->process($this->file($this->encode($png, 'png')));

        $this->assertSame([40, 30], [$variants['large']->width, $variants['large']->height]);
        $decoded = imagecreatefromstring($variants['medium']->bytes);
        $this->assertInstanceOf(GdImage::class, $decoded);
        $this->assertGreaterThan(100, (imagecolorat($decoded, 5, 5) >> 24) & 0x7F, '透明が残る');
    }

    public function test_a_jpeg_with_too_many_scans_is_refused_before_decoding(): void
    {
        // プログレッシブ JPEG の最後の走査を、終わりの印の手前に何度も重ねる（小さく縦横も上限の中だが、ほどくのに処理を長く占有する。第 2 回）
        $image = imagecreatetruecolor(64, 64);
        imageinterlace($image, true);
        $jpeg = $this->encode($image, 'jpeg');
        $body = substr($jpeg, 0, -2);
        $scan = substr($body, (int) strrpos($body, "\xFF\xDA"));
        $this->assertLessThan(ImageProcessor::MAX_JPEG_SCANS, substr_count($jpeg, "\xFF\xDA"), 'ふつうのプログレッシブ JPEG は通る');
        (new ImageProcessor)->process($this->file($jpeg));

        $bomb = $body.str_repeat($scan, ImageProcessor::MAX_JPEG_SCANS)."\xFF\xD9";
        $started = microtime(true);
        $this->expectException(InvalidImageException::class);

        try {
            (new ImageProcessor)->process($this->file($bomb));
        } finally {
            $this->assertLessThan(1.0, microtime(true) - $started, 'ほどく前に断る');
        }
    }

    public function test_metadata_and_appended_code_do_not_survive(): void
    {
        // EXIF に目印の文字（位置情報の代わり）を入れ、後ろに PHP と HTML を付けた JPEG（画像としても読めてしまうもの）
        $marker = 'SECRET-GPS-35.6895-139.6917';
        $bytes = $this->withExif($this->jpeg(64, 48), $marker, 1).'<?php system($_GET["c"]); ?><script>alert(1)</script>';

        $variants = (new ImageProcessor)->process($this->file($bytes));

        foreach ($variants as $variant) {
            $this->assertStringNotContainsString($marker, $variant->bytes);
            $this->assertStringNotContainsString('<?php', $variant->bytes);
            $this->assertStringNotContainsString('<script', $variant->bytes);
            $this->assertStringNotContainsString('Exif', $variant->bytes);
        }
    }

    public function test_the_exif_orientation_of_a_phone_photo_is_applied(): void
    {
        // 横 40・縦 20 で撮り、「右に 90 度回して見る」（6）と書かれた写真は、縦長になる
        $variants = (new ImageProcessor)->process($this->file($this->withExif($this->jpeg(40, 20), 'phone', 6)));

        $this->assertSame([20, 40], [$variants['large']->width, $variants['large']->height]);
    }

    public function test_files_that_are_not_allowed_images_are_refused(): void
    {
        $gif = imagecreatetruecolor(10, 10);
        $cases = [
            '画像でない文字' => 'hello, this is not an image',
            'SVG' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="10" height="10"/></svg>',
            'GIF' => $this->encode($gif, 'gif'),
            'JPEG の頭だけの壊れたもの' => "\xFF\xD8\xFF\xE0".random_bytes(200),
            '空' => '',
            // 縦横を巨大に偽った PNG（開くとメモリを食い尽くす）。中身を開く前に断る
            '画素数が多すぎる' => $this->pngHeader(5001, 5001),
            '辺が長すぎる' => $this->pngHeader(8001, 10),
            '10MB を超える' => $this->jpeg(10, 10).str_repeat("\0", ImageProcessor::MAX_BYTES),
        ];

        foreach ($cases as $name => $bytes) {
            try {
                (new ImageProcessor)->process($this->file($bytes));
                $this->fail("{$name} を受け付けてしまった");
            } catch (InvalidImageException $e) {
                $this->assertNotSame('', $e->getMessage(), $name);
            }
        }
    }

    private function file(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, $bytes);
        $this->files[] = $path;

        return $path;
    }
}
