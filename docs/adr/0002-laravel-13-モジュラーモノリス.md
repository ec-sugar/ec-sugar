# 0002 Laravel 13 のモジュラーモノリス

状態: 採用（2026-10-27）

## 文脈

一人で開発し、パッケージとして配布もする。配布先（日本の中小企業）のサーバーは PHP が多い。
TypeScript（Next.js ＋ Hono）も検討したが、配布先が Node 前提になる。

## 決定

Laravel 13 / PHP 8.3 以上のモジュラーモノリス。マイクロサービスにはしない。
開発機は PHP 8.5（Laravel 13 の対応範囲）。

## 結果

- 得たもの: 一人での開発速度、配布先の広さ、quickeats で得た Laravel の勘所の流用
- 失ったもの: React で店頭テーマを配る容易さ（ヘッドレスにしておき、後から別配布で補う）
- 期限: Laravel 13 はバグ修正 2027 Q3、セキュリティ修正 2028 Q1 まで。14 への追従を見込む

## 追記（2026-10-30）

- 求める PHP を **8.4.1 以上**に上げた（composer.json の `php` は `^8.4.1`）。カスタム区画の HTML の整え方（ADR 0041）が PHP 8.4 からの HTML5 の解析器（`Dom\HTMLDocument`）と、PHP 8.4.1 以上を求める symfony/html-sanitizer を使うため。Symfony 8 の部品（Laravel 13 が使う）も、もともと 8.4.1 以上を求めていた
- 開発機・本番のイメージ（`docker/Dockerfile` の `php:8.5-fpm-alpine`）・CI（setup-php の 8.5）はどれも 8.5 のまま。配布先で 8.3 は使えない
