<?php

declare(strict_types=1);

namespace App\Core\Plugin\Manifest;

/**
 * App がショップで読みたいスクリプト 1 つ（ADR 0034 の 1）。ファイルは App のパッケージの assets/ の中の .js だけで、
 * 核が同じオリジンの決まった経路（/apps/<App>/<ファイル>）から配る。$path は登録のときに確かめた実体の道筋
 */
final readonly class AppScript
{
    /**
     * @param  list<PageKind>  $pages  差し込む画面の種類（個人情報の画面は入らない）
     */
    public function __construct(
        public string $file,
        public array $pages,
        public string $path,
    ) {}

    public function on(PageKind $kind): bool
    {
        return in_array($kind, $this->pages, true);
    }
}
