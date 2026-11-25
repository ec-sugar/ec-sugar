import { defineConfig, devices } from '@playwright/test';
import { adminUrl, port, serverEnv, stripePort, stripeUrl, webhookSecret } from './tests/e2e/env.js';

// 管理画面のブラウザでのテスト（場面ごとに独立させたのは ）。本物の Chromium と、Chrome の仮想の認証器でパスキーを登録してログインする。
// 画面は `npm run build` 済みのものを PHP のサーバーが配る（Node.js のサーバーは立てない。ADR 0015）。
// 場面は互いの結果に頼らない（運営とテナントは場面ごとに作る。tests/e2e/support/fixtures.js）ので、並べて同時に走らせる。
// 1 つだけ走らせるとき: npm run e2e -- -g "取引の一覧"
export default defineConfig({
    testDir: './tests/e2e',
    // 通しの確かめ（本物の Stripe。札 @flow）は、毎回の CI でも夜の CI でも流さない（CI に Stripe の鍵が無い。playwright.flow.config.js で手で流す）
    testIgnore: ['flow/**'],
    grepInvert: /@flow/,
    globalSetup: './tests/e2e/global-setup.js',
    globalTeardown: './tests/e2e/global-teardown.js',
    fullyParallel: true,
    workers: process.env.CI ? 2 : 4,
    forbidOnly: !!process.env.CI,
    retries: 0,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: adminUrl,
        testIdAttribute: 'data-test',
        trace: 'retain-on-failure',
        // 端末の「動きを減らす」の形で開く。管理画面の動き（画面の切り替え・開き閉じ・保存の印）を止め、
        // 消える動きの間に古い要素が残ったり、動いている途中のボタンを押したりして結果が揺れないようにする
        reducedMotion: 'reduce',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
    webServer: [{
        // --no-reload: 渡した環境変数を PHP のサーバーへそのまま引き継ぐ（.env の値で上書きさせない）
        // 先にマイグレーションを流す: /up は中央の DB に流していないマイグレーションがあると 500 を返す（ADR 0043）。
        // サーバーは下ごしらえ（globalSetup の reset）より先に立ち、/up が 200 になるのを待つので、空の ec_e2e（CI）や古い ec_e2e のままだと待ちきれない
        command: `php artisan migrate --force --no-interaction && php artisan serve --host=127.0.0.1 --port=${port} --no-reload`,
        url: `http://127.0.0.1:${port}/up`,
        // PHP の組み込みのサーバーを複数の処理で動かし、同時に走る場面のリクエストをさばく
        env: { ...serverEnv, PHP_CLI_SERVER_WORKERS: '4' },
        reuseExistingServer: false,
        stdout: 'ignore',
        stderr: 'pipe',
        timeout: 60_000,
    }, {
        // 偽の Stripe（支払いの画面を作って答える。本物の Stripe には繋がない）
        command: `php -S 127.0.0.1:${stripePort} tests/e2e/fake-stripe.php`,
        url: `${stripeUrl}/v1/up`,
        env: { E2E_STRIPE_PORT: String(stripePort), E2E_WEBHOOK_SECRET: webhookSecret, PHP_CLI_SERVER_WORKERS: '2' },
        reuseExistingServer: false,
        stdout: 'ignore',
        stderr: 'pipe',
        timeout: 30_000,
    }],
});
