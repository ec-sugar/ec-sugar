<script setup>
import AppButton from './ui/AppButton.vue';
import AppIcon from './ui/AppIcon.vue';
import AppNotice from './ui/AppNotice.vue';

// パスキーが 1 本だけの運営への通知。ホームとパスキーの画面に出し、2 本目を足すと消える（出すかどうかは呼ぶ側が本数で決める）。
// オーナーは締め出されるとメンバーや権限を戻せる人がいなくなるので、警告の色で強めに出す。スタッフは案内の色。
// 同期のパスキー（iCloud キーチェーン・Google パスワードマネージャーなど）があれば、そのアカウントの 2 段階認証を勧める一言を添える。
// 出し分けは表示のためだけ（本数はサーバーが本人の分だけを数えて返す）
defineProps({
    owner: { type: Boolean, default: false },
    synced: { type: Boolean, default: false },
    // ホームではパスキーの画面へのボタンを出す（パスキーの画面では下の「パスキーを追加」へ案内する）
    link: { type: Boolean, default: false },
});
</script>

<template>
    <AppNotice :tone="owner ? 'warning' : 'info'" :live="false" :data-owner="owner ? 'true' : 'false'">
        <span v-if="owner">パスキーが 1 本だけです。この端末をなくしたり壊したりすると管理画面に入れなくなり、オーナーにしかできない操作（メンバーと権限など）も止まります。別の端末のパスキーを追加してください。</span>
        <span v-else>パスキーが 1 本だけです。この端末をなくしたり壊したりすると管理画面に入れなくなります。別の端末のパスキーを追加してください。</span>
        <span v-if="synced" data-test="single-passkey-synced">同期するパスキー（iCloud キーチェーン・Google パスワードマネージャーなど）は、そのアカウントを守ることが大切です。Apple アカウント・Google アカウントの 2 段階認証を有効にしてください。</span>
        <div v-if="link">
            <AppButton size="sm" :to="{ name: 'passkeys' }" data-test="single-passkey-add"><AppIcon name="key" />パスキーを追加</AppButton>
        </div>
        <span v-else>下の「パスキーを追加」から追加できます。</span>
    </AppNotice>
</template>
