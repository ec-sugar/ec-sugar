import { ref } from 'vue';
import { describe } from './api.js';
import { ConfirmationCancelled, withConfirmation } from './confirmation.js';
import { errorsOf } from './format.js';

// 画面の操作の共通の流れ: 送る → 結果を受ける → 済んだ通知、か、欄ごとの誤り・全体の誤り。
// サーバーが再確認（423）を求めたら、パスキーで確認してから送り直す（求めない操作はそのまま通る）。
// pending は今動いている操作の名前（そのボタンだけ回す）。then(result) は済んだときの続き（ここでの誤りも同じく出す）
export function useOperation() {
    const pending = ref(null);
    const errors = ref({});
    const error = ref(null);
    const message = ref(null);
    let leaving = false;

    async function run(name, action, { done = null, then = null } = {}) {
        pending.value = name;
        errors.value = {};
        error.value = null;
        message.value = null;

        try {
            const result = await withConfirmation(action);
            await then?.(result);
            message.value = typeof done === 'function' ? done(result) : done;

            return true;
        } catch (e) {
            errors.value = errorsOf(e);
            error.value = e instanceof ConfirmationCancelled ? e.userMessage : Object.keys(errors.value).length > 0 ? null : describe(e);

            return false;
        } finally {
            if (!leaving) {
                pending.value = null;
            }
        }
    }

    // 外の画面（Stripe の登録など）へ移る。移るまでボタンは回したまま
    function leave(url) {
        leaving = true;
        window.location.assign(url);
    }

    return { pending, errors, error, message, run, leave };
}
