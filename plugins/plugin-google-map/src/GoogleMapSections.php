<?php

declare(strict_types=1);

namespace EcSugar\PluginGoogleMap;

use App\Core\Plugin\Sections\AppSectionHandler;
use App\Core\Plugin\Sections\Embed;

/**
 * 地図の区画の係（ADR 0040 の 6。2026-10-30 にキーの要らない埋め込みへ置き換え）。店の人が入れた場所（住所か場所の名前）から、
 * Google マップの埋め込みの URL（https://www.google.com/maps?q=…&output=embed）を作る。API キーは要らない。
 *
 * - この形は Google の公式の資料に無く、予告なく止まりうる（運営が承知のうえで選んだ）。止まったら Maps Embed API（キー）へ戻す（手順は ADR 0040 の 6）
 * - 場所は文字のまま URL の値として符号化して渡す（rawurlencode。URL の形を壊せない）。地図の URL（https://…）を貼られたら断る
 *   （場所の文字を探す形なので、URL では地図にならない）
 * - 外で開くリンクは Google マップの検索の URL（Maps URLs。公式の形）
 */
final class GoogleMapSections implements AppSectionHandler
{
    public const string TYPE = 'app_google_map';

    public function problems(string $type, array $values): array
    {
        $place = $values['place'] ?? null;

        if (is_string($place) && preg_match('~\A\s*(?:[a-z][a-z0-9+.-]*://|javascript:|data:|//|www\.)~i', $place) === 1) {
            return ['place' => '地図の URL ではなく、住所か場所の名前を文字で入れてください（例: 東京都千代田区丸の内1-9-1）'];
        }

        return [];
    }

    public function embed(string $type, array $values, array $settings): ?Embed
    {
        $place = is_string($values['place'] ?? null) ? trim($values['place']) : '';

        if ($place === '' || $this->problems($type, ['place' => $place]) !== []) {
            return null;
        }

        $query = rawurlencode($place);

        return new Embed(
            "https://www.google.com/maps?q={$query}&output=embed&hl=ja",
            '地図: '.$place,
            "https://www.google.com/maps/search/?api=1&query={$query}",
        );
    }
}
