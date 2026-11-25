<?php

declare(strict_types=1);

namespace App\Admin\Mail;

use App\Core\Mail\MailHeaderText;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 運営のログインまわりの知らせ（ADR 0038）。コード・リンク・接続元などの秘密や個人情報は載せず、「何が起きたか」と「心当たりが無い時にすること」だけ。
 * キューに積む（Mail::queue）ので、積んだ中身（宛先のメール・名前・メールでの戻しのリンク＝合い言葉）はアプリの鍵で暗号化する（ShouldBeEncrypted）。
 * 暗号化しないと、キュー（Redis）と送れなかった時の failed_jobs（中央 DB とそのバックアップ）に平文で残る
 */
final class OperatorSecurityMail extends Mailable implements ShouldBeEncrypted
{
    use Queueable;

    public const string CODES_ISSUED = 'codes_issued';

    public const string CODE_USED = 'code_used';

    /** ほかの運営が回復用のコードかメールで戻った（オーナーへ） */
    public const string OTHER_CODE_USED = 'other_code_used';

    /** メールでの戻し: 申し込みのリンク（本人へ。リンクだけは載せる） */
    public const string RECOVERY_LINK = 'recovery_link';

    /** メールでの戻し: 24 時間の待ちに入った（本人へ） */
    public const string RECOVERY_WAITING = 'recovery_waiting';

    /** メールでの戻し: ほかの運営が待ちに入った（オーナーへ） */
    public const string OTHER_RECOVERY_WAITING = 'other_recovery_waiting';

    /** メールでの戻し: 待った後に登録のリンクを受け取った（本人へ） */
    public const string RECOVERY_EMAIL_USED = 'recovery_email_used';

    /** メールでの戻し: 本人のログインで取り消した（本人へ） */
    public const string RECOVERY_CANCELLED = 'recovery_cancelled';

    /** パスキーが足された（本人へ。2） */
    public const string PASSKEY_ADDED = 'passkey_added';

    /** パスキーが消された（本人へ） */
    public const string PASSKEY_REMOVED = 'passkey_removed';

    /** いつもと違う接続元からログインした（本人へ） */
    public const string NEW_LOCATION_LOGIN = 'new_location_login';

    /** AI エージェントの鍵を作った（本人へ。ADR 0100） */
    public const string AGENT_TOKEN_ISSUED = 'agent_token_issued';

    public const array SUBJECTS = [
        self::CODES_ISSUED => '[EC-SUGAR] 回復用のコードを作り直しました',
        self::CODE_USED => '[EC-SUGAR] 回復用のコードが使われました',
        self::OTHER_CODE_USED => '[EC-SUGAR] メンバーの 1 人がパスキーを登録し直せる状態になりました',
        self::RECOVERY_LINK => '[EC-SUGAR] パスキーの登録のし直しの申し込み',
        self::RECOVERY_WAITING => '[EC-SUGAR] パスキーの登録のし直しの申し込みを受け付けました（24 時間後に有効）',
        self::OTHER_RECOVERY_WAITING => '[EC-SUGAR] メンバーの 1 人からパスキーの登録のし直しの申し込みがありました',
        self::RECOVERY_EMAIL_USED => '[EC-SUGAR] メールでの申し込みから、パスキーを登録し直せる状態になりました',
        self::RECOVERY_CANCELLED => '[EC-SUGAR] パスキーの登録のし直しの申し込みを取り消しました',
        self::PASSKEY_ADDED => '[EC-SUGAR] 新しいパスキーが登録されました',
        self::PASSKEY_REMOVED => '[EC-SUGAR] パスキーが削除されました',
        self::NEW_LOCATION_LOGIN => '[EC-SUGAR] いつもと違う場所から管理画面にログインがありました',
        self::AGENT_TOKEN_ISSUED => '[EC-SUGAR] AI エージェントのアクセスキーが作られました',
    ];

    public function __construct(
        public readonly string $kind,
        public readonly string $operatorName,
        public readonly ?int $remaining = null,
        public readonly ?string $subjectName = null,
        public readonly ?string $link = null,
        public readonly ?CarbonImmutable $readyAt = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::SUBJECTS[$this->kind]);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.operator-security-text', with: [
            'kind' => $this->kind,
            'name' => MailHeaderText::line($this->operatorName),
            'remaining' => $this->remaining,
            'subjectName' => MailHeaderText::line((string) $this->subjectName),
            'adminUrl' => (string) config('admin.url'),
            'link' => $this->link,
            'readyAt' => $this->readyAt?->setTimezone((string) config('app.display_timezone', 'Asia/Tokyo'))->format('Y年n月j日 H:i'),
        ]);
    }
}
