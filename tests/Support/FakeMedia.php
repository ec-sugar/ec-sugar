<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * テストの偽の写真の置き場（Storage::fake('media') の代わり）。
 *
 * Storage::fake の場所は storage/framework/testing/disks/media に固定で、別の名前のテスト用 DB（ec_test_<文字>）で同時に走らせると、
 * 互いの写真と置き場の札（MediaRoot の .owner）を消し合い、店の片付けが断られる（攻撃テスト第 2 回で起きた）。
 * ここでは場所を MEDIA_DIRECTORY の名前ごとに分ける（既定の media-testing なら …/disks/media-testing）。ほかは Storage::fake と同じく毎回空にする
 */
final class FakeMedia
{
    public static function fake(): FilesystemAdapter
    {
        $root = storage_path('framework/testing/disks/'.basename((string) config('filesystems.disks.media.root')));
        (new Filesystem)->cleanDirectory($root);
        $fake = Storage::createLocalDriver(['throw' => (bool) config('filesystems.disks.media.throw', false), 'root' => $root]);

        if (! $fake instanceof FilesystemAdapter) {
            throw new LogicException('写真の偽の保存先が作れませんでした');
        }

        Storage::set('media', $fake);

        return $fake;
    }
}
