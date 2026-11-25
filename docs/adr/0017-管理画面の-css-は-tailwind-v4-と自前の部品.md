# 0017 管理画面の CSS は Tailwind v4 と自前の部品

状態: 採用（2026-10-27）

## 文脈

管理画面の画面を増やす前に、見た目の決まりを作る。方向は見本の中から選んだ A′（モダンで統一感があり、清潔な、落ち着いた業務ツール）。
CSP は `style-src 'self'`・インラインなし・Trusted Types（ADR 0016）、本番で Node.js を常駐させない（ADR 0015）。
将来、ストアのテーマを外部の人が作る。開発する側が Tailwind に慣れていて、テーマを作る人にも Tailwind があると扱いやすい。

比べた案: Tailwind v4、Tailwind ＋ shadcn-vue（部品の雛形を写す）、UnoCSS、daisyUI、Bootstrap、自前の CSS ＋ トークン、部品集のライブラリ（Vuetify・PrimeVue など）。
部品集のライブラリの多くは実行中に `<style>` を差し込むので CSP で止まる（緩めると XSS への守りを手放す）。依存も大きい。

## 決定

- **Tailwind CSS v4 ＋ 自前の Vue 部品**（`resources/admin/components/ui`）。Tailwind はビルドの時だけ動き、静的な CSS を出す
- 見た目の値は `admin.css` のトークン（CSS の変数。意味で名前を付ける）だけ。画面に色の値・Tailwind の既定の色・その場限りの色を書かない。ダークモードはトークンの値の入れ替えだけ
- Tailwind のクラスは部品の中に書き、画面は部品を組み合わせる（統一感を部品で保つ）
- フォントは OS 標準（Web フォントを使わない。同梱も要らない）
- 決まりの本文は `docs/design/admin-ui.md`（ベイジの 25 項目を下敷き）
- キーボード操作の作り込みが難しい部品（候補つきの入力、メニューなど）が要るようになったら、そのときに shadcn-vue（Reka UI）の取り込みを別に検討する
- 機械で守る: 色の直書き・危ない書き方（`AdminShellTest`）、文字と地のコントラスト WCAG AA（`AdminContrastTest`）、CSP 違反 0 と操作（ブラウザのテスト）

## 結果

- 得たもの: CSP を緩めずに、統一された見た目を速く作れる。色や余白をトークンで一度に変えられる。テーマを作る人にも通じる書き方（Tailwind）にそろう
- 失ったもの: npm の依存が増えた（tailwindcss・@tailwindcss/vite と、その下の lightningcss・oxide などのネイティブの部品。いずれもビルドの時だけ。バージョンを固定し、install スクリプトは動かさない）。部品は自前で作り続ける手間がある
