import { reactive } from 'vue';
import { api, ApiError, describe } from './api.js';
import { usePasskey } from './webauthn.js';

// 重要な操作の前の再確認（サーバーが 423 を返したとき）。
// パスキーの操作は利用者のクリックの直後でないと拒むブラウザがあるので、
// 「本人確認」と「続ける」をそれぞれボタンにし、確認が済んだら元の操作を 1 回だけやり直す。
export const confirmation = reactive({
    open: false,
    confirmed: false,
    busy: false,
    error: null,
});

let pending = null;

export class ConfirmationCancelled extends Error {
    constructor() {
        super('confirmation cancelled');
        this.userMessage = '本人確認を取り消しました。';
    }
}

function ask() {
    Object.assign(confirmation, { open: true, confirmed: false, busy: false, error: null });

    return new Promise((resolve, reject) => {
        pending = { resolve, reject };
    });
}

export async function confirmWithPasskey() {
    confirmation.busy = true;
    confirmation.error = null;

    try {
        const { options } = await api('GET', '/auth/confirm/options');
        await api('POST', '/auth/confirm', { credential: await usePasskey(options) });
        confirmation.confirmed = true;
    } catch (error) {
        confirmation.error = describe(error);
    } finally {
        confirmation.busy = false;
    }
}

export function continueAfterConfirmation() {
    confirmation.open = false;
    pending?.resolve();
    pending = null;
}

export function cancelConfirmation() {
    confirmation.open = false;
    pending?.reject(new ConfirmationCancelled());
    pending = null;
}

export async function withConfirmation(action) {
    try {
        return await action();
    } catch (error) {
        if (!(error instanceof ApiError) || error.status !== 423) {
            throw error;
        }

        await ask();

        return action();
    }
}
