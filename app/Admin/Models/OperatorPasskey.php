<?php

declare(strict_types=1);

namespace App\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Laravel\Passkeys\Passkey;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * 運営のパスキー。中身（credential）は公開鍵と署名カウンタで、秘密は含まない。
 * 署名カウンタはログインのたびにパッケージが行ロックを取って更新する（複製された認証器の検知）。
 *
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property string $credential_id
 */
final class OperatorPasskey extends Passkey
{
    use CentralConnection;
    use HasUlids;

    protected $table = 'operator_passkeys';

    protected $hidden = ['credential', 'credential_id'];

    /**
     * 同期のパスキー（iCloud キーチェーン・Google パスワードマネージャー・パスワード管理アプリなど、端末の外へ写せるもの）か。
     * 登録のときに認証器が立てた「写せる」の印（WebAuthn の BE＝backup eligible）で見分ける。印が無い古い行は同期でない扱い。
     */
    public function isSynced(): bool
    {
        $credential = $this->getAttribute('credential');

        return is_array($credential) && ($credential['backupEligible'] ?? null) === true;
    }
}
