<?php

declare(strict_types=1);

namespace App\Core\Party\Services;

use App\Core\Crypto\TenantCipher;
use App\Core\Event\EventStore;
use App\Core\Party\Models\CustomerAccount;
use App\Core\Party\Models\CustomerPasskey;
use Cose\Algorithms;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * 会員のパスキー（ADR 0044 の 3、ADR 0023）。任意で足せて、ログインと本人の再確認に使える。
 *
 * - laravel/passkeys は運営（中央の DB・管理画面のホスト）の作りなので、ここでは使わず、その下の WebAuthn のライブラリを直に呼ぶ
 *   （JSON との変換の部品だけ借りる）。結びつく先（relying party）は、そのショップのホストとオリジン
 * - 本人の確かめ（指紋・顔・暗証番号）を必ず求め、見つけられるパスキー（メールを入れずに入れる）で作る。認証器の証明書は確かめない（attestation none）
 * - 会員の取っ手（user handle）は店の鍵の HMAC（台帳の ID を認証器に渡さない）
 * - 足す・消すは連鎖のロック → 台帳の行のロックの順で書き、イベントには ID だけ。本人の再確認は呼ぶ側
 */
final class CustomerPasskeys
{
    public const int LIMIT = 10;

    private const int TIMEOUT = 60_000;

    public function __construct(private readonly EventStore $events) {}

    /**
     * @return Collection<int, CustomerPasskey>
     */
    public function list(CustomerAccount $account): Collection
    {
        return CustomerPasskey::query()->where('customer_account_id', $account->id)->orderBy('created_at')->orderBy('id')->get();
    }

    public function registrationOptions(CustomerAccount $account, string $host, string $shopName): PublicKeyCredentialCreationOptions
    {
        $type = PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY;

        return PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create(name: mb_substr($shopName, 0, 100), id: $host),
            user: PublicKeyCredentialUserEntity::create(name: $account->email, id: self::handle($account), displayName: $account->email),
            challenge: random_bytes(32),
            pubKeyCredParams: [
                PublicKeyCredentialParameters::create($type, Algorithms::COSE_ALGORITHM_ES256),
                PublicKeyCredentialParameters::create($type, Algorithms::COSE_ALGORITHM_RS256),
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                authenticatorAttachment: AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_NO_PREFERENCE,
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $this->descriptors($account),
            timeout: self::TIMEOUT,
        );
    }

    /**
     * @param  CustomerAccount|null  $account  本人の再確認なら、その会員のパスキーだけを候補に。ログインなら null（見つけられるパスキー）
     */
    public function requestOptions(?CustomerAccount $account, string $host): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: $host,
            allowCredentials: $account === null ? [] : $this->descriptors($account),
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: self::TIMEOUT,
        );
    }

    /**
     * 決まったパスキーだけを候補にした確かめのオプション（App「デジタルチケット」が、買う時に結んだパスキーで当日に確かめる）。
     * 本人の確かめ（指紋・顔・暗証番号）は必ず求める。$credentialIds はその会員のパスキーの ID だけが残る（ほかの会員の ID は候補にしない）
     *
     * @param  list<string>  $credentialIds
     */
    public function requestOptionsFor(CustomerAccount $account, array $credentialIds, string $host): PublicKeyCredentialRequestOptions
    {
        $allowed = $this->list($account)->filter(static fn (CustomerPasskey $p): bool => in_array($p->credential_id, $credentialIds, true))
            ->map(static fn (CustomerPasskey $p): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                Base64UrlSafe::decodeNoPadding($p->credential_id),
            ))->values()->all();

        return PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: $host,
            allowCredentials: $allowed,
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: self::TIMEOUT,
        );
    }

    /**
     * 足す。確かめられなければ CustomerException（理由は言わない）
     */
    public function register(CustomerAccount $account, string $name, string $credentialJson, string $optionsJson, string $origin, string $host, string $actor): CustomerPasskey
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 60) {
            throw new CustomerException('パスキーの名前は 60 文字以内で入力してください。');
        }

        try {
            $credential = WebAuthn::fromJson($credentialJson, PublicKeyCredential::class);
            $options = WebAuthn::fromJson($optionsJson, PublicKeyCredentialCreationOptions::class);
            $response = $credential->response;

            if (! $response instanceof AuthenticatorAttestationResponse || $options->user->id !== self::handle($account)) {
                throw new CustomerException(self::FAILED);
            }

            $record = AuthenticatorAttestationResponseValidator::create(self::steps($origin)->creationCeremony())
                ->check($response, $options, $host);
        } catch (CustomerException $e) {
            throw $e;
        } catch (Throwable) {
            throw new CustomerException(self::FAILED);
        }

        $credentialId = Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId);

        return DB::transaction(function () use ($account, $name, $record, $credentialId, $actor): CustomerPasskey {
            $this->events->lock();
            $locked = $this->member($account);

            if (CustomerPasskey::query()->where('credential_id', $credentialId)->exists()) {
                throw new CustomerException(self::FAILED);
            }

            if (CustomerPasskey::query()->where('customer_account_id', $locked->id)->count() >= self::LIMIT) {
                throw new CustomerException('パスキーは '.self::LIMIT.' 本まで登録できます。使わないパスキーを削除してから追加してください。');
            }

            $passkey = CustomerPasskey::create(['customer_account_id' => $locked->id, 'name' => $name, 'credential_id' => $credentialId, 'credential' => WebAuthn::toJson($record)]);
            $this->events->append('customer_passkey.added', 'customer_account', $locked->id, ['passkey_id' => $passkey->id], $actor);

            return $passkey;
        });
    }

    /**
     * 確かめる。合えば会員の台帳（会員で、止めていない）。$expected があれば、その会員のパスキーでなければ null
     */
    public function verify(string $credentialJson, string $optionsJson, string $origin, string $host, ?CustomerAccount $expected = null): ?CustomerAccount
    {
        return $this->check($credentialJson, $optionsJson, $origin, $host, $expected)?->account;
    }

    /**
     * その会員のパスキーで確かめ、合えば使ったパスキーの ID（credential の ID。生体の情報ではない）。合わなければ null。
     * 本人の確かめ（UV）の印はオプションの userVerification（必ず求める）に従ってライブラリが確かめる
     */
    public function verifyCredential(string $credentialJson, string $optionsJson, string $origin, string $host, CustomerAccount $expected): ?string
    {
        return $this->check($credentialJson, $optionsJson, $origin, $host, $expected)?->credentialId;
    }

    /**
     * @return object{account: CustomerAccount, credentialId: string}|null
     */
    private function check(string $credentialJson, string $optionsJson, string $origin, string $host, ?CustomerAccount $expected): ?object
    {
        try {
            $credential = WebAuthn::fromJson($credentialJson, PublicKeyCredential::class);
            $options = WebAuthn::fromJson($optionsJson, PublicKeyCredentialRequestOptions::class);
        } catch (Throwable) {
            return null;
        }

        $response = $credential->response;

        if (! $response instanceof AuthenticatorAssertionResponse) {
            return null;
        }

        return DB::transaction(function () use ($credential, $response, $options, $origin, $host, $expected): ?object {
            $passkey = CustomerPasskey::query()->where('credential_id', Base64UrlSafe::encodeUnpadded($credential->rawId))->lockForUpdate()->first();

            if ($passkey === null || ($expected !== null && $passkey->customer_account_id !== $expected->id)) {
                return null;
            }

            $account = CustomerAccount::query()->whereKey($passkey->customer_account_id)->whereNotNull('member_since')->whereNull('disabled_at')->first();

            if ($account === null) {
                return null;
            }

            try {
                $record = WebAuthn::fromJson($passkey->credential, CredentialRecord::class);
                $record = AuthenticatorAssertionResponseValidator::create(self::steps($origin)->requestCeremony())
                    ->check($record, $response, $options, $host, self::handle($account));
            } catch (Throwable) {
                return null;
            }

            $passkey->credential = WebAuthn::toJson($record);
            $passkey->last_used_at = now()->toImmutable();
            $passkey->save();

            return (object) ['account' => $account, 'credentialId' => $passkey->credential_id];
        });
    }

    public function remove(CustomerAccount $account, string $id, string $actor): void
    {
        DB::transaction(function () use ($account, $id, $actor): void {
            $this->events->lock();
            $locked = $this->member($account);
            $passkey = preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $id) === 1
                ? CustomerPasskey::query()->where('customer_account_id', $locked->id)->whereKey($id)->lockForUpdate()->first()
                : null;

            if ($passkey === null) {
                throw new CustomerException('そのパスキーは見つかりません。');
            }

            $passkey->delete();
            $this->events->append('customer_passkey.removed', 'customer_account', $locked->id, ['passkey_id' => $passkey->id], $actor);
        });
    }

    /**
     * 退会の時: 全部消す（CustomerAccounts::leave のロックの中から呼ぶ）
     */
    public function forgetAll(CustomerAccount $account): int
    {
        return CustomerPasskey::query()->where('customer_account_id', $account->id)->delete();
    }

    private const string FAILED = 'パスキーを確認できませんでした。もう一度お試しください。';

    /**
     * @return list<PublicKeyCredentialDescriptor>
     */
    private function descriptors(CustomerAccount $account): array
    {
        return $this->list($account)->map(static fn (CustomerPasskey $p): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            Base64UrlSafe::decodeNoPadding($p->credential_id),
        ))->values()->all();
    }

    private function member(CustomerAccount $account): CustomerAccount
    {
        $locked = CustomerAccount::query()->whereKey($account->id)->lockForUpdate()->first();

        if ($locked === null || $locked->member_since === null || $locked->disabled_at !== null) {
            throw new CustomerException('会員としてログインし直してください。');
        }

        return $locked;
    }

    private static function handle(CustomerAccount $account): string
    {
        return app(TenantCipher::class)->index('customer-passkey-handle|'.$account->id);
    }

    private static function steps(string $origin): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory;
        $factory->setAllowedOrigins([$origin]);
        $support = AttestationStatementSupportManager::create();
        $support->add(NoneAttestationStatementSupport::create());
        $factory->setAttestationStatementSupportManager($support);

        return $factory;
    }
}
