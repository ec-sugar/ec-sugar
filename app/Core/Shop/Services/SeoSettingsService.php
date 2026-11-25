<?php

declare(strict_types=1);

namespace App\Core\Shop\Services;

use App\Core\Event\EventStore;
use App\Core\Media\Models\ShopMedia;
use App\Core\Shop\Models\ShopSeo;
use App\Core\Shop\Seo\SeoText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * 店の検索と共有の設定（ADR 0061）を読む・変える。変えるのはここだけ。書き込みは EventStore::lock() → 行のロックの順（ADR 0012）。
 * 持つのは、トップの題と説明（空なら店の名前と決まった文）と、共有の既定の写真（商品の写真が無い画面で LINE・X などに出す。ショップの写真から）
 */
final class SeoSettingsService
{
    public function __construct(private readonly EventStore $events) {}

    /**
     * @return array{home_title: string|null, home_description: string|null, share_image_id: string|null}
     */
    public function get(): array
    {
        $row = ShopSeo::query()->first();

        return [
            'home_title' => $row?->home_title,
            'home_description' => $row?->home_description,
            'share_image_id' => $row?->share_image_id,
        ];
    }

    /**
     * 共有の既定の写真（置き場に今あるものだけ。無ければ null）
     */
    public function shareImage(): ?ShopMedia
    {
        $id = ShopSeo::query()->value('share_image_id');

        return is_string($id) ? ShopMedia::query()->find($id) : null;
    }

    /**
     * まとめて置き換える。形が違えば ValidationException
     *
     * @param  array<string, mixed>  $input  home_title・home_description・share_image_id
     * @return array{home_title: string|null, home_description: string|null, share_image_id: string|null}
     */
    public function set(array $input, ?string $actor = null): array
    {
        $rules = SeoText::rules();
        $data = Validator::make($input, [
            'home_title' => ['present', ...array_slice($rules['seo_title'], 1)],
            'home_description' => ['present', ...array_slice($rules['seo_description'], 1)],
            'share_image_id' => ['present', 'nullable', 'string', 'ulid'],
        ], [
            'home_title.not_regex' => 'トップのタイトルは、改行や見えない文字の無い 1 行にしてください。',
            'home_description.not_regex' => 'トップの説明は、改行や見えない文字の無い 1 行にしてください。',
        ], ['home_title' => 'トップのタイトル', 'home_description' => 'トップの説明', 'share_image_id' => '共有の既定の写真'])->validate();

        $values = [
            'home_title' => SeoText::clean($data['home_title']),
            'home_description' => SeoText::clean($data['home_description']),
            'share_image_id' => is_string($data['share_image_id']) ? strtolower($data['share_image_id']) : null,
        ];

        DB::transaction(function () use ($values, $actor): void {
            $this->events->lock();

            // 写真は置き場にあるものだけ（消されないよう、行を押さえてから指す）
            if ($values['share_image_id'] !== null && ShopMedia::query()->whereKey($values['share_image_id'])->lockForUpdate()->doesntExist()) {
                throw ValidationException::withMessages(['share_image_id' => '写真が見つかりません。「写真」の一覧にあるものから選んでください。']);
            }

            $row = ShopSeo::query()->lockForUpdate()->first() ?? new ShopSeo;
            $row->fill($values);

            if ($row->exists && ! $row->isDirty()) {
                return;
            }

            $changed = $row->exists ? array_keys($row->getDirty()) : array_keys(array_filter($values, static fn (?string $v): bool => $v !== null));
            $row->save();
            // 文は記録に入れず、変わった欄の名前だけ
            $this->events->append('shop.seo_updated', 'shop_seo', $row->id, ['changed' => $changed], $actor);
        });

        return $this->get();
    }
}
