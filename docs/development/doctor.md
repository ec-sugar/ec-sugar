# 開発の環境の診断と、作業ごとのテストの環境

2026-11-06 に足した（外の提案「環境の不調とコードの不具合を切り分けやすく」）。同じ日に、Mac のディスクが満杯になって手元の
Postgres が止まった・OrbStack が数分応えなくなった・前の回が残したテストの DB とロールのぶつかりで「Tenant cannot be created」が出た・
管理画面のビルドが古いままで画面が欠けた・worktree で vendor をリンクで借りて phpstan が誤って落ちた、が起きた。
どれもコードの不具合に見えて、原因を探すのに時間を使った。**テストや画面がおかしいと思ったら、コードを疑う前に `make doctor`。**

スクリプトは `ops/dev/`（`doctor.sh`・`test-work.sh`・共通の `lib.sh`）と、ファイルを比べる所の `tests/Support/DevDoctor.php`。
Mac の `/bin/bash`（3.2）でも Linux（CI）でも動く書き方にしてある（決まりは `ops/dev/lib.sh` の頭。`tests/Unit/ShellScriptsTest.php` も監視する）。

## make doctor（読むだけ）

```sh
make doctor
DOCTOR_DISK_WARN_GB=10 DOCTOR_DISK_NG_GB=5 make doctor   # 空きの閾値（GB。既定は 10 で注意、5 で NG）
```

1 行ずつ `ok`・`注意`・`NG` で出し、注意と NG には直し方の 1 行（`→`）を添える。**NG が 1 つでもあれば終わりの値は 1**（`bash ops/dev/doctor.sh` の値。`make doctor` は make の決まりで、落ちたら 2 を返す）。
何も変えない・消さない: ファイルは読むだけ、DB への問い合わせは読むだけの取引（`default_transaction_read_only`）で実行し、Docker はコンテナの
一覧と状態を読むのと、db コンテナの中で `df` と `psql` を実行するだけ（起動・停止・作り直しをしない）。直し方の行は案内で、ここでは実行しない。

| 段 | 見ること | よくある直し方 |
|---|---|---|
| ディスク | この作業ツリーのあるディスクの空き。10 GB を切ったら注意、5 GB で NG | 大きなものを消す。Docker は `docker system df` で見る |
| Docker | 10 秒以内に応えるか（遅いと注意）・開発のコンテナ（app・db・redis・web・queue・scheduler）が動いていて不調でないか・前の名前（sugar-ec）のコンテナ・開発の画面（:8080）がどの作業ツリーのコードで動いているか・Docker のデータの空き（db コンテナから見た空き。閾値は上と同じ） | `make up`、`docker logs --tail 50 <コンテナ>`。固まっていたら、ほかの作業のテストが終わるのを待つ（OrbStack の再起動はほかの作業も止める） |
| PHP と依存 | PHP の版と拡張が本番のイメージとそろうか（`EnvironmentParityTest` と同じ決まり。一覧は `DevDoctor::EXTENSIONS`）・vendor が composer.lock と同じか（`vendor/composer/installed.json` と lock の名前・版・参照を比べる）・vendor がリンクでないか・`composer validate`・App とテーマ（`plugins/*`）が composer.json・lock・vendor・Laravel のキャッシュ（`bootstrap/cache/packages.php`）にそろい、`vendor/ec-sugar/<名前>` がこの保存先の `plugins/` を指すか・node_modules が package-lock.json と同じか（npm のキャッシュ `node_modules/.package-lock.json` と比べる。ほかの OS 向けの任意の依存は除く）。worktree から実行したときは、開発の画面のコードの vendor と App も見る | `composer install --no-interaction`、`rm vendor && composer install`（リンクだけを消す）、`php artisan package:discover`、`npm ci --ignore-scripts` |
| キャッシュとビルド | 設定のキャッシュ（`bootstrap/cache/config.php`。あると NG）・経路とイベントのキャッシュ・パッケージのキャッシュが vendor より古くないか・`public/hot`（Vite の開発サーバーの印）・画面のビルド（`public/build/manifest.json`）がソース（`resources/admin`・`resources/storefront`・テーマの CSS・`vite.config.js`・package-lock.json）より古くないか（古ければ NG、テーマの Blade だけが新しければ注意） | `php artisan config:clear` など、`make front`（作っている間は `make watch`） |
| DB | 中央の DB に入れるか・手元から 127.0.0.1:54329（PHP のテストの入り口）と Redis の 63799 に届くか・接続の数（8 割を超えたら注意）・中央と開発の店の DB のマイグレーションの実行し忘れ（開発の画面が使う作業ツリーのマイグレーションのファイルと、各 DB の `migrations` 表を比べる）・テストの店の DB とロールの残り（印ごとに数え、`make test-clean TEST_WORK=<印>` の行を出す。いま接続のある DB はそう書く）・テストの DB の大きさ（合わせて 5 GB を超えたら注意） | `make migrate`、`make test-clean TEST_WORK=<印>`（その作業のテストが流れていないことを確認してから） |
| .env | 無い（本体なら NG、worktree なら ok）・APP_KEY が空・APP_ENV が production・DB_URL が入っている・APP_URL と APP_DEBUG が CI（`http://localhost`・`false`）と違う（素の `php artisan test` の結果が CI と変わるので注意）・シェルに `DB_DATABASE` などが入ったまま | `make setup`、テストは `make test-work` で |

出すのは秘密でない値（APP_ENV・APP_DEBUG・APP_URL・DB の名前）だけで、鍵とパスフレーズは出さない。

## 作業ごとのテストの環境（make test-env・make test-work・make test-clean）

作業を並べて進めるとき、ほかの作業とぶつからない自分の DB と写真の保存先でテストを実行するためのもの。印（`TEST_WORK`）は英小文字と数字の 1 区切りで、
`t` と、`p<数字>` で終わるもの（`p3`・`ap1`）は使えない（`ec_test_p3` は既定の `make test` の並べた 3 番目、`ec_test_ap1` は印 `a` の並べた 1 番目の DB と同じ名前になる）。

```sh
# 1 ファイルずつ実行する（フォルダは中の *Test.php を名前の順に）。FILTER でテストの名前を絞る、STOP=1 で最初に落ちたところで止める、ARGS で phpunit の引数を足す
make test-work TEST_WORK=dr FILES="tests/Feature/Admin/ProductsTest.php tests/Unit"

# 自分のシェルで実行したいとき。環境変数の行を出すので eval で入る（その後は php artisan test --compact <ファイル>）
eval "$(make -s test-env TEST_WORK=dr)"

# 使い終わったら（店の DB・ロール・型を消す。中央の DB ec_test_dr は残る）
make test-clean TEST_WORK=dr
```

- `test-env` は中央のテスト DB `ec_test_<印>` が無ければ作り（持ち主は ec_app）、写真の保存先 `storage/app/media-testing-<印>` を作る。
  店の DB の型（`ec_test_<印>_t__template`。ADR 0057）は、最初に店を作るテストが作る（そのテストだけ数十秒長くなる）
- 渡す環境変数は、引き継ぎの文に毎回書いていたものと同じ: `DB_DATABASE=ec_test_<印> TENANT_DB_PREFIX=ec_test_<印>_t_ MEDIA_DIRECTORY=media-testing-<印>`
  （組み合わせが合わないとテストの起動時のチェック `tests/Support/TestEnvironmentGuard` が止める）、`APP_ENV=testing`、
  `APP_URL=http://localhost APP_DEBUG=false`（CI と同じ。手元の .env の `http://localhost:8080`・`true` が入ると結果が変わる）、
  `APP_KEY` は回ごとに作る使い捨ての鍵（worktree には .env が無く、店を作るテストが鍵の無さで落ちる。鍵は画面に出さない。本体の .env をコピーしない）。
  シェルに残った `DB_URL`・`TEST_TOKEN`・`APP_CONFIG_CACHE` は外して実行する
- `test-work` は各ファイルの前に、その印の店の DB とロール（`ec_test_<印>_t_*`・並べた各々の `ec_test_<印>p<番号>_t_*`）のうち型でないものを消す。
  前のファイルが落ちて店を残すと、同じ店の ID をまた作るテストが「Tenant cannot be created」で落ちるため。型・中央の DB・開発の DB・ほかの印のものには触らない
- 終わりに、通った・落ちた・見つからないファイルの数と、落ちたファイルの名前を出す。落ちた・見つからないものがあれば終わりの値は 1
- PHP のテストを並列で実行する（`php artisan test --parallel`）ときは、今までどおり環境変数を渡して実行する（`test-work` は 1 ファイルずつ順に）
