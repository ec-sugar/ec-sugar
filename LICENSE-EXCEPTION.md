# EC-SUGAR Extension Exception, Version 1.0

> **Draft (2026-11-12).** The wording has not been reviewed by a lawyer. The English text below is the exception; the Japanese explanation after it is informational only and does not change its meaning.
>
> **下書き（2026-11-12）。** 専門家の確認はまだ。効力を持つのは下の英語の条文で、その後ろの日本語の説明は読むための手引き（意味を変えない）。

Copyright (C) 2026 Fivenine design

This exception is an additional permission under Section 7 of the GNU Affero General Public License, version 3 (the "AGPLv3"). It applies to EC-SUGAR, which is licensed under the AGPLv3 (see the file `LICENSE`).

## 0. Purpose

EC-SUGAR is built so that themes and apps can be added through documented interfaces. The purpose of this exception is to let the authors of such themes and apps choose any license for their own work, including proprietary and paid terms, while EC-SUGAR itself, and every modification of it, stays under the AGPLv3.

## 1. Definitions

"**EC-SUGAR**" means the work that the copyright holders release under the AGPLv3 together with this exception, including its core and the apps bundled with it that are licensed under the AGPLv3. Parts of the distribution that are offered under another license (for example, the official themes and the sample app under the MIT License) are governed by that license.

"**Extension**" means a theme or an app (plugin) for EC-SUGAR, made of files that are not part of EC-SUGAR.

"**Interface Documentation**" means the following documents, as published with the version of EC-SUGAR with which an Extension is used or with any earlier version, and any successor documents that the copyright holders designate as describing the interfaces for Extensions:

- (a) `docs/design/theme-api.md` (the Theme API);
- (b) `plugins/README.md`, `docs/development/making-apps.md`, `docs/design/app-sections.md` and `docs/design/tag-apps.md` (the Plugin API);
- (c) the table of promised interfaces in section 1 of Architecture Decision Record 0093 (`docs/adr/0093-*.md`) and the documents it refers to.

"**Scaffold Output**" means files that the scaffolding commands of EC-SUGAR (such as `php artisan make:plugin`) generate from the templates included in EC-SUGAR, and modified versions of such files.

"**Reference Extensions**" means the official themes (`plugins/theme-*`) and the sample app (`plugins/plugin-faq`) that the copyright holders distribute under the MIT License.

"**Public Interfaces**" means:

- (a) the interfaces described in the Interface Documentation, such as the theme and plugin manifests, `theme.json`, the views a theme provides and the data, components, attributes and CSS variables that EC-SUGAR passes to or reads from a theme, the registration of an app (`PluginRegistry::register()` and `PluginDefinition`), the contracts in the `App\Core\Plugin\Contracts` namespace, app sections and admin pages, route placement and route markers, the security helpers, and event receivers; and
- (b) any class, method, function, view, configuration key, route marker, event name or other element of EC-SUGAR that is referenced by unmodified Scaffold Output or by the Reference Extensions, as published with the version of EC-SUGAR with which an Extension is used, used in the way they use it.

"**Independent Extension**" means an Extension that:

- (a) interacts with EC-SUGAR only through the Public Interfaces; and
- (b) does not contain any part of EC-SUGAR, except (i) names, identifiers, signatures and short declarations needed to use the Public Interfaces, (ii) Scaffold Output, and (iii) material that the copyright holders make available under a license other than the AGPLv3 (such as the Reference Extensions), used under that license.

An Extension is not an Independent Extension if it modifies files of EC-SUGAR, replaces or changes the behavior of parts of EC-SUGAR that are not Public Interfaces (for example, by rebinding its internal services or patching its classes), or reads or writes database tables of EC-SUGAR other than through the Public Interfaces.

## 2. Permission for Independent Extensions

As an additional permission under Section 7 of the AGPLv3, the copyright holders of EC-SUGAR give you permission to create, copy, modify, use, convey and make available to users over a network an Independent Extension, alone or combined with EC-SUGAR, under license terms of your choice, provided that you comply with the AGPLv3 for EC-SUGAR itself.

In particular, the terms of the AGPLv3 do not apply to an Independent Extension because it is used or conveyed together with EC-SUGAR, and the Corresponding Source of EC-SUGAR, or of a combination of EC-SUGAR and one or more Independent Extensions, does not need to include the source code of an Independent Extension.

## 3. Permission for Scaffold Output

You may use, modify, convey and make available to users over a network Scaffold Output under license terms of your choice, without the conditions of the AGPLv3. This permission does not extend to the scaffolding commands or their templates as part of EC-SUGAR.

## 4. What this exception does not cover

- EC-SUGAR itself and every modification of it, including changes made to its files by an Extension, an installer or a deployment script, remain under the AGPLv3. If you modify EC-SUGAR and let users interact with it over a network, Section 13 of the AGPLv3 applies to your modified version of EC-SUGAR.
- An Extension that is not an Independent Extension is not covered by this exception. Whether and how the AGPLv3 applies to it is decided by the AGPLv3 and applicable law.
- This exception does not grant any right to use the names, logos or trademarks of EC-SUGAR. An Extension may state in a factual way that it is made for EC-SUGAR.
- This exception does not change the availability of EC-SUGAR under a separate commercial license from the copyright holders.

## 5. Modified versions of EC-SUGAR

If you modify EC-SUGAR, you may extend this exception to your modified version, but you are not obliged to do so. If you do not wish to do so, delete this exception from your version.

## 6. Revised versions of this exception

The copyright holders may publish revised versions of this exception for later releases of EC-SUGAR. Each version has a distinguishing version number. A revised version does not withdraw the permission that an earlier version gives for the releases of EC-SUGAR distributed with that earlier version.

---

## 日本語の説明（手引き。効力は上の英語の条文）

### 要点

- EC-SUGAR の核は **AGPL v3**（`LICENSE`）。改造して配る・改造したものをネットで貸すなら、改造した核のソースを出す
- そのうえで **公開の口だけを使うテーマと App（「独立した拡張」）は、作った人が好きなライセンスで配れる**（有料・非公開を含む）。核と一緒に配っても、核と組み合わせてネットで貸しても、その拡張のソースを出す義務は無い
- 公式のひな形（`php artisan make:plugin`）が出したコードも、出した人が好きなライセンスにしてよい
- これは AGPL v3 の **7 条の「追加の許可」**として書いた。AGPL を狭める条件ではなく、許すことを足すだけ。核を改造した人は、この許可を自分の版に残しても消してもよい（5）

### 「公開の口」とは

条文の 1 の「Public Interfaces」。次の 2 つの合わせたもの。

1. **文書に挙げた口**: テーマ API（[docs/design/theme-api.md](docs/design/theme-api.md)）、プラグイン API（[plugins/README.md](plugins/README.md)・[docs/development/making-apps.md](docs/development/making-apps.md)・[docs/design/app-sections.md](docs/design/app-sections.md)・[docs/design/tag-apps.md](docs/design/tag-apps.md)）、[ADR 0093](docs/adr/0093-外に約束する口を決め、壊すときはAPIのバージョンを上げてCHANGELOGで知らせる.md) の 1 の「約束するもの」の表
2. **公式のひな形が出すコードと、MIT の見本（公式テーマ 4 つ・見本の App「よくある質問」）が使っている核の部品**（同じ使い方で）。たとえば `EventStore::lock()`・`StorefrontView`・管理画面の App の画面の部品・見本が読む商品のモデル。ひな形や見本をまねて作った人が、気づかずに例外の外に出ないようにするため

文書は、その拡張を使う EC-SUGAR のバージョンに付いたものか、それより前のバージョンのもの。後のバージョンで口が減っても、前のバージョンで公開だった口を使う拡張は、そのバージョンとの組み合わせでは例外の中にいる。

### 例外に入る・入らない

| 入る（作った人が好きなライセンスで配れる） | 入らない（AGPL の決まりのまま考える） |
|---|---|
| `theme.json` と Blade の画面と CSS だけで作ったテーマ | 核のファイル（`app/` など）を書き換える・差分を当てる |
| `PluginRegistry::register()` で登録し、App の区画・管理画面の App の画面・受け手（`EventReceivers`）・核の守りの部品を使う App | 核の中のサービスをサービスコンテナで差し替える・核のクラスを書き換えて動きを変える |
| ひな形から作り、核の部品をひな形と同じ使い方で使う App | 核の DB の表を直に読む・書く（公開の口を通さない） |
| 公式テーマ（MIT）を写して作り直したテーマ（MIT の表示を残す） | 核のコードを写して自分の App に入れる（MIT の部分を除く） |

入らないものも、すぐに「AGPL になる」と決まるわけではない（それは AGPL と法律で決まる）。この例外が約束しない、というだけ。

### 参考にしたもの

- **GCC Runtime Library Exception**: 「決まった口を通した組み合わせ（Eligible Compilation Process）なら、できたものを好きな条件で配れる」という形。何が「決まった口」かを定義で指す書き方を借りた
- **GNU Classpath Exception**: 「独立したモジュール（このライブラリから派生しない・基づかないもの）とつなぐことを許し、改造した人はこの例外を自分の版に残しても消してもよい」という形。5 の文はこれにならった
- **WordPress のテーマとプラグインの議論**: WordPress は GPL で、有料のテーマやプラグインも GPL の派生物かが長く争われ、はっきりした例外が無いまま「PHP は GPL、画像と CSS は別」のような分け方で落ち着いた。EC-SUGAR は最初から例外を書いて、有料の縦の App と他社の App・テーマが迷わず作れるようにする（ADR 0033 の 2）

### 関係するもの

- 核のライセンス: [LICENSE](LICENSE)（AGPL v3 の全文、書き換えない）
- 配る中身の部品とライセンス: [NOTICE](NOTICE)
- 決めたこと: [ADR 0033](docs/adr/0033-ライセンスはAGPLv3とプラグイン・テーマの例外と商用の二重ライセンス.md)
- AGPL を避けたい会社向けの商用ライセンス（二重ライセンス）は、この例外とは別に出す（条件は決める途中）
