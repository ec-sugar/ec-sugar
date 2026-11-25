<script setup>
import { computed } from 'vue';
import { describe } from '../../api.js';
import AppButton from './AppButton.vue';
import AppIcon from './AppIcon.vue';

// 画面の中身を出せないときの表示。白いカードの真ん中に、線の絵・何が起きたかの一言・次にやることを置く。
// 読み込みの失敗（API の応答の状態）ごとに文と次の操作を決める。中の事情（サーバーの文・内部の ID）は出さない。
// サーバーが「見せてよい」と決めた文（user_message）だけは describe() を通して本文に出す。
// 操作の失敗（保存できなかったなど）は、その場の AppNotice で伝える（画面ごと差し替えない）
const props = defineProps({
    // ApiError（status を持つ）か、そのほかの誤り
    error: { type: [Object, String], default: null },
    // error を持たないとき（画面が無いなど）に状態だけで選ぶ
    status: { type: Number, default: null },
    // 題を画面に合わせて変えるとき
    title: { type: String, default: null },
});

const RELOAD = { label: '読み込み直す', reload: true };
const TENANTS = { label: '店の一覧へ', to: { name: 'tenants' } };

function kindOf(status) {
    if (status === 0) {
        return { icon: 'cloud-off', title: '通信できませんでした', body: '接続を確認してから、読み込み直してください。', action: RELOAD };
    }

    if (status === 401 || status === 419) {
        return { icon: 'key', title: 'ログインの有効期限が切れました', body: 'もう一度ログインしてください。', action: { label: 'ログインし直す', to: { name: 'login' } } };
    }

    if (status === 403) {
        return { icon: 'lock', title: 'この画面を見る権限がありません', body: '必要なときは、オーナーに権限を依頼してください。', action: TENANTS };
    }

    if (status === 404) {
        return { icon: 'search', title: '見つかりませんでした', body: 'アドレスが間違っているか、削除されたか、担当していない店の可能性があります。', action: TENANTS };
    }

    if (status === 429) {
        return { icon: 'clock', title: '試行が多すぎます', body: null, action: RELOAD };
    }

    if (status === 503) {
        return { icon: 'server-alert', title: '一時的に使えません', body: '時間をおいて、読み込み直してください。', action: RELOAD };
    }

    if (typeof status === 'number' && status >= 500) {
        return { icon: 'server-alert', title: 'サーバーで問題が起きました', body: '時間をおいて、読み込み直してください。', action: RELOAD };
    }

    return { icon: 'alert', title: '表示できませんでした', body: null, action: RELOAD };
}

const view = computed(() => {
    const status = props.status ?? (typeof props.error?.status === 'number' ? props.error.status : null);
    const kind = kindOf(status);
    const shared = typeof props.error?.body?.user_message === 'string' && props.error.body.user_message !== '';
    // 文字の誤りはそのまま、ApiError は「見せてよい文」があるときと、決まった文が無いときだけ describe() の文
    const message = typeof props.error === 'string' ? props.error : (props.error && (shared || kind.body === null) ? describe(props.error) : null);

    return { ...kind, title: props.title ?? kind.title, body: message ?? kind.body };
});

function reload() {
    window.location.reload();
}
</script>

<template>
    <section role="alert" class="flex flex-col items-center gap-3 rounded-card bg-surface px-6 py-14 text-center sm:px-card sm:py-20" data-test="error-state">
        <span class="mb-2 flex size-16 items-center justify-center rounded-full bg-subtle text-ink-soft">
            <AppIcon :name="view.icon" :size="30" />
        </span>
        <h2 class="text-lg font-bold text-ink">{{ view.title }}</h2>
        <p class="max-w-md text-sm text-ink-soft"><slot>{{ view.body }}</slot></p>
        <div class="mt-3 flex flex-wrap justify-center gap-2">
            <slot name="action">
                <AppButton v-if="view.action.to" :to="view.action.to">{{ view.action.label }}</AppButton>
                <AppButton v-else @click="reload"><AppIcon name="refresh" />{{ view.action.label }}</AppButton>
            </slot>
        </div>
    </section>
</template>
