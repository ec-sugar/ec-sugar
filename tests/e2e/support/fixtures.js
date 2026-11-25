import { execFileSync } from 'node:child_process';
import { test as base, expect } from '@playwright/test';
import { serverEnv } from '../env.js';

// ブラウザのテストの共通の部品。場面ごとに新しい運営・新しいテナントを作り、場面どうしが互いの結果に頼らない。
//   violations    全場面に自動で掛かる。CSP と Trusted Types の違反、スクリプトのエラーが 1 件でもあれば落とす
//   authenticator Chrome の仮想の認証器（パスキー）。add() で 2 つ目を足せる
//   operator      新しい運営をパスキーで登録してログインした状態（パスキーの画面にいる）
//   staff         staff(tenant, level) で、その店だけの段階のスタッフとして登録してログインする
//   makeTenant    新しいテナントを作る（商品・HTML 入りの名前・取引・テーマを選べる）

// tests/e2e/fixtures.php を呼ぶ。最後の行の JSON を返し、失敗したら出力を見せて止める
export function runFixture(...args) {
    const output = execFileSync('php', ['tests/e2e/fixtures.php', ...args], {
        env: { ...process.env, ...serverEnv },
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'inherit'],
    });

    try {
        return JSON.parse(output.trim().split('\n').pop() ?? '');
    } catch {
        throw new Error(`tests/e2e/fixtures.php ${args.join(' ')} が失敗しました:\n${output}`);
    }
}

const AUTHENTICATOR = {
    protocol: 'ctap2',
    transport: 'internal',
    hasResidentKey: true,
    hasUserVerification: true,
    isUserVerified: true,
    automaticPresenceSimulation: true,
};

// 登録用のリンクからウィザード（ようこそ → 確かめ → 登録 → できた）を通ってパスキーを登録し、パスキーの画面へ移る（ADR 0031）
async function enrollThroughWizard(page, url) {
    await page.goto(url);
    await page.getByTestId('wizard-start').click();
    await page.getByTestId('wizard-next').click();
    await page.getByTestId('passkey-name').fill('仮想の認証器 A');
    await page.getByTestId('enroll').click();
    await page.getByTestId('wizard-add-passkey').click();
    await expect(page).toHaveURL(/\/passkeys$/);
}

export const test = base.extend({
    violations: [async ({ page }, use) => {
        const found = [];
        page.on('console', (message) => {
            if (/Content Security Policy|Trusted Type|TrustedHTML|TrustedScript/i.test(message.text())) {
                found.push(message.text());
            }
        });
        page.on('pageerror', (error) => found.push(`pageerror: ${error.message}`));
        await use(found);
        expect(found, 'CSP / Trusted Types の違反やスクリプトのエラーが無い').toEqual([]);
    }, { auto: true }],

    authenticator: async ({ page }, use) => {
        const cdp = await page.context().newCDPSession(page);
        await cdp.send('WebAuthn.enable');
        const add = async () => (await cdp.send('WebAuthn.addVirtualAuthenticator', { options: AUTHENTICATOR })).authenticatorId;
        const first = await add();
        await use({ id: first, add, remove: (authenticatorId) => cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId }) });
    },

    operator: async ({ page, authenticator }, use) => {
        void authenticator; // 登録の前に仮想の認証器が要る
        const operator = runFixture('operator');
        await enrollThroughWizard(page, operator.enrollUrl);
        await use(operator);
    },

    // その店だけの段階（read・operate・money）を与えたスタッフをパスキーで登録してログインする（operator と一緒には使わない）
    staff: async ({ page, authenticator }, use) => {
        void authenticator;
        await use(async (tenant, level) => {
            const staff = runFixture('operator', `--grant=${tenant.id}:${level}`);
            await enrollThroughWizard(page, staff.enrollUrl);

            return staff;
        });
    },

    // eslint-disable-next-line no-empty-pattern
    // 作った店は場面の終わりに消す（最後の cleanup まで溜めると CI の VM のメモリの上の DB が埋まって遅くなる）。
    // DB を残して調べるときは E2E_KEEP_TENANTS=1
    makeTenant: async ({}, use) => {
        const made = [];
        await use((options = {}) => {
            const tenant = makeTenantNow(options);
            made.push(tenant.id);

            return tenant;
        });

        if (made.length > 0 && !process.env.E2E_KEEP_TENANTS) {
            try {
                runFixture('drop-tenants', ...made);
            } catch {
                // 消せなくても場面は落とさない（最後の cleanup が消す）
            }
        }
    },
});

function makeTenantNow(options) {
    return runFixture(
        'tenant',
        ...(options.listings ? [`--listings=${options.listings}`] : []),
        ...(options.drafts ? [`--draft=${options.drafts}`] : []),
        ...(options.stock ? [`--stock=${options.stock}`] : []),
        ...(options.htmlName ? ['--html-name'] : []),
        ...(options.transaction ? ['--transaction'] : []),
        // 入金待ちの注文の数（注文の一覧の絞り込み）
        ...(options.awaiting ? [`--awaiting=${options.awaiting}`] : []),
        ...(options.stripe ? ['--stripe'] : []),
        ...(options.theme ? [`--theme=${options.theme}`] : []),
        // テーマのトップに区画の種類 × パターンをすべて並べ、自由なページ /pages/about も作る（ADR 0039 区切り 4）
        ...(options.everyPattern ? ['--every-pattern'] : []),
        // テーマの見本の絵を撮るときの店（名前は SAMPLE SHOP・商品ごとに無地の写真と在庫）
        ...(options.showcase ? ['--showcase'] : []),
        // 動画と地図の App を使い、トップに動画と地図の区画を足して公開する（ADR 0040）
        ...(options.embeds ? ['--embeds'] : []),
        ...(options.noApps ? ['--no-apps'] : []),
        // 見本のタグの App を使い、ID を入れる（ADR 0034）。consentBanner で同意の帯を出す店にする（既定は出さない）
        ...(options.tags ? ['--tags'] : []),
        ...(options.consentBanner ? ['--consent-banner'] : []),
        // ショップを準備中のまま（既定は公開）と、入れたときのサンプル。ホームの開設の手順
        ...(options.preparing ? ['--preparing'] : []),
        ...(options.samples ? ['--samples'] : []),
        // 返金が要る支払いのある店（運営全体の画面の困りごと）
        ...(options.stray ? ['--stray'] : []),
        // お客さんの記録（名前と電話が同じでメールの違う 3 人と会員 1 人。返す customers に ID とメール）
        ...(options.customers ? ['--customers'] : []),
        // 定期便の App を使う（ADR 0050。商品の画面に「App」の節が出る）
        ...(options.subscription ? ['--subscription'] : []),
        // 定期便の App を使い、会員の定期便を 2 つ（続いている・お休み）入れる（管理画面の App の画面。ADR 0051。返す subscriptions に ID）
        ...(options.subscriptions ? ['--subscriptions'] : []),
    );
}

export { expect };
