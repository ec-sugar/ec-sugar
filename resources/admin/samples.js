import { ref } from 'vue';
import { api } from './api.js';
import { useOperation } from './operation.js';

// 入れたときのサンプルをまとめて消す。ホームの開設の手順と、商品の一覧の上の帯で、同じ流れと同じ文を使う。
// 消せるのはオーナーだけ（サーバーの経路も RequireOwner と直前の再確認で断る。ボタンを隠すのは見た目のためだけ）。
// サーバーがパスキーの再確認を求めたら、useOperation が確認してから送り直す。
// after(result) は消した後の続き（画面の読み直し）。result.samples は消した後に残っているサンプルの数（ShopSamples::summary。無ければ null）

// 消さずに残した物の理由と種類（SampleRemover の kept）
export const KEPT_REASONS = { ordered: '注文がある', edited: '編集済み', in_use: '使用中' };
export const KEPT_TYPES = { listing: '商品', category: 'カテゴリ', shop_media: 'ショップの写真', theme_version: 'テーマの内容', shop_settings: '特商法の表記' };

// tenant は今の店の ID を返す関数（画面の props が変わっても、押した時の店へ送る）
export function useSampleRemoval(tenant, { after = null } = {}) {
    const operation = useOperation();
    const result = ref(null);

    function remove() {
        // テナント ID は経路の形（router.js）で絞ってあり、さらに URL に入れる前に符号化する
        return operation.run('samples', () => api('DELETE', `/api/tenants/${encodeURIComponent(tenant())}/samples`), {
            then: async (answer) => {
                result.value = answer;
                await after?.(answer);
            },
            done: (answer) => `サンプルを ${answer.removed} 件削除しました。`,
        });
    }

    // 店を替えたときに、前の店の結果の文を消す
    function reset() {
        result.value = null;
        operation.error.value = null;
        operation.message.value = null;
    }

    return { operation, result, remove, reset };
}
