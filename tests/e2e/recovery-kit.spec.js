import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { expect, test } from './support/fixtures.js';

// 復元のキット（ADR 0111）: オーナーが管理画面で作り、保存したファイルを開け直して確かめる。
// 本物のブラウザ（管理画面の CSP の下）で、鍵を作る・暗号化する・保存する・開け直すを通す。サーバーのテストでは確かめられない所:
// - 保存したファイルが、サーバーの道具と同じ形で gpg で開けること
// - 合い言葉・秘密の鍵・キットの中身が、どの送信にも載らないこと
const PASSPHRASE = 'kit-passphrase-for-e2e-0276';

test('オーナーは復元のキットを作って保存し、開け直して確かめられる。合い言葉と秘密の鍵はサーバーへ行かない', async ({ page, operator }) => {
    void operator;
    const sent = [];
    page.on('request', (request) => sent.push(`${request.url()}\n${request.postData() ?? ''}`));
    await page.goto('/operations');

    const panel = page.getByTestId('recovery-kit');
    await expect(panel).toContainText('復元のキット');
    await expect(panel.getByTestId('recovery-kit-done')).toHaveCount(0);

    // 短い合い言葉・2 回で違う合い言葉では作れない
    await panel.getByTestId('recovery-kit-passphrase').fill('short');
    await expect(panel).toContainText('12 文字以上にしてください');
    await expect(panel.getByTestId('recovery-kit-create')).toBeDisabled();
    await panel.getByTestId('recovery-kit-passphrase').fill(PASSPHRASE);
    await panel.getByTestId('recovery-kit-passphrase-again').fill(`${PASSPHRASE}x`);
    await expect(panel).toContainText('2 つのパスフレーズが違います');
    await expect(panel.getByTestId('recovery-kit-create')).toBeDisabled();
    await panel.getByTestId('recovery-kit-passphrase-again').fill(PASSPHRASE);

    // 作る → 本人確認（サーバーが 423 を返す）→ ブラウザの中で作って保存
    const downloading = page.waitForEvent('download');
    await panel.getByTestId('recovery-kit-create').click();
    const dialog = page.getByRole('dialog', { name: '本人確認' });
    await dialog.getByTestId('confirm-passkey').click();
    await dialog.getByTestId('confirm-continue').click();
    const download = await downloading;
    expect(download.suggestedFilename()).toMatch(/^ec-sugar-recovery-kit-[A-Za-z0-9.-]+-\d{8}\.gpg$/);
    const dir = mkdtempSync(join(tmpdir(), 'ec-kit-'));
    const file = join(dir, download.suggestedFilename());
    await download.saveAs(file);
    await expect(panel.getByTestId('recovery-kit-created')).toContainText('このパソコンの外の 2 か所');
    // 作った後、合い言葉の欄は空に戻る（次の手順でもう一度入れてもらう）
    await expect(panel.getByTestId('recovery-kit-passphrase')).toHaveValue('');

    try {
        // 保存したファイルは、gpg（サーバーの道具が使うもの）で開けられて、キットの形をしている
        const home = join(dir, 'gnupg');
        execFileSync('mkdir', ['-m', '700', home]);
        const plain = execFileSync('gpg', ['--homedir', home, '--batch', '--quiet', '--pinentry-mode', 'loopback', '--passphrase-fd', '0', '--decrypt', file], { input: `${PASSPHRASE}\n` });
        const kit = JSON.parse(plain.toString('utf8'));
        expect(Object.keys(kit).sort()).toEqual(['app_key', 'created_at', 'decrypt_key', 'decrypt_key_passphrase', 'format', 'kind', 'server']);
        expect([kit.format, kit.kind]).toEqual([1, 'ec-sugar-recovery-kit']);
        expect(kit.app_key).toMatch(/^base64:[A-Za-z0-9+/]{43}=$/);
        expect(kit.decrypt_key).toContain('BEGIN PGP PRIVATE KEY BLOCK');
        // 生のファイルに、鍵や JSON の字が見えない（暗号化されている）
        expect(readFileSync(file).toString('latin1')).not.toContain('app_key');

        // 違う合い言葉では確かめられず、サーバーにも何も残らない
        await panel.getByTestId('recovery-kit-file').getByTestId('file-input').setInputFiles(file);
        await panel.getByTestId('recovery-kit-check-passphrase').fill('wrong-passphrase-000');
        await panel.getByTestId('recovery-kit-check').click();
        await expect(panel.getByTestId('recovery-kit-notice')).toContainText('復号できませんでした');
        await expect(panel.getByTestId('recovery-kit-done')).toHaveCount(0);

        // 控えた合い言葉で開け直すと、確かめ終わる。指紋はキットの中の鍵のもの
        // 鍵を受け取った時に本人確認は使い切っているので、送る前にもう一度聞かれる
        await panel.getByTestId('recovery-kit-check-passphrase').fill(PASSPHRASE);
        await panel.getByTestId('recovery-kit-check').click();
        await dialog.getByTestId('confirm-passkey').click();
        await dialog.getByTestId('confirm-continue').click();
        await expect(panel.getByTestId('recovery-kit-notice')).toContainText('保存したキットを開いて確認しました');
        const fingerprint = await panel.getByTestId('recovery-kit-fingerprint').textContent();
        expect(fingerprint).toMatch(/^[0-9A-F]{40}$/);
        const listed = execFileSync('gpg', ['--homedir', home, '--batch', '--with-colons', '--show-keys'], { input: kit.decrypt_key }).toString('utf8');
        expect(listed).toContain(`fpr:::::::::${fingerprint}:`);

        // どの送信にも、合い言葉・秘密の鍵・APP_KEY は載っていない（APP_KEY は受け取るだけで、送り返さない）
        const all = sent.join('\n');
        expect(all).not.toContain(PASSPHRASE);
        expect(all).not.toContain('PRIVATE KEY');
        expect(all).not.toContain(kit.app_key);
        expect(all).toContain('BEGIN PGP PUBLIC KEY BLOCK');

        // 開き直しても「作って確かめてある」と出る
        await page.reload();
        await expect(page.getByTestId('recovery-kit-done')).toContainText(fingerprint);
    } finally {
        rmSync(dir, { recursive: true, force: true });
    }
});
