# セキュリティポリシー

EC-SUGAR の脆弱性を見つけたら、**公開の Issue・プルリクエスト・SNS には書かず**、下の窓口に知らせてください。

## 報告の窓口

窓口は GitHub の非公開の報告（Private vulnerability reporting）だけです。このリポジトリの「Security」タブ →「Report a vulnerability」から送ってください。やり取りは報告者と私たちだけに見えます。メールでは受けていません。

報告には次を入れてください。

- 再現の手順（リクエストの例・画面の操作・設定）
- 影響（どの店・どのデータ・どの権限に届くか。例: ほかの店のデータが読める、ログインせずに注文が変えられる）
- 確かめたバージョン（`ec-sugar status` の表示か、タグ・コミット）と、動かした形（1 店舗・複数の店・Cloudflare Tunnel など）

試すのは、自分で入れた EC-SUGAR だけにしてください。公式の店・デモの店・ほかの人の店で試したり、データを持ち出したり、サービスを止めるほど多くの要求を送ったりしないでください。

## 受けてからの流れ

| 段 | 目安 |
|---|---|
| 受け取った返事 | 3 営業日以内 |
| 最初の見立て（再現できたか・深刻さ・対象のバージョン） | 7 日以内 |
| 直したバージョンを出す | 深刻さで: 重大 7 日・高 30 日・中 60 日・低 90 日を目安に |
| 公表 | 直したバージョンを出した後 |

- 深刻さは CVSS v3.1 / v4.0 を目安に、店の境界（ほかの店のデータに届くか）・お金・個人情報への影響を重く見て決めます
- 直しはパッチのバージョン（例 1.0.1）で、署名つきのリリースとして出します。セキュリティの直しは既定で自動で入れる決まりです（ただし署名つきの更新の仕組みが入るまでは、自動の更新は既定で切ってあります。[docs/install.md](docs/install.md) の「自動の更新と、失敗したときのロールバック」）
- **公表**は、直したバージョンを出した後に、GitHub Security Advisory と [CHANGELOG.md](CHANGELOG.md) の「守り」で行います。必要なら CVE の番号を GitHub に頼みます。直す前の弱点の詳しい中身と攻め方は書きません
- 公表の日は報告者と相談して決め、報告から 90 日を上限にします。すでに悪用されているときは早めます
- 報告者が望めば、公表のときにお名前（ハンドル名）を載せます

善意の報告者（上の決まりを守って試し、知らせてくれた人）に対して、法的な措置は取りません。

## 対象のバージョン

| バージョン | セキュリティの直し |
|---|---|
| 最新のメジャーの、最新のマイナー（例 1.2.x） | 出す |
| 1 つ前のメジャーの、最新のマイナー | 出す |
| それより古いもの・タグの付いていない開発中のもの | 出さない（最新へ上げてください） |

同じメジャーの古いマイナーで見つかったときは、最新のマイナーで直します（同じメジャーの中は上げても壊れない約束です。[ADR 0093](docs/adr/0093-外に約束する口を決め、壊すときはAPIのバージョンを上げてCHANGELOGで知らせる.md)）。

## 対象の範囲

対象:

- EC-SUGAR の核（管理画面・ショップ・API・決済の受付）と、同梱の App とテーマ（`plugins/`）
- インストーラー（`deploy/install.sh`）・`ec-sugar` コマンド・公式のコンテナのイメージ

対象の外（ここでは受けません）:

- 他の人が作った App とテーマ（作った人へ）
- 入れた人のサーバーや Cloudflare などの設定の誤り、Stripe などつなぐ先のサービスそのもの
- 量の多い要求でサービスを止めるもの（回数の上限をすり抜けるものは対象）、人をだます手口、物理的に触れる必要のあるもの

## 設計上の約束（抜粋）

- 管理画面からコードを入れられない。テーマと App は composer / CLI だけ
- Web のプロセスはコードを書き換えられない（読み取り専用のイメージ）
- カード情報に触れない（決済代行のトークン化）
- 店ごとに DB とロールを分け、1 つの店の資格情報ではほかの店の DB に入れない
- 個人情報を既定で外の LLM に送らない

全体は [docs/CONCEPT.md](docs/CONCEPT.md) の 7.2 と [docs/security/threat-model.md](docs/security/threat-model.md)。

## In English

Please report vulnerabilities privately, not in public issues: use GitHub's **Private vulnerability reporting** (this repository's "Security" tab → "Report a vulnerability"). This is the only reporting channel; we do not accept reports by email. We aim to acknowledge within 3 business days, give a first assessment within 7 days, and release a fix as a signed patch release before publishing an advisory (at most 90 days after the report, coordinated with the reporter). Please test only against your own installation. We will not take legal action against good-faith reporters who follow this policy.
