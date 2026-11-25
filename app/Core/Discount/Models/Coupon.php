<?php

declare(strict_types=1);

namespace App\Core\Discount\Models;

use App\Core\Discount\Enums\CouponKind;
use App\Core\Plugin\MemberDiscount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * クーポンと自動で効く割引（ADR 0054・0065）。変えるのは CouponService だけ。code は大文字にそろえた形（CouponService::normalize）。
 * method が automatic の割引はコードを持たず（code は null）、お客さんに見せる名前 label を持つ。
 * 会員価格（ADR 0079）は保存しない形（memberPrice）で同じ候補に入る。ID は MEMBER_PRICE の頭で始まり、表には無い（使った記録も持たない）
 *
 * @property string $id
 * @property string $method code（コードを入れて使う）・automatic（条件に合えば効く）
 * @property string|null $code
 * @property string|null $label 自動の割引のお客さんに見せる名前（40 文字まで）
 * @property int|null $min_quantity 対象の商品の数の合計がこれ以上
 * @property string $name
 * @property CouponKind $kind
 * @property int $value
 * @property int|null $max_discount
 * @property string $target
 * @property list<string> $target_ids
 * @property int|null $min_subtotal
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property int|null $usage_limit
 * @property int|null $per_customer_limit
 * @property bool $members_only
 * @property string $status
 */
final class Coupon extends Model
{
    use HasUlids;

    public const string CODE = 'code';

    public const string AUTOMATIC = 'automatic';

    /** 会員価格の ID の頭（表の ULID と重ならない形） */
    public const string MEMBER_PRICE = 'member-price:';

    /** 送られなければ使っているクーポン（DB の既定と同じ。作った直後のモデルでも読めるように。自動の割引の数の上限を作る前に数える） */
    protected $attributes = ['method' => self::CODE, 'status' => 'active'];

    protected $fillable = ['method', 'code', 'label', 'min_quantity', 'name', 'kind', 'value', 'max_discount', 'target', 'target_ids', 'min_subtotal', 'starts_at', 'ends_at', 'usage_limit', 'per_customer_limit', 'members_only', 'status'];

    protected function casts(): array
    {
        return [
            'kind' => CouponKind::class,
            'value' => 'integer',
            'max_discount' => 'integer',
            'target_ids' => 'array',
            'min_subtotal' => 'integer',
            'min_quantity' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'usage_limit' => 'integer',
            'per_customer_limit' => 'integer',
            'members_only' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * 会員価格（ADR 0079）を、値引きの候補として照らせる形にする（保存しない。全品が対象の定率・会員だけ・回数の上限なし）
     */
    public static function memberPrice(MemberDiscount $discount): self
    {
        $coupon = new self(['method' => self::AUTOMATIC, 'label' => $discount->label, 'name' => $discount->label, 'kind' => CouponKind::Percent, 'value' => $discount->percent,
            'target' => 'all', 'target_ids' => [], 'members_only' => true, 'status' => 'active']);
        $coupon->id = self::MEMBER_PRICE.$discount->key;

        return $coupon;
    }

    /**
     * 会員価格か（表に無い。使った記録を残さず、注文には値引きの行とイベントだけ）
     */
    public function isMemberPrice(): bool
    {
        return ! $this->exists && str_starts_with((string) $this->id, self::MEMBER_PRICE);
    }

    public static function isMemberPriceId(?string $id): bool
    {
        return $id !== null && str_starts_with($id, self::MEMBER_PRICE);
    }

    public function isAutomatic(): bool
    {
        return $this->method === self::AUTOMATIC;
    }

    /**
     * 注文・メール・帳票の値引きの行の名前（自動の割引は label、クーポンは「クーポン（CODE）」）
     */
    public function lineTitle(): string
    {
        return $this->isAutomatic() ? (string) $this->label : 'クーポン（'.$this->code.'）';
    }
}
