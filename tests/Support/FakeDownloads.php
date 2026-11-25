<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * テストの偽の売るファイルの置き場（downloads）。FakeMedia と同じく、場所を置き場の名前（MEDIA_DIRECTORY から決まる）ごとに分け、
 * 別の名前のテスト用 DB で同時に走らせても互いのファイルを消し合わない。毎回空にする
 */
final class FakeDownloads
{
    public static function fake(): FilesystemAdapter
    {
        $root = storage_path('framework/testing/disks/'.basename((string) config('filesystems.disks.downloads.root')));
        (new Filesystem)->ensureDirectoryExists($root);
        (new Filesystem)->cleanDirectory($root);
        $fake = Storage::createLocalDriver(['throw' => true, 'root' => $root]);

        if (! $fake instanceof FilesystemAdapter) {
            throw new LogicException('売るファイルの偽の保存先が作れませんでした');
        }

        Storage::set('downloads', $fake);

        return $fake;
    }
}
