<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Crypto\TenantCipher;
use App\Core\Event\Outbox\EventReceivers;
use App\Core\Event\Outbox\EventRelay;
use App\Core\IsolatedCopy;
use App\Core\Listing\ListingKinds;
use App\Core\Mail\MailRecords;
use App\Core\Mail\RecordingMailManager;
use App\Core\Party\Events\CustomerLeft;
use App\Core\Payment\Contracts\PaymentCredentialStore;
use App\Core\Payment\Contracts\PaymentDriver;
use App\Core\Payment\Contracts\PaymentWebhookReceiver;
use App\Core\Payment\Jobs\DetachStoredCards;
use App\Core\Payment\PaymentDrivers;
use App\Core\Payment\Services\PaymentGatewayService;
use App\Core\Plugin\PluginRegistry;
use App\Core\Plugin\Services\PluginStates;
use App\Core\Points\Services\PointService;
use App\Core\Sample\Demo\DemoMails;
use App\Core\Sample\Demo\DemoShop;
use App\Core\Trace\TraceId;
use App\Core\Transaction\TransactionFlows;
use App\Models\Tenant;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\MailManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;

/**
 * 核（app/Core）のサービス登録。
 */
final class CoreServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 隔離した写し（ADR 0082）なら、外への送信の口を閉じ、メールを Mailpit か送らない形にする
        IsolatedCopy::guard($this->app->make(HttpFactory::class));

        // 退会したら、預けたカードを決済代行から外す（ADR 0045 の 1。退会の確定の後に裏で。核の文脈どうしの頼り合いを作らないよう、つなぐのはここ）
        Event::listen(CustomerLeft::class, static fn (CustomerLeft $e) => DetachStoredCards::forAccount($e->accountId, null, $e->actor));
        // 退会したら、残っているポイントを同じトランザクションで消す（ADR 0068 の 5。退会した人は使えない）
        Event::listen(CustomerLeft::class, static fn (CustomerLeft $e) => app(PointService::class)->forfeit($e->accountId, $e->actor));
        // デモの店（ADR 0086）はメールを送らない。お客さんへのメールは来訪者の受け箱に置き、店の人への知らせは捨てる。偽を返すと Laravel は送らない。
        // 送るメールだけに、メールの記録の札を結ぶ（ADR 0095）。1 つの受け手の中で、止めるかを決めた後に結ぶ（止めたメールは記録に出ない）
        Event::listen(MessageSending::class, static function (MessageSending $e): ?bool {
            if (DemoShop::active()) {
                app(DemoMails::class)->capture($e->message);

                return false;
            }

            MailRecords::attach($e->message, $e->data);

            return null;
        });
        // キューの仕事のやり直しを、メールの記録の同じ行にまとめるための仕事の ID（ADR 0095）
        Event::listen(JobProcessing::class, static fn (JobProcessing $e) => MailRecords::jobStarted($e->job->uuid()));
        Event::listen(JobProcessed::class, static fn () => MailRecords::jobEnded());
        Event::listen(JobExceptionOccurred::class, static fn () => MailRecords::jobEnded());
        // メールの記録の別の接続は、店の文脈が変わるたびに捨てる（ほかの店へ持ち越さない）
        Event::listen(TenancyInitialized::class, static fn () => MailRecords::forgetWriter());
        Event::listen(TenancyEnded::class, static fn () => MailRecords::forgetWriter());

        $this->carryTraceId();
    }

    /**
     * 処理を追う ID（ADR 0087）を、要求の外の処理の始まりで決め、店の ID と一緒にログの文脈へ置く。
     * 要求は AssignTraceId が決める。キューの仕事は、積んだ時の Context（ID と店の ID）を Laravel が中身に載せて処理の前に戻す
     */
    private function carryTraceId(): void
    {
        // 裏の仕事を処理する前に、積んだ時の Context が戻った直後。積んだ時に ID が無かった（無い処理から積んだ）なら、仕事ごとに新しく決める
        Context::hydrated(static fn () => TraceId::ensure());
        // 定期の処理は、処理ごとに新しく決める（同じプロセスで動く関数・仕事も、別のプロセスで動くコマンドも。コマンドへは Laravel が Context を環境変数で渡す）
        Event::listen(ScheduledTaskStarting::class, static fn () => TraceId::start());
        // CLI のコマンド（人が流す・裏の仕事の処理役・定期の処理の親）。定期の処理から渡された ID があれば、それを使う
        Event::listen(CommandStarting::class, static function (): void {
            TraceId::ensure();

            // 環境変数から戻った店の ID は、店の文脈に入っていないなら外す（ログに入っていない店を書かない）
            if (! tenancy()->initialized) {
                Context::forget(TraceId::TENANT_KEY);
            }
        });
        // 店の文脈に入っている間は、ログに店の ID を添える（ID と店の ID で記録を引けるように。名前・お客さんは置かない）
        Event::listen(TenancyInitialized::class, static fn (TenancyInitialized $e) => Context::add(TraceId::TENANT_KEY, (string) $e->tenancy->tenant?->getTenantKey()));
        Event::listen(TenancyEnded::class, static fn () => Context::forget(TraceId::TENANT_KEY));
    }

    public function register(): void
    {
        // 出品物の種類。核は product だけ。縦のプラグインが自分の種類を register する
        $this->app->singleton(ListingKinds::class, static function (): ListingKinds {
            $kinds = new ListingKinds;
            $kinds->register(ListingKinds::product());

            return $kinds;
        });

        // 取引の種類（フェーズの並び）。核は即時物販（sale）だけ。縦のプラグインが自分の流れを register する
        $this->app->singleton(TransactionFlows::class, static function (): TransactionFlows {
            $flows = new TransactionFlows;
            $flows->register(TransactionFlows::sale());

            return $flows;
        });

        // 入っているプラグイン（ADR 0025）。プラグインのサービスプロバイダが boot で register する
        $this->app->singleton(PluginRegistry::class);

        // 記録の受け手の登録簿と送り出し役（ADR 0089）。核と App が boot で受け手を足す。
        // App の受け手は、店でその App を使っている間に起きた記録だけ受ける（使う・使わないはプラグインの状態から。核の文脈どうしの頼り合いを作らないよう、ここでつなぐ）
        $this->app->singleton(EventReceivers::class, static function (): EventReceivers {
            $receivers = new EventReceivers;
            $receivers->useAppStates(static fn (): array => app(PluginStates::class)->all());

            return $receivers;
        });
        $this->app->singleton(EventRelay::class);

        // メールの係を、送る口で送った・送れなかったを記録する形に（ADR 0095）。送り方（transport）は設定のまま
        $this->app->extend('mail.manager', static fn (MailManager $manager, $app): MailManager => new RecordingMailManager($app));

        // 決済代行の口（ADR 0026）。どの代行かは登録簿（PaymentDrivers）から引き、核は名前も型も知らない。
        // 登録するのはドライバ自身のサービスプロバイダ（Stripe は StripeServiceProvider）
        $this->app->singleton(PaymentDrivers::class);
        // 鍵の置き場の口（ADR 0107 の段 3）。核の金の流れは置き場の中身（今は Stripe の形）を知らない
        $this->app->bind(PaymentCredentialStore::class, PaymentGatewayService::class);
        $this->app->bind(PaymentDriver::class, static fn ($app) => $app->make($app->make(PaymentDrivers::class)->driverClass()));
        $this->app->bind(PaymentWebhookReceiver::class, static fn ($app) => $app->make($app->make(PaymentDrivers::class)->webhookClass()));

        // テナント鍵は呼び出しのたびに現在のテナントから引く（同一プロセスでテナントが切り替わるため、キャッシュしない）
        $this->app->singleton(TenantCipher::class, static fn (): TenantCipher => new TenantCipher(static function (): string {
            $tenant = tenant();

            if (! $tenant instanceof Tenant) {
                throw new RuntimeException('テナント文脈の外では暗号化できません（tenancy()->initialize() の中で使う）');
            }

            $encoded = $tenant->getAttribute('data_key');

            if (! is_string($encoded) || $encoded === '') {
                throw new RuntimeException("テナント {$tenant->getTenantKey()} にデータ鍵が発行されていません");
            }

            $key = base64_decode($encoded, true);

            if ($key === false) {
                throw new RuntimeException('テナント鍵の形式が不正です');
            }

            return $key;
        }));
    }
}
