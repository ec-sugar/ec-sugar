import { expect, runFixture, test } from './support/fixtures.js';

// ログインと登録・パスキーの管理・セッション切れ。パスキーは Chrome の仮想の認証器、サーバー側の検証は本物

test('ログインしていなければ、どの画面もログインへ戻る', async ({ page }) => {
    for (const path of ['/tenants', '/passkeys', '/tenants/e2enone/listings']) {
        await page.goto(path);
        await expect(page).toHaveURL(/\/login$/);
    }

    await expect(page.getByTestId('login')).toBeVisible();
});

test('無効な登録リンクは断られ、トークンは URL に残らない', async ({ page }) => {
    await page.goto(`/enroll#${'0'.repeat(64)}`);
    await expect(page.getByTestId('enroll-invalid')).toBeVisible();
    expect(page.url()).not.toContain('#');

    await page.goto('/enroll#not-a-token');
    await expect(page.getByTestId('enroll-invalid')).toBeVisible();
});

test('登録リンクからパスキーを登録してログインし、同じリンクは 2 度使えない', async ({ page, authenticator }) => {
    void authenticator;
    const operator = runFixture('operator');

    await page.goto(operator.enrollUrl);
    await expect(page.getByTestId('enroll-operator')).toHaveText(operator.name);
    // トークンは読んだらすぐ URL から消える（履歴・画面共有に残さない）
    expect(page.url()).toMatch(/\/enroll$/);

    // ウィザード: ようこそ → このブラウザの確かめ → 登録 → できた（ADR 0031）
    await page.getByTestId('wizard-start').click();
    await expect(page.getByTestId('check-secure')).toHaveAttribute('data-ok', 'true');
    await expect(page.getByTestId('check-supported')).toHaveAttribute('data-ok', 'true');
    // 開発・試験の環境はバージョンを焼き込まないので「バージョンの印なし」。画面とサーバーのバージョンはそろっている
    await expect(page.getByTestId('check-version')).toHaveAttribute('data-ok', 'true');
    await expect(page.getByTestId('check-version')).toContainText('システムのバージョン');
    await page.getByTestId('wizard-next').click();
    await page.getByTestId('passkey-name').fill('仮想の認証器 A');
    await page.getByTestId('enroll').click();
    await expect(page.getByTestId('enroll-done')).toBeVisible();
    await page.getByTestId('wizard-add-passkey').click();
    await expect(page).toHaveURL(/\/passkeys$/);
    await expect(page.getByTestId('operator-name')).toHaveText(operator.name);
    // メニューの下に動いているバージョン（試験の環境は焼き込んでいないので「開発中」）
    await expect(page.getByTestId('system-version')).toHaveText('EC-SUGAR 開発中');
    await expect(page.getByTestId('passkey-row')).toHaveCount(1);
    await expect(page.getByTestId('single-passkey')).toBeVisible();
    await expect(page.getByTestId('remove-passkey')).toBeDisabled();

    await page.getByRole('button', { name: 'ログアウト' }).click();
    await expect(page).toHaveURL(/\/login$/);
    await page.goto(operator.enrollUrl);
    await expect(page.getByTestId('enroll-invalid')).toBeVisible();
});

test('安全でない接続では、登録の前の確かめで止まり、先へ進めない', async ({ page }) => {
    const operator = runFixture('operator');
    // 証明書の警告を飛ばして開いた・http で開いた、の代わり
    await page.addInitScript(() => Object.defineProperty(window, 'isSecureContext', { get: () => false }));

    await page.goto(operator.enrollUrl);
    await page.getByTestId('wizard-start').click();
    await expect(page.getByTestId('check-secure')).toHaveAttribute('data-ok', 'false');
    await expect(page.getByTestId('check-blocked')).toBeVisible();
    await expect(page.getByTestId('wizard-next')).toHaveCount(0);
});

test('サーバーのバージョンと画面のバージョンが食い違えば、バージョンの行に読み込み直しの案内を出す（登録は止めない）', { tag: '@nightly' }, async ({ page }) => {
    const operator = runFixture('operator');
    // 更新の後に古い画面が残った形: サーバーだけが新しいバージョンを名乗る
    await page.route('**/auth/enrollment', async (route) => {
        const response = await route.fetch();
        const body = await response.json();
        await route.fulfill({ response, json: { ...body, system: { revision: 'ab12'.repeat(10), short: 'ab12ab12ab12', date: '2026-10-29T16:40:00+09:00' } } });
    });

    await page.goto(operator.enrollUrl);
    await page.getByTestId('wizard-start').click();
    await expect(page.getByTestId('check-version')).toHaveAttribute('data-ok', 'false');
    await expect(page.getByTestId('check-version')).toContainText('ab12ab12ab12');
    await expect(page.getByTestId('check-version')).toContainText('開き直して');
    await expect(page.getByTestId('wizard-next')).toBeVisible();
});

test('パスキーでログインし、開こうとした画面へ戻る', async ({ page, operator }) => {
    void operator;
    await page.getByRole('button', { name: 'ログアウト' }).click();
    await expect(page).toHaveURL(/\/login$/);

    await page.goto('/passkeys');
    await expect(page).toHaveURL(/\/login$/);
    await page.getByTestId('login').click();
    await expect(page).toHaveURL(/\/passkeys$/);
    await expect(page.getByTestId('passkey-row')).toHaveCount(1);
});

test('パスキーの追加は本人確認の後にでき、削除もできる（最後の 1 本は消せない）', async ({ page, operator, authenticator }) => {
    void operator;
    await page.getByTestId('new-passkey-name').fill('仮想の認証器 B');
    await page.getByTestId('add-passkey').click();

    // サーバーが 423（再確認が要る）を返し、確認の画面が出る。やめると何も追加されない
    const dialog = page.getByRole('dialog', { name: '本人確認' });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'やめる' }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByRole('alert')).toContainText('本人確認を取り消しました');
    await expect(page.getByTestId('passkey-row')).toHaveCount(1);

    // 認証器 A で確認する。A には同じ運営のパスキーがあるので、ブラウザが二重登録を断る
    await page.getByTestId('add-passkey').click();
    await expect(dialog).toBeVisible();
    await dialog.getByTestId('confirm-passkey').click();
    await dialog.getByTestId('confirm-continue').click();
    await expect(page.getByRole('alert')).toContainText('この保存先には、あなたのパスキーがもう登録されています');
    await expect(page.getByTestId('passkey-row')).toHaveCount(1);

    // 別の認証器 B に作る。本人確認は済んでいる（5 分間有効）ので、今度は聞かれない
    await authenticator.remove(authenticator.id);
    await authenticator.add();
    await page.getByTestId('add-passkey').click();
    await expect(page.getByTestId('passkey-row')).toHaveCount(2);
    await expect(page.getByRole('status')).toContainText('「仮想の認証器 B」を追加しました');

    // A のパスキーを削除する
    const row = page.getByTestId('passkey-row').filter({ hasText: '仮想の認証器 A' });
    await row.getByTestId('remove-passkey').click();
    await row.getByTestId('confirm-remove-passkey').click();
    await expect(page.getByTestId('passkey-row')).toHaveCount(1);
    await expect(page.getByTestId('passkey-row')).toContainText('仮想の認証器 B');
    await expect(page.getByTestId('remove-passkey')).toBeDisabled();

    // ログイン中の端末: A で入ったこの画面は、A を消しても残る（消した本人）。使ったパスキーは「削除済み」
    await expect(page.getByTestId('session-row')).toHaveCount(1);
    await expect(page.getByTestId('session-row')).toContainText('この画面');
    await expect(page.getByTestId('session-row')).toContainText('削除済み');
    await expect(page.getByTestId('end-session')).toHaveCount(0);
});

test('パスキーが 1 本だけのオーナーには、ホームとパスキーの画面で知らせ、2 本目を足すと消える', { tag: '@nightly' }, async ({ page, operator, authenticator }) => {
    void operator;
    const notice = page.getByTestId('single-passkey');

    // パスキーの画面（登録の直後）。オーナーなので警告の色で強めに
    await expect(notice).toBeVisible();
    await expect(notice).toHaveAttribute('data-owner', 'true');
    await expect(notice).toContainText('別の端末のパスキーを追加してください');

    // ホーム（店の一覧）にも出て、ボタンからパスキーの画面へ移れる
    await page.goto('/tenants');
    await expect(page.getByRole('heading', { name: '店の一覧', level: 1 })).toBeVisible();
    await expect(notice).toBeVisible();
    await notice.getByTestId('single-passkey-add').click();
    await expect(page).toHaveURL(/\/passkeys$/);

    // 2 本目を足す。本人確認は認証器 A で済ませ（A には登録済みなので、この回の追加はブラウザが断る）、別の認証器 B に作る
    await page.getByTestId('new-passkey-name').fill('仮想の認証器 B');
    await page.getByTestId('add-passkey').click();
    const dialog = page.getByRole('dialog', { name: '本人確認' });
    await dialog.getByTestId('confirm-passkey').click();
    await dialog.getByTestId('confirm-continue').click();
    await expect(page.getByRole('alert')).toContainText('この保存先には、あなたのパスキーがもう登録されています');
    await authenticator.remove(authenticator.id);
    await authenticator.add();
    await page.getByTestId('add-passkey').click();
    await expect(page.getByTestId('passkey-row')).toHaveCount(2);
    await expect(notice).toHaveCount(0);

    // ホームからも消える
    await page.goto('/tenants');
    await expect(page.getByRole('heading', { name: '店の一覧', level: 1 })).toBeVisible();
    await expect(page.getByTestId('tenant-row').or(page.getByText('店はまだありません')).first()).toBeVisible();
    await expect(notice).toHaveCount(0);
});

test('セッションが切れたら、ログインへ戻して知らせ、ログインし直すと元の画面へ戻る', async ({ page, operator }) => {
    void operator;
    await page.goto('/tenants');
    await expect(page.getByRole('heading', { name: '店の一覧', level: 1 })).toBeVisible();
    // 画面が裏で呼ぶ API の応答が返り切るのを待つ（応答はセッションの Cookie を付け直すので、途中で消すと戻ってしまう）
    await page.waitForLoadState('networkidle');

    // サーバー側のセッションを失わせる（Cookie を消す）。次の API の呼び出しが 401 になる
    await page.context().clearCookies();
    await page.getByRole('link', { name: 'パスキー', exact: true }).click();

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByRole('status')).toContainText('有効期限が切れました');

    await page.getByTestId('login').click();
    await expect(page).toHaveURL(/\/passkeys$/);
});

test('回復用のコードを作って控え、パスキーをなくしてもコードで登録し直せる（同じコードは 2 度使えない。ADR 0038）', async ({ page, operator, authenticator }) => {
    // 作るのは本人確認の後。10 個はこの時だけ見せる
    await page.getByTestId('make-codes').click();
    const dialog = page.getByRole('dialog', { name: '本人確認' });
    await expect(dialog).toBeVisible();
    await dialog.getByTestId('confirm-passkey').click();
    await dialog.getByTestId('confirm-continue').click();
    const codes = page.getByTestId('secret-codes').locator('li');
    await expect(codes).toHaveCount(10);
    const code = (await codes.first().innerText()).trim();
    expect(code).toMatch(/^[0-9a-z]{4}(-[0-9a-z]{4}){3}$/);
    await page.getByTestId('codes-saved').click();
    await expect(page.getByTestId('secret-codes')).toHaveCount(0);
    await expect(page.getByTestId('codes-remaining')).toContainText('残り 10 個');

    // パスキーをなくした（認証器を替える）。ログインの画面の「パスキーを使えないとき」から戻る
    await page.getByRole('button', { name: 'ログアウト' }).click();
    await expect(page).toHaveURL(/\/login$/);
    await authenticator.remove(authenticator.id);
    await authenticator.add();
    await page.getByTestId('to-recover').click();
    await expect(page).toHaveURL(/\/recover$/);
    await page.getByTestId('recover-email').fill(operator.email);
    await page.getByTestId('recover-code').fill('zzzz-zzzz-zzzz-zzzz');
    await page.getByTestId('recover').click();
    await expect(page.getByRole('alert')).toContainText('メールか回復用のコードが違います');

    await page.getByTestId('recover-code').fill(code.toUpperCase());
    await page.getByTestId('recover').click();
    await expect(page).toHaveURL(/\/enroll$/);
    await page.getByTestId('wizard-start').click();
    await page.getByTestId('wizard-next').click();
    await page.getByTestId('passkey-name').fill('新しい端末');
    await page.getByTestId('enroll').click();
    await page.getByTestId('wizard-add-passkey').click();
    await expect(page).toHaveURL(/\/passkeys$/);
    await expect(page.getByTestId('passkey-row')).toHaveCount(2);
    await expect(page.getByTestId('codes-remaining')).toContainText('残り 9 個');

    // 同じコードは 2 度使えない
    await page.getByRole('button', { name: 'ログアウト' }).click();
    await page.getByTestId('to-recover').click();
    await page.getByTestId('recover-email').fill(operator.email);
    await page.getByTestId('recover-code').fill(code);
    await page.getByTestId('recover').click();
    await expect(page.getByRole('alert')).toContainText('メールか回復用のコードが違います');
});
