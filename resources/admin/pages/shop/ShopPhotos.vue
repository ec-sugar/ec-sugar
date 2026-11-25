<script setup>
import { reactive, ref, watch } from 'vue';
import { api, describe } from '../../api.js';
import AppButton from '../../components/ui/AppButton.vue';
import AppField from '../../components/ui/AppField.vue';
import AppInput from '../../components/ui/AppInput.vue';
import AppNotice from '../../components/ui/AppNotice.vue';
import AppPanel from '../../components/ui/AppPanel.vue';
import AppText from '../../components/ui/AppText.vue';
import ConfirmButton from '../../components/ui/ConfirmButton.vue';
import FileDrop from '../../components/ui/FileDrop.vue';
import MediaTile from '../../components/ui/MediaTile.vue';

// ショップの写真の置き場（ADR 0028 区切り 1・2b）: 一覧・上げる・代わりの文字・消す。
// 変わった一覧は update:items で、済んだ通知と誤りは notice で親の画面へ返す。写真を使うのは、テーマの中身（セクションの写真の欄）
const props = defineProps({
    // 置き場の API（/api/tenants/{tenant}/shop-media）
    base: { type: String, required: true },
    items: { type: Array, required: true },
    max: { type: Number, required: true },
    // 親が店の設定を変えている間は押せなくする
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:items', 'notice']);

const PHOTO_TYPES = 'image/jpeg,image/png,image/webp';
// 使っている所の画面での呼び名。テーマのセクションの値（ADR 0028 区切り 2）は theme:テーマ:バージョン
const usageLabel = (usage) => {
    const [kind, theme, ...version] = usage.split(':');
    if (kind === 'theme') return `テーマ「${theme}」の「${version.join(':')}」`;
    // 設定 › SEO の共有の既定の写真（ADR 0061）
    if (usage === 'seo:share') return '設定の「SEO」の共有の写真';
    // 区切り 2c で店の設定から控えへ移したヒーロー。セクションのテーマへ写すまで消せない
    return usage === 'legacy:hero' ? '前の表示のバックアップ（ヒーロー）' : usage;
};
const busy = ref(false);
const progress = ref(null);
const failures = ref([]);
const altDrafts = reactive({});

watch(() => props.items, (items) => {
    for (const item of items) {
        altDrafts[item.id] = item.alt ?? '';
    }
}, { immediate: true });

// 商品の説明（ADR 0032）は listing:商品の ID。商品ごとに並べず「商品の説明（n 件）」にまとめる
function usage(item) {
    const listings = item.used_by.filter((u) => u.startsWith('listing:')).length;
    const labels = item.used_by.filter((u) => !u.startsWith('listing:')).map(usageLabel);

    if (listings > 0) {
        labels.push(`商品の説明（${listings} 件）`);
    }

    return labels.length > 0 ? `${labels.join('・')}に使用中` : null;
}

function notice(message, error = null) {
    emit('notice', { message, error });
}

async function upload(files) {
    busy.value = true;
    failures.value = [];
    notice(null);
    // 親から props が戻るのは次の描画なので、一覧はここで持ち回す
    let items = props.items;
    let added = 0;

    for (const [index, file] of files.entries()) {
        if (items.length >= props.max) {
            failures.value.push({ name: file.name, message: `ショップの写真は ${props.max} 枚までです。使っていない写真を削除してからアップロードしてください。` });
            continue;
        }

        progress.value = `${files.length} 枚中 ${index + 1} 枚目をアップロードしています…`;
        const body = new FormData();
        body.append('image', file);

        try {
            const { item } = await api('POST', props.base, body);
            items = [item, ...items];
            emit('update:items', items);
            added++;
        } catch (e) {
            failures.value.push({ name: file.name, message: describe(e) });
        }
    }

    progress.value = null;
    busy.value = false;

    if (added > 0) {
        notice(`ショップの写真を ${added} 枚追加しました。`);
    }
}

async function act(run, done) {
    busy.value = true;
    failures.value = [];
    notice(null);

    try {
        emit('update:items', await run());
        notice(done);
    } catch (e) {
        notice(null, describe(e));
    } finally {
        busy.value = false;
    }
}

function saveAlt(item) {
    const alt = altDrafts[item.id].trim();

    if (alt === (item.alt ?? '')) {
        return;
    }

    act(async () => {
        const { item: saved } = await api('PATCH', `${props.base}/${item.id}`, { alt: alt === '' ? null : alt });

        return props.items.map((m) => (m.id === saved.id ? saved : m));
    }, '代替テキストを保存しました。');
}

function remove(item) {
    act(async () => (await api('DELETE', `${props.base}/${item.id}`)).items, '写真を削除しました。');
}
</script>

<template>
    <AppPanel title="ショップの写真" :description="`店の紹介やキャンペーンなど、商品以外の写真を保存しておく場所です。JPEG・PNG・WebP、1 枚 10MB まで、${max} 枚まで。位置情報などの埋め込み情報は削除して保存します。`">
        <ul v-if="items.length > 0" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3" data-test="shop-media">
            <MediaTile v-for="(item, index) in items" :key="item.id" :src="`${base}/${item.id}/medium`" :alt="item.alt ?? ''" :badge="usage(item)">
                <AppField :label="`${index + 1} 枚目の代替テキスト`" optional hint="画面の読み上げ機能で、写真の代わりに読み上げる説明（200 字まで）">
                    <AppInput v-model="altDrafts[item.id]" maxlength="200" :disabled="busy || disabled" data-test="media-alt" @change="saveAlt(item)" />
                </AppField>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <AppText v-if="item.used_by.length > 0" size="sm" tone="soft">使用中は削除できません</AppText>
                    <ConfirmButton v-else label="削除" confirm-label="削除する" :disabled="busy || disabled" test="remove-media" @confirm="remove(item)" />
                </div>
            </MediaTile>
        </ul>
        <FileDrop
            :accept="PHOTO_TYPES"
            multiple
            :disabled="busy || disabled || items.length >= max"
            :label="items.length >= max ? `ショップの写真は ${max} 枚までです` : '写真を選ぶ（ここにドラッグ＆ドロップしても追加できます）'"
            :hint="progress ?? `あと ${max - items.length} 枚追加できます`"
            @select="upload"
        />
        <AppNotice v-if="failures.length > 0" tone="error">
            <span>追加できなかった写真があります。</span>
            <ul data-test="media-errors">
                <li v-for="(failure, i) in failures" :key="i">{{ failure.name }}: {{ failure.message }}</li>
            </ul>
        </AppNotice>
    </AppPanel>
</template>
