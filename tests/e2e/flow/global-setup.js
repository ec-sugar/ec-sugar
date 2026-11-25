import { execFileSync, spawn } from 'node:child_process';
import { createWriteStream, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { assertNotProduction, externalWebhookSecret, shopDomainSuffix, shopUrl, STRIPE_EVENTS, stripeCli, stripeSecretKey, webhookMode } from './config.js';
import { runFlow } from './runner.js';

// 通しの確かめの下ごしらえ（1 回だけ）: 行き先が本番でないか → 試しの店を作る → Stripe の通知を店へ送る道（stripe listen）を立てる。
// 店の ID などは環境変数 FLOW_STATE で場面へ渡す（場面の処理は下ごしらえの後に立つので引き継がれる）。終わりの片付けは返す関数
export const outDir = join(process.cwd(), 'test-results', 'flow');

export default async function globalSetup() {
    assertNotProduction();
    const key = stripeSecretKey();
    mkdirSync(outDir, { recursive: true });
    writeFileSync(join(outDir, 'summary.json'), '[]');

    const guard = runFlow('guard');
    // FLOW_SHOP に残した店の ID を入れると、その店でもう一度流す（店を作り直さない。消すのは自分で）
    const shop = process.env.FLOW_SHOP ? runFlow('reuse', process.env.FLOW_SHOP) : runFlow('setup', shopDomainSuffix());
    console.log(`[flow] 確かめる先: APP_ENV=${guard.env}・メール=${guard.mailer}。試しの店 ${shop.id}（${shopUrl(shop.id)}）`);

    let listener = null;
    let secret;

    if (webhookMode === 'listen') {
        // 署名の鍵は、この端末とアカウントで決まる（stripe listen を立て直しても同じ）
        const env = { ...process.env, STRIPE_API_KEY: key };
        secret = execFileSync(stripeCli, ['listen', '--print-secret', '--skip-update'], { env, encoding: 'utf8' }).trim();

        if (!/^whsec_/.test(secret)) {
            throw new Error('stripe listen から署名の鍵を受け取れませんでした（stripe のコマンドと鍵を確かめてください）');
        }

        const forwardTo = shopUrl(shop.id, '/webhooks/stripe');
        listener = spawn(stripeCli, ['listen', '--skip-update', '--events', STRIPE_EVENTS.join(','), '--forward-to', forwardTo], { env, stdio: ['ignore', 'pipe', 'pipe'] });
        // 届いた通知と店の答え（[200] など）を残す。署名の鍵の行は残さない
        const log = createWriteStream(join(outDir, 'stripe-listen.log'));
        const ready = new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('stripe listen が 60 秒たっても立ちませんでした')), 60_000);
            const onData = (chunk) => {
                const text = chunk.toString().replace(/whsec_[A-Za-z0-9+/=]+/g, 'whsec_<伏せた>');
                log.write(text);

                if (text.includes('Ready!')) {
                    clearTimeout(timer);
                    resolve();
                }
            };
            listener.stdout.on('data', onData);
            listener.stderr.on('data', onData);
            listener.on('exit', (code) => reject(new Error(`stripe listen が止まりました（${code}）`)));
        });
        await ready;
        console.log(`[flow] Stripe の通知を ${forwardTo} へ送ります（stripe listen。記録は test-results/flow/stripe-listen.log）`);
    } else {
        secret = externalWebhookSecret();
        console.log('[flow] Stripe の通知は用意済みの送信先で受けます（FLOW_WEBHOOK=external）');
    }

    process.env.FLOW_STATE = JSON.stringify(shop);
    process.env.FLOW_WEBHOOK_SIGNING = secret;

    return async () => {
        listener?.kill();
        const summary = existsSync(join(outDir, 'summary.json')) ? JSON.parse(readFileSync(join(outDir, 'summary.json'), 'utf8')) : [];
        printSummary(summary);
        const failed = summary.some((row) => row.status === 'failed' || row.status === 'timedOut' || row.ng > 0);

        if (failed || process.env.FLOW_KEEP === '1' || shop.reused) {
            console.log(`[flow] 試しの店 ${shop.id} を残しました（調べ終えたら: npm run e2e:flow:cleanup -- ${shop.id}）`);
        } else {
            runFlow('cleanup', shop.id, shop.operator);
            console.log(`[flow] 試しの店 ${shop.id} を消しました`);
        }
    };
}

function printSummary(rows) {
    if (rows.length === 0) {
        return;
    }

    console.log('\n[flow] 結果');
    for (const row of rows) {
        const mark = row.status === 'skipped' ? '飛ばした' : (row.status === 'passed' && row.ng === 0 ? '通った' : '違った');
        console.log(`  ${mark.padEnd(4, '　')}  ${row.title}${row.note ? `（${row.note}）` : ''}${row.ng > 0 ? `  違い ${row.ng} 件` : ''}`);
    }
}
