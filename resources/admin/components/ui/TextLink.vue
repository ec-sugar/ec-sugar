<script setup>
import { RouterLink } from 'vue-router';

// 文の中の画面の移動のリンク（下線つき）
defineProps({ to: { type: [String, Object], required: true } });
</script>

<template>
    <RouterLink :to="to" class="underline underline-offset-2"><slot /></RouterLink>
</template>
