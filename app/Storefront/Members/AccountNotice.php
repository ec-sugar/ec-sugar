<?php

declare(strict_types=1);

namespace App\Storefront\Members;

use App\Core\Party\Models\CustomerAccount;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * 会員への事後の知らせ（「アカウントで次の操作がありました」。ADR 0070 の作って決めたこと の見直し 中 1・中 2）。
 * 届け先を足した・直した、定期便の届け先や数を変えた、LINE をつないだ・外した、のように、乗っ取られた時にお金や裏口につながる操作を、
 * 止めずに会員のメールへ知らせる（心当たりが無ければお店へ）。載せるのは何をしたかの一言と日時だけ（住所・届け先・金額は載せない）。
 *
 * 仕事に載せるのは台帳の ID と一言と時刻だけ（宛先は送る時に台帳から読む。キューにも失敗の記録にも平文のメールを残さない。ADR 0010）。
 * 載せられなくても、呼んだ操作は止めない（報告だけ）
 */
final class AccountNotice
{
    /**
     * @param  string  $what  何をしたかの一言（1 行・60 文字まで。住所などの個人情報を入れない）
     */
    public static function send(CustomerAccount|string $account, string $what): void
    {
        if ($what === '' || mb_strlen($what) > 60 || preg_match('/\p{C}/u', $what) === 1) {
            throw new InvalidArgumentException('会員への通知の一言は、1 行の 60 文字までです');
        }

        try {
            dispatch(new SendAccountNotice(is_string($account) ? $account : $account->id, $what, CarbonImmutable::now()->getTimestamp()));
        } catch (Throwable $error) {
            report($error);
        }
    }
}
