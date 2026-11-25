<script setup>
import ErrorState from '../components/ui/ErrorState.vue';
</script>

<template>
    <ErrorState :status="404" title="この画面はありません">
        アドレスが間違っているか、画面が移動した可能性があります。
    </ErrorState>
</template>
