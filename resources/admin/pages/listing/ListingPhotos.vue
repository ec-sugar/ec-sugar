<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { api, describe } from '../../api.js';
import AppButton from '../../components/ui/AppButton.vue';
import AppField from '../../components/ui/AppField.vue';
import AppIcon from '../../components/ui/AppIcon.vue';
import AppInput from '../../components/ui/AppInput.vue';
import AppNotice from '../../components/ui/AppNotice.vue';
import AppPanel from '../../components/ui/AppPanel.vue';
import AppText from '../../components/ui/AppText.vue';
import ConfirmButton from '../../components/ui/ConfirmButton.vue';
import FileDrop from '../../components/ui/FileDrop.vue';
import PhotoTile from '../../components/ui/PhotoTile.vue';

// 商品の写真: 追加・代わりの文字・並べ替え・削除。変わった写真の並びは update:images で親の画面へ返す。
// 今ある商品の写真は、選ぶ・並べ替える・消すとすぐ反映する（ファイルを送る操作なので、下の「保存する」を待たない。通知はこの節の中に）。
// 新しい商品（listing が null）は、選んだ写真を覚えておき、商品を作った後に送る（step()）。
// 管理画面の CSP は写真を自分の経路からしか読まないので、送る前の写真はファイルの名前で並べる
const props = defineProps({
    // 店の API（/api/tenants/{tenant}）
    tenantBase: { type: String, required: true },
    listing: { type: Object, default: null },
    // 閲覧だけの人（運用より下）には変える操作を出さない（見た目だけ。サーバーも 403 で断る）
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:images']);

const images = computed(() => props.listing?.images ?? []);
const base = computed(() => `${props.tenantBase}/listings/${encodeURIComponent(props.listing?.id ?? '')}/images`);
// 新しい商品で、作った後に送る写真
const queued = ref([]);
const message = ref(null);
const error = ref(null);

const MAX_PHOTOS = 10;
const PHOTO_TYPES = 'image/jpeg,image/png,image/webp';
const busy = ref(false);
const progress = ref(null);
const failures = ref([]);
const altDrafts = reactive({});

watch(images, (list) => {
    for (const image of list) {
        altDrafts[image.id] = image.alt ?? '';
    }
}, { immediate: true });

function notice(text, problem = null) {
    message.value = text;
    error.value = problem;
}

// 新しい商品: 選んだ写真を覚える（種類と枚数は送るときにサーバーが確認する）
function enqueue(files) {
    failures.value = [];
    const room = MAX_PHOTOS - queued.value.length;

    for (const file of files.slice(room)) {
        failures.value.push({ name: file.name, message: `写真は ${MAX_PHOTOS} 枚までです。` });
    }

    queued.value = [...queued.value, ...files.slice(0, room)];
}

function dequeue(index) {
    queued.value = queued.value.filter((_, i) => i !== index);
}

async function upload(files) {
    busy.value = true;
    failures.value = [];
    notice(null);
    // 親から props が戻るのは次の描画なので、枚数はここで数える
    let count = images.value.length;
    let added = 0;

    for (const [index, file] of files.entries()) {
        if (count >= MAX_PHOTOS) {
            failures.value.push({ name: file.name, message: `写真は ${MAX_PHOTOS} 枚までです。` });
            continue;
        }

        progress.value = `${files.length} 枚中 ${index + 1} 枚目をアップロードしています…`;
        const body = new FormData();
        body.append('image', file);

        try {
            const saved = (await api('POST', base.value, body)).listing.images;
            emit('update:images', saved);
            count = saved.length;
            added++;
        } catch (e) {
            failures.value.push({ name: file.name, message: describe(e) });
        }
    }

    progress.value = null;
    busy.value = false;

    if (added > 0) {
        notice(`写真を ${added} 枚追加しました。`);
    }
}

async function act(run, done) {
    busy.value = true;
    failures.value = [];
    notice(null);

    try {
        emit('update:images', await run());
        notice(done);
    } catch (e) {
        notice(null, describe(e));
    } finally {
        busy.value = false;
    }
}

function saveAlt(image) {
    const alt = altDrafts[image.id].trim();

    if (alt === (image.alt ?? '')) {
        return;
    }

    act(async () => {
        const { image: saved } = await api('PATCH', `${base.value}/${image.id}`, { alt: alt === '' ? null : alt });

        return images.value.map((p) => (p.id === saved.id ? saved : p));
    }, '代替テキストを保存しました。');
}

function move(index, step) {
    const ids = images.value.map((p) => p.id);
    [ids[index], ids[index + step]] = [ids[index + step], ids[index]];
    act(async () => (await api('POST', `${base.value}/order`, { ids })).listing.images, '並べ替えました。');
}

function remove(image) {
    act(async () => (await api('DELETE', `${base.value}/${image.id}`)).listing.images, '写真を削除しました。');
}

// 新しい商品の保存の手順（商品を作った後に、覚えた写真を 1 枚ずつ送る）。送れなかった写真があれば、全部を試した後で失敗にする
function step() {
    return {
        label: '写真',
        section: 'listing-photos',
        run: async (listing) => {
            const files = queued.value;
            const path = `${props.tenantBase}/listings/${encodeURIComponent(listing.id)}/images`;
            let current = listing;
            failures.value = [];
            queued.value = [];

            for (const file of files) {
                const body = new FormData();
                body.append('image', file);

                try {
                    current = { ...current, images: (await api('POST', path, body)).listing.images };
                } catch (e) {
                    failures.value.push({ name: file.name, message: describe(e) });
                }
            }

            if (failures.value.length > 0) {
                const failed = new Error('photos');
                failed.subject = `${files.length} 枚中 ${failures.value.length} 枚`;
                failed.partial = current;
                throw failed;
            }

            return current;
        },
    };
}

const dirty = computed(() => queued.value.length > 0);

defineExpose({ dirty, step });
</script>

<template>
    <AppPanel title="写真" :description="`JPEG・PNG・WebP、1 枚 10MB まで、${MAX_PHOTOS} 枚まで。1 枚目が一覧に表示されます。位置情報などの埋め込み情報は削除して保存します。`">
        <template v-if="listing">
            <AppText v-if="!disabled" size="sm" tone="soft">写真は、選択・並べ替え・削除がすぐ反映されます（下の「保存する」は不要です）。</AppText>
            <ul v-if="images.length > 0" class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5" data-test="photos">
                <PhotoTile v-for="(image, index) in images" :key="image.id" :src="`${base}/${image.id}/medium`" :alt="image.alt ?? ''" :index="index">
                    <AppField :label="`${index + 1} 枚目の代替テキスト`" optional hint="画面の読み上げ機能で、写真の代わりに読み上げる説明">
                        <AppInput v-model="altDrafts[image.id]" maxlength="200" :disabled="busy || disabled" data-test="photo-alt" @change="saveAlt(image)" />
                    </AppField>
                    <div v-if="!disabled" class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex gap-1">
                            <AppButton size="sm" variant="ghost" :disabled="busy || index === 0" :aria-label="`${index + 1} 枚目を前へ`" data-test="photo-earlier" @click="move(index, -1)"><AppIcon name="arrow-left" /></AppButton>
                            <AppButton size="sm" variant="ghost" :disabled="busy || index === images.length - 1" :aria-label="`${index + 1} 枚目を後ろへ`" data-test="photo-later" @click="move(index, 1)"><AppIcon name="arrow-right" /></AppButton>
                        </div>
                        <div class="ml-auto flex flex-wrap justify-end gap-2">
                            <ConfirmButton label="削除" confirm-label="削除する" :disabled="busy" test="remove-photo" @confirm="remove(image)" />
                        </div>
                    </div>
                </PhotoTile>
            </ul>
            <FileDrop
                v-if="!disabled"
                :accept="PHOTO_TYPES"
                multiple
                :disabled="busy || images.length >= MAX_PHOTOS"
                :label="images.length >= MAX_PHOTOS ? `写真は ${MAX_PHOTOS} 枚までです` : '写真を選ぶ（ここにドラッグ＆ドロップしても追加できます）'"
                :hint="progress ?? `あと ${MAX_PHOTOS - images.length} 枚追加できます`"
                @select="upload"
            />
        </template>
        <template v-else>
            <ul v-if="queued.length > 0" class="flex flex-col gap-2" data-test="queued-photos">
                <li v-for="(file, index) in queued" :key="`${index}-${file.name}`" class="flex items-center justify-between gap-3" data-test="queued-photo">
                    <span class="flex min-w-0 items-center gap-2">
                        <AppIcon name="image" />
                        <AppText as="span" size="sm" break-all>{{ index + 1 }} 枚目: {{ file.name }}</AppText>
                    </span>
                    <AppButton size="sm" variant="ghost" :aria-label="`${file.name} を削除`" data-test="dequeue-photo" @click="dequeue(index)">削除</AppButton>
                </li>
            </ul>
            <FileDrop
                v-if="!disabled"
                :accept="PHOTO_TYPES"
                multiple
                :disabled="queued.length >= MAX_PHOTOS"
                :label="queued.length >= MAX_PHOTOS ? `写真は ${MAX_PHOTOS} 枚までです` : '写真を選ぶ（ここにドラッグ＆ドロップしても追加できます）'"
                :hint="`保存するときに一緒にアップロードします。あと ${MAX_PHOTOS - queued.length} 枚選べます`"
                @select="enqueue"
            />
        </template>
        <AppNotice v-if="message" tone="success" data-test="photo-message">{{ message }}</AppNotice>
        <AppNotice v-if="error" tone="error">{{ error }}</AppNotice>
        <AppNotice v-if="failures.length > 0" tone="error">
            <span>追加できなかった写真があります。</span>
            <ul data-test="photo-errors">
                <li v-for="(failure, i) in failures" :key="i">{{ failure.name }}: {{ failure.message }}</li>
            </ul>
        </AppNotice>
    </AppPanel>
</template>
