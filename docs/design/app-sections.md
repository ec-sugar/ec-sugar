# App がセクションを足す（App を作る人向け）

App（プラグイン）がページの編集の画面にセクションの種類を足す書き方。決めたことと理由は [ADR 0040](../adr/0040-App-はページに区画を足せる.md)。見本は `plugins/plugin-video`（動画）と `plugins/plugin-google-map`（地図）。

- 店がその App を「使う」にしたときだけ、セクションを足す欄に出る（トップと自由なページ。決まった並びのテーマのトップには出ない）
- App を止めた店では、置けない・ショップに出ない・**値は消えない**（使い直すと元の位置に元の値で出る）
- お店の人は HTML も JavaScript も入れない。App も、セクションのために JavaScript と CSS を持ち込まない（見た目はコアの CSS、外の埋め込みはコアの部品）
- 外の埋め込み（動画・地図）は、同意を待たずに**誰にでも最初から枠を出す**（2026-10-30。ADR 0040 の 3）。防御は CSP の `frame-src`・`sandbox`・`allow`・宛先の確認で、送る内容は外部送信に関する公表に載せる

## 1. マニフェスト（composer.json の extra.ec-sugar.sections）

```json
"sections": {
    "app_video": {
        "label": "動画",
        "patterns": {
            "wide": { "label": "横いっぱい", "wireframe": "video-wide" },
            "narrow": { "label": "文の幅", "wireframe": "video-narrow" }
        },
        "fields": {
            "url": { "type": "text", "label": "動画の URL", "max": 200, "help": "YouTube か Vimeo の動画のページの URL を貼ってください" },
            "heading": { "type": "text", "label": "見出し", "max": 80 },
            "autoplay": { "type": "choice", "label": "自動で再生する", "options": { "off": "しない", "on": "する" }, "help": "…音を消すが自動で付きます…" },
            "mute": { "type": "choice", "label": "音を消す（ミュート）", "options": { "off": "しない", "on": "する" } },
            "controls": { "type": "choice", "label": "操作の表示（再生バーなど）", "options": { "show": "出す", "hide": "出さない" } },
            "loop": { "type": "choice", "label": "繰り返す（ループ）", "options": { "off": "しない", "on": "する" } }
        },
        "example": { "heading": "動画で見る" },
        "embed": {
            "frame-src": ["https://www.youtube-nocookie.com/embed/", "https://player.vimeo.com/video/"],
            "links": ["https://www.youtube.com/watch", "https://vimeo.com/"],
            "sandbox": ["allow-scripts", "allow-same-origin", "allow-presentation", "allow-popups", "allow-popups-to-escape-sandbox"],
            "allow": ["autoplay", "encrypted-media", "fullscreen", "picture-in-picture"]
        }
    }
}
```

- セクションを足す App は、目的（`extra.ec-sugar.category`）に `page`（ページの部品）を書く。管理画面の App の画面で「ページの部品」のまとまりに並ぶ（言葉の一覧は plugins/README.md の「App の目的」）
- **種類の名前**: `app_` ＋ App の名前（`-` は `_`）か、その後ろに `_` と英小文字・数字（App「google-map」なら `app_google_map`・`app_google_map_route`）。32 文字まで。ほかの App と重なると登録で止まる。1 つの App は 5 種類まで
- **セクションの宣言**（`label`・`patterns`・`fields`・`items`・`blocks`・`max_blocks`・`limit`・`example`・`example_blocks`・`wireframe`）は、テーマのセクションとまったく同じ書き方と決まり（[theme-api.md の「ページのセクションの並び」](theme-api.md)）。欄はコアの 7 種類（`text`・`textarea`・`image`・`link`・`products`・`categories`・`choice`）だけ。ワイヤーフレームの画像はコアの語彙から名前で選ぶ（足してほしいアイコンはコアへ）
- **`consent`**（任意）: このセクションを出すのに要る同意の種類の並び（`analytics`・`ads`）。書かなければ（か `[]`）誰にでも出す。書けば、同意バナーを出す店では、その種類に全部同意したお客さんにだけセクションを出し、同意バナーでも聞く（ほかの人にはセクションも枠も CSP の宛先も出さない）。バナーを出さない店（既定）では誰にでも出す。動画・地図は書かない（「動画・地図の表示」`embeds` の種類は 2026-10-30 に外した。書くと登録で断る）
- **`embed`**（外から埋め込むときだけ）:
  - `frame-src`（1 つ以上）: 枠に読み込んでよい宛先。形は ADR 0034 の CSP の宛先と同じ（`https://` ＋ 英小文字のホスト、パスで絞れる・`/` で終われば前方一致、そうでなければパスがちょうど同じときだけ。例: 地図は `https://www.google.com/maps` で `/maps?q=…` だけ）。そのセクションを出した画面の CSP の `frame-src` にだけ足す。パスに `.`・`..` の区切りや `%2e`・`%2f`・`%5c` のある URL は、コアが合わないとみなす
  - `links`: 外のサイトで開くリンクの宛先（同じ形。リンクを出さないなら `[]`）
  - `sandbox`: `allow-scripts`・`allow-same-origin`・`allow-presentation`・`allow-popups`・`allow-popups-to-escape-sandbox` の中から
  - `allow`: `autoplay`・`encrypted-media`・`fullscreen`・`picture-in-picture`・`clipboard-write`・`web-share` の中から。ハンドラーが枠ごとに絞れる（下の `Embed` の `allow`）ので、ここには使うかもしれない言葉を全部書く
  - 読み込む前のボタンの文字（`button`）は外した（押して読み込む形が無い。書くと登録で断る）
- 外から埋め込むセクションを持つ App は、`disclosure`（外部送信に関する公表。ADR 0034）を必ず書く。いつ・何が送られるか（「動画のセクションのあるページを表示したとき」）を書く
- App の設定（`settings`。ADR 0034）も使える。入れるべき設定（`required`）が欠けている間は、その App のセクションはショップに出ない（編集の画面では「設定を入れると出ます」）

## 2. セクションのハンドラー（AppSectionHandler）

外から埋め込むセクションを持つ App は、`PluginDefinition` の `sectionHandler` にハンドラーを渡す（無いと登録で止まる）。

```php
$plugins->register(new PluginDefinition(
    name: 'video',
    title: '動画',
    description: '…',
    pluginApi: 1,
    manifest: __DIR__.'/../composer.json',
    sectionHandler: new VideoSections,
));
```

```php
final class VideoSections implements AppSectionHandler
{
    // 保存のとき: 宣言どおりに確認した欄の値をもう一段確認する。断るなら 欄の名前 => 理由（422 の sections.3.values.url になる）
    public function problems(string $type, array $values): array { … }

    // 描くとき: 埋め込む URL を作る。出せなければ null（ショップには出さない）
    public function embed(string $type, array $values, array $settings): ?Embed
    {
        $autoplay = ($values['autoplay'] ?? null) === 'on';   // 選ぶ欄の値は決まった選択肢だけ。値の文字は URL に入れない

        return new Embed($src, '動画: '.$heading, link: $watchUrl, allow: $autoplay ? ['autoplay', 'fullscreen'] : ['fullscreen']);
    }
}
```

- 店の人の入れた値は、決まった形だけを受けて、そこから**取り出した部分だけで** URL を組み立てる（貼られた URL をそのまま枠に入れない）
- 返した `src` が `frame-src` に合わなければ、コアはセクションごと出さない。`link` が `links` に合わなければリンクを出さない
- `allow`（任意）: この枠に付ける言葉。コアは宣言の `allow` と重なる言葉だけを使う（宣言より広げられない）。渡さなければ宣言のまま
- ハンドラーは値を変えない・DB に触らない・外へ接続しない。`$settings` はショップに出してよい設定だけ（秘密の項目は入らない）

### 店のデータを並べるセクション（AppSectionContent。ADR 0072）

外から埋め込まず、App の表から読んだものを並べるセクション（「新着のお知らせ」など）は、ハンドラーに `App\Core\Plugin\Sections\AppSectionContent` も実装する。`embed()` は null を返す。

```php
public function content(string $type, array $values): ?NewsSection
{
    $items = app(BlogReader::class)->latest(3);   // ショップに出してよいものだけ（下書き・予約は入れない）

    return $items === [] ? null : new NewsSection($items, '/news');
}
```

- コアは描く前に呼び、`$section->app->content` に入れる。null なら、ショップではセクションを出さない（編集の画面のプレビューでは出す）
- 返すのは画面に出す形（文・パス・`ShopPhoto` など）だけ。モデル・個人情報は渡さない。テーマがセクションを描き直すときもこの形を読む
- ハンドラーが落ちてもページは止まらない（そのセクションを出さないだけ）。見本は `plugins/plugin-blog` の `BlogSections`

## 3. 描く Blade

パッケージの `resources/views/sections/{種類}.blade.php`（パターンごとに分けるなら `{種類}/{パターン}.blade.php`）。コアが `app-{App の名前}` の名前で読む。テーマが同じ名前の部品を置けば、テーマの方が勝つ。

```blade
<section class="sugar-section sugar-app-section" data-section="{{ $section->id }}" data-section-type="app_video" data-pattern="{{ $section->pattern }}" data-layout="{{ $section->pattern === 'narrow' ? 'narrow' : 'wide' }}">
    @if ($section['heading'])
        <h2 class="sugar-section__heading">{{ $section['heading'] }}</h2>
    @endif
    <x-storefront.embed :section="$section" :poster="$section['poster']" ratio="wide" />
</section>
```

- セクションの属性（`data-section` と `data-section-type`）は必ず付ける（編集の画面が押したセクションを知るため）
- 外の枠は**コアの部品 `<x-storefront.embed>` に任せる**。ショップでは最初から枠（`loading="lazy"`・`referrerpolicy="strict-origin"`）と、その下に「別のタブで開く」（`links` に合うときだけ）、編集の画面のプレビューでは外へ読み込まない箱（`poster` を渡せば写真、無ければ無地）を、コアが決めて描く。`ratio` は `wide`（16:9）・`standard`（4:3）・`square`・`tall`（9:16）・`panorama`（21:9。狭い画面では 16:9）
- 並べ方はコアの CSS の `sugar-app-section`（`data-layout` は `wide`・`narrow`・`split`）と、コアのセクションと同じ `sugar-section__heading`・`sugar-section__body` など
- テーマの部品と同じ決まり（`ThemeTest` が `plugins/*/resources/views` を検査する）: `{!! !!}`・`@php`・`<script>`・`style` 属性・外の URL の文字（`https://…`）を書かない。外の URL はハンドラーが作り、コアの部品が出す
- ショップでは、設定がそろって埋め込むものがあるときだけセクションが出る（`$section->app->visible()`）。空の箱は出ない
- **外から埋め込まないセクション**（2026-11-05、App「おすすめのセクション」。ADR 0071）: `embed` を書かないセクションは、ハンドラー（`sectionHandler`）も公表も要らず、設定がそろえばショップに出る。中身が空の時は Blade が何も描かない（プレビュー〔`$section->app->preview`〕では形と案内の一言を出すとよい）。店のデータ（商品など）が要るときは、Blade で DB に触らず（`ThemeTest` が断る）、App のサービスプロバイダで `View::composer('*::sections.{種類の頭}_*', …)` を登録して部品に渡す（1 つの形で App の部品とテーマが描き直す同じ名前の部品の両方に当たる。`app-{名前}::sections.*` と並べると App の部品で 2 度動く）。セクションの中の共通の部品は `sections/` の外に置く（composer がもう一度動かないように。例 `app-recommendations::cards`）

## 4. JavaScript が要るとき

セクションのために JavaScript を持ち込む口は無い。枠はコアの部品がサーバーで描く（押して読み込むコアの JavaScript は 2026-10-30 に外した）。どうしても要る App は ADR 0034 の `storefront.scripts`（パッケージの `assets/` の同じオリジンのファイル。審査済みの App だけ、ふつうの画面だけ。同意バナーを出す店では同意のある人だけ）を使う。SaaS とマーケットの他社の App は持てない（ADR 0020）。

## 5. テスト

- マニフェストがコアの確認を通る（`app(PluginRegistry::class)->get('名前')->app()->sections`）
- ハンドラーが受ける形と断る形（別のホスト・`javascript:`・似せたホスト・利用者名やポート）
- 店で使っていないと置けない（422）、最初から枠があり属性（src・sandbox・allow・referrerpolicy・loading）が正しい、CSP の `frame-src` はセクションのある画面だけ、選ぶ欄の知らない値は断る（422）
- 見本: `tests/Feature/Storefront/AppSectionsTest.php`・`tests/Feature/Plugin/AppSectionManifestTest.php`・`tests/e2e/app-embeds.spec.js`
