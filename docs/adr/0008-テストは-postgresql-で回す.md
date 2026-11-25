# 0008 テストは PostgreSQL で回す

状態: 採用（2026-10-27）

## 文脈

Laravel の既定はテストを SQLite のメモリ DB で回す。しかし本製品の要はテナントごとの DB 分割で、
SQLite ではテナント DB の作成がファイル生成に置き換わり、本物の挙動（CREATE DATABASE が
トランザクション内で動かない等）を検証できない。実際、ローカルは通るのに CI だけ落ちた。

## 決定

ローカルも CI も PostgreSQL でテストする。開発用 compose の db に `ec_test` を持ち、
テスト用テナント DB は `ec_test_t_` プレフィックスで分離する。
テナントを作るテストは RefreshDatabase（トランザクション）ではなく DatabaseTruncation を使う。

## 結果

- 得たもの: テストがテナント DB の作成・切り替え・削除を本物で検証する。ローカルと CI の挙動が一致する
- 失ったもの: テストに Docker の db が必要。SQLite より遅い（現状 1 秒未満）
