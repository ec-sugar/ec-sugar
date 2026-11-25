<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use DateTimeInterface;

/**
 * 商品の画面の App の欄に並べるファイル 1 つ（ListingFilesHandler）。名前は店の人が上げた時の名前（表示だけ。置き場の名前は作り直してある）
 */
final readonly class ListingFile
{
    public function __construct(
        public string $id,
        public string $name,
        public int $bytes,
        public string $extension,
        public DateTimeInterface $createdAt,
    ) {}

    /**
     * @return array{id: string, name: string, bytes: int, extension: string, created_at: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'bytes' => $this->bytes, 'extension' => $this->extension, 'created_at' => $this->createdAt->format(DATE_ATOM)];
    }
}
