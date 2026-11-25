<?php

declare(strict_types=1);

namespace EcSugar\PluginWrapping;

use App\Core\Plugin\AdminPage\AdminAction;
use App\Core\Plugin\AdminPage\AdminColumn;
use App\Core\Plugin\AdminPage\AdminFact;
use App\Core\Plugin\AdminPage\AdminField;
use App\Core\Plugin\AdminPage\AdminFilter;
use App\Core\Plugin\AdminPage\AdminRecord;
use App\Core\Plugin\AdminPage\AdminRecordNotFound;
use App\Core\Plugin\AdminPage\AdminRow;
use App\Core\Plugin\AdminPage\AdminRows;
use App\Core\Plugin\AdminPage\CellType;
use App\Core\Plugin\AdminPage\FieldType;
use App\Core\Plugin\Contracts\AdminFormHandler;
use App\Core\Plugin\Contracts\AdminPageHandler;
use EcSugar\PluginWrapping\Models\WrappingListing;
use EcSugar\PluginWrapping\Models\WrappingOrder;
use EcSugar\PluginWrapping\Models\WrappingType;
use Illuminate\Database\Eloquent\Builder;

/**
 * 管理画面の「ラッピング・のし」（ADR 0051 の App の画面と、足す・直すの欄）。ラッピングの種類の一覧（すべて・使う・使わない）・1 件・足す・直す・消す。
 * のしの決まり（表書き・料金・名入れ）は App の設定で、商品ごとの「ラッピングできる」「のしを付けられる」は商品の画面の欄で決める。
 * 種類の名前と料金は店の人の文と数で、お客さんの個人情報は扱わない（名入れはこの画面に出さない）
 */
final class WrappingAdminPage implements AdminFormHandler, AdminPageHandler
{
    private const array FILTERS = ['all' => 'すべて', 'active' => 'ショップで選べる', 'inactive' => 'ショップに出さない'];

    public function title(): string
    {
        return 'ラッピング・のし';
    }

    public function filters(): array
    {
        $active = WrappingType::query()->where('active', true)->count();
        $all = WrappingType::query()->count();
        $counts = ['all' => $all, 'active' => $active, 'inactive' => $all - $active];

        return array_map(static fn (string $key): AdminFilter => new AdminFilter($key, self::FILTERS[$key], $counts[$key]), array_keys(self::FILTERS));
    }

    public function attention(): array
    {
        return [];
    }

    public function columns(): array
    {
        return [
            new AdminColumn('name', 'ラッピングの種類', CellType::Text),
            new AdminColumn('price', '料金（税込み）', CellType::Money),
            new AdminColumn('status', '状態', CellType::Status),
            new AdminColumn('updated', '更新した日時', CellType::DateTime),
        ];
    }

    public function rows(string $filter, int $page, int $perPage): AdminRows
    {
        $query = self::filtered($filter);
        $total = (clone $query)->count();

        return new AdminRows($query->orderBy('position')->orderBy('id')->forPage($page, $perPage)->get()->map(static fn (WrappingType $type): AdminRow => new AdminRow($type->id, [
            'name' => $type->name,
            'price' => $type->price,
            'status' => self::status($type),
            'updated' => $type->getAttribute('updated_at'),
        ]))->values()->all(), $total);
    }

    public function record(string $id): ?AdminRecord
    {
        $type = WrappingType::query()->find($id);

        if ($type === null) {
            return null;
        }

        return new AdminRecord($type->name, [
            new AdminFact('料金（税込み）', CellType::Money, $type->price),
            new AdminFact('状態', CellType::Status, self::status($type)),
            new AdminFact('この種類を選んだ注文', CellType::Number, WrappingOrder::query()->where('wrapping_type_id', $type->id)->count()),
            new AdminFact('ラッピングできる商品', CellType::Number, WrappingListing::query()->where('wrappable', true)->count()),
            new AdminFact('追加した日時', CellType::DateTime, $type->getAttribute('created_at')),
            new AdminFact('更新した日時', CellType::DateTime, $type->getAttribute('updated_at')),
        ], [], [
            new AdminAction('delete', '削除する', 'この種類を削除します。すでに受けたご注文の内容（名前と料金）はそのまま残ります。削除すると元に戻せません。', danger: true),
        ]);
    }

    public function act(string $id, string $action, string $actor): void
    {
        match ($action) {
            'delete' => app(WrappingTypes::class)->delete($id, $actor),
            default => throw new AdminRecordNotFound,
        };
    }

    public function createLabel(): string
    {
        return 'ラッピングの種類を追加';
    }

    public function fields(): array
    {
        return [
            new AdminField('name', FieldType::Text, '名前', help: 'ショップの注文の入力に出ます（例: リボン掛け・ギフトボックス）。', required: true, max: WrappingTypes::NAME_MAX),
            new AdminField('price', FieldType::Text, '料金（円・税込み）', help: '0 なら無料。ご注文の合計に、消費税 10% の料金として足します（商品が軽減税率でも、包装の料金は 10%）。', required: true, max: 6),
            new AdminField('active', FieldType::Toggle, 'ショップで選べる', help: 'オフにすると、種類を残したまま、ショップの注文の入力に出さなくなります。', default: true),
        ];
    }

    public function values(string $id): ?array
    {
        $type = WrappingType::query()->find($id);

        return $type === null ? null : ['name' => $type->name, 'price' => (string) $type->price, 'active' => $type->active];
    }

    public function create(array $values, string $actor): string
    {
        return app(WrappingTypes::class)->create($values, $actor);
    }

    public function update(string $id, array $values, string $actor): void
    {
        app(WrappingTypes::class)->update($id, $values, $actor);
    }

    /**
     * @return Builder<WrappingType>
     */
    private static function filtered(string $filter): Builder
    {
        $query = WrappingType::query();

        return match ($filter) {
            'active' => $query->where('active', true),
            'inactive' => $query->where('active', false),
            default => $query,
        };
    }

    /**
     * @return array{label: string, tone: string}
     */
    private static function status(WrappingType $type): array
    {
        return $type->active ? ['label' => 'ショップで選べる', 'tone' => 'good'] : ['label' => 'ショップに出さない', 'tone' => 'muted'];
    }
}
