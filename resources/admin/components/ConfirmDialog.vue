<script setup>
import { ref, watch } from 'vue';
import { confirmation, confirmWithPasskey, continueAfterConfirmation, cancelConfirmation } from '../confirmation.js';
import AppButton from './ui/AppButton.vue';
import AppNotice from './ui/AppNotice.vue';

const dialog = ref(null);

// 開く時は少し縮めた形からふわっと出し、後ろの幕は薄く出す（閉じるのはすぐ。「動きを減らす」の人には admin.css が止める）

watch(() => confirmation.open, (open) => {
    if (open && !dialog.value.open) {
        dialog.value.showModal();
    } else if (!open && dialog.value.open) {
        dialog.value.close();
    }
});

// Esc で閉じたときも取り消しとして扱う
function onCancel(event) {
    event.preventDefault();
    cancelConfirmation();
}
</script>

<template>
    <dialog
        ref="dialog"
        aria-labelledby="confirm-title"
        class="m-auto w-[calc(100%-2rem)] max-w-md rounded-card bg-surface p-6 sm:p-card text-ink shadow-xl ring-1 ring-ink/10 backdrop:bg-ink/40 open:animate-pop backdrop:animate-fade"
        @cancel="onCancel"
    >
        <div class="flex flex-col gap-4">
            <h2 id="confirm-title" class="text-lg font-bold">本人確認</h2>
            <template v-if="!confirmation.confirmed">
                <p class="text-ink-muted">この操作の前に、もう一度パスキーで本人確認をします。</p>
                <AppNotice v-if="confirmation.error" tone="error">{{ confirmation.error }}</AppNotice>
                <div class="flex justify-end gap-2">
                    <AppButton variant="ghost" :disabled="confirmation.busy" @click="cancelConfirmation">やめる</AppButton>
                    <AppButton variant="primary" :disabled="confirmation.busy" data-test="confirm-passkey" @click="confirmWithPasskey">パスキーで確認する</AppButton>
                </div>
            </template>
            <template v-else>
                <p class="text-ink-muted">確認できました。操作を続けます。</p>
                <div class="flex justify-end">
                    <AppButton variant="primary" data-test="confirm-continue" @click="continueAfterConfirmation">続ける</AppButton>
                </div>
            </template>
        </div>
    </dialog>
</template>
