<script setup>
import { computed, ref } from 'vue';
import { api, describe } from '../../api.js';
import AppButton from '../ui/AppButton.vue';
import AppNotice from '../ui/AppNotice.vue';
import AppReveal from '../ui/AppReveal.vue';
import AppText from '../ui/AppText.vue';
import FileDrop from '../ui/FileDrop.vue';
import MediaTile from '../ui/MediaTile.vue';

// セクションの写真の欄（ADR 0028 区切り 2b）: ショップの写真から選ぶか、新しく上げる（上げた写真もショップの写真に入る）。
// 値は写真の ID。新しく上げたら uploaded で親に通知し、親が写真の一覧を持ち直す
// insert のときは値を持たず、選んだ写真（{ id, alt }）を pick で通知するだけ（セクションの HTML に写真を差し込む）
const model = defineModel({ type: [String, null], default: null });
const props = defineProps({
    label: { type: String, required: true },
    // ショップの写真（{ id, alt }）と、その API（/api/tenants/{tenant}/shop-media）
    media: { type: Array, required: true },
    base: { type: String, required: true },
    disabled: { type: Boolean, default: false },
    test: { type: String, default: null },
    insert: { type: Boolean, default: false },
});
const emit = defineEmits(['uploaded', 'pick']);

const open = ref(false);
const busy = ref(false);
const error = ref(null);
const chosen = computed(() => (props.insert ? null : props.media.find((item) => item.id === model.value) ?? null));

function choose(id) {
    if (props.insert) {
        emit('pick', props.media.find((item) => item.id === id) ?? { id, alt: null });
    } else {
        model.value = id;
    }
    open.value = false;
}

async function upload(files) {
    busy.value = true;
    error.value = null;
    const body = new FormData();
    body.append('image', files[0]);

    try {
        const { item } = await api('POST', props.base, body);
        emit('uploaded', item);
        if (props.insert) {
            emit('pick', item);
            open.value = false;
        } else {
            choose(item.id);
        }
    } catch (e) {
        error.value = describe(e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <!-- 列の数は画面の幅でなく、この欄の幅で決める（編集の画面の右の狭い欄でも札がつぶれないように） -->
    <div class="@container flex flex-col gap-3" :data-test="test">
        <ul v-if="chosen" class="grid grid-cols-1 gap-3 @xl:grid-cols-2">
            <MediaTile :src="`${base}/${chosen.id}/medium`" :alt="chosen.alt ?? ''">
                <div class="flex flex-wrap justify-end gap-2">
                    <AppButton size="sm" :disabled="disabled" data-test="photo-change" @click="open = !open">{{ open ? '閉じる' : '変更' }}</AppButton>
                    <AppButton size="sm" variant="ghost" :disabled="disabled" data-test="photo-clear" @click="model = null">選択を解除</AppButton>
                </div>
            </MediaTile>
        </ul>
        <AppText v-else-if="model && !insert" tone="soft">選んだ写真が「写真」の一覧にありません（削除された可能性があります）。選び直してください。</AppText>
        <div v-if="!chosen" class="flex flex-wrap items-center gap-2">
            <AppText v-if="!model && !insert" size="sm" tone="soft">写真はまだありません。</AppText>
            <AppButton size="sm" :disabled="disabled" data-test="photo-open" @click="open = !open">{{ open ? '閉じる' : (insert ? 'ショップの写真から差し込む' : 'ショップの写真から選ぶ') }}</AppButton>
        </div>

        <AppReveal :show="open" class="flex flex-col gap-3" data-test="photo-choices">
            <ul v-if="media.length > 0" class="grid grid-cols-1 gap-3 @xs:grid-cols-2 @xl:grid-cols-3 @4xl:grid-cols-4">
                <MediaTile v-for="item in media" :key="item.id" :src="`${base}/${item.id}/medium`" :alt="item.alt ?? ''">
                    <div class="flex justify-end">
                        <AppButton size="sm" :disabled="disabled || (!insert && item.id === model)" data-test="photo-choose" @click="choose(item.id)">{{ insert ? '差し込む' : (item.id === model ? '選択中' : '選ぶ') }}</AppButton>
                    </div>
                </MediaTile>
            </ul>
            <FileDrop accept="image/jpeg,image/png,image/webp" :disabled="disabled || busy" :label="`「${label}」に新しい写真をアップロード（ここにドラッグ＆ドロップしても選べます）`" hint="アップロードした写真は「写真」の一覧にも追加されます（JPEG・PNG・WebP、10MB まで）" @select="upload" />
            <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>
        </AppReveal>
    </div>
</template>
