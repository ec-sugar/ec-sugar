// 動く QR コード（App「デジタルチケット」の入場の QR。ADR 0069）。[data-live-qr] があるときだけ動く。
// QR の印の並びはサーバーが作って JSON で渡す（{ modules: ['0101…', …] }）。ここは四角を SVG の要素で並べ直すだけで、HTML の文字列を差し込まない
// （ショップの CSP と Trusted Types のまま動く。画像の data: や blob: も使わない）。JavaScript が無いときは、最初の画像（サーバーの SVG）がそのまま残る。
//
// - data-live-qr-src: 値を取り直す先（自分のオリジン）。data-live-qr-every: 取り直す間隔（秒）
// - data-sugar-page-key: 画面の鍵（ADR 0088）。取り直す先は画面を開く要求のほかを断るので、見出し X-Sugar-Page-Key で添える
// - 取り直しに失敗したら（電波が弱い）直前の QR を残したまま [data-live-qr-offline] を出し、data-live-qr-stale 秒（既定 60）続いたら QR を隠して
//   [data-live-qr-lost] を出す（受付は前後 30 秒の値だけを受けるので、古い QR を出し続けない）
// - サーバーが「もう出さない」と答えたら（無効にした・期限・確かめの期限など。4xx）、QR を隠して [data-live-qr-ended] を出す

const SVG = 'http://www.w3.org/2000/svg';

function draw(holder, modules) {
    const size = modules.length;

    if (size < 21 || size > 177 || !modules.every((row) => typeof row === 'string' && row.length === size && /^[01]+$/.test(row))) {
        throw new Error('modules');
    }

    const full = size + 8;
    const svg = document.createElementNS(SVG, 'svg');
    svg.setAttribute('viewBox', `0 0 ${full} ${full}`);
    svg.setAttribute('shape-rendering', 'crispEdges');
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', holder.dataset.liveQrLabel ?? 'QR コード');
    const background = document.createElementNS(SVG, 'rect');
    background.setAttribute('width', String(full));
    background.setAttribute('height', String(full));
    background.setAttribute('fill', '#fff');
    svg.append(background);
    let d = '';

    modules.forEach((row, y) => {
        for (let x = 0; x < size; x += 1) {
            if (row[x] === '1') {
                d += `M${x + 4} ${y + 4}h1v1h-1z`;
            }
        }
    });

    const path = document.createElementNS(SVG, 'path');
    path.setAttribute('d', d);
    path.setAttribute('fill', '#000');
    svg.append(path);
    holder.replaceChildren(svg);
}

function wire(root) {
    const holder = root.querySelector('[data-live-qr-image]');
    const offline = root.querySelector('[data-live-qr-offline]');
    const lost = root.querySelector('[data-live-qr-lost]');
    const ended = root.querySelector('[data-live-qr-ended]');
    const src = root.dataset.liveQrSrc;
    const key = root.dataset.sugarPageKey ?? '';
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(key === '' ? {} : { 'X-Sugar-Page-Key': key }) };
    const every = Math.max(5, Number.parseInt(root.dataset.liveQrEvery ?? '10', 10) || 10) * 1000;
    const stale = Math.max(10, Number.parseInt(root.dataset.liveQrStale ?? '60', 10) || 60) * 1000;

    if (!holder || !src || !src.startsWith('/')) {
        return;
    }

    let lastOk = Date.now();
    let timer = null;

    const show = (element, on) => {
        if (element) {
            element.hidden = !on;
        }
    };

    const stop = (element) => {
        window.clearInterval(timer);
        holder.hidden = true;
        show(offline, false);
        show(element, true);
    };

    const tick = async () => {
        let response;

        try {
            response = await fetch(src, { headers, credentials: 'same-origin', cache: 'no-store', redirect: 'error' });
        } catch {
            response = null;
        }

        if (response && response.status >= 400 && response.status < 500) {
            stop(ended);

            return;
        }

        try {
            if (!response || !response.ok) {
                throw new Error('offline');
            }

            draw(holder, (await response.json()).modules);
            lastOk = Date.now();
            show(offline, false);
        } catch {
            if (Date.now() - lastOk > stale) {
                stop(lost);
            } else {
                show(offline, true);
            }
        }
    };

    timer = window.setInterval(tick, every);
    tick();
}

for (const root of document.querySelectorAll('[data-live-qr]')) {
    wire(root);
}
