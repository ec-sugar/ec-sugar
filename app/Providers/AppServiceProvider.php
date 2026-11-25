<?php

namespace App\Providers;

use App\Core\Mail\RedactedFailedJobs;
use App\Core\RateLimit\AtomicRateLimiter;
use App\Storefront\Access\ClientSource;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 回数の上限の数えを、同時の要求でも落とさない形に差し替える（ADR 0081。核の Throttle と経路の throttle の両方）。
        // 置き場は Laravel と同じ（cache.limiter、無ければ既定の置き場）。Laravel の CacheServiceProvider は後から読まれる（deferred）ので、
        // 束ね直さずに extend で包み直す
        $this->app->extend(CacheRateLimiter::class, static fn (CacheRateLimiter $limiter, Application $app): CacheRateLimiter => $limiter instanceof AtomicRateLimiter
            ? $limiter
            : new AtomicRateLimiter($app->make('cache')->driver($app->make('config')->get('cache.limiter'))));

        // 失敗した処理の記録に残す例外の文から、メールアドレスを伏せる（ADR 0112。送信サービスが宛先を文に入れて返すことがある）
        $this->app->extend('queue.failer', static fn (FailedJobProviderInterface $failer): FailedJobProviderInterface => $failer instanceof RedactedFailedJobs
            ? $failer
            : new RedactedFailedJobs($failer));
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * レート制限の定義。ルート側で throttle:<name> として使う。
     * 認証系は IP 単位で厳しく（総当たり対策）、API は利用者単位、店頭は IP 単位で緩く。
     * 接続元はどれも ClientSource（IPv6 は /64 にまとめる）。Cloudflare Tunnel の形で IPv6 のお客さんが来るので、
     * 住所ごとに数えると後ろの 64 ビットを替えるだけで抜けられる（の見直し）
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by(ClientSource::of($request->ip())));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by(
            (string) ($request->user()?->getAuthIdentifier() ?? ClientSource::of($request->ip())),
        ));

        RateLimiter::for('storefront', fn (Request $request) => Limit::perMinute(300)->by(ClientSource::of($request->ip())));
        // 店頭のカートの書き込み。カートを大量に作らせて DB を膨らませるのを防ぐ
        // 郵便番号から住所。外の API を大量に呼ばせない
        RateLimiter::for('storefront-postal', fn (Request $request) => Limit::perMinute(30)->by('storefront-postal|'.ClientSource::of($request->ip())));
        // ご注文の確認。注文番号とメールの総当たりを接続元ごとに止める（注文番号ごとの制限は受け口で）
        RateLimiter::for('storefront-order-lookup', fn (Request $request) => Limit::perMinute(10)->by('storefront-order-lookup|'.ClientSource::of($request->ip())));
        // 準備中のショップのパスワード。店をまたいだ総当たりを接続元ごとに止める（店ごと・ショップごとの間違いの数は受け口で）
        // IPv6 は /64 にまとめて数える（ClientSource）
        RateLimiter::for('storefront-password', fn (Request $request) => Limit::perMinute(20)->by('storefront-password|'.ClientSource::of($request->ip())));
        // 会員のログイン（ADR 0044 の 3）。コードを送る・確かめるを接続元ごとに（メールごとの数は MemberLogin で）
        RateLimiter::for('storefront-member', fn (Request $request) => Limit::perMinute(10)->by('storefront-member|'.tenant()?->getTenantKey().'|'.ClientSource::of($request->ip())));
        RateLimiter::for('storefront-cart', fn (Request $request) => Limit::perMinute(60)->by('storefront-cart|'.ClientSource::of($request->ip())));
        // クーポンのコード（ADR 0054 の 8）: 10 分に 10 回（店ごと・接続元ごと）
        RateLimiter::for('storefront-coupon', fn (Request $request) => Limit::perMinutes(10, 10)->by('storefront-coupon|'.tenant()?->getTenantKey().'|'.ClientSource::of($request->ip())));
        // 同意を決める（ADR 0034）。Cookie を書くだけだが、DB を読むので回数を絞る
        RateLimiter::for('storefront-consent', fn (Request $request) => Limit::perMinute(30)->by('storefront-consent|'.ClientSource::of($request->ip())));
        // 決済代行からの通知。正しい通知は署名で通るので、数は大きめ。署名の無い通知を大量に送らせない
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by('webhooks|'.ClientSource::of($request->ip())));
    }
}
