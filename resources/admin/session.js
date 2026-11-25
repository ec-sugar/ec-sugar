import { reactive } from 'vue';
import { api, ApiError } from './api.js';

// ログイン中の運営。サーバーのセッションが正本で、ここは表示のための写し
export const session = reactive({
    operator: null,
    // 動いている EC-SUGAR のバージョン（ログインした運営にだけ届く。メニューの下に出す）
    system: null,
    // 自分のパスキーの本数と、同期のパスキーがあるか（{ count, synced }。1 本だけならホームで通知する）
    passkeys: null,
    // メールでの戻しの待ち（ADR 0038。開ける時）
    recoveryPending: null,
    // いつもと違う接続元から入った、今の画面でない端末（{ id, device, created_at }）
    unusualSession: null,
    // 隔離した写し（ADR 0082。本番のバックアップを確認のために立てたもの）なら true。画面の上に帯を出す
    isolated: false,
    // 入っている App の名前（ヘルプが、入っていない App の説明のページを出さないため。まだ読んでいなければ null）
    apps: null,
    checked: false,
    // ログインの後に戻る画面（ルーターが入れる、自分の画面の経路だけ）
    returnTo: null,
    notice: null,
});

export async function refreshSession() {
    try {
        const me = await api('GET', '/auth/me');
        session.operator = me.operator;
        session.system = me.system ?? null;
        session.passkeys = me.passkeys ?? null;
        session.recoveryPending = me.recovery_pending ?? null;
        session.unusualSession = me.unusual_session ?? null;
        session.isolated = me.isolated === true;
        session.apps = Array.isArray(me.apps) ? me.apps.filter((name) => typeof name === 'string') : null;
    } catch (error) {
        if (!(error instanceof ApiError) || error.status !== 401) {
            throw error;
        }

        session.operator = null;
        session.system = null;
        session.passkeys = null;
        session.recoveryPending = null;
        session.unusualSession = null;
    } finally {
        session.checked = true;
    }
}

export function signedIn(operator) {
    session.operator = operator;
    session.checked = true;
    session.notice = null;
    // ログインと登録の応答にはバージョンとパスキーの本数が無いので、後から読む（読めなくても画面は困らない）
    // 前にログインしていた人の本数を残さないよう、読むまでは空にしておく
    session.passkeys = null;
        session.recoveryPending = null;
    session.unusualSession = null;
    api('GET', '/auth/me').then((me) => {
        session.system = me.system ?? null;
        session.passkeys = me.passkeys ?? null;
        session.recoveryPending = me.recovery_pending ?? null;
        session.unusualSession = me.unusual_session ?? null;
        session.isolated = me.isolated === true;
        session.apps = Array.isArray(me.apps) ? me.apps.filter((name) => typeof name === 'string') : null;
    }).catch(() => {});
}

export function takeReturnTo() {
    const target = session.returnTo;
    session.returnTo = null;

    return isInternalPath(target) ? target : '/';
}

export function isInternalPath(path) {
    return typeof path === 'string' && path.startsWith('/') && !path.startsWith('//') && !path.startsWith('/\\');
}
