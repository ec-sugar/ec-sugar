<?php

declare(strict_types=1);

namespace App\Core\Shop\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use App\Core\Crypto\Casts\TenantEncryptedJson;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * 店の設定（テナントごとに 1 行）。変えるのは ShopSettingsService だけ。
 * 持つのは店の事実（送料・注文の案内・特商法の表記・プライバシーポリシー）だけ。見せ方はテーマの区画の値（ThemeVersion。ADR 0028）
 *
 * @property string $id
 * @property int $shipping_fee
 * @property int|null $free_shipping_over
 * @property array{delivery?: string|null, returns?: string|null}|null $order_terms
 * @property array<int, mixed>|null $legal 特商法の表記（暗号化）
 * @property string|null $privacy_policy プライバシーポリシーの本文（暗号化。空は null。ADR 0094）
 * @property bool $sell_free_items 0 円の品を売るか（既定は売らない）
 * @property bool $consent_banner 解析・広告のタグの同意の帯を出すか（既定は出さない）
 * @property string $storefront_status ショップの公開の状態（preparing・open。変えるのは StorefrontAccessService だけ）
 * @property string|null $storefront_password_hash 準備中に中を見るためのパスワードのハッシュ（外へ出さない）
 * @property int $storefront_password_generation パスワードを変えるたびに進む世代（入れた人の Cookie と照らす）
 * @property bool $points_enabled ポイントを使うか（既定は使う。変えるのは PointSettings だけ。ADR 0068）
 * @property int $points_rate_bp ポイントを付ける率（万分率。既定 100 = 1%）
 * @property string|null $agent_write_mode AI エージェントの書き込み（immediate・approval。空は承認。変えるのは AgentSettings だけ。ADR 0101）
 * @property bool $agent_customer_data AI エージェントにお客さんの情報を渡すか（既定は渡さない。ADR 0101）
 */
final class ShopSettings extends Model
{
    use HasUlids;

    protected $table = 'shop_settings';

    protected $fillable = ['shipping_fee', 'free_shipping_over', 'order_terms', 'legal', 'sell_free_items', 'consent_banner'];

    /** パスワードのハッシュは JSON にも配列にも出さない */
    protected $hidden = ['storefront_password_hash'];

    protected function casts(): array
    {
        return [
            'sell_free_items' => 'boolean',
            'consent_banner' => 'boolean',
            'points_enabled' => 'boolean',
            'agent_customer_data' => 'boolean',
            'points_rate_bp' => 'integer',
            'storefront_password_generation' => 'integer',
            'shipping_fee' => 'integer',
            'free_shipping_over' => 'integer',
            'order_terms' => 'array',
            'legal' => TenantEncryptedJson::class,
            'privacy_policy' => TenantEncrypted::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
