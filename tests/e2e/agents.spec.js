import { expect, test } from './support/fixtures.js';

// 設定 › AI エージェント（ADR 0100）。鍵を作る（直前の再確認つき）→ 今だけ鍵の帯が出る → 閉じると一覧に頭の 8 文字だけ →
// 取り消すと状態が変わり、鍵で MCP の入口に入れなくなる。鍵は画面を閉じた後・読み込み直した後・ブラウザの保存領域のどこにも残らない。
// スマホの幅（390px）で横にはみ出さない

const SHAPE = /^ecs_agent_[A-Za-z0-9_-]{43}$/;

function rpc(method) {
    return { jsonrpc: '2.0', id: 1, method, params: {} };
}

// MCP の入口へ、Cookie を持たない要求で鍵を送る（エージェントと同じ形）
async function callMcp(request, secret) {
    return request.post('/mcp', {
        data: rpc('tools/list'),
        headers: { Authorization: `Bearer ${secret}`, Accept: 'application/json, text/event-stream' },
    });
}

test('AI エージェントの鍵を再確認の後に作り、今だけ見せ、閉じると頭の 8 文字だけが残り、取り消すと入れなくなる', async ({ page, operator, makeTenant, playwright }) => {
    void operator;
    const tenant = makeTenant();
    const name = `Claude Desktop ${Date.now()}`;

    await page.goto(`/tenants/${tenant.id}/settings/agents`);
    await expect(page.getByRole('heading', { name: 'AI エージェント', level: 1 })).toBeVisible();
    // つなぎ方の URL は、この管理画面と同じホストの /mcp。例の中の鍵は置き換えの文字
    const origin = new URL(page.url()).origin;
    await expect(page.getByRole('figure', { name: 'MCP の URL' })).toContainText(`${origin}/mcp`);
    // 例は鍵をコマンドの引数に直に書かず、環境変数で渡す（シェルの履歴・プロセスの一覧に残さない）。中継の mcp-remote はバージョンを固定する
    await expect(page.getByTestId('agent-example-cli')).toContainText('read -rs EC_SUGAR_AGENT_KEY');
    await expect(page.getByTestId('agent-example-cli')).toContainText('Authorization: Bearer $EC_SUGAR_AGENT_KEY');
    await expect(page.getByTestId('agent-example-http-json')).toContainText('"Authorization": "Bearer ${EC_SUGAR_AGENT_KEY}"');
    await expect(page.getByTestId('agent-example-claude-desktop')).toContainText('"mcp-remote@0.16.0"');
    await expect(page.getByTestId('agent-example-claude-desktop')).toContainText('"EC_SUGAR_AGENT_KEY_HEADER": "Bearer ここにアクセスキー"');

    // 作る。サーバーが再確認を求め、パスキーで確かめると作り直す
    await page.getByTestId('agent-name').fill(name);
    await page.getByTestId('agent-days-30').check();
    // 店は今の店が先に選ばれている（見られる店が 1 つなら欄が出ない）
    if (await page.getByTestId('agent-shop').count() > 0) {
        await expect(page.getByTestId('agent-shop')).toHaveValue(tenant.id);
    }
    await page.getByTestId('agent-issue').click();
    const dialog = page.getByRole('dialog', { name: '本人確認' });
    await expect(dialog).toBeVisible();
    await dialog.getByTestId('confirm-passkey').click();
    await dialog.getByTestId('confirm-continue').click();

    // 今だけの鍵の帯
    const shown = page.getByTestId('agent-secret');
    await expect(shown).toBeVisible();
    await expect(shown.getByTestId('agent-secret-once')).toContainText('このアクセスキーは今だけ表示します。閉じると二度と見られません');
    const secret = (await shown.getByTestId('code-block').textContent()).trim();
    expect(secret).toMatch(SHAPE);
    expect(page.url()).not.toContain(secret);
    const stored = await page.evaluate(() => JSON.stringify({ local: { ...localStorage }, session: { ...sessionStorage } }));
    expect(stored).not.toContain(secret);

    // 鍵で MCP の入口に入れる（Cookie は持たない）
    const agent = await playwright.request.newContext({ baseURL: origin });
    expect((await callMcp(agent, secret)).status()).toBe(200);

    // 閉じると二度と出ない。一覧には頭の 8 文字だけ
    await page.getByTestId('agent-secret-close').click();
    await expect(shown).toBeHidden();
    const row = page.getByTestId('agent-row').filter({ hasText: name });
    await expect(row.getByTestId('agent-prefix')).toHaveText(`${secret.slice(0, 18)}…`);
    await expect(row.getByTestId('agent-status')).toHaveText('有効');
    await expect(row).toContainText(tenant.name);
    expect(await page.content()).not.toContain(secret);
    await page.reload();
    await expect(page.getByTestId('agent-row').filter({ hasText: name })).toBeVisible();
    expect(await page.content()).not.toContain(secret);

    // スマホの幅で横にはみ出さない（一覧は札の並び、設定の例は折り返す）
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByTestId('agent-row').filter({ hasText: name })).toBeVisible();
    const widths = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, view: window.innerWidth }));
    expect(widths.scroll, `横幅 ${widths.view}px からはみ出している`).toBeLessThanOrEqual(widths.view);
    await page.setViewportSize({ width: 1280, height: 900 });

    // 取り消す（確かめてから）。状態が変わり、取り消しのボタンは消え、鍵では入れなくなる
    const again = page.getByTestId('agent-row').filter({ hasText: name });
    await again.getByTestId('agent-revoke').click();
    await again.getByTestId('confirm-agent-revoke').click();
    await expect(page.getByRole('status').filter({ hasText: 'のアクセスキーを取り消しました' })).toBeVisible();
    await expect(again.getByTestId('agent-status')).toHaveText('取り消し済み');
    await expect(again.getByTestId('agent-revoke')).toHaveCount(0);
    expect((await callMcp(agent, secret)).status()).toBe(401);
    await agent.dispose();
});

// ADR 0101: 書ける鍵を作り、店の設定は「承認してから」（既定）のまま → エージェントの create_product は案になる →
// メニューの「AI エージェントの案」で要約を見て承認すると、反映した状態になる
test('書ける鍵の AI エージェントの案を、AI エージェントの案の画面で承認して反映する', async ({ page, operator, makeTenant, playwright }) => {
    void operator;
    const tenant = makeTenant();
    const title = `季節のジャム ${Date.now()}`;

    await page.goto(`/tenants/${tenant.id}/settings/agents`);
    await expect(page.getByTestId('agent-shop-settings')).toBeVisible();
    await expect(page.getByTestId('agent-write-mode-approval')).toBeChecked();
    await expect(page.getByTestId('agent-customer-data-off')).toBeChecked();

    await page.getByTestId('agent-name').fill('書く鍵');
    await page.getByTestId('agent-scope-write').check();
    // 書ける鍵の期限は 7・30・90 日（はじめは 30 日）
    await expect(page.getByTestId('agent-days-30')).toBeChecked();
    await expect(page.getByTestId('agent-days-365')).toHaveCount(0);
    await page.getByTestId('agent-issue').click();
    const dialog = page.getByRole('dialog', { name: '本人確認' });
    await dialog.getByTestId('confirm-passkey').click();
    await dialog.getByTestId('confirm-continue').click();
    const secret = (await page.getByTestId('agent-secret').getByTestId('code-block').textContent()).trim();
    await page.getByTestId('agent-secret-close').click();
    await expect(page.getByTestId('agent-row').filter({ hasText: '書く鍵' })).toContainText('閲覧と変更');

    const origin = new URL(page.url()).origin;
    const agent = await playwright.request.newContext({ baseURL: origin });
    const answer = await agent.post('/mcp', {
        data: { jsonrpc: '2.0', id: 2, method: 'tools/call', params: { name: 'create_product', arguments: { title, price: 1200 } } },
        headers: { Authorization: `Bearer ${secret}`, Accept: 'application/json, text/event-stream' },
    });
    expect(answer.status()).toBe(200);
    expect(answer.headers()['cache-control']).toContain('no-store');
    const result = JSON.parse((await answer.json()).result.content[0].text).result;
    expect(result.status).toBe('proposed');
    await agent.dispose();

    await page.goto(`/tenants/${tenant.id}/agent-proposals`);
    const row = page.getByTestId('proposal-row').filter({ hasText: title });
    await expect(row.getByTestId('proposal-status')).toHaveText('承認待ち');
    await row.getByRole('link').click();
    await expect(page.getByTestId('proposal-summary')).toContainText(`商品「${title}」を下書きで登録する（¥1,200`);
    await page.getByTestId('proposal-approve').click();
    await page.getByTestId('confirm-proposal-approve').click();
    await expect(page.getByRole('status').filter({ hasText: '承認して反映しました' })).toBeVisible();
    await expect(page.getByTestId('proposal-status')).toHaveText('承認・反映済み');
    await expect(page.getByTestId('proposal-approve')).toHaveCount(0);
});
