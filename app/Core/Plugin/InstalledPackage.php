<?php

declare(strict_types=1);

namespace App\Core\Plugin;

/**
 * 入っているプラグインかテーマ 1 つ（composer が入れたときの記録から読んだもの）。
 * 文はパッケージの作り手が書いたものなので、画面では文字として出すだけにする
 */
final readonly class InstalledPackage
{
    /**
     * @param  'theme'|'plugin'|null  $kind  マニフェストの種類（読めなければ null）
     * @param  list<string>  $authors
     * @param  list<string>  $licenses
     * @param  ?string  $problem  マニフェストのおかしな所（無ければ null）
     */
    public function __construct(
        public string $package,
        public ?string $kind,
        public ?string $name,
        public string $version,
        public ?string $description,
        public array $authors,
        public array $licenses,
        public ?string $core,
        public bool $compatible,
        public bool $firstParty,
        public ?string $problem,
    ) {}
}
