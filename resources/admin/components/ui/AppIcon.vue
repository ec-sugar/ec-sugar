<script setup>
import { computed } from 'vue';
import {
    ArrowLeft,
    ArrowRight,
    Building2,
    ChartLine,
    Check,
    ChevronDown,
    ChevronRight,
    ChevronsUpDown,
    ChevronUp,
    CircleQuestionMark,
    Clock,
    CloudOff,
    CreditCard,
    Download,
    ExternalLink,
    Eye,
    EyeOff,
    FileText,
    FolderTree,
    GripVertical,
    House,
    Image,
    Info,
    KeyRound,
    LayoutGrid,
    LayoutTemplate,
    List,
    Lock,
    LogOut,
    Megaphone,
    Menu,
    Merge,
    Monitor,
    Package,
    Paintbrush,
    PanelLeft,
    Plug,
    Plus,
    Receipt,
    RefreshCw,
    Repeat,
    Search,
    ServerCrash,
    ShieldCheck,
    Smartphone,
    SlidersHorizontal,
    Store,
    Ticket,
    Trash2,
    TriangleAlert,
    Truck,
    Upload,
    UserRound,
    Users,
    X,
} from '@lucide/vue';

// 線のアイコン（Lucide。ISC ライセンスのアイコンセット）。飾りなので読み上げない（意味は必ず隣の文字か aria-label で伝える。ベイジの 2）
// 絵はビルドに入る（外から読まない。管理画面の CSP＝読み込み元の制限は自分のオリジンだけ）。使う絵だけを名前で読み込み、全部は入れない
const props = defineProps({
    name: { type: String, required: true },
    size: { type: Number, default: 16 },
});

// 画面で使う名前 → Lucide の絵。足すときはここに 1 行（上の import にも足す）
const ICONS = {
    grid: LayoutGrid,
    // メニュー: ホーム・注文・ヘルプと、狭い画面でメニューを開くボタン
    home: House,
    receipt: Receipt,
    help: CircleQuestionMark,
    menu: Menu,
    box: Package,
    list: List,
    building: Building2,
    key: KeyRound,
    shield: ShieldCheck,
    plus: Plus,
    search: Search,
    'chevron-up-down': ChevronsUpDown,
    'chevron-down': ChevronDown,
    'chevron-up': ChevronUp,
    'chevron-right': ChevronRight,
    // 設定（歯車でなく調整のつまみ。前の手描きの形に合わせる）
    settings: SlidersHorizontal,
    'panel-left': PanelLeft,
    check: Check,
    alert: TriangleAlert,
    info: Info,
    tree: FolderTree,
    store: Store,
    upload: Upload,
    // 商品の CSV の書き出し
    download: Download,
    'arrow-left': ArrowLeft,
    'arrow-right': ArrowRight,
    users: Users,
    clock: Clock,
    plug: Plug,
    image: Image,
    brush: Paintbrush,
    // 自由なページ（ADR 0039 区切り 3）
    file: FileText,
    external: ExternalLink,
    // 商品のプレビュー
    eye: Eye,
    // ページの編集の画面（ADR 0039 区切り 2）: 隠したセクション・つかんで動かす所・パソコンとスマホの切り替え・消す
    'eye-off': EyeOff,
    grip: GripVertical,
    monitor: Monitor,
    smartphone: Smartphone,
    trash: Trash2,
    'log-out': LogOut,
    // エラーの画面（ErrorState）の絵
    lock: Lock,
    'cloud-off': CloudOff,
    'server-alert': ServerCrash,
    refresh: RefreshCw,
    // 札を外す（TagInput）
    x: X,
    // App の目的のまとまり（TenantPluginsPage の札の絵）
    chart: ChartLine,
    megaphone: Megaphone,
    layout: LayoutTemplate,
    repeat: Repeat,
    card: CreditCard,
    truck: Truck,
    // お客さん: メニューの絵と、同じお客さんの記録をまとめる
    customer: UserRound,
    ticket: Ticket,
    merge: Merge,
};

const icon = computed(() => {
    const found = ICONS[props.name] ?? null;
    if (found === null && import.meta.env.DEV) {
        console.warn(`AppIcon: 知らない名前「${props.name}」。AppIcon.vue の対応の表に足してください`);
    }

    return found;
});

// 線の太さは前の手描きのアイコンと同じ 1.8（Lucide の既定は 2）。知らない名前は何も描かない（空の枠で場所だけ取る。前の動きと同じ）
// 呼ぶ側の class（色など）がそのまま svg に付くよう、テンプレートの一番外は svg 1 つにする（注釈も置かない）
const STROKE_WIDTH = 1.8;
</script>

<template>
    <component :is="icon" v-if="icon" :size="size" :stroke-width="STROKE_WIDTH" aria-hidden="true" focusable="false" class="shrink-0" />
    <svg v-else aria-hidden="true" focusable="false" :width="size" :height="size" viewBox="0 0 24 24" class="shrink-0" />
</template>
