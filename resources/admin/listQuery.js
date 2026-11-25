import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

// 一覧の型の条件。言葉（q）・状態（status）・並べ替え（sort）・ページ（page）を URL に持つので、
// 戻る・読み込み直し・URL の共有で同じ表示になる。URL の値は決まった候補だけを使い、知らない値は既定に戻す
// （サーバーも同じ候補で確認する。ここは見た目のため）。
//
//   statuses: 状態の候補（値の並び）。sorts: 並べ替えの候補（「-」の付かない名前の並び）。defaultSort: 既定の並べ替え
export function useListQuery({ statuses, sorts, defaultSort }) {
    const route = useRoute();
    const router = useRouter();
    const q = ref(String(route.query.q ?? ''));

    // 戻る・進むで URL が変わったら、検索欄の文字も URL に合わせる（打ちかけの文字を URL の古い値で残さない）
    watch(() => route.query.q, (value) => {
        q.value = String(value ?? '');
    });

    const page = computed(() => {
        const n = Number.parseInt(String(route.query.page ?? '1'), 10);

        return Number.isSafeInteger(n) && n >= 1 ? n : 1;
    });
    const status = computed({
        get: () => (statuses.includes(route.query.status) ? route.query.status : ''),
        set: (value) => navigate({ status: value || undefined, page: undefined }),
    });
    const sort = computed({
        get: () => (sorts.includes(String(route.query.sort ?? '').replace(/^-/, '')) ? String(route.query.sort) : defaultSort),
        set: (value) => navigate({ sort: value === defaultSort ? undefined : value, page: undefined }),
    });
    // 言葉か状態で絞っているか（並べ替えとページは絞り込みでない）
    const filtered = computed(() => String(route.query.q ?? '') !== '' || status.value !== '');

    function navigate(changes) {
        const query = { ...route.query, ...changes };
        router.push({ query: Object.fromEntries(Object.entries(query).filter(([, v]) => v !== undefined && v !== '')) });
    }

    function submitSearch() {
        navigate({ q: q.value.trim() || undefined, page: undefined });
    }

    // 条件を外す（言葉・状態・ページ。並べ替えは残す）
    function clear() {
        q.value = '';
        navigate({ q: undefined, status: undefined, page: undefined });
    }

    function go(n) {
        navigate({ page: n === 1 ? undefined : String(n) });
    }

    return { route, q, page, status, sort, filtered, navigate, submitSearch, clear, go };
}

// 「/」で検索欄へ（入力中は奪わない。ベイジの 14）。search は SearchField の ref
export function useSearchShortcut(search) {
    function onKeydown(event) {
        const target = event.target;
        const typing = target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));

        if (event.key === '/' && !typing && !event.metaKey && !event.ctrlKey && !event.altKey) {
            event.preventDefault();
            search.value?.focus();
        }
    }

    onMounted(() => document.addEventListener('keydown', onKeydown));
    onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
}
