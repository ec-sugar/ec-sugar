import { expect, test } from './support/fixtures.js';
import { port } from './env.js';

// 動画と地図の App の区画（ADR 0040）。外へは実際に出ないよう、ページの外への要求をすべて止めて（中身の無い応えを返して）数えるだけにする。
// - 同意を待たずに、ページを開いた時点で動画と地図の枠（iframe）がある（2026-10-30 の置き換え。押して読み込む形は無い）
// - 枠の src は、確かめた ID・場所と決まった値だけ（動画は自動で再生する・繰り返すを選んだもの。音を消すが自動で付く）。sandbox・allow・送り元の決まり
// - 外への要求は、宣言の宛先（youtube-nocookie.com の /embed/ と www.google.com の /maps）だけ
// CSP と Trusted Types の違反は、すべての場面の見張り（support/fixtures.js）が落とす

const storefront = (tenant, path = '/') => `http://${tenant.id}.localhost:${port}${path}`;
const local = /^http:\/\/([a-z0-9-]+\.)?localhost(:\d+)?\//;

test('動画と地図の区画は、同意を待たずに最初から枠を出し、宣言の宛先だけへ読み込む', async ({ page, makeTenant }) => {
    const tenant = makeTenant({ listings: 1, embeds: true });
    const external = [];
    await page.context().route((url) => !local.test(url.href) && url.protocol !== 'data:', (route) => {
        external.push(route.request().url());

        return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: '<!doctype html><title>止めた外の応え</title>' });
    });

    // 開いた時点で 2 つの枠がある（ボタンを押さない・同意しない）。同意の帯は動画と地図のことを聞かない
    await page.goto(storefront(tenant));
    await expect(page.getByTestId('app-embed')).toHaveCount(2);
    await expect(page.locator('iframe')).toHaveCount(2);
    await expect(page.getByTestId('consent-banner')).toHaveCount(0);

    // 動画: プライバシー強化モード、選んだ「自動で再生する」「繰り返す」だけ（音を消すが付き、繰り返しは playlist に同じ ID）。allow に autoplay
    const video = page.locator('[data-section-type="app_video"]').getByTestId('app-embed-frame');
    await expect(video).toHaveAttribute('src', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&playsinline=1&autoplay=1&mute=1&loop=1&playlist=dQw4w9WgXcQ');
    await expect(video).toHaveAttribute('sandbox', 'allow-scripts allow-same-origin allow-presentation allow-popups allow-popups-to-escape-sandbox');
    await expect(video).toHaveAttribute('allow', 'autoplay; encrypted-media; fullscreen; picture-in-picture');
    await expect(video).toHaveAttribute('referrerpolicy', 'strict-origin');
    await expect(video).toHaveAttribute('loading', 'lazy');

    // 地図: キーの要らない埋め込み（www.google.com の /maps ちょうど）に、場所を値として。キーは載せない
    const map = page.locator('[data-section-type="app_google_map"]').getByTestId('app-embed-frame');
    await expect(map).toHaveAttribute('src', `https://www.google.com/maps?q=${encodeURIComponent('東京駅')}&output=embed&hl=ja`);
    await expect(map).toHaveAttribute('allow', 'fullscreen');
    await expect(page.locator('[data-section-type="app_google_map"]').getByTestId('app-embed-link')).toHaveAttribute('href', `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent('東京駅')}`);

    // 枠のところまで動かすと読み込む。外への要求は宣言の宛先だけ
    await map.scrollIntoViewIfNeeded();
    await expect.poll(() => external.some((url) => url.startsWith('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?'))).toBe(true);
    await expect.poll(() => external.some((url) => url.startsWith('https://www.google.com/maps?q='))).toBe(true);
    expect(external.every((url) => url.startsWith('https://www.youtube-nocookie.com/embed/') || url.startsWith('https://www.google.com/maps?'))).toBe(true);
    expect(external.some((url) => url.includes('key='))).toBe(false);

    if (process.env.E2E_SCREENSHOTS) {
        await page.locator('[data-section-type="app_video"]').scrollIntoViewIfNeeded();
        await page.screenshot({ path: 'test-results/screens/app-embeds.png', fullPage: true });
    }
});
