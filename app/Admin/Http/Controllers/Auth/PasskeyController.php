<?php

declare(strict_types=1);

namespace App\Admin\Http\Controllers\Auth;

use App\Admin\Models\Operator;
use App\Admin\Models\OperatorPasskey;
use App\Admin\Services\AuditLog;
use App\Admin\Services\OperatorEmailRecovery;
use App\Admin\Services\OperatorSecurityNotices;
use App\Admin\Services\OperatorSessions;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;

/**
 * ログイン中の運営が、自分のパスキーを再確認・一覧・追加・削除する。
 * 追加と削除は直前の再確認（RequireRecentConfirmation）が要る。最後の 1 本は削除できない（締め出し防止）。
 */
final class PasskeyController
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly Ceremony $ceremony,
    ) {}

    public function index(): JsonResponse
    {
        $passkeys = $this->operator()->passkeys()->orderBy('created_at')->get()
            ->map(static fn (Passkey $passkey): array => [
                'id' => $passkey->getKey(),
                'name' => $passkey->getAttribute('name'),
                'authenticator' => $passkey->getAttribute('authenticator'),
                // 同期のパスキーか（そのアカウントの 2 段階認証を勧める）
                'synced' => $passkey instanceof OperatorPasskey && $passkey->isSynced(),
                'last_used_at' => $passkey->getAttribute('last_used_at')?->toIso8601String(),
                'created_at' => $passkey->getAttribute('created_at')?->toIso8601String(),
            ]);

        // 本数は一覧の長さと同じだが、ホームの知らせ（/auth/me）と同じ形でも返す
        return response()->json(['passkeys' => $passkeys, 'count' => $passkeys->count()]);
    }

    public function confirmOptions(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        $options = $generate($this->operator());
        $request->session()->put('passkey.verification_options', WebAuthn::toJson($options));

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function confirm(PasskeyVerificationRequest $request, VerifyPasskey $verify): JsonResponse
    {
        $operator = $this->operator();

        $this->ceremony->run($request, 'operator.confirm_failed', $operator,
            static fn () => $verify($request->credential(), $request->verificationOptions(), $operator));

        $request->session()->passwordConfirmed();
        $this->audit->record('operator.confirmed', $operator, ip: $request->ip());

        // ログインしたまま知らせを読んだ本人が再確認した: メールでの戻しの待ちを取り消す（戻ってから 24 時間のうちは取り消さない。ADR 0038）
        if (! $operator->recentlyRecovered()) {
            app(OperatorEmailRecovery::class)->cancelFor($operator, 'confirmed', $request->ip());
        }

        return response()->json(null, 204);
    }

    public function options(Request $request, GenerateRegistrationOptions $generate): JsonResponse
    {
        $options = $generate($this->operator());
        $request->session()->put('passkey.registration_options', WebAuthn::toJson($options));

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function store(PasskeyRegistrationRequest $request, StorePasskey $store): JsonResponse
    {
        $name = $request->validate(['name' => ['required', 'string', 'max:100']])['name'];
        $operator = $this->operator();
        $before = $operator->passkeys()->count();

        $passkey = $this->ceremony->run($request, 'operator.passkey_add_failed', $operator,
            static fn () => $store($operator, $name, $request->credential(), $request->registrationOptions()));

        $this->audit->record('operator.passkey_added', $operator, ['passkey_id' => (string) $passkey->getKey(), 'via' => 'self'], ip: $request->ip());
        // 本人へ知らせる（乗っ取った人が自分のパスキーを足したときに気づけるように。2）
        app(OperatorSecurityNotices::class)->passkeyAdded($operator, $name, $before);

        return response()->json(['id' => $passkey->getKey()], 201);
    }

    public function destroy(Request $request, string $passkeyId): JsonResponse
    {
        $operator = $this->operator();
        $sessions = app(OperatorSessions::class);
        $current = $sessions->current($request);
        $ended = 0;

        $removedName = DB::connection($operator->getConnectionName())->transaction(function () use ($operator, $passkeyId, $sessions, $current, &$ended): string {
            // 運営の行をロックして数える（2 つの削除が同時に走って 0 本になるのを防ぐ）
            $locked = Operator::query()->whereKey($operator->id)->lockForUpdate()->firstOrFail();
            $target = $operator->passkeys()->whereKey($passkeyId)->firstOrFail();

            // 回復用のコードで戻ってから 24 時間は、回復の前からあるパスキーを消せない（盗んだコードで入った人に本人を締め出させない。ADR 0038）。
            // 回復の後に足したパスキーは消せる（本人が元のパスキーで入って、見覚えの無いものを消せる）
            $protectedUntil = $target->getAttribute('protected_until');

            if ($protectedUntil !== null && CarbonImmutable::parse($protectedUntil)->isFuture()) {
                throw ValidationException::withMessages(['passkey' => '回復用のコードでログインしてから 24 時間は、それより前に登録したパスキーを削除できません。']);
            }

            if ($operator->passkeys()->count() <= 1) {
                throw ValidationException::withMessages(['passkey' => '最後のパスキーは削除できません。先に別のパスキーを追加してください。']);
            }

            $name = (string) $target->getAttribute('name');
            $target->delete();
            // そのパスキーで入ったセッションも切る（盗まれた端末のパスキーを消せば、その端末の画面も次の操作で締め出される）。
            // 消した本人が今使っている画面は残す
            $ended = $sessions->endForPasskey($operator, $passkeyId, $current);

            return $name;
        });
        $sessions->record($operator, 'passkey_removed', $ended, $request->ip());

        $this->audit->record('operator.passkey_removed', $operator, ['passkey_id' => $passkeyId], ip: $request->ip());
        app(OperatorSecurityNotices::class)->passkeyRemoved($operator, $removedName);

        return response()->json(null, 204);
    }

    private function operator(): Operator
    {
        $operator = Auth::guard('operator')->user();
        assert($operator instanceof Operator);

        return $operator;
    }
}
