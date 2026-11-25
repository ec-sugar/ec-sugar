# 入れたときのサンプルの写真

入れたときのサンプル（`php artisan shop:sample`・ADR 0053）の写真について。中身（商品・テーマの中身など）は核の `app/Core/Sample/` のセットにあります。

## 同梱する写真（雑貨のお店だけ）

- `zakka/` — 雑貨のお店（`ZakkaSamples`）の写真。自作の絵（SVG）を WebP にしたもので、権利の心配が無いのでリポジトリに入れて配ります。作り直しは `zakka/render.sh`（開発の機械で手で流す）
- 写真の名前は英小文字・数字・ハイフンだけ。セットは名前で指し、`<名前>.webp`（ほかに `.jpg`・`.jpeg`・`.png`）を読みます

## 入れる時に取る写真（お菓子・布もの・植物）

ほかのセット（`SweetsSamples`・`FashionSamples`・`BotanicaSamples`）の写真は配る物に入れず、入れる時に Unsplash から取ります（`SamplePhotos`）。出どころはセットの `PHOTOS` に、写真の名前ごとに書きます。

```php
public const array PHOTOS = [
    // 新しい形: Unsplash の写真の ID（写真のページの URL の最後の部分）。https://unsplash.com/photos/<ID>/download?force=true&w=2000 から取る
    'hero' => ['id' => 'z8kriatLFdA', 'author' => 'Vyshnavi Bisani', 'page' => 'https://unsplash.com/photos/brown-round-cookie-on-white-surface-z8kriatLFdA'],
    // 前の形: images.unsplash.com の URL（クエリは付けない。?w=2000&fm=jpg&q=80 を付けて取る）
    'pot' => ['url' => 'https://images.unsplash.com/photo-1485955900006-10f4d324d411', 'author' => null, 'page' => null],
];
```

- `author`（撮った人）と `page`（写真のページ）は、分かれば必ず書きます。商品の説明の終わりの一行（「写真: …（Unsplash）」）と、店のサンプルの印に残ります
- 写真を足したり替えたりしたら、取れるかを確かめます（`php artisan tinker` で `app(App\Core\Sample\SamplePhotos::class)->fetch(…, <空のフォルダ>)` が空の配列を返す）
- 写真の数はセットごとに、写真のアップロードの 1 分の上限（30 枚）の内に収めます（超えても待ってから上げますが、入れるのが遅くなります）
- 開発の見本の店（`SugarShopSeeder`・`DemoTenantSeeder`・`BotanicaShopSeeder`）は、同じ名前の写真を手元の `storage/app/demo/<sugar|samples|botanica>/<名前>.jpg` から読みます（git の外。取りに行かない）。同じことは `shop:sample <店> --set=… --photos=<フォルダ>` でもできます
