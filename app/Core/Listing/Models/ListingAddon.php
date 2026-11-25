<?php

declare(strict_types=1);

namespace App\Core\Listing\Models;

use App\Core\Listing\Enums\AddonKind;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 商品のオプション（名入れ・ギフト包装など。ADR 0036）。変更は AddonService を通す。
 * 形ごとの決まり（選択肢の数・最大の文字数・額）は DB の CHECK でも守る。
 *
 * @property string $id
 * @property string $listing_id
 * @property int $position
 * @property AddonKind $kind
 * @property string $name
 * @property bool $required
 * @property int $amount 入れる文字・付けるの足す額
 * @property list<array{label: string, amount: int}>|null $choices 選ぶの選択肢
 * @property string|null $tax_class 税区分（standard・reduced。null は商品の区分に従う。TaxClass）
 * @property int|null $max_length 入れる文字の最大の文字数
 */
final class ListingAddon extends Model
{
    use HasUlids;
    use SoftDeletes;

    public const int MAX_PER_LISTING = 10;

    public const int MAX_CHOICES = 20;

    public const int MAX_TEXT_LENGTH = 100;

    protected $table = 'listing_addons';

    protected $fillable = ['listing_id', 'position', 'kind', 'name', 'required', 'amount', 'choices', 'max_length', 'tax_class'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'kind' => AddonKind::class,
            'required' => 'boolean',
            'amount' => 'integer',
            'choices' => 'array',
            'max_length' => 'integer',
        ];
    }
}
