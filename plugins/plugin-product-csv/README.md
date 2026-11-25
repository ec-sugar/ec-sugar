# 商品の CSV（EC-SUGAR の App）

商品・種類・在庫・写真を CSV で書き出し、同じ形の CSV（とほかのサービスの形）を取り込んで、まとめて足す・直す App です。
ほかのサービスから移る店の入口で、形と決まりは [docs/design/product-csv.md](../../docs/design/product-csv.md)、店の人向けの使い方は管理画面のヘルプ（「商品の登録」の「CSV でまとめて登録・書き出し」）にあります。

元は核（`app/Core/ProductCsv`）にありました。配送の CSV（`plugin-shipping-csv`）と同じ形の機能なのに居場所が違ったので、2026-11-18 に App へ出しました（ADR 0107 の段 1）。**動かしたのは居場所と名前空間だけ**で、画面・API・CSV の形・DB の形は変えていません。

## 使い方

1. 管理画面の店のメニュー「App」から「商品の CSV」を開き、「使う」にします（運用の段階・直前の再確認）。更新で入った店と、これから作る店では最初から「使う」になっています（今までどおり使えるように。下の「移行」）。
2. メニューの「商品」の右上の「CSV で書き出す」で今の商品を書き出し、「CSV で取り込む」で CSV（と写真の ZIP）を上げます。
3. 確かめの結果（足す・直す・変わらない・誤りの行）を見て、「取り込む」で反映します。

## ほかの App と違うところ

- **画面と受け口は核のまま**です（`app/Admin/Http/Controllers/Api/ListingCsvController.php`・`resources/admin/pages/ListingImportPage.vue`）。ファイルを上げる・確かめの画面を挟む・ファイルを返すは、App の画面の口（ADR 0051 の決まった型の値と欄）では描けないため（ADR 0036 の「App でなく核の画面にした」）。核の経路は使う店の印（`plugin:product-csv`）を通るので、**止めた店では画面も書き出しも 404** になります
- **記録の表（`product_imports`）は核の移行のまま**です（`database/migrations/tenant/2026_11_04_000500_create_product_imports_table.php`・`2026_11_05_000700_add_photo_import_columns.php`）。ADR 0107 の段 1 は DB の形を変えないので、App の頭で始まる表に移していません
- App 自身の設定・ショップの経路・差し込みはありません

## 作り

| ファイル | 役 |
|---|---|
| `src/ProductCsvReader.php` | 上げた CSV を読む（文字コード・見出しから形を決める・上限） |
| `src/ProductCsvFormat.php` | CSV の形（見出しの名前と値の呼び名。ほかのサービスの形を足す所） |
| `src/ProductCsvRows.php` | 1 行の値を確かめて決まった形にする（DB は見ない） |
| `src/ProductCsvGroups.php` | 行を商品ごとにまとめる |
| `src/ProductCsvImporter.php` | 確かめと反映（商品・種類・在庫・カテゴリ・オプション。書くのは核のサービスだけ） |
| `src/ProductCsvCatalog.php` | 書き出しと取り込みで同じに読む値（オプションの形・在庫の拠点） |
| `src/ProductCsvExporter.php` | 書き出し |
| `src/ProductCsvPhotos.php`・`src/PhotoArchive.php`・`src/ProductImportPhotos.php` | 写真（値の確かめ・ZIP・反映の写真の段。外へ取りに行くのは核の `Media\RemoteImages`） |
| `src/ProductImports.php` | 取り込みの記録を進める（状態・進み具合・片付け） |
| `src/Jobs/` | 確かめ・反映のキューの仕事 |
| `src/Models/ProductImport.php` | 取り込みの記録（核の表 `product_imports`） |
| `src/ImportRefused.php`・`src/RowRejected.php`・`src/PhotoSkipped.php`・`src/ProductImportProgressed.php` | 断りと行の誤り・写真を飛ばす・進んだ知らせ |

CSV の欄の差し込みよけ（`CsvCells`）は核に残しました（`app/Core/Csv/CsvCells.php`）。売上の集計の CSV（核の管理画面）も同じ決まりで書き出すためです。

テストは `tests/Feature/Admin/ListingCsvTest.php`・`tests/Feature/Admin/ListingCsvPhotosTest.php`（書き出しと取り込み・写真・止めた店では 404）。

## 移行

`database/migrations/tenant/2026_11_18_100000_enable_product_csv_app.php` が、店ごとに「使う」の行を足します（すでに行のある店はそのまま）。核にあった機能が App になっても、今まで使えていた店で画面が消えないようにするためです。止めたい店は「App」の画面で止められます。
