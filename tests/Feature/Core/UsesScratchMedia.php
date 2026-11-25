<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * テスト用: 写真の置き場（media）と、開発と本番の既定の置き場を、使い捨ての場所に向ける。
 * 開発の本物の置き場（storage/app/media）に触らずに、置き場どうしの取り違えを試す。
 */
trait UsesScratchMedia
{
    private string $tmp;

    /** @var array<string, mixed> */
    private array $originalMedia;

    private string $originalEnv;

    private function setUpScratchMedia(): void
    {
        $this->tmp = sys_get_temp_dir().'/ec-sugar-media-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->tmp);
        $this->originalMedia = [
            'filesystems.disks.media.root' => config('filesystems.disks.media.root'),
            'filesystems.media_default_root' => config('filesystems.media_default_root'),
        ];
        $this->originalEnv = (string) $this->app['env'];
    }

    private function tearDownScratchMedia(): void
    {
        $this->app['env'] = $this->originalEnv;
        config($this->originalMedia);
        Storage::forgetDisk('media');
        File::deleteDirectory($this->tmp);
    }

    /**
     * media の置き場を使い捨ての場所に向ける（defaultRoot は開発と本番の既定の場所の代わり）
     */
    private function useMedia(string $root, ?string $defaultRoot = null): string
    {
        $path = $this->path($root);
        File::ensureDirectoryExists($path);
        config([
            'filesystems.disks.media.root' => $path,
            'filesystems.media_default_root' => $this->path($defaultRoot ?? 'unused/media'),
        ]);
        Storage::forgetDisk('media');

        return $path;
    }

    private function path(string $relative): string
    {
        return "{$this->tmp}/{$relative}";
    }

    private function place(string $root, string $relative, string $contents = 'webp'): void
    {
        File::ensureDirectoryExists(dirname("{$root}/{$relative}"));
        file_put_contents("{$root}/{$relative}", $contents);
    }
}
