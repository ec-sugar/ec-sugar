import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

// 通しの確かめ（docs/development/flow-check.md）の行き先と Stripe の鍵。値は環境変数で変える（既定は手元の開発の環境 make up）。
//
//   FLOW_ADMIN_URL        管理画面（既定 http://admin.localhost:8080）
//   FLOW_SHOP_URL         ショップの URL の形。{shop} を店の ID に置き換える（既定 http://{shop}.localhost:8080）
//   FLOW_PHP              確かめる先のアプリの PHP を動かすコマンド（既定 docker exec -i ec-sugar-app-1 php。写しなら ssh … docker exec -i … php）
//   FLOW_ENV_FILE         Stripe のテストの鍵を読む .env（既定 ./.env。worktree では本体の .env を指す）
//   FLOW_STRIPE_KEY_NAME  .env の中の鍵の名前（既定 STRIPE_PLATFORM_SECRET_KEY）
//   FLOW_WEBHOOK          Stripe の通知の受け方。listen（既定。stripe listen を立てて店へ送る）か external（自分で用意した送信先。FLOW_WEBHOOK_SECRET に署名の鍵）
//   FLOW_STRIPE_CLI       stripe のコマンド（既定 stripe）
//   FLOW_MAILPIT_URL      届いたメールを見る Mailpit（既定 http://127.0.0.1:8025。届かなければメールは確かめずに先へ）
//   FLOW_SHOP             残した試しの店の ID。店を作り直さずにその店で流す
//   FLOW_KEEP             1 なら、全部通っても試しの店を消さない（落ちたときはいつも残す）
//
// 本番のホスト（ec-sugar.jp）を行き先にしたら、何もせずに止める。鍵は sk_test_ / rk_test_ だけを受ける。鍵の値は表示も記録もしない

export const PRODUCTION_HOST = /(^|\.)ec-sugar\.jp$/i;

export const adminUrl = (process.env.FLOW_ADMIN_URL || 'http://admin.localhost:8080').replace(/\/+$/, '');
export const shopUrlPattern = process.env.FLOW_SHOP_URL || 'http://{shop}.localhost:8080';
export const phpCommand = process.env.FLOW_PHP || 'docker exec -i ec-sugar-app-1 php';
export const webhookMode = process.env.FLOW_WEBHOOK || 'listen';
export const stripeCli = process.env.FLOW_STRIPE_CLI || 'stripe';
export const mailpitUrl = (process.env.FLOW_MAILPIT_URL ?? 'http://127.0.0.1:8025').replace(/\/+$/, '');

export function shopUrl(shop, path = '/') {
    return `${shopUrlPattern.replace('{shop}', shop).replace(/\/+$/, '')}${path}`;
}

// ショップのホストの後ろ（店の ID の次。例 localhost）。店のドメインを決めるのに使う
export function shopDomainSuffix() {
    const host = new URL(shopUrl('flowprobe')).hostname;

    if (!host.startsWith('flowprobe.')) {
        throw new Error(`FLOW_SHOP_URL（${shopUrlPattern}）は http://{shop}.<ホスト>:<ポート> の形にしてください`);
    }

    return host.slice('flowprobe.'.length);
}

// 行き先が本番でないか（最初に 1 回）。ホストの名前だけで決める。写しはトンネルで localhost として入る
export function assertNotProduction() {
    for (const [name, url] of [['FLOW_ADMIN_URL', adminUrl], ['FLOW_SHOP_URL', shopUrl('flowprobe')]]) {
        const host = new URL(url).hostname;

        if (PRODUCTION_HOST.test(host)) {
            throw new Error(`${name} が本番のホスト（${host}）を向いています。通しの確かめは本番では流しません（写しには SSH のトンネルで localhost として入る）`);
        }
    }

    if (!['listen', 'external'].includes(webhookMode)) {
        throw new Error('FLOW_WEBHOOK は listen か external にしてください');
    }
}

// .env から Stripe のテストの鍵を読む（値は返すだけで、表示しない）
export function stripeSecretKey() {
    const file = resolve(process.env.FLOW_ENV_FILE || '.env');
    const name = process.env.FLOW_STRIPE_KEY_NAME || 'STRIPE_PLATFORM_SECRET_KEY';

    if (!existsSync(file)) {
        throw new Error(`Stripe の鍵を読む .env がありません（${file}）。FLOW_ENV_FILE で本体の .env を指してください`);
    }

    const line = readFileSync(file, 'utf8').split('\n').find((l) => l.startsWith(`${name}=`));
    const key = (line ?? '').slice(name.length + 1).trim().replace(/^["']|["']$/g, '');

    if (!/^(sk|rk)_test_[A-Za-z0-9]{16,247}$/.test(key)) {
        throw new Error(`${file} の ${name} が Stripe のテストの鍵（sk_test_ / rk_test_）ではありません。本番の鍵では流しません`);
    }

    return key;
}

// 通知の署名の鍵（external のとき。listen のときは global-setup が stripe listen から受け取る）
export function externalWebhookSecret() {
    const secret = process.env.FLOW_WEBHOOK_SECRET ?? '';

    if (!/^whsec_[A-Za-z0-9+/=]{16,200}$/.test(secret)) {
        throw new Error('FLOW_WEBHOOK=external のときは、店の送信先の署名の鍵を FLOW_WEBHOOK_SECRET に入れてください');
    }

    return secret;
}

// 店がほしい Stripe の通知の種類（app/Admin/Http/Controllers/Api/PaymentSettingsController::STRIPE_EVENTS と同じ）
export const STRIPE_EVENTS = [
    'checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired',
    'payment_intent.succeeded', 'payment_intent.payment_failed', 'refund.created', 'refund.updated', 'refund.failed',
];
