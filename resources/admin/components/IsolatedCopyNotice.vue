<script setup>
import AppNotice from './ui/AppNotice.vue';

// 隔離した写し（ADR 0082）の帯。本番のバックアップを確認のために立てた管理画面で、どの画面にも上に出す（本番と取り違えないように）。
// 出すかどうかは呼ぶ側がサーバーの答え（/auth/me の isolated）で決める。写しでは外へ何も送らない（メール・支払い・通知・計測）
</script>

<template>
    <AppNotice tone="warning" :live="false" data-test="isolated-copy">
        <span>これは確認のための複製です（本番のバックアップから作ったもの）。メール・支払い・外部への通知は送られず、定期の処理も止めています。確認が終わったら、この複製ごと削除してください。</span>
    </AppNotice>
</template>
