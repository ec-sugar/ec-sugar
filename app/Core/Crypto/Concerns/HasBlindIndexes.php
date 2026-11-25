<?php

declare(strict_types=1);

namespace App\Core\Crypto\Concerns;

use App\Core\Crypto\BlindIndex;
use Illuminate\Database\Eloquent\Builder;

/**
 * 暗号化した列に対するブラインドインデックス列を、保存時に自動で埋める。
 *
 * 使う側は blindIndexes() で「平文の属性 => インデックス列」を返す。
 * 検索は Model::whereBlind('email', $value)（完全一致のみ。前方一致や並べ替えはできない）。
 */
trait HasBlindIndexes
{
    /**
     * @return array<string, string>
     */
    abstract protected function blindIndexes(): array;

    public static function bootHasBlindIndexes(): void
    {
        static::saving(static function (self $model): void {
            foreach ($model->blindIndexes() as $attribute => $column) {
                $plain = $model->getAttribute($attribute);
                $model->setAttribute(
                    $column,
                    $plain === null || $plain === '' ? null : BlindIndex::of($attribute, (string) $plain),
                );
            }
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereBlind(Builder $query, string $attribute, string $value): Builder
    {
        $column = $this->blindIndexes()[$attribute] ?? throw new \InvalidArgumentException("ブラインドインデックスが定義されていない属性: {$attribute}");

        return $query->where($column, BlindIndex::of($attribute, $value));
    }
}
