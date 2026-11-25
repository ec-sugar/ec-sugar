// 管理画面の API の呼び出し。自分のオリジンだけ、Cookie のセッション ＋ CSRF（XSRF-TOKEN）。
// 状態ごとの扱い: 401 はログイン画面へ、419（CSRF の期限切れ）は Cookie を取り直して 1 回だけ再送、
// 423（再確認が要る）は confirmation.js、429 は待つ秒数を添えて伝える。

export class ApiError extends Error {
    constructor(status, body, retryAfter = null) {
        super(`API ${status}`);
        this.status = status;
        this.body = body;
        this.retryAfter = retryAfter;
    }
}

let unauthorizedHandler = () => {};

export function onUnauthorized(handler) {
    unauthorizedHandler = handler;
}

// https では __Host-XSRF-TOKEN（隣のホストから置けない名前。HostPrefixedCookies）、手元の http では XSRF-TOKEN。
// https で頭の無い XSRF-TOKEN は読まない（隣のホストから置かれたものかもしれない）
function xsrfToken() {
    const name = window.location.protocol === 'https:' ? '__Host-XSRF-TOKEN=' : 'XSRF-TOKEN=';
    const cookie = document.cookie.split('; ').find((c) => c.startsWith(name));

    return cookie ? decodeURIComponent(cookie.slice(name.length)) : null;
}

async function refreshCsrfCookie() {
    await fetch('/auth/csrf', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
}

// 送って答え（Response）を受け取る。419（CSRF の期限切れ）は Cookie を取り直して 1 回だけ再送
async function send(method, path, body = undefined, retried = false) {
    // 呼び出し先は自分のオリジンの絶対パスだけ（// で始まると別ホストになる）
    if (!path.startsWith('/') || path.startsWith('//') || path.startsWith('/\\')) {
        throw new Error(`invalid API path: ${path}`);
    }

    const unsafe = method !== 'GET';

    if (unsafe && xsrfToken() === null) {
        await refreshCsrfCookie();
    }

    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

    // FormData（ファイルの送信）は、ブラウザが境界の文字列つきの Content-Type を付ける
    const form = body instanceof FormData;

    if (body !== undefined && !form) {
        headers['Content-Type'] = 'application/json';
    }

    const token = xsrfToken();

    if (unsafe && token !== null) {
        headers['X-XSRF-TOKEN'] = token;
    }

    let response;

    try {
        response = await fetch(path, {
            method,
            headers,
            body: body === undefined || form ? body : JSON.stringify(body),
            credentials: 'same-origin',
            cache: 'no-store',
            redirect: 'error',
        });
    } catch {
        throw new ApiError(0, null);
    }

    if (response.status === 419 && !retried) {
        await refreshCsrfCookie();

        return send(method, path, body, true);
    }

    return response;
}

export async function api(method, path, body = undefined) {
    const response = await send(method, path, body);
    const data = response.status === 204 ? null : await response.json().catch(() => null);

    if (response.ok) {
        return data;
    }

    throw failure(response, data);
}

// ファイルを受け取る（App の画面の CSV の書き出し）。答えをファイルとして保存させる（画面は移らない）。
// 断られた時の答えは JSON なので、api と同じ誤り（ApiError）にする。ファイルの名前はサーバーの Content-Disposition から（形の合うものだけ）
export async function download(path, body) {
    const response = await send('POST', path, body);

    if (!response.ok) {
        throw failure(response, await response.json().catch(() => null));
    }

    return saveBlob(response, 'download.csv');
}

// 答えのファイルをブラウザで保存させる（ファイル名はサーバーの Content-Disposition の決まった文字だけ）
async function saveBlob(response, fallbackName) {
    const name = /filename="([A-Za-z0-9][A-Za-z0-9_.-]{0,80})"/.exec(response.headers.get('Content-Disposition') ?? '')?.[1] ?? fallbackName;
    const url = URL.createObjectURL(await response.blob());
    const link = document.createElement('a');
    link.href = url;
    link.download = name;
    document.body.append(link);
    link.click();
    link.remove();
    // 保存が始まるのを待ってから、ブラウザの中の写しを捨てる
    setTimeout(() => URL.revokeObjectURL(url), 10_000);

    return name;
}

function failure(response, data) {
    const retryAfter = Number.parseInt(response.headers.get('Retry-After') ?? '', 10);
    const error = new ApiError(response.status, data, Number.isFinite(retryAfter) ? retryAfter : null);

    if (response.status === 401) {
        unauthorizedHandler();
    }

    return error;
}

// ファイルを受け取って保存する（商品の CSV の書き出し）。自分のオリジンの GET だけ。断られたときは api() と同じ ApiError。
// 保存の名前はサーバーの Content-Disposition（店の ID と日付だけ）から取り、無ければ決まった名前
export async function downloadGet(path, fallbackName = 'download.csv') {
    if (!path.startsWith('/') || path.startsWith('//') || path.startsWith('/\\')) {
        throw new Error(`invalid API path: ${path}`);
    }

    let response;

    try {
        response = await fetch(path, {
            method: 'GET',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            cache: 'no-store',
            redirect: 'error',
        });
    } catch {
        throw new ApiError(0, null);
    }

    if (!response.ok) {
        const data = await response.json().catch(() => null);

        if (response.status === 401) {
            unauthorizedHandler();
        }

        throw new ApiError(response.status, data);
    }

    return saveBlob(response, fallbackName);
}

// 利用者に見せる文言。サーバーの 422 の文言は Vue の文字列の差し込み（エスケープされる）でだけ表示する
export function describe(error) {
    if (!(error instanceof ApiError)) {
        return error instanceof Error && error.userMessage ? error.userMessage : '予期しないエラーが起きました。画面を読み込み直してください。';
    }

    // サーバーが「利用者に見せてよい」と決めた文（外の決済代行が断ったときなど）。フレームワークの文はここに来ない
    if (typeof error.body?.user_message === 'string' && error.body.user_message !== '') {
        return error.body.user_message;
    }

    switch (error.status) {
        case 0:
            return '通信できませんでした。接続を確認してください。';
        case 401:
            return 'ログインの有効期限が切れました。もう一度ログインしてください。';
        case 403:
            return 'この操作をする権限がありません。必要なときは、オーナーに権限を依頼してください。';
        case 419:
            return '画面の有効期限が切れました。画面を読み込み直してから、もう一度お試しください。';
        case 404:
            return '見つかりませんでした。';
        case 409:
            return 'すでにログインしています。';
        case 422: {
            const errors = error.body?.errors ?? {};
            const first = Object.values(errors).flat()[0];

            return typeof first === 'string' ? first : '入力を確認してください。';
        }
        case 423:
            return '本人確認が必要です。';
        case 429:
            return error.retryAfter
                ? `試行が多すぎます。${error.retryAfter} 秒ほど待ってからもう一度お試しください。`
                : '試行が多すぎます。しばらく待ってからもう一度お試しください。';
        case 503:
            return '一時的に使えません。時間をおいてお試しください。';
        default:
            return error.status >= 500
                ? 'サーバーでエラーが起きました。時間をおいてお試しください。'
                : `処理できませんでした（${error.status}）。`;
    }
}
