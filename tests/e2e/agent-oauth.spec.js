import { createHash, randomBytes } from 'node:crypto';
import { expect, test } from './support/fixtures.js';

// AI エージェントの OAuth（ADR 0101）。claude.ai などの「コネクタを追加」の流れを、ブラウザで通す:
// クライアントを登録 → /oauth/authorize を開く → 許可の画面（行き先のホストを大きく・名乗った名前は確かめていない）→ 店にチェック（既定は無し。ADR 0104）と「読むだけ」→
// 許可（パスキーの再確認）→ 登録した行き先へ code と state が戻る → 鍵に替えて /mcp に入れる → 設定の一覧に出て、取り消すと入れなくなる

const REDIRECT = 'https://client.example/api/mcp/auth_callback';

function base64url(buffer) {
    return buffer.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

test('コネクタからの頼みを許可の画面で許可すると、行き先へ code と state が戻り、鍵で店を読め、一覧から取り消せる', async ({ page, operator, makeTenant, playwright }) => {
    void operator;
    const tenant = makeTenant();
    await page.goto(`/tenants/${tenant.id}/settings/agents`);
    const origin = new URL(page.url()).origin;
    const machine = await playwright.request.newContext({ baseURL: origin });

    // クライアントの登録（Cookie を持たない機械の要求）
    const registered = await machine.post('/oauth/register', { data: { client_name: 'Claude', redirect_uris: [REDIRECT] } });
    expect(registered.status()).toBe(201);
    const clientId = (await registered.json()).client_id;

    // 行き先（AI エージェントのサービス）への移動は、ブラウザの中で受け止める
    let returned = null;
    await page.route('https://client.example/**', async (route) => {
        returned = route.request().url();
        await route.fulfill({ status: 200, contentType: 'text/plain', body: 'connected' });
    });

    const verifier = base64url(randomBytes(48));
    const challenge = base64url(createHash('sha256').update(verifier).digest());
    const query = new URLSearchParams({ response_type: 'code', client_id: clientId, redirect_uri: REDIRECT, code_challenge: challenge, code_challenge_method: 'S256', state: 'state-123', scope: 'read write' });
    await page.goto(`/oauth/authorize?${query}`);

    // 許可の画面: 行き先のホストを大きく、名乗った名前は確かめていないと出す
    await expect(page.getByRole('heading', { name: 'AI エージェントの接続', level: 1 })).toBeVisible();
    await expect(page.getByTestId('oauth-host')).toContainText('client.example');
    await expect(page.getByTestId('oauth-client-name')).toContainText('「Claude」');
    await expect(page.getByTestId('oauth-client-name')).toContainText('確認していません');
    // 店は既定で何も選ばない。選ぶまで許可できない
    await expect(page.getByTestId(`oauth-shop-${tenant.id}`)).not.toBeChecked();
    await expect(page.getByTestId('oauth-approve')).toBeDisabled();
    await page.getByTestId(`oauth-shop-${tenant.id}`).check();
    await expect(page.getByTestId('oauth-approve')).toBeEnabled();
    // 書けるを頼まれても、既定はいつも「読むだけ」（書けるは人が選んだ時だけ）
    await expect(page.getByTestId('oauth-scope-read')).toBeChecked();
    await expect(page.getByTestId('oauth-redirect-uri')).toContainText(REDIRECT);
    await expect(page.getByTestId('oauth-host-check')).toContainText('コネクタを追加」を押していなければ');

    // 許可はパスキーの再確認の後
    await page.getByTestId('oauth-approve').click();
    const dialog = page.getByRole('dialog', { name: '本人確認' });
    await expect(dialog).toBeVisible();
    await dialog.getByTestId('confirm-passkey').click();
    await dialog.getByTestId('confirm-continue').click();
    await expect.poll(() => returned).not.toBeNull();

    const back = new URL(returned);
    expect(`${back.origin}${back.pathname}`).toBe(REDIRECT);
    expect(back.searchParams.get('state')).toBe('state-123');
    const code = back.searchParams.get('code');
    expect(code).toMatch(/^[A-Za-z0-9_-]{43}$/);

    // 鍵に替えて /mcp に入れる
    const exchanged = await machine.post('/oauth/token', { form: { grant_type: 'authorization_code', code, redirect_uri: REDIRECT, client_id: clientId, code_verifier: verifier } });
    expect(exchanged.status()).toBe(200);
    const { access_token: access } = await exchanged.json();
    const callMcp = () => machine.post('/mcp', {
        data: { jsonrpc: '2.0', id: 1, method: 'tools/list', params: {} },
        headers: { Authorization: `Bearer ${access}`, Accept: 'application/json, text/event-stream' },
    });
    expect((await callMcp()).status()).toBe(200);

    // 設定の一覧に出て、取り消すと入れなくなる
    await page.goto(`/tenants/${tenant.id}/settings/agents`);
    const row = page.getByTestId('agent-grant-row').filter({ hasText: tenant.name }).filter({ hasText: 'client.example' });
    await expect(row.getByTestId('agent-grant-status')).toHaveText('接続中');
    await row.getByTestId('agent-grant-revoke').click();
    await row.getByTestId('confirm-agent-grant-revoke').click();
    await expect(row.getByTestId('agent-grant-status')).toHaveText('取り消し済み');
    expect((await callMcp()).status()).toBe(401);
    await machine.dispose();
});

test('登録と違う行き先の頼みは、行き先へ送らず許可の画面に誤りだけを出す', async ({ page, operator, playwright }) => {
    void operator;
    await page.goto('/');
    const origin = new URL(page.url()).origin;
    const machine = await playwright.request.newContext({ baseURL: origin });
    const registered = await machine.post('/oauth/register', { data: { client_name: 'Claude', redirect_uris: [REDIRECT] } });
    const clientId = (await registered.json()).client_id;
    await machine.dispose();

    const query = new URLSearchParams({ response_type: 'code', client_id: clientId, redirect_uri: `${REDIRECT}?next=https://evil.example`, code_challenge: 'a'.repeat(43), code_challenge_method: 'S256', state: 's' });
    await page.goto(`/oauth/authorize?${query}`);
    await expect(page).toHaveURL(/\/oauth\/consent\?error=invalid_redirect_uri$/);
    await expect(page.getByTestId('oauth-invalid')).toContainText('戻り先の URL が、接続するアプリの登録と一致しません');
    await expect(page.getByTestId('oauth-approve')).toHaveCount(0);
});
