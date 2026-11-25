<?php

declare(strict_types=1);

namespace App\Core\Shop\Services;

use App\Core\Event\EventStore;
use App\Core\Shop\Models\ShopSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * 店の「AI エージェント」の設定（ADR 0101。設定 › AI エージェント）。値は店の設定の行（shop_settings）に置き、変えるのはここだけ。
 *
 * - 書き込み（write_mode）: すぐ反映（immediate）か、承認してから（approval。既定。行の無い店・空も承認）。
 *   お金に届く操作（取消・返金・クーポン）は、ここにかかわらず承認が要る（決めるのは道具の層）
 * - お客さんの情報を渡す（customer_data）: 既定は渡さない。渡す店は、外部送信の公表に「AI エージェント」の行が出る（ショップが読む）
 *
 * 書き込みは EventStore::lock() → 設定の行のロックの順（ADR 0012）。変えたら記録に値だけを残す
 */
final class AgentSettings
{
    public const string IMMEDIATE = 'immediate';

    public const string APPROVAL = 'approval';

    public function __construct(private readonly EventStore $events) {}

    public function writeMode(): string
    {
        return ShopSettings::query()->value('agent_write_mode') === self::IMMEDIATE ? self::IMMEDIATE : self::APPROVAL;
    }

    public function sharesCustomerData(): bool
    {
        return (bool) (ShopSettings::query()->value('agent_customer_data') ?? false);
    }

    /**
     * @return array{write_mode: string, customer_data: bool}
     */
    public function show(): array
    {
        return ['write_mode' => $this->writeMode(), 'customer_data' => $this->sharesCustomerData()];
    }

    /**
     * 変える。形が違えば ValidationException
     *
     * @param  array<string, mixed>  $input  {write_mode, customer_data}
     * @return array{write_mode: string, customer_data: bool}
     */
    public function update(array $input, ?string $actor = null): array
    {
        $data = Validator::make($input, [
            'write_mode' => ['required', 'string', 'in:'.self::IMMEDIATE.','.self::APPROVAL],
            'customer_data' => ['required', 'boolean'],
        ], [], ['write_mode' => 'AI エージェントによる変更', 'customer_data' => 'お客さんの情報を渡すか'])->validate();

        $mode = (string) $data['write_mode'];
        $customer = (bool) $data['customer_data'];

        DB::transaction(function () use ($mode, $customer, $actor): void {
            $this->events->lock();
            $settings = ShopSettings::query()->lockForUpdate()->first() ?? ShopSettings::create([]);
            $current = [
                'write_mode' => $settings->getAttribute('agent_write_mode') === self::IMMEDIATE ? self::IMMEDIATE : self::APPROVAL,
                'customer_data' => (bool) ($settings->getAttribute('agent_customer_data') ?? false),
            ];

            if ($current === ['write_mode' => $mode, 'customer_data' => $customer]) {
                return;
            }

            ShopSettings::query()->whereKey($settings->id)->update(['agent_write_mode' => $mode, 'agent_customer_data' => $customer, 'updated_at' => now()]);
            $this->events->append('shop.agent_settings_updated', 'shop_settings', $settings->id, ['write_mode' => $mode, 'customer_data' => $customer], $actor);
        });

        return $this->show();
    }
}
