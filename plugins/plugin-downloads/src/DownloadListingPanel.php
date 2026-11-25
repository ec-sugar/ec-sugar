<?php

declare(strict_types=1);

namespace EcSugar\PluginDownloads;

use App\Core\Event\EventStore;
use App\Core\Listing\Models\Listing;
use App\Core\Plugin\Contracts\ListingPanelHandler;
use App\Core\Plugin\Contracts\ListingPanelScope;
use App\Core\Plugin\Manifest\SettingSpec;
use App\Core\Plugin\Manifest\SettingType;
use EcSugar\PluginDownloads\Models\DownloadFile;
use EcSugar\PluginDownloads\Models\DownloadGrant;
use EcSugar\PluginDownloads\Models\DownloadListing;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 商品の画面の App の欄「ダウンロード販売」（ADR 0084）。商品の扱いが「ダウンロード」の商品だけに出す（ListingPanelScope）。
 * 欄は期限の日数と回数（空なら App の設定）。ファイルは同じ欄の下に核の部品が並べる（DownloadFiles）。
 * 変えた値は、それ以降に払われた注文のリンクにだけ効く（もう渡したリンクは、注文の画面で店の人が回数を戻す・期限を延ばす）
 */
final class DownloadListingPanel implements ListingPanelHandler, ListingPanelScope
{
    public const string KIND = 'download';

    public function appliesTo(Listing $listing): bool
    {
        return $listing->kind === self::KIND;
    }

    public function fields(): array
    {
        return [
            new SettingSpec(key: 'days', type: SettingType::Text, label: 'ダウンロードの期限（お支払いからの日数）',
                help: '1〜365 日。空なら App の設定（'.app(DownloadSettings::class)->days().' 日）を使います。', pattern: '[1-9][0-9]{0,2}', max: 3),
            new SettingSpec(key: 'max_count', type: SettingType::Text, label: '1 つのファイルをダウンロードできる回数',
                help: '1〜100 回。空なら App の設定（'.app(DownloadSettings::class)->limit().' 回）を使います。', pattern: '[1-9][0-9]{0,2}', max: 3),
        ];
    }

    public function read(Listing $listing): array
    {
        $row = DownloadListing::query()->find($listing->id);

        return ['days' => $row?->days === null ? null : (string) $row->days, 'max_count' => $row?->max_count === null ? null : (string) $row->max_count];
    }

    public function note(Listing $listing): string
    {
        $files = DownloadFile::query()->where('listing_id', $listing->id)->whereNull('removed_at')->count();
        $sold = DownloadGrant::query()->whereIn('file_id', DownloadFile::query()->select('id')->where('listing_id', $listing->id))->distinct()->count('transaction_id');

        return ($files === 0 ? 'まだファイルがありません。下の「ファイルを上げる」から、販売するファイルをアップロードしてください（ファイルが無いと、購入したお客さんに何も届きません）。' : "販売するファイルは {$files} つです。")
            ."これまで {$sold} 件の注文にダウンロードのリンクを送りました。お支払いが確認できると、ご注文の確認の画面・マイページ・メールにダウンロードのリンクが表示されます。"
            .'ダウンロードの商品には送料と在庫がありません（1 回のご注文で 1 点まで）。';
    }

    public function write(Listing $listing, array $values, string $actor): void
    {
        $next = [
            'days' => self::number($values['days'] ?? null, DownloadSettings::MAX_DAYS),
            'max_count' => self::number($values['max_count'] ?? null, DownloadSettings::MAX_LIMIT),
        ];

        foreach (['days' => DownloadSettings::MAX_DAYS, 'max_count' => DownloadSettings::MAX_LIMIT] as $key => $max) {
            if (($values[$key] ?? null) !== null && $next[$key] === null) {
                throw ValidationException::withMessages([$key => "1〜{$max} の数字で入れてください。"]);
            }
        }

        DB::transaction(function () use ($listing, $next, $actor): void {
            $events = app(EventStore::class);
            $events->lock();
            $row = DownloadListing::query()->whereKey($listing->id)->lockForUpdate()->first();

            if ($row !== null && $row->days === $next['days'] && $row->max_count === $next['max_count']) {
                return;
            }

            $row ??= new DownloadListing(['listing_id' => $listing->id]);
            $row->days = $next['days'];
            $row->max_count = $next['max_count'];
            $row->save();
            $events->append('download.listing_updated', 'listing', $listing->id, $next, $actor);
        });
    }

    private static function number(mixed $value, int $max): ?int
    {
        if (! is_string($value) || preg_match('/\A[1-9][0-9]{0,2}\z/', $value) !== 1) {
            return null;
        }

        $number = (int) $value;

        return $number <= $max ? $number : null;
    }
}
