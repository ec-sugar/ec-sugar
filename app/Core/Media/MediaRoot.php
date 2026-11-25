<?php

declare(strict_types=1);

namespace App\Core\Media;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * 写真の置き場（media）がこの環境のものかを確かめる。
 *
 * 店の ID は開発もテストも短い固定の名前（demo など）なので、置き場を共有すると、ある環境で店を消したときに
 * 別の環境の同じ ID の店の写真（tenants/{id}）まで消える。置き場は環境ごとに分ける（MEDIA_DIRECTORY）が、
 * 設定を忘れても消せないように、消す前に 2 つを確かめる:
 *
 * 1. テストの環境（APP_ENV=testing）では、開発と本番の既定の置き場（filesystems.media_default_root）を使わせない。
 *    phpunit.xml の MEDIA_DIRECTORY を忘れた形を、何も書かずに断る
 * 2. 置き場の持ち主の札（.owner）。中身は店の DB の名前の形（接頭辞 * 接尾辞）で、店の ID が指す DB の集まりを表す。
 *    札が別の形なら、別の環境の置き場なので断る。札が無ければこの環境の札を付ける（今ある開発の置き場もそのまま使える）。
 *    APP_ENV が local のブラウザのテスト（店の DB は ec_e2e_t_*）が開発の置き場を指した形も、ここで止まる
 *
 * 断るのは消す前で、そのときは何も書かない。確かめるのは置き場の場所だけで、利用者の入力は使わない。
 */
final class MediaRoot
{
    public const string OWNER_FILE = '.owner';

    public function __construct(
        private readonly Filesystem $disk,
        private readonly string $owner,
        private readonly bool $testing,
        private readonly string $defaultRoot,
    ) {}

    public static function current(): self
    {
        return new self(
            Storage::disk('media'),
            self::ownerFor((string) config('tenancy.database.prefix'), (string) config('tenancy.database.suffix')),
            app()->environment('testing'),
            (string) config('filesystems.media_default_root'),
        );
    }

    /**
     * 札の中身。店の DB の名前の形（例: 開発 ec_t_*、テスト ec_test_t_*、ブラウザのテスト ec_e2e_t_*）
     */
    public static function ownerFor(string $prefix, string $suffix): string
    {
        return "tenant-databases: {$prefix}*{$suffix}\n";
    }

    public function owner(): string
    {
        return $this->owner;
    }

    /**
     * この置き場の店の写真を消してよいかを確かめる。札が無ければこの環境の札を付ける
     *
     * @throws ForeignMediaRootException
     */
    public function ensureOwned(): void
    {
        if ($this->testing && $this->isDefaultRoot()) {
            throw new ForeignMediaRootException(
                'テストの環境で、写真の保存先が開発と本番の既定の場所のままです。開発の店の写真を消さないように断りました。'
                .'テストには MEDIA_DIRECTORY（phpunit.xml の media-testing など）を設定してください。'
            );
        }

        if (! $this->disk->exists(self::OWNER_FILE)) {
            $this->disk->put(self::OWNER_FILE, $this->owner);

            return;
        }

        $marked = $this->disk->get(self::OWNER_FILE);

        if ($marked !== $this->owner) {
            throw new ForeignMediaRootException(
                '写真の保存先が別の環境のものです（保存先のマーク '.trim((string) $marked).'、この環境 '.trim($this->owner).'）。'
                .'別の環境の店の写真を消さないように断りました。環境ごとに MEDIA_DIRECTORY を分けてください。'
            );
        }
    }

    private function isDefaultRoot(): bool
    {
        return self::normalize($this->disk->path('')) === self::normalize($this->defaultRoot);
    }

    private static function normalize(string $path): string
    {
        $real = realpath($path);

        return rtrim($real === false ? $path : $real, '/');
    }
}
