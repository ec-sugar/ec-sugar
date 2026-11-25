import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

// Node.js はビルドの時だけ使う（ADR 0015）。開発でも開発サーバーは立てず、`npm run watch` でビルドし直す。
// 本番と同じ CSP（インラインの script / style なし）のまま画面を確かめられる。
export default defineConfig({
    plugins: [vue(), tailwindcss()],
    base: '/build/',
    publicDir: false,
    build: {
        outDir: 'public/build',
        emptyOutDir: true,
        // Laravel の @vite が読む場所
        manifest: 'manifest.json',
        // 小さな画像やフォントも data: URI にしない（CSP の img-src / font-src を 'self' だけにする）
        assetsInlineLimit: 0,
        sourcemap: false,
        rollupOptions: {
            // 管理画面の SPA、店頭の核の部品（カート）、Trusted Types の既定の関所（App を差し込む画面だけ。ADR 0034）、入っているテーマの CSS（店頭。ADR 0018）
            input: ['resources/admin/main.js', 'resources/storefront/cart.js', 'resources/storefront/trusted-types.js', 'plugins/theme-default/resources/css/theme.css', 'plugins/theme-kinari/resources/css/theme.css', 'plugins/theme-fashion/resources/css/theme.css', 'plugins/theme-botanica/resources/css/theme.css'],
        },
    },
});
