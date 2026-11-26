# 入れた JavaScript のライブラリ

基準は [ADR 0037](../adr/0037-JavaScriptのライブラリは基準を満たせば採る.md)。入れるとき・外すときに 1 行ずつ書く。版は package.json と lock ファイルが正。

| ライブラリ | 使う所 | 何のために | ライセンス | 依存 | 確認したこと | 入れた日 |
|---|---|---|---|---|---|---|
| vue・vue-router | 管理画面 | 画面の骨組み（ADR 0013・0016） | MIT | — | ADR 0016 | 2026-10-27 |
| @lucide/vue | 管理画面 | アイコン（使うアイコンだけをビルドに入れる。AppIcon.vue の対応の表） | ISC | 0（vue を前提にするだけ） | 1.57.0（10-14 の版）を固定。取得元の証明と署名あり、audit 0 件。lucide-vue-next は使うのをやめるよう勧める印が付いたので、正式な後継のこちら。ビルドの増え方は gzip で +2.8 kB | 2026-10-29 |
| motion | ショップ（コアのカートの部品 cart.js と動きの部品 motion.js） | カートの引き出しを横から滑らせる。値が変わった時・写真を入れ替えた時・メニューを閉じる時の動き（theme-api.md の「動き」）。小さい版の `motion/mini`（ブラウザの Web Animations だけを使う）を読む | MIT | 4（framer-motion・motion-dom・motion-utils が 14.1.1、tslib 2.8.1 は 0BSD）。react は任意の前提で入らない | 14.1.1（10-13 の版）を固定。2026-10-29 の比べ（ADR 0037）。登録所の署名あり・取得元の証明（provenance）は無い、audit 0 件。元のコードとビルドの出力に `eval`・`new Function`・`innerHTML` が無く、style は CSSOM（要素の style の項目）で書くので、ショップの CSP と Trusted Types の下で動く。ビルドの増え方は cart.js が gzip で +1.5 kB（3.77 → 5.27 kB） | 2026-10-30 |
| motion-v | 管理画面 | 通知（AppNotice）を下から浮かせて出す。`LazyMotion` と `domAnimation` で動きの機能だけを読む | MIT | 4（framer-motion・motion-dom・motion-utils は motion と共有、hey-listen 1.0.8 は開発時の警告だけの 427 バイトで 2022 年から更新なし）＋前提の @vueuse/core 15.0.0（@vueuse/shared・@vueuse/metadata・@types/web-bluetooth。前提として npm が入れる） | 2.7.0（10-12 の版）を固定。framer-motion ~14.1 を使うので motion と 14.1 でそろえた。@vueuse は 9-16 の版で取得元の証明あり、Motion の 3 つは登録所の署名だけ、audit 0 件。`eval`・`new Function`・`innerHTML` なし（出力に残るのは Vue の分だけ）。ビルドの増え方は main.js が gzip で +27.4 kB（105.1 → 132.5 kB。全部入りの `motion` だと +40.7 kB）、CSS は +0.07 kB | 2026-10-30 |
| openpgp | 管理画面（運営ダッシュボードの「復元のキット」だけ。`resources/admin/recovery-kit.js` が要る時に読む） | 復元のキットをブラウザの中で作る・開け直して確認する（ADR 0111）。秘密鍵ペアを作り、gpg と同じ形（OpenPGP）でパスフレーズの暗号を掛ける。ブラウザに入っている暗号の口（Web Crypto）だけでは OpenPGP の鍵と包みを作れず、自分で形を組むと防御の要の所に手作りの暗号の形を持つことになる | LGPL-3.0-or-later（AGPL v3 と組み合わせられる。変えずに使い、NOTICE に全文を添える） | 0 | 6.3.2（2026-09-24 の版。公開から 57 日）を固定。Proton が手入れしている部品で、外の監査を受けている。audit 0 件。ビルドの出力に `eval`・`new Function`・`innerHTML`・外への通信（`fetch`・`XMLHttpRequest`）が無い。`WebAssembly` は使わない道（Argon2 によるパスフレーズのストレッチング）にだけあり、管理画面の CSP（script-src 'self'）の下で動く。ふだんの画面には読まれない別のかたまり（gzip で 130 kB）。作った鍵とキットを、サーバーと同じ版の gpg（2.4）で開けられることを確認した（Curve25519 の鍵は 2.4 で開けられなかったので RSA 4096） | 2026-11-20 |

例外として認めたもの（2026-10-30、運営の判断）:
- hey-listen 1.0.8（motion-v の依存）: 2022 年から更新がなく、ADR 0037 の「1 年以内に更新」に合わない。開発時の警告だけの 427 バイトで、本番のビルドでは空になるので認める。motion-v が外したら消す
- Motion の 3 つ（framer-motion・motion-dom・motion-utils）と motion・motion-v: 取得元の証明（provenance）が無く、登録所の署名だけ。署名と audit 0 件、版の固定で認める
- @vueuse/core 15.0.0（motion-v の前提）: lock だけでなく package.json にも書いて固定する

ビルドだけに使うもの（Vite・Tailwind CSS・Playwright・フォント）は package.json の devDependencies。本番のイメージには入らない（ADR 0015）。
ただし、ビルドの出力に入るもの（Tailwind CSS の下地の CSS と、テーマが同梱する書体の Jost・Cormorant Garamond）は配る中身なので、下の NOTICE に載る。

## 配る中身の部品とライセンス（NOTICE）

リポジトリの直下の `NOTICE` に、配る中身に入る部品とそのライセンスを並べる（ADR 0033 の「作って決めたこと」）。手で直さず、スクリプトで作り直す。

```sh
npm ci --ignore-scripts   # node_modules が無ければ
release/notice.sh         # NOTICE を作り直す（public/build は触らない）
```

- 載るもの: composer.lock の本番の依存（`packages`。自社の `ec-sugar/*` と require-dev は除く）、ソースマップつきで使い捨ての場所へビルドして**実際に配る JS に入った** node_modules の部品と package.json の `dependencies`、ビルドした CSS に残る見出しの部品（Tailwind CSS）、テーマの CSS が `@import` する `@fontsource` の書体。JavaScript と書体はライセンスの全文も添える（縮めたビルドでは著作権の表示が消えるため）
- **依存を足した・外した・上げたら実行して、NOTICE も同じコミットに入れる**。足して実行し忘れると `tests/Unit/LicenseTest.php` が落ちる（PHP は名前とライセンスがちょうど同じか、JS は dependencies と書体があるか。版は比べないので、Dependabot の版上げだけでは落ちない。版はリリースの前に実行してそろえる）
- 入れる部品のライセンスは AGPL v3 と組み合わせられるもの（MIT・BSD・Apache-2.0・ISC・0BSD、書体は OFL-1.1）。「BSD か GPL を選べる」形は BSD を選ぶ。MPL-2.0 などは、組み立てだけに使い配る中身に入らないことを NOTICE で確認する（lightningcss）。GPL v2 だけのもの・ライセンスの無いものは入れない

## 防御に関わる PHP の部品（composer）

JavaScript と同じ考え方（ADR 0037 の基準と、conventions.md 6 の「公開から 7 日以上・既知の脆弱性・署名・版の固定」）で確認して入れたもの。版は composer.json と composer.lock が正。

| 部品 | 使う所 | 何のために | ライセンス | 依存 | 確認したこと | 入れた日 |
|---|---|---|---|---|---|---|
| symfony/html-sanitizer | コア（`App\Core\Section\SectionHtml`） | カスタムセクション（ADR 0041）の HTML を、保存のときと描くときに決まったタグと属性だけに整える。自分で正規表現で整えない | MIT | 足したものは無し（前提の league/uri と ext-dom はもう入っている）。PHP の HTML5 の解析器（`Dom\HTMLDocument`）で読む | 8.1.10（10-20 の版。公開から 10 日）を `8.1.10` に固定（2026-11-26 に `8.1.12`。Symfony のセキュリティの修正。固定のままだと composer が直った版へ上げられないので、修正が出たらこの行と composer.json を一緒に上げる）。Symfony の公式の部品（手入れが続き、セキュリティの通知の仕組みがある）、`composer audit` 0 件、roave/security-advisories を通る。中を読んで、表に無い要素を既定で中身ごと消すこと・属性の値を確認する口（`AttributeSanitizerInterface`）・解析器を差し替える口（`ParserInterface`）があることを確認した。`<head>` の要素（`style`・`meta`・`link`・`title`）は本文の設定から外される作りなので、既定を「消す」のまま使う（「タグだけ外す」を既定にすると `style` の中の文が文字で出る） | 2026-10-30 |

## 開発とテストだけの PHP の部品（composer の require-dev）

本番のイメージには入らない（`composer install --no-dev`）。考え方は上と同じ。

| 部品 | 使う所 | 何のために | ライセンス | 依存 | 確認したこと | 入れた日 |
|---|---|---|---|---|---|---|
| brianium/paratest | PHP のテスト（`php artisan test --parallel`・`make test`・CI の `check`） | テストを CPU の数だけ並列で実行する（ADR 0057）。Laravel の `--parallel` が前提にしている部品。処理を立てたまま、空いた処理へテストのファイルを 1 つずつ渡すので、遅いファイルに偏らない。自前で作ると PHPUnit の中の作りに頼ることになる | MIT | 2（fidry/cpu-core-counter 1.4.1・jean85/pretty-package-versions 2.1.1。どちらも MIT）。ほかの前提（symfony/process・phpunit の部品）はもう入っている | v7.20.0（2026-03-29 の版）を `^7.20` で入れ、lock で固定。今の PHPUnit 12.5 に合う最後の版（7.21 から PHPUnit 13 を求める。PHPUnit を 13 に上げるときに一緒に上げる）。`composer audit` 0 件、roave/security-advisories を通る。並べた各々の DB・店の DB の頭・写真の保存先は `tests/bootstrap.php` が決める（Laravel の既定の名前の付け方はテストの起動時のチェックの決まりの外なので止める） | 2026-11-05 |
