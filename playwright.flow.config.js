import { defineConfig, devices } from '@playwright/test';
import { adminUrl } from './tests/e2e/flow/config.js';

// 通しの確かめ（docs/development/flow-check.md）。動いているアプリ（手元の make up か、本番のバックアップを戻した写し）に、
// 本物の Stripe（テストモード）でショップから買い、通知で確定し、発送・返金・在庫の戻り・定期便までを一続きで確かめる。
// サーバーは立てない（行き先は FLOW_ADMIN_URL・FLOW_SHOP_URL）。CI には Stripe の鍵が無いので流さない（playwright.config.js は tests/e2e/flow を外す）。
//   npm run e2e:flow                      全部
//   npm run e2e:flow -- -g "定期便"        1 つだけ（場面どうしは互いの結果に頼らない。管理画面のログインと鍵の設定は毎回する）
export default defineConfig({
    testDir: './tests/e2e/flow',
    grep: /@flow/,
    globalSetup: './tests/e2e/flow/global-setup.js',
    // 1 つずつ順に（同じ stripe listen と試しの店を使う。本物の Stripe と通知を待つので、速さより読みやすさ）
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 300_000,
    expect: { timeout: 30_000 },
    reporter: [['list']],
    outputDir: './test-results/flow/artifacts',
    use: {
        baseURL: adminUrl,
        testIdAttribute: 'data-test',
        locale: 'ja-JP',
        // 押す・入れるの待ちに上限を付ける（見つからない時に場面の上限の 5 分まで止まらない）
        actionTimeout: 30_000,
        navigationTimeout: 60_000,
        // トレースは撮らない（Stripe の鍵を入れる操作が記録に残る）。落ちた時の画面の写しだけ
        trace: 'off',
        screenshot: 'only-on-failure',
        reducedMotion: 'reduce',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
