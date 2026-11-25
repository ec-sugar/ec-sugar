<?php

declare(strict_types=1);

namespace App\Core\Sample\Models;

use App\Core\Crypto\Casts\TenantEncrypted;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * デモの店で送らずに止めたメール（テナント DB。ADR 0086）。書くのは DemoMails だけ。
 *
 * @property string $id
 * @property string $mailbox 来訪者の受け箱（DemoVisitors::mailbox）
 * @property string $subject 件名（暗号化）
 * @property string $body 本文の文字（暗号化）
 * @property CarbonImmutable $created_at
 */
final class DemoMail extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'demo_mails';

    protected $fillable = ['mailbox', 'subject', 'body'];

    protected function casts(): array
    {
        return [
            'subject' => TenantEncrypted::class,
            'body' => TenantEncrypted::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
