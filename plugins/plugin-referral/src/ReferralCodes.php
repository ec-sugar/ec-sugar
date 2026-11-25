<?php

declare(strict_types=1);

namespace EcSugar\PluginReferral;

use App\Core\Event\EventStore;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Points\Services\PointService;
use EcSugar\PluginReferral\Models\ReferralCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 会員の紹介のリンクの印（ADR 0075 の 1）。10 文字で、見間違えやすい文字（0 O 1 I l）を除いた 57 種から乱数で選ぶ（約 58 ビット）。
 * 初めてマイページの「友だちを紹介する」を開いた時に作り、会員が作り直すと前の印は使えなくなる（前の印の Cookie を持つ人は、紹介されなかったことになる）。
 * 書き込みは連鎖のロック → 台帳の行のロックの順で、イベントには台帳の ID だけ（印そのものは残さない）
 */
final class ReferralCodes
{
    public const string ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public const int LENGTH = 10;

    /** 経路と Cookie の印の形（ALPHABET と同じ文字だけ） */
    public const string PATTERN = '[2-9A-HJ-NP-Za-km-z]{10}';

    public function __construct(private readonly EventStore $events) {}

    /**
     * 会員の今の印（無ければ作る）。会員でない台帳は null
     */
    public function forMember(CustomerAccount $account, string $actor): ?string
    {
        $code = ReferralCode::query()->find($account->id)?->code;

        return $code ?? $this->write($account, $actor, regenerate: false);
    }

    /**
     * 作り直す（前の印は使えなくなる）。会員でない台帳は null
     */
    public function regenerate(CustomerAccount $account, string $actor): ?string
    {
        return $this->write($account, $actor, regenerate: true);
    }

    /**
     * 印から紹介した人の台帳の ID（無い・形の違う印は null）
     */
    public function owner(string $code): ?string
    {
        if (preg_match('/\A'.self::PATTERN.'\z/', $code) !== 1) {
            return null;
        }

        $id = ReferralCode::query()->where('code', $code)->value('customer_account_id');

        return is_string($id) ? $id : null;
    }

    public static function generate(): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::LENGTH; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }

    private function write(CustomerAccount $account, string $actor, bool $regenerate): ?string
    {
        // 印がたまたま重なったら（57^10 なので、まず無い）作り直して試す
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($account, $actor, $regenerate): ?string {
                    $this->events->lock();
                    $locked = CustomerAccount::query()->whereKey($account->id)->lockForUpdate()->first();

                    if ($locked === null || ! PointService::isMember($locked)) {
                        return null;
                    }

                    $current = ReferralCode::query()->whereKey($locked->id)->lockForUpdate()->first();

                    if ($current !== null && ! $regenerate) {
                        return $current->code;
                    }

                    $code = self::generate();

                    if ($current === null) {
                        ReferralCode::create(['customer_account_id' => $locked->id, 'code' => $code]);
                    } else {
                        ReferralCode::query()->whereKey($locked->id)->update(['code' => $code, 'updated_at' => now()]);
                    }

                    $this->events->append($current === null ? 'referral.code_issued' : 'referral.code_regenerated', 'customer_account', $locked->id, [], $actor);

                    return $code;
                });
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new LogicException('紹介のリンクの印を作れませんでした');
    }
}
