<?php

declare(strict_types=1);

namespace App\Core\Payment\Services;

use App\Core\Event\EventStore;
use App\Core\Payment\Models\PaymentMethodSetting;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * 店が受け取る支払い（銀行振込・代金引換。ADR 0074）の設定を読む・変える。既定はどちらも使わない。
 * 振込先は店の鍵で暗号化して持ち、イベントには「振込先がある」ことだけを残す（銀行名・口座番号・名義は入れない）。
 * 代引きの手数料は段（「1 万円未満 ¥330」）で持ち、注文の合計（手数料の前）で決める。手数料を含む合計が上限を超える注文には出さない
 */
final class DirectMethodSettings
{
    public const int MIN_BANK_DAYS = 1;

    public const int MAX_BANK_DAYS = 14;

    public const int MAX_COD_TIERS = 10;

    public const int MAX_COD_FEE = 100_000;

    public const int MAX_COD_LIMIT = 1_000_000;

    /** 口座の種類（画面の名前） */
    public const array ACCOUNT_TYPES = ['ordinary' => '普通', 'checking' => '当座', 'savings' => '貯蓄'];

    /** 代引きを初めて使う時の手数料の段の見本（税込み。運送会社の代金引換の料金の目安） */
    public const array DEFAULT_COD_FEES = [
        ['below' => 10_000, 'fee' => 330],
        ['below' => 30_000, 'fee' => 440],
        ['below' => 100_000, 'fee' => 660],
        ['below' => null, 'fee' => 1_100],
    ];

    public function __construct(private readonly EventStore $events) {}

    /**
     * 管理画面に見せる今の設定（振込先はお金の権限の人の設定の画面にだけ出す）
     *
     * @return array{bank_transfer: bool, bank_days: int, bank_account: array{bank: string, branch: string, type: string, number: string, holder: string}|null, cod: bool, cod_fees: list<array{below: int|null, fee: int}>, cod_limit: int, members_only: bool}
     */
    public function status(): array
    {
        $row = PaymentMethodSetting::query()->first();
        $fees = $row === null ? [] : self::tiers($row->cod_fees);

        return [
            'bank_transfer' => (bool) $row?->bank_transfer,
            'bank_days' => $row->bank_days ?? 7,
            'bank_account' => $row?->bank_account,
            'cod' => (bool) $row?->cod,
            'cod_fees' => $fees === [] ? self::DEFAULT_COD_FEES : $fees,
            'cod_limit' => $row->cod_limit ?? 300_000,
            'members_only' => (bool) $row?->direct_members_only,
        ];
    }

    /**
     * 銀行振込・代金引換を会員（ログインしたお客さん）だけに出すか（ADR 0085。既定は切る＝ゲストも選べる）
     */
    public function membersOnly(): bool
    {
        return (bool) PaymentMethodSetting::query()->value('direct_members_only');
    }

    /**
     * 銀行振込を使う店の振込先と払う期限の日数。使わない店は null
     *
     * @return array{account: array{bank: string, branch: string, type: string, number: string, holder: string}, days: int}|null
     */
    public function bankTransfer(): ?array
    {
        $row = PaymentMethodSetting::query()->first();

        return $row === null || ! $row->bank_transfer || $row->bank_account === null ? null : ['account' => $row->bank_account, 'days' => $row->bank_days];
    }

    /**
     * 代引きを使う店の、この額（手数料の前の合計）の手数料。使わない店・1 円未満・手数料を含めて上限を超える額は null
     */
    public function codFee(int $amount): ?int
    {
        $row = PaymentMethodSetting::query()->first();

        if ($row === null || ! $row->cod || $amount < 1) {
            return null;
        }

        $fee = self::feeFor(self::tiers($row->cod_fees), $amount);

        return $fee === null || $amount + $fee > $row->cod_limit ? null : $fee;
    }

    /**
     * 代引きを使う店か（額は問わない。注文の入力で「代金引換」を見せるか）
     */
    public function codEnabled(): bool
    {
        return (bool) PaymentMethodSetting::query()->value('cod');
    }

    /**
     * 段から手数料を決める（below 円未満の最初の段。最後の段は below が null で、それより上の全部）
     *
     * @param  list<array{below: int|null, fee: int}>  $tiers
     */
    public static function feeFor(array $tiers, int $amount): ?int
    {
        foreach ($tiers as $tier) {
            if ($tier['below'] === null || $amount < $tier['below']) {
                return $tier['fee'];
            }
        }

        return null;
    }

    /**
     * 保存した段をそろえた形にする（jsonb は鍵の順を並べ替えるので、比べる前に below・fee の順へ）
     *
     * @param  array<int|string, mixed>  $stored
     * @return list<array{below: int|null, fee: int}>
     */
    private static function tiers(array $stored): array
    {
        $tiers = [];

        foreach ($stored as $tier) {
            if (is_array($tier) && isset($tier['fee'])) {
                $tiers[] = ['below' => isset($tier['below']) ? (int) $tier['below'] : null, 'fee' => (int) $tier['fee']];
            }
        }

        return $tiers;
    }

    /**
     * 払う期限（注文の日〔日本時間〕から何日後の 23:59:59）
     */
    public static function bankDue(CarbonImmutable $placedAt, int $days): CarbonImmutable
    {
        return $placedAt->setTimezone('Asia/Tokyo')->startOfDay()->addDays($days)->setTime(23, 59, 59)->utc();
    }

    /**
     * 設定を変える（お金の権限の人が、パスキーの再確認の後）。何も変わらなければ記録も残さない
     *
     * @param  array<string, mixed>  $input  {bank_transfer, bank_days, bank_account{bank, branch, type, number, holder}, cod, cod_fees[{below, fee}], cod_limit, members_only}
     *                                       members_only（会員だけ）は送らなければ今のまま
     */
    public function update(array $input, ?string $actor = null): void
    {
        $bank = filter_var($input['bank_transfer'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $line = 'not_regex:/[\x00-\x1F\x7F]/u';
        $data = Validator::make($input, [
            'bank_transfer' => ['required', 'boolean'],
            'bank_days' => ['required', 'integer', 'between:'.self::MIN_BANK_DAYS.','.self::MAX_BANK_DAYS],
            'bank_account' => [$bank ? 'required' : 'nullable', 'array:bank,branch,type,number,holder'],
            'bank_account.bank' => [$bank ? 'required' : 'nullable', 'string', 'max:40', $line],
            'bank_account.branch' => [$bank ? 'required' : 'nullable', 'string', 'max:40', $line],
            'bank_account.type' => [$bank ? 'required' : 'nullable', 'string', 'in:'.implode(',', array_keys(self::ACCOUNT_TYPES))],
            'bank_account.number' => [$bank ? 'required' : 'nullable', 'string', 'regex:/\A[0-9]{4,8}\z/'],
            'bank_account.holder' => [$bank ? 'required' : 'nullable', 'string', 'max:60', $line],
            'cod' => ['required', 'boolean'],
            'cod_fees' => ['required', 'array', 'min:1', 'max:'.self::MAX_COD_TIERS],
            'cod_fees.*' => ['array:below,fee'],
            'cod_fees.*.below' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_COD_LIMIT],
            'cod_fees.*.fee' => ['required', 'integer', 'min:0', 'max:'.self::MAX_COD_FEE],
            'cod_limit' => ['required', 'integer', 'min:1', 'max:'.self::MAX_COD_LIMIT],
            'members_only' => ['sometimes', 'boolean'],
        ], [
            'bank_days.between' => 'お振込みの期限は 1〜14 日で選んでください。',
            'bank_account.number.regex' => '口座番号は 4〜8 桁の数字で入力してください。',
        ], [
            'bank_transfer' => '銀行振込', 'bank_days' => 'お振込みの期限', 'bank_account' => '振込先', 'bank_account.bank' => '銀行名', 'bank_account.branch' => '支店名',
            'bank_account.type' => '口座の種類', 'bank_account.number' => '口座番号', 'bank_account.holder' => '口座の名義', 'cod' => '代金引換',
            'cod_fees' => '代引きの手数料', 'cod_fees.*.below' => '手数料の区分の額', 'cod_fees.*.fee' => '手数料', 'cod_limit' => '代引きの上限の額',
            'members_only' => '会員限定',
        ])->after(self::tiersInOrder())->validate();

        $account = $bank ? [
            'bank' => trim((string) $data['bank_account']['bank']),
            'branch' => trim((string) $data['bank_account']['branch']),
            'type' => (string) $data['bank_account']['type'],
            'number' => (string) $data['bank_account']['number'],
            'holder' => trim((string) $data['bank_account']['holder']),
        ] : null;
        $fees = array_map(static fn (array $tier): array => ['below' => isset($tier['below']) ? (int) $tier['below'] : null, 'fee' => (int) $tier['fee']], array_values($data['cod_fees']));
        $values = [
            'bank_transfer' => $bank,
            'bank_days' => (int) $data['bank_days'],
            'cod' => (bool) $data['cod'],
            'cod_fees' => $fees,
            'cod_limit' => (int) $data['cod_limit'],
        ];

        $membersOnly = array_key_exists('members_only', $data) ? (bool) $data['members_only'] : null;

        DB::transaction(function () use ($values, $account, $actor, $membersOnly): void {
            $this->events->lock();
            $row = PaymentMethodSetting::query()->lockForUpdate()->first() ?? new PaymentMethodSetting;
            // 使わなくした時も、前の振込先は残す（もう一度使う時に入れ直さなくてよい）。入れた時だけ替える
            $nextAccount = $account ?? $row->bank_account;
            // 会員だけは送られた時だけ替える（送らない古い画面から保存しても、入れた設定を外さない）
            $values['direct_members_only'] = $membersOnly ?? (bool) $row->direct_members_only;
            $same = $row->exists && $row->bank_transfer === $values['bank_transfer'] && $row->bank_days === $values['bank_days'] && $row->cod === $values['cod']
                && self::tiers($row->cod_fees) === $values['cod_fees'] && $row->cod_limit === $values['cod_limit'] && $row->bank_account === $nextAccount
                && $row->direct_members_only === $values['direct_members_only'];

            if ($same) {
                return;
            }

            $accountChanged = $row->bank_account !== $nextAccount;
            $row->fill($values);
            $row->bank_account = $nextAccount;
            $row->save();
            // 振込先そのものは記録に入れない（変えたかどうかだけ）
            $this->events->append('payment.direct_methods_updated', 'payment_method_settings', $row->id, [
                'bank_transfer' => $row->bank_transfer,
                'bank_days' => $row->bank_days,
                'bank_account_changed' => $accountChanged,
                'cod' => $row->cod,
                'cod_tiers' => count($row->cod_fees),
                'cod_limit' => $row->cod_limit,
                'members_only' => $row->direct_members_only,
            ], $actor);
        });
    }

    /**
     * 段の額は小さい順で、最後の段だけが「それより上の全部」（below が空）
     *
     * @return Closure(\Illuminate\Validation\Validator): void
     */
    private static function tiersInOrder(): Closure
    {
        return static function (\Illuminate\Validation\Validator $validator): void {
            $tiers = $validator->getData()['cod_fees'] ?? null;

            if ($validator->errors()->isNotEmpty() || ! is_array($tiers) || $tiers === []) {
                return;
            }

            $tiers = array_values($tiers);
            $last = count($tiers) - 1;
            $previous = 0;

            foreach ($tiers as $index => $tier) {
                $below = is_array($tier) ? ($tier['below'] ?? null) : null;

                if ($index === $last ? $below !== null : ($below === null || (int) $below <= $previous)) {
                    $validator->errors()->add('cod_fees', '手数料の区分は、額の小さい順に並べ、最後の区分を「それ以上のすべての額」にしてください。');

                    return;
                }

                $previous = (int) $below;
            }
        };
    }
}
