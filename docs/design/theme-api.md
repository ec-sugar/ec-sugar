# 店頭のテーマ API（第 2 バージョン）

店頭（ADR 0018）がテーマに約束していること。テーマはこのバージョンに合わせて作り、マニフェスト（composer.json の `extra.ec-sugar.theme_api`）でバージョンを宣言する。
バージョンの合わないテーマは登録できない（`ThemeRegistry`）。この約束を変えるときはバージョンを上げ、ここを書き直す（CONCEPT 7「テーマ API はバージョン固定」）。
付け足し（新しく渡す値・部品・任意のキー）ではバージョンを上げない。今のテーマが書き直さないと動かなくなる変更でだけ上げ、前のバージョンも次のマイナーリリースまでは受け付け、[CHANGELOG](../../CHANGELOG.md) の「壊す変更」で通知する（互換の約束、[ADR 0093](../adr/0093-外に約束する口を決め、壊すときはAPIのバージョンを上げてCHANGELOGで知らせる.md)。上の見出しの番号は `CompatibilityPromiseTest` が `ThemeRegistry::API_VERSION` と比べる）。

## テーマの形

- composer のパッケージ（`plugins/` と同じ配り方。管理画面からは入れられない）
- サービスプロバイダの `boot` で、画面（Blade）を `loadViewsFrom` で読み込ませ、`ThemeRegistry` に `ThemeDefinition` を登録する
- CSS は Tailwind CSS v4 を `@import "tailwindcss"` で使い、色・文字はテーマのトークン（CSS の変数）で決める。ビルドのエントリー（`stylesheet`）を `vite.config.js` の入口に足す
- 書体を使うときは、ファイルをテーマのビルドに同梱して自分のオリジンから配る（例: 公式テーマ 1 は `@fontsource/cormorant-garamond` の英字だけを `@import`）。Google Fonts など外部の読み込みは CSP で止まる。許諾（OFL など）を確認してから入れる

テーマの信頼の段階（ADR 0020）: ここに書いた Blade ＋ composer の形は**公式テーマ（自社）だけ**。サードパーティのテーマはコードを含まない形式（Liquid ＋ ビルド済み CSS、JS は審査済みだけ）で受け付ける予定。それまではサードパーティのテーマを入れない。下の「テーマがしてはいけないこと」の検査は書き方の決まりで、悪意のある作者を止める仕組みではない

入っているテーマ:

| 名前 | パッケージ | 内容 |
|---|---|---|
| `default` | `plugins/theme-default` | 既定のテーマ「角砂糖」（白黒、角砂糖の積み木、Jost の英字）。店がテーマを選んでいないとき・選んだテーマが入っていないときに使う。**テーマ API 2・セクションの並び**（お知らせバナー・ヒーローとショップの説明・商品の並び・カテゴリ・約束・フッター。パターンは下の「公式テーマのセクションとパターン: 角砂糖と kinari」） |
| `kinari` | `plugins/theme-kinari` | 公式テーマ 1（kinari）（アパレル向け。生成りの地とセージ、細いセリフの見出し）。**テーマ API 2・セクションの並び**（お知らせバナー・ヒーロー・カテゴリ・コーディネート・新着・よく選ばれている商品・季節の特集・約束・フッター。パターンは下の「公式テーマのセクションとパターン: 角砂糖と kinari」） |
| `botanica` | `plugins/theme-botanica` | 公式テーマ 3。セージグリーンと白、左の大きな写真と右の特集 2 点。植物・園芸向け。**テーマ API 2**（右の特集はタイトル・英字・写真・行き先）。**セクションの並びの形**（下の「公式テーマのパターン」。自由なページの画面 `page` とメニューの `$navPages` も） |
| `fashion` | `plugins/theme-fashion` | 公式テーマ 2。白背景・中央ロゴ・全面のヒーロー写真・3 列カテゴリ・6 列の新着。**テーマ API 2**（セクションは `theme.json`。写真と文言は管理画面の「デザイン」の「テーマ」から）。**セクションの並びの形**（下の「公式テーマのパターン」。自由なページの画面 `page` とメニューの `$navPages` も） |

店ごとのテーマはテナントの `data.theme`。`php artisan storefront:theme <テナント> <テーマ>` で変え（`--reset` で既定に戻す）、変えたことは中央の監査ログ（`tenant.theme_changed`）に残る。管理画面の店の設定ができたら、そこからも変える

```json
"extra": {
    "laravel": { "providers": ["EcSugar\\ThemeDefault\\ThemeServiceProvider"] },
    "ec-sugar": { "type": "theme", "name": "default", "theme_api": 2, "core": "^1.0" }
}
```

マニフェストのキーは `ec-sugar`。名前を EC-SUGAR にそろえる前のキー `sugar-ec`（旧名 SUGAR-EC）も当面は読む（`ec-sugar` が無いときだけ。両方あれば `ec-sugar` だけを見る。ADR 0025 の「作って決めたこと」）。新しく作るテーマは `ec-sugar` で書く

## テーマ API 2: セクションの宣言（ADR 0028 段階 2）

公式テーマはすべて 2 に移った（段階 2b・2c）。第 1 バージョン（店の設定のお知らせバナー・ヒーロー・安心の一行・トップのセクションを `$shop` と `HomeSectionsView` で受け取る形）は段階 2c でやめた。店の設定にあった値は退避用の表（`legacy_storefront_settings`）に移し、セクションのテーマへ初めて切り替えたときにセクションへコピーする。下の画面の約束は 1 から変わらない。

- テーマはパッケージの中に **`theme.json`** を置き、`ThemeDefinition` の `manifest` にそのパスを渡す。登録のときにコアが読んで確認し、形が違えば登録で止まる（バージョンの違いと同じく、アプリが起動しない。公式テーマだけなので CI で気づく。64KB まで。書けるのは `theme_api`・`sections`・`pages`〔ページのセクションの並び。下の「ページのセクションの並び」〕・`motion`〔動きの強さ。下の「動き」〕・`screenshot`〔見本の画像。下の「見本の画像」〕・`style`〔店の人が選べる色・字・角の丸み。下の「見た目」〕だけ）
- `sections` はセクションの名前（英小文字で始まる英小文字・数字・`_`、32 文字まで）をキーにした宣言。30 セクションまで。セクションは `label`（30 文字まで）と `fields`（欄、20 個まで）、繰り返しのセット `items`（`label`・`max`〔1〜12〕・`fields`。1 階層だけ）を持つ。ページに並べるテーマでは、ここがセクションの「種類」の宣言になり、`patterns`・`blocks`・`max_blocks`・`limit` と見本の `example`・`example_blocks` も書ける（下の「ページのセクションの並び」）
- 欄の種類はコアが決めた 7 つだけ（ほかにコアのセクションだけが使う `html`・`richtext` がある。テーマは宣言できず、登録で止まる。下の「カスタムセクション」）。`max` を書けばコアの上限より小さくできる（越えられない）。どの欄にも `label`（必須）と `help`（100 文字まで）

| 種類 | 店の人が入れるもの | テーマが受け取るもの | 上限（コア） |
|---|---|---|---|
| `text` | 1 行の文 | `string\|null` | 200 文字 |
| `textarea` | 複数行の文（`whitespace-pre-line` で出す） | `string\|null` | 2000 文字 |
| `image` | ショップの写真から選ぶ | `ShopPhoto\|null`（`url()`・`srcset()`・`width`・`height`・`alt`〔店の人の説明か null〕） | — |
| `link` | 店の中のパス（`/products` など）か `https://` の URL（URL に使える文字だけ。`"` `'` `<` `>` 空白は断る） | `string\|null`（`{{ }}` で `href` に） | 500 文字 |
| `products` | 新着・カテゴリ・選んだ商品のどれか（`from` で取得元を絞れる） | `list<ListingCard>`（公開中だけ、`max` 個まで） | 24 個 |
| `categories` | カテゴリを選ぶ（選ばなければ一番上の階層） | `list<{label, slug, photo}>` | 12 個 |
| `choice` | `options`（値 => 名前、30 個まで。2026-11-12 に 20 から広げた）から 1 つ | `string\|null`（値） | — |

セクションで選んだカテゴリ（`categories` の並びと、`products` の取得元が「カテゴリから」のときのカテゴリ）と写真は、店の人が消せない（どのテーマ・バージョンの下書きと公開でも、使っている間は断る。カテゴリは ADR 0039）。コアはカテゴリの ID の場所を欄の種類で見つけるので、テーマはカテゴリを `text` などほかの種類の欄に入れさせない。

すべての画面に `$sections`（`ThemeSections`）を渡す。`$sections->get('hero')` は、店の人が隠したセクションなら `null`、それ以外は `SectionView`（まだ何も入れていなければ中身が空）。中身はトップの公開した並びの、その種類の最初のセクション（ページに並べるテーマで、店の人がその種類を並びから外していれば `null`）。欄は `$hero['heading']`、繰り返しは `$hero->items`（各行も欄の名前で読む配列）。`isEmpty()` で空かどうか。宣言に無いセクション・欄の名前は書き間違いとして例外にする（テストで気づける）。出すのは店の人が**公開**した値だけで、下書きは出ない。

```json
{
    "theme_api": 2,
    "sections": {
        "hero": { "label": "ヒーロー", "fields": {
            "heading": { "type": "text", "label": "見出し", "max": 60 },
            "image": { "type": "image", "label": "写真" },
            "link": { "type": "link", "label": "ボタンの行き先" } } },
        "features": { "label": "特集", "items": { "max": 3, "fields": {
            "title": { "type": "text", "label": "題", "max": 30 },
            "tone": { "type": "choice", "label": "色", "options": { "light": "明るい", "dark": "暗い" } } } } }
    }
}
```

```blade
@if ($hero = $sections->get('hero'))
    <h1>{{ $hero['heading'] ?? $shop->name }}</h1>
    @if ($hero['image'])
        <img src="{{ $hero['image']->url() }}" srcset="{{ $hero['image']->srcset() }}" sizes="100vw" width="{{ $hero['image']->width }}" height="{{ $hero['image']->height }}" alt="">
    @endif
@endif
```

見本は `tests/Support/Themes/sample`（テスト用のテーマ）と `plugins/theme-fashion`（公式テーマ 2。段階 2b で移した）。

- 店の人は管理画面の「デザイン」（「テーマ」のタブ）の「編集する」から、ページの編集の画面で宣言から作られた欄に値を入れる（下書きは自動で保存し、「公開する」でショップに出す。からテーマのタブには欄を置かない）。写真は「写真」の画面（ショップの写真）から選ぶか、その場で上げる
- テーマをセクションに移したら、`php artisan storefront:import-theme-content [店]` で店の設定の今の値をセクションへコピーする（決まったセクションの名前 `announcement`・`hero`・`promises`・`categories`・`looks`・`seasonal`・`favorites` にコピーする。そのテーマにまだバージョンが無い店だけ。特集の写真は商品の写真からショップの写真へ再コピーする）
- テーマは `@php` を書けないので、セクションは `@if ($hero = $sections->get('hero'))` や `$sections->get('hero')?->offsetGet('image')` の形で読む（`get` は同じセクションを 1 回だけ組み立てる）

### 見本の画像（screenshot。任意）

管理画面の「デザイン」のテーマの画面（今のテーマのカードと、ほかのテーマのカード）に出すアイコン。書かなければ、カードは無地の枠に線のアイコンになる。

```json
{ "theme_api": 2, "screenshot": "screenshot.jpg", "sections": { … } }
```

- `theme.json` と**同じフォルダ**の png・jpg（jpeg）・webp のファイルの**名前だけ**を書く（英小文字・数字・`_`・`-`。`/` や `..` は書けない。フォルダの外を指すシンボリックリンクも断る）。512KB まで。無い・形が違う・大きすぎると登録で止まる
- 管理画面は `GET /api/themes/{テーマ}/screenshot`（ログイン中の運営だけ。店をまたがない静的な画像）で読む。配る前に中身の頭が拡張子どおりのアイコンかを確認し、違えば 404
- 形の目安: ショップのトップを幅 1280・高さ 1800 で開き、半分の解像度（640 × 900）で撮った縦長の画像。今のテーマのカードでは全体を、ほかのテーマのカードでは上の 4:3 を出す
- 公式テーマ 4 つのアイコンは `THEME_SHOTS=1 npx playwright test theme-shots` で撮り直せる（商品 6 つ・無地の写真・セクションは保存していない店。見本はワイヤーの形で、写真は無地の枠）。テーマの見た目を変えたら撮り直す

## ページのセクションの並び（ADR 0039。テーマ API 2 の付け足し）

お店の人がテーマの用意したセクションを足し・消し・並べ替え、セクションごとに見た目のパターンを選んでページを作る形。テーマは**セクションの種類**と**種類ごとのパターン**と**ページごとに置ける種類と最初の並び**を宣言し、種類 × パターンの Blade の部品を持つ。ページはトップ（`home`）と、店の人が作る自由なページ（下の「自由なページ」）。

### 宣言の書き方

```json
{
    "theme_api": 2,
    "sections": {
        "announcement": { "label": "お知らせの帯", "limit": 1, "fields": { "text": { "type": "text", "label": "お知らせ" } } },
        "hero": {
            "label": "ヒーロー",
            "patterns": {
                "full": { "label": "全面", "wireframe": "hero-full" },
                "split": { "label": "左右", "wireframe": "hero-split" },
                "grid": { "label": "格子", "wireframe": "hero-grid" }
            },
            "fields": { "heading": { "type": "text", "label": "見出し", "max": 60 }, "image": { "type": "image", "label": "写真" } },
            "blocks": {
                "button": { "label": "ボタン", "fields": { "label": { "type": "text", "label": "文字", "max": 20 }, "link": { "type": "link", "label": "行き先" } } }
            },
            "max_blocks": 3
        },
        "rail": { "label": "商品の並び", "fields": { "products": { "type": "products", "label": "商品", "max": 8 } } }
    },
    "pages": {
        "home": {
            "types": ["announcement", "hero", "rail"],
            "default": [{ "type": "announcement" }, { "type": "hero", "pattern": "full" }, { "type": "rail" }]
        }
    }
}
```

- `patterns`: パターンの名前（セクションの名前と同じ形）=> `{ "label": 画面の名前, "wireframe": ワイヤーの絵（任意） }`、1〜6 個。最初が既定。書かなければ `default`（「標準」）の 1 つ
- `wireframe`（任意）: 編集の画面の「＋ セクションを足す」に出すワイヤーフレームの画像の名前。下の「ワイヤーフレームの画像」の語彙から選ぶ。パターンに書くか、セクションに書く（セクションに書いたアイコンは、アイコンを書いていないパターンの画像。パターンを宣言しないセクションはセクションに書く）
- `blocks`: セクションの中の小さなブロックの種類（見出し・文・ボタンなど）=> `{ label, fields }`、1〜10 種類。欄はセクションと同じコアの 7 種類。`max_blocks` は 1 セクションに置ける数（1〜20、書かなければ 20）
- `limit`: 1 つのページに置ける数（書かなければページの上限まで）。外枠が読むセクション（お知らせバナーなど）は 1 にする
- `role`（任意）: セクションの役目。コアの決まった名前 `hero`（ヒーロー）・`categories`（カテゴリ）・`products`（商品の並び）・`promises`（安心の一行）のどれか。コアのページの雛形はセクションを名前でなく役目で書くので、書いておくと雛形がテーマのセクションを使う（下の「ページの雛形」）。同じ役目を複数のセクションに書いてよい（ページの `types` の順で最初のもの）。知らない名前は登録で止める
- `pages.home.types`: そのページに置ける種類（1 つ以上）。`default`: 最初の並び（お店の人がまだ保存していないときの並び。`pattern` を書かなければ最初のパターン）。`pages` を書くなら `home` が要り、`sections` の種類はどれかのページの `types` に入れる（どこにも置けない種類は登録で止める）
- コアの上限: 1 ページ 40 セクション・1 セクションのブロック 20 個・1 つのバージョンの値の全体 256KB。テーマは越えられない
- **`pages` を書かないテーマ**（今の書き方。公式テーマはどれも並びの形に移ったので、今はテスト用のテーマだけ）は、宣言したセクションを宣言の順に 1 つずつ置いた決まった並びとして扱う。お店の人は値と隠す・出すだけを変えられ、画面は今までどおり `$sections->get('…')` で読む

### 見本（example・example_blocks。任意）

編集の画面で「＋ セクションを足す」で足したセクションに、最初から入れておく値。お店の人は見本の文を書き換えるだけでセクションを作れる。書かない種類は今まで通り空の値で足される。

```json
"hero": {
    "label": "ヒーロー",
    "fields": { "heading": { "type": "text", "label": "見出し", "max": 60 }, "image": { "type": "image", "label": "写真" }, "link": { "type": "link", "label": "行き先" } },
    "blocks": { "button": { "label": "ボタン", "fields": { "label": { "type": "text", "label": "文字" }, "link": { "type": "link", "label": "行き先" } } } },
    "example": { "heading": "見出しを入れてください", "link": "/products" },
    "example_blocks": [{ "type": "button", "values": { "label": "くわしく見る", "link": "/products" } }]
},
"features": {
    "label": "特集",
    "fields": { "heading": { "type": "text", "label": "見出し" } },
    "items": { "max": 3, "fields": { "title": { "type": "text", "label": "題" } } },
    "example": { "heading": "特集", "items": [{ "title": "1 つ目の特集" }, { "title": "2 つ目の特集" }] }
}
```

- `example`: 欄の名前 => 値（店の人の値 `values` と同じ形）。繰り返しのあるセクションは `items` に見本の並び（行は繰り返しの欄の名前 => 値）。繰り返しだけのセクションは、並び（`[{ "title": … }]`）をそのまま `example` に書いてもよい。書かない欄は空
- `example_blocks`: `[{ "type": ブロックの種類, "values": { 欄: 値 } }]`。セクションを足したときにこの並びで入る。お店の人がブロックを足すときも、その種類の見本（並びの中の同じ種類の最初のもの）が入る。`blocks` を書いたセクションだけに書ける
- 登録のときに、店の人の値と同じ決まり（欄の形・文の長さ・改行・リンクの形・選択肢・ブロックの種類・繰り返しとブロックの数の上限・知らない欄）で確認し、合わなければ登録で止める（パスつき。例: `sections.hero.example.heading`）
- **写真・選んだ商品・カテゴリは書けない**（`image` に値、`products` の `category`・`pick`、`categories` の並び）。見本でお店の写真や商品を勝手に選ばない（どの店にも無い ID になる）。商品の並びは `{ "source": "new" }`（新着）だけ書ける
- 見本は「見本と分かる短い文」にする（「見出しを入れてください」など）。リンクは店の中のパス（`/products` など）
- 見本が入るのは編集の画面で足したときだけ。テーマの最初の並び（`default`。まだ保存していないページ）は今まで通り空の値で、ショップに見本の文は出ない

### 描き方

- トップの画面に `$page`（`PageView`）を渡す。公開した順にセクション（`SectionView`）が入っている。店の人が隠したセクションと、**テーマに部品が無い種類のセクションは入らない**（エラーにしない。お知らせバナーのように外枠が `$sections->get` で読む種類は、部品を作らなければページには出ない）
- 部品は `views/sections/{種類}/{パターン}.blade.php`、無ければ `views/sections/{種類}.blade.php`（パターンごとに部品を分けても、1 つの部品で `$section->pattern` を見て描き分けてもよい）。コアが選んだ部品の名前が `$section->view` に入っている
- セクションの中身は今までと同じ読み方（`$section['heading']`・`$section->items`）。ほかに `$section->id`（並びの中で重ならない。`data-` に出してよい）・`$section->pattern`・`$section->blocks`（`SectionView` の並び。`$block->key` がブロックの種類、欄は `$block['label']`）
- 部品はトップの画面の変数（`$shop`・`$listings` など）も読める

```blade
@section('content')
    @foreach ($page as $section)
        @include($section->view, ['section' => $section])
    @endforeach
@endsection
```

```blade
{{-- views/sections/hero/split.blade.php --}}
<section class="grid gap-6 md:grid-cols-2" data-section="{{ $section->id }}">
    <h1>{{ $section['heading'] ?? $shop->name }}</h1>
    @foreach ($section->blocks as $block)
        @if ($block->key === 'button' && $block['link'])
            <a href="{{ $block['link'] }}">{{ $block['label'] ?? '見る' }}</a>
        @endif
    @endforeach
</section>
```

### ワイヤーフレームの画像（wireframe。任意）

編集の画面の「＋ セクションを足す」は、種類ごとにパターンをワイヤーフレームの画像のカードで並べる（写真は無地の枠、文は線、濃い地は面）。アイコンは**コアが持つ**決まった語彙の中から、テーマが名前で選ぶ（テーマは SVG もコードも持ち込まない。ADR 0020）。知らない名前・文字でない値は登録で止める（パスつき。例: `sections.hero.patterns.full.wireframe`）。書かなかったパターンは汎用のアイコン（枠の中に見出しと文）と名前で出る。公式テーマ 4 つとコアの汎用のセクションは、すべてのパターンに書いてある（`WireframeTest` が見る）。

```json
"announcement": { "label": "お知らせの帯", "wireframe": "announcement-bar", "limit": 1, "fields": { … } },
"hero": {
    "label": "ヒーロー",
    "patterns": { "full": { "label": "全面", "wireframe": "hero-full" }, "split": { "label": "左右", "wireframe": "hero-split" } },
    …
}
```

| 名前 | アイコン |
|---|---|
| `announcement-bar` | ページの上の細いバナーに 1 行の文 |
| `hero-full` | 写真が幅いっぱい、文字を写真に重ねる |
| `hero-split` | 写真と文を左右に |
| `hero-grid` | 写真の格子と文 |
| `hero-band` | 写真だけのバナーの下に説明の文 |
| `hero-panel` | 上に写真、下に色の面と文字 |
| `products-grid` | 商品のカードを 4〜6 列で |
| `products-large` | 商品の大きなカードを 2 列で |
| `products-scroll` | 商品のカードが横に流れる |
| `products-side` | 左に見出し、右に商品のカード |
| `categories-cards` | 写真のカード（四角） |
| `categories-circles` | 丸い写真と名前 |
| `categories-bento` | 大小の箱を組んだ格子（ベント） |
| `categories-links` | 文字のリンクの並び |
| `features-stack` | 写真と文のセットを縦に積む |
| `features-two` | 写真と文のセットを 2 列で |
| `features-three` | 写真と文のセットを 3 列で |
| `features-scroll` | 写真と文のセットが横に流れる |
| `features-band` | 色のバナーに文と写真の並び |
| `feature-split` | 写真が左、文が右 |
| `feature-split-reverse` | 文が左、写真が右 |
| `feature-stack` | 写真が上、文が下 |
| `feature-overlay` | 写真が幅いっぱい、文を重ねる |
| `feature-overlap` | 写真に文の箱が重なって回り込む |
| `promises-row` | 小さなファイルと文を 1 行に並べる |
| `promises-cards` | 印と文のカードを多列で |
| `promises-dark` | 濃い地に印と文を 1 行で |
| `footer-columns` | リンクを多列で |
| `footer-dark` | 濃い地にリンクを多列で |
| `footer-line` | リンクを 1 行で |
| `footer-cta` | 濃い地に呼びかけとリンク |
| `text-left` | 見出しと文を左寄せ |
| `text-center` | 見出しと文を中央に |
| `image-wide` | 写真を幅いっぱい |
| `image-narrow` | 写真を文の幅で |
| `button-center` | ボタンを中央に |
| `button-left` | ボタンを左寄せ |
| `testimonials-cards` | お客さんの声のカードを 3 列で |
| `testimonials-quote` | お客さんの声を大きな引用で |
| `testimonials-scroll` | お客さんの声のカードが横に流れる |
| `faq-list` | 質問を開閉の 1 列で |
| `faq-side` | 左に見出し、右に質問の並び |
| `benefits-three` | アイコンと見出しと文を 3 列で |
| `benefits-four` | アイコンと見出しと文を 4 列で |
| `benefits-list` | アイコンと見出しと文を 2 列のリストで |
| `cta-band` | 色のバナーに見出しとボタン |
| `cta-photo` | 写真の上に見出しとボタン |
| `gallery-grid` | 写真の格子 |
| `gallery-masonry` | 高さの違う写真を石垣のように |
| `gallery-scroll` | 写真が横に流れる |
| `product-split` | 1 つの商品の写真が左、名前と価格とカートが右 |
| `product-full` | 1 つの商品の写真が全面、名前とカートの箱を重ねる |
| `entries-two` | 写真のリンクカードを 2 つ並べる |
| `entries-three` | 写真のリンクカードを 3 つ並べる |
| `story-longform` | 写真と長い文 |
| `story-timeline` | 年と出来事の年表 |
| `numbers-row` | 大きな数字と説明を 3 つ並べる |
| `numbers-photo` | 写真が左、数字と説明を 2 × 2 で右に |
| `logos-row` | ロゴを 1 行に並べる |
| `logos-grid` | ロゴを格子に並べる |
| `steps-row` | 番号の丸を線で接続、手順を横に並べる |
| `steps-line` | 左に見出し、右に番号の丸を縦の線で接続した手順 |
| `table-plain` | 見出しの行のある表 |
| `table-photo` | 写真が左、サイズ表が右 |
| `spacer-space` | セクションの間を空ける（上下の矢印） |
| `spacer-line` | セクションの間に線を 1 本 |
| `pricing-cards` | 値段のカードを 3 つ横に並べる（名前・値段・箇条・ボタン） |
| `pricing-featured` | 真ん中のおすすめのカードを大きく濃い地で、両側に小さなカード |
| `pricing-table` | プランを列にした表（値段の行・含むものの行・ボタンの行） |
| `video-wide` | 動画を幅いっぱい、真ん中に再生の丸（App のセクション。ADR 0040） |
| `video-narrow` | 見出しと、文の幅の動画 |
| `map-wide` | 地図を幅いっぱい、場所の印 |
| `map-split` | 地図が左、見出しと案内の文が右 |
| `html-narrow` | HTML の印（</>）と見出し・文・2 列の箱を文の幅で（カスタムセクション） |
| `html-wide` | HTML の印（</>）と見出し・文・2 列の箱を幅いっぱいに |
| `richtext-narrow` | 整えるツールバーと見出し・太字・リストの文を文の幅で |
| `richtext-wide` | 整えるツールバーと見出し・太字・リストの文を幅いっぱいに |

- 一覧の正本はコアの `app/Core/Section/Wireframe.php`（名前）と `resources/admin/components/sections/wireframes.js`（アイコン）。足すときは両方とこの表に足す。名前は消さない・変えない（書いたテーマが登録できなくなる）
- 合うアイコンが無いときは近いアイコンを選ぶか、書かずに汎用のアイコンにする。足してほしいアイコンはコアへ（テーマの側でアイコンを作らない）

### セクションのプレビュー（編集の画面）

- 「＋ セクションを足す」でアイコンにマウスを重ねく・キーボードで焦点を当てると、真ん中のプレビューの「選んでいるセクションの後ろ」に、その種類とパターンのセクションが**見本の値**（`example`・`example_blocks`）で仮に出る（点線の輪郭）。離れると消える。DB には何も書かない
- 描くのはテーマのいつもの部品（`sections/{種類}/{パターン}.blade.php` か `sections/{種類}.blade.php`）。テーマは何もしなくてよい。写真の欄にはコアの無地の仮の写真（`/storefront/trial-photo.svg`。`ShopPhoto` の `url()`・`srcset()` で出る）が入り、繰り返しのセットの見本が無いセクションには空のセットが 3 つまで入る（写真の並びの形が分かるように）
- 部品が何も描かない（見出しが要るセクションで見本に見出しが無いなど）と、コアが「文や写真を入れるとここに出ます」の箱を出す。部品の無い種類（外枠が読むお知らせバナー・フッター）は「ページの上か下の決まった場所に出ます」の箱
- コアのセクション（`.sugar-section`）のプレビューは、四方に 0.75rem の透明な枠を足して中身を内側へ寄せる（点線の輪郭はセクションの内側に描くので、左右と上下の余白が 0 のセクションでは文にかぶるため）。テーマのセクションには何も足さない。テーマのセクションの中身が端まで来るなら、点線が文にかぶらないようセクションに自分の余白を持たせる
- 仮のセクションは、セクションの属性（`data-section`）・`on…` の属性・スクリプトと埋め込みを外し、ページにすでにある `id` も外してから差し込む。部品の中で `@once` の印（開いた時の動き・ページ内のリンク先）はテスト用のセクションにも付くことがあるが、試しの間だけで、保存した後の描き方は変わらない

### セクションの属性（data-section。必須）

- セクションの部品は、いちばん外の要素に **`data-section="{{ $section->id }}"`** を必ず置く（上の例のとおり）。管理画面のページの編集の画面（ADR 0039 段階 2）で、プレビューのセクションを押して選ぶ・選んだセクションに輪郭を付けるのはこの印で決まる。印の無いセクションは、プレビューで押しても選べない（左の一覧からは選べる）
- 1 つのセクションに印は 1 つだけ。セクションの中に別のセクションの属性を入れ子にしない（押した所からいちばん近い印のセクションを選ぶ）
- 印の値は `$section->id` だけを `{{ }}` で出す（ID は英小文字・数字・`_`・`-` の 40 文字まで）。印のほかに、編集のための属性や JavaScript をテーマに書かない（押して選ぶ動きはコアの部品 `resources/storefront/editor.js` が持つ）
- 外枠が読むセクション（お知らせバナーなど、`$page` に入らないセクション）は、プレビューで押しても選べない（左の一覧から選ぶ）。外枠の中で `$sections->get('…')` のセクションに印を置いてもよい（値は `{{ $announcement->id }}`）

### 下書きのプレビュー（編集の画面の真ん中）

- 管理画面のページの編集の画面は、真ん中の枠（iframe）にショップのホストの `/preview/themes/{テーマ}/{ページ}?expires=…&token=…`（署名つき・30 分）を開く。テーマのいつもの画面（`home`）が、使うバージョンの**下書き**のセクションで描かれる（`$page` と `$sections` のどちらも下書き）。テーマは何もしなくてよい
- この画面では App を差し込まず、`<x-storefront.apps>` がバナーの代わりに編集用の属性（`data-sugar-editor`。見えない）を置く。コアの部品が、リンク・ボタン・フォームを押しても動かないようにし（押すとセクションを選ぶだけ）、「カートに入れる」を押せない形にする
- セクションにマウスを重ねくと細い輪郭、選んだセクションには太い輪郭が付く（コアの CSS が `[data-sugar-selected]` で付ける。色はテーマの `--color-accent`）。輪郭は `outline` なので、セクションの大きさと並びは変わらない
- 検索に載らない・どこにも残らない（noindex・no-store）。枠に入れてよいのは管理画面だけ（`frame-ancestors`）
- 編集用の属性には、セクションのプレビューの断片のパス（`data-sugar-trial`。`/preview/themes/{テーマ}/{ページ}/section?expires=…&token=…`、同じオリジン・同じ期限・用途の違う鍵の署名）も添える。コアの部品が種類とパターンを足して読み、セクション 1 つを見本の値で描いた断片を差し込む（上の「セクションのプレビュー」）

### セクションの決まり（レスポンシブ）

- 並びはパソコンとスマホで同じ（スマホだけ別の並びにする仕組みは無い）。セクションは**どれもレスポンシブ**にする: 画面の幅に合わせて CSS で組み変える（左右 → 縦積み、4 列 → 2 列など）。横にはみ出さない（`overflow-x` を出さない、写真は `max-width: 100%` と `width`・`height` で比を保つ）
- セクションは前後のセクションを前提にしない（どの順に並べても、同じ種類が続いても崩れない）。セクションの間の余白はセクションの内側で持つ
- 公式テーマを並びの形に作り直すとき（ADR 0039 の ④）、スマホの幅で横にはみ出さないことをブラウザのテストで確認する（`tests/e2e/theme-patterns.spec.js`。置ける種類 × パターンをすべてトップと自由なページに並べ、390px で `document.documentElement.scrollWidth <= 390`）
- 横に流れる並び（`overflow-x-auto` の箱）には `relative` を付ける。箱が位置の基準を持たないと、中のカードの読み上げ用の `sr-only`（位置を絶対で置く要素）が箱で切り取られず、ページが横に広がる

### 公式テーマのセクションとパターン: 角砂糖と kinari（ADR 0039 段階 4。fashion と botanica は下の「公式テーマのパターン」）

どのパターンも、最初の 1 つ（`default`）は並びの形にする前のトップと同じ見た目（今の店の見た目を変えない）。部品は `views/sections/{種類}/{パターン}.blade.php`。お知らせバナーとフッターは外枠（`layout`）が `$sections->get(…)` で読むので、`sections/` に部品を置かない（置くとトップの中にも描かれる）。フッターは外枠がパターンで描き分け、セクションの属性（`data-section`）は付けない（外枠はどの画面にも出るので、自由なページのプレビューにトップのセクションの属性が混ざる）。外枠が App のリンクを 1 つだけ置く決まり（`ThemeTest`）なので、フッターの形は 1 つにして class だけを替える。

**角砂糖（`default`）**。セクションの間の余白は外枠の `main` の間隔（トップ `gap-24`・自由なページ `gap-16`）。自由なページの雛形（`pages.page`）はヒーロー・商品の並び・カテゴリ・約束とコアの汎用のセクション 13 個（で 8 種類を足した。トップにもコアの汎用のセクション 13 個すべてを置ける。2026-10-30、運営の判断）、最初は「見出しと文」

| 種類 | パターン | 中身 |
|---|---|---|
| `announcement` お知らせの帯 | — | 外枠の上の黒い帯（1 ページに 1 つ） |
| `hero` ヒーローとショップの説明 | `default` 写真の帯と説明・`full` 全面（写真に文を重ねる）・`split` 左右・`grid` 格子（文の箱と写真 3 枚） | 写真・英字の一言・見出し・文・説明の写真 2 枚・ボタンの文字と行き先（下の「ヒーローのボタン」） |
| `products` 商品の並び（新しい種類。前はトップの画面が決まって描いた新着） | `default` 4 列（スマホ 2 列）・`large` 2 列で大きく（4 点）・`scroll` 横に流れる（パソコンで 5 点） | 英字の一言・見出し・商品（選ばなければトップでは新着 8 点。自由なページでは空） |
| `categories` カテゴリ | `default` カード（帯の地）・`circle` 丸・`bento` 大小のタイル | カテゴリ（無ければ区画を出さない） |
| `promises` 安心の一行 | `default` | お約束 4 つまで |
| `footer` フッター（外枠） | `default` 暗い背景・`columns` 複数の列（帯の地、メニューのページも）・`line` 1 行 | Powered by の出す・出さない（隠した・外したときは暗い背景、Powered by は出さない） |

**kinari（`kinari`）**。ヒーローは画面の幅いっぱい、ほかのセクションは自分で 1360px までの幅の箱に入る。セクションの間の余白はトップの並びの間隔（`gap-22`）。最初のセクションがヒーローでないときは `first:mt-18` で上を空ける。自由なページの雛形はお知らせバナー・フッター以外の種類とコアの汎用のセクション 13 個（で 8 種類を足した。トップにもコアの汎用のセクション 13 個すべてを置ける。2026-10-30、運営の判断）、最初は「見出しと文」。メニュー（スマホの `<details>` とパソコンの案内）と、フッターの「お店について」の列（多列）・案内の行（1 行・呼びかけ）に `$navPages`

| 種類 | パターン | 中身 |
|---|---|---|
| `announcement` お知らせバナー | — | 外枠の上のバナー（1 ページに 1 つ） |
| `hero` ヒーロー | `default` 写真に重ねる・`split` 左右・`grid` 格子（文と写真 3 枚） | 見出し・文・写真・写真 2・写真 3（2 と 3 は格子だけ）・ボタンの文字と行き先（下の「ヒーローのボタン」） |
| `categories` カテゴリ | `default` 四角・`circle` 丸・`bento` 大小のタイル | カテゴリ（飛び先 `#category`） |
| `looks` コーディネート | `default` 帯に文章と写真・`two` 2 列・`three` 3 つ | 見出し・文・写真の組 4 つまで（見出しと写真が無ければ出さない。飛び先 `#looks`） |
| `new_arrivals` 新着（新しい種類） | `default` 左に見出し・4 列・`large` 2 列で大きく・`scroll` 横に流れる | 見出し・文・商品（選ばなければトップでは新着 8 点。飛び先 `#new`） |
| `favorites` よく選ばれている商品 | `default` 帯に 4 列・`large` 2 列で大きく・`scroll` 横に流れる | 商品（無ければ出さない） |
| `seasonal` 季節の特集 | `default` 文章と写真・`overlay` 写真に重ねる・`wrap` 回り込み | 小見出し・見出し・文・大きな一言・注意書き・写真（見出しと写真が無ければ出さない） |
| `promises` 安心の一行 | `default` | お約束 4 つまで |
| `footer` フッター（外枠。新しい種類） | `default` 複数の列・`line` 1 行・`cta` 暗い背景にメッセージ | メッセージ（`cta` だけ） |

- 前と変わった見た目は kinari の 1 か所だけ: よく選ばれている商品と季節の特集は、前は横に 2 つ並んでいたが、セクションは前後を前提にしないので、今はそれぞれ幅いっぱいの箱（よく選ばれている商品が先）
- 今の店の値は、店の DB のマイグレーション（`…_default_and_kinari_themes_to_page_order.php`）で新しい最初の並びの順に並べ直し、無かった種類（角砂糖の商品の並び、kinari の新着・フッター）を空で足す（決まった並びの間は店の人が並べ替えられなかったので、店の選んだ順を壊さない）。下書きと公開に同じコピーをするので「公開していない変更」の印は変わらない
- 同じ種類を 2 つ置くと、飛び先（`#new` など）と `data-motion-intro`（ヒーロー）は 2 つになる（最初のセクションへ飛ぶ・どちらも浮かぶ）
- **ヒーローのボタン**（4 つの公式テーマとも）: ヒーローの欄 `button_label`（`text`、20 文字まで）と `button_link`（`link`。店の中のパスか `https://` の URL だけ。`javascript:`・`data:`・`//` で始まる別のホスト・`http://` は保存で断る）。どのパターンも同じ部品（角砂糖・kinari は `partials/hero-text`、fashion・botanica は `sections/partials/hero-copy`）で `<a data-test="hero-button">` に出す。空なら今までの文字と行き先（角砂糖「商品一覧を見る」・fashion「コレクションを見る」・botanica「植物を探す」は商品一覧、kinari「新着を見る」は同じページの新着 `#new`）。見本（`example`）には今までの文字（と商品一覧の `/products`。kinari は行き先を書かない）を入れた。kinari の「カテゴリから探す」はそのまま（カテゴリのセクションがあるときだけ）
- 見本（`example`）は、カテゴリ（店ごとの ID になるので書けない）と角砂糖のフッター（文の欄が無い）を除くすべての種類に書いた（「季節のおすすめ」「ここにお店の説明を書きます。」など。商品の並びは新着）。写真は空なので、写真が要るセクション（kinari のコーディネート・季節の特集）は、足しただけでは写真を選ぶまで出ない

### 管理画面の API（編集の画面のため）

- `GET /api/tenants/{店}/themes/{テーマ}/pages/home`: `schema`（種類の宣言。`patterns`・`blocks`・`max_blocks`・`limit` と、見本 `example`〔`{ values, items }`。欄はすべてそろえ、空は null〕・`example_blocks` も。見本を書かない種類には出ない。ワイヤーフレームの画像を書いた種類には `wireframes`〔パターン => アイコンの名前〕）・`page`（`name`・`label`・`fixed`・`free`・`types`・`default`・`max_sections`）・`shop_page`（自由なページの `id`・`title`・`slug`・`status`・`url`。トップでは `null`）・`version`・`sections`（下書きの並び。まだ無ければ最初の並びを空の値で）。ページの名前は `home` か自由なページの `page-{ID}`（下の「自由なページ」）
- `PUT …/pages/home/draft`: `{ version_id, revision, sections: [ { id?, type, pattern?, hidden?, values?, items?, blocks? } ] }` で並びをまとめて置き換える（ID の無いセクションは新しく足したセクションとして ID が付く）。宣言に無い種類・パターン・ブロック・欄、上限の超え、ID の重なりはパスつきの 422（`sections.0.pattern` など）。古い改訂は 409
- `POST …/pages/home/publish`: `{ version_id, revision }`。公開はバージョンごと（すべてのページ）
- `POST …/pages/home/preview`: 下書きのプレビューの署名つきのリンク（`{ url, expires_at }`。30 分。閲覧の段階から）。編集の画面の真ん中の枠に開く（上の「下書きのプレビュー」）
- `GET …/themes/{テーマ}/style`・`PUT …/themes/{テーマ}/style/draft`: 見た目（カラーパレット・主な色・フォント・角の丸み）の宣言と下書きの値（下の「見た目」）
- `GET /api/tenants/{店}/themes`（閲覧の段階から）: 入っているテーマのカード（`name`・`label`・`version`〔composer のパッケージのバージョン。パッケージで入っていなければ null〕・`screenshot`〔アイコンの URL か null〕・`content`〔使っている中身のバージョンの `version_id`・`revision`・`published_at`・`unpublished_changes`。まだ無ければ null〕）と、`chosen`（店が選んだテーマ）・`default`・`active`（今描いているテーマ）
- 前の `…/themes/{テーマ}/content`（種類 => 値）は で外した。中身を入れるのはこの並びの口だけ（今の形の保存は、`storefront:import-theme-content` と入れたときのサンプルのために `ThemeContentService::saveDraft` に残っている）

### 公式テーマのパターン（ADR 0039 段階 4）

公式テーマのセクションの種類と、種類ごとの見た目のパターン（部品は `views/sections/{種類}/{パターン}.blade.php`）。最初に書いたパターンが既定で、今までのトップの見た目のまま（作り直す前の店の見た目は変わらない）。どのパターンもスマホの幅（390px）で横にはみ出さない（ブラウザのテスト `theme-patterns.spec.js`）。お知らせバナー（`announcement`）は外枠が読むので、どのテーマもパターンを持たない（1 ページに 1 つ）。

| テーマ | 種類 | パターン（最初が既定） |
|---|---|---|
| fashion | `hero` ヒーロー | `full` 全面・`split` 左右・`grid` 格子（ヒーローの写真と写真のブロック 3 つまでを並べる）。ボタンの文字と行き先は上の「ヒーローのボタン」 |
| fashion | `categories` カテゴリ | `cards` 写真のカード・`circles` 丸・`bento` 大小のタイル |
| fashion | `new_arrivals` 新着の商品 | `grid` 6 列・`large` 2 列で大きく・`scroll` 横に流れる（広い画面で 5 枚） |
| fashion | `looks` 新作（コーディネート） | `grid` 2 列・`trio` 3 つ・`scroll` 横に流れる |
| fashion | `seasonal` 季節の特集 | `split` 左右・`overlap` 回り込み（写真に白い面を重ねる）・`banner` 全面 |
| fashion | `favorites` おすすめの商品 | `grid` 6 列・`large` 2 列で大きく・`scroll` 横に流れる |
| fashion | `promises` 安心の一行 | `row` 1 行・`cards` カード・`dark` 暗い背景 |
| botanica | `hero` ヒーロー | `panel` 写真と色の面・`split` 左右・`grid` 格子（文字の面と写真 2 × 2）。ボタンの文字と行き先は上の「ヒーローのボタン」 |
| botanica | `looks` 右の特集 | `stack` ヒーローの右に縦に並べる・`row` 横に 2 つ |
| botanica | `categories` カテゴリ | `links` 文字・`cards` 写真のカード・`circles` 丸 |
| botanica | `new_arrivals` 新着の商品 | `grid` 4 列・`large` 2 列で大きく・`scroll` 横に流れる |
| botanica | `seasonal` 季節の特集 | `split` 写真が左・`reverse` 写真が右・`stack` 写真が上 |
| botanica | `favorites` おすすめの商品 | `grid` 4 列・`large` 2 列で大きく・`scroll` 横に流れる |
| botanica | `promises` 安心の一行 | `row` 1 行・`dark` 濃い背景 |

- 自由なページの雛形（`pages.page`）: fashion・botanica は、トップのセクションのうち新着の商品とお知らせバナーを除いた種類と、コアの汎用のセクション（`basic_*`）を置ける（トップにもコアの汎用のセクション 13 個すべて。2026-10-30 の運営の判断）。最初の並びは「見出しと文」1 つ（コアの既定と同じ）。新着の商品は店頭がトップにだけ渡す `$listings` を使うので、自由なページには置けない（商品はおすすめの商品かコアの「商品の並び」で）
- botanica の「左の大きな写真と右の特集 2 点」はセクション 2 つ。ヒーロー `panel` のすぐ後に右の特集 `stack` が来たときだけ、CSS（`:has()` と隣の印 `+`）が左右の列に並べる。離して置けばどちらも幅いっぱい（セクションは前後を前提にしない）
- ページを開いた時の見出しの塊（`data-motion-intro`）は 1 ページに 1 か所なので、ヒーローの部品は `@once('motion-intro')` で最初のヒーローにだけ付ける。メニューの「新着」の `#new` も同じく最初の新着のセクションにだけ（`@once('anchor-new')`）
- 自由なページではページのタイトルが `h1` なので、ヒーローの見出しは `h2` に下げる（`$page->title` が null ならトップ）
- 見本の値（`example`）: fashion・botanica は、お知らせバナー・ヒーロー・新着の商品・新作／右の特集・季節の特集・おすすめの商品（新着 `{"source": "new"}`）・安心の一行（3 件）に、見本と分かる短い文を書いた。写真は空。カテゴリは欄がカテゴリの並びだけなので書かない。ヒーローのブロックは写真だけなので `example_blocks` は書かない
- 横に流れる並びは自分の箱の中だけが横に動く（`overflow-x: auto`）。中のカードの `sr-only` などの位置の基準が箱の外にならないよう、箱に `position: relative` を付ける（付けないと、ページ全体が横に伸びる）

## 自由なページ（ADR 0039 段階 3。テーマ API 2 の付け足し）

店の人が「私たちについて」・キャンペーン・お問い合わせの案内などのページを作り、トップと同じくセクションを積んで中身を作る形。ページの URLはショップの **`/pages/{slug}`**（slug は英小文字・数字・ハイフン。ショップの決まった画面の名前〔`products`・`cart`・`checkout`・`orders`・`password`・`preview`・`media`・`apps`・`legal`・`privacy` など〕は使えない）。ページのタイトル・メニューに載せるか・並び順・公開の状態は店ごと（テーマを替えても残る）、中身（セクションの並び）はテーマの値の中にページごと（`page-{ID}`。テーマごと・下書きと公開）。

### 宣言の書き方（任意）

```json
{
    "pages": {
        "home": { "types": ["hero", "rail"], "default": [{ "type": "hero" }] },
        "page": {
            "types": ["hero", "rail", "basic_text"],
            "default": [{ "type": "hero", "pattern": "split" }]
        }
    }
}
```

- `pages.page`: 自由なページの雛形。`types` は自由なページに置ける種類、`default` は新しいページの最初の並び（書き方は `pages.home` と同じ）。店の人が作ったページはどれもこの雛形を使う
- `types` にはテーマのセクションのほかに、**コアの汎用のセクション**（下）の名前も入れられる（コアの部品で描くか、テーマが同じ名前の部品 `views/sections/basic_text/…` で描き直す）
- **`pages.page` を書かないテーマ**（今の書き方のテーマ）は、コアの汎用のセクションだけで自由なページを作る（最初の並びは「見出しと文」1 つ）。テーマは何もしなくても自由なページを出せる
- テーマは `basic_` で始まる名前のセクションを宣言できない（コアの汎用のセクションのために取ってある。登録で止める）

### コアの汎用のセクション

どのテーマでも描けるセクション。部品はコア（`resources/views/storefront/sections/{種類}.blade.php`）、見た目はコアの CSS（`resources/storefront/sections.css`。`$storefrontScript` と一緒に読まれる）がテーマの色の変数（`--color-canvas`・`--color-ink`・`--color-ink-soft`・`--color-line`・`--color-band`・`--color-accent`・`--color-accent-hover`・`--color-on-accent`）で付ける。規則は `:where()` で強さ 0 なので、テーマは `.sugar-section`・`.sugar-text`・`.sugar-image-text`・`.sugar-image`・`.sugar-button`・`.sugar-products`・`.sugar-free-page`（と下のコアの共通のセクションの class）を自分の CSS で上書きできる。どれもレスポンシブ（狭い画面では縦に積む）。

自由なページの雛形（`pages.page`）にも**トップ（`pages.home`）にも**置ける（トップは から。`types` に名前を入れる）。

| 種類 | 名前 | パターン | 欄 |
|---|---|---|---|
| `basic_text` | 見出しと文 | `left` 左寄せ・`center` 中央 | `heading`（文）・`body`（複数行の文） |
| `basic_image_text` | 写真と文 | `image_left` 写真が左・`image_right` 写真が右 | `image`・`heading`・`body`・`button_label`・`button_link` |
| `basic_image` | 写真 | `wide` 横いっぱい・`narrow` 文の幅 | `image`・`caption` |
| `basic_button` | ボタン | `center` 中央・`left` 左寄せ | `label`・`link` |
| `basic_products` | 商品の並び | `default` | `heading`・`products`（8 点まで） |

どれにも見本（`example`）がある（「見出しを入れてください」のような見本と分かる短い文、ボタンは「くわしく見る」と `/products`、商品の並びは新着。写真は空）。

#### コアの共通のセクション

世の中のページによくある形を、コアが持つセクション（ページを考えるのが得意でない人でも、よいページを作れるようにするため。2026-10-30、運営の判断）。足したらすぐ見本の値で形になり、色・字・角の丸みはテーマに合わせて変わる。公式テーマ 4 つはトップと自由なページの両方に置ける。外と接続されるもの（動画・地図・Instagram・メールの登録）は入れない（後で App）。

| 種類 | 名前 | パターン | 欄 | ブロック（上限） |
|---|---|---|---|---|
| `basic_testimonials` | お客さんの声 | `cards` カードを 3 列・`quote` 大きな引用・`scroll` 横に流れる | `heading` | `voice` 声: `quote`（声の文）・`name`（お名前）・`detail`（属性）（12）。星の数は持たない |
| `basic_faq` | よくある質問 | `list` 開閉の 1 列・`side` 見出しが左 | `heading`・`body`・`button_label`・`button_link` | `question` 質問: `question`・`answer`（20）。開閉は `<details>` |
| `basic_features` | 特長 | `three` 3 列・`four` 4 列・`list` 2 列のリスト | `heading` | `feature` 特長: `icon`（アイコン。コアの選択肢）・`title`・`text`（12） |
| `basic_cta` | CTA バナー | `band` 色のバナー・`photo` 写真の上 | `heading`・`body`・`button_label`・`button_link`・`image`（写真の上だけ） | — |
| `basic_gallery` | ギャラリー | `grid` 格子・`masonry` 石垣・`scroll` 横に流れる | `heading` | `photo` 写真: `image`・`caption`（20） |
| `basic_product` | 1 つの商品を大きく | `split` 写真が左・`full` 写真が全面 | `product`（選んだ商品 1 つ。`from: ["pick"]`）・`kicker`・`body` | —（**1 つのページに 1 つまで**） |
| `basic_entries` | リンクカード | `two` 2 つ・`three` 3 つ | `heading` | `entry` 入口: `image`・`title`・`link`・`button_label`（6） |
| `basic_story` | 開発元・物語 | `longform` 写真と長文・`timeline` 年表 | `image`（写真と長文だけ）・`kicker`・`heading`・`body` | `milestone` 年表の 1 行: `when`・`title`・`text`（12。年表だけ） |

- **何も無ければ描かない**: 中身の要る欄が空（声も質問も無い・写真を 1 枚も選んでいない・商品を選んでいない）セクションは、ショップに何も出さない。編集の画面の下書きのプレビュー（`$preview`）では、写真を選ぶまでの無地の枠と「…を入れるとここに出ます」の通知を出す（押して選べるように印も付く）
- **1 つの商品を大きく**: 値の `product` は、選んだ商品が公開中なら `ProductView`（商品の画面と同じ詳細。写真・種類・オプション・入れられる数）、選んでいない・公開をやめた・消した商品なら `null`（`$section['product']`。ほかのセクションの `products` と違い、並びではない）。「カートに入れる」のフォームと種類・オプションは商品の画面と同じコアの部品（`<x-storefront::variant-picker>`・`<x-storefront::addon-fields>`・`form[data-cart-form]`・`[data-stepper]`）。種類の部品はページの `[data-variant-price]`・`img[data-variant-photo]` を読むので、1 つのページに 1 つまで（`limit: 1`）
- **特長のアイコン**: コアが持つ Lucide の 26 個から店の人が選ぶ（`App\Core\Section\SectionIcons`）。物を売る店向けの 16 個（`truck` お届け・`package` 梱包・`gift` 贈り物・`leaf` 自然の素材・`droplets` 水・洗える・`sun` 日なた・`recycle` 繰り返し使える・`heart` 心をこめて・`hand_heart` 手しごと・`shield_check` 安心・`award` 品質・`sparkles` 新しさ・`clock` 時間・`map_pin` 産地・`scissors` 手づくり・`ruler` サイズ）と、サービスを売る店向けの 10 個（`wrench` 設置・作業・`message_circle` 相談・`calendar_days` 予定・予約・`zap` 速さ・`lock` 防御・`headset` サポート・`sliders_horizontal` カスタマイズ・`rocket` 始める・公開・`handshake` 寄り添う・お約束・`clipboard_check` 確認・点検）。テーマも店の人も SVG を持ち込まない。テーマが描き直すときもコアの部品 `<x-storefront::section-icon :name="$block['icon']" class="…" />` で描く（知らない名前・空なら何も描かない。線の色は `currentColor`）
- **動き**: カード・写真・年表の行に `data-motion-reveal`（`rich` のテーマでスクロールで浮かぶ）、リンクカードに `data-motion-card`（写真が少し寄る）。よくある質問は開くと答えが少し上から薄く出る（CSS。動きを減らす設定と `none` では動かない）
- **見本**: お客さんの声は「ここにお客さんの声が入ります」の形（作り話のレビューに見えないよう、本物らしい感想を見本に書かない）、よくある質問は送料・お届け・返品の 3 問と「ここに答えを書いてください。」、特長はアイコン 3 つ、ギャラリーは写真の枠 6 つ、リンクカードは「ギフト・自分用・新作」と `/products`、年表は 3 行。写真と商品は見本に入れない

#### コアの共通のセクションの残り

見本帳の「次に」の 5 種類。8 種類と同じくコアのセクション（`basic_*`）で、公式テーマ 4 つのトップと自由なページに置け、足したらすぐ見本の値で形になる。

| 種類 | 名前 | パターン | 欄 | ブロック（上限） |
|---|---|---|---|---|
| `basic_numbers` | 数字で見せる | `row` 3 つ並び・`photo` 写真と数字 | `heading`・`body`・`image`（写真と数字だけ） | `stat` 数字: `value`（数字。12 字）・`unit`（単位）・`label`（何の数か）（6） |
| `basic_logos` | ロゴのバナー | `row` 1 行・`grid` 格子 | `heading` | `logo` ロゴ: `image`（ショップの写真）・`name`（名前。写真の代わりの文と読み上げ）・`link`（12） |
| `basic_steps` | 手順・流れ | `row` 横に番号・`line` 縦の線 | `heading`・`body` | `step` 手順: `title`・`text`（8）。番号は並びの順にコアが付ける（`<ol>`） |
| `basic_table` | 比べる表 | `table` 表・`photo` 写真とサイズ表 | `heading`・`image`（写真とサイズ表だけ）・`corner`（左上のます）・`col1`〜`col5`（列の見出し）・`note`（表の下の注記） | `row` 行: `label`（行の見出し）・`c1`〜`c5`（20） |
| `basic_spacer` | 余白・区切り | `space` 余白・`line` 線 | `size`（`s`・`m`・`l` の選択肢） | — |

- **比べる表はコアの文の欄だけで作る**（表の欄の種類は足さない）: 列の見出しはセクションの欄、行はブロック。見出しも中身も空の列（`col4` と全部の行の `c4` が空など）と、見出しも中身も空の行は出さない（`App\Storefront\View\SectionTable`）。左上のますか列の見出しがあれば見出しの行（`<thead>`）を出す。狭い画面で幅に収まらなければ、表の箱（`.sugar-table__scroll`。キーボードで焦点を当てて動かせる）の中だけが横に動く
- **ロゴはショップの写真**（JPEG・PNG・WebP。保存先が WebP に描き直すとき透過は残る。SVG は保存先が受け付けない）。写真が無ければ名前を文字で出す。行き先があればリンクにする（店の中のパスか https だけ）。白地のロゴも地になじむよう、コアの CSS は写真を乗算（`mix-blend-mode: multiply`）で重ねる。暗い地のテーマは `.sugar-logos__image` の `mix-blend-mode` を `normal` に上書きする
- **余白・区切り**は中身の欄が無いので、いつも描く（余白は読み上げない、線は `<hr>`）。セクションの上下の余白（`--sugar-section-space`）は持たず、大きさで高さを決める。編集の画面のプレビューでは点線の枠と「余白」の文字を出す（押して選べるように）
- **何も無ければ描かない**: 数字が 1 つも無い・手順の名前が 1 つも無い・行が 1 つも無い・ロゴの写真も名前も無いセクションは、ショップに何も出さない（プレビューでは通知）
- **見本**: 数字は「00」と「ここに数字の意味を書きます」（作り話の実績を見本に書かない）、ロゴは名前だけ 5 つ（「ロゴ 1」…）、手順は 4 つ、表は S・M・L の 3 列と 3 行（中身は「00」）、余白は「ふつう」
- **動き**: 数字・ロゴ・手順の行に `data-motion-reveal`

#### 値段の表

公式の店（設置の代行などのサービスを売る）を作って、料金を「特長」で代用したので足した。コアのセクション（`basic_pricing`）で、公式テーマ 4 つのトップと自由なページに置ける。

| 種類 | 名前 | パターン | 欄 | ブロック（上限） |
|---|---|---|---|---|
| `basic_pricing` | 値段の表 | `cards` カードを横に・`featured` おすすめを大きく・`table` 表で比べる | `heading`・`body`（見出しの下の文）・`note`（値段の下の注記） | `plan` プラン: `name`（40 字）・`price`（値段。30 字）・`price_note`（値段の添え書き。40 字）・`description`（200 字）・`includes`（含むもの。1 行に 1 つ、10 行まで）・`button_label`・`button_link`・`featured`（おすすめのバッジ。選択肢 `recommended` おすすめ・`popular` 人気・`best` いちばん人気）（4） |

- **値段は見せ方の文字**: 「¥30,000〜」「月 ¥980」「お見積もり」のように、店の人が書いたまま出す。注文・支払い・Ledger とは結びつかない（金額を整数で持つ決まりの外。ADR 0039 の作って決めたこと）。売るものはいつもの商品（App「サービスの販売」など）で、ボタンの行き先にその商品のパスを書く
- **おすすめのバッジは 1 つのプランだけ**: 2 つ目に付けると保存で断る（パス `sections.N.blocks.M.values.featured`。`CoreSections::blockRules`）。カードの文字は選択肢の名前で、店の人が書く文字ではない。描くときも最初の 1 つだけを目立たせる（`App\Storefront\View\SectionPricing`）
- **含むもの**は複数行の文で、空でない行を 1 つずつ印つきの箇条（`<ul>`）で出す。空でない行が 10 行を越えると保存で断る（ブロックの中にブロックは置けないため、行で分ける）
- **パターン**: カードは狭い画面で縦に積み、広い画面でプランの数だけ横に（4 つは中くらいの幅で 2 × 2）。おすすめのカードは 2 本線の枠とカード。「おすすめを大きく」はおすすめのカードを `--color-accent` の濃い地で一回り大きく（文字は `--color-on-accent`）。「表で比べる」はプランを列に、値段・説明・含むもの・ボタンを行にした `<table>` で、おすすめの列に `--color-band` の地。狭い画面では表の箱（`.sugar-pricing__scroll`。キーボードで焦点を当てて動かせる）の中だけが横に動く。中身のある行だけを出す
- **何も無ければ描かない**: 名前も値段も無いプランは出さず、1 つも無ければセクションごと出さない（プレビューでは「プランの名前か値段を入れるとここに出ます」の通知）
- **見た目**はテーマの変数で合わせる（`--color-*`・`--sugar-radius`・`--sugar-button-radius`・`--sugar-heading-font`・`--sugar-heading-weight`）。テーマは `views/sections/basic_pricing/{パターン}.blade.php` で描き直せる（カードの文字は `SectionPricing::badge($plan)`、箇条は `SectionPricing::includes($plan)` を使う）
- **見本**: プラン 3 つ（名前「プランの名前」・値段「¥00,000」・2 つ目がおすすめ・ボタン「くわしく見る」と `/products`）。作り話の値段を書かない
- **動き**: カードに `data-motion-reveal`

#### カスタムセクション（ADR 0041）

HTML の分かる店の人が自分で形を作る「自由な HTML」と、HTML を書かずにボタンで整える「見たまま書ける文」（HTML を書ける場所を用意するため。2026-10-30、運営の判断）。どちらも中身は HTML で、**保存するときと描くときの両方**でコアが決まったタグ・属性・class だけに整える（`App\Core\Section\SectionHtml`。symfony/html-sanitizer で読み、表に無いものを外す）。スクリプトは動かせない（動かしたいときは管理画面でなく開発者モードか App。CONCEPT 7.2）。公式テーマ 4 つはトップと自由なページの両方に置ける。

| 種類 | 名前 | パターン | 欄 |
|---|---|---|---|
| `basic_html` | 自由な HTML | `narrow` 文の幅・`wide` 横いっぱい | `html`（欄の種類 `html`。2 万字まで） |
| `basic_richtext` | 見たまま書ける文 | `narrow` 文の幅・`wide` 横いっぱい | `body`（欄の種類 `richtext`。2 万字まで） |

**残すもの**（ほかは外す。外したものは管理画面の欄の下に並ぶ）

| 区分 | 残す |
|---|---|
| 見出し・文 | `h2`〜`h6`・`p`・`br`・`hr`・`div`・`span`・`blockquote`・`pre`・`code`・`q`・`cite`・`abbr`（`title`） |
| 文字の飾り | `strong`・`b`・`em`・`i`・`u`・`s`・`del`・`small`・`mark`・`sub`・`sup` |
| リスト | `ul`・`ol`（`start`）・`li`・`dl`・`dt`・`dd` |
| 表 | `table`・`caption`・`thead`・`tbody`・`tfoot`・`tr`・`th`（`colspan`・`rowspan`・`scope`）・`td`（`colspan`・`rowspan`） |
| 写真 | `img`（`src`・`alt`・`width`・`height`）・`figure`・`figcaption`。`src` は**店の写真の保存先**の `/media/shop/{ID}/large`（か `medium`）だけ。ほかの写真は要素ごと外す |
| リンク | `a`（`href`・`title`）。`href` は店の中のパス（`/` で始まり `//` でない）か `https://` の URL だけ。どのリンクにも `rel="noopener noreferrer"` を付ける |
| 開閉 | `details`・`summary` |
| class | 下の決まった名前だけ（どの要素にも）。ほかの名前は外す |

- **タグだけ外して中の文を残す**: `h1`（ページのタイトルに使う。見出しは `h2` から）・`section`・`article`・`header`・`footer`・`main`・`aside`・`nav`・`address`・`center`・`font` など
- **中身ごと消す**: 上の表に無いものすべて。`script`・`style`・`iframe`・`object`・`embed`・`form` と入力の欄（`input`・`button`・`select`・`textarea`）・`base`・`meta`・`link`・`svg`・`math`・`template`・`noscript`・`video`・`audio` など
- **属性**: 表に無いものは外す。`on…`（スクリプト）・`style`・`id`・`data-…`・`target`・`srcset` も外す。値は文字の参照を戻した後で見る（`&#106;avascript:` も見える）
- **上限**: HTML は 2 万字まで（ショップに出す形で数える。`&#34;` のような文字の参照は 1 字）、しまう形（整えた後の HTML）は 8 万字まで、タグの入れ子は 20 階層まで。越えたら保存で断る（パスつきの 422）。整えると `"` `+` `=` `@` や全角の `＜` `＋` などが `&#34;`・`&#xFF1C;` の 5〜8 字になるので、記号の多い HTML は 2 万字の中でも 8 万字を越えることがある（断る文でそう通知する）。保存・確認の API・描くときは同じ決まりなので、保存できたものは描ける（描くときに何も出さないのは、DB を直に書き換えたときだけ）。しまった形をそのまま保存し直しても断らない
- **写真の確認**: HTML の中の写真（`<img src="/media/shop/{ID}/…">`）も、ほかの写真の欄と同じく保存のときにこの店の保存先にあるかを確認し、無い写真（ほかの店の ID・消した写真）があればその欄のパスで断る（黙って外さない）。HTML の中で使っている写真は、写真の画面で消せない（ほかのセクションで使っている写真と同じ）
- **整え直しても形が変わらない**: 整えた形をもう 1 度整えても同じ。`<pre>` の先頭の改行（HTML の決まりで、開きタグのすぐ後の改行 1 つは読むときに捨てられる）も、書き出すときに 1 つ足して減らさない
- **決まった class**（`SectionHtml::CLASSES`。見た目はコアの CSS がテーマの変数で付ける。名前は消さない・変えない）: `sugar-cols-2`（中を 2 列。狭い画面では 1 列）・`sugar-cols-3`（3 列）・`sugar-align-center`（中央）・`sugar-align-right`（右）・`sugar-btn`（リンクをボタンの形に）・`sugar-btn-outline`（枠だけのボタン）・`sugar-note`（注記の箱）・`sugar-lead`（大きめの文）・`sugar-small`（小さめの文）・`sugar-space-s`・`sugar-space-l`（上下の余白）・`sugar-wide`（写真や表を幅いっぱい）
- **テーマが受け取るもの**: 値はコアが描く前に整え直した `Illuminate\Support\HtmlString|null`（`$section['html']`・`$section['body']`）。`{{ }}` で出すとそのまま HTML になる（ほかの文字列は今までどおりエスケープされる）。テーマが描き直すときもこの値を `{{ }}` で出す。整えていない文字を出す書き方（エスケープしない Blade の書き方）は使わない
- **見た目**: コアの部品が `<section class="sugar-section sugar-custom" data-pattern="…"><div class="sugar-html">…</div></section>` で描き、コアの CSS が `.sugar-html` の中の見出し・リスト・表・引用などの形を付ける（テーマの preflight で消えた形を戻す。表と `pre` は箱の中で横に動き、ページを広げない）。色・角・見出しの字はテーマに合わせる変数（下の表）
- **見本**: 自由な HTML は見出し・文・2 列の箱・ボタンの形のリンク、見たまま書ける文は見出し・文・リスト。どちらも整えても変わらない形（テストで見る）。写真は見本に書けない
- **ショップの CSP は緩めない**（`script-src 'self'`・`style-src 'self'`・Trusted Types）。整えた後の HTML も CSP の下で描く

**テーマに合わせる変数**（コアの CSS が読む。テーマの CSS の層〔`@layer`〕の外の `:root` に書く。書かなければ括弧の中の既定）

| 変数 | 何の | 公式テーマ |
|---|---|---|
| `--sugar-radius` | 写真・カードの角（0.75rem） | 角砂糖 0.75rem、kinari・fashion・botanica 0 |
| `--sugar-button-radius` | ボタンの角（999px） | 角砂糖 0.75rem、ほか 0 |
| `--sugar-heading-font` | 見出しの字（本文と同じ） | kinari・fashion・botanica は `var(--font-mincho)` |
| `--sugar-heading-weight` | 見出しの太さ（600） | kinari・fashion・botanica 500 |
| `--sugar-section-space` | セクションの上下の余白（1.5rem） | 角砂糖・kinari は 0（外枠の並びの間隔が持つ）、fashion・botanica は `clamp(2.5rem, 5vw, 4rem)` |

```css
/* テーマの theme.css の最後（層の外） */
:root {
    --sugar-radius: 0;
    --sugar-button-radius: 0;
    --sugar-heading-font: var(--font-mincho);
}
```

**テーマが自分の見た目で描き直す**: 好きな種類だけ、テーマの `views/sections/{種類}/{パターン}.blade.php`（か `views/sections/{種類}.blade.php`）を置く。コアより先にテーマの部品が選ばれる（`SectionResolver`）。置かなかった種類とパターンはコアの部品のまま。描き直すときの決まりはセクションの決まりと同じ（いちばん外に `data-section="{{ $section->id }}"`、値は `{{ }}` で出す、リンクは `$section['…']` の href をそのまま、レスポンシブ）。

```blade
{{-- plugins/theme-x/resources/views/sections/basic_testimonials/cards.blade.php（札を 3 列だけ描き直す。ほかのパターンはコアのまま） --}}
<section class="x-voices" data-section="{{ $section->id }}">
    <h2>{{ $section['heading'] ?? 'お客さんの声' }}</h2>
    @foreach ($section->blocks as $voice)
        @if ($voice['quote'])
            <blockquote>{{ $voice['quote'] }}<cite>{{ $voice['name'] }}</cite></blockquote>
        @endif
    @endforeach
</section>
```

- 幅の箱をセクションが持たないテーマ（kinari のトップのように、セクションが自分で幅の箱に入る作り）は、外の画面で `basic_` のセクションだけを幅の箱で包む（kinari の `home`・`page` の画面。中身が空なら箱も出さない `hidden has-[*]:block`）

### 描き方

- 画面 **`page`**（任意）: 自由なページ。`$page`（`PageView`）に公開したセクションの並びと、`$page->title`（タイトル）・`$page->url`（`/pages/{slug}`）が入っている。描き方はトップと同じ（`@foreach ($page as $section) @include($section->view, ['section' => $section])`）。部品はテーマの `sections/{種類}/{パターン}`・`sections/{種類}`、どちらも無ければコアの部品（コアの汎用のセクション）
- テーマに `page` の画面が無ければ、コアの既定の画面（`storefront.page`。テーマの外枠の中にタイトルとセクションを描く）で描く
- **`$navPages`**（すべての画面）: メニューに載せるページの並び（`list<PageLink>`。公開していて、店の人が「メニューに載せる」を選んだものだけ、並び順）。`PageLink` は `title`（タイトル。`{{ }}` で出す）・`url`（`/pages/{slug}`）・`current`（今そのページを見ているか。`aria-current="page"` に使う）。準備中の「準備中です」の画面では空
- 公開していない・ゴミ箱のページは描かない（ショップは「見つかりません」）。準備中のショップでは、ほかの画面と同じく関門の内側
- App は差し込む（画面の種類はふつうの画面。お客さんの情報は入らない）

```blade
{{-- layout.blade.php のメニュー --}}
@foreach ($navPages as $link)
    <a href="{{ $link->url }}" @if ($link->current) aria-current="page" @endif>{{ $link->title }}</a>
@endforeach
```

### 下書きのプレビューと管理画面の API

- 下書きのプレビューは `/preview/themes/{テーマ}/page-{ID}`（トップと同じ署名つきのリンク）。公開していないページも、下書きの中身で描く
- `GET|POST /api/tenants/{店}/shop-pages`（一覧と作る）・`PATCH …/shop-pages/{ID}`（`title`・`slug`・`in_nav`・`status`〔`draft`・`published`〕）・`POST …/shop-pages/order`（`ids` をすべて並べたい順に）・`DELETE …/shop-pages/{ID}`（ゴミ箱へ）・`POST …/shop-pages/{ID}/restore`（戻す）。応答はいつも `{ pages, trash, max_pages }`（作ったときは `created` も）。形の違い・予約語・重なりは 422（`slug` など）
- 中身は `…/themes/{テーマ}/pages/page-{ID}`（上の「管理画面の API」）。自由なページの `…/publish` は、中身の公開（バージョンごと）と、そのページを公開の状態にすることを 1 つのトランザクションで行う
- `POST …/shop-pages/{ID}/purge`: ゴミ箱のページを完全に消す（戻せない。すべてのテーマ・バージョンの下書きと公開の値から `page-{ID}` を消し、行も消す。ゴミ箱でないページは 422）

### テーマを替えたとき

- 自由なページの中身のうち、**コアの汎用のセクション**（`basic_*`）で新しいテーマの雛形に置けるものは、新しいテーマの使っているバージョンの**下書き**へコピーする（ID・パターン・値はそのまま。公開は今までどおり、新しいテーマで公開するまで出ない）。コピーするのは前のテーマで中身を保存してあり、新しいテーマにはまだ中身が無いページだけ（ゴミ箱のページも）
- テーマ固有のセクションはコピーできない。前のテーマの値は消さないので、前のテーマに戻せば元の中身に戻る（戻した先には中身があるので再コピーしない）
- 替える前の通知: `GET /api/tenants/{店}/shop-settings/theme/impact?theme={替える先}`（空なら既定のテーマ）が、コピーできないセクションのあるページ（ゴミ箱でないもの）を `{ pages: [ { id, title, slug, dropped, copied } ] }` で返す

## ページの雛形（テーマ API 2 の付け足し）

選ぶとセクションがよい並びで入り、写真と文を差し替えるだけで 1 ページになる形（ページを考えるのが得意でない人でも、よいページを作れるようにするため。2026-10-30、運営の判断）。雛形は**コアが持つ**（`App\Core\Section\PageTemplates`）。テーマは雛形を書かない。セクションに `role` を書いておけば、雛形がテーマのセクションを使う。

| 名前 | 画面の名前 | セクションの並び（役目は〔 〕） |
|---|---|---|
| `shop_top` | トップ（雑貨の店） | 〔ヒーロー〕・〔カテゴリ〕・〔商品の並び〕・開発元と物語（写真と長文）・お客さんの声（カードを 3 列）・〔安心の一行〕 |
| `single_product` | 1 つの商品を売るページ | 1 つの商品を大きく（写真が左）・特長（3 列）・ギャラリー（格子）・お客さんの声（横に流れる）・比べる表（写真とサイズ表）・よくある質問（開閉の 1 列）・CTA バナー（色のバナー） |
| `brand_story` | ブランドの物語 | 〔ヒーロー〕・開発元と物語（写真と長文）・数字で見せる（3 つ並び）・開発元と物語（年表）・ギャラリー（石垣）・CTA バナー（写真の上） |
| `seasonal` | 季節の特集 | 〔ヒーロー〕・見出しと文（中央）・商品の並び・写真と文（写真が左）・商品の並び・CTA バナー（色のバナー） |
| `faq` | よくある質問 | 見出しと文（左寄せ）・よくある質問（見出しが左。5 問）・CTA バナー（色のバナー） |
| `how_to` | ご注文の流れ・使い方 | 見出しと文（左寄せ）・手順と流れ（横に番号。商品を選ぶ → カートに入れる → お支払い → お届け）・特長（2 列のリスト）・よくある質問（開閉の 1 列）・CTA バナー（色のバナー） |
| `service` | サービスと料金 | 〔ヒーロー〕・特長（3 列。設置・相談・サポートのアイコン）・手順と流れ（横に番号。ご相談 → お見積もり → お申し込み → ご利用の開始）・値段の表（おすすめを大きく）・よくある質問（開閉の 1 列。期間・支払い・変更の 3 問）・CTA バナー（色のバナー） |

- **役目の選び方**: 〔役目〕の行は、そのページの `types` の順で、その役目（`role`）を書いたテーマのセクションの最初のもの（パターンは宣言の最初、値はテーマの見本 `example`）。無い・置けないときはコアのセクションで代わりにする（ヒーロー → CTA バナーの「写真の上」、カテゴリ → リンクカードの「3 つ」、商品の並び → コアの商品の並び、安心の一行 → 特長の「3 列」）。代わりも置けなければその行を飛ばす（画面に「このテーマでは置けないので入りません: …」）
- **公式テーマの役目**: 角砂糖は `hero`・`categories`・`products`・`promises`、kinari・fashion・botanica は `hero`・`categories`・`new_arrivals` と `favorites`（商品の並び）・`promises`。自由なページに新着を置けない fashion・botanica は、自由なページでは `favorites` を使う
- **値**: セクションの見本（`example`・`example_blocks`）に、雛形ごとの上書きを重ねる。写真は入れず、文は「ここに〜を書きます」の形。作り話の実績・お客さんの声・数（「創業 30 年」のような数と単位・西暦）を書かない（年表の年は「〇〇〇〇 年」。テストで見る）
- **置けない種類と上限**: そのページに置けない種類・無いパターン・1 つのページに置ける数（`limit`）を越える分は飛ばす。最後はいつもの保存（ページのセクションの並びの確認）を通る。1 つも置けない雛形は断る（422）
- **作り直すとき**は、今の並びのうちページに描く部品の無いセクション（お知らせバナー・フッターのように外枠が `$sections->get` で読むセクション）を残す（最初に描くセクションより前のものは先頭、後ろのものは最後）。残したセクションも `limit` に数える。決まった並びのページ（`pages` を書かないテーマ）は作り直せない
- **API**: `GET …/themes/{テーマ}/pages/{ページ}` の `templates`（雛形ごとに `name`・`label`・`use`・`rows: [{ type, pattern, label, wireframe, from }]`・`skipped`。`from` は `theme` テーマの役目のセクション・`fallback` 役目の代わりのコアのセクション・`core` コアのセクション）、`POST …/pages/{ページ}/template`（`version_id`・`revision`・`template`。下書きに入るだけ。運用の段階から。古い改訂は 409）。自由なページを作るときは `POST …/shop-pages` に `template`（ショップで使っているテーマの下書きに入れる。ページを作るのと同じトランザクション）。`GET …/shop-pages` の `templates` は、ショップで使っているテーマの自由なページでの並び
- 雛形の名前は消さない・変えない（管理画面と API が使う）

## App のセクション（ADR 0040。テーマ API 2 の付け足し）

App（プラグイン）もセクションの種類を足せる（動画・地図など）。書き方は App を作る人向けの [app-sections.md](app-sections.md)。テーマの側で知っておくこと:

- 種類の名前は `app_` で始まる（`app_video`・`app_google_map`）。**テーマは `app_` で始まる名前を宣言できない**（`basic_` と同じく取ってある。登録で止まる）。`pages` の `types` にも書かなくてよい: 店がその App を「使う」にしていれば、コアが決まった並びでないページ（トップと自由なページ）の置ける種類の後ろに足す
- 部品はテーマの `sections/{種類}/{パターン}`・`sections/{種類}` が先、無ければ App の Blade。テーマが描き直すときも、外の枠はコアの部品 `<x-storefront.embed :section="$section" … />` に任せる（テーマが iframe を書かない。外の URL の文字も書けない）
- ショップでは同意を待たずに最初から枠を描く（`loading="lazy"`。2026-10-30。ADR 0040 の 3）。枠の下に「別のタブで開く」のリンク（`sugar-embed__links`）。編集の画面のプレビューでは外へ読み込まず、無地の箱に「ショップではここに出ます」
- 見た目はコアの CSS（`sections.css` の `sugar-app-section`・`sugar-embed`）がテーマの色の変数（`--color-band`・`--color-ink-soft`・`--sugar-radius`）で付ける。テーマは `:where` で強さ 0 の規則を自分の CSS で上書きできる
- App のセクションの部品には `$section->app`（`AppSectionView`。コアが決めた埋め込みの扱い）が入っている。テーマは中を組み立てず、コアの部品に渡すだけ
- 外から埋め込まない App のセクションもある（2026-11-05、App「おすすめのセクション」の `app_recommendations_related`・`app_recommendations_recent`・`app_recommendations_ranking`。ADR 0071）。並べる商品は App の composer が部品に `$recommended`（`list<ListingCard>`。ショップに出ていていま買えるものだけ）で渡す。テーマが `sections/app_recommendations_…` を置いて描き直すときも同じ変数が届く。並べる商品が無ければセクションごと描かない（プレビューでは形と案内の一言）。見た目はコアのセクション「商品の並び」（`sugar-products`。「横に流れる」は `sugar-products__grid--scroll`）
- **店のデータを並べる App のセクション**（ADR 0072。App「お知らせ・ブログ」の `app_blog`「新着のお知らせ」）は枠を持たず、並べるものが `$section->app->content` に入る（App が決めた形。`app_blog` は `NewsSection`: `items`〔`PostCard` の並び。下の「お知らせ・ブログ」〕・`moreUrl`〔一覧のパス〕）。並べるものが無ければショップではセクションを出さない（`content` が null。編集の画面のプレビューでは出す）。テーマが描き直すときもこの形だけを読み、DB には触らない

## テーマが持つ画面と、店頭が渡すデータ

すべての画面に `$theme`（ThemeDefinition）と `$shop`（ShopView）を渡す。

| 画面 | いつ | 渡すデータ |
|---|---|---|
| `layout` | 他の画面が `@extends` する枠 | なし（`@vite($theme->stylesheet)` を head に置き、`<html lang="ja" data-motion="{{ $theme->motion }}">` で動きの強さを出す。下の「動き」）。`<head>` のタイトルと説明はコアの部品 `<x-storefront::seo :seo="$seo">@yield('title', $shop->name)</x-storefront::seo>` を 1 行（下の「検索と共有」） |
| `home` | 店のトップ | `$listings`: `list<ListingCard>`（公開中の商品だけ、新しい順）。セクションはすべての画面に渡す `$sections`（上の「テーマ API 2」）。`$page`: `PageView`（公開したセクションの並び。上の「ページのセクションの並び」） |
| `products` | 商品の一覧（すべて・カテゴリごと） | `$list`: `ProductListView`（下を参照） |
| `product` | 商品の詳細 | `$productDiscounts`: その商品に今使える自動の割引の名前（`list<string>`。コアの部品 `<x-storefront::product-discounts :names="$productDiscounts ?? []" />` を価格の下に置く。下の「自動の割引」）。`$product`: `ProductView`（`card`（`ListingCard`）・`description`（説明のブロックの文をつないだ素の文）・`photos`（`list<ListingPhoto>`、並び順、alt は無ければ商品名）・`breadcrumbs`（主カテゴリまで）・`url`（正規の URL）・`blocks`（説明のブロック）・`specs`（仕様の表）・`options` と `variants`（種類。下の「商品の種類」）・`addons`（オプション。下の「商品のオプション」）、`summary()`（短い説明か説明の先頭）・`hasDescription()`・`hasVariants()`・`hasAddons()`）。説明と仕様の表はコアの部品で描く（下の「商品の説明」）。種類とオプションはコアの部品で選ばせる（下の「商品の種類」「商品のオプション」）。タイトル・説明・canonical・共有の見え方・構造化データは layout のコアの部品が出すので、テーマは書かない（下の「検索と共有」。`@section('head')` は layout の `@yield('head')` にほかの物を足す時だけ） |
| `cart` | カート | `$cart`: `CartView`（`lines`（`list<{card, title, quantity, maxQuantity, lineTotal, lineTotalLabel, purchasable, notice, listingId}>`。`card` は販売を終えた商品では `null`。行の小計は `lineTotalLabel`（`¥7,660`）を出す）・`subtotal`・`count`、`subtotalLabel()`・`discountLabel()`（値引き「−¥500」、無ければ null）・`discountName`（値引きの行の名前。クーポンは「クーポン（CODE）」、自動の割引は店が決めた名前。無ければ null）・`discountHint`（次に届く自動の割引の一言。無ければ null）・`shippingLabel()`（送料。下の「送料の見せ方」）・`shippingNote()`（送料の添え書き。無ければ null）・`freeShippingNote()`・`totalLabel()`・`couponCode`・`couponNotice`（使えない理由）・`taxBreakdown()`（税率ごとの内訳。下の「税率ごとの内訳」）・`points`（ポイント。下の「ポイント」）・`isEmpty()`・`hasNotices()`）。クーポンの欄はコアの部品 `<x-storefront::coupon-form :cart="$cart" />`（下）。数は `route('storefront.cart.quantity', ['listingId' => …])` へ `quantity` を、外すは `route('storefront.cart.remove', …)` へ POST（`@csrf`）。数の変更は「− 数 +」のボタンで 1 つ減らした・増やした数を送る形にすると JS なしで動く |
| `checkout` | 注文の入力 | `$cart`（CartView。`needsShipping()` が偽ならお届け先の欄を出さない。下の「ダウンロード販売・送らない品」）、`$form`: `CheckoutForm`（`value(key)`・`name(key)`・`id(key)`・`error(key)`・`hasErrors()`・`sameAsBuyer()`・`prefectures()`・`giftAvailable()`・`gift()`・`optInOffer()`・`optIn()`・`delivery()`・`orderFields()`。key は `buyer.name` など）。`route('storefront.checkout.submit')` へ POST（`@csrf`）。項目は buyer[email・name・phone・postal・prefecture・city・line1・line2]、same_as_buyer、recipient[…]、贈り物の gift[on・sender・message]（コアの部品が出す。下の「贈り物」）、お知らせのメールの opt_in（コアの部品が出す。下の「お知らせのメールを受け取る」）、お届け日時の delivery[date・slot]（コアの部品が出す。下の「お届け日時」）、注文の項目の fields[<欄の ID>]（コアの部品が出す。下の「注文の項目」） |
| `checkout-confirm` | 注文の最終確認（特商法） | `$order`: `CheckoutSummary`（`cart`・`input`（`buyer`・`recipient`・`gift`（贈り物の差出人とメッセージ。贈り物でなければ null。下の「贈り物」））・`delivery`・`returns`・`orderKey`・`payable`、`paymentMethod()`・`paymentTiming()`・`addressLine()`・`fingerprint()`・`unavailableNotice()`・`deliveryLabel()`（お届け日時のご希望。下の「お届け日時」）・`orderFields`（注文の項目の値。下の「注文の項目」））、`$flash`（`error`: 内容が変わったなど）。`paymentMethod()`・`paymentTiming()` の文は、店が選んだ支払いの手段のうちその注文の額で使えるものからコアが作る（ADR 0058。カードだけなら今までの文、いくつかあれば「クレジットカード・コンビニ払い・PayPay（次の決済代行の画面でお選びいただけます）」、コンビニ払いがあれば払う期限の文。お届け日を選んだ注文はコンビニ払いを出さず、店が使っていれば「お届け日をご指定のご注文は、コンビニ払いをお選びいただけません」を添える〔ADR 0059〕。テーマはそのまま出す）。分量・支払総額・支払いの方法と時期・引き渡しの時期・返品の条件を、確定のボタンの前にまとめて出す。合計の下に税率ごとの内訳 `<x-storefront::tax-breakdown :taxes="$order->cart->taxBreakdown()" />`、軽減税率の商品の名前に「※」（`$line['card']?->reducedTax()`）。確定は `route('storefront.checkout.place')` へ POST（`@csrf`、隠しの `order_key` と `fingerprint`）。確定すると Stripe の支払いの画面へ移る。`payable` が偽（店の支払いの準備ができていない）なら、フォームの代わりに `unavailableNotice()` を出す。銀行振込・代金引換（ADR 0074）を選んだ注文は、`paymentMethod()`・`paymentTiming()` が手段ごとの文になり、代引きは合計の欄の送料・ポイントの下に「代引き手数料」の行（`$order->cart->paymentFeeLabel()`。無ければ null。合計 `totalLabel()` と税率ごとの内訳は手数料を含む）、確定のボタンの下の一言は `placeNote()`（Stripe へ移る・振込先を通知する・お届けの時に払う） |
| `checkout-complete` | 注文を受け付けた画面 | `$receipt`: `OrderReceipt`（`number()`・`totalLabel()`・`paid`（Webhook で確認した支払い）・`returned`（Stripe の画面から戻った印。支払いの証拠にしない）・`cancelled`（取り消した注文）・`cancelledForExpiry`（払われないまま期限が過ぎて取り消した。偽なら運営の取消）・`free`（合計 0 円で支払いが要らずに確定した注文。「お支払いへ進む」を出さない）・`gift`（贈り物の状況。贈り物でなければ null。下の「贈り物」）・`payment`（支払いの状況 `PaymentState`）・`direct()`（銀行振込・代引きで、まだ払われていない。ADR 0074））、`$flash`（`error`: 支払いの画面を用意できなかったなど）。払っていなければ `route('storefront.checkout.pay')` へ POST する「お支払いへ進む」（`direct()` が真なら出さず、`<x-storefront::direct-payment :payment="$receipt->payment" />` で振込先・期限か代引きの案内を出す）。個人情報は渡さない |
| `order-lookup` | ご注文の確認（会員が無い間） | `$orderNumber`（入れ直しのための注文番号）、`$flash`（`error`）。`route('storefront.orders.find')` へ POST（`@csrf`、`order_number`・`email`） |
| `order` | ご注文の状況 | `$order`: `OrderView`（`number()`・`status`・`statusLabel`・`placedAt`・`items`（`title`・`quantity`・`amountLabel`・`reduced`〔軽減税率の対象。名前に「※」を添える〕）・`discounts`（`title`・`amountLabel`。クーポン・定期便の値引きと「ポイントのご利用」。税率ごとに分けた行は同じ名前で 1 つにまとめてある）・`points`（ポイントの付く予定・付いた。下の「ポイント」）・`shippingLabel`・`totalLabel`・`taxes`（税率ごとの内訳 `TaxBreakdown`。合計の下に `<x-storefront::tax-breakdown :taxes="$order->taxes" />`）・`shipments`（`carrier`・`trackingNumber`・`shippedAt`）・`links`（App のリンク。下の「App のご注文のリンク」）・`cancelled()`・`gift`（贈り物の状況。メールで使う。画面への入口は `links` の App のリンク）・`hidePrices`（贈り物の注文。納品書や受け取る人の目に触れうる所で値段を出さない印）・`payment`（支払いの状況。下の「支払いの状況」）・`awaitingPayment()`（コンビニ払いの入金待ち）・`deliveryWish`（お届け日時のご希望の文。選ばなかった注文は null。下の「お届け日時」）・`fields`（注文の項目の値。下の「注文の項目」）・`charges`（代引き手数料などの行。`title`・`amountLabel`。送料の下に描く。ADR 0074））。名前・住所・電話は渡さない |
| `legal` | 特定商取引法に基づく表記 | `$shop->legal` を表示する（空なら「準備中」） |
| `privacy` | プライバシーポリシー（ADR 0094。任意。無ければコアの既定の画面 `storefront.privacy`） | `$privacy`: `PrivacyPolicyView`（`title`〔「プライバシーポリシー」〕・`url`〔`/privacy`〕・`blocks`〔本文。コアの部品 `<x-storefront::description-blocks :blocks="$privacy->blocks" :heading="2" />` で描く〕）。本文の無い店では描かない（ショップは「見つかりません」）。下の「プライバシーポリシー」 |
| `error` | 400・404・500 など | `$status`: HTTP の状態の番号 |
| `page` | 自由なページ（ADR 0039 段階 3。任意。無ければコアの既定の画面 `storefront.page`） | `$page`: `PageView`（タイトル `title`・`url` と、公開したセクションの並び。上の「自由なページ」） |
| `news` | お知らせの一覧（App「お知らせ・ブログ」の `/news`。ADR 0072。任意。無ければ App の既定の画面 `blog::news`） | `$news`: `NewsList`（`items`〔`list<PostCard>`。新しい順の 20 件〕・`categories`〔種類で絞るカード `list<{name, url, current}>`。先頭は「すべて」〕・`category`〔絞っている種類の名前か null〕・`page`・`lastPage`・`total`・`previousUrl`・`nextUrl`〔無ければ null〕・`feedUrl`〔Atom〕、`title()`）。URL はパスで、テーマは組み立てない |
| `news-post` | お知らせの記事（`/news/{slug}` と管理画面からのプレビュー。ADR 0072。任意。無ければ `blog::post`） | `$post`: `PostView`（`title`・`url`・`publishedAt`〔プレビューの下書きでは null〕・`date()`〔2026.11.05〕・`isoDate()`・`category`〔種類の名前〕・`categorySlug`・`excerpt`・`photo`〔一覧の写真の `ShopPhoto` か null〕・`blocks`〔本文。コアの部品 `<x-storefront::description-blocks :blocks="$post->blocks" :heading="2" />` で描く〕・`plainText`・`updatedAt`・`paywall`〔有料の記事の案内。下の「有料の記事」〕）。タイトル・説明・canonical・Article の構造化データは layout のコアの部品が出す |
| `password` | 準備中のショップの「準備中です」とパスワードの欄（任意。無ければコアの既定の画面 `storefront.password`） | `$flash`（`error`: パスワードが違う・回数が多すぎる）。`route('storefront.password.unlock')` へ POST（`@csrf`、`password`）。**外枠（layout）を `@extends` しない 1 枚の画面**にする（メニュー・カート・フッターのリンクは準備中の店の中を見せる・押しても戻ってくるだけ）。`<head>` に `<meta name="robots" content="noindex, nofollow">` と `@vite([$theme->stylesheet, $storefrontScript])`。App は差し込まれない |

- **金額の見せ方**: お客さんに見せる金額は、どの画面でも `¥5,400` の形（半角の「¥」と 3 桁ごとのカンマ。円でない通貨は `1,234 USD`）。「5,400 円」とは書かない。テーマは金額を自分で整えず（`number_format` や「¥」の手書きをしない）、コアが渡す `…Label`（`priceLabel()`・`lineTotalLabel`・`subtotalLabel()`・`totalLabel()`・`amountLabel` など）をそのまま出す。コアの側の形は `App\Core\Money\Money::format`（PHP）と `resources/storefront/money.js`（JavaScript）の 1 か所ずつで、メールと管理画面も同じ形（`PriceFormatTest` が監視する）
- `ShopView`: `name`（店の名前）、`legal`（`list<{label, value}>`。店の設定の「特定商取引法に基づく表記」の並び順。value は改行を含む）、`privacyUrl`（プライバシーポリシーのページ `/privacy`。本文の無い店では null。ADR 0094）。見せ方（お知らせバナー・ヒーロー・安心の一行など）は持たない（セクションで宣言する）
- `ListingCard`: `id`、`title`、`price`（最小通貨単位、税込み）、`currency`、`availability`（`in_stock` / `low_stock` / `sold_out`）、`remaining`（`low_stock` のときだけ残りの数）、`priceLabel()`（`¥1,500` の形。種類で価格が違えば一番安いものから `¥4,800〜`）、`priceVaries`（種類で価格が違うか。ADR 0036）、`photo`（1 枚目の写真の `ListingPhoto`。無ければ `null`）、`summary`（一覧に出す短い説明。1 行・200 字まで。無ければ `null`。ADR 0032）、`taxRateBp`（消費税の率。basis point、10% = 1000。商品の区分からコアが決める。ADR 0063）・`reducedTax()`（軽減税率 8% の対象か。「※ 軽減税率の対象」と添えるとき）
- `ListingPhoto`: `alt`（代わりの文字。登録が無ければ商品名）、`width`・`height`（大きい方の縦横。`<img>` に入れて読み込み中のずれを防ぐ）、`url('medium' | 'large')`、`srcset()`。写真は店頭のオリジンから配る（`/media/listings/…`）ので CSP の `img-src 'self'` のままでよい。`loading="lazy"` を付ける
- `ProductListView`: `items`（`list<ListingCard>`、24 点ずつ）、`total`、`page`、`lastPage`、`query`（検索語か `null`）、`category`（`{name, slug}` か `null`）、`breadcrumbs`・`children`（`list<{name, url}>`）、`title()`、`sorts()`（`list<{value, label, url, current}>`）、`filters()`（絞り込み。下）、`url(ページ)`・`previousUrl()`・`nextUrl()`・`pages()`（番号、間は `null`）。URL は店頭が作るので、テーマは組み立てない。検索の欄は `GET` で `route('storefront.products')` へ `q` を送る
- **一覧の絞り込みと「おすすめ順」**（App「並び順と絞り込み」を使う店だけ）: `filters()` は `list<{key, label, options}>`（まとまりは価格・タグ・在庫で、`options` は `list<{value, label, url, current}>`。先頭は「すべて」、同じものを選び直すと外れる URL）。出さない店・App を使っていない店では空の配列なので、テーマは `@if ($list->filters() !== [])` で囲んでリンクを並べるだけでよい（公式テーマ 4 つは並べ替えの下に 1 行ずつ）。`sorts()` は、お店が並びを決めた店では「おすすめ順」（`value` は `shop`）を含む。どちらも選択肢と URL は店頭が作るので、テーマは引数を組み立てない
- 経路の名前に `storefront.products`（すべての商品）・`storefront.category`（`slug` を渡す）・`storefront.product`（`listingId`。`ListingCard::url()` でも得られる）を足した。画面を足したので、テーマは `products` を持つ（今のテーマはすべて持っている）
- すべての画面に `$navPages`（メニューに載せる自由なページ。上の「自由なページ」）を渡す
- すべての画面に `$account`（ヘッダの会員の入口。`AccountLink`。下の「会員の入口と会員の画面」）を渡す
- すべての画面に `$cartCount`（ヘッダのカートの数）と `$flash`（`message`・`error`。カートの操作の結果）と `$storefrontScript`（店頭のコアの部品。layout で `@vite([$theme->stylesheet, $storefrontScript])`）を渡す。
- **App の差し込み口（ADR 0034）**: すべての画面に `$apps`（`AppsView`）を渡す。テーマは中を組み立てず、コアの部品に渡すだけ。layout の `</body>` の前に `<x-storefront.apps :apps="$apps" />`（同意バナーと App の `<script src defer>`。バナーは店の設定で「出す」にした店だけ）、フッターの案内に `<x-storefront.app-links :apps="$apps" class="…" />`（「外部送信について」「Cookie の設定」のリンク。要るときだけ出る。「Cookie の設定」はバナーを出す店だけ。`class` などの属性は各リンクに付くので、テーマのフッターのリンクと同じ class を渡す）を 1 行ずつ置く（`ThemeTest` が監視する）。バナーとコアの画面（`/legal/external` 外部送信に関する公表・`/consent` Cookie の設定。テーマの layout の中にコアが描く）の見た目はコアの CSS（`resources/storefront/apps.css`、`$storefrontScript` と一緒に読まれる）が `--color-canvas`・`--color-ink`・`--color-ink-soft`・`--color-line`・`--color-band`・`--color-accent`・`--color-accent-hover`・`--color-on-accent` で付ける。テーマは `[data-sugar-consent]`・`.sugar-consent__*`・`.sugar-page__*` を CSS で上書きできる（コアの規則は `:where()` で強さ 0）
  - 隔離した複製環境（本番のバックアップを確認のために立てたもの。ADR 0082）では、コアが App のスクリプトを差し込まない（`$apps` のスクリプトの並びが空になる。コピーを開いた人のブラウザから本番の解析・広告の計測へ送らない）。テーマは何もしなくてよい
- コアの部品（`resources/storefront/cart.js`。他社のテーマは JS を持てない。公式テーマとサーバーに入れたテーマは別のファイルで持てる。ADR 0047）が見る印: `form[data-cart-form]`（詳細の「カートに入れる」。ページを移らずに入れる。`data-cart-page` は横のカートが無いときの移り先）、`[data-stepper]`（中に `input[type=number]` と `[data-step="-1"]`・`[data-step="1"]` のボタン）、`[data-cart-count]`（数の印。0 なら `hidden` の class を付ける）、`[data-cart-link]`、`dialog[data-cart-drawer]`（横から出るカート。中に `[data-cart-drawer-lines]`・`template[data-cart-line-template]`（`[data-field=title|link|photo|quantity|total|notice]`）・`[data-cart-drawer-message]`・`[data-cart-drawer-subtotal]`・`[data-cart-drawer-close]`。空のときは `data-empty` が付く）、注文の入力の `[data-postal-group]`（中に `[data-postal-code]`・`[data-postal-prefecture]`（select）・`[data-postal-city]`・`[data-postal-line1]`・`[data-postal-status]`）と `[data-same-as-buyer]`・`[data-recipient-fields]`、カートのページの `[data-cart-page]`・`[data-cart-line=商品の ID]`・`form[data-cart-line-form]`・`[data-step-button]`・`[data-field=quantity|total|notice]`・`[data-cart-subtotal]`・`[data-cart-total-count]`・`[data-cart-page-message]`。印が無いところでは、フォームのまま動く
- 行の通知と「注文できない」の見せ方: カートのページの行には、通知が無くても `[data-field=notice]` を置いて `hidden` にし、注文できない行（`purchasable` が偽）の `[data-cart-line]` には `data-unavailable` を付ける。数を変えたとき、コアの部品がサーバーの回答（行ごとの `notice`・`purchasable`）でこの 2 つを書き換える（横のカートの行にも `data-unavailable` を付ける）。打ち消しや色は、テーマが印から CSS で決める（例: 行に `group`、合計に `group-data-[unavailable]:line-through`。`purchasable` で class を出し分けると、書き換えた後に古いまま残る）
- 在庫が減った・0 円になった（1 点まで）行は、上限を超えていても「−」（減らす）を受け付ける。減らしてもまだ超えていれば通知が残る。増やすのは上限で断る
- 詳細の `ProductView` に `maxQuantity`（在庫と 10 の小さい方）。カートに入れるのは `route('storefront.cart.add')` へ `listing_id` と `quantity` を POST（`@csrf`）
- 在庫の数そのものは渡さない（`low_stock` の時の残りの数だけ）
- **ショップへの送信はフォームの形だけ**: テーマの画面や公式テーマのスクリプトがショップへ送る本文は、`<form>` の POST か `fetch` の `FormData`（`application/x-www-form-urlencoded`・`multipart/form-data`）。Content-Type が JSON の本文は、ショップは読まずに 415 で断る（コアの `cart.js`・`toggles.js` もフォームの形で送り、応答だけを `Accept: application/json` で JSON にしてもらう）。本文はショップのホストでは 1 MB まで（受付の nginx）

## 商品の説明（ADR 0032。テーマ API 2 の付け足し）

商品の説明は、決まった種類のブロックの並び（段落・見出し・箇条書き・写真・表・注意書き）と、仕様の表（項目と値の組）。中身は店の人が書いた素の文字で、HTML ではない。今のテーマはそのまま動く（付け足しなので、バージョンは 2 のまま）。

- 描くのは**コアの Blade の部品**。属性（`class` など）は外枠に付く

  ```blade
  @if ($product->hasDescription())
      <x-storefront::description-blocks :blocks="$product->blocks" class="text-sm leading-loose text-ink-muted" />
  @endif
  @if ($product->specs !== [])
      <x-storefront::product-specs :specs="$product->specs" class="text-sm" />
  @endif
  ```

- 部品が出す要素と印（見た目はテーマの CSS で、印から決める。部品は class を持たないので、テーマの Tailwind のビルドに頼らない）

  | ブロック | 要素 | 印 |
  |---|---|---|
  | 外枠 | `div` | `data-blocks` |
  | 段落 | `p`（改行を含む。`white-space: pre-line` で出す） | `data-block="paragraph"` |
  | 見出し | 大は `h3`、小は `h4`（`:heading="2"` で 1 つ上げられる） | `data-block="heading"`・`data-level="2\|3"` |
  | 箇条書き | `ul` / `ol` と `li` | `data-block="list"`・`data-style="bullet\|number"` |
  | 写真 | `figure` に `img`（`loading="lazy"`、代わりの文字はショップの写真の説明）と `figcaption` | `data-block="image"` |
  | 表 | 横に動かせる `div`（`role="region"`・`tabindex="0"`）の中に `table`・`caption`・`thead`（`th scope="col"`）・`tbody` | `data-block="table"` |
  | 注意書き | `aside`（`aria-label` は「お知らせ」か「ご注意」）の中に `p` | `data-block="note"`・`data-tone="info\|warning"` |
  | 仕様の表 | `dl` の中に行ごとの `div`（`dt`・`dd`。`dd` は改行を含む） | `data-specs` |

- テーマが自分で描くときは `$product->blocks`（`list<DescriptionBlock>`: `type`・`text`・`level`・`headingTag()`・`ordered`・`items`・`photo`（`ShopPhoto`）・`caption`・`header`・`headRow()`・`bodyRows()`・`tone`）を読む。そのときもエスケープしない出力は使えない（下の「してはいけないこと」）。写真のブロックは、写真がショップの写真に無ければ渡さない
- 公式テーマの商品の詳細は、この部品を使う（`ThemeTest` が監視する）

## 商品の種類（ADR 0036。テーマ API 2 の付け足し）

色・サイズのような種類のある商品は、詳細で種類を選んでカートに入れる。今のテーマはそのまま動く（付け足しなので、バージョンは 2 のまま）。種類の無い商品は今までどおり（`listing_id` だけ送れば、ただ 1 つの種類が入る）。

- 選ばせるのは**コアの Blade の部品**。「カートに入れる」のフォームの中に 1 行置く。種類の無い商品では何も出さない。属性（`class` など）は外枠に付く

  ```blade
  <form method="post" action="{{ route('storefront.cart.add') }}" data-cart-form …>
      @csrf
      <input type="hidden" name="listing_id" value="{{ $product->card->id }}">
      <x-storefront::variant-picker :product="$product" class="…" />
      …数と「カートに入れる」…
  </form>
  ```

- **JavaScript が無くても選べる**: 部品は販売中の種類を並べた `select`（`name="variant_id"`）を出す。はじめは売り切れでない最初の種類を選んでおく。売り切れの種類は「（売り切れ）」と書いて選べなくする。種類で価格が違えば、各行に価格も出す。フォームはそのまま `route('storefront.cart.add')` へ `listing_id`・`variant_id`・`quantity` を送る。別の商品の種類・止めた種類・選ばなかったとき（種類のある商品）は、店頭が断る
- **JavaScript があれば**、コアの部品（`resources/storefront/variants.js`。`$storefrontScript` と一緒に読まれる。テーマが自分で書かなくてよい）が `select` を隠し、軸ごとのボタンに作り替える（外枠に `data-variant-style="select"` を付ければ、軸ごとの `select`）。選んだ種類に合わせて、ページの次の印を書き換える。印が無ければ何もしない
  - `[data-variant-price]`: 価格の文字（`¥4,800`）。「税込」などは印の外に置く（中は文字ごと入れ替わる）
  - `[data-variant-availability]`: 在庫の通知（「在庫あり」「残り 2 点」「売り切れ」）。売り切れなら `data-sold-out` が付く（色は `data-[sold-out]:text-sold` のように印から）
  - `img[data-variant-photo]`: 種類に写真があればその写真に（`src`・`srcset`・`alt`・`width`・`height`）、無ければ元の写真に戻す。ふつうは 1 枚目の写真に付ける
  - フォームの `input[name=quantity]` の上限（種類の在庫と 10 の小さい方）と、`[type=submit]`（売り切れなら押せなくして「売り切れ」）
- 部品が出す要素と印（見た目はテーマの CSS で、印から決める。部品は class を持たない）

  | もの | 要素 | 印 |
  |---|---|---|
  | 外枠 | `div`（種類のデータは JSON で `data-variants`） | `data-variant-picker`（`data-variant-style="select"` で軸ごとの select） |
  | JS が無いときの選び方 | `div` の中に `label` と `select name="variant_id"` | `data-variant-fallback`・`data-variant-label`・`data-variant-select` |
  | 軸ごとの選び方（JS） | `div` の中に軸ごとの `fieldset`。`legend` に軸の名前と選んだ値 | `data-variant-axes`・`data-variant-axis`・`data-variant-axis-name`・`data-variant-axis-value` |
  | 値のボタン（JS） | `div` の中に `button type="button"`（選んだものは `aria-pressed="true"`） | `data-variant-values`・`data-variant-value="値の ID"`。今の選び方で無い組み合わせは `data-unavailable`、売り切れは `data-sold-out` |
  | 軸の select（JS） | `select`（無い・売り切れの値は「（なし）」「（売り切れ）」と書く） | `data-variant-axis-select` |

- テーマが自分で描くときは `$product->options`（`list<{name, values: list<{id, label}>}>`。軸の順、値は販売中の種類が使うものだけ）と `$product->variants`（`list<ProductVariant>`: `id`・`valueIds`（軸の順の値の ID）・`label`（「墨 / M」）・`price`・`priceLabel()`・`availability`・`remaining`（`low_stock` のときだけ）・`availabilityLabel()`・`soldOut()`・`maxQuantity`・`photo`（`ListingPhoto` か null））、`selectedVariant()`・`pricesVary()` を読む。在庫の数そのものは渡さない。そのときも `select name="variant_id"` を置いて、JS なしで選べるようにする
- 詳細の `maxQuantity` は、種類のある商品ではいちばん多く入れられる種類に合わせる（どれも売り切れなら 0。テーマは「売り切れ」を出す）
- カートの行の名前（`title`）には種類の名前が付く（「エプロン（墨 / M）」）。カートの画面は変えなくてよい
- 公式テーマの商品の詳細は、この部品を使う

## 商品のオプション（ADR 0036。テーマ API 2 の付け足し）

名入れ・ギフト包装・保証の延長のような、在庫の無い追加を、詳細で選んでカートに入れる。今のテーマはそのまま動く（付け足しなので、バージョンは 2 のまま。部品を置かないテーマでは、必須のオプションのある商品だけがカートに入れられない）。

- 選ばせるのは**コアの Blade の部品**。「カートに入れる」のフォームの中に、種類の部品の後へ 1 行置く。オプションの無い商品では何も出さない。属性（`class` など）は外枠に付く

  ```blade
  <x-storefront::variant-picker :product="$product" class="…" />
  <x-storefront::addon-fields :product="$product" class="…" />
  ```

- 欄の名前は `addons[オプションの id]`。形ごとに、部品が次の欄を出す（**JavaScript が無くても**そのまま送れる）
  - **選ぶ**: `select`。値は `label:選択肢の名前`（番号は送らない。店が選択肢を並べ替えても、見ていたものと違う選択肢にならないように）。額があれば「あり（+¥330）」。先頭はいつも空の行（値は空）で、必須なら「選んでください」と `required`（選ばずに入れようとするとブラウザが止める）、必須でなければ「選ばない」
  - **入れる文字**: `input type="text"`（`maxlength` は最大の文字数、必須なら `required`）と、案内（「10 文字まで・+¥500」）
  - **付ける**: チェックボックス（値は `1`）。付けないときは何も送らない（隠した欄で `0` を送らない。店頭は `1` のほかを断る）。付けるは必須にしない（管理画面もコアも断る）
- 額はいつもサーバーが今の定義と照らして決め直す（画面の額は案内だけ）。必須の抜け・長すぎる文字・改行や見えない書式の文字・別の商品のオプション・無い選択肢は、店頭が断って詳細に戻す（`$flash['error']`）
- 断られて詳細に戻ったときは、入れていたオプションの値（名入れの文字など）を部品が欄に戻す（次の 1 回の画面だけ。セッションの中では暗号化して置く）。テーマが自分で描くときは `$product->addonValue(オプションの id)` を欄の初めの値にする
- **JavaScript があれば**、コアの部品（`resources/storefront/addons.js`。`$storefrontScript` と一緒に読まれる）が、選んだ種類の価格にオプションの額を足して数（`name="quantity"`）を掛けた合計を、ページの `[data-addon-total]` に「2 点で ¥5,400」の形で出す（部品の中の `[data-addon-summary]` を見せる。カートの行の小計と同じ計算）。種類を選び直したとき・数を変えたときも合わせる
- 部品が出す要素と印（見た目はテーマの CSS で、印から決める。部品は class を持たない）

  | もの | 要素 | 印 |
  |---|---|---|
  | 外枠 | `div`（合計のためのデータは JSON で `data-addons`） | `data-addon-fields` |
  | オプション 1 つ | `div` | `data-addon-field`・`data-addon-kind`（`choice`・`text`・`toggle`） |
  | 名前と必須の印 | 選ぶ・入れる文字は `label`、付けるは `label` の中の `span`（付けるに必須の印は無い） | `data-addon-label`・`data-addon-required`（「必須」） |
  | 欄 | `select`・`input type="text"`・`input type="checkbox"`（付けるは `label` の中） | `data-addon-input`。付けるの `label` は `data-addon-toggle` |
  | 入れる文字の案内 | `p`（欄の `aria-describedby`） | `data-addon-hint` |
  | 合計（JS） | `p`（はじめは `hidden`）の中の `span` | `data-addon-summary`・`data-addon-total` |

- テーマが自分で描くときは `$product->addons`（`list<ProductAddon>`: `id`・`kind`（`choice` / `text` / `toggle`）・`name`・`required`・`amount`（入れる文字・付けるの額）・`choices`（`list<{label, amount}>`）・`maxLength`（入れる文字だけ）、`fieldName()`・`amountLabel()`・`amountFor(欄の値)`・`ProductAddon::choiceValue(名前)`）と `$product->addonValue(id)`・`addonTotalLabel()` を読む。そのときも上の欄の名前と値の形にする
- カートの行の名前（`title`）にはオプションが付く（「マグ／ギフト包装：あり、名入れ：入力あり」）。**入れた文字そのものは出さない**（個人情報になりうる。見せるのは管理画面の注文の詳細だけ）。行の小計はオプションの額を含む。カートの画面は変えなくてよい
- 同じ種類でもオプションが違えば、カートの別の行になる
- 公式テーマの商品の詳細は、この部品を使う

経路の名前（テーマのリンクに使う）: `storefront.home`（トップ）、`storefront.legal`（特商法の表記）、`storefront.privacy`（プライバシーポリシー。リンクは `$shop->privacyUrl` があるときだけ置く）。外部送信に関する公表は `storefront.disclosure`、同意は `storefront.consent`（どちらも `<x-storefront.app-links>` が出すので、テーマが直に書かなくてよい）。注文: `storefront.checkout.place`（確定）・`storefront.checkout.pay`（お支払いへ進む）・`storefront.orders.lookup`（ご注文の確認。フッターに置く）。

既定のテーマはライトだけ（`color-scheme: light`）。店の見た目は店が決めるもので、見る人の OS の設定で色を入れ替えない。ダークの店にしたいときは、ダークの色のテーマを作る。

## 見た目: カラーパレット・主な色・フォント・角の丸み（ADR 0097。テーマ API 2 の付け足し）

店の人は、テーマが用意した**カラーパレット**から選び、**主な色**（ボタン・リンクの色）だけを自由に決め、**フォント**と**角の丸み**を選ぶ。CSS の値は店の人が持たず、選んだ名前と主な色の 1 色だけを持つ。宣言しないテーマは今までどおり何も選べない（付け足しなので版は 2 のまま）。

### 宣言する（`theme.json` の `style`。任意）

```json
"style": {
    "tokens": ["sage-band"],
    "palettes": {
        "mono":   { "label": "白と黒", "colors": { "canvas": "#ffffff", "band": "#f6f6f7", "ink": "#18181b", "accent": "#18181b" } },
        "indigo": { "label": "藍",     "colors": { "canvas": "#ffffff", "band": "#f3f5fb", "ink": "#0e1330", "accent": "#3451b2", "sage-band": "#e8ebf5" } }
    },
    "accent": true,
    "fonts":  { "gothic": { "label": "ゴシック" }, "mincho": { "label": "明朝" } },
    "radius": { "soft": { "label": "少し丸く" }, "square": { "label": "四角" } }
}
```

| キー | 書き方 | 上限 |
|---|---|---|
| `palettes` | 組の名前 => `{ label, colors }`。**最初のパレットが既定**で、テーマの CSS の `@theme` の色と同じ値にする（何も選ばない店の見た目を変えない）。`colors` は色の名前 => `#rrggbb`（大文字は小文字にそろえる）。色の名前はコアの名前（`canvas`・`band`・`line`・`ink`・`ink-muted`・`ink-soft`・`accent`）か `tokens` で足した名前だけ。どのパレットも `canvas`・`ink`・`accent` を必ず持つ | 2〜6 つ |
| `accent` | `true` なら店の人が主な色を自由に決められる（書かなければ `false`） | — |
| `fonts` | フォントの名前 => `{ label }`。最初が既定。中身（書体の並び）はテーマの CSS が持つ | 1〜4 つ（書かなくてよい） |
| `radius` | 角の丸みの名前 => `{ label }`。最初が既定。中身（rem の値）はテーマの CSS が持つ | 1〜3 つ（書かなくてよい） |
| `tokens` | パレットで変えてよい色の名前を足す（テーマの `@theme` の `--color-<名前>`。例 kinari の `sage-band`）。コアの名前と `on-accent`・`accent-hover` は書けない | 8 つ |

- 名前（カラーパレット・フォント・角・足す色）は英小文字で始まる英小文字・数字・`-` の 32 文字まで、表示の名前は 1〜20 文字の 1 行。形の違う宣言（`#fff`・`red`・知らない色の名前・知らないキー・数の超え）は登録で止まる（セクションの宣言と同じ扱い）
- `on-accent`（ボタンの文字の色）と `accent-hover`（押したときの色）はコアが主な色から計算する（白か黒の読みやすい方・OKLCH の明るさを 0.08 動かす）。パレットにも書けない

### 受ける

外枠（`layout`）と「準備中です」（`password`）に 2 か所書く:

```blade
<html lang="ja" data-motion="{{ $theme->motion }}" data-sugar-font="{{ $themeStyle->font ?? '' }}" data-sugar-radius="{{ $themeStyle->radius ?? '' }}">
<head>
    …
    @vite([$theme->stylesheet, $storefrontScript])
    <x-storefront::theme-style />
```

- **色**: コアの部品 `<x-storefront::theme-style />` が、公開した値の小さな CSS（`/theme/style-{印}.css`。`:root{--color-…:#rrggbb;…}` だけ）を `<link>` で読む。テーマの CSS の**後**に置く（層の外の `:root` なので、`@theme` の値に勝つ）。最初のパレットで主な色もパレットのままなら何も出さない。置かないテーマは色が変わらないだけで壊れない。インラインの style は使わない（ショップの CSP は `style-src 'self'` のまま）
- **字と角**: `$themeStyle->font`・`->radius` に選んだ名前（宣言しないテーマは空）。テーマの CSS の**層の外**に、既定でない名前ごとに書く:

```css
:root[data-sugar-font="mincho"] {
    --font-sans: "Hiragino Mincho ProN", "Yu Mincho", "YuMincho", serif;
}
:root[data-sugar-radius="square"] {
    --radius-xl: 0;
    --sugar-radius: 0;
    --sugar-button-radius: 0;
}
```

- 書体はテーマが同梱するもの（自分のオリジン）か端末の書体だけ。外の書体の配り先は使わない（CSP と外部送信の公表を増やさない）
- 色はテーマの CSS で `var(--color-accent)` などの変数（Tailwind の `bg-accent`・`text-on-accent` など）で使う。値を直に書いた所はパレットで変わらない
- テーマの更新でカラーパレット・フォント・角が宣言から消えたら、その店は描くときに既定へ戻る（店の値は消さない。戻せば元に戻る）

### 公式テーマのセット

| テーマ | カラーパレット（最初が既定） | フォント | 角の丸み |
|---|---|---|---|
| 角砂糖 | 白と黒・藍（公式サイトの藍 `#3451b2`）・桜・抹茶 | ゴシック・明朝 | 少し丸く・四角・丸く |
| kinari | 生成り・墨と白・藍染め | 見出しを明朝・すべてゴシック・すべて明朝 | 四角・少し丸く |
| fashion | モノトーン・グレージュ・ネイビー | ゴシック・見出しを明朝 | 四角・少し丸く |
| botanica | セージ・テラコッタ・深い森 | 見出しを明朝・すべてゴシック | 四角・少し丸く |

どのパレットも、文字（`ink`・`ink-muted`・`ink-soft`）が地の上で 4.5 : 1 以上・主な色が地の上で 3 : 1 以上（`ThemeStyleTest` が監視する）。

### 店の値と下書き・公開

- 値はセクションの並びと同じバージョンの下書き・公開（`theme_versions.draft.style` / `published.style` = `{ palette, accent, font, radius }`）に入る。下書き・公開・バージョンの切り替えはセクションと同じ（公開はバージョンごと）
- 管理画面の API: `GET /api/tenants/{店}/themes/{テーマ}/style`（`style` 宣言〔宣言しないテーマは null〕・`values` 下書きの値・`warnings` 読みにくいかもしれない組み合わせ・`version`。閲覧の段階から）、`PUT …/style/draft`（`{ version_id, revision, style: { palette, accent, font, radius } }`。宣言に無い名前・`#rrggbb` でない主な色・主な色を許さないテーマの主な色はパスつきの 422〔`style.accent` など〕で何も保存しない。古い改訂は 409。運用の段階から）
- 通知（止めない）: 主な色と地のコントラストが 3 : 1 に満たない（`accent_on_canvas`）・主な色の上の文字が 4.5 : 1 に満たない（`text_on_accent`。白か黒の高い方を選ぶので、ふつうは出ない）
- 下書きのプレビューは、署名つきの `/preview/themes/{テーマ}/style.css?expires=…&token=…`（店・テーマ・期限、用途の違う鍵）で下書きの値の CSS を読む。編集の画面が見た目を保存するたびに、コアの部品（`editor.js`）がこの CSS だけを読み直し、`<html>` の字と角の名前を付け直す（ページは描き直さない）

## 動き（テーマ API 2 の付け足し）

共通の決まり（何を・どのくらい動かすか）は [docs/design/motion.md](motion.md)。ここはテーマを作る人が書くことだけ。

動きは**コアの部品**が付ける（CSS は `resources/storefront/motion.css`、JS は `resources/storefront/motion.js`。どちらも `$storefrontScript` と一緒に読まれる）。テーマは JS を書かず、強さの宣言・`data-` の印・CSS の変数で選ぶ。今のテーマはそのまま動く（付け足しなので、バージョンは 2 のまま。印を置かない所は動かない）。

### 強さを宣言する

`theme.json` の `motion`。書かなければ `subtle`。ほかの値は登録で止まる。外枠の `<html>` に出す（`ThemeTest` が監視する）。

| 値 | 動き | 公式テーマ |
|---|---|---|
| `none` | 動かさない（横のカートの滑りも止める） | — |
| `subtle`（既定） | 押した・開いた・変わった時だけ 0.15〜0.3 秒、動くのは数 px。浮かぶ動きと写真の拡大は無し | 角砂糖・kinari |
| `rich` | `subtle` に加え、スクロールで商品のカードが順に浮かぶ・ページを開いた時に写真がゆっくり引く。カードの写真の寄りも大きくゆっくり | fashion・botanica |

```json
{ "theme_api": 2, "motion": "rich", "sections": { … } }
```

```blade
<html lang="ja" data-motion="{{ $theme->motion }}">
```

### コアが動かすもの（印はコアの部品が付ける・読む）

| いつ | どこ | 印 | 動き |
|---|---|---|---|
| 種類を選んだ | 詳細の写真 | `img[data-variant-photo]`（今の印のまま） | 新しい写真が読めてから薄い所から出る |
| 種類・オプション・数を変えた | 価格・合計 | `[data-variant-price]`・`[data-addon-total]`（今の印のまま。行の中の箱 `inline-block` になる） | 少し下から薄く出る（読み込んだ直後は動かさない） |
| カートに入れた | 横のカートの通知・ヘッダの数 | `[data-cart-drawer-message]`・`[data-cart-count]`（今の印のまま） | 通知は少し下から、数は一度ふくらむ |
| カートのページで数を変えた | 行の数と小計・合計 | `[data-field=quantity\|total]`・`[data-cart-subtotal]` など（今の印のまま） | 変わった値だけ少し下から |
| 「カートに入れる」を押している間 | `form[data-cart-form] [type=submit]` | — | 少し縮む |
| カードに指を乗せた | 商品のカードの写真 | カードの外枠に `data-motion-card`（写真の箱は `overflow: hidden`） | 写真が少し寄る（指を乗せられる端末だけ） |
| ページを開いた | トップの見出しの塊 | `data-motion-intro`（**1 ページに 1 か所だけ**） | 薄い所から少し浮かぶ（0 にはしない） |
| ページを開いた（`rich`） | トップの大きな写真 | `img` に `data-motion-zoom`（箱は `overflow: hidden`） | ゆっくり引く |
| スクロール（`rich`） | 商品のカードなど | 浮かべたい要素に `data-motion-reveal` | 画面に入ったものから順に浮かぶ。最初から見えている所は動かさない |
| スマホのメニュー | `<details data-motion-menu>` の中の `<summary>` と `[data-motion-menu-panel]` | — | 開く時は CSS、閉じる時は JS が上へ薄く消してから閉じる。Esc とパネルの中のリンクでも閉じる。JS が無くても `<details>` のまま開け閉めできる |

コアが付ける印: `<html data-motion-ready>`（浮かぶ動きを始めた。`rich` で JS が動き、動きを減らす設定でない時だけ）、浮かんだ要素の `data-motion-shown`。

```blade
{{-- 商品の札（rich のテーマは浮かぶ印も） --}}
<a href="{{ $listing->url() }}" data-motion-card data-motion-reveal>
    <div class="relative aspect-[3/4] overflow-hidden">…<img …></div>
    …
</a>

{{-- トップ（1 か所だけ） --}}
<section class="relative overflow-hidden">
    <img … data-motion-zoom>
    <div data-motion-intro><h1>…</h1></div>
</section>

{{-- スマホのメニュー --}}
<details data-motion-menu>
    <summary aria-label="メニュー">…</summary>
    <nav aria-label="メニュー" class="absolute …" data-motion-menu-panel>…</nav>
</details>
```

### テーマの CSS で上書きしてよい変数

`motion.css` の変数は `:where()` で強さ 0 にしてある。テーマの CSS の**層（`@layer`）の外**に `:root[data-motion] { … }`（強さごとなら `:root[data-motion="rich"]`）で書けばテーマが勝つ。Tailwind の `@theme` の中は層の中なので効かない。

| 変数 | 何の | `subtle` | `rich` |
|---|---|---|---|
| `--motion-duration` | 値が変わった・メニュー | 200ms | 320ms |
| `--motion-nudge` | 値が変わった・メニューで動く量 | 3px | 6px |
| `--motion-distance` | 開いた時・浮かぶ時に動く量 | 4px | 16px |
| `--motion-ease` | CSS の動きの緩急 | `cubic-bezier(.2, .7, .2, 1)` | `cubic-bezier(.16, 1, .3, 1)` |
| `--motion-press-scale` | 押している間の縮み | .98 | .98 |
| `--motion-pop-scale` | カートの数のふくらみ | 1.15 | 1.3 |
| `--motion-hover-scale`・`--motion-hover-duration` | カードの写真の寄り | 1.02・300ms | 1.05・900ms |
| `--motion-intro-duration`・`--motion-intro-opacity` | 開いた時の見出し（出始めの濃さ） | 300ms・.35 | 900ms・.2 |
| `--motion-zoom-from`・`--motion-zoom-duration` | 開いた時の写真 | 1・0ms | 1.08・2400ms |
| `--motion-reveal-duration`・`--motion-stagger` | 浮かぶ時間と、続けて浮かぶ間 | 0ms・0ms | 700ms・80ms |

```css
/* fashion をもう少しゆっくりにする例（層の外に書く） */
:root[data-motion="rich"] {
    --motion-reveal-duration: 900ms;
    --motion-stagger: 100ms;
}
```

### 動きを減らす設定

端末の「視差効果を減らす」（`prefers-reduced-motion: reduce`）では、コアは何も動かさない。CSS の規則はすべて `no-preference` の中にあり、`reduce` では印の付いた所の `animation`・`transition` を止め、浮かぶ要素を見えたままにする。JS は強さを `none` として扱い、値はすぐ最後の形にする（横のカートも滑らせない）。テーマが自分で `transition` を足すときも、`@media (prefers-reduced-motion: no-preference)` の中（Tailwind なら `motion-safe:`）に書く。

### やってはいけないこと

- **支払い・注文の入力と確認の画面（`checkout`・`checkout-confirm`・`checkout-complete`）、ご注文の確認（`order-lookup`・`order`）の本文に動きの印を置かない**。個人情報を入れる画面に余計な動きを足さない（`ThemeTest` が `checkout*` を監視する）
- **並びを動かさない**: 動かすのは `transform` と `opacity` だけ。`width`・`height`・`top`・`margin` などを動かすと読み込み中のずれ（CLS）が出る。拡大する写真は `overflow: hidden` の箱に入れる
- **中身を隠さない**: 最初を隠すのはコアの `[data-motion-ready]` がある時だけ。テーマの CSS で `[data-motion-reveal] { opacity: 0 }` のように最初から隠さない（JS が動かない・遅い時に見えなくなる）。`data-motion-intro` は 1 ページに 1 か所、ページの中身の大半を包まない
- テーマの Blade の中に JS を書かない（インラインの `<script>`）・インラインの `style` を書かない（上の「テーマがしてはいけないこと」と同じ）。JS を持てるのは公式テーマとサーバーに入れたテーマの別のファイルだけで、個人情報の画面では読まない（ADR 0047）

## 商品のプレビュー（テーマ API 2 の付け足し）

管理画面の商品の画面の「プレビュー」から、署名つきの期限つきのリンク（30 分。`/preview/products/{listingId}`）で、下書きの商品も含めてその商品の詳細の画面（`product`）を開く。テーマは何もしなくてよい。

- すべての画面に `$preview`（bool）を渡す。プレビューの画面だけ真。バナーはコアの部品 `<x-storefront.apps :apps="$apps" />` が画面の上に描く（どのテーマでも同じ。見た目は `resources/storefront/apps.css` の `.sugar-preview`・`[data-sugar-preview]` で、テーマの CSS が上書きできる）。テーマが自分でバナーを描きたい・見せ方を変えたいときだけ `$preview` を見る
- プレビューの画面では、コアの部品（`cart.js`）と CSS が「カートに入れる」のフォーム（`form[data-cart-form]`）を押せなくする。テーマが `$preview` で「プレビューではカートに入れられません」などに描き替えてもよい
- プレビューの画面は App を差し込まない（個人情報の画面と同じ扱い）。応答は `X-Robots-Tag: noindex, nofollow`・`Cache-Control: no-store`・`Referrer-Policy: no-referrer`。プレビューできるのは売る側の固定価格の商品だけ
- 写真の URL（`ListingPhoto::url()`・`srcset()`）にはプレビューの署名が付く。テーマは URL を組み立てず、今までどおり `url()`・`srcset()` を使う

## App の買い方（ADR 0050。テーマ API 2 の付け足し）

商品の画面（product）に `$purchaseOptions`（`list<App\Core\Plugin\PurchaseOption>`）を渡す。この店で使っている App の買い方（定期便の「定期便で申し込む」など）。テーマはコアの部品 `<x-storefront::purchase-options :options="$purchaseOptions ?? []" />` を「カートに入れる」のフォームの下に置く（4 つの公式テーマは置いてある）。App が無ければ何も出さない。見た目は `.sugar-purchase-options`・`__button`・`__note`（テーマが上書きできる）。行き先は App のショップの画面（`/p/<名前>/…`）で、App の画面はテーマの外枠の中に描かれる

売り切れの商品（「カートに入れる」を出さないブランチ）にも、同じ部品を `sold-out` を付けて置く（2026-11-04、App「再入荷のお知らせ」。4 つの公式テーマは置いてある）。`sold-out` を付けた部品は、売り切れの時にも出す印（`whenSoldOut`）のある買い方（「入荷したら通知する」など）だけを出し、定期便のようにカートに入れられる時だけの買い方は出さない:

```blade
@if ($product->maxQuantity > 0)
    <form …>…</form>
    <x-storefront::purchase-options :options="$purchaseOptions ?? []" />
@else
    <button type="button" disabled …>売り切れ</button>
    <x-storefront::purchase-options :options="$purchaseOptions ?? []" sold-out />
@endif
```

買い方でない入口もこの部品に入る（2026-11-05、App「お問い合わせ」の「この商品について問い合わせる」。売り切れでも出す）。テーマは何も足さなくてよい。ご注文の画面の「この注文について問い合わせる」は、App のご注文のリンク（`order-links`）に入る


押す・戻すのボタン（トグル）もこの部品に入る（2026-11-05、App「お気に入り」のハート。ADR 0071）。`PurchaseOption::$pressed` が真偽の買い方は、リンクでなくコアの部品 `<x-storefront::toggle-button>` が POST のフォーム（CSRF トークンつき）で描き、押してある形を `aria-pressed="true"` で出す。JavaScript があればコアの `toggles.js` が画面を移らずに送って形と文字を替える。見た目は `.sugar-toggle`・`__button`・`__icon`・`__label`（テーマが上書きできる）。テーマは何も足さなくてよい

## App のカードのボタン（ADR 0071。テーマ API 2 の付け足し）

一覧のカード（`partials.product-card`。一覧・トップ・セクションの商品の並び）に、App の押す・戻すのボタン（App「お気に入り」のハートなど）を置く口。テーマはカードを外枠 `<div class="sugar-card">` で包み、外枠の中・**カードのリンクの外**にコアの部品を置く（フォームはリンクの中に置けない。4 つの公式テーマは置いてあり、`ThemeTest` が監視する）:

```blade
<div class="sugar-card">
    <a href="{{ $listing->url() }}" …>…写真・名前・値段…</a>
    <x-storefront::card-options :listing="$listing->id" />
</div>
```

- 部品は、この店で使っている App のボタンだけを描く（無ければ何も出さない）。中に書いたもの（`$slot`）は、ボタンが無い時にだけ出る（kinari は前からの飾りのハートをここに置いた）
- 見た目はコアの CSS（`apps.css` の `.sugar-card`〔`position: relative`〕・`.sugar-card-options`〔写真の右上に重ねる〕・`.sugar-toggle--compact`〔文字は読み上げだけ〕）。テーマの色の変数で付き、テーマが上書きできる
- カードの枚数だけ DB を引かない（どの App を聞くかと、会員のセッションは 1 回の要求で 1 度だけ読む）


## App の開いて読む並び（テーマ API 2 の付け足し）

商品の画面（product）に `$productPanels`（`list<App\Core\Plugin\ProductPanel>`）を渡す。この店で使っている App が、商品の説明の下に出す開いて読む並び（App「よくある質問」の質問と答えなど）。1 つの並びは `plugin`（App の名前）・`title`（見出し。40 文字まで）・`items`（`ProductPanelItem` の `summary` と `body`。50 個まで）。出すものの無い App は並びを渡さない。テーマは説明・仕様の節の下に、並びごとに節と見出しを置き、中はコアの部品に任せる（4 つの公式テーマは置いてある）:

```blade
@foreach ($productPanels ?? [] as $panel)
    <section aria-labelledby="panel-{{ $panel->plugin }}-title" class="…">
        <h2 id="panel-{{ $panel->plugin }}-title" class="…">{{ $panel->title }}</h2>
        <x-storefront::product-panel :panel="$panel" class="…" />
    </section>
@endforeach
```

- コアの部品は項目ごとに `<details>` と `<summary>` を描く（開閉に JavaScript を使わない）。見出しと本文は `{{ }}` で出す（HTML として扱わない）。本文の改行は CSS の `white-space: pre-line` で生かす
- 見た目はコアの CSS（`resources/storefront/apps.css`）が `[data-product-panel]`・`[data-product-panel-item]`・`[data-product-panel-summary]`（右に「+」、開くと「−」）・`[data-product-panel-body]` で付ける。規則は `:where()` で強さ 0 なので、テーマは同じ印を自分の CSS で上書きできる（印は消さない・変えない）
- App は文字だけを返し、HTML を返さない（口は `App\Core\Plugin\Contracts\ProductPanelProvider`。`PluginDefinition` の `productPanel`）。App が落ちても商品の画面は描く（その App の並びを出さないだけ）。商品のプレビューにも同じく渡す
- 構造化データ（FAQPage の JSON-LD）は出さない（今は検索の結果に出るのが一部のサイトだけで、得るものが小さいため。出すときは `<script type="application/ld+json">` の中身を JSON の HTML 用の書き方で外す）
- **星と返信**（2026-11-04、App「商品レビュー」）: 並びは任意で `rating`（`ProductPanelRating`。平均を 10 倍した整数 `tenths` と件数 `count`。`average()` は「4.3」、`stars()` はいちばん近い整数の星の数）と `open`（項目を最初から開いて出す）を持ち、項目は任意で `rating`（1〜5）・`meta`（書いた人の表示名と日付などの 1 行。100 文字まで）・`reply`（店からの返信。改行を生かす）を持つ。どれもコアの部品が描くので、テーマは今の使い方のままでよい（書き足すものは無い）。印は `[data-product-panel-rating]`（平均の行）・`[data-product-panel-stars]`（星のアイコン。`role="img"` と「5 段階中 4.3」の `aria-label`）・`[data-product-panel-average]`・`[data-product-panel-count]`・`[data-product-panel-title]`（星のある項目のタイトル）・`[data-product-panel-meta]`・`[data-product-panel-reply]`（「ショップから」の見出し `[data-product-panel-reply-label]` と本文 `[data-product-panel-reply-body]`）。星のない項目の `<summary>` の中は今までどおり文字だけ
- 平均の星と件数（`rating`）は、コアが商品の構造化データ（JSON-LD の `Product`）の `AggregateRating` にも使う（ADR 0061。新しい口は足していない。最初に `rating` を持つ並びの値）。1 件ずつのレビュー（`Review`）は出さない

## App のご注文のリンク（2026-11-04、App「商品レビュー」「納品書・領収書」。テーマ API 2 の付け足し）

ご注文の状況（order）の `$order->links`（`list<App\Core\Plugin\OrderLink>`）。この店で使っている App が、そのご注文に置くリンク（発送済みの注文の「レビューを書く」、払い済みの注文の「領収書」など）。1 つのリンクは `plugin`（App の名前）・`label`（20 文字まで。1 行）・`path`（その App のショップの経路 `/p/<名前>/…` だけ。ゲストに開かせるリンクは App が署名と期限をつけた問い合わせ〔`?…`〕を持つ）・`newTab`（新しいタブで開くか。印刷用のページなど）。テーマは合計の下にコアの部品を置く（4 つの公式テーマは置いてある。マイページの注文の履歴はコアが描く）:

```blade
<x-storefront::order-links :links="$order->links" class="…" />
```

- コアの部品は `<nav data-order-links aria-label="このご注文について">` の中にリンクごとの `<a>`（`data-test="order-link-<名前>"`）を描く。空なら何も描かない。文字は `{{ }}` で出し、行き先は組み立てずに `path` をそのまま使う。`newTab` のリンクは `target="_blank"`・`rel="noopener noreferrer"`、ほかも `rel="noreferrer"`（署名つきのご注文の画面の URL を次の画面へ渡さない）
- 見た目はコアの CSS（`resources/storefront/apps.css` の `[data-order-links]`。丸い枠のボタンを並べる）。規則は `:where()` で強さ 0 なので、テーマは同じ印で上書きできる

### 支払いの状況（ADR 0058。2026-11-05）

ご注文の状況（`order` の画面）に、払った手段とコンビニ払いの入金待ちを出すコアの部品。4 つの公式テーマは App のご注文のリンクの前に置いてある。

```blade
<x-storefront::payment-status :order="$order" />
```

- `$order->payment` は `PaymentState`（`method`・`label`〔「クレジットカード」「Apple Pay」「コンビニ払い」「PayPay」など〕・`awaiting`・`payBy`・`payByLabel()`〔「2026/11/08 23:59」日本時間〕・`instructionsUrl`〔Stripe の払込票の画面。入金待ちのときだけ〕）。支払いの記録が無い注文（0 円・まだ支払いの画面に進んでいない）は null
- 部品は、入金待ちなら `<section data-payment-status="awaiting">` に期限の文と払込票の画面へのリンク（`target="_blank"`・`rel="noopener noreferrer"`）、払われた注文なら `data-payment-status="paid"` に「お支払いの方法　クレジットカード」。取り消した注文と支払いの記録の無い注文は何も描かない
- 見た目はコアの CSS（`resources/storefront/apps.css` の `[data-payment-status]`）。規則は `:where()` で強さ 0 なので、テーマは同じ印で上書きできる
- 銀行振込の入金待ち・代金引換で払う前の注文（ADR 0074）は、この部品が中で `<x-storefront::direct-payment>` を描く（下の「銀行振込・代金引換」）。テーマは何も足さなくてよい
- 注文の通知メール（コアの `mail.order`）にも「お支払いの方法」、入金待ちの通知（`order_awaiting_payment`）に期限と払込票の画面、期限切れの取り消しの通知（`order_payment_lapsed`）がある。テーマは触らない
- リンクは注文を見てよい人の画面（確認のメールの署名つきのリンクか、注文番号とメールでの照会で開いたご注文の状況・ログインした会員のマイページ）でだけ入る。コアはその確認の後にだけ App に聞く。確認のメールの `OrderView` には入らない（`links` は空）
- App は文字と自分の経路だけを返す（口は `App\Core\Plugin\Contracts\OrderLinkProvider`。`PluginDefinition` の `orderLinks`）。ほかの App の経路・外のサイトは `OrderLink` が断る。App が落ちても、ほかの App の名前を装っても、ご注文の画面は描く（その App のリンクを出さないだけ）

## 会員の入口と会員の画面（ADR 0044。テーマ API 2 の付け足し）

お客さんはメールに届く 6 桁のコードでログインし（会員の登録の画面は別に無い）、マイページで注文の履歴を見る。ログインとマイページの画面はコアが描く。テーマはヘッダに入口を 1 つ置くだけ。

- すべての画面に `$account`（`App\Storefront\View\AccountLink`）を渡す。`url`（ショップの中のパス）・`label`（文字）・`member`（今の店の会員としてログインしているか）・`current`（今、ログイン・コード・マイページのどれかを見ているか）。ログイン中は `/account` と「マイページ」、そうでなければ `/account/login` と「ログイン」。会員かどうかはコアが今の店で引いた会員か（`Member::belongsHere()`）で確認する。**メールや名前など、誰かが分かる値は渡さない**。商品のプレビューと下書きのプレビューでは、いつも「ログイン」（会員を引かない）
- テーマは layout のヘッダの、カートの入口の近くに置く。URL と文字は組み立てず `$account->url`・`$account->label` を出す。`data-test="account-link"` を付け、`current` のときは `aria-current="page"`。絵柄だけのテーマは `aria-label="{{ $account->label }}"`、文字のテーマは文字を出す（狭い画面で絵柄だけにするなら、文字は `sr-only` で読み上げに残す）。スマホの幅でも隠さない

```blade
<a href="{{ $account->url }}" aria-label="{{ $account->label }}" @if ($account->current) aria-current="page" @endif data-test="account-link">
    @include('my-theme::partials.icon', ['name' => 'account'])
</a>
```

- 今の公式テーマ: 角砂糖は人の絵柄と文字（狭い画面では絵柄だけ）、kinari・fashion・botanica は人の絵柄（fashion と botanica は前はこの絵柄が「ご注文の確認」へのリンクだった。ご注文の確認はフッター・案内に残る）
- 準備中のショップの「準備中です」の画面（`password`）は外枠を使わないので、入口も出ない
- **会員の画面はコアが描く**（`resources/views/storefront/account-login.blade.php`・`account-code.blade.php`・`account.blade.php`、段階 3 で足した届け先 `account-addresses`・`account-address`、本人の確認 `account-confirm`、メールの変更 `account-email`、パスキー `account-passkeys`、退会 `account-leave`）。テーマの外枠（layout）を `@extends` し、App は差し込まない（個人情報の画面）。テーマは画面を持たない（持っても使われない）
- 見た目はコアの CSS（`resources/storefront/apps.css`。`$storefrontScript` と一緒に読まれる）が、テーマの色の変数（`--color-canvas`・`--color-ink`・`--color-line`・`--color-band`・`--color-sold`）と共通の `.sugar-page__*`（タイトル・説明・表・一覧・注記）で付ける。規則は `:where()` で強さ 0 なので、テーマは次の class を自分の CSS で上書きできる（名前は消さない・変えない）

| class | 場所 |
|---|---|
| `.sugar-account` | 会員の画面の列（真ん中寄せ、幅 40rem まで）。マイページ・届け先・パスキーは、この列に節のカードを重ねる |
| `.sugar-account--card`・`.sugar-account__card`・`__head`・`__foot` | ログイン・コード・本人の確認・メールの変更・退会の形（2026-11-01 に運営が選んだ案 A）: 真ん中に線で囲んだカード 1 枚（幅 28rem まで）、見出しと説明は中央、カードの下に小さな案内 |
| `.sugar-account__primary`・`.sugar-account__secondary` | 主のボタン（塗り）と控えめなボタン（線だけ）。角は欄とそろえる（0.75rem）。パスキーのボタンは控えめな方（本人の確認では主） |
| `.sugar-account__or` | 「または」の区切り（線の間に文字） |
| `.sugar-account__form`・`.sugar-account__label`・`.sugar-account__input`・`.sugar-account__input--code` | 欄（高さ 48px・文字 16px でスマホが拡大しない）。コードの欄は大きな数字を中央に、字間を空ける |
| `.sugar-account__error`・`.sugar-account__notice` | 誤りの通知と、ふつうの通知（バナーの色の箱） |
| `.sugar-account__email` | メールアドレス（長くても折り返す） |
| `.sugar-account__section`・`__section-head`・`__links` | マイページなどの節のカード、その見出しの行、会員の情報の丸いカードのリンク |
| `.sugar-account__list`・`.sugar-account__order`・`__order-head`・`__status`・`__lines`・`__total` | 注文・届け先・パスキーの並び（1 件の箱・日付と番号と状態の行・品の並び・合計）。状態は丸いカード |
| `.sugar-account__field`・`.sugar-account__field-error`・`.sugar-account__actions` | 届け先の入力欄（1 項目の箱・項目の誤り）と、「直す・いつもにする・消す」の並び |
| `.sugar-account__back`・`.sugar-account__logout` | 上の「← 戻る」のリンクと、ログアウト・退会の文字のリンク |
| `.sugar-saved-addresses`・`__title`・`__list`・`__item`・`__manage` | 注文の入力の「登録した届け先から選ぶ」（下）。選んでいる届け先は `aria-current="true"` |

**送料の見せ方**（ADR 0064）: 送料はコアが配送の方法（通常便・クール便など）ごとの都道府県の表で決める。テーマは額を計算せず、`CartView` の文字をそのまま出す。
- `shippingLabel()`: 「¥800」「無料」「無料（クーポン）」。カートと注文の入力（お届け先をまだ知らない）では地域で額が違えば目安の幅「¥800〜¥1,500」。店に方法が 2 つ以上・便が 2 つ以上なら方法の名前を添える（「¥800（クール便）」「¥2,000（通常便 ¥800・クール便 ¥1,200）」）。最終確認（checkout-confirm）からはお届け先の都道府県の確定の額（贈り物はいちばん高い額）
- `totalLabel()`: 目安のときは合計も幅（「¥4,800〜¥6,000」）
- `shippingNote()`: 「送料はお届け先の都道府県で決まります…」（目安のとき）・「配送の方法の違う商品は分けてお送りするため、送料は便ごとにかかります。」（便が 2 つ以上）・予約の品をまとめて送る一言（下の「予約販売」）。無ければ null。カート・注文の入力・最終確認の送料の行の下に出す（JS で数を変えた時に書き換えるなら、カートの要素に `data-cart-shipping-note`。応答の JSON は `shipping_note`）
- `freeShippingNote()`: 「あと ¥3,000 で送料無料」。方法が 2 つ以上なら「あと ¥3,000 で通常便の送料無料」
- ご注文の状況（order）とメールの `shippingLabel` も同じ形（便が 2 つ以上なら「¥2,000（通常便 ¥800・クール便 ¥1,200）」）。4 つの公式テーマは置いてある

**カートの画面（cart）に、コアの部品 `<x-storefront::coupon-form :cart="$cart" />` を置く**（ADR 0054。合計の欄の中）。コードを入れる欄と「使う」、入れた後はコードと「外す」、使えない理由を出す。JS なしで動く（`route('storefront.cart.coupon')` へ `code` を POST、外すは `route('storefront.cart.coupon.remove')`）。値引きの行は合計の欄に `$cart->discountLabel()` で自分で描く（JS で数を変えた時に書き換えるなら、行に `data-cart-discount-row`、額に `data-cart-discount`）。注文の入力（checkout）・最終確認（checkout-confirm）・ご注文の状況（order）にも値引きの行を出す。最終確認では、確認の後に使えなくなったクーポンの理由（`couponNotice`）を出す。部品の見た目は `.sugar-coupon`・`__applied`・`__form`・`__label`・`__row`・`__input`・`__apply`・`__remove`・`__notice`（色はテーマのトークン）。4 つの公式テーマは置いてある。

**自動の割引**（ADR 0065）: コード無しで条件に合うカートに効く割引（「秋のセール 全品 10%」「まとめ買い 3 点以上で 10%」）。1 つの注文に値引きは 1 つで、どれが効くかはサーバーが決める（自動の割引とクーポンのうちいちばん得なもの）。テーマは次のように置く（4 つの公式テーマは置いてある）:

- 値引きの行の名前は `$cart->discountName` で出す（カート・注文の入力・最終確認。「クーポン（CODE）」と書かない。自動の割引では店が決めた名前になる）。カートの JS で数を変えた時に書き換えるなら、名前に `data-cart-discount-name`
- カートの合計の欄に、次に届く割引の一言のコアの部品 `<x-storefront::discount-hint :cart="$cart" />`（「あと 1 点で「まとめ買い 10%」」「あと ¥1,200 で「秋のセール」」。無ければ `hidden` で隠れ、JS で数を変えると書き換わる。見た目は `.sugar-discount-hint`）
- カートの合計の欄に、1 回のご注文の下限の一言のコアの部品 `<x-storefront::minimum-notice :cart="$cart" />`（ADR 0109。店が App「購入の制限」で下限を決めていて、今買える商品の合計が届かない時だけ「ご注文は商品の合計 ¥3,000 から承ります（あと ¥1,200）。」。無ければ `hidden` で隠れ、JS で数を変えると書き換わる。見た目は `.sugar-minimum-notice`。商品ごとの 1 回の上限は行の `notice` と `maxQuantity` に入るので、テーマが足すものは無い）
- 最終確認では、値引きの行と `couponNotice` を別々に出す。通知は `$order->cart->couponNoticeForConfirm()` で出す（使えない理由には「（クーポンは使わずにご注文になります）」が付き、「自動の割引が効いているため、クーポンは使っていません。」「…お支払いの合計が下がらないため…」のように言い切る通知はそのまま。テーマで文を足さない）
- 商品の画面の価格の下に `<x-storefront::product-discounts :names="$productDiscounts ?? []" />`（その商品が対象で今使える自動の割引の名前をカードで並べる。会員だけの割引はログインした会員にだけ。無ければ何も描かない。見た目は `.sugar-product-discounts`・`__item`）
- 送料無料の自動の割引が効いた時の送料は `shippingLabel()` が「無料（割引の名前）」にする。0 円の注文の「お支払いは要りません（割引で 0 円のご注文）」もコアが出す
- クーポンの欄の通知（`.sugar-coupon__notice`）は、自動の割引が効いている・使っても合計が下がらない時だけ `.sugar-coupon__notice--info`（誤りでない落ち着いた色）が付く

**注文の入力の画面（checkout）に、コアの部品 `<x-storefront::saved-addresses :form="$form" />` を置く**（段階 3a。フォームの上）。会員でなければ何も出さない。会員なら、登録した届け先を並べ、選ぶとお届け先に入る（`?ship_to=`）。いつもの届け先と会員のメールは、`$form->value()` に最初から入る（テーマは何もしなくてよい）。`$form->addresses()` で同じ並び（`id`・`label`・`url`・`selected`）を自分で描いてもよい。4 つの公式テーマは部品を置いてある。

パスキーのボタン（ログイン・本人の確認・パスキーの画面）はコアの部品 `<x-storefront::passkey-form>` で、パスキーを使えるブラウザでだけ出る（`resources/storefront/passkeys.js`。テーマの `$storefrontScript` に入っている）。

コアの画面の箱は `.sugar-page`（会員の画面と、外部送信に関する公表・Cookie の設定で共通）。外枠の `<main>` が余白を持たないテーマ（画面ごとに自分の箱を持つ形）は、層の外の CSS で `.sugar-page` に左右の余白と `margin-inline: auto` を付ける（kinari・fashion・botanica。角砂糖は `<main>` が余白を持つので要らない）。ボタンは会員の画面の `.sugar-account__primary`・`.sugar-account__secondary`（同意バナーの `.sugar-consent__button` とは別。2026-11-01 に分けた）。経路の名前は `storefront.account.login`（ログイン）・`storefront.account`（マイページ）。テーマがほかの場所（フッターなど）にリンクを足すときも、`$account->url` を使う。

## 税率ごとの内訳（ADR 0063。テーマ API 2 の付け足し）

価格は税込みのまま（`priceLabel()` などは変わらない）。商品ごとに標準 10%・軽減 8% があり、消費税は税率ごとに 1 回だけ切り捨てる。テーマはコアの部品を 2 つの画面の合計の下に置くだけ（`ThemeTest` が監視する）。4 つの公式テーマは置いてある。

```blade
<x-storefront::tax-breakdown :taxes="$order->cart->taxBreakdown()" />   {{-- 最終確認（checkout-confirm） --}}
<x-storefront::tax-breakdown :taxes="$order->taxes" />                    {{-- ご注文の状況（order） --}}
```

- 部品は 1 率 1 行で「（8%※ 対象 ¥1,080　うち消費税 ¥80）」（`data-test="tax-row"`）と、軽減の率があれば「※ は軽減税率（8%）の対象です。」を描く。`$taxes` が `null`・行が無ければ何も描かない
- `TaxBreakdown`（コア）: `rows`（`list<{rateBp, rateLabel, reduced, amount, tax, amountLabel, taxLabel}>`。率の低い順。`amount` は税込みの対象の額）・`hasReduced()`・`tax()`・`text()`（1 行の文）。テーマは金額を組み立てず `…Label` を出す
- 確認の画面の内訳は、注文と同じ計算（値引きは対象の行の率ごとに分け、送料は 10%）。確認の後に商品の税率が変わると、指紋が変わって確定しない
- 軽減の商品の名前に「※」を添える: 最終確認は `$line['card']?->reducedTax()`、ご注文の状況は `$item['reduced']`
- 見た目はコアの CSS（`resources/storefront/apps.css` の `[data-tax-breakdown]`。右寄せの小さな文字）。`:where()` で強さ 0 なので、テーマは同じ印で上書きできる

## 贈り物（ADR 0055。テーマ API 2 の付け足し）

贈り物の App（`plugin-gift`）を使う店では、買う人が「贈り物として送る（お届け先は受け取る方が入力します）」を選べる。お届け先は、お支払いの後に買う人が送る受け取りのリンクから、受け取る人が入れる。テーマはコアの部品を 3 つの画面に置くだけ（使っていない店では何も出ない）。ご注文の状況への入口は App のご注文のリンク（`order-links`）で出る。4 つの公式テーマは置いてある。

| 画面 | 置くもの | 中身 |
|---|---|---|
| 注文の入力（checkout） | `<x-storefront::gift-fields :form="$form" />` をフォームの中のお届け先の欄の前に。お届け先の欄（`fieldset` など）に `data-ship-to` の印 | 「贈り物として送る」の選び（`gift[on]`）と、選んだ時だけ出る差出人のお名前（`gift[sender]`。50 文字まで、空ならご依頼主のお名前）・メッセージ（`gift[message]`。500 文字まで、改行できる。制御文字と向きの制御文字は断る）。選ぶとコアの CSS が `data-ship-to` の欄を隠す（JavaScript は使わない。隠せないブラウザでも、贈り物ならお届け先の欄は使わない）。`$form->giftAvailable()`・`$form->gift()` で自分で描いてもよい |
| 最終確認（checkout-confirm） | お届け先の所で `@if ($order->input->gift !== null)` なら `<x-storefront::gift-summary :gift="$order->input->gift" />` | 「贈り物（お届け先は受け取る方が入力します）」と差出人のお名前・メッセージ |
| 注文を受け付けた画面（checkout-complete） | `<x-storefront::gift-share :gift="$receipt->gift" />` | 贈り物の状況（お支払いの確認待ち・受け取り待ち・受け取り済み・発送済み・取り消し・期限で返金）と期限、買う人だけが開ける App の画面（`/p/gift/orders/{注文}`）への入口。**この画面には App のタグを差し込むので、受け取りのリンクそのものは渡さない**（リンクを知った人は誰でも受け取れるため） |
| ご注文の状況（order） | 何も足さない（App のご注文のリンク `<x-storefront::order-links :links="$order->links" />` に「贈り物のリンク・取り消し」が入る） | 行き先は買う人の App の画面（署名つきのリンク）。受け取りのリンク・「コピー」「LINE で送る」・作り直す・取り消すはそこで |

- `GiftShare`（`App\Core\Plugin\Gift\GiftShare`）: `status`（`preparing`・`waiting`・`claimed`・`shipped`・`cancelling`・`stopped`・`cancelled`・`expired`）・`statusLabel`・`url`（受け取りのリンク。受け取り待ちで、買う人だけが見る画面の時だけ）・`manageUrl`・`expiresOn`（「2026年11月18日」）、`waiting()`・`lineUrl()`
- 部品の見た目は `.sugar-gift`・`__title`・`__toggle`・`__note`・`__fields`・`__field`・`__label`・`__input`・`__textarea`・`__hint`・`__error`、`.sugar-gift-summary`・`__lead`・`__list`・`__message`、`.sugar-gift-share`・`__title`・`__status`・`__label`・`__row`・`__link`・`__copy`・`__line`・`__manage`・`__note`（色はテーマのトークン。`resources/storefront/apps.css`）
- 受け取る人の画面（`/p/gift/{リンクの token}`）と、買う人の画面（`/p/gift/orders/{注文}`・マイページの `/p/gift/account`）は App の画面で、テーマの外枠（`layout`）の中にコアの会員の画面と同じ箱（`.sugar-page`・`.sugar-account`）で描く。受け取る人の画面には、差出人・メッセージ・商品の名前と写真と数・発送（運送会社とお問い合わせ番号）だけを渡す（値段・買う人の名前と連絡先は渡さない）
- お届け先の入力はコアの部品 `<x-storefront::address-fields>`（会員の届け先と同じ）。郵便番号を入れると住所が入る（`data-postal-*` の印を部品が付ける）。郵便番号と都道府県が合わない入力は、サーバーが郵便番号の欄の誤り（`…postal`）として断る（ADR 0064 の作って決めたこと。部品が欄の下に出す）

## お届け日時（ADR 0059。テーマ API 2 の付け足し）

店が「設定 › お届け日時」で受けると、お客さんが注文の入力でお届け日と時間帯を選べる（どちらも「指定なし」も選べる）。選べる日と時間帯はサーバーが今の時刻（日本の時間）と店の決まりで作り、送られた値を確定までに照らし直す（範囲外・知らない時間帯は断る。確認の画面の後に範囲外になったら確定せず、入力の画面で選び直してもらう）。テーマはコアの部品を 3 つの画面に置くだけ（受けていない店では何も出ない）。4 つの公式テーマは置いてある。

| 画面 | 置くもの | 中身 |
|---|---|---|
| 注文の入力（checkout） | `<x-storefront::delivery-fields :form="$form" />` をフォームの中のお届け先の欄の後に | 「お届け日」（`delivery[date]`。値は `2026-11-07` の形）と「時間帯」（`delivery[slot]`。`am`・`14-16`・`16-18`・`18-20`・`19-21` のうち店が受けるもの）の `<select>`（JavaScript は使わない。最初の選択肢は「指定なし」で値が空）と、いちばん早いお届けの案内。誤りは欄の下に。「贈り物として送る」を選ぶとコアの CSS が隠す（届く日は受け取る人しだい。サーバーも贈り物では使わない）。`$form->delivery()`（`DeliveryOptions`: `dates`・`slots`〔`[{value, label}]`〕・`earliestLabel()`。受けていなければ null）と `$form->value('delivery.date')`・`$form->error('delivery.date')` で自分で描いてもよい（選択肢はこの中のものだけ） |
| 最終確認（checkout-confirm） | `<x-storefront::delivery-summary :label="$order->deliveryLabel()" />` を「お支払いとお届け」の所に | 「お届け日時のご希望」と「2026年11月7日（土） 午前中」・「日付の指定なし 午前中」・「指定なし（いちばん早くお届けします）」。受けていない店・贈り物では何も出さない |
| ご注文の状況（order） | `<x-storefront::delivery-summary :label="$order->deliveryWish" />` | 選んだ注文だけ「お届け日時のご希望」 |

- 部品の見た目は `.sugar-delivery`・`__title`・`__note`・`__field`・`__label`・`__optional`・`__input`・`__error`、`.sugar-delivery-summary`・`__label`・`__value`（色はテーマのトークン。`resources/storefront/apps.css`）
- ご注文の確認のメール（コアの `mail/order`）にも「お届け日時のご希望」が出る（選んだ注文だけ）

## 注文の項目（ADR 0066。テーマ API 2 の付け足し）

店が「設定 › 注文の項目」で足した欄（のしの有無・用途・ご要望など）を、注文の入力に「ご注文について」として出す。種類は 1 行の文（200 文字まで）・複数行の文（1,000 文字まで）・選ぶ（店の選択肢から 1 つ）・チェック（はい／いいえ）。サーバーが入力のエンドポイント・確認の画面・確定のロックの中の 3 回、店の今の決まりで確認する（必須・長さ・選択肢の中か。使わない欄・知らない欄の値は捨てる）。確認の画面の後に店が欄を変えたら確定せず、入力の画面で確認し直してもらう。テーマはコアの部品を 3 つの画面に置くだけ（欄の無い店では何も出ない）。4 つの公式テーマは置いてある。贈り物の注文でも出す（買う人に聞く）。

| 画面 | 置くもの | 中身 |
|---|---|---|
| 注文の入力（checkout） | `<x-storefront::order-fields :form="$form" />` をフォームの中のお届け日時の欄の後に | 使う欄を並びの順に（`fields[<欄の ID>]`。チェックは隠しの `0` と `1` のチェックボックス）。必須・任意のバッジ、店の説明、誤りは欄の下に。`$form->orderFields()`（`[{id, name, description, type, options, required, maxLength, inputName, inputId, hintId, value, error}]`。`type` は `text`・`textarea`・`select`・`checkbox`）で自分で描いてもよい（名前は `inputName` のまま。選択肢は `options` のものだけ） |
| 最終確認（checkout-confirm） | `<x-storefront::order-fields-summary :fields="$order->orderFields" />` を「お支払いとお届け」の所に | 「ご注文について」と、回答のある欄の名前と値（`[{name, value}]`。チェックは「はい」「いいえ」。改行はそのまま見せる）。入れた本人の画面なので文の回答も出る |
| ご注文の状況（order） | `<x-storefront::order-fields-summary :fields="$order->fields" :withheld="$order->fieldsWithheld" />`（`fields` の先頭には App が足す注文の事実〔予約の日時・場所・人数など。`OrderFactProvider`、〕も入る。テーマは何も足さなくてよい） | 注文の時の欄の名前で、選ぶ・チェックの回答だけ。文の回答（ご要望など）は中身を渡さず、ある時だけ「ご要望などの文のお答えはお店に届いています」の一言（`fieldsWithheld`） |

- 名前・説明・選択肢・値は文字のまま出す（部品は `{{ }}` だけ。HTML として解釈しない）。文の回答は、確認の画面（入れた本人）にだけ出す。ご注文の状況・マイページ・メールには渡さない（打ち間違えたメールの持ち主や、払わずに送れる入金待ちのメールで他人に見せない。ADR 0066 の決定 5）
- 部品の見た目は `.sugar-order-fields`・`__title`・`__field`・`__label`・`__check`・`__required`・`__optional`・`__input`・`__textarea`・`__hint`・`__error`、`.sugar-order-fields-summary`・`__title`・`__list`・`__row`・`__label`・`__value`・`__note`（色はテーマのトークン。`resources/storefront/apps.css`）
- コアのマイページの注文の履歴と、ご注文の確認のメール（コアの `mail/order`。発送の通知には出さない）にも、選ぶ・チェックの回答と一言が出る

## 検索と共有（ADR 0061。テーマ API 2 の付け足し）

検索の結果と LINE・X・Facebook で共有した時の見え方は、コアが決めて、テーマは layout の `<head>` にコアの部品を 1 行置くだけ（4 つの公式テーマは置いてある）。今のテーマの `<title>` と `<meta name="description">` をこの 1 行に替える:

```blade
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-storefront::seo :seo="$seo">@yield('title', $shop->name)</x-storefront::seo>
    @yield('head')
    @vite([$theme->stylesheet, $storefrontScript])
</head>
```

- すべての画面に `$seo`（`App\Storefront\Seo\SeoMeta`）を渡す。テーマは中を組み立てず、部品に渡すだけ
- 部品が出すもの: `<title>`・`<meta name="description">`、検索に載せない画面は `<meta name="robots" content="noindex, nofollow">`、載せる画面は `<link rel="canonical">`・`og:site_name`・`og:type`・`og:title`・`og:description`・`og:url`・`og:locale`・`og:image`（と幅・高さ・代わりの文字）・`twitter:card`・JSON-LD（`<script type="application/ld+json">`。コアが作る塊で、テーマは書けない）
- **タイトルの形**: 「ページのタイトル | 店の名前」（トップは店の名前か、設定 › SEO で入れたタイトルだけ）。店が画面ごとに入れた「検索の結果に出すタイトル」が先、無ければ商品名・カテゴリの名前・ページのタイトル
- **検索に載せる画面はコアが決める**（トップ・商品・すべての商品とカテゴリの一覧・自由なページ・特商法の表記）。ほかの画面（カート・注文・マイページ・App の画面・プレビュー・エラー・検索の結果）は、タイトルはテーマの `@section('title', …)` のまま（部品の中の文）で、`noindex` が付く。準備中のショップはどの画面も `noindex`
- 構造化データ: 商品の画面は `Product`（`Offer`。種類で価格が違えば `AggregateOffer`、App の平均の星があれば `AggregateRating`）と `BreadcrumbList`、カテゴリは `BreadcrumbList`、トップは `OnlineStore`
- 商品の画面の `@section('title')`・`@section('description')`・canonical は書かない（部品が出す。前に書いていた 4 つの公式テーマからは外した）。外枠を使わない `password` の画面は、前のとおり自分で `<meta name="robots" content="noindex, nofollow">` を書く
- `/robots.txt` と `/sitemap.xml` はコアが店ごとに返す（テーマは何もしない）
- **App の検索に載せるページ**（ADR 0072。App「お知らせ・ブログ」の `/news`・`/news/{slug}`）も、見え方はコアが作る（`SeoBuilder::forAppPage`）。記事は `og:type` が `article` で、構造化データは `Article`（見出し・日時・写真・店）と `BreadcrumbList`。使っている店のサイトマップに一覧と記事が載る。下書き・予約の記事はどこにも出ない

## お知らせ・ブログ（ADR 0072。App「お知らせ・ブログ」を使う店だけ）

- `PostCard`（一覧とセクションの 1 件）: `title`・`url`（`/news/{slug}`）・`publishedAt`・`date()`（`2026.11.05`）・`isoDate()`（`<time datetime>` 用）・`category`・`categorySlug`・`excerpt`（null あり）・`photo`（`ShopPhoto` か null）。文は店の人が書いた素の文字で、必ず `{{ }}` で出す
- 画面は上の表の `news`・`news-post`。テーマが持たなければ App の既定の画面が、コアの CSS（`apps.css` の `.sugar-news*`・`.sugar-news-post*`。`:where` で強さ 0）で描く。テーマは同じ class を CSS で上書きするか、画面ごと持つ
- 一覧の Atom は `$news->feedUrl`（既定の画面は `@section('head')` に `<link rel="alternate" type="application/atom+xml">` を置く。テーマが `news` を持つときも同じく置くとよい）

### 有料の記事（テーマ API 2 の付け足し）

記事は「読める条件（鍵）」と「ここから有料」を持てる。**権利の無い人には、有料のブロックが `$post->blocks` に入らない**（テーマが隠すのではなく、サーバーが組み立てていない）。テーマは本文の後ろに案内を出すだけでよい。

- `$post->paywall`: 有料の分を落としたときだけ `PostPaywall`、ほかは null
  - `message()`: 案内の文（無料の分があれば「ここから先は〜」、全部有料なら「この記事は〜」）
  - `signInNote()`: 会員でない人への一言（会員なら null）・`signInUrl()`: ログインのパス（読み終えたら記事へ戻る）
  - `keys`: 記事が要求する鍵（買う・入会する導線を作る App 向けの値。画面には出さない）
- App の既定の画面は `blog::paywall`（`.sugar-paywall`）を本文の後ろに置く。テーマが `news-post` を持つなら、同じ所に自分の見た目で出す（**出さないと、切れた記事の理由がお客さんに分からない**）
- 一覧のカード（`PostCard`）・セクション・Atom に出るのはタイトルと抜き書きだけで、本文は入らない（有料の分がカードから漏れない）

## プライバシーポリシー（ADR 0094。テーマ API 2 の付け足し）

店の人が管理画面の「設定 › プライバシーポリシー」で書いた本文を、ショップの **`/privacy`** に出す。今のテーマは何もしなくても出せる（画面は任意。付け足しなので、バージョンは 2 のまま）。

- **フッターのリンク**: 本文がある店だけ `$shop->privacyUrl` にパスが入る。特商法の表記のリンクの隣、`<x-storefront.app-links>`（外部送信について・Cookie の設定）の前に置く。4 つの公式テーマは置いてある

```blade
<a href="{{ route('storefront.legal') }}">特定商取引法に基づく表記</a>
@if ($shop->privacyUrl)
    <a href="{{ $shop->privacyUrl }}">プライバシーポリシー</a>
@endif
<x-storefront.app-links :apps="$apps" />
```

- **画面 `privacy`（任意）**: テーマが持てばそれで、無ければコアの既定の画面（`storefront.privacy`。テーマの外枠の中にタイトル・本文と、外へ送る App を使う店では外部送信に関する公表への一言）で描く。コアの画面の見た目は `.sugar-page`・`.sugar-privacy`・`.sugar-privacy__body`（コアの CSS。`:where()` で強さ 0）と、テーマの `[data-blocks]` の規則
- **本文**: `$privacy->blocks` は商品の説明と同じ `DescriptionBlock` の並び（`paragraph`・`heading`〔level 2 と 3〕・`list`〔箇条書き〕だけ）。店の人が書いた素の文をコアが読む: 空の行で段落、`# `・`## ` で大きい見出し、`### ` で小さい見出し、`- `・`* `・`・` で箇条書き。番号・リンク・太字の記号は読まず、書いたままの文字で出す。HTML は受けない。テーマが自分で描くときも、コアの部品か `{{ }}` で出す（`{!! !!}` は使わない）
- **画面の種類**はふつうの画面（App のタグを差し込む。お客さんの情報は入らない）。検索に載せ、サイトマップにも入る。準備中のショップでは、ほかの画面と同じく関門の内側
- 本文の無い店では「見つかりません」（404。空のページを出さない）

## お知らせのメールを受け取る（テーマ API 2 の付け足し）

App「メルマガ・一斉メール」（`plugin-newsletter`）など、注文の入力の同意の口（`CheckoutOptInHandler`）を持つ App を使う店では、注文の入力に「ショップからのお知らせ（新商品・セールなど）をメールで受け取る」の切り替えが 1 つ出る。テーマはコアの部品を置くだけ（使っていない店では何も出ない）。4 つの公式テーマは置いてある。

| 画面 | 置くもの | 中身 |
|---|---|---|
| 注文の入力（checkout） | `<x-storefront::opt-in :form="$form" />` をフォームの中のお届け先の欄の後（送るボタンの前）に | 切り替え（`opt_in`。**いつも選んでいない状態から**。同意は本人が選ぶ〔特定電子メール法のオプトイン〕。誤りで戻った時・確認の画面から戻った時だけ、本人が選んだ値を戻す）と、その下の一言（届く中身・止め方）。選んだ人の注文が確定した後にだけ、App がメールを受け取る |

- 自分で描くときは `$form->optInOffer()`（`{label, note}` か null。null なら何も出さない）と `$form->optIn()`（今選んでいるか）を使う。**`checked` を最初から付けない**（入れた値を戻すときだけ `$form->optIn()` で）
- 部品の見た目は `.sugar-opt-in`・`__toggle`・`__note`（コアの CSS。`:where()` で強さ 0、テーマが上書きできる）
- 最終確認（checkout-confirm）には出さない（選んだかは入力の画面で見える）

## 銀行振込・代金引換（ADR 0074。テーマ API 2 の付け足し）

Stripe を通さず店が受け取る支払い。選べるか・手数料・期限はすべてサーバーが決め、テーマはコアの部品を置くだけ。店が使っていなければ部品は何も描かない（今までと同じ画面）。4 つの公式テーマは置いてある。

| 画面 | 置くもの | 中身 |
|---|---|---|
| 注文の入力（checkout） | `<x-storefront::payment-choice :form="$form" />`（フォームの中。ポイントの欄の後） | 店が銀行振込か代引きを使う時だけ、「お支払いの方法」の選び方（`payment` = `stripe`・`bank_transfer`・`cod`）。「クレジットカード・コンビニ払い・PayPay（次の決済の画面で選ぶ）」「銀行振込（先払い。ご注文から 7 日以内にお振込み）」「代金引換（手数料 ¥330）」。選べない手段（代引きを贈り物・予約・デジタルの品・上限の額を超える注文で、銀行振込をお届け日を選んだ注文で）は押せないまま理由を添える。送ってきた値が選べなければ `payment` の誤りで断り、部品が下に出す。`$form->paymentOffer()`（`PaymentOffer`: `options`〔`value`・`label`・`available`・`reason`・`fee`〕・`note`）・`$form->payment()`・`$form->paymentNote()` で自分で描いてもよい。同じメール・同じ接続元の入金待ちが上限に達したら、銀行振込・代引きは押せないまま理由「同じメールアドレスまたは同じ回線から、お支払いの済んでいないご注文が続いています。…」を添える。店が「銀行振込・代金引換は会員だけ」にした時のゲストには銀行振込・代引きを並べず、添え書き（`note`。「銀行振込・代金引換は、ログインした会員の方だけお選びいただけます。」、Stripe の鍵が無い店では「…ログインすると、銀行振込・代金引換でご注文いただけます。」）とログインへのリンク（`data-test="payment-login"`）だけを出す（選び方が無くても部品が出す。ADR 0085）。見た目は `.sugar-payment-choice`・`__legend`・`__option`・`__reason`・`__note`・`__error` |
| 最終確認（checkout-confirm） | 合計の欄の送料・ポイントの下に `@if ($order->cart->paymentFeeLabel())` で「代引き手数料」の 1 行、確定のボタンの下に `{{ $order->placeNote() }}` | 代引きの手数料（税込み・標準の率）。合計と税率ごとの内訳は手数料を含む |
| 受け付けた画面（checkout-complete） | 支払いの文の分かれに `@elseif ($receipt->direct())` → `<x-storefront::direct-payment :payment="$receipt->payment" />`、「お支払いへ進む」の条件に `|| $receipt->direct()` | 銀行振込: 振込の額・期限（日本時間）・振込先（銀行名・支店名・口座の種類・口座番号・口座の名義。注文の時のスナップショット）。代引き: 「代金（代引き手数料を含む ¥5,280）は、商品のお届けの時に配達員へお支払いください」。見た目は `[data-direct-payment]`（`bank_transfer`・`cod`）と `.sugar-direct-payment__note` |
| ご注文の状況（order） | 送料の下に `@foreach ($order->charges as $charge)` の行。支払いの状況は今の `<x-storefront::payment-status>` が描く | 「代引き手数料 ¥330」と、入金待ちの振込先・代引きの案内 |

- `PaymentState` に `bankAccount`（振込先。銀行振込の入金待ちの時だけ）・`onDelivery`（代引きで払う前）・`amount`、`awaitingTransfer()`・`bankLines()`（「銀行名 ◯◯」の行の並び）・`amountLabel()` がある。振込先は店の口座で、注文を見てよい人の画面（受け付けた画面・ご注文の状況）とメールにだけ入る
- 注文の通知メール（コアの `mail.order`）: 銀行振込は「入金をお待ちしています」（`order_awaiting_payment`）に振込先・額・期限、期限切れは「お振込みが確認できなかった」。代引きは受け付けた時の「ご注文ありがとうございます」に「代金引換で受け付けました」。テーマは触らない

## ポイント（ADR 0068。テーマ API 2 の付け足し）

会員だけが貯めて使えるポイント（1 ポイント = 1 円）。数はすべてサーバーが決め、テーマはコアの部品を置くだけ（`ThemeTest` が監視する）。店が「使わない」にしている時・ゲストに見せない所では、部品は何も描かない。4 つの公式テーマは置いてある。

| 画面 | 置くもの | 中身 |
|---|---|---|
| 商品（product） | `<x-storefront::product-points :points="$productPoints ?? null" />`（価格の近く。割引の名前の下） | 会員には「44 ポイント付きます」、ゲストには「会員なら 44 ポイント付きます」（今の値段 × 店の率の切り捨て）。見た目は `.sugar-product-points` |
| カート（cart） | `<x-storefront::points-note :points="$cart->points" />`（合計の欄の中。次に届く割引の一言の下） | 「このご注文で 44 ポイント付きます（発送の時）」・ゲストには「会員なら…」。付かなければ `hidden`。JS で数を変えると `[data-cart-points-note]` が書き換わる（`cart.js`。`toArray()` の `points_note`） |
| 注文の入力（checkout） | `<x-storefront::points-fields :form="$form" />`（フォームの中。注文の項目の後、お知らせのメールの前） | 会員で残高がある時だけ: 残高と期限、「使わない・全部使う・数を入れて使う」（`points[mode]` = `none`・`all`・`some`、`points[amount]`）。残高を超える数はサーバーが `points.amount` の誤りで断り、部品が欄の下に出す。`$form->pointsOffer()`・`$form->pointsMode()` で自分で描いてもよい。見た目は `.sugar-points-fields`・`__legend`・`__balance`・`__expiry`・`__choice`・`__amount`・`__error`・`__note` |
| 最終確認（checkout-confirm） | 合計の欄（`dl`）の中の送料の下・合計の上に `<x-storefront::points-used :points="$order->cart->points" />`、税率ごとの内訳の下に `<x-storefront::points-note :points="$order->cart->points" />` | 「ポイントのご利用 −¥300」（`dt`・`dd` の 1 行。`.sugar-points-used`）と付くポイントの一言。合計 `totalLabel()` は使ったポイントを引いた額。0 円になれば「お支払いは要りません（ポイントで 0 円のご注文）」をコアが出す |
| ご注文の状況（order） | 税率ごとの内訳の下に `<x-storefront::order-points :points="$order->points" />` | 「44 ポイントが付く予定です（発送の時に付きます）」「44 ポイントが付きました」「ご返金のため、このご注文のポイントは取り消しました」。使ったポイントは `$order->discounts` に「ポイントのご利用」の 1 行で入っている（値引きの行と同じに描けば出る） |

- `CartView::$points`（`App\Storefront\Cart\CartPoints`）: `enabled`・`member`・`used`（使うポイント）・`earn`（付くポイント）・`balance`（使う前の残高）・`byRate`（税率ごとの使ったポイント）・`onShipping`・`earnBase`・`lowered`（使った後の合計が 1〜49 円になるので 50 円が残る数に抑えた）、`usedLabel()`（「−¥300」）・`earnNote()`（一言）。使った後の合計はいつも 0 円か 50 円以上（Stripe の円の最低額）。テーマは数を組み立てない
- `OrderView::$points`（`App\\Storefront\\Orders\\OrderPointsView`）: `spent`・`earn`・`earned`・`state`（`pending`・`earned`・`revoked`・`cancelled`・`none`）、`note()`（一言。言うことが無ければ null）。会員でない注文は `null`
- マイページ（コアが描く `account`）に、残高・期限・付く予定・動きの節が出る（`.sugar-account__section`。テーマは何もしなくてよい）
- 注文のメールはコアが描く（使ったポイントの行と付く予定・付いた一言）

## 会員ランク（ADR 0079。App「会員ランク」を使う店だけ。テーマの変更は要らない）

テーマが置く部品は増えない。今の部品と画面のまま、次のように出る:

- 会員価格はほかの値引きと同じ値引きの行で出る（`$cart->discountName` が「ゴールドの会員価格（5% 引き）」。自動の割引・クーポンと重ねず、いちばん得な 1 つ）。商品の画面の `<x-storefront::product-discounts>` の先頭に、ログインした会員の会員価格の名前が出る
- ポイントの上乗せは、`<x-storefront::product-points>`・カートと確認の画面の付く一言の数に入る（会員の分だけ。ゲストの「会員なら」は店の率）
- 会員だけ・ランクだけの商品は、見せない人にはコアの窓口（`Catalog`）が渡すデータに入らない（一覧・検索・カテゴリ・セクション・商品の画面〔404〕・カート）。テーマで隠す処理を書かない
- 見てよい会員に描く会員だけの商品の写真の URL には、コアが署名（店・商品・期限）を付ける。写真の URL は `ListingPhoto::url()`・`srcset()` のまま使い、テーマで組み立てない（署名が落ちると写真が出ない）
- マイページの「会員ランク」（`/p/member-ranks/account`）は App の画面で、テーマの外枠（`layout`）の中に会員の画面と同じ箱（`.sugar-page`・`.sugar-account`）で描く。マイページの App の入口に出る

## ギフトカード（ADR 0076。テーマ API 2 の付け足し）

注文の入力でギフトカード（商品券）のコードを入れて使う。ギフトカードは値引きではなく**支払いの一部**なので、注文の合計（税込み）と消費税は変えず、「お支払い」の額（決済代行で払う額）が減る。使う順は値引き → ポイント → ギフトカード。数はすべてサーバーが決め、テーマはコアの部品を置くだけ（`ThemeTest` が監視する）。4 つの公式テーマは置いてある。ギフトカードを売る画面は App「ギフトカード」の画面（`/p/gift-cards`。テーマの外枠の中に App が描く）。

| 画面 | 置くもの | 出るもの |
|---|---|---|
| 注文の入力（checkout） | `<x-storefront::gift-card-fields :form="$form" />`（フォームの中。ポイントの欄の後） | 店が 1 枚でも発行していれば出す（会員でなくてよい）: コードの欄（`gift_card[code]`）と、使っているカードの末尾 4 文字・残高・期限と「このギフトカードを使わない」（`gift_card[remove]`）。見つからない・使えない・回数を超えたはサーバーが `gift_card.code` の誤りで断り、部品が欄の下に出す。コードそのものは画面にも入れ直した値にも出さない。`$form->giftCardOffered()`・`giftCardInUse()`・`giftCardError()` で自分で描いてもよい。見た目は `.sugar-gift-card-fields`・`__legend`・`__current`・`__remove`・`__label`・`__code`・`__error`・`__note` |
| 最終確認（checkout-confirm） | 合計の欄（`dl`）の中の合計の下に `<x-storefront::gift-card-used :cart="$order->cart" />` | 「ギフトカード（末尾 ABCD）−¥3,000」「お支払い ¥1,950」「ギフトカードの残り」の `dt`・`dd` の行（`.sugar-gift-card-used`）。合計 `totalLabel()` は変わらない。支払いの方法の文（`paymentMethod()`）はコアがギフトカードを含めて書く（全額なら「ギフトカード（¥4,950。ほかのお支払いは要りません）」） |
| ご注文の状況（order） | 税率ごとの内訳の下に `<x-storefront::order-gift-card :gift-card="$order->giftCard" />` | 「ギフトカード（末尾 ABCD）で ¥3,000 をお支払い（カードの残り ¥0）」、取消などで全部戻したら「…は、カードへ戻しました」、一部なら「…のうち、¥500 をカードへ戻しました」 |

- `CartView::$giftCard`（`App\Storefront\Cart\CartGiftCard`）: `id`・`last4`・`applied`（使う額）・`balance`（使う前の残高）・`expiresAt`・`lowered`（お支払いが 1〜49 円になるので 50 円が残る額に抑えた）・`problem`（使えない理由）、`appliedLabel()`・`remaining()`・`remainingLabel()`。`CartView::due()`・`dueLabel()` は払ってもらう額（合計 − ギフトカードの額）。お支払いはいつも 0 円か 50 円以上
- `OrderView::$giftCard`（`App\Storefront\Orders\OrderGiftCardView`）: `last4`・`amount`・`remaining`・`released`（カードへ戻した額）、`note()`。使っていない注文は `null`
- 注文のメールはコアが描く（使った額とカードの残りの一言。コードは載せない）

## App の料金（ラッピング・のしなど。ADR 0078。テーマ API 2 の付け足し）

App が注文に足す料金（最初は App「ラッピング・のし」）。注文の入力に App の欄を出し、選んだものの額をサーバーが店の設定で決めて、注文の合計に「料金の行」として足す（税込み・標準の率。使ったポイントの後・代引き手数料の前。ポイントでは払わず、ギフトカードは料金を含めて充てる）。数と文はすべてサーバーが決め、テーマはコアの部品を置くだけ（`ThemeTest` が監視する）。料金の口を持つ App を使っていない店・選べる商品の無いカートでは、部品は何も描かない。4 つの公式テーマは置いてある。

| 画面 | 置くもの | 出るもの |
|---|---|---|
| 注文の入力（checkout） | `<x-storefront::charge-fields :form="$form" />`（フォームの中。注文の項目の後、ポイントの欄の前） | App の見出し（「ラッピング・のし」）と一言、欄（選ぶ・1 行の文。`charges[<鍵>]`。ラッピングの App は `charges[wrapping]`・`charges[noshi]`・`charges[noshi_style]`・`charges[noshi_name]`）。選択肢の名前に値段が入る（「リボン（¥330）」）。選べない値・長すぎる名入れはサーバーが `charges.<鍵>` の誤りで断り、部品が欄の下に出す（欄が消えた時は `charges._form` を見出しの下に）。`$form->charges()` で自分で描いてもよい。見た目は注文の項目と同じ `.sugar-order-fields`・`__title`・`__field`・`__label`・`__optional`・`__input`・`__hint`・`__error` |
| 最終確認（checkout-confirm） | 合計の欄（`dl`）の中の使ったポイントの下・代引き手数料の上に `<x-storefront::charges-used :cart="$order->cart" />`、「お支払いとお届け」の注文の項目の値の下に `<x-storefront::charges-summary :items="$order->charges" />` | 「ラッピング（リボン） ¥330」「のし（御祝・外のし・名入れあり） ¥110」の `dt`・`dd` の行（`.sugar-charges-used`。0 円は「無料」）と、選んだ内容（「ラッピング: リボン（¥330）」「のし: 御祝・外のし（¥110）」「名入れ: 鈴木一郎」。入れた本人の画面なので名入れの文字も出る。見た目は `.sugar-order-fields-summary`）。合計 `totalLabel()`・税率ごとの内訳は料金を含む |
| ご注文の状況（order） | 何も足さなくてよい（`$order->charges` を代引き手数料と同じに描けば出る） | `$order->charges` に料金の行（`title`・`amountLabel`。0 円は「無料」）が代引き手数料の前に入る。名入れの文字は出さず、行の名前の「名入れあり」だけ（メールも同じ） |

- `CartView::$charges`（`list<{kind, title, amount}>`）・`chargesTotal()`・`chargeLines()`（`[{title, amountLabel}]`）。`total()` は料金を含む。料金は確認の画面と確定でだけ足す（カートの画面・注文の入力の合計には入らない）
- `CheckoutSummary::$charges`（選んだ内容の `[{name, value}]`）と `$chargesDigest`（指紋に入る要約）。確認の後に店が値段・選択肢・のしの決まりを変えたら確定しない（確認の画面へ戻る）

## 予約販売（ADR 0073。テーマ API 2 の付け足し）

App「予約販売」を使う店で、発売日の前の商品は在庫によらず予約で受ける。数と文はサーバーが決め、テーマは出すだけ。App を使っていない店・予約でない商品では、どれも今までどおり（`preorder` は null）。4 つの公式テーマとコアの商品のセクションは合わせてある。

- `ListingCard::$preorder`: 予約の商品の発売日（`Y-m-d`。発送を始める日）。予約でなければ null。在庫の具合（`availability`・`remaining`）は予約の残りで決まる（受付を終えた・上限に届いたら `sold_out`）
- `ListingCard::preorderLabel()`: 「予約商品（11月20日ごろ発送）」（予約でなければ null）
- `ListingCard::availabilityLabel()`: 在庫の具合の文。ふつうは「在庫あり」「残り 2 点」「売り切れ」、予約の商品は「予約商品（11月20日ごろ発送）」「予約商品（11月20日ごろ発送）・残り 2 点」「予約の受付を終えました」（`ProductVariant::availabilityLabel()` も同じ決め方。種類を選ぶ部品はこの文に書き換える）
- `ListingCard::addToCartLabel()`: 「カートに入れる」の文字（予約の商品は「予約する」）
- 商品の画面（product）: 在庫の通知の所で `@if ($product->card->preorder !== null)` なら `availabilityLabel()` を出し（`data-test="product-preorder"`。受付を終えたら売り切れの色）、ボタンの文字は `addToCartLabel()`
- カート（cart）: 行の値段の下に、`$line['card']->preorder` があれば `preorderLabel()`（`data-test="cart-preorder"`。応答の JSON の行は `preorder`）
- まとめて送る一言は `CartView::shippingNote()` に入る（カート・注文の入力・最終確認がもう出している所）: 予約の品だけなら「予約商品は発売日（11月20日ごろ）にお送りします。」、ふつうの品や発売日の違う予約の品と一緒なら「予約商品の発売日（12月1日ごろ）にまとめてお送りします。」
- ご注文の状況（order）: `OrderView::$preorder`（「11月20日ごろ発送（予約商品）」。発送の前の予約の注文だけ）と `$preorderOrderedOn`（発売日が変わった時の注文の時の日）。コアの部品に渡す: `<x-storefront::delivery-summary :label="$order->deliveryWish" :preorder="$order->preorder" :ordered-on="$order->preorderOrderedOn" />`（「発送の予定」の行。4 つの公式テーマは渡してある）。注文のメールとマイページはコアが描く
- `CartView::preorderShipDate()`: まとめて送る日（いちばん遅い発売日。`Y-m-d`。予約の品が無ければ null）。この日がある注文は、コアの部品 `<x-storefront::delivery-fields>` も `<x-storefront::delivery-summary>` も何も描かない（お届け日時の指定は受けない）

## 年齢の確認（ADR 0077。テーマ API 2 の付け足し）

App「年齢確認」を使う店で、年齢の確認が要る商品（お酒・たばこなど。20 歳・18 歳・15 歳）は、商品の画面に一言を出し、注文の入力で生年月日と「◯ 歳以上です」の同意を受ける。満年齢はサーバーが日本の暦の今日で数え、足りなければ確定しない。**生年月日は残さない**（欄は入れ直しの時も空で出す）。テーマはコアの部品を 3 つの画面に置くだけ（年齢の要る品が無い・App を使っていない店では何も出ない）。4 つの公式テーマは置いてある。

| 画面 | 置くもの | 中身 |
|---|---|---|
| 商品（product） | `<x-storefront::age-notice :age="$productAge ?? null" />`（価格の近く。ポイントの下） | 「20 歳未満の方はお求めいただけません。」（`$productAge` は年齢か null）。見た目は `.sugar-age-notice` |
| 注文の入力（checkout） | `<x-storefront::age-check :offer="$form->ageOffer()" />` をフォームの中の注文の項目の後に | 「年齢の確認」の枠に、生年月日（`age[birthdate]`。`type="date"`。会員が前にこの年齢以上を確認した・このセッションで前に確認したなら出さず「ご年齢の確認は済んでいます」）と同意のチェック（`age[consent]`）。贈り物の App を使う店では、受け取る方の同意（`age[recipient]`。贈り物を選んだ時だけ見える。コアの CSS）。誤りは欄の下に |
| 最終確認（checkout-confirm） | `<x-storefront::age-check-summary :answer="$order->input->age" />` を注文の項目の後に | 「20 歳以上であることを確認しました（贈り物を受け取る方も）。」 |

- 自分で描くときは `$form->ageOffer()`（`AgeOffer`: `age`・`needsBirthdate`・`giftPossible`・`consent`・`recipient`・`notice()`・`consentText()`・`recipientText()`・`error('birthdate'|'consent'|'recipient')`・`minDate()`・`maxDate()`）を使う。名前は `age[birthdate]`・`age[consent]`・`age[recipient]` のまま。**生年月日の欄に値を戻さない**（`value` を空に）
- 見た目は注文の項目と同じ `.sugar-order-fields` の形（枠に `.sugar-age-check` を足す）と `.sugar-order-fields-summary`
- App の申し込みの画面（定期便など、カートを通さない注文）も同じ部品 `<x-storefront::age-check :offer="…" />` を置く（App が `AgeOffer` を作って渡す）
- 年齢の門（店の設定で使う時だけ）は App の画面（`/p/age-check/gate`）で、テーマの外枠の中に描く。テーマは何もしなくてよい

## ダウンロード販売・送らない品（ADR 0084。テーマ API 2 の付け足し）

App「ダウンロード販売」の商品（扱い「ダウンロード」）は、ふだんのカートに入る送らない品。送料の便に入らず、在庫の具合は「在庫あり」、入れられる数は 1。物と一緒のカートも通る。4 つの公式テーマは合わせてある。

- `CartView::needsShipping()`: 送る品があるか（今買える行に、送らない品でない行がある）。**偽なら注文の入力（checkout）でお届け先の欄（`data-ship-to` の fieldset）を出さない**（`@if ($cart->needsShipping())` で囲む）。サーバーは偽の時、お届け先・贈り物・お届け日時を捨てるので、囲んでいないテーマでも注文は通る（欄が無駄に出るだけ）。贈り物とお届け日時のコアの部品は、偽の時は自分で何も描かない
- 最終確認（checkout-confirm）: お届け先の所（`data-test="confirm-recipient"`）を `@if ($order->cart->needsShipping())` で囲む（ご依頼主はいつもどおり出す）
- `CartView::hasDigital()`: 送らない品が入っているか。カートの行（`$cart->lines` の各行）にも `digital`（真偽）がある。テーマが「ダウンロード」と添えたい時に使える（公式テーマは添えていない）
- ご注文の確認の画面とマイページの注文の履歴の「ダウンロード」は、App のご注文のリンク（上の「App のご注文のリンク」）としてコアの部品 `<x-storefront::order-links>` が出す。マイページの「ダウンロード」は会員の入口（`AccountLink`）としてコアが出す。テーマの手直しは要らない
- ダウンロードの画面（`/p/downloads/…`）は App の画面で、テーマの外枠（`layout`）の中に App が描く（`sugar-page`・`sugar-account` の印。会員の画面と同じ形）

## テーマがしてはいけないこと（ThemeTest が検査する）

- **DB やアプリの内部に触らない**（`DB::`・`::query(`・`App\`・`app(`・`@inject`・`@php`）。表示に要るものは店頭が渡す
- **エスケープしない出力（`{!! !!}`）を使わない**。店の名前・商品名・表記はすべて `{{ }}` で出す
- インラインの `<script>`・`style` 属性・`<style>`、外部の URL を使わない（店頭の CSP は自分のオリジンだけ）。JavaScript が要るときは別のファイルにする（公式テーマとサーバーに入れたテーマだけ。他社のテーマは持てない。ADR 0047）。`eval` を使う書き方は CSP で止まる
- 行き先の無いリンクを置かない（まだ無い画面へのリンクは、その画面ができてから）
- **テーマの JavaScript から個人情報の画面を fetch しない**（ADR 0088）。マイページ（`/account…`）・ログインのコードの画面・注文の入力と確認（`/checkout`・`/checkout/confirm`）・ご注文の状況（`/orders/…`）・App の会員の画面と申し込みの流れ（`/p/<App>/account…` など）は、ブラウザが画面を開く要求のほか（fetch・XHR・枠〔iframe〕）を中身の無い 403 で断る。ふつうの画面では App のタグも同じオリジンの JavaScript として動くので、区別せずに断る。リンク・フォームの送信（画面が移る）はそのまま使える。マイページの一部を画面を移らずに差し替えるような作りはできない（要るときはコアの部品に足す。コアの部品は、その画面にだけ書く画面の鍵 `data-sugar-page-key` で読む。全部の画面に渡す `$pageKey` はマークの付いた画面でだけ値があり、ほかは空。App の画面〔動く QR〕もこれを使う）

## デモの店のバナー（ADR 0086。テーマ API 2 の付け足し）

デモの店（CLI の `shop:demo <店> --on` で印を付けた店）では、ショップのどの画面のいちばん上にも「デモのお店です。実際の代金は動きません」のバナーを出す。バナーはコアの部品で、テーマは `layout` と `password` の `<body>` のすぐ後に 1 行置くだけ:

```blade
<body>
    <x-storefront::demo-banner />
```

- デモでない店では何も描かない（置いても見た目は変わらない）。公式の 4 つのテーマと見本のテーマは置いてある。自分で作るテーマも置く
- 中身はコアが決める: バナーの文・「送るはずだったメール」（`/demo/mails`）への入口・ログインしていない時の「デモの会員でログイン」（`POST /demo/member`）。どちらも個人情報の画面の印（`navigation-only`。ADR 0088）の経路なので、テーマの JavaScript から fetch しない（リンクとフォームでだけ使える）
- 見た目はコアの CSS（`resources/storefront/apps.css` の `.sugar-demo`）がテーマの色の変数（`--color-ink`・`--color-canvas`）で付ける。強さ 0（`:where()`）なので、テーマは `[data-sugar-demo]` の印で上書きできる。固定せず、本文を押し下げる形（小さな画面で本文を隠さない）
- デモの店では、どの画面と写真にも `X-Robots-Tag: noindex, nofollow` が付き、`<x-storefront::seo>` の robots も noindex になる（テーマは何もしなくてよい）
