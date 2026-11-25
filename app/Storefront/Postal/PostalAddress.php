<?php

declare(strict_types=1);

namespace App\Storefront\Postal;

/**
 * 郵便番号から引いた住所（都道府県・市区町村・町域）
 */
final readonly class PostalAddress
{
    public function __construct(
        public string $prefecture,
        public string $city,
        public string $town,
    ) {}

    /**
     * @return array{prefecture: string, city: string, town: string}
     */
    public function toArray(): array
    {
        return ['prefecture' => $this->prefecture, 'city' => $this->city, 'town' => $this->town];
    }
}
