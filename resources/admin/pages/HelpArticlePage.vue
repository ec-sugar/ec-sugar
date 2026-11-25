<script setup>
import { computed, nextTick, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { crumbsFor, titleOf } from '../navigation.js';
import { articleOf, helpAvailable, helpRoute, partsOf, screenRoute, stepOf } from '../help/index.js';
import { session } from '../session.js';
import { onlyTenant } from '../tenants.js';
import { reducesMotion } from '../components/ui/motion.js';
import AppButton from '../components/ui/AppButton.vue';
import AppIcon from '../components/ui/AppIcon.vue';
import AppNotice from '../components/ui/AppNotice.vue';
import AppPanel from '../components/ui/AppPanel.vue';
import AppText from '../components/ui/AppText.vue';
import BulletList from '../components/ui/BulletList.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import FormOutline from '../components/ui/FormOutline.vue';
import HelpFigure from '../components/ui/HelpFigure.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import RichText from '../components/ui/RichText.vue';
import StepList from '../components/ui/StepList.vue';

// 説明のページ（/help/<key>）。中身はデータ（resources/admin/help/）で、この画面は描くだけ（ページごとの画面を作らない）。
// 節ごとに白いカード（段落 → 箇条書き → 写し → 手順 → 補足 → 注意の順）。広い画面では右に節の目次。URL の # の後ろが節の印なら、開いたときにその節へ移る（各画面の「説明を見る」から）。
// ?shop= はどの店の画面から来たか（表示のためだけ。節の「画面を開く」の行き先に使う。店に入れるかはサーバーが決める）。見られる店が 1 つならその店
// 写しは resources/help/ の画像（ビルドに入る。自分のオリジンだけ）。撮り直しは tests/e2e/help-shots.spec.js（HELP_SHOTS=1）
const props = defineProps({ article: { type: String, required: true } });
const route = useRoute();

const IMAGES = import.meta.glob('../../help/*.png', { eager: true, import: 'default', query: '?url' });
const imageOf = (file) => IMAGES[`../../help/${file}`] ?? null;

// 入っていない App の説明のページは「見つかりません」（helpAvailable）
const current = computed(() => {
    const article = articleOf(props.article);

    return helpAvailable(article, session.apps) ? article : null;
});
// 店の ID の形（router.js の :tenant と同じ）に合うものだけ
const shop = computed(() => {
    const value = route.query.shop;

    return typeof value === 'string' && /^[a-z0-9][a-z0-9_-]{0,62}$/.test(value) ? value : onlyTenant();
});
const crumbs = computed(() => crumbsFor('help-article', null, null, titleOf('help-article', { article: props.article })));

const sections = computed(() => (current.value?.sections ?? []).map((section) => ({
    ...section,
    paragraphs: (section.body ?? []).map((text) => partsOf(text, shop.value)),
    items: (section.list ?? []).map((text) => partsOf(text, shop.value)),
    steps: (section.steps ?? []).map(stepOf).map((step) => ({
        text: partsOf(step.text, shop.value),
        notes: step.notes.map((text) => partsOf(text, shop.value)),
        figure: step.figure ? { ...step.figure, src: imageOf(step.figure.file) } : null,
    })),
    figures: (section.figures ?? []).map((figure) => ({ ...figure, src: imageOf(figure.file) })),
    notes: (section.notes ?? []).map((text) => partsOf(text, shop.value)),
    caution: section.caution ? partsOf(section.caution, shop.value) : null,
    // その節の画面へ戻るボタン（店の画面は、どの店か分かるときだけ）
    opens: (section.screens ?? []).map((screen) => ({ screen, to: screenRoute(screen, shop.value), label: titleOf(screen) })).filter((open) => open.to !== null),
})));
const outline = computed(() => sections.value.map((section) => ({ id: section.id, label: section.title, changed: false })));

// # の後ろの節へ移る（焦点も移す）。動きを減らす設定ではすぐ移る
function jump() {
    const id = route.hash.replace(/^#/, '');

    if (id === '' || !sections.value.some((section) => section.id === id)) {
        return;
    }

    nextTick(() => {
        const element = document.getElementById(id);

        if (element) {
            element.scrollIntoView({ behavior: reducesMotion() ? 'auto' : 'smooth', block: 'start' });
            element.focus({ preventScroll: true });
        }
    });
}

onMounted(jump);
watch(() => [props.article, route.hash], jump);
</script>

<template>
    <PageHeader :title="current?.label ?? '見つかりません'" :crumbs="crumbs" />
    <ErrorState v-if="current === null" :status="404" title="説明のページが見つかりません" data-test="help-not-found">
        アドレスを確認するか、ヘルプの目次から選んでください。
        <template #action>
            <AppButton :to="{ name: 'help' }">ヘルプの目次へ</AppButton>
        </template>
    </ErrorState>
    <div v-else class="flex items-start gap-6">
        <div class="flex min-w-0 flex-1 flex-col gap-6" :data-test="`help-article-${current.key}`">
            <AppText tone="soft">{{ current.description }}</AppText>
            <AppPanel v-for="section in sections" :id="section.id" :key="section.id" tabindex="-1" :title="section.title" :data-test="`help-section-${section.id}`">
                <template v-if="section.opens.length" #actions>
                    <AppButton v-for="open in section.opens" :key="open.screen" size="sm" :to="open.to" :data-test="`help-open-${open.screen}`">
                        {{ open.label }}を開く<AppIcon name="chevron-right" />
                    </AppButton>
                </template>
                <AppText v-for="(text, index) in section.paragraphs" :key="`p-${index}`"><RichText :text="text" /></AppText>
                <BulletList v-if="section.items.length">
                    <li v-for="(text, index) in section.items" :key="index"><RichText :text="text" /></li>
                </BulletList>
                <HelpFigure v-for="figure in section.figures.filter((f) => f.src)" :key="figure.file" :src="figure.src" :alt="figure.alt" :caption="figure.caption" :width="figure.width" :height="figure.height" />
                <StepList v-if="section.steps.length" :size="section.stepsSize ?? 'md'">
                    <li v-for="(step, index) in section.steps" :key="index" class="flex flex-col gap-3">
                        <AppText :as="section.stepsSize === 'sm' ? 'span' : 'p'" :size="section.stepsSize === 'sm' ? 'sm' : 'md'"><RichText :text="step.text" /></AppText>
                        <AppText v-for="(note, noteIndex) in step.notes" :key="noteIndex" tone="soft"><RichText :text="note" /></AppText>
                        <HelpFigure v-if="step.figure?.src" :src="step.figure.src" :alt="step.figure.alt" :caption="step.figure.caption" :width="step.figure.width" :height="step.figure.height" />
                    </li>
                </StepList>
                <AppText v-for="(text, index) in section.notes" :key="`n-${index}`" tone="soft"><RichText :text="text" /></AppText>
                <AppNotice v-if="section.caution" tone="warning" :live="false"><span><RichText :text="section.caution" /></span></AppNotice>
            </AppPanel>
            <AppPanel v-if="current.key !== 'faq'" title="困ったときは">
                <AppText>
                    <RichText :text="['うまくいかないときは、', { to: helpRoute('faq', null, shop), text: 'よくある質問' }, 'を見てください。ヘルプの目次では、言葉で探せます。']" />
                </AppText>
            </AppPanel>
        </div>
        <FormOutline :key="current.key" :items="outline" label="このページの目次" class="sticky top-8 hidden w-56 shrink-0 lg:flex" />
    </div>
</template>
