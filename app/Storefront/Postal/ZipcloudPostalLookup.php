<?php

declare(strict_types=1);

namespace App\Storefront\Postal;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * zipcloud（株式会社アイビス）の郵便番号検索 API。利用規約: https://zipcloud.ibsnet.co.jp/rule/api
 * - 保証は無く、予告なく止まることがある → 3 秒で諦めて空を返す
 * - 最新の情報を出す義務がある → 覚えるのは 1 日
 * - 返ってきた中身は信じすぎない（文字だけ、長さの上限、47 都道府県のどれか）
 */
final class ZipcloudPostalLookup implements PostalLookup
{
    private const string ENDPOINT = 'https://zipcloud.ibsnet.co.jp/api/search';

    public function find(string $code): array
    {
        if (preg_match('/\A[0-9]{7}\z/', $code) !== 1) {
            return [];
        }

        /** @var list<array{prefecture: string, city: string, town: string}>|null $cached */
        $cached = Cache::get("postal:{$code}");

        if (is_array($cached)) {
            return array_map(static fn (array $a): PostalAddress => new PostalAddress($a['prefecture'], $a['city'], $a['town']), $cached);
        }

        try {
            $response = Http::timeout(3)->connectTimeout(2)->acceptJson()->get(self::ENDPOINT, ['zipcode' => $code]);
        } catch (ConnectionException $e) {
            Log::warning('郵便番号の検索に失敗（つながらない）', ['error' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful() || ! is_array($response->json('results'))) {
            // 見つからない（results が null）も、ここに来る。止まっているときと区別せず空にする
            return [];
        }

        $addresses = [];

        foreach (array_slice((array) $response->json('results'), 0, 20) as $row) {
            $address = $this->parse($row);

            if ($address !== null) {
                $addresses[] = $address;
            }
        }

        Cache::put("postal:{$code}", array_map(static fn (PostalAddress $a): array => $a->toArray(), $addresses), now()->addDay());

        return $addresses;
    }

    private function parse(mixed $row): ?PostalAddress
    {
        if (! is_array($row)) {
            return null;
        }

        $text = static function (mixed $value): ?string {
            return is_string($value) && mb_strlen($value) <= 100 && preg_match('/[\x00-\x1F\x7F<>]/u', $value) !== 1 ? $value : null;
        };
        [$prefecture, $city, $town] = [$text($row['address1'] ?? null), $text($row['address2'] ?? null), $text($row['address3'] ?? null)];

        if ($prefecture === null || ! in_array($prefecture, Prefectures::ALL, true) || $city === null || $city === '' || $town === null) {
            return null;
        }

        return new PostalAddress($prefecture, $city, $town);
    }
}
