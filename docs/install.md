# EC-SUGAR を入れる

まっさらな Ubuntu のサーバー 1 台に EC-SUGAR を入れる手順書です（[ADR 0030](adr/0030-SUGAR-ECはインストーラーで入れる.md)・[ADR 0031](adr/0031-入れてから管理画面に入るまではウィザードで、証明書はCaddyが取る.md)）。
サーバーに入れるのはインストーラー（`deploy/install.sh`）の端末のウィザードが行います。この手順書は、**インストーラーが何をするかを、インストーラーと同じ順で**書いたものです。手で 1 つずつ辿る手順書ではありません（スクリプトが正本）。

開発の環境のステージング（VM）も、本番のサーバーも、ソースを受け取ったエンジニアも、同じ道で入れます。

## 入れる人がすることは 3 つ

1. **DNS に 2 行を足す**: 管理画面の名前（例 `admin.example.jp`）と、**ショップのホスト**（自分のドメイン。例 `example.jp` か `www.example.jp`）の A レコードを、サーバーの公開の IPv4 に向ける
2. **サーバーで `sudo ./deploy/install.sh` を実行**、端末のウィザードに答える（5 ステップ。下の「ウィザードの 5 ステップ」）
3. **最後に出るアドレスをブラウザで開き**、ブラウザのウィザードに沿ってパスキーを登録する

> **外から 80・443 番に届かない所に置くとき**（社内のネットワーク・自宅のサーバー・Cloudflare Tunnel で出す形・`MODE=staging`）は、証明書を自動で取れません。先に下の「[証明書の取り方](#証明書の取り方)」を読んで、`dns`（Cloudflare の API の鍵）か `manual`（手で用意した証明書）を設定ファイルに書いてください。何も書かずに実行すると 2/5 で止まります。

```sh
# git が無ければ（クラウドの Ubuntu にはたいてい入っている）
sudo apt-get update && sudo apt-get install -y git

# リポジトリを取る（/srv/ec-sugar の外。ホームでよい）。入れるバージョンのタグを取り出す
git clone https://github.com/ec-sugar/ec-sugar.git ~/ec-sugar
cd ~/ec-sugar
git checkout v1.0.0   # 公開版は、人の鍵で署名したバージョンのタグを取り出して入れる（下の「タグの署名の鍵」）

# 公開版: 公式サイトで配っている「タグの署名の鍵の一覧」（allowed_signers）をサーバーに置く（指紋は 2/5 で公式サイトの値と比べる）
sudo install -m 0644 -o root -g root ~/allowed_signers /root/allowed_signers

# 先に確認だけ（何も変えない。root でなくてよい）
./deploy/install.sh --check

# 入れる。質問に答える（2 回目からは聞かない）
sudo ./deploy/install.sh
```

証明書はリバースプロキシの **Caddy**（Go 製の Web サーバー）が Let's Encrypt から自動で取り、期限の前に自動で取り直します。自分で証明書を用意する必要はありません。

既定は **1 システム・1 店舗の形**です（CONCEPT 4.1）。複数の店（マルチテナント・マーケットプレイス・SaaS）にする形と、Plesk などで**すでにある Web サーバーの後ろに置く形**、家やオフィスのネットワークに穴を開けずに **Cloudflare Tunnel で出す形**は、下の「店の数」「すでにある Web サーバーの後ろに置く」「Cloudflare Tunnel で出す形」を見てください。

- サーバーはインストーラーで入れる。お店の開設（店名・特商法・送料・支払い）は、入れた後に管理画面で行う
- ブラウザからサーバーを入れる形（設定ファイルを Web から書く・DB のパスワードをフォームで受ける）は取りません。入れ終わった後も開いていると乗っ取りの入口になるためです。ブラウザのウィザードは、端末に出た一度きりのパスワードを持つ人だけが開け、パスキーの登録だけを行います
- 共用のレンタルサーバー（FTP で上げる形）では動きません。PostgreSQL・Redis・常駐の処理が要るので、VPS（仮想の専用サーバー）を使います

## 準備するもの

| もの | 中身 |
|---|---|
| サーバー 1 台 | Ubuntu Server 22.04・24.04・26.04 の LTS。CPU 2 個以上・メモリ 4 GB のプラン以上・ディスク 40 GB（空き 20 GB 以上）。DigitalOcean・AWS（EC2）・Google Cloud（GCE）などの VPS。SSH で入れて sudo できること。**外から 80・443 番に届くこと**（Let's Encrypt が確認に来る。Cloudflare Tunnel の形では要らない） |
| ドメイン | 管理画面のホスト名（例 `admin.example.jp`）と、ショップのホスト（例 `example.jp` か `www.example.jp`。複数の店の形ではショップのドメインで、店は `<店>.example.jp`）。DNS を書き換えられること |
| 管理画面に入る接続元 | ブラウザで管理画面を見る端末の、会社や家の固定の IP（か範囲）。ここに無い所からは管理画面を開けません（既定は今 SSH で接続している所。プライベート IP アドレスやサーバーと同じネットワークの IP アドレスなら、踏み台を経由している見込みなので既定にせずに聞く） |
| 最初の運営のメール | 管理画面に最初に入る人。パスキー（指紋や顔・端末の画面ロックで入る鍵）を登録できるブラウザも |
| リポジトリを読む権限 | 非公開のリポジトリなら、サーバーで `git clone` できること。自動の更新のために、入れた後に読み取りだけの鍵（deploy key）を登録します（下の「非公開のリポジトリから取る」） |
| 後で要るもの | Stripe の鍵（管理画面で店ごとに貼る）、メールの送信サービス（本番。SMTP のホストとパスワード） |

## ウィザードの 5 ステップ

番号つきのステップで進み、各ステップの頭に「何をするか」、終わりに ✓ を出します。何度実行しても同じ結果になります（冪等）。途中で止まったら、出た直し方に従って直し、もう一度実行すれば続きから進みます。壊れる操作（作り直し・消す）はしません。

### [1/5] 環境の確認（何も変えない）

全部を確認してから **✓／✗ の一覧で一度に**見せます。✗ には直し方が 1 行ずつ付き、✗ が 1 つでもあれば何も変えずに止まります。`./deploy/install.sh --check` で、この確認だけを実行できます（root でなくてよい。設定ファイルがあれば DNS の確認も 1 回だけ）。

| 確認すること | 決まり |
|---|---|
| OS | Ubuntu 22.04・24.04・26.04（amd64・arm64） |
| 大きさ | CPU 2 個以上、メモリ 3.5 GiB 以上（4 GB のプラン。イメージを作るときに使う）、`/var/lib` の空き 20 GiB 以上 |
| 時計 | NTP で同期している（していなければ、外の時刻との差が 2 分以内）。パスキーと証明書は時計がずれると通らない |
| リポジトリ | git の作業ツリーで、手で変えた所が無い（入れるバージョンがコミットで決まる）。取得元（origin のリモート）があり、その URL にパスワードやトークンが入っていない（入っていると記録と画面に残るので ✗。URL そのものは出さない） |
| ポートとほかの Web サーバー | 80・443 番が空いていて、apache2・nginx・caddy・lighttpd・httpd・haproxy・traefik が動いていない。使われていても**止めることは勧めません**（ほかのサイトが止まる）。注意を出し、2/5 で「すでにある Web サーバーの後ろに置く形」にするかを聞きます。Plesk・cPanel・DirectAdmin・ISPConfig のサーバーは見分けて、そのことも出します |
| 外への道 | Let's Encrypt（証明書）・Docker Hub（部品のイメージ）・GitHub（ソースと更新）・release チャネルは GitHub のコンテナのレジストリ（`ghcr.io`。公開版のイメージ）、source チャネルは Go のモジュールのプロキシ（リバースプロキシ（Caddy）を作る）、Docker が無ければ Docker の公式のリポジトリに、https で届く。Cloudflare Tunnel の形（設定ファイルに `FRONT=tunnel` があるとき）は、Cloudflare の 7844 番（TCP）にも届くか（届かなくても注意だけ。cloudflared はふつう UDP で、だめなら TCP で接続する）。サンプルの写真を取るセット（設定ファイルの `SAMPLES` が `sweets`・`fashion`・`botanica`。まだ設定ファイルが無い初めての実行では既定の `sweets` として見る）なら、`unsplash.com` と `images.unsplash.com` にも届くか（届かなくても注意だけ。写真なしで入る） |

Docker のバージョン（Engine 28.1 以上・compose 2.35 以上）は 4/5 で確認します（無ければ入れる）。

### [2/5] 聞く

管理画面のホスト名、**ショップのホスト**（例 `example.jp`。1 店舗の形。複数の店の形ではショップのドメインと最初のショップのサブドメイン `demo` → `https://demo.example.jp/`）、ショップの名前、どのテーマで始めるか（サンプル）、最初の運営のメールと表示名（既定は付けない。メールの @ の前は名前でないことが多いため）、管理画面を開ける接続元。設定ファイルで実行するときは、聞く項目をすべて書く（無い項目があり、端末も無ければ、その項目の名前を出して止まる）。設定ファイルは `/srv/ec-sugar/install.answers` に残り、2 回目からは聞きません。最後に設定ファイルの一覧を ✓ で出します。

- 使い方は既定で本番。ステージングは設定ファイルに `MODE=staging` と書く
- **更新の形**（設定ファイルの `CHANNEL`。聞かない）: 公開用のリポジトリから入れると release チャネル（`release`。ツリーの `deploy/channel.default` で決まる）、開発用のリポジトリからは source チャネル（`source`）。release チャネルは、人の鍵で署名したバージョンのタグ（`v1.2.3`）だけを入れ、イメージは公開用の CI が作って署名したものを確認してから取ります（サーバーの上では作らない。下の「自動の更新と、失敗したときのロールバック」）
- release チャネルでは、**タグの署名で信頼する鍵の一覧**（設定ファイルの `TRUST_SIGNERS_FILE`）を聞き、鍵ごとの指紋を出して「公式サイトで公表している指紋と同じなら yes」を聞きます（下の「タグの署名の鍵」）。設定ファイルで実行するときは、指紋を `TRUST_FINGERPRINTS` に書く（一覧の指紋とちょうど同じでなければ止まる）。取り出した HEAD にバージョンのタグが無ければ止まります
- **どのテーマで始めるか**（入れたときのサンプル。商品・カテゴリ・テーマの中身〔トップの見せ方〕・特商法の表記の見本・送料）を聞く。設定ファイルの `SAMPLES` は次のどれか（既定は `sweets`）。管理画面のホームからオーナーがまとめて消せます。設定ファイルに `SAMPLES` の無い、もう入っているサーバーでは聞かずに入れない

  | 設定値 | 中身 | テーマ | 写真 |
  |---|---|---|---|
  | `sweets`（既定） | お菓子のお店 | 角砂糖 | 入れる時に Unsplash から取る |
  | `fashion` | 布ものとツールのお店 | fashion | 入れる時に Unsplash から取る |
  | `botanica` | 植物のお店 | botanica | 入れる時に Unsplash から取る |
  | `zakka` | 雑貨のお店 | 角砂糖 | 同梱のアイコン（取りに行かない） |
  | `none` | 入れない | 角砂糖のまま | — |

  前の設定ファイルの `SAMPLES=yes` は `zakka`（前からの中身の雑貨のお店）、`SAMPLES=no` は `none` と読みます（書き直さなくてよい）
- 店の数は既定で 1 店舗（聞かない）。複数の店にするときは設定ファイルに `SHOPS=multi`（下の「店の数」）
- 80・443 番をすでにある Web サーバーが使っていれば、EC-SUGAR をその後ろに置くか（`proxy`、既定）、EC-SUGAR が受けるか（`caddy`。すでにある Web サーバーを止めてから）を聞く
- 定期の仕事（夜の自動の更新・朝のバックアップ）のタイムゾーンは既定で Asia/Tokyo（聞かない。設定ファイルの `TIMEZONE`）。サーバーのタイムゾーン（Ubuntu の既定は UTC）そのものは変えません
- 待ち受けるアドレス（このサーバーの IPv4）は聞かずに決めます（外へ出る道の IP アドレス）。違うときは設定ファイルの `BIND` を直す
- 本番とステージングの違い: ステージングは 80・443 番と SSH を決めた LAN からだけ受け、メールを外へ出さず（Mailpit で受ける。画面は Basic 認証で、利用者 `ec-sugar`、パスワードはインストーラーが作って root だけが読める `/srv/ec-sugar/secrets/mailpit-ui-password` に置く。画面には出さない）、本番の Stripe の鍵を置けず、HSTS（https だけで来るようにブラウザに覚えさせる）は 5 分。本番は 80・443 番を誰からでも受け、HSTS は 1 年
- ステージングは、すでにある Web サーバーの後ろに置く形（`FRONT=proxy`）では使えません（2/5 で止まる）。ほかの利用者と同居するサーバーでは、Mailpit の画面（127.0.0.1）にほかの利用者から届き、待ち受けを LAN だけにする決まりも入れられないため

### [3/5] DNS の確認

2 つの名前（管理画面とショップのホスト）が、**公開の DNS** でこのサーバーの IP を指しているかを見ます（DNS over HTTPS で Cloudflare の公開の DNS に聞く。サーバーの `/etc/hosts` には惑わされない）。

指していなければ、足す行をそのまま出して待ちます（30 秒ごとに見直す。Ctrl-C で抜けて、後で再実行しても続きから進む。端末が無いときは 10 分で止まる）:

```
   種類 A   名前 admin.example.jp      値 203.0.113.10
   種類 A   名前 example.jp            値 203.0.113.10
```

複数の店の形では、2 行目は `*.example.jp`（ワイルドカード）です。ワイルドカードは毎回違う名前（`ec-sugar-check-<乱数>.<ドメイン>`）で引いて確認します。

- 値は、このサーバーを外から見た IP アドレス（クラウドで待ち受けるアドレスが私設のときも、外から見た IP アドレスを出す）
- AAAA レコード（IPv6）があれば ✗ にします。このサーバーは IPv6 で受けないので、Let's Encrypt がそちらへ確認に行って失敗するため
- 証明書を DNS の鍵で取る形と、手で用意した証明書の形（下の「証明書の取り方」）では、このステップを飛ばします
- Cloudflare Tunnel の形では、確認せずに **Cloudflare の DNS に足す CNAME の 2 行**（値は `<トンネルの ID>.cfargotunnel.com`、プロキシ済み）を出します。Cloudflare のプロキシの CNAME は、公開の DNS からは行き先が見えないためです。外から開けるかは 5/5 で確認します

### [4/5] 入れて立てる

1. **ツールと Docker**: git・openssl・ufw・unattended-upgrades・jq（更新の前に、新しいバージョンの compose を今のセキュリティチェックにかける）など（apt）。Docker は公式のリポジトリ（download.docker.com）から。取った apt の鍵の指紋を Docker の公表しているもの（`9DC8 5822 9FC7 DD38 854A E2D8 8D81 803C 0EBF CD88`）と比べ、違えば止まる。公式のリポジトリがまだその Ubuntu に無いとき（LTS が出た直後）は Ubuntu の同梱のバージョン。どちらもバージョンの下限を確認する
2. **専用の利用者と保存先**: 利用者 **`ec-sugar`**（ログインできない。docker のグループにも入れない）と `/srv/ec-sugar`（下の「保存先」）。release チャネルでは、2/5 で確認した鍵の一覧を `/srv/ec-sugar/trust/allowed_signers`（root の 0644）に置きます（もうあれば触らない。替えるのは `sudo ec-sugar trust`）
3. **ファイアウォール（ufw）**: 入ってくるものは既定で断る。本番は SSH（回数を絞る）・80・443。ステージングは SSH と 80・443 を決めた LAN だけ、SSH は今接続している接続元も許す（締め出さない）。すでにある Web サーバーの後ろに置く形では ufw に触りません
   - **Docker は ufw を迂回します**。80・443 の本当の締めは compose の待ち受けるアドレス（`EC_SUGAR_BIND`）だけで行います（`docker/check-compose.sh` が CI で監視する）。ステージングは加えて、Docker の前の決まり（`DOCKER-USER` のチェーン）で LAN の外から 80・443 へ来たものを落とします
   - Cloudflare Tunnel の形では 80・443 番を ufw で開けません（Caddy は 127.0.0.1 だけで受ける。前の形で足した許しは消す）
   - **ホストのファイアウォール**（`ec-sugar-firewall.service`、どの形でも。下の「ホストのファイアウォール」）: ホストの root でない利用者から、EC-SUGAR のコンテナ（app・db・redis・nginx）へ直に届かないようにします
4. **自動のセキュリティ更新**（unattended-upgrades）: Ubuntu のセキュリティの修正だけ。Docker は自動では上げません
5. **リバースプロキシの秘密**（使う取り方のときだけ）: DNS の API の鍵・手で用意した証明書と鍵を確認してから `/srv/ec-sugar/secrets/caddy/` にコピーします（リバースプロキシ（Caddy） の利用者だけが読める 0400）。鍵の中身は画面にも記録にも出しません。Cloudflare Tunnel の形では、トンネルのトークンと cloudflared の設定を `/srv/ec-sugar/secrets/tunnel/` に、トンネルの監視の読むだけの鍵（あれば）を `/srv/ec-sugar/secrets/tunnel-watch/` に置きます（下の「Cloudflare Tunnel で出す形」）
6. **設定のファイル** `/srv/ec-sugar/env/.env.production`（root だけ読める 0600）。`deploy/production.env.example` の【作る】の項目（`APP_KEY`・DB の管理者とアプリのパスワード・Redis のパスワード）を、サーバーの乱数で 1 回だけ作ります。画面にもコマンドの引数（ps で見える）にも出しません。【聞く】の項目は設定ファイルから毎回書きます
7. **作業ツリー** `/srv/ec-sugar/src`: 取ったリポジトリを `ec-sugar` の持ち物としてコピーし、入れるバージョンのコミットを取り出します
8. **イメージを作る**: アプリ（`ec-sugar:<コミット>`。初回は 10 分ほど）とリバースプロキシの Caddy（`ec-sugar-caddy:<バージョン>`。公式の Caddy に Cloudflare の DNS の部品を足したもの。数分）。release チャネルでは作らず、取り出したタグの人の署名を置いた鍵で確認し、2 つのイメージ（`ghcr.io/ec-sugar/ec-sugar:<タグ>`・`ghcr.io/ec-sugar/ec-sugar-caddy:<タグ>`）の CI の署名を検証てから digest で取ります（レジストリの一時のタイムアウトに備えて、同じ digest で 3 回まで取り直す。下の「release チャネルの更新」）
9. **起動する**: DB と Redis（ネットワークを作ったら、起動する前にホストのファイアウォールを適用する）→ マイグレーション（`migrate --force` と `tenants:migrate --force`）→ 運用のコマンド `ec-sugar` と systemd の unit（作業ツリーのファイルでなく、入れるコミットの git の中身から）→ `ec-sugar.service` で全部を起動する → ヘルスチェック（全部のコンテナが healthy・app が入れたイメージ・nginx から PHP へ `/up` が 200）→ 自動の更新と毎朝のバックアップのタイマー
10. **最初のショップを作る**（`php artisan shop:create <住所> --name=<名前> --domain=<住所>.<ショップのドメイン>`。店の DB を作る力は CLI だけが持ち、Web のプロセスには持たせない。同じものがあれば何もしない）
    - 名前は後から管理画面の「設定 › 基本」でオーナーが変えられます。管理画面で変えた後にインストーラーを再実行しても、同じホストの店は「もうあります」で通り、設定ファイルの `SHOP_NAME` で名前を書き戻しません（`SHOP_NAME` が効くのは最初に作る時だけ）
    - 設定ファイルの `SAMPLES` が `none` でなければ、続けて `php artisan shop:sample <住所> --set=<答え>` で入れる（ショップのテーマもセットのテーマへ切り替わる。下の「入れたときのサンプル」）。写真を取るセットでは「写真を Unsplash から取ります（数十秒）」とプレフィックスし、途中の状況をそのまま出す。入れ終えたら印（`/srv/ec-sugar/state/samples_added`）を残し、再実行では入れない（消した後に戻さない）。入れられなかったとき（終わりの番号 1。お店の商品がもうある・別のセットが残っている・途中の失敗など）はインストールを止めず、注意と直し方を出す（直して再実行すると残りだけを入れる。入れないなら設定ファイルの `SAMPLES` を `none` に）。番号 2（引数の誤り）はインストーラーの誤りなので止まる。写真が取れなかったときは、その写真なしで入って続く
11. 初めてのときは **APP_KEY のバックアップの取り方**（`sudo ec-sugar app-key`）を出します（下の「後から変えにくい設定」）。バックアップを取ったと管理画面で回答するまで、運営ダッシュボードに通知が出続けます

### [5/5] 仕上げ

Cloudflare Tunnel の形では、https の確認（サーバーの中からリバースプロキシ（Caddy） へ）の後に、偽の接続元のヘッダーで管理画面の接続元の制限を偽れないことと、外（Cloudflare）を回って開けることを確認します（下の「Cloudflare Tunnel で出す形」。外からまだ開けなければ止めずに案内だけ）。

すでにある Web サーバーの後ろに置く形では、先にその Web サーバーに足す設定（Plesk の手順か nginx の例）を出し、もう https で開けるなら、偽の `X-Forwarded-For` を付けて送り、本当の接続元が届くか（そのまま渡していないか）も確認します。まだ開けなければ、設定を足してから再実行するように案内します。

1. 管理画面と最初のショップが **https で開けるか**を確認します（証明書が取れていて、信頼できる形か。取るのに 1 分ほどかかることがある。最大 3 分待つ）。開けなければ、どこを見ればよいかを出して止まります
2. 最初の運営を作り、**「このアドレスをブラウザで開いてください」の 1 行**（一度きりのパスワードつき。`#` の後ろはサーバーの記録に残らない）と、切れたときの出し直し方（`sudo ec-sugar operator enroll <メール>`）だけを出します。登録の URL は記録のファイルに書きません

## 設定ファイル（install.answers）

端末の無い所から実行するとき・同じ設定ファイルで何台も入れるときは、設定ファイルを渡します（`sudo ./deploy/install.sh --answers ~/install.answers`）。設定ファイルは `/srv/ec-sugar/install.answers` に残り、2 回目からはそれを使います。設定ファイルを変えるときは、このファイルを直して再実行します（`/srv/ec-sugar/env/.env.production` を直に書き換えない。再実行すると設定ファイルの値に戻ります）。

```ini
# 設定ファイルの例（秘密は入れない。パスワードと鍵はインストーラーがサーバーで作る。行の後ろに説明を書かない）
# 使い方: production（本番。既定）か staging（ステージング）
MODE=production
ADMIN_HOST=admin.example.jp
# 1 店舗の形（既定）: ショップのホスト
SHOP_HOST=example.jp
SHOP_NAME=はじめてのショップ
# どのテーマで始めるか（入れたときのサンプル）: sweets（お菓子・角砂糖。既定）・fashion（布ものとツール・fashion）・botanica（植物・botanica）・zakka（雑貨・角砂糖）・none（入れない）
# SAMPLES=botanica
# 複数の店の形にするときは、上の SHOP_HOST の代わりに次の 3 行（店は <店の住所>.example.jp）
# SHOPS=multi
# SHOP_DOMAIN=example.jp
# SHOP_ID=demo
OPERATOR_EMAIL=you@example.jp
OPERATOR_NAME=あなたの名前
# 管理画面を開ける接続元（カンマ区切り）
ADMIN_ALLOWED_IPS=198.51.100.7/32
# 待ち受けるアドレス（このサーバーの IPv4。書かなければ外へ出る道の IP アドレス）
# BIND=203.0.113.10
# ステージングだけ: 見せてよい LAN の範囲
# LAN_CIDR=192.168.0.0/24
# 更新の形（書かなければ、公開用のリポジトリは release〔署名したタグだけ〕、開発用は source〔コミットからサーバーで組み立てる〕）
# CHANNEL=release
# release チャネル: タグの署名で信頼する鍵の一覧（公式サイトで配っているもの）と、公式サイトで公表している指紋（カンマ区切り。一覧の指紋とちょうど同じ）
# TRUST_SIGNERS_FILE=/root/allowed_signers
# TRUST_FINGERPRINTS=SHA256:…,SHA256:…
# source チャネルの自動の更新が見るブランチ（既定はリポジトリの今のブランチか main）
# UPDATE_BRANCH=main
# 自動の更新を入れるか（既定は release チャネルが yes〔署名の通る新しいタグだけ〕、source チャネルが no〔本番もステージングも。入れても fast-forward のコミットだけ〕）
# AUTO_UPDATE=no
# 定期の仕事（夜の更新・朝のバックアップ）のタイムゾーン（既定 Asia/Tokyo）
# TIMEZONE=Asia/Tokyo
# すでにある Web サーバーの後ろに置く形（既定は caddy。80・443 番が使われていれば聞く）と、EC-SUGAR のソケットに入れる前段のリバースプロキシのグループ
# （書かなければ 443 番で受けている Web サーバーの働き手から見分ける）
# FRONT=proxy
# PROXY_GROUP=www-data
# 同じグループで PHP などが動くと分かったうえで進めるときだけ（status に出し続ける）
# PROXY_GROUP_SHARED_OK=1
# Cloudflare Tunnel で出す形（下の節）と、トンネルのトークンを 1 行で書いたファイル。証明書は TLS_CERT・TLS_KEY（Cloudflare の Origin CA のものを勧める）か CLOUDFLARE_TOKEN_FILE が要る。BIND は 127.0.0.1 に決まる
# FRONT=tunnel
# TUNNEL_TOKEN_FILE=/root/tunnel-token
# トンネルの監視の読むだけの Cloudflare の API の鍵（任意。トークンが漏れて、よそからコネクタを足されたら気づく。下の節の 5.）
# TUNNEL_WATCH_TOKEN_FILE=/root/tunnel-watch-token
# 証明書の取り方（下の節）。どれも書かなければ Let's Encrypt から自動で
# CLOUDFLARE_TOKEN_FILE=/root/cloudflare-token
# TLS_CERT=/home/ubuntu/tls/fullchain.pem
# TLS_KEY=/home/ubuntu/tls/privkey.pem
# 内部の整合性チェック（下の「内部の整合性チェック」）で、compose の外でも動いてよいコンテナの名前（カンマ区切り。* が使える）。書かなければ compose の外のコンテナは問題
# INTEGRITY_ALLOW_CONTAINERS=backup-*,node-exporter
```

- 間違えやすい 3 つ: `ADMIN_ALLOWED_IPS` は**ブラウザで管理画面を見る端末**の IP アドレス（サーバーのネットワークではない）、`BIND` は**このサーバー**が待ち受ける IP アドレス、`LAN_CIDR`（ステージングだけ）は**見る端末のいる LAN の範囲**です。踏み台（`ssh -J`）を経由して入れると `ADMIN_ALLOWED_IPS` の既定が踏み台の IP アドレスになるので、設定ファイルに先に書いてください

## 証明書の取り方

リバースプロキシは 2 段です（ADR 0031）。インターネット → **Caddy**（80・443 番。https と証明書）→ **nginx**（内側のネットワークの http。管理画面の接続元の制限は後ろの PHP、ファイルの拒否・ヘッダは nginx。今までの防御のまま）→ PHP。

| 取り方 | 選び方 | 何が起きるか |
|---|---|---|
| **auto**（既定） | 何も書かない | **1 店舗の形**: 管理画面とショップのホストの 2 つを起動のときに Let's Encrypt から取り、期限の前に取り直す（その場で取る形も問い合わせの経路も使わない。ほかの名前は握手で断る）。**複数の店の形**: 管理画面と店のホストは**初めて開かれたときにその場で取る**（on-demand TLS）。取る前に EC-SUGAR の内側の経路に「この名前は管理画面か、ある店か」を聞き、違えば取らない（知らない名前で証明書を取らせない。Let's Encrypt の回数の上限を食い潰させない）。店の独自ドメインも同じ仕組みに載せられる形（今はまだ受けない。取るのは管理画面と `*.ショップのドメイン` の 1 つ下の名前だけで、それ以外の名前は問い合わせる前に握手で断る）。問い合わせは受付の nginx で毎秒 20 回まで（超えたら 429。知らない店の名前で握手を繰り返されると、その間は本当の店の証明書のその場の取得も待たされる〔可用性だけ。取れた証明書は使える〕）。外から 80・443 番に届くことが要る |
| **dns**（外から届かないステージング） | `CLOUDFLARE_TOKEN_FILE` に鍵のファイル | Cloudflare の DNS の API の鍵で、DNS の TXT で確認して、管理画面とショップ（1 店舗はそのホスト、複数の店は `*.ショップのドメイン` のワイルドカード 1 枚）を取る。外から届かなくてよい。DNS の確認（3/5）は飛ばす |
| **manual**（上級者向け。Cloudflare Tunnel の形では Origin CA の証明書で） | `TLS_CERT` と `TLS_KEY` | 手で用意した証明書（管理画面のホスト名と、ショップのホスト〔複数の店は `*.ショップのドメイン`〕を含む。中間の証明書も含めた PEM）と、パスフレーズの付いていない秘密の鍵を読む。取り直しは人が行い、置き直して再実行する。4/5 で、鍵と対になっているか・名前を含むか・期限を確認する。**Cloudflare の Origin CA の証明書**（発行者が CloudFlare Origin SSL〔ECC〕Certificate Authority）は Cloudflare Tunnel の形でだけ使えます（ブラウザが信頼しないので、ほかの形では 2/5 で止まる。下の「Cloudflare の Origin CA の証明書」） |

- 証明書が覆っていても Caddy の場所に合わない名前（1 店舗の形に戻した後の `*.<ドメイン>` の店のホスト、複数の店の形のショップのドメインそのもの、Cloudflare Tunnel の形で知らない Host が載った要求など）は、どの取り方でも中身の無い 404 を返します（後ろへ渡さない。前は Caddy の既定の空の 200 だった）。証明書に含まれていない名前は今までどおり握手で断ります
- 証明書と ACME のアカウントの鍵は、Caddy の名前付きボリューム（`ec-sugar_caddy_data`）の中だけ。nginx・PHP のコンテナからは見えません。消すと取り直しになります（Let's Encrypt の回数の上限に触れうる）
- **Cloudflare の API の鍵**: Cloudflare の画面で「ゾーン DNS を編集する」のテンプレートから作り、範囲はそのドメインだけ、できれば接続元をサーバーの IP に絞り、期限を付けます。鍵だけを 1 行で書いたファイル（root だけ読める 0600）を置き、そのパスを `CLOUDFLARE_TOKEN_FILE` に書きます。インストーラーが形を確認してリバースプロキシの秘密の保存先へコピーするので、元のファイルは消してかまいません。環境変数や引数では渡しません（ps・`docker inspect` に出るため）
- ステージング（`MODE=staging`）と Cloudflare Tunnel の形（`FRONT=tunnel`）は外から 80 番に届かないので、dns か manual が要ります（auto のままだと 2/5 で止まります）
- 試験のためだけの設定値 `ACME_CA`（取得元の URL）・`ACME_CA_ROOT`（その https の根の証明書のファイル）で、Pebble などの試験の認証局に向けられます。本番では書きません（ブラウザが信頼しない証明書になる。書くと 2/5 で注意を出す）
- 本当の接続元の IP アドレス: Caddy は外から来た `X-Forwarded-For` を捨てて自分が見た IP アドレスを付け、nginx はそれを Caddy の IP アドレス（リバースプロキシの前のネットワークで固定）からだけ信頼し、PHP には本当の接続元を `REMOTE_ADDR` で渡します。PHP にはプロキシのヘッダーを渡さず、Laravel の `TRUSTED_PROXIES` は空のままです。管理画面の接続元の制限と回数の制限（ショップの注文の確定の回数と、銀行振込・代金引換の入金待ちの数〔ADR 0085〕も）はこれに頼ります

## 店の数（1 店舗と複数の店）

| 形 | 選び方 | 名前 | 証明書（auto） |
|---|---|---|---|
| **1 店舗**（既定） | 何も書かない（`SHOPS=single`） | 管理画面のホストと、ショップのホスト（自分のドメインそのもの。例 `example.jp`）の 2 つちょうど | 2 つを起動のときに取る。その場で取らないので、問い合わせの経路（`/internal/tls/allowed`）は何にも「取ってよい」と回答しない（攻め口ごと無い） |
| **複数の店** | 設定ファイルに `SHOPS=multi`・`SHOP_DOMAIN`・`SHOP_ID` | 管理画面のホストと `<店>.<ショップのドメイン>`（DNS はワイルドカードか店ごとの名前。Cloudflare Tunnel の形は店ごとの名前を勧める） | その場で取る。取る前に問い合わせの経路に聞く（回数の制限と名前の絞り込みつき） |

- 1 店舗の形の店の ID は聞かずに `shop` に決めます（店の DB の名前に使う。画面には出ない）
- この形を足す前に入れたサーバー（設定ファイルに `SHOP_DOMAIN` があり `SHOPS` が無い）は、複数の店の形のままです
- 1 店舗の形には中央のホスト（`api.<ドメイン>`）が無いので、**Stripe Connect（運営あての連結アカウントの通知を受ける形。SaaS 向け）は使えません**。管理画面の支払いの設定では「この環境では使えない」になり、接続しようとしても断ります（店が自分の Stripe の鍵を貼る形は使える）
- 最初から複数の店にすると決まっているなら、入れるときに `SHOPS=multi` を書く。1 店舗で入れた後に広げるのは下の「1 店舗から複数の店へ広げる」
- 複数の店の形で店を足すのは CLI だけ（`php artisan shop:create <ID> --name=<名前> --domain=<1 段>.<ショップのドメイン>`。app のコンテナの中で）。ホストはショップのドメインの直下の 1 階層だけで、**店の ID と違ってよい**（ID `demosake`・ホスト `demo-sake.example.jp`。店の ID は店の DB の名前になるのでハイフンを避け、ホストには使う）。管理画面・中央のホスト（`api`）と、同じドメインに置かれやすいほかのサイト・メールの名前（`www`・`dev`・`mail`・`webmail`・`ftp`・`blog`・`docs` など）は断ります。足した店のホストの DNS の行は人が足します（`sudo ec-sugar shops` が出す）。店の名前は後から管理画面の「設定 › 基本」で変えられます（ホストと ID は変えられません）
- **店の Cookie はその店のホストだけ**です（Domain 属性を付けない）。env の `SESSION_DOMAIN` を `.<ドメイン>` にしても、ショップの Cookie（セッション・CSRF・カート・会員のセッション）には付けません（ほかの店・管理画面・同じドメインのほかのサイトへ送らない。ADR 0090）。`SESSION_DOMAIN` は `null` のままにする
- **https では、ショップと管理画面の Cookie の名前に `__Host-` の頭が付きます**（例 `__Host-sugar_cart`・`__Host-XSRF-TOKEN`）。ブラウザは `__Host-` の Cookie を、そのホストが Secure・Path=/・Domain なしで出したときだけ受け取るので、同じドメインのほかのホスト（ほかの店・`www`・`dev`）から Cookie を置いてセッションを乗っ取る手（cookie tossing・セッション固定）が効きません。頭の無い Cookie は https では読みません。手元の http（`make up`）では頭を付けません
  - **前のバージョンから更新した時**: 運営は一度ログインし直し（パスキー）、入力の途中の注文は入れ直しになります（セッションと CSRF の Cookie は前の名前から移さない）。カート・会員のログイン・準備中のパスワード・タグの同意は、1 店舗の形の間だけ前の名前を一度読んで移します。複数の店へ広げる前に、更新してから数日おく（移っていないカートとログインは、広げた後は読まない）

### 1 店舗から複数の店へ広げる（ADR 0090）

1 店舗で動いている店を、**お客さんの URL を変えずに**複数の店の形にします。今のショップのホストが、ショップのドメインの直下の 1 階層の名前（例 `shop.example.jp` と `example.jp`）のときだけ広げられます（ショップがドメインそのもの〔`example.jp`〕・2 階層下のときは URL が変わるので断る）。今の店の URL・店の DB・データの鍵・Stripe の通知先・管理画面のホスト（パスキー）は変わりません。

```sh
sudo ec-sugar shops                                # 今の形と店のホスト（複数の店の形なら DNS に足す行も）
sudo ec-sugar shops multi --domain=example.jp      # 広げる（確認の文を見て、ドメインを打つ。端末でなければ --yes）
sudo ec-sugar shops single                         # 1 店舗へ戻す（最初の店のほかは開けなくなる。データは消さない）
```

1. **先に の入ったバージョンへ更新する**（`sudo ec-sugar update --to <コミット>`。前のバージョンにはこのコマンドが無い）
2. **証明書**（下の表）。手で渡した証明書（Cloudflare の Origin CA など）は、先に `*.<ドメイン>` を含むものに替える
3. `sudo ec-sugar shops multi --domain=<ドメイン>`。確認する → 設定ファイル（`SHOPS=multi`・`SHOP_DOMAIN`。`SHOP_HOST`・`SHOP_ID` は残す）→ env（店の数の項目と中央のホスト `CENTRAL_DOMAINS=api.<ドメイン>`）→ cloudflared の行き先（Tunnel の形）→ `web`・`app`・`queue`・`scheduler`・`caddy`・`cloudflared` を作り直す（数秒止まる。DB と redis は作り直さない）→ ヘルスチェック → 管理画面（`/up` が 200）と最初の店（500 未満で 404 でない）をリバースプロキシから https で開けるか（dns の取り方は 5 分まで待つ）→ Cloudflare Tunnel の形では、中から接続元を偽れないことと、外（Cloudflare）から管理画面と最初の店が開くこと。**駄目なら 1 店舗の形へ自動で戻します**（外から開けないのは、替える前に開けていたときだけ戻す。替える前から開けなかったなら、戻さずに「大事」の注意を出すので、DNS とトンネルを見て `sudo ec-sugar check-front`）
4. 新しい店を作り（上の `shop:create`）、出た DNS の行を足す。Cloudflare Tunnel の形では `sudo ec-sugar check-front`

| 証明書の取り方 | 広げる時 |
|---|---|
| dns（DNS の鍵） | 何もしない。作り直しの時に Caddy が `*.<ドメイン>` のワイルドカード 1 枚を取る（1〜2 分、**店だけでなく管理画面も** https が開けないことがある〔複数の店の形では管理画面も `*.<ドメイン>` に覆われる〕。コマンドは 5 分まで待つ。空いている時間に） |
| manual（Origin CA など） | 先に `*.<ドメイン>` を含む証明書を作り直す（下の「Cloudflare の Origin CA の証明書」の 1.〜2. で、ホスト名に `*.<ドメイン>`。1 店舗の名前も含む）→ 設定ファイルの `TLS_CERT`・`TLS_KEY` に書いて `sudo ./deploy/install.sh`（1 店舗のまま）→ それから広げる。含まれていなければコマンドが断る |
| auto（Let's Encrypt） | 店のホストは初めて開いたときにその場で取る形に替わる（上の「証明書の取り方」）。DNS は `*.<ドメイン>` の A か店ごとの A |

- **確認で断るもの**（何も変えない）: ドメインの形が違う・公開の接尾辞（`co.jp`・`tokyo.jp` など）・管理画面のホストと同じ・今のショップのホストがドメインの直下でない・env の `SESSION_DOMAIN` が空でも `null` でもない・手で渡した証明書が `*.<ドメイン>` を覆わない・設定ファイルの `TLS_CERT` がリバースプロキシのものと違う（新しい証明書を置いたのに再実行していない）・もう別のドメインで複数の店の形
- **再実行しても同じ**です。途中で止まったら（証明書を取るのが遅い・回線が一時切れたなど）、同じコマンドをもう一度実行すれば直ります。もうその形なら確認の文を飛ばして当て直します。設定ファイルは仮のファイルに書いてから入れ替えるので、前の形か新しい形のどちらかです
- 設定ファイルの書き方は install.sh と同じなので、広げた後に `sudo ./deploy/install.sh` を再実行しても同じ形のままです（設定ファイルを手で `SHOPS=multi`・`SHOP_DOMAIN=<ドメイン>` に直して再実行しても同じ結果。最初の店は `SHOP_HOST` のホストのまま「もうあります」で通る）
- **`sudo ec-sugar rollback` は店の数の形を戻しません**（イメージだけ。env は複数の店のまま。前のバージョンも複数の店の形を知っている）。形を戻すのは `shops single`
- `shops single` は、最初の店のほかの店のホストを出し、`single` と打ったときだけ戻します。中央のホストが無くなり Stripe Connect は使えなくなります。足した店の DB は残り、毎晩の `demo:reset` などの定期の処理もその店を回り続けます（止めるなら `shop:demo <店> --off` か店を消す）
- 記録は `/srv/ec-sugar/state/history.log` の `shops_multi`・`shops_single`・`shops_reverted` の行

- 管理画面の「運営ダッシュボード」（オーナーだけ。全部の店の問題・今月の売上・システムの状態）の数は、scheduler が 10 分ごとに店ごとに数えて中央に置きます（ADR 0042）。入れた直後は 10 分ほど「まだ数えていない店」と出ます。この機能より前から注文のあるサーバーで、30 日より前の日の数も入れたいときは、一度だけ app のコンテナの中で `php artisan operations:collect --rebuild` を実行します（何度実行しても同じ結果。`ec-sugar` コマンドにはまだ口がありません）
- **壊れたら通知する**（ADR 0043）: 画面のエラー（500）・バックグラウンド処理の失敗・定期の処理の失敗は、中央に「どこで・何が・何回」だけを記録し（メッセージは残しません）、scheduler が毎分、止めていないオーナー全員へメールで通知します（最初はすぐ、同じものは 1 時間に 1 通まで。運営ダッシュボードの要対応の新しい問題も同じメールに）。メールの設定（`MAIL_*`）が要ります。キューを通さず直に送るので、キューが止まっていても届きます。中身は管理画面の運営ダッシュボードの「エラー」で見ます。90 日より前の記録は毎晩消えます
- **メールの記録**（ADR 0095）: 店が送ったメール（注文の通知・会員のログインのコード・App のメール・テストメール）は、送る口で 1 通ずつ店の DB に「送っている・送った・送れなかった」と理由（受け取りを断られた・一時的に受け取れない・送るサーバーにログインできない・接続できない・時間切れ。SMTP の応答の番号 `550`・`5.1.1` も）を残し、お店の人が管理画面の「設定 › メールの記録」で見られます。宛先と件名は店の鍵で暗号化し、例外の文・SMTP の応答の文・本文は残しません。「テストメールを送る」で、ログインしている運営のアドレスへキューを通して 1 通送れます（送れなかった理由がそのまま出るので、`MAIL_*` の設定の誤りを店の人が見つけられます）。「送った」はメールのメールの送信サービス（SMTP）が受け取ったという意味で、相手に届いたこと（返ってきたメール）までは見ていません。90 日より前の記録は毎晩 04:15 に消えます（`mail-records:prune`。scheduler のコンテナが実行する）。運営へのメール（ログインの防御・壊れた通知）は店の記録に入りません
- **App「メルマガ・一斉メール」を使うとき**: 配信は scheduler が毎分（`newsletter:run`）、店の設定の「1 分に送る数」ずつキューに積み、キューのワーカーが送ります。メールのメールの送信サービス（Plesk の箱・メールのサービス）の 1 時間・1 日の送信の上限を確認してから数を選びます（既定は 1 分に 10 通＝1 時間に 600 通）。Gmail などのワンクリックの配信停止（`List-Unsubscribe-Post`）が働くには、メールの送信サービスの DKIM の署名がこのヘッダーも覆う必要があります（メールの送信サービスの DKIM の設定で確認する。まだ確認していない）。返ってきたメール（届かないアドレス）を自動で止める仕組みはまだ無いので、メールの送信サービスの画面で返ってきた数を見ます（plugins/plugin-newsletter/README.md）
- **App「ダウンロード販売」を使うとき**（ADR 0084）: 売るファイルは写真と別の `storage/app/<MEDIA_DIRECTORY>-downloads`（本番は `media-downloads`）に置きます。保存先は storage の名前つきの保存先（全コンテナで共有）の中なので、イメージを差し替えても残ります。**今の `sudo ec-sugar backup` は DB だけで、売るファイルは含みません**（写真と同じ。サーバーが壊れた時に備えるなら、元のファイルを店の手元に残してもらう）。上限は `.env.production` の `DOWNLOADS_MAX_FILE_MB`（1 つ。既定 500）と `DOWNLOADS_SHOP_QUOTA_MB`（店ごとの合計。既定 10240）で、ディスクの空きに合わせて決めます（空き容量の監視は写真と同じ Docker の保存先で数えます）。ファイルは 8 MB のチャンクに分けて受けるので、受付の nginx（12m）と Cloudflare（100MB）の上限は変えません。上げる途中のファイルは 24 時間で捨て、店ごとに同時に 3 つまでです。scheduler が 10 分ごとに `downloads:grant`（払われたのにリンクの無い注文に作り直す）、毎日 04:40 に `downloads:purge`（店の人が消したファイルを、渡したリンクの期限の後に保存先から消し、24 時間を過ぎた上げる途中のファイルも捨てる）を実行します。お客さんへのファイルは PHP が渡す（大きなファイルを同時に多く配ると php-fpm の数を使う）ので、配る数が増えたら nginx の `X-Accel-Redirect` へ移します（後で。plugins/plugin-downloads/README.md）
- **App「カゴ落ちのお知らせ」を使うとき**（ADR 0067）: scheduler が毎時 35 分に `cart-reminders:run` を実行し、払われずに消えた注文へのお知らせをキューに積み、キューのワーカーが 1 通ずつ送ります（App を使う店だけ）。送るのは App「メルマガ・一斉メール」の受け取りを確認した人だけなので、両方を使います。ワンクリックの配信停止のヘッダーと DKIM の注意はメルマガと同じです（plugins/plugin-cart-reminder/README.md）
- **App「お気に入り」を使うとき**（ADR 0071）: scheduler が毎日 10:15（日本時間）に `favorites:notify` を実行し、前の日と比べて値下げ・再入荷した商品を、通知を選んだ会員へ 1 日 1 通にまとめてキューに積みます（App を使う店だけ）。送るのは App「メルマガ・一斉メール」の受け取りを確認した会員だけなので、通知を使うなら両方を使います（plugins/plugin-favorites/README.md）
- **App「おすすめのセクション」を使うとき**（ADR 0071）: scheduler が毎時 45 分に `recommendations:rank` を実行し、売れ筋ランキングを数え直します（App を使う店だけ。ショップの画面は数えた結果を読むだけ）
- **App「ラッピング・のし」を使うとき**（ADR 0078）: 定期の処理もメールもありません。店の DB に表が 3 つ増えるだけです（`tenants:migrate` で流れる）。名入れは店の鍵で暗号化して置きます（plugins/plugin-wrapping/README.md）
- **App「予約販売」を使うとき**（ADR 0073）: 定期の処理はありません。発売日を変えた時の通知メールは、キューのワーカーが 1 通ずつ送ります（メールの設定 `MAIL_*` が要ります）。発売日は日本の暦（Asia/Tokyo）で決まるので、サーバーの時計（UTC のまま）がずれていないことだけ確認します（plugins/plugin-preorder/README.md）
- **App「セット商品」を使うとき**: 定期の処理もキューもメールもありません。店の DB に表が 1 つ増えるだけです（`tenants:migrate` で流れます。セットの中にセットを断る引き金〔トリガー〕も一緒に作られます）。セットの在庫は構成の品から数えるので、ショップの一覧を開くたびに在庫の問いが 1 つ増えます（セットの数だけでなく 1 回の問いにまとめています）。セットの商品そのものに在庫を入れる必要はありません（売れる数と出す拠点は構成の品から数えます）。セットと構成の品は同じ拠点に置いてください（数えるのは拠点ごとです。plugins/plugin-bundle/README.md）
- **App「購入の制限」を使うとき**: 定期の処理もキューもメールもありません。店の DB に表が 1 つ増えるだけです（`tenants:migrate` で流れる）。決め方と決まりは plugins/plugin-purchase-limits/README.md
- **App「公開の予約と販売期間」を使うとき**: 定期の処理もキューもメールもありません。店の DB に表が 1 つ増えるだけです（`tenants:migrate` で流れる）。出す・終わるの判断は、お客さんが画面を開いたその時の時刻で決まるので、サーバーの時計（UTC のまま）がずれていないことだけ確認します（店の人が入れる日時は日本時間として読みます。plugins/plugin-sale-period/README.md）
- **App「年齢確認」を使うとき**（ADR 0077）: 定期の処理もキューもありません。満年齢は日本の暦（Asia/Tokyo）の今日で数えるので、サーバーの時計（UTC のまま）がずれていないことだけ確認します。生年月日はどこにも残さないので、バックアップに足すものもありません（plugins/plugin-age-check/README.md）
- **App「友だち紹介」を使うとき**（ADR 0075）: 設定の「ポイント」を使うにしておきます（使わない店では働きません）。紹介のポイントは、会員になった・払われた・発送したの通知でその場で決まり、scheduler が毎時 45 分に `referral:run` を実行して取りこぼし（キューや受信側の落ちた時）を拾います（App を使う店だけ）。メールは送りません（plugins/plugin-referral/README.md）
- **サーバーの監視**: 証明書・空き容量・更新・バックアップはサーバーの外からしか見えないので、`ec-sugar` が見て運営ダッシュボードへ渡します。何を見て、どこに通知が来るかは下の「サーバーの監視と通知」
- **`/up` は中央の DB に実行していないマイグレーションがあると 500** を返します。`ec-sugar update` はマイグレーションの後に切り替えるので、ふつうは起きません。手でイメージを切り替えた時に 500 になったら `php artisan migrate --force` を実行します

## 入れたときのサンプル

最初のショップに、見本の商品・カテゴリ・テーマの中身（トップのセクション）・特商法の表記の見本・送料を入れます（ADR 0053）。どれも名前に「（サンプル）」が付き、説明の先頭に通知が入ります。お客さんと注文は入れません。管理画面のホームの「サンプルをまとめて消す」（オーナーだけ）か、下の `--remove` で消せます。

| セット（`--set`） | 中身 | テーマ | 写真 |
|---|---|---|---|
| `zakka`（既定） | 雑貨のお店。商品 8 点（種類とオプションの見本を含む）・カテゴリ 3 つ | 角砂糖（default） | 自作のアイコンを同梱（取りに行かない） |
| `sweets` | お菓子のお店。焼き菓子とジャム 18 点・カテゴリ 5 つ | 角砂糖（default） | 入れる時に Unsplash から 26 枚 |
| `fashion` | 布ものとツールのお店。エプロン・マグ・帆布のトート（種類 20・オプション 3 つ）・カテゴリ 2 つ | fashion | 入れる時に Unsplash から 11 枚 |
| `botanica` | 植物のお店。観葉植物・多肉・園芸用品 8 点・カテゴリ 3 つ | botanica | 入れる時に Unsplash から 5 枚 |

インストーラーは 2/5 で選んだセット（設定ファイルの `SAMPLES`）を 4/5 で入れます。入れた後に別のセットへ替えるときや、インストーラーを使わないときは app のコンテナの中で次のコマンドを実行します。

```sh
php artisan shop:sample <店>                          # 雑貨のお店（今までと同じ）
php artisan shop:sample <店> --set=sweets             # お菓子のお店（写真はその場で取る）
php artisan shop:sample <店> --set=fashion --photos=/path/to/photos   # 写真を取りに行かず、手元のフォルダから（<名前>.jpg などを名前で合わせる。ネットの無いサーバー向け）
php artisan shop:sample <店> --remove                 # まとめて消す
php artisan shop:sample <店> --force                  # お店の商品がもうある店にも入れる
```

- **テーマを切り替えます**: 入れ終えたら、店のテーマをセットのテーマへ切り替えます（中央の監査ログに残る。もうそのテーマなら何もしない）。消してもテーマは戻しません
- **1 つの店に入れるのは 1 つのセットだけ**: 別のセットが残っている店には入れません（`--force` でも）。替えるときは先に `--remove` で消してから入れます。前のセットのテーマの中身は、消すと空のトップに戻り、お店がその後に触っていなければ次のセットの中身に替わります（触っていれば、お店の物として残します）
- **再実行しても増えません**。入れた物には印が付き、途中で落ちても再実行すると残りだけを入れます。一度消したセットは、同じ店にもう一度は入りません（「もう入っています」）
- **送料は消しても残ります**（お店が使い続けることが多いため）。どのセットも「通常便」1 つを全国 ¥800・¥5,000 以上で無料にし（ADR 0064。地域ごとの額やクール便は入れません）、表記の見本の文と合わせてあります。送料の表がまだ入れたときのまま（方法 1 つ・全部 0 円・無料の線なし）の店だけに入れます。お店がもう送料・特商法の表記・テーマの中身・同じ slug のカテゴリを持っていれば、そこには触りません
- **写真を取る所は `unsplash.com` と `images.unsplash.com` の https だけ**です（リダイレクトの行き先も確認し、3 回まで）。1 枚 10 MB まで・画像（JPEG・PNG・WebP）だけ・接続する 5 秒と全体 20 秒で打ち切り。取った写真は一時フォルダ（`/tmp`）に置き、ほかの写真と同じく作り直して（WebP に）から入れ、入れ終えたらフォルダごと消します。**取れなかった写真は、その写真なしで入れて注意を出します**（止めない。終わりの番号は 0）。サーバーから外へ https で出られないときは、写真なしで入ります（`--photos` で手元から入れられます）
- **写真のアップロードの回数の上限**（1 分に 30 枚。`MEDIA_UPLOADS_PER_MINUTE`）に触れそうなら、その前で待ちます（「… 秒待ちます」と出る）。セットの写真はどれも 30 枚の内です
- **写真の取得元**: 撮った人の名前は、各商品の説明の終わりの一行（「写真: …（Unsplash）」）と、サンプルのフラグ（店の DB の `sample_marks.snapshot` の `credits`・`credit`。写真の ID か URL・撮った人・ページ）に残します。Unsplash の写真は Unsplash のライセンス（https://unsplash.com/license）で、表記は求められていませんが、取得元を追えるようにしています
- 終わりの番号: `0` 入れた・もう入っていた・消した（写真が欠けても 0）／ `1` 断った・入れ終えられなかった（店が無い・お店の商品がある・別のセットが残っている・途中の失敗・テーマを切り替えられなかった）／ `2` 引数の誤り（店の ID の形・無いセット・`--remove` と `--set`・`--photos`・`--force` を一緒に・無い写真の保存先）
- 開発の見本の店（`sugar.localhost`・`demo.localhost`・`midori.localhost`）も同じセットから作ります（`SugarShopSeeder` が sweets・`DemoTenantSeeder` が fashion・`BotanicaShopSeeder` が botanica。写真は手元の `storage/app/demo/<sugar|samples|botanica>/` から。取りに行かない）

### 誰でも触れるデモの店（ADR 0086）

入れたときのサンプルとは別に、誰でも触れる架空の店（酒蔵・コーヒー焙煎所・和菓子店・ライブハウス）を公開できます。`shop:create` で店を作り、`php artisan shop:demo <店> --on` で印を付けてから `php artisan demo:shop <扱い> --tenant=<店>` で中身を入れます。印のある店はお金とメールを動かさず（Stripe はテストの鍵だけ・メールは来訪者の画面に出すだけ）、毎晩 04:50 に作った時の状態へ戻ります（定期の処理 `demo:reset`。scheduler のコンテナが実行する）。在庫・予約の枠・予約販売の数・席は 1 時間ごとに作った時の数へ戻り（`demo:restock`）、注文は来訪者ごとに 1 日 5 件まで。店への通知・LINE・計測のタグの App は使えません。

## すでにある Web サーバーの後ろに置く（Plesk など）

ほかのサイトを載せたサーバー（Plesk・cPanel・素の nginx・Apache）に入れるときは、そのサーバーの Web サーバーを止めずに、**その後ろに置きます**（`FRONT=proxy`。ADR 0031 の 5）。インストーラーは、80・443 番がすでに使われていると気づくと、この形にするかを聞きます（既定はこの形）。

- EC-SUGAR はリバースプロキシ（Caddy）を起動せず、受付の nginx を **TCP では待たせず、Unix ソケット `/var/lib/ec-sugar/front/web.sock` だけで**待たせます
- ソケットのディレクトリ（`/var/lib/ec-sugar/front`）は、持ち主が root・グループが専用の `ec-sugar-front`（EC-SUGAR の nginx のコンテナが足し、ソケットを作る）の 2770 で、**前段のリバースプロキシの働き手（worker）のグループには ACL で通り抜けだけ**を許します（中は見られず、ソケットにだけ接続する。`getfacl /var/lib/ec-sugar/front` で見られる）。同じサーバーの別の利用者（ホスティングの契約者の PHP・乗っ取られた WordPress・cron・SSH の利用者）はディレクトリに入れず、EC-SUGAR に接続できません
- グループは、**443 番を本当に持っているプロセス**（`ss -ltnp` の pid）の働き手から見分けます（`ps -o user,group --ppid <pid>` で見られる。見分けられなければ聞く。設定ファイルは `PROXY_GROUP`）。次のときは止まります:
  - 共有のグループ（`psacln`・`psaserv`・`users`・`staff`・`adm`・`sudo` など）
  - 働き手のほかの利用者が所属している（補助の所属と主のグループの両方を見る）
  - **そのグループの権限で前段のリバースプロキシのほかに動くもの（php-fpm・Apache の mod_php など）がある**。見るのは、今動いているプロセス（実効と補助のグループを番号で比べる）・php-fpm のプールの設定（`/etc/php/*/fpm/pool.d/*.conf` と Plesk の `/opt/plesk/php/*/etc/php-fpm.d/*.conf` の `user`・`group`。ondemand のプールは要求が来るまでプロセスが無いため）・`/etc/apache2/envvars` の `APACHE_RUN_GROUP`・そのグループの利用者の crontab。その PHP からもソケットに接続し、接続元を偽れるためです。PHP は別の利用者とグループで動かしてください（Plesk はサイトごとの利用者で動かすのが既定）。分かったうえで進めるときだけ、設定ファイルに `PROXY_GROUP_SHARED_OK=1` を書きます（`status` に出し続けます）
  - 127.0.0.1 の番号で待つ形（この形の最初のバージョン）は、ホストのどの利用者からも前段のリバースプロキシと同じ IP アドレスに見え、`X-Forwarded-For` を偽って管理画面の接続元の制限・ログインとショップの回数の制限を越えられたので、ソケットに替えました（2 回目の見直し）
- 80・443 番と **証明書は、すでにある Web サーバーが受け持ちます**（Let's Encrypt もそちらで取る）。EC-SUGAR は ufw と DOCKER-USER に触りません。ホストのファイアウォール（OUTPUT の決まり。下の「ホストのファイアウォール」）は、この形でも入れます
- **Plesk のファイアウォールを適用した後や、iptables を作り直した後は `sudo ec-sugar firewall`** を実行します（ほかのツールがホストのファイアウォールの決まりを消すことがある。`sudo ec-sugar status` が ✗ を出す。毎晩の更新の確認でも当て直す）
- 3/5 の DNS の確認は残します（名前がこのサーバーを向いているか）
- 本当の接続元: ソケットから来た要求（前段のリバースプロキシ）が付けた `X-Forwarded-For` の**最後の IP アドレス**を本当の接続元とみなします（その Web サーバーが「付け足す」形でも「付け直す」形でも正しい。前の方は外から偽れるので使わない）
- https: 前段のリバースプロキシが `X-Forwarded-Proto: https` を付けて渡さないと、EC-SUGAR は https へ送り直します（308）。http を https へ送る設定は前段のリバースプロキシで
- **設定を足したら `sudo ec-sugar check-front` を実行します**。管理画面とショップの両方のホストを、前段のリバースプロキシを通して https で開きます。偽の `X-Forwarded-For` と偽の `X-Forwarded-Proto: http` を付けて送り、EC-SUGAR に届いた接続元がこの確認の送り元（待ち受けるアドレスか 127.0.0.1）と同じ IPv4 のときだけ、`X-Forwarded-Proto` を https に付け直しているときだけ ✓ にします（そのまま渡す・落とす・別の IP アドレスに付け直す設定は ✗。✓ になるまで登録の URL を開かない）。同じ確認は 5/5（設定がまだなら「まだ」と出す）・`ec-sugar status`・自動の更新の後にも流れます
- ヘルスチェックは、ホストの root がソケットへ Host を付けて聞きます

**Plesk**（5/5 にも同じ手順が出ます）:

1. 「ウェブサイトとドメイン」で、管理画面のホスト（例 `admin.example.jp`）とショップのホスト（例 `example.jp`）の **2 つのドメイン（かサブドメイン）**を作る。1 店舗の形ならワイルドカードは要りません（複数の店の形は `*.example.jp` のワイルドカードのサブドメインか、店ごとの名前）
2. それぞれの「SSL/TLS 証明書」で Let's Encrypt の証明書を取り、http を https へ送る
3. それぞれの「Apache & nginx の設定」で「プロキシモード」を外し、「追加の nginx ディレクティブ」に次を貼る
4. **どのサービスプランでも（プランに縛られない契約も）、契約者に Web サーバーの設定（追加の Apache と nginx のディレクティブ）を変える権限を与えていないことを確認する**（「サービスプラン」→ 各プラン →「権限」）。与えていると、ほかの契約者が自分のドメインに `proxy_pass http://unix:/var/lib/ec-sugar/front/web.sock:` と偽の `X-Forwarded-For` を書き、前の nginx（ソケットに入れるグループ）を通して管理画面の接続元の制限を偽れます
5. `sudo ec-sugar check-front` が ✓ になってから、登録の URL を開く

```nginx
location ~ ^/ {
    proxy_pass http://unix:/var/lib/ec-sugar/front/web.sock:;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    client_max_body_size 100m;
}
```

（`location ~ ^/` は、Plesk が Let's Encrypt の確認に使う `/.well-known/acme-challenge/` の場所より後に選ばれるので、証明書の取り直しを邪魔しない）

（`client_max_body_size 100m` は、商品の CSV と一緒に上げる写真の ZIP〔90MB まで〕のため。ほかの経路は EC-SUGAR の中の nginx が断る〔管理画面の API は 12m、ショップ・通知・ログインは 1m。〕ので、前段のリバースプロキシではまとめて 100m にしてよい。Apache の既定の上限〔`LimitRequestBody`、2.4.54 から 1GB〕は小さくしていなければそのままでよい。前に Cloudflare を置くときは、1 回の要求の上限が 100MB〔Free・Pro のプラン〕なので ZIP を 90MB までにしている）

**素の nginx**: 管理画面とショップの名前の `server`（443 番・証明書・http を https へ送る設定はその nginx で）の中に、上と同じ `location` を書く。

**Apache**（2.4.7 以上。`mod_proxy`・`mod_proxy_http`・`mod_headers`。ソケットのディレクトリに入れるのは Apache の働き手のグループ〔例 www-data〕。設定ファイルの `PROXY_GROUP`）:

```apache
<VirtualHost *:443>
    ServerName admin.example.jp
    ServerAlias example.jp
    # SSLEngine on と証明書の行はその Apache の書き方で
    ProxyPreserveHost On
    ProxyPass        / unix:/var/lib/ec-sugar/front/web.sock|http://localhost/
    ProxyPassReverse / unix:/var/lib/ec-sugar/front/web.sock|http://localhost/
    RequestHeader set X-Forwarded-Proto "https"
    # X-Forwarded-For は mod_proxy が付け足す。RequestHeader で書き換えない
</VirtualHost>
```

`X-Forwarded-For` を足さずに、外から来たものをそのまま渡す設定にすると、管理画面の接続元の制限を偽れます。`check-front` が止まったら、転送の設定に `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;` を足して再実行します。

**前段のリバースプロキシの前に CDN（Cloudflare のプロキシなど）があるとき**: 前段のリバースプロキシが付け足す最後の IP アドレスは CDN の IP アドレスになり、管理画面の接続元の制限は CDN の IP アドレスで判断されてしまいます。CDN を通すときは、前段のリバースプロキシで CDN の本当の接続元のヘッダー（Cloudflare なら `CF-Connecting-IP`）を `X-Forwarded-For` に付け直す設定（nginx の `real_ip` など）を入れるか、管理画面のホストだけ CDN を通さないでください（今は EC-SUGAR の側では見分けません）。

## Cloudflare Tunnel で出す形

家やオフィスのネットワークに置いたサーバー（例: 手元のマシンの上の VM）を、**ルーターのポートを開けずに**出す形です（`FRONT=tunnel`。ADR 0052）。サーバーの中の `cloudflared`（Cloudflare の公式のツール）が Cloudflare へ接続に行き、お客さんの要求はその道で届きます。

```
お客さん → Cloudflare → cloudflared（このサーバーから外へつなぎに行く）→ Caddy（127.0.0.1 の 443 番。https と証明書）→ nginx → PHP
```

- リバースプロキシは今までの Caddy の形に `cloudflared` を重ねたもの（`docker-compose.tunnel.yml`）です。Caddy は今までどおり https で受け、**待ち受けるアドレスは 127.0.0.1 に決まります**（設定ファイルの `BIND`。ほかの値は 2/5 で止まる）。外から 80・443 番には届かず、ufw でも開けません
- **この形の Caddy は、TLS の名前（SNI）と Host の違いを断りません**（`front-tunnel.servers` の `strict_sni_host insecure_off`。ほかの形は今までどおり 421）。`cloudflared` は行き先の規則ごとに接続を使い回し、SNI は新しく接続する時にしか決まらないので、複数の店の形の `*.ショップのドメイン` の規則では、ある店の名前で接続した接続に別の店の要求が載ります。断ると本物のショップの要求が 421 になります（2026-11-09 に本番で、ワイルドカードの DNS を足して店の無い名前を開いたら 421 になって分かった）。この形の Caddy に届くのは `cloudflared` とサーバーの中の 127.0.0.1 だけで、店と管理画面の防御は要求ごとの Host と接続元で決まるので、SNI と Host をずらしても、その Host を名前どおりに開いたのと同じ扱いにしかなりません
- `cloudflared` は Caddy の 443 番へ、**要求のホスト名どおりの名前（SNI）で https で接続します**（`matchSNItoHost`）。Cloudflare から先も暗号のままです。Cloudflare の SSL/TLS の設定は「フル（厳密）」にし、「常に HTTPS を使用」を入れます（入れ忘れても、http のまま来た要求は Caddy が `CF-Visitor` を見て https へ送ります）。ネットワークの「Pseudo IPv4」は「切」のままにします（「上書き」にすると、IPv6 のお客さんの住所が作り物の IPv4 になり、回数の制限の /64 のまとめと管理画面の接続元の範囲が壊れます）
- **証明書は Cloudflare の Origin CA の証明書（`TLS_CERT`・`TLS_KEY`。勧める）か DNS の鍵（`CLOUDFLARE_TOKEN_FILE`）**。外から 80 番に届かないので、自動（auto）では取れません（2/5 で止まる）。この形で Caddy の証明書を確認するのは同じサーバーの `cloudflared` だけで、お客さんのブラウザは Cloudflare の証明書を見ます。なので Cloudflare の画面で作る Origin CA の証明書（15 年）で足り、ゾーン全体を書き換えられる DNS の鍵をサーバーに置かずに済みます（作り方は下の「Cloudflare の Origin CA の証明書」）。公開の認証局の証明書を手で渡してもかまいません
- **本当の接続元**: Caddy は、`cloudflared` の IP アドレス（Caddy と cloudflared だけの内側のネットワーク `tunnel` で固定。既定 `172.30.255.242`）から来た接続のときだけ、Cloudflare が付ける `CF-Connecting-IP` を接続元にします（`docker/caddy/etc/front-tunnel.servers`）。ほかから来た `CF-Connecting-IP`・`X-Forwarded-For`・`True-Client-IP` は使わず、nginx へは Caddy が決めた接続元だけを渡します。管理画面の接続元の制限・ログインとショップの回数の制限は、これに頼ります
- **管理画面を開ける接続元（`ADMIN_ALLOWED_IPS`）は、ブラウザで見る端末のグローバル IP アドレス**です（Cloudflare を通るので、家の LAN の IP アドレス `192.168.…` ではない）。端末が IPv6 で Cloudflare に接続するなら、IPv6 の範囲（例 `2001:db8:1234:5678::/64`）も書きます。自分の IP アドレスは、その端末で https://1.1.1.1/cdn-cgi/trace を開くと `ip=` の行に出ます。家の回線の IP アドレスは変わることがあります。変わって入れなくなったら、サーバーで `sudo ec-sugar admin-ips <新しい住所,…>`
- ショップのドメインは、自分の Cloudflare のアカウントにあるゾーンだけです（トンネルの行き先と DNS の確認に要る）
- 複数の店の形で、ショップのドメインが 2 階層より深い（例 `*.shops.example.jp`）と、Cloudflare の無料の証明書（Universal SSL）では含められません（`*.example.jp` までの 1 階層だけ）。そのときは Cloudflare の Advanced Certificate を足すか、ショップのドメインを `example.jp` の直下にします

### 1. Cloudflare でトンネルを作る

どこか 1 台（Mac でよい）に `cloudflared` を入れ、**コマンドで**作ります（このサーバーの `config.yml` の行き先が使われる形。下の注意）。

```sh
cloudflared tunnel login                                  # ブラウザが開くので、使うドメイン（ゾーン）を選ぶ
cloudflared tunnel create ec-sugar-prod                   # トンネルを作る（ID が出る）
cloudflared tunnel token ec-sugar-prod > tunnel-token     # トークン（eyJ で始まる 1 行）をファイルに
cloudflared tunnel route dns ec-sugar-prod admin.example.jp   # 管理画面の CNAME を作る（3/5 の 2 行と同じもの）
cloudflared tunnel route dns ec-sugar-prod example.jp         # ショップ（複数の店は '*.example.jp'）
```

- トークンは、トンネルに接続するための秘密です（持っている人は誰でも、このトンネルの行き先を装える）。`login` で Mac にできる `~/.cloudflared/cert.pem`（アカウントの鍵）と、`create` でできる `~/.cloudflared/<ID>.json` も同じ重さです。リポジトリやチャットに入れず、終わったら消すか、パスワード管理ツールに移します
- **Cloudflare の画面（Zero Trust → Networks → Tunnels）で作ったトンネルは、行き先を Cloudflare が持つ形**になり、このサーバーの `config.yml` の行き先は使われません。画面で作ったときは、その画面の「Public Hostname」に、管理画面とショップのホストを「HTTPS・`caddy:443`」で足し、追加の設定の TLS で「Match SNI to Host」を入れます（「No TLS Verify」は入れない）。画面を触れる人が行き先を変えられるので、コマンドで作る形を勧めます

**アカウントの鍵（cert.pem）を作らない作り方**（EC-SUGAR の本番はこれで作った。2026-11-04）: Cloudflare の画面（マイ プロフィール › API トークン）で、権限「アカウント › Cloudflare Tunnel › 編集」だけ・範囲はそのアカウントだけ・期限は 1〜2 日の鍵を作り、API で `config_src` を `local` にして作ります（このサーバーの `config.yml` の行き先が使われる形）。作り終えたら鍵は画面で消します。CNAME は Cloudflare の画面（DNS › レコード）で足すか、期限の短いゾーンの DNS の鍵で API から足し、終わったら鍵を消します（サーバーには置かない）。

```sh
A=<アカウントの ID>; H='Authorization: Bearer <Tunnel の編集だけの鍵>'
curl -s -X POST -H "$H" -H 'Content-Type: application/json' \
  --data "{\"name\":\"ec-sugar-prod\",\"config_src\":\"local\",\"tunnel_secret\":\"$(openssl rand -base64 32)\"}" \
  https://api.cloudflare.com/client/v4/accounts/$A/cfd_tunnel          # 出てきた result.id がトンネルの ID
curl -s -H "$H" https://api.cloudflare.com/client/v4/accounts/$A/cfd_tunnel/<ID>/token | jq -r .result > tunnel-token
```

### 2. トークンのファイルをサーバーに置く

```sh
# Mac から
scp tunnel-token サーバー:~/
# サーバーで（root だけ読める 0600 にしてから、設定ファイルにパスを書く）
sudo install -m 0600 -o root -g root ~/tunnel-token /root/tunnel-token && rm ~/tunnel-token
```

設定ファイル:

```ini
FRONT=tunnel
TUNNEL_TOKEN_FILE=/root/tunnel-token
# 証明書は Cloudflare の Origin CA のもの（下の「Cloudflare の Origin CA の証明書」）
TLS_CERT=/root/origin-tls/cert.pem
TLS_KEY=/root/origin-tls/key.pem
# DNS の鍵で取るなら、上の 2 行の代わりに CLOUDFLARE_TOKEN_FILE=/root/cloudflare-token（ゾーン DNS の編集だけを許した鍵）
# 証明書は DNS の鍵で（下の「証明書の取り方」の dns。ゾーン DNS の編集だけを許した鍵）
CLOUDFLARE_TOKEN_FILE=/root/cloudflare-token
# トンネルの監視の読むだけの鍵（任意。下の 5.）
TUNNEL_WATCH_TOKEN_FILE=/root/tunnel-watch-token
# BIND は書かない（127.0.0.1 に決まる）
```

インストーラーは、トークンの形（base64 の JSON で、アカウント・トンネルの ID・秘密がある）を確認してから、`/srv/ec-sugar/secrets/tunnel/token` にコピーします。元のファイルは消してかまいません（2 回目からはコピーしたものを使う）。**トークンは環境変数・コマンドの引数・記録・画面に出しません**（`cloudflared` には `--token-file` でファイルとして渡す）。形が違えば、中身を出さずに止まります（よくあるのは、画面に出た `cloudflared service install eyJ…` の行を丸ごと貼ったもの。`eyJ…` の 1 行だけにする）。

`cloudflared` の行き先（`/srv/ec-sugar/secrets/tunnel/config.yml`）は、インストーラーが設定ファイルから書きます（手で直さない。設定ファイルを直して再実行する）:

```yaml
ingress:
  - hostname: "admin.example.jp"
    service: https://caddy:443
    originRequest:
      matchSNItoHost: true
  - hostname: "example.jp"          # 複数の店は "*.example.jp"
    service: https://caddy:443
    originRequest:
      matchSNItoHost: true
  - service: http_status:404
```

リバースプロキシの証明書が Cloudflare の Origin CA のものなら、行き先ごとの `originRequest` に `caPool: /etc/cloudflared/origin-ca.pem` が付きます（下の節）。確認を切る `noTLSVerify` は書きません。

### Cloudflare の画面で切っておくもの（Tunnel の形と、Cloudflare のプロキシの後ろに置くとき）

- **「メールアドレスの難読化」（Scrape Shield → Email Address Obfuscation）は、オフにしておくと確か**です。既定でオンで、Cloudflare が HTML の中のメールアドレスを書き換え、戻すための script を差し込みます。ショップと管理画面の CSP（Trusted Types）はその script を止めるので、特商法の表記などのメールアドレスが「[email protected]」のまま出ます（2026-11-10 に本番で起きた）。リバースプロキシ（Caddy） は HTML の応答の `Cache-Control` に `no-transform`（書き換えるな、の印）を足すので、オンのままでも Cloudflare は書き換えません。ただし Cloudflare の画面の「キャッシュの決まり」などで見出しを上書きするとこの印が外れるので、オフにしておくのがいちばん確かです
- 同じ理由で、HTML を書き換える機能（Rocket Loader など）は入れません
- 圧縮はリバースプロキシ（Caddy） がします（zstd か gzip。CSS・JavaScript・JSON・SVG など）。**HTML は圧縮しません**。ショップの HTML には CSRF トークンと、検索の言葉など外から決められる文字が同じ本文に並び、圧縮すると暗号の長さからトークンを当てる攻撃（BREACH）の面になるためです。`no-transform` の付いた HTML は Cloudflare も圧縮しないので、HTML は圧縮されずに届きます。Cloudflare の画面で HTML の圧縮を足さないでください。写真には印を付けないので、Cloudflare の画像の最適化はそのまま使えます
- すでにある Web サーバーの後ろに置く形（上の節）はリバースプロキシ（Caddy）を通らないので、`no-transform` も圧縮も付きません。その形を Cloudflare の後ろに置くときは、「メールアドレスの難読化」をオフにしてください。前段のリバースプロキシでも HTML（`text/html`）は圧縮しないでください（上と同じ BREACH の理由。CSS・JavaScript は圧縮してよい）

### Cloudflare の Origin CA の証明書

この形では、Caddy の証明書を確認するのは同じサーバーの `cloudflared` だけです（お客さんのブラウザは Cloudflare の証明書を見る）。なので Cloudflare が無料で出す **Origin CA の証明書**（期限は最長 15 年）を手で渡す形（manual）にすると、証明書を取るための DNS の鍵（ゾーン全体を書き換えられる）をサーバーに置かずに済みます。Origin CA は公開の認証局ではないのでブラウザは信頼しません。**Cloudflare Tunnel の形でだけ使えます**（ほかの形で渡すと 2/5 で止まる）。

1. **本番のサーバーで、秘密の鍵と CSR（証明書を作ってもらう申し込みのファイル）を作ります**。秘密の鍵はサーバーから出しません（Mac やチャットにコピーしない。Cloudflare に作らせる形も使わない）:

   ```sh
   sudo install -d -m 0700 -o root -g root /root/origin-tls
   sudo sh -c 'umask 077 && openssl req -new -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes \
     -keyout /root/origin-tls/key.pem -out /root/origin-tls/req.csr -subj "/CN=example.jp"'
   sudo cat /root/origin-tls/req.csr      # これ（CSR）だけを次の画面に貼る
   ```

2. **Cloudflare の画面で証明書を作ります**: そのドメインの SSL/TLS › Origin Server（配信元サーバー）› 証明書を作成 › 「自分の秘密の鍵と CSR を使う」（Use my private key and CSR）を選び、上の CSR を貼ります。ホスト名には管理画面とショップを含む名前（例 `example.jp` と `*.example.jp`。複数の店でショップのドメインが深いなら `*.shops.example.jp`）、期限は 15 年、形は PEM。出た「配信元証明書」を、サーバーの `/root/origin-tls/cert.pem` に貼って保存します（`sudo nano /root/origin-tls/cert.pem`。証明書は秘密ではない）
3. **設定ファイル**に `TLS_CERT=/root/origin-tls/cert.pem` と `TLS_KEY=/root/origin-tls/key.pem` を書き、`CLOUDFLARE_TOKEN_FILE` は書かない（上の 2. の例）。実行すると、インストーラーが次を行います:
   - 2/5 と 4/5: 証明書の発行者が Origin CA なら、設定ファイルが Tunnel の形か・Cloudflare の Origin CA の元の証明書で確認できるか（名前だけを似せたものを断る）・鍵と対か・管理画面とショップの名前を含むか・期限、を確認する
   - 4/5: 証明書と鍵をリバースプロキシの秘密の保存先（`/srv/ec-sugar/secrets/caddy/tls/`。鍵は Caddy だけが読める 0400）にコピーする。Cloudflare の Origin CA の元の証明書（リポジトリの `deploy/cloudflare/origin-ca-roots.crt`。Cloudflare の公式の文書から取った RSA と ECC の 2 つ。取得元と指紋はファイルの頭）を `/srv/ec-sugar/secrets/tunnel/origin-ca.pem`（root・cloudflared のグループ・0440）にコピーし、`config.yml` の行き先ごとに `caPool` で指す（元の証明書を置いてから設定を書く）。替えたら Caddy と `cloudflared` を作り直す
   - コピーした後は、`/root/origin-tls/` の元のファイルは消してかまいません（2 回目からはコピーしたものを使う）
4. 期限の監視（`sudo ec-sugar status` と運営ダッシュボード）は、Caddy が出している証明書の期限（15 年先）を読みます。**Origin CA の元の証明書は 2029-08-15 に切れます**（`deploy/cloudflare/origin-ca-roots.crt` の頭）。Cloudflare が新しい元を出したら、リポジトリのファイルを替えたバージョンでインストーラーを再実行、必要なら証明書も作り直します（監視は渡した証明書の期限だけを見るので、元の期限は見ない）

公開の認証局の証明書を渡したときは、`caPool` を付けません（`cloudflared` はふつうの認証局を信頼する。前に置いた `origin-ca.pem` は片付ける）。

**DNS の鍵（dns）から Origin CA（manual）へ切り替える**（入れた後のサーバー）:

1. 上の 1.〜2. で、秘密の鍵・CSR・証明書を本番のサーバーに作る
2. `sudo nano /srv/ec-sugar/install.answers` で、`CLOUDFLARE_TOKEN_FILE=…` の行を消し（`#` でコメントにする・値を空にするでもよい）、`TLS_CERT=/root/origin-tls/cert.pem` と `TLS_KEY=/root/origin-tls/key.pem` を足す。`--answers` で渡すファイルにも `CLOUDFLARE_TOKEN_FILE` が残っていれば消す（設定ファイルに無い項目は `--answers` のファイルから足されるので、また入って「同時に使えません」で止まる）
3. リポジトリを新しくして（`git pull`。`deploy/cloudflare/origin-ca-roots.crt` があるバージョン）、`sudo ./deploy/install.sh` を再実行する。証明書を確認してコピーし、Caddy（証明書の取り方 manual）と `cloudflared`（`caPool` つき）を作り直し、健全になった後に**リバースプロキシの秘密の保存先の DNS の鍵（`/srv/ec-sugar/secrets/caddy/cloudflare_token`）を消します**（途中で止まったときは、前の取り方に戻せるように消さずに残る）
4. 確認する: `sudo ls /srv/ec-sugar/secrets/caddy/` に `cloudflare_token` が無い、`sudo ls /srv/ec-sugar/secrets/tunnel/` に `origin-ca.pem` がある、`sudo ec-sugar check-front` が通る、`sudo ec-sugar status` の証明書の期限が 15 年先
5. **Cloudflare の画面（マイ プロフィール › API トークン）で DNS の鍵を取り消し**、元のファイル（`/root/cloudflare-token` など）も消す。サーバーから消すだけでは、コピーを持たれていれば使えるため

### 3. DNS

3/5 で、Cloudflare の DNS に足す 2 行が出ます（1. の `cloudflared tunnel route dns` で作ったなら、もうある）:

```
   種類 CNAME   名前 admin.example.jp   値 <トンネルの ID>.cfargotunnel.com
   種類 CNAME   名前 example.jp         値 <トンネルの ID>.cfargotunnel.com
```

プロキシの状態は「プロキシ済み」（オレンジの雲）。同じ名前の A・AAAA レコードがあれば消します。

複数の店の形では、2 行目は最初の店のホストで、**店を足すたびにその店のホストの CNAME を足します**（`sudo ec-sugar shops` が一覧を出す。Stripe Connect を使うなら `api.<ドメイン>` も）。cloudflared の行き先は `*.<ドメイン>` のワイルドカードのままなので、設定とトンネルは作り直しません。ワイルドカードの CNAME（`*.<ドメイン>` の 1 行）でも動きますが、**同じドメインに別のサーバーのサイト（`www` など）があるときは勧めません**: 明示の行は食われないものの、行の無い名前（打ち間違い・消し忘れたサイトの名前・行を足し忘れた新しいサイト）が全部このサーバーへ来ます（ADR 0090）。

### 4. 確認する

5/5 と `sudo ec-sugar check-front`（DNS を足した後に実行する）が、管理画面のホストで次を確認します。

1. サーバーの中からリバースプロキシの Caddy（127.0.0.1 の 443 番）へ、偽の `CF-Connecting-IP`・`X-Forwarded-For`・`True-Client-IP` を付けて送り、EC-SUGAR に届いた接続元が偽の IP アドレスでない（偽れたら 5/5 で止まる）
2. 公開の DNS（DNS over HTTPS）で名前を引いて外（Cloudflare）を回り、`https://<管理画面>/up` が 200 で、届いた接続元が偽の IP アドレスでも `cloudflared` の IP アドレスでもない（Cloudflare が付けた本当の接続元が届く）。まだ開けなければ（DNS がまだ・トンネルがまだ）、5/5 は止めずに案内だけを出します

`sudo ec-sugar status` は、`cloudflared` が動いていて Cloudflare に接続されているか（`running healthy`）と、最後の確認の結果を出します。`cloudflared` の記録は `sudo ec-sugar logs cloudflared`。

- 停電やルーターの再起動の後は、回線が戻るまで `cloudflared` だけが接続できません。ほかが動いていれば EC-SUGAR は起きたままで、`cloudflared` は回線が戻れば自分で接続し直します（`status` に ✗）
- **家の回線や停電で止まったことは、サーバーの中からは通知できません**。外から `https://<管理画面>/up` を数分ごとに開く監視（Cloudflare の Health Checks〔有料のプラン〕か、無料の外部監視のサービス）を用意します
- `cloudflared` のバージョンは compose で digest まで固定しています（自分では更新しない。`--no-autoupdate`）。利用者は root でも Caddy でもない 65533 で、根は読み取り専用・権限なし・ポートを出しません。トークンと設定の保存先は読み取り専用で `cloudflared` にだけ差し込みます（`docker/check-compose.sh` が監視する）

### 5. トンネルの監視（漏れたトークンに気づく）

トークンを持っている人は、**どこからでも同じトンネルにコネクタ（connector。`cloudflared` のこと）を足せます**。Cloudflare は要求をコネクタの間で振り分けるので、足された偽のコネクタに、お客さんの要求の一部（ログインやカートの中身を含む）が平文で届きます。EC-SUGAR の側からは止められないので、**足されたら気づく**監視を入れます（見直しの中 5）。

`ec-sugar-watch.timer`（10 分ごと。`sudo ec-sugar watch` で今すぐ）が、読むだけの Cloudflare の API の鍵でトンネルのコネクタの一覧を取得し、**コネクタの接続元の IP アドレスが、このサーバー自身の外向けの IP アドレス**（https://1.1.1.1/cdn-cgi/trace の `ip=` を IPv4 と IPv6 で。IPv6 は頭の /64 で比べる）**のどれとも合わないコネクタ**があれば、運営ダッシュボードの要対応「よそからトンネルに接続されています（トークンが漏れたかもしれません）」とオーナーへのメールで通知します。コネクタが 1 つも無い（トンネルが落ちている）も要対応、Cloudflare の API に届かない・鍵の権限が足りないは近いうちに、です。`sudo ec-sugar status` にも最後の結果が出ます。

読むだけの鍵の作り方（Cloudflare の画面の マイ プロフィール › API トークン › トークンを作成 › カスタム トークン）:

| 項目 | 値 |
|---|---|
| 権限 | **アカウント › Cloudflare Tunnel › 読み取り**（これだけ。編集や DNS の権限を足さない） |
| アカウント リソース | 含む › このトンネルのアカウントだけ |
| クライアント IP アドレスのフィルタリング・TTL | 入れなくてよい（家の回線の IP アドレスは変わるため。入れるなら IP アドレスが変わった時に鍵を作り直す） |

```sh
# Mac から（鍵の 1 行だけを書いたファイル）
scp tunnel-watch-token サーバー:~/
# サーバーで
sudo install -m 0600 -o root -g root ~/tunnel-watch-token /root/tunnel-watch-token && rm ~/tunnel-watch-token
# 設定ファイルに TUNNEL_WATCH_TOKEN_FILE=/root/tunnel-watch-token を書いて
sudo ./deploy/install.sh
sudo ec-sugar watch && sudo ec-sugar status    # 「監視（よそのコネクタ）: このサーバーからだけ」と出る
```

- インストーラーは鍵の形を確認してから `/srv/ec-sugar/secrets/tunnel-watch/token`（root だけの 0700 の保存先・0400）にコピーします。トンネルのトークンの保存先とは分けてあり、`cloudflared` には見えません。元のファイルは消してかまいません
- 鍵は環境変数・コマンドの引数・記録・画面に出しません（`curl` へは設定として標準入力で渡す）。Cloudflare の API の答えと、コネクタの IP アドレスも記録と運営ダッシュボードに出しません（渡すのは `ok`・`foreign`・`down`・`unknown`・`unwatched` の語と時刻だけ）
- 鍵が無ければ監視は「見ていない」で、ほかの監視は止めません（運営ダッシュボードの「トンネル」に「監視の鍵が無いので見ていません」）
- コネクタが 0 と出たときは 30 秒待って聞き直してから決めます（更新で `cloudflared` を作り直した直後を「落ちた」と通知しない）
- 見分けられないもの: **同じ家の回線（同じグローバル IP アドレス）の別のマシン**から足されたコネクタ（家の回線の IP アドレスは 1 つなので、サーバーからのものと同じに見える）と、10 分の間に足して外したもの

### トークンが漏れた時

運営ダッシュボードに「よそからトンネルに接続されています」が出たら、トークンが漏れたものとして、**トンネルを作り直してトークンを替えます**（トークンの秘密はトンネルを作る時に決まる。新しいトンネルに移ってから古いトンネルを消すのが確か）。

1. 1. の手順で、**新しい名前のトンネル**を作り、新しいトークンをファイルに出す（例 `ec-sugar-prod-2`。`config_src` は `local`）
2. サーバーに新しいトークンを置き、設定ファイルの `TUNNEL_TOKEN_FILE` をそのファイルにして `sudo ./deploy/install.sh` を再実行する（トークンが替わると `cloudflared` を作り直す）
3. Cloudflare の DNS の CNAME（管理画面とショップ）の値を、新しいトンネルの ID の `<新しい ID>.cfargotunnel.com` に付け替える（`cloudflared tunnel route dns --overwrite-dns ec-sugar-prod-2 <ホスト名>` でも。3/5 と同じ 2 行）
4. 古いトンネルを消す（`cloudflared tunnel cleanup <古い名前>` → `cloudflared tunnel delete <古い名前>`、または API の `DELETE …/cfd_tunnel/<古い ID>`）。**消すと、足された偽のコネクタも切れます**
5. `sudo ec-sugar check-front` で外から開けることを、`sudo ec-sugar watch` → `sudo ec-sugar status` で監視が「このサーバーからだけ」に戻ったことを確認する
6. 漏れた道を探して塞ぐ: トークンを置いたファイル（`/root/` の元のファイル・Mac の `~/.cloudflared/`・手元のメモ）、バックアップ、サーバーの root を取られていないか。root を取られた見込みがあれば、トンネルだけでなく `APP_KEY`・DB のパスワードなどの秘密も替える（サーバーを作り直す）

## ホストのファイアウォール

Docker のネットワークのコンテナの IP アドレスには、ホストのどの利用者からも届きます（ホスティングの契約者の PHP・乗っ取られた WordPress・cron・SSH の利用者も）。EC-SUGAR は、どの形でも（Caddy の形でも、すでにある Web サーバーの後ろに置く形でも）、ホストのファイアウォールを入れます（`ec-sugar-firewall.service`）。

- **OUTPUT のチェーン**に、この compose のネットワーク（internal・edge・front・egress・egress_in。Cloudflare Tunnel の形は tunnel・tunnel_out も）の bridge ごとに 1 つずつ決まりを入れ、**root でない利用者が始めた接続**のうち、公開したポートへの DNAT でないもの（`-m owner --uid-owner 1-4294967294 -m conntrack --ctdir ORIGINAL ! --ctstate DNAT`）を断ります。追跡の記録の無い包みもこの形で断ります。db・redis・nginx・Caddy・Mailpit の IP アドレスへ直接接続できません（php-fpm は、そもそも TCP で待たない）
- root（docker・ec-sugar）の通信、コンテナどうしの通信、**コンテナから始めた接続の返り**には触りません
- **コンテナからホストへ届かない**（INPUT のチェーン。ADR 0102）: EC-SUGAR のネットワークの bridge ごとに INPUT の先頭からチェーン `EC-SUGAR-HOST` へ飛び、ホストから始めた接続の返りと公開したポートへの DNAT のほかは断ります（REJECT）。コンテナが取られても、ホストの SSH・DNS・メールのメールの送信サービスなどへ届きません（外へ出られないネットワークでも、ふつうは bridge の IP アドレスからホストへ届く）。`MAIL_HOST` をホストの Postfix に向けるときは、そのポートを env の `EC_SUGAR_HOST_ALLOW_PORTS`（例 `25,587`。カンマ区切りで 15 個まで）に書き、`sudo ec-sugar firewall` を実行します。書いたポートは INPUT の続き（ufw）に任せるので、ufw でも docker のネットワーク（例 `172.16.0.0/12`）からそのポートを許します
- IPv4（iptables）と IPv6（ip6tables）の両方に入れます。今のネットワークは IPv4 だけでコンテナに IPv6 の IP アドレスはありませんが、Docker の設定で IPv6 が付いても抜けないようにしています
- 公開したポート（Caddy の 80・443 番、ステージングの Mailpit の 127.0.0.1:8025）へホストの中から接続する接続は通します（ホストの監視が自分の https を開ける）。待ち受けるアドレスへの接続は、Docker の nat の OUTPUT の決まり（`! -d 127.0.0.0/8 -m addrtype --dst-type LOCAL -j DOCKER`）でその場で DNAT されて bridge へ出るので、決まりは DNAT された接続を除きます（公開したポートは外からも届くので、ホストの利用者に許しても失うものは無い）。127.0.0.1 の Mailpit は docker-proxy（root）が受けます
- ネットワークを作るときは、コンテナを作るだけ（起動しない）→ ファイアウォールを適用する → 起動する、の順にします（決まりの無い間を作らない）。Docker を再起動したときと、毎晩の更新の確認（「もう最新です」のときも。ステージングは 15 分ごと）にも当て直します
- Docker にネットワークの一覧を聞けないときは、今ある決まりを消さずに残し、`status` が ✗ を出します
- **Plesk のファイアウォールを適用した後や、iptables を作り直した後（`iptables-restore`・ufw の reload など）は `sudo ec-sugar firewall`** を実行します（ほかのツールがこの決まりを消すことがある）。`sudo ec-sugar status` がネットワークごとに `iptables -C` で確認し、足りなければ ✗ と直し方を出します
- ステージングは加えて、DOCKER-USER のチェーンで、決めた LAN の外から 80・443 番へ来たものを落とします（すでにある Web サーバーの後ろに置く形と、127.0.0.1 だけで受ける Cloudflare Tunnel の形は除く）

## 外への通信（送信用プロキシ。ADR 0102）

アプリ（app・queue・scheduler）から外への https は、compose の**送信用プロキシ `egress`** を通ります（`HTTPS_PROXY`）。プロキシは行き先の名前とポート 443 だけを見て、許す一覧に無い宛先を記録し、止める形では断ります。アプリが中を取られても、決まった宛先の外へは送り出せないようにするためです。中身は暗号のままで見ません（記録は名前・ポート・決めたことだけ）。

- **許す一覧**: `docker/egress/allow.txt`（Stripe `api.stripe.com`・LINE `api.line.me`・Slack `hooks.slack.com`・郵便番号 `zipcloud.ibsnet.co.jp`・サンプルの写真 `unsplash.com` `images.unsplash.com`）に、env の `EC_SUGAR_EGRESS_ALLOW`（カンマか空白で区切る。`*.example.com` は下の名前をまとめて許す）を足したもの。商品の CSV の写真の取得元を使う店は、その名前を足します
- **共用の名前を足さない**: `*.amazonaws.com`・`*.cloudfront.net`・`*.googleapis.com`・`*.azureedge.net` のような、誰でも自分のサイトを置ける名前を許すと、そこに置いた攻める人のサイトへ送り出せます（同じ CDN の別のサイトへ行かせる domain fronting も）。足すのは取得元の名前そのもの（`img.example-shop.jp`）だけにします。IP アドレス・`*.com` のように広すぎるものはプロキシが捨てます
- **形**（env の `EC_SUGAR_EGRESS_MODE`。替えるのは `sudo ec-sugar egress <形>`。書いた後に作り直すまでする）:

| 形 | 一覧に無い宛先 | アプリから外へ直に | 使う時 |
|---|---|---|---|
| `watch`（既定） | 通して記録する（運営ダッシュボードに「近いうちに」） | 出られる（今までどおり） | 入れた直後。数日見て一覧を整える |
| `enforce` | プロキシが断る（運営ダッシュボードに「要対応」とメール） | 出られない（外へ出られないネットワークだけ。DNS も外の名前を解決しない） | 一覧が整った後 |
| `off` | プロキシを通らない（記録しない） | 出られる | プロキシが困ったときの逃げ道 |

- どの形でも、名前を解決した IP アドレスが 1 つでも内向き（LAN・このサーバー・ほかのコンテナ・`169.254.169.254`）ならプロキシが断ります。握手の名前（SNI）が CONNECT の名前と違えば切ります。IP アドレスの直書き（`https://203.0.113.5/`）は、監視の形では記録して通し、止める形では断ります。平文の http（`http://…`）はプロキシを通らず、止める形では届きません
- **メール**: 止める形では、`MAIL_HOST` の名前をプロキシの IP アドレスに向け、プロキシが `MAIL_HOST:MAIL_PORT` へだけ中継します（TLS はアプリとメールのメールの送信サービスの間で閉じたまま、証明書の名前は `MAIL_HOST` で確認する）。**`MAIL_HOST` は名前にします**（IP アドレスだと止める形に切り替えられない。`ec-sugar egress enforce` が断る）。ステージングの Mailpit は内側のネットワークで直に届きます
- プロキシが止まると、監視の形でも外への https（支払い・LINE・Slack）が止まります。プロキシにはヘルスチェックがあり、更新のヘルスチェック（app からプロキシに届くかも見る）を通らなければ前のバージョンへロールバックります。運営ダッシュボードは、プロキシの数が 5 分より古いと「要対応」にします。困ったら `sudo ec-sugar egress off`
- **止める形では、プロキシが止まるとメールも止まり、「プロキシが止まった」の通知メールも届きません**。そのため `ec-sugar watch`（10 分ごと）がホストからプロキシを見て、止まった・unhealthy なら作り直し、`/srv/ec-sugar/state/history.log` に `egress_restart` を残します（`sudo ec-sugar status` の「外への通信」と合わせて見る）。メールに頼らない監視（サーバーの外からショップを開く監視など）も合わせて置きます
- 止める形でも守れないもの: 許した宛先そのものを使った送り出し（攻める人の Slack の宛先・LINE や Stripe の鍵）と、メールの中継でのメール（受け取り手は自由）。詳しくは ADR 0102 の「残り」
- ネットワークの範囲（`EC_SUGAR_EGRESS_SUBNET` 172.30.255.192/28・自動で配る範囲 `EC_SUGAR_EGRESS_RANGE`・`EC_SUGAR_EGRESS_GATEWAY`・プロキシの IP アドレス `EC_SUGAR_EGRESS_ADDR`）は、ほかのネットワークと重なるときだけ 4 つをそろえて env に書きます
- プロキシを通さないもの: リバースプロキシの Caddy（Let's Encrypt と Cloudflare の DNS の API）・cloudflared（Cloudflare の拠点へ IP と 7844 番）・ホストの root が実行する更新（GHCR・Sigstore・GitHub・Docker Hub）

### 監視の形から止める形へ（今の本番に入れる手順）

1. このバージョンへ更新すると、監視の形で入ります（`egress` のコンテナが増え、アプリはプロキシを通る）。入れる前に、復元のコピー（`sudo ec-sugar restore <名前> --isolated`、docs/development/restore-drill.md）で、`sudo ec-sugar status` の「外への通信」と `egress` が healthy なことを確認します（隔離の形ではアプリがプロキシのネットワークに居ないので、外へは出ません）
2. 本番で数日、運営ダッシュボードの「外への通信」と `sudo ec-sugar egress`（この 24 時間の数と、記録にある一覧に無かった名前・断った名前）を見ます。要る名前は env の `EC_SUGAR_EGRESS_ALLOW` に足して `sudo ec-sugar egress watch`（作り直して読み直す）
3. `MAIL_HOST` が名前なのを確認（`sudo grep ^MAIL_HOST= /srv/ec-sugar/env/.env.production`）、`sudo ec-sugar egress enforce`。この形ではプロキシが止まると通知メールも止まるので、`ec-sugar-watch.timer` が動いていること（`systemctl status ec-sugar-watch.timer`）と、サーバーの外からの監視があることを先に確認する
4. すぐに確認する: テストの注文（Stripe）・注文の通知メールが届く・LINE や Slack を使う店はその通知。`sudo ec-sugar egress` の「断った」が増えていないこと
5. 困ったら `sudo ec-sugar egress watch`（すぐ前の形へ。データは変わらない）

## 後から変えにくい設定

最初に決めたら、なるべく変えないものです。インストーラーもこれを聞くときに「後から変えにくい」と出します。

- **管理画面のホスト名**: パスキーはこの名前に結びつきます。変えると、運営の全員がパスキーを登録し直すことになります
- **ショップのホスト**（1 店舗）・**ショップのドメインの付け方**（複数の店。`<店>.<ドメイン>`）: 店の URL・お客さんのブックマーク・Stripe の通知先が変わります。1 店舗から複数の店へ広げるのは URL を変えずにできますが（`sudo ec-sugar shops multi`）、そのためには 1 店舗のショップのホストを `<1 段>.<ドメイン>` の形（例 `shop.example.jp`）にしておきます。ドメインそのもの（`example.jp`）にすると、後から URL を変えずには広げられません
- **`APP_KEY`**: お客さんの個人情報を暗号化する鍵の元です。インストーラーが 1 回だけ作り、二度と作り直しません。**失くすと、DB のバックアップがあっても個人情報を読めません**。バックアップ（`sudo ec-sugar backup`）にも入りません（鍵と中身を同じ所に置かないため）。入れ終わったらすぐに、設定のファイル `/srv/ec-sugar/env/.env.production` を丸ごと、サーバーの外（パスワード管理ツール・オフラインの保管）に保管してください:
  1. `sudo ec-sugar app-key export /root/ec-sugar-env-backup` で、設定のファイルを root だけが読める 0600 のファイルにコピーする（鍵は画面に出ません。もうあるファイルは上書きせず、シンボリックリンク・ほかの人が書ける保存先・バックアップの保存先は断ります）
  2. そのファイルをサーバーの外へ移す。手元へ持っていくのは `scp` か、パスワード管理ツールの CLI で。画面に出してコピーしない・チャットやメールやチケットに貼らない
  3. 移したら、サーバーに残ったファイルを消す（`sudo rm /root/ec-sugar-env-backup`）
  4. 管理画面の「運営ダッシュボード」で「バックアップを取りました」を押す（オーナーだけ。パスキーでの再確認の後）

  バックアップを取ったと回答するまで、運営ダッシュボードの「近いうちに」に「APP_KEY のバックアップをサーバーの外に取ったことを、まだ確認していません」と出続け、オーナーへのメールも 1 回だけ出ます。アプリが覚えるのは「いつ・誰が回答したか」だけで、鍵や控えの中身は扱いません。何の鍵か・どこにあるかは `sudo ec-sugar app-key` でも読めます

  サーバーの外のバックアップを設定するなら、この控えは**復元のキット**に入れておきます（下の「サーバーの外のバックアップ」の「復元のキット」。保存先を読み取り用のアクセスキー・復号の秘密鍵と一緒に 1 つのファイルにまとめ、パスフレーズで暗号化する。戻す時は `--kit` の 1 つで渡せる）

## 保存先

| パス | 持ち主・権限 | 中身 |
|---|---|---|
| `/srv/ec-sugar` | root:ec-sugar 0750 | |
| `src/` | ec-sugar | git の作業ツリー（compose のファイル・nginx と Caddy の設定・DB の初期化のスクリプトもここから使う） |
| `home/` | ec-sugar 0700 | 利用者 ec-sugar の家（deploy key） |
| `env/.env.production` | root 0700 / 0600 | 設定とパスワード・APP_KEY |
| `/var/lib/ec-sugar/front/` | root:ec-sugar-front 2770 ＋ 前段のリバースプロキシのグループに ACL で通り抜けだけ | すでにある Web サーバーの後ろに置く形の、受付の nginx のソケット `web.sock` |
| `secrets/caddy/` | uid 65532（リバースプロキシ（Caddy））0700、中は 0400 | DNS の API の鍵（`cloudflare_token`）・手で用意した証明書と鍵（`tls/`）。使う取り方のものだけ |
| `secrets/tunnel/` | root:65533（cloudflared）0750、中は 0440 | Cloudflare Tunnel の形だけ。トンネルのトークン（`token`）と cloudflared の行き先（`config.yml`）。`cloudflared` に読み取り専用で差し込む |
| `secrets/tunnel-watch/` | root 0700、中は 0400 | Cloudflare Tunnel の形で、監視の鍵を渡したときだけ。トンネルの監視の読むだけの Cloudflare の API の鍵（`token`）。`cloudflared` には差し込まない |
| `backup/<日時>/` | root 0700 / 0600 | DB と写真などのファイル（storage の下）のバックアップと指紋（`SHA256SUMS`）（DB は 7 日分、写真などのファイルの tar は新しい 3 世代だけ。更新の前のものは DB だけ）。個人情報を含む |
| `secrets/offsite/` | root 0700、中は 0400 | サーバーの外のバックアップを設定したときだけ。保存先の書き込み用のアクセスキー（`credentials`）と公開鍵（`recipient.asc`）。どのコンテナにも差し込まない。読み取り用のアクセスキーと秘密鍵は置かない |
| `offsite/` | root 0700 | 同じく、送る前のバックアップ（`stage/`。バックアップへのハードリンクで、送れたら消える）と一時の保存先（`tmp/`） |
| `state/` | root 0750 | 今と前のバージョン・最後の更新の結果（`last_update`）・最後に取得元へ届いた時刻（`last_check`）・自動の更新を入れているか（`auto_update`。運営ダッシュボードへ渡る）・最後のバックアップの結果（`last_backup`）・トンネルの監視の最後の結果（`tunnel_watch`。Tunnel の形だけ）・内部の整合性チェックの結果（`integrity`）と外部監視の状態（`integrity_sentinel`。来たことがある時だけ）・更新の記録（`history.log`）・保留したバージョン・作るときとマイグレーションの記録（`logs/`）・隔離した複製環境のフラグ（`isolated`。`restore --isolated` の後だけ） |
| `trust/integrity/` | root 0700 | 内部の整合性チェックの署名の鍵（`key`、Ed25519・0400）・このサーバーの識別子（`server`）・基準の状態の記録（`expected`、0600）・外部監視が最後に来た時刻（`last_fetch`）。どのコンテナにも差し込まない |
| `/var/lib/ec-sugar/integrity/` | root 0755、中は 0644 | 内部の整合性チェックの署名したステータス（`integrity.json`・`integrity.json.sig`）。受付の nginx（web）にだけ読み取り専用で差し込み、どのホストでも `/.well-known/ec-sugar/` で返す |
| `install.answers` | root 0600 | 設定ファイル（秘密は入れない） |
| `/usr/local/bin/ec-sugar` | root | 運用のコマンド（中身は `/usr/local/lib/ec-sugar/`。更新のたびに新しいバージョンのものに置き換わる） |
| Docker のボリューム `ec-sugar_caddy_data` | Caddy だけが差し込む | 証明書と ACME のアカウントの鍵 |

`src/` を root の compose が読むので、利用者 `ec-sugar` は実質的に root と同じ重さです。ログインできない形にして、git の取り込み（外から中身を取る所）だけをこの利用者で行います（取ってきた中身を root の git で扱わない）。

## 入れた後: `ec-sugar` コマンド

| コマンド | すること |
|---|---|
| `sudo ec-sugar status` | 動いているか・ホストのファイアウォール（ネットワークごとに決まりがあるか。無ければ ✗ と直し方）・外への通信の形とこの 24 時間の数・バージョン・前のバージョン・最後の更新と結果・保留したバージョン・最後のバックアップ・証明書の期限（リバースプロキシが今出しているもの）・次の自動の更新 |
| `sudo ec-sugar backup [--no-files]` | 中央と全部の店の DB（`pg_dump`）と写真などのファイル（storage の下を 1 つの tar に。画面の下書きと記録は除く）をバックアップする（`/srv/ec-sugar/backup/<日時>/`、0600。DB は 7 日分、写真などのファイルの tar は新しい 3 世代だけ残し、古い世代からは tar だけを外す）。取る前に空き容量を確認し、取った後に DB は `pg_restore --list`・tar は目次で読めるかを確認し、指紋（`SHA256SUMS`）を残す。終わりに大きさと時間を出す。`--no-files` は DB だけ。証明書は含まない |
| `sudo ec-sugar restore <バックアップの名前> [--isolated] [--kit <復元のキット> \| --app-key-file <ファイル>] [--no-files] [--yes]` | バックアップから中央と全部の店の DB と写真などのファイルを戻す（前のバージョンが無くても使える）。下の「バックアップから戻す」。`--isolated` は本番のバックアップを使い捨ての VM に戻して確認する、外へ何も出さない形。別のサーバーへ戻す時の APP_KEY は、復元のキット（`--kit`）か、個別のファイル（`--app-key-file`）で渡す |
| `sudo ec-sugar restore-check [--verify]` | 店ごとの取引・Ledger の行と合計・記録の数・写真の数と見つからないファイルの数を出す（読むだけ。本番でも実行してよい。`--verify` は記録のチェーンも確認する）。戻した後の確認と、訓練で本番と複製環境の数を比べるのに使う |
| `sudo ec-sugar offsite [status \| test \| run [--retry] \| pull …]` | サーバーの外のバックアップ（下の「サーバーの外のバックアップ」。保存先を設定したサーバーだけ）。`status` は設定と最後の結果（何も変えない）、`test` は保存先に書けるか・暗号化できるか・保存先が上書きと削除を断るか、`run` は溜まったバックアップを今すぐ送る、`pull` は保存先から取って開く（戻す先のマシンで。`pull <名前 \| --latest \| --list> --kit <復元のキット>`） |
| `recovery-kit create … ・ check <ファイル>`（**持ち主の手元のマシンで**。リポジトリの `deploy/recovery-kit` の 1 ファイル） | 復元のキットを作る・確認する（下の「サーバーの外のバックアップ」の「復元のキット」）。保存先を読み取り用のアクセスキー・復号の秘密鍵とパスフレーズ・APP_KEY のバックアップを 1 つのファイルにまとめ、パスフレーズで暗号化する。サーバーでは実行しない |
| `sudo ec-sugar update [--to <タグかコミット>] [--allow-downgrade]` | 更新（下の順）。release チャネルは署名したタグ（`v1.2.3`・プレリリース版 `v1.2.3-rc.1`）だけで、既定は取得元の署名の通る最新。今より古いバージョンへは `--allow-downgrade` を付けた時だけ。source チャネルは既定で決めたブランチ（`UPDATE_BRANCH`）の最新、`--to` にコミットも書ける |
| `sudo ec-sugar trust [list \| add <公開鍵のファイル> --name=<名前> \| remove <指紋>] [--yes]` | タグの署名で信頼する鍵（`/srv/ec-sugar/trust/allowed_signers`）を見る・足す・外す（下の「タグの署名の鍵」） |
| `sudo ec-sugar app-key [export <ファイル>]` | APP_KEY のバックアップ（上の「後から変えにくい設定」）。引数なしは説明（何の鍵か・どこにあるか・取り方）。`export <ファイル>` は設定のファイルを root だけが読める 0600 のファイルにコピーする（サーバーの外へ移すため）。もうあるファイルは上書きしない。シンボリックリンク・ほかの人が書ける保存先・バックアップの保存先は断る。鍵の値は画面・引数・記録に出さない |
| `sudo ec-sugar rollback` | 前のバージョンへロールバックする（イメージだけ。DB はそのまま。店の数の形〔`shops`〕も戻さない） |
| `sudo ec-sugar rollback --restore-backup <バックアップの名前>` | 前のバージョンへロールバックし、DB もバックアップから戻す。**バックアップの後に入った注文などが消える**ので、確認の文を出し、バックアップの名前を打ったときだけ行う |
| `sudo ec-sugar logs [-f] [サービス]` | 記録を見る（caddy・web・app・queue・scheduler・egress・db・redis。Cloudflare Tunnel の形は cloudflared も） |
| `sudo ec-sugar operator create <メール> --name=<名前>` | 運営を作り、パスキーの登録の URL を出す（`enroll`・`list`・`disable` なども同じ） |
| `sudo ec-sugar deploy-key` | 非公開のリポジトリから取るための読み取りだけの鍵を作って見せる |
| `sudo ec-sugar check-front` | すでにある Web サーバーの後ろに置く形で、前段のリバースプロキシの設定を確認する（管理画面とショップを https で通し、偽の `X-Forwarded-For` と `X-Forwarded-Proto` を見る）。Cloudflare Tunnel の形では、中から接続元を偽れないことと、外（Cloudflare）を回って本当の接続元が届くことを確認する（「Cloudflare Tunnel で出す形」の 4.） |
| `sudo ec-sugar firewall` | ホストのファイアウォールを適用し直す（上の「ホストのファイアウォール」。Plesk のファイアウォールを適用した後・iptables を作り直した後・`status` が ✗ を出したとき） |
| `sudo ec-sugar report` | サーバーの監視の報告（証明書の期限・空き容量・最後の更新とバックアップ）を、運営ダッシュボードへ今すぐ渡す。ふだんは毎晩と更新・バックアップ・ロールバックの後に自動で渡すので、実行するのは入れた直後や直した後に確認したいとき。断られたら理由が出る |
| `sudo ec-sugar admin-ips [<IP か範囲,…>]` | 管理画面を開ける接続元を見る（引数なし）・替える。家の回線の IP アドレスが変わって管理画面に入れなくなったときに、サーバーへ SSH で入って実行する（形を確認してから設定ファイルと env に書き、管理画面を作り直す。再実行しても変わらない。IPv4 で /16・IPv6 で /48 より広い範囲と、Tunnel の形では私設の範囲〔サーバーの中からの要求に当たる〕を断る）。自分の IP アドレスは、ブラウザで見る端末で https://1.1.1.1/cdn-cgi/trace を開くと `ip=` の行に出る（IPv6 なら `/64` の範囲にする） |
| `sudo ec-sugar watch` | 通知のジョブを今すぐ実行する（ふだんは `ec-sugar-watch.timer` が 10 分ごと。定期の処理が止まっても通知するため。下の「サーバーの監視と通知」）。Cloudflare Tunnel の形では、先によそからトンネルに接続されているコネクタが無いかを確認し、結果を運営ダッシュボードへ渡す（「Cloudflare Tunnel で出す形」の 5.） |
| `sudo ec-sugar install-units` | 運用のスクリプトと systemd の unit を、入っているバージョンのもので入れ直す（`status` が「タイマーを読めていません」と出したとき）。内部の整合性チェックの基準の状態も記録し直す |
| `sudo ec-sugar egress [watch \| enforce \| off]` | 外への通信の形を見る（引数なし。形・この 24 時間の数・記録にある一覧に無かった名前と断った名前）・替える（プロキシと app・queue・scheduler を作り直す。止める形は `MAIL_HOST` が名前のときだけ）。上の「外への通信」 |
| `sudo ec-sugar integrity [check \| key \| record]` | 内部の整合性チェック（下の「内部の整合性チェック」）。`check` は今すぐチェックして署名したステータスを書く（ふだんは `ec-sugar-integrity.timer` が毎分。問題があれば名前を出す）、`key` は外部監視に置く公開の鍵（PEM）を出す、`record` は基準の状態を今の状態で記録し直す（違いを調べて、正しい変更と分かった時だけ） |
| `sudo ec-sugar shops [multi --domain=<ドメイン> \| single] [--yes]` | 店の数の形を見る（引数なし。店のホストと、複数の店の形なら DNS に足す行）・1 店舗から複数の店へ広げる（今のショップの URL は変えない）・1 店舗へ戻す。駄目なら前の形へ自動で戻す（上の「1 店舗から複数の店へ広げる」） |

店の人が自分の AI エージェント（Claude など）から店を読む・変える入口（管理画面のホストの `/mcp`）と、claude.ai などの「コネクタを追加」から接続する OAuth のエンドポイントは、足す設定がありません。`/mcp` と OAuth のマシンのエンドポイントは管理画面の接続元の制限の外です（許可の画面は中）。使い方と接続元の制限との関係は下の「AI エージェントから接続する」。

## 自動の更新と、失敗したときのロールバック

更新の形は 2 つです（設定ファイルの `CHANNEL`。`sudo ec-sugar status` の「更新の形」に出る）。

- **release チャネル**（`release`。公開用のリポジトリから入れた既定）: 人の鍵で署名したバージョンのタグだけを入れ、イメージは公開用の CI が作って署名したものを確認してから digest で取る（下の「release チャネルの更新」。ADR 0099）
- **source チャネル**（`source`。開発用のリポジトリから入れた既定・この仕組みの前に入れたサーバー）: 取得元のコミットを取り、サーバーの上でイメージを組み立てる。`sudo ec-sugar update --to <コミット>` は今までのまま使える

`ec-sugar-update.timer` が `ec-sugar update --auto` を呼びます（本番は夜に 1 回、ステージングは 15 分ごと）。**自動の更新は、release チャネルだけ既定で入れます**（署名の通る新しいタグだけ・同じメジャーバージョンの中・プレリリース版〔`-rc.1` など〕は取らない）。**source チャネルは本番もステージングも既定で入れません**（決めたブランチの先を、人の署名なしに root で取り込むため。高）。設定ファイルの `AUTO_UPDATE=yes` で入れても、source チャネルの自動の更新は今のコミットから fast-forward できるコミットだけを取り込み（履歴が書き換えられたら止めて記録する）、前に失敗したバージョンは取り込み直しません。前に入れていた自動の更新も、インストーラーを再実行すると設定ファイルどおりになります。

```
取得元から取る → 入れるものを決めて確認する（公開版の形はタグの署名）→ 取り出したバージョンの運用の道具を git の中身から取り出す
  → 新しいバージョンの compose を、今入っているバージョンのセキュリティチェックにかける → イメージを得る（開発の形は作る・公開版の形は CI の署名を検証して digest で取る）
  → DB のバックアップ → 新しいイメージでマイグレーション → 切り替え → ヘルスチェック
  → 駄目なら前のイメージへ自動でロールバック → 結果を /srv/ec-sugar/state に残す（sudo ec-sugar status で見る）
```

- **運用のスクリプトと unit は、確認したコミットの git の中身から**入れます（`git archive` で root だけの一時の保存先へ。作業ツリー `/srv/ec-sugar/src` のファイルは利用者 `ec-sugar` が書けるので使わない）。リンクのファイルはコピーしません
- **release チャネルでは、compose の一式も確認したコミットの git の中身から**使います。作業ツリーを切り替えるたびに、そのコミットの compose のファイルと、compose が差し込むもの（`docker/` のリバースプロキシ（Caddy） の設定・nginx の設定・DB の初めのスクリプト）を `/srv/ec-sugar/compose/<コミット>/`（root だけが書ける）へ取り出し、起動・再起動・ロールバック・セキュリティチェックはそこを読みます。作業ツリーの compose を書き換えても効きません。保存先は今と前のバージョンの分だけ残します。source チャネルは今までどおり作業ツリーから
- **新しいバージョンの compose は、今入っているバージョンのセキュリティチェック**（`/usr/local/lib/ec-sugar/check-compose.sh`。ポート・権限・ネットワーク・イメージの固定）にかけてから起動します。通らなければ何も変えずに止めます（source チャネルの手の更新だけは、注意を出して続ける）。検査が入っていない前のバージョンからの初めての更新では、取り出したバージョンの検査で見ます。検査には `jq` が要ります（無ければ release チャネルは止め、source チャネルは注意だけ）。防御の決まりを変えるバージョンは、前のバージョンの検査も通る形で出す（CHANGELOG に手順を書く）
- 取得元の URL にパスワードやトークンが入っていれば、取りに行かずに止めます（URL は記録にも画面にも出さない）。読み取りだけの鍵（`sudo ec-sugar deploy-key`）の形にしてください
- **途中で止まったとき**: 作業ツリーを切り替えた直後に印（`/srv/ec-sugar/state/updating`）を置き、止める・終わる所で消します。思わぬ所で落ちた時（止める合図など）はその場で、電源断などで切れた時は次の `update`・`rollback` の始めに、作業ツリーと env を前のバージョンへそろえて記録します（切り替えの後なら前のバージョンへロールバックしてヘルスチェック）。`status` にも出ます
- 手の `rollback` で前のバージョンがヘルスチェックを通らなければ、元のバージョンへ戻し直します（記録と env と作業ツリーをずらさない。DB もバックアップから戻した時は戻し直さずに通知する）

切り替えのたびにリバースプロキシ（Caddy） も作り直し、取り出したバージョンの設定を読み直します（証明書はボリュームに残るので取り直さない）。

ヘルスチェックで見るもの: 全部のコンテナが healthy・app と queue と scheduler が入れたイメージ・nginx から PHP へ管理画面の `/up` が 200（中央の DB に実行していないマイグレーションがあれば 500）とリバースプロキシが 500 未満・ショップのホストが 500 未満（準備中のパスワードの画面も通す）・キューの処理が見本の仕事を 90 秒のうちに片付ける（`operations:queue-probe`。この口を持つバージョンだけ）・リバースプロキシ（Caddy）から https で `/up` が 200。`/up` は `ok` か `down` の文字だけを返し、どの枠組みかも中身も見せません。更新の確認はサーバー自身の IP アドレスからリバースプロキシを通して聞くので、管理画面の接続元の制限はかけていません

- 取り出したバージョンが新しく要る設定（env の項目）は、設定ファイルから決まるものなら更新の前に補います（取り出したバージョンの `ec-sugar ensure-env` で。新しいバージョンが足した項目も分かる）。設定ファイルから決められない項目が足りなければ、「マイグレーションに失敗」とは別の理由で止め、`install.sh` を再実行するよう案内します（そのバージョンを保留したバージョンにしない）
- 更新が済むと、新しく入った `ec-sugar` 自身がもう一度 unit を入れ直します（前のバージョンのツールは新しい unit の書き方を知らないため。例 朝のバックアップのタイムゾーンは drop-in `ec-sugar-backup.timer.d/10-timezone.conf`）

### 設定の名前（`EC_SUGAR_…` と前の名前 `SUGAR_EC_…`）

設定のファイル（`/srv/ec-sugar/env/.env.production`）の compose とリバースプロキシが読む項目は `EC_SUGAR_…`（例 `EC_SUGAR_IMAGE`・`EC_SUGAR_ADMIN_HOST`）です。2026-10-31 より前に入れたサーバーは前の名前 `SUGAR_EC_…` で書かれていて、次のように移ります（手ですることはありません）。

- 更新（`update`）・ロールバック・起動・`status`・`backup`・`report`・`install-units`・インストーラーの再実行のたびに、前の名前の行ごとに、同じ値の新しい名前の行をそのすぐ後ろに足します。前の名前の行は消しません（前のバージョンへロールバックしても動くように）。ほかの行・並び・引用符・権限（0600）は変えず、何度実行しても同じです
- 前の名前の行がある間は、前の名前の値が正です（compose もツールも前の名前を先に読む）。サーバーに入っている前のバージョンの `ec-sugar` は、新しいバージョンへの更新の途中でも前の名前だけを書くためです。新しいバージョンの `ec-sugar` は両方に同じ値を書きます
- 手で値を変えるときは、前の名前の行があれば**前の名前の行を**変えてください（次に `ec-sugar` が動いたときに新しい名前の行もそろいます）
- compose が必ず要る項目（管理画面のホスト・イメージなど）が env に無いと、`ec-sugar` は compose を実行せずに「設定 … に EC_SUGAR_…（説明）がありません」と出して止めます（値は出しません）。`sudo ./deploy/install.sh` を再実行すると設定ファイルから書き直します。エンドポイントの IP アドレス（`EC_SUGAR_BIND`）が無いときは、手で compose を実行しても `invalid IP address` で止まります（全部の IP アドレスに出ません）
- 新しく入れるサーバーには新しい名前だけが入ります。前の名前の行は公開の前に消します（消す仕組みはその時に足す）

| 失敗の起き方 | どうなるか | 自動か |
|---|---|---|
| イメージを作れない | 何も切り替えずに止まる。前のバージョンのまま | 自動（次の回にやり直す） |
| release チャネルで、タグかイメージの署名が通らない・入れてはいけないバージョン（巻き戻し・書き換え・fast-forward でない） | 何も変えずに止まる（stopped として記録して通知する）。前のバージョンのまま。署名の通らないタグは取り込み直さない | 自動 |
| 新しいバージョンの compose が今のセキュリティチェックを通らない | 何も変えずに止まる。前のバージョンのまま（source チャネルの手の更新だけは注意を出して続ける） | 自動 |
| 更新が途中で止まった（止める合図・電源断） | 前のバージョンへそろえて記録する（その場か、次の update・rollback の始めに） | 自動 |
| バックアップを取れない | 先へ進まずに止まる。前のバージョンのまま | 自動 |
| マイグレーションが途中で失敗する | 失敗したマイグレーションは丸ごと取り消される（PostgreSQL は DB の形の変更も取り消せる）。切り替える前なので前のバージョンが動き続ける。そのバージョンは「保留したバージョン」になり、自動では取り込み直さない。前のバージョンがヘルスチェックを通るかも確認し、通らなければ「失敗」として通知する（店ごとのマイグレーションは途中まで当たっていることがある） | 自動 |
| 一部の店だけマイグレーションできた | DB の変え方は「足してから後で消す」の二段なので、前のバージョンはどちらの店でも動く。止めて記録する | 自動 |
| 新しいバージョンがヘルスチェックを通らない | 前のバージョンのイメージへ切り替え直す。そのバージョンは「保留したバージョン」になる | 自動 |
| 動いているが後から問題に気づいた | `sudo ec-sugar rollback` | 人 |
| DB の中身まで戻したい（最後の手段） | `sudo ec-sugar rollback --restore-backup <バックアップの名前>` | 人 |
| サーバーが壊れて入れ直した・写真まで戻したい・前のバージョンが無い | `sudo ec-sugar restore <バックアップの名前>`（下の「バックアップから戻す」） | 人 |

保留したバージョンをもう一度試すときは `sudo ec-sugar update --to <タグかコミット>`。更新の失敗・ロールバック・途中で止めたことは、オーナーへのメールと運営ダッシュボードで通知します（下の「サーバーの監視と通知」）。取得元に届かなかったときは、最後の更新の結果は変えずに記録（`history.log`）と `status` の注意にだけ残し、48 時間続けば運営ダッシュボードに出ます。source チャネルのイメージは、取ったコミットからサーバーの上で作ります。release チャネルは作らず、CI が作って署名したものだけを入れます（下）。

### release チャネルの更新（署名したタグと CI の署名。ADR 0099）

`sudo ec-sugar update [--to v1.2.3] [--allow-downgrade]`（`--to` が無ければ、取得元の署名の通る最新の semver のタグ）。次の確認が**1 つでも通らなければ、何も変えずに止め、記録して通知します**（`status`・運営ダッシュボード・オーナーへのメール）。

1. **タグの人の署名**: `git verify-tag` を、信頼する鍵の一覧 `/srv/ec-sugar/trust/allowed_signers`（root の持ち物）**だけ**で。リポジトリの中の鍵の一覧・`.git/config` の署名の設定・置き換えのフラグ（`refs/replace/`）は読みません。鍵の種類では断りません（Mac の Secure Enclave の `ecdsa-sha2-nistp256`・ハードウェアキーの `sk-ssh-ed25519`・`sk-ecdsa-sha2-nistp256` など、一覧にある鍵かだけを見る）。署名の無いタグ（軽いタグ・注記だけのタグ）・知らない鍵・中身を書き換えたタグ・**中に書かれた名前とタグの名前が違うもの**（古いタグを新しい名前で出し直す巻き戻し）は断ります
2. **入れてよいバージョンか**: 今より古いバージョン（巻き戻し）は、手の更新で `--allow-downgrade` を付けた時だけ（自動の更新は戻らない）。今と同じ名前で別のコミットを指すタグ（出したタグの書き換え）は断る。新しいバージョンは、今のコミットから fast-forward できる時だけ。自動の更新はプレリリース版とメジャーバージョンの上がるもの（大きな変更）を取らない（新しいメジャーバージョンがあることは記録に出す。CHANGELOG を読んでから `--to` で）。署名の通らない新しいタグは入れずに飛ばし、`status` の注意と記録に出します
3. **タグの指すコミットのスクリプトと compose で**続ける（上の流れ。ツールは git の中身から、compose は今のセキュリティチェックに）
4. **2 つのイメージの CI の署名**: `ghcr.io/ec-sugar/ec-sugar:<タグ>` と `ghcr.io/ec-sugar/ec-sugar-caddy:<タグ>` が今指す digest を保存先に聞き、その digest の cosign の keyless の署名を検証ます。身元は `https://github.com/ec-sugar/ec-sugar/.github/workflows/release.yml@refs/tags/<タグ>`、発行者は `https://token.actions.githubusercontent.com`、**証明書の workflow のコミットが、1. で署名を検証たタグの指すコミットと同じ**こと（人の署名と CI の署名が結び付く。別のリポジトリ・別の workflow・別のタグ・別のコミットから作ったものは通らない）。透明性のログも確認します。cosign はホストに入れず、版と digest を固定した公式のイメージ（`ghcr.io/sigstore/cosign/cosign:v3.1.4@sha256:…`）を読み取り専用・権限なしで動かします
5. **digest で取る**（タグの名前では取らない）。取ったイメージのラベル（`org.opencontainers.image.revision`）がタグの指すコミットかを確認してから、今までの名前（`ec-sugar:<コミット>` と compose のリバースプロキシの名前）を付けます。取った digest は `/srv/ec-sugar/state/release-<コミット>` に残ります。記録は `/srv/ec-sugar/state/logs/verify-<タグ>.log`
6. 後は source チャネルと同じ（バックアップ → マイグレーション → 切り替え → ヘルスチェック → 駄目なら戻す）

`sudo ec-sugar rollback` は今までどおり、手元に残っている前のバージョンのイメージ（入れた時に確認したもの）へ戻します（取得元には行かない）。もっと古い署名したバージョンへ戻すときは `sudo ec-sugar update --to v1.1.0 --allow-downgrade`（DB の形は「足してから後で消す」の二段なので、ふつうは古いバージョンでも動く。メジャーバージョンをまたいで戻すときは CHANGELOG を読む）。

1.0 では、署名つきの「新しいバージョンの通知」（期限つき）・緊急の更新・止血・取り消し・少しずつ広げる・透明性のログの監視はまだありません（ADR 0099 の「1.0 の後に回すもの」）。そのため、取得元やネットワークの途中が新しいタグを隠す（更新を止めさせる）ことには、1.0 では気づけません。`status` の「最後の確認」と、公式サイトで告げる最新のバージョンを比べてください。

### タグの署名の鍵（release チャネル）

タグの署名で信頼する鍵は、サーバーの root の持ち物 `/srv/ec-sugar/trust/allowed_signers`（git の allowed signers の形、0644）**だけ**です。リポジトリの中には置かず、更新でリポジトリの中から読むこともありません（リポジトリを乗っ取った人が一覧ごと書き換えても通らないように）。

- **入れる時**: 公式サイトで配っている一覧をサーバーに置き、設定ファイルの `TRUST_SIGNERS_FILE` にパスを書く（端末なら 2/5 で聞く）。インストーラーが鍵ごとの指紋（`SHA256:…`）を出すので、**公式サイトで公表している指紋と同じか**を確認して `yes` と打つ。設定ファイルで実行するときは `TRUST_FINGERPRINTS=SHA256:…,SHA256:…` を書く（一覧の指紋とちょうど同じでなければ止まる）。指紋が違えば入れずに止めてください（取り違えた鍵を信頼すると、その鍵の持ち主が作ったバージョンが入る）
- 一覧には予備の鍵も初めから並んでいます（1 本をなくしても配れるように）
- **見る**: `sudo ec-sugar trust list`（指紋・名前・種類）
- **足す**: `sudo ec-sugar trust add <公開鍵のファイル> --name=<名前>`（公開鍵の `.pub` の 1 行か、allowed signers の 1 行）。指紋を出すので、公式サイトの告知と同じなら `yes`（端末でなければ確認したうえで `--yes`）
- **外す**: `sudo ec-sugar trust remove <指紋>`（最後の 1 つは外せない。先に新しい鍵を足す）。鍵が漏れた・なくしたと告知されたら、新しい鍵を足してから古い鍵を外します
- 1.0 では鍵のローテーションは手動で行います（署名つきの自動のローテーションは 1.0 の後）
- 一覧が無い・root の持ち物でない・ほかの利用者が書ける・読めない行があると、どのタグも入れません（`status` に出る）

公開版のイメージ（GHCR）は公開なので、取るのにログインは要りません。自分で非公開のレジストリに置き直して使う時だけ、root でログインしておきます（`sudo docker login <レジストリ>`。資格情報は root の持ち物の `/root/.docker/config.json` で、cosign にも読み取り専用で渡す）


### 更新に失敗した時の手順

通知（件名「[EC-SUGAR] 動きに問題があります」・運営ダッシュボードの「最後の更新」）が来たら:

1. `sudo ec-sugar status` で「最後の更新」の結果と一言・「動いているか」・保留したバージョンを見る
2. 結果ごとに:
   - **stopped**（イメージを作れない・バックアップを取れない・env が足りない・マイグレーションに失敗した・release チャネルで署名が通らない・compose が今のセキュリティチェックを通らない）: 前のバージョンのまま動いている。一言とログ（`/srv/ec-sugar/state/logs/` の `build-*.log`・`migrate-*.log`・`verify-*.log`・`compose-check-*.log`）で原因を見る。署名が通らないときは、取得元のタグと `sudo ec-sugar trust list` の指紋を公式サイトの告知と比べる（身に覚えのないタグなら、取得元の乗っ取りを疑い、入れない）。直したバージョンが出れば次の更新で入る（保留したバージョンは自動では取り込み直さない。直したら `sudo ec-sugar update --to <コミット>`）
   - **rolled_back**（新しいバージョンがヘルスチェックを通らなかった）: 前のバージョンへ自動で戻って動いている。`sudo ec-sugar logs app web` で原因を見る
   - **failed**（前のバージョンへロールバックしてもヘルスチェックを通らない。ショップが止まっている恐れ）: すぐに `sudo ec-sugar status` と `sudo ec-sugar logs app web db` を見る。コンテナが止まっているだけなら `sudo systemctl restart ec-sugar`。DB の中身が原因なら最後の手段として `sudo ec-sugar rollback --restore-backup <更新の前のバックアップ>`（出た文の名前。後に入った注文が消える）
3. 動いているのに後から問題に気づいたら `sudo ec-sugar rollback`（イメージだけ。DB はそのまま。そのバージョンは保留したバージョンになる）
4. 直したら `sudo ec-sugar report` で運営ダッシュボードを今に合わせる（次の成功の更新でも消える）

## バックアップから戻す（restore）

`sudo ec-sugar restore <バックアップの名前>` は、中央と全部の店の DB と写真などのファイルを、バックアップの時点に戻します。サーバーが壊れて新しいマシンに入れ直した時（同じ手順で EC-SUGAR を入れ、バックアップを `/srv/ec-sugar/backup/` の下に名前を変えずに root の 0700 で置き、APP_KEY を復元のキット `--kit` か `--app-key-file` で渡す）や、ロールバックでは戻らない写真まで戻したい時に使います。

1. **まず何も変えずに確認する**: そろっているか（`.complete`・中央の DB・ロール）、DB の名前がこのサーバーの店の DB のプレフィックス（`TENANT_DB_PREFIX`）と合うか、指紋（`SHA256SUMS`）が合うか（壊れた・書き換えられたバックアップを止める）、写真の tar の中に storage の外を指す名前・リンクが無いか
2. **確認**: 戻すバックアップ（取った時刻・理由・バージョン・管理画面のホスト・店の数・写真の大きさ）とこのサーバー（管理画面のホスト・本番かステージングか・リバースプロキシの形・今の店の DB の数・バージョン）を出し、**バックアップの名前を打ったときだけ**進みます（端末でなければ `--yes` が要る）。本番の形のサーバーでは「最後の手段」と出ます。別のサーバーで取ったバックアップを隔離せずに戻す時と、バックアップのバージョンが今より新しい時は注意が出ます（新しいバージョンのバックアップは、`sudo ec-sugar update --to <そのコミット>` で同じバージョンにしてから戻すのが確か）
3. **戻す前の状態のバックアップを取る**（取れなければ何も変えずに止まる）
4. app・リバースプロキシ・キュー・定期の処理を止める → 店ごとの DB のロール（バックアップの `globals.sql` から店のロールの行だけ。管理者とアプリのロールのパスワードは変えない）→ 中央の DB → 店の DB → 写真などのファイル（広げられた時だけ入れ替える。`--no-files` で DB だけ）→ マイグレーション（バックアップが前のバージョンの時）→ 起動してヘルスチェック → 戻した後の数（`restore-check`）
5. 途中で止まったら、**どこまで戻ったか**（「DB ec_central・DB ec_t_…」）・止まった所・戻し方（同じ restore をもう一度実行すると初めから同じ所へ戻る／戻す前の状態のバックアップの名前で restore／今のまま `sudo systemctl restart ec-sugar`）を出します。app などは止まったままです

- **写真の無い世代**（写真は新しい 3 世代だけなので古い世代・更新の前のバックアップ）を選ぶと、`--no-files` と同じに DB だけを戻し、今の写真などのファイルは残します（確認の文にそう出る。DB と写真の時点がずれるので、写真の見つからない商品は `restore-check` に出る）
- バックアップの後に作った店の DB は消しません（中央の店の一覧からは外れる。名前が出るので、中身を確認してから手で消す）
- `--kit <復元のキット>`: 新しいマシンに戻す時・複製環境を立てる時に、取ったサーバーの APP_KEY を、復元のキット（下の「サーバーの外のバックアップ」の「復元のキット」）から読む。キットのパスフレーズは端末で聞きます（画面に出ない。端末が無ければ、パスフレーズを 1 行で書いた root だけが読める 0600 のファイルを `--kit-passphrase-file` で）。キットのファイルも root だけが読める 0600 で置く（ほかの利用者から読めるものは断る）。開いた中身は `/run/ec-sugar/`（root だけ・ディスクに残らない）にだけ置き、APP_KEY を読んだらすぐ消します。キットのサーバーの名前がバックアップの管理画面と違う時は注意を出します（別のサーバーのキットの APP_KEY では個人情報を開けない）。`--app-key-file` と同時には渡せません
- `--app-key-file <ファイル>`: キットを使わずに、取ったサーバーの APP_KEY を個別のファイルで渡す（店の鍵と店の DB のパスワードが APP_KEY で包まれていて、違うと店に入れない。`restore-check` が「店の鍵を開けられない」と出す）。root だけが読める 0600 のファイルで（ほかの利用者から読めるものは断る）、中身は `base64:…` の 1 行（`APP_KEY=` が付いていてもよい）か、**`sudo ec-sugar app-key export` で取った設定のファイルのコピーそのまま**（中の `APP_KEY=` の行を使う）。鍵は画面・引数・記録に出ません。戻した後はファイルを消す
- `--isolated`: **隔離した複製環境**にする（本番のバックアップを使い捨ての VM に戻して確認する形。コピーには個人情報が載るので、確認し終わったらその日のうちに VM ごと消します）。app・キュー・定期の処理を外へ出るネットワークから外し（`docker-compose.isolated.yml` を重ねる）、定期の処理を止め、メールは Mailpit（ステージングの形）か送らない、Stripe・LINE・Slack・Webhook などへ送らない、ショップにタグ（解析・広告）を差し込まない、env の外のサービスの鍵を空にする。管理画面の上に「これは確認のための複製環境です」のバナーが出ます。**元に戻す道はありません**（使い終わったら VM ごと消す）。Cloudflare Tunnel の形のサーバーでは断ります。印（`/srv/ec-sugar/state/isolated`）があるのに隔離の形のファイルが無いバージョン（前のバージョンへロールバックした）では、`ec-sugar` は compose を実行せずに止まります

## サーバーの外のバックアップ（ADR 0110）

`sudo ec-sugar backup`（毎晩と更新の前）のバックアップは、同じサーバーの中に置かれます。サーバーごと失う・入られて消される、に備えるには、**サーバーの外の、消せない保存先**にも置きます。任意の設定で、設定ファイルに `OFFSITE_…` を書いたサーバーだけが送ります（書いていなければ、何も変わりません）。

- 送信先は **S3 互換の保存先**（Cloudflare R2 など）。バックアップができるたびに、10 分ごとのタイマー（`ec-sugar-offsite.timer`）が送る
- 送る前に、**持ち主の公開鍵で暗号化**する（gpg）。サーバーに置くのは公開鍵だけで、秘密鍵は持ち主の手元。サーバーに入られても、保存先の鍵を取られても、保存先の中身は読めない
- **消せない・上書きできない**は、保存先の側の設定（一定の期間、消すことも上書きすることも断るロック）に任せる。サーバーの鍵では消せない形にしておく
- 送るのは DB（毎回）と写真などのファイル。**APP_KEY は送りません**（下の「戻す」に要るので、別に保存する。上の「後から変えにくい設定」）

### 用意するもの

| | どこで | 置き場所 |
|---|---|---|
| 保存先（バケット）とロック | 保存先のサービスの画面。プレフィックス `<サーバーの名前>/db/`・`files/`・`heads/` ごとに、消せない期間を決める（例: 35 日・14 日・400 日） | — |
| **書き込み用のアクセスキー**（そのバケットだけに書ける鍵） | 保存先のサービスの画面 | サーバー（設定ファイルの `OFFSITE_KEY_FILE`） |
| **読み取り用のアクセスキー**（そのバケットを読むだけの鍵） | 同じ | **サーバーに置かない**。戻す時にだけ使うので、復元のキットに入れる |
| **暗号の鍵ペア**（gpg） | 手元のマシン | 公開鍵だけをサーバーへ（`OFFSITE_RECIPIENT_FILE`）。**秘密鍵は手元の gpg のキーリングと、復元のキットに** |
| **復元のキット**（読み取り用のアクセスキー・秘密鍵とそのパスフレーズ・APP_KEY のバックアップを 1 つにまとめ、パスフレーズで暗号化したファイル） | 手元のマシン（下の「復元のキット」） | **サーバーに置かない**。2 か所に置き、キットのパスフレーズは別の所に |

暗号の鍵は、手元のマシンで作ります（サーバーでは作らない）:

```sh
gpg --quick-generate-key "EC-SUGAR offsite backup <あなたのメール>" ed25519 cert never
gpg --fingerprint "EC-SUGAR offsite backup"                                   # 指紋（40 桁）を控える
gpg --quick-add-key <指紋を空白なしで> cv25519 encr never                      # 暗号化に使う鍵を足す
gpg --armor --export "EC-SUGAR offsite backup" > ec-sugar-offsite.pub.asc     # これをサーバーへ
```

作る時に聞かれるパスフレーズは付けておきます（キーリングを取られても、パスフレーズが無ければ開けられない）。秘密鍵は、ファイルに書き出さなくて構いません。下の「復元のキット」のツールが、キーリングから取り出してキットに入れます（パスフレーズもキットに入るので、戻す時に打つのはキットのパスフレーズだけ）。

**秘密の鍵をなくすと、保存先のバックアップは誰にも開けられません**（手元のキーリングのほかのバックアップが、復元のキットです）。期限は付けない（`never`）のを勧めます（期限が切れると、気づかないうちに送れなくなるため）。

### 設定する

1. 書き込み用のアクセスキーと公開鍵を、サーバーの root だけが書ける所に置く:
   ```sh
   sudo install -m 600 -o root -g root /dev/stdin /root/ec-sugar-offsite-key <<'KEY'
   access_key_id=<鍵の ID>
   secret_access_key=<秘密の鍵>
   KEY
   sudo install -m 644 -o root -g root ec-sugar-offsite.pub.asc /root/ec-sugar-offsite.pub.asc
   ```
2. 設定ファイル（`/srv/ec-sugar/install.answers`）に足す:
   ```
   OFFSITE_ENDPOINT=https://<アカウントの ID>.r2.cloudflarestorage.com
   OFFSITE_BUCKET=<バケットの名前>
   OFFSITE_KEY_FILE=/root/ec-sugar-offsite-key
   OFFSITE_RECIPIENT_FILE=/root/ec-sugar-offsite.pub.asc
   ```
   ほかに書けるもの: `OFFSITE_PREFIX`（保存先の中のサーバーの名前。既定は管理画面のホスト）・`OFFSITE_REGION`（既定 `auto`）・`OFFSITE_ENCRYPT`（既定 `yes`。`no` は勧めない）・`OFFSITE_LOCK_CHECK`（保存先が上書きと削除を断るかを確認する。R2 のエンドポイントなら既定で `yes`）
3. `sudo ./deploy/install.sh` を再実行する。鍵は `/srv/ec-sugar/secrets/offsite/` にコピーされ、**公開鍵の指紋**が出ます。手元で控えた指紋と同じことを確認する（違う鍵で暗号化されると、開けられない）。コピーした後、1 のファイルは消してよい（**設定ファイルの `OFFSITE_KEY_FILE`・`OFFSITE_RECIPIENT_FILE` の行は残す**。無いファイルを指していても、コピーしたものを使い続ける。行を消すと、内部の整合性チェックが設定の書き換えとして通知する）
4. `sudo ec-sugar offsite test` — 保存先に小さなファイルを置けるか・暗号化できるか・保存先が上書きと削除を断るかを確認する
5. `sudo ec-sugar backup` の後に `sudo ec-sugar offsite run`（待てば 10 分以内にタイマーが送る）→ `sudo ec-sugar offsite status` で「最後の回 送れた」

curl は 8.5.0 以上が要ります（Ubuntu 24.04 以降。足りなければ `status` と送る回が理由を出します）。再実行で公開鍵のファイルが前と違う鍵になっていたら、入れ替えずに止まります（黙って別の鍵で暗号化しない）。

### 復元のキットを管理画面で作る（いちばん簡単）

管理画面にオーナーで入り、**運営ダッシュボード**の「復元のキット」で作ります。SSH も gpg も要りません。

1. パスフレーズを決めて（12 文字以上。パスワード管理ツールで作って保存するのが確実）「キットを作って保存」を押す。本人確認（パスキー）の後、`ec-sugar-recovery-kit-<サーバー>-<日付>.gpg` が保存されます
2. 保存したファイルを、**このパソコンの外の 2 か所**（USB メモリとクラウドのストレージなど）に置く。パスフレーズは、キットとは別の所に控える
3. 同じ画面で、保存したファイルを選び、パスフレーズをもう一度入れて「開けて確認する」を押す。開けられたら終わりです（確認するまで、運営ダッシュボードの「近いうちに」に通知が出続けます）

鍵とキットはブラウザの中で作り、パスフレーズ・秘密鍵・キットはサーバーへ送りません（サーバーが受け取るのは、鍵ペアの公開鍵と指紋だけ。ADR 0111）。パスフレーズを忘れた・ファイルをなくした時は、同じ画面からもう一度作れます。

- このキットに入るのは **APP_KEY と秘密鍵**です。保存先を読み取り用のアクセスキーは入りません（保存先の側でいつでも作り直せるため）。サーバーの外のバックアップから戻す時は、保存先で読むだけの鍵を作り、`sudo ec-sugar offsite pull … --kit <キット> --credentials-file <読む鍵> --endpoint <入口> --bucket <バケット>` と一緒に渡します（設定ファイルに `OFFSITE_PREFIX` を書いていたサーバーなら `--prefix <その名前>` も）
- **手元で作った gpg の公開鍵をもう置いてあるサーバー**（設定ファイルの `OFFSITE_RECIPIENT_FILE`）では、画面で作ったキットの鍵はバックアップの暗号化に使われません。画面に「別の鍵で暗号化している」と出ます。その時、このキットで戻せるのは APP_KEY だけで、バックアップを開けるには前からの鍵（か、それを入れた下のツールのキット）が要ります
- サーバーの外のバックアップを設定していて、設定ファイルに `OFFSITE_RECIPIENT_FILE` を書いていなければ、キットを確認した後の回（10 分ごと）から、**このキットの鍵で暗号化して送り始めます**。`sudo ec-sugar offsite status` の「公開鍵の指紋」が、画面の指紋と同じになります
- 置いてある公開鍵は、キットを作り直しても替わりません（黙って替わると、前のキットで開けられなくなるため）。保存先を設定した後に作り直した時は、前のキットも持ち続けるか、`sudo rm /srv/ec-sugar/secrets/offsite/recipient.asc` で消して採り直させます（以後のバックアップは新しいキットでしか開けません）
- 手元で確認するには `recovery-kit check <キットのファイル>`（下のツール）

手元の gpg の鍵を使いたい時・読み取り用のアクセスキーもキットに入れたい時は、下のツールで作ります。

### 復元のキット（戻すのに要るものを 1 つのファイルに）

保存先のバックアップから戻すには、**サーバーに置いていない 3 つ**が要ります: 保存先を**読み取り用のアクセスキー**（鍵の ID と秘密の鍵）・暗号の**秘密鍵**（gpg の秘密の鍵と、そのパスフレーズ）・**APP_KEY のバックアップ**。ばらばらに保管して、戻す時に 1 つずつ手で貼って運ぶと、取り違えます（鍵の ID と別の値の取り違え・貼り付けの上書き）。そこで、この 3 つを **1 つのファイルにまとめ、パスフレーズで暗号化**しておきます。これが復元のキットで、戻す先のサーバーには `--kit` の 1 つで渡せます。

作るのは**持ち主の手元のマシン**です（サーバーでは作らない。秘密鍵をサーバーに触れさせないため）。ツールはリポジトリの `deploy/recovery-kit` の 1 ファイルで、手元へコピーして使えます。要るのは bash・python3・gpg（macOS と Linux で動く）。

```sh
# 1. APP_KEY のバックアップを手元へ（上の「後から変えにくい設定」の手順。サーバーで export → scp で手元へ → サーバーのコピーを消す）
# 2. 手元で作る。--gpg-key は、上で作った暗号の鍵（手元の gpg のキーリングにあるもの）。--server は、サーバーの管理画面のホスト
./recovery-kit create ec-sugar-recovery-kit.gpg \
    --server admin.example.jp \
    --endpoint https://<アカウントの ID>.r2.cloudflarestorage.com --bucket <バケットの名前> \
    --gpg-key "EC-SUGAR offsite backup" \
    --app-key-file ./ec-sugar-env-backup \
    --verify-bucket
```

- **聞かれるもの**（どれも画面に出ません。引数・環境変数・履歴にも載りません）: 読み取り用のアクセスキーの ID → 読み取り用のアクセスキーの秘密の鍵 → 秘密鍵のパスフレーズ → キットのパスフレーズ（2 回。12 文字以上）。読み取り用のアクセスキーは `--read-key-file <ファイル>`（`access_key_id=…` と `secret_access_key=…` の 2 行）でも、APP_KEY は `--app-key-file` を付けずに入力でも渡せます（`base64:…` の 1 行・`APP_KEY=…`・`sudo ec-sugar app-key export` のコピーそのまま、のどれでも）
- **作る時に確認すること**: 秘密鍵をキーリングから取り出し、その公開鍵で暗号化した小さなファイルを、入れたパスフレーズで開けられるか（開けられなければ作らない。戻す時に開けられないキットを作らないため）。`--verify-bucket` を付けると、読み取り用のアクセスキーで保存先の一覧を 1 回引いて、届くことも確認します（鍵の取り違えにここで気づける。curl 8.5.0 以上が要り、無ければ理由を出して飛ばします）。できたキットは、開け直して中身が同じことを確認してから終わります
- できるのは本人だけが読める 0600 のファイルです。もうあるファイルは上書きしません。`--server` は保存先の中のサーバーの名前で、設定ファイルの `OFFSITE_PREFIX` を変えたサーバーではその値を書きます
- 出てくる**秘密鍵の指紋**は、サーバーの `sudo ec-sugar offsite status` の「公開鍵の指紋」と同じはずです（違えば、別の鍵のキット）
- 作り終わったら、手元に残った APP_KEY のバックアップと読み取り用のアクセスキーのファイルは消します（キットの中に入った）

**バックアップの置き方**（キット 1 つで、全部の店のお客さんの個人情報まで開けます。防御は、キットのパスフレーズと置き場所です）:

- **キットは 2 か所に置く**（例: USB メモリと、クラウドのストレージ）。1 か所だけだと、その 1 か所を失った時に戻せません
- **キットのパスフレーズは、キットとは別の所に 2 か所**（パスワード管理ツールと、紙）。キットとパスフレーズを同じ所に置くと、そこを見た人が全部を開けられます
- **メモのアプリ 1 か所に、鍵とパスフレーズを全部並べて置かない**（1 つ見られると全部が開く・1 つ失うと全部を失う）
- **時々確認する**（年に 1 回と、鍵を替えた後）: `./recovery-kit check ec-sugar-recovery-kit.gpg`。パスフレーズを聞いて開け、入っているもの（欄の名前・サーバーの名前・エンドポイント・バケット・秘密鍵の指紋・作った時刻）と、秘密鍵が使えることだけを出します（鍵・パスフレーズ・APP_KEY の値は出しません）。`--verify-bucket` で、読み取り用のアクセスキーが今も保存先に届くかも見られます
- **作り直す時**: 読み取り用のアクセスキーを替えた・暗号の鍵を替えた・バケットを移した時。APP_KEY は変わりません。前のキットは、前の鍵で送ったバックアップの消せない期間が過ぎるまで残します

### 送れない時

送れなかったことは、管理画面の「運営ダッシュボード」に「サーバーの外のバックアップ」のカードと問題で出ます（下の「サーバーの監視と通知」）。保存先に置けなかっただけの失敗は「近いうちに」（画面だけ。回線のこともあり、次に送れたら消えます）、**待っても直らない失敗**（設定が読めない・curl や gpg が無い・公開鍵で暗号化できない・送り直しの回数の上限）・**まだ 1 回も送れていないサーバーの失敗**・**最後に送れてから 48 時間を越えた**・**保存先が上書きか削除を断らなかった**（ロックが掛かっていない・外された）は「要対応」で、オーナーへメールも出ます。保存先を設定していないサーバーでは、カードも問題も出ません（設定を外せば、次の報告で消えます）。

サーバーでは `sudo ec-sugar offsite status` に最後の回の結果と理由が出ます。理由の語と、運営ダッシュボードの文:

| 理由の語 | 運営ダッシュボードの文 | 直し方 |
|---|---|---|
| `config` | 保存先の設定が読めません | 設定ファイルの `OFFSITE_…` を直して `sudo ./deploy/install.sh` を再実行する |
| `curl-old` | サーバーの curl が古くて送れません | curl 8.5.0 以上の OS（Ubuntu 24.04 以降）へ |
| `no-gpg` | 暗号化に使う gpg がサーバーにありません | `sudo apt-get install -y gnupg` |
| `no-recipient` | 暗号化の公開鍵が置かれていません | 公開鍵のファイルを設定ファイルの `OFFSITE_RECIPIENT_FILE` に書いて `install.sh` を再実行する |
| `bad-recipient` | 暗号化の公開鍵が使えません。期限が切れたか、壊れています | 新しい公開鍵を置き直す（指紋を手元の鍵と比べる） |
| `put` | 保存先に置けませんでした。間を空けて自動で送り直します | 続くなら `sudo ec-sugar offsite test`（エンドポイント・バケット・鍵の権限・回線） |
| `gave-up` | 何度試しても保存先に置けず、自動で送り直すのをやめました | 原因を直してから `sudo ec-sugar offsite run --retry` |

運営ダッシュボードへ渡すのは、この決まった語と時刻（最後に試した・最後に送れた）と、保存先のロックの確認の結果だけです。エンドポイントの URL・バケットの名前・鍵・バックアップの名前・保存先の設定ファイルは渡しません。送る回（10 分ごと）は、結果が前の回と変わった時だけ報告し、ほかに毎晩の報告にも載ります。「48 時間」はアプリの側の時計で数えるので、送る回そのものが止まっても気づけます。

送れなかったバックアップは、間を倍々に空けて（10 分後・20 分後…6 時間まで）8 回まで送り直し、そこで止めます（途中まで置いたチャンクは消せないので、切れる回線で送り続けない）。原因を直したら `sudo ec-sugar offsite run --retry`。送れないまま溜めるバックアップは 3 つまでで、超えたら古いものから外して通知します。

### 保存先から戻す

戻す先のマシン（新しいサーバー・確認用の複製環境）に EC-SUGAR を入れ、**復元のキット**を root だけが読める 0600 で置いて（例 `scp` で運び、`sudo install -m 600 -o root -g root ec-sugar-recovery-kit.gpg /root/`）:

```sh
sudo ec-sugar offsite pull --list --kit /root/ec-sugar-recovery-kit.gpg
sudo ec-sugar offsite pull --latest --kit /root/ec-sugar-recovery-kit.gpg
sudo ec-sugar restore <出てきた名前> --kit /root/ec-sugar-recovery-kit.gpg
```

- どのコマンドも、**キットのパスフレーズを端末で聞きます**（画面に出ません）。端末の無い所から実行する時だけ、パスフレーズを 1 行で書いた root だけが読める 0600 のファイルを `--kit-passphrase-file <ファイル>` で渡します
- 読み取り用のアクセスキー・エンドポイント・バケット・サーバーの名前・秘密鍵とそのパスフレーズ・APP_KEY は、全部キットの中から読みます（戻す先のマシンに保存先を設定していなくてよい）。開いた中身は `/run/ec-sugar/`（root だけ・ディスクに残らない）にだけ置き、終わっても・失敗しても・途中で止めても消します
- ほかの利用者から読めるキット・パスフレーズのファイル、知らない欄のあるキット・形の違う値・https でないエンドポイントは断ります（中身は出しません）。キットと、下の個別の鍵の引数は同時に渡せません
- 取ったものは指紋（`SHA256SUMS`）まで確認してから `/srv/ec-sugar/backup/` に置かれます。その先は上の「バックアップから戻す」と同じ
- **保存先の中身は、そのままは信頼しません**。同じ名前のペアが 2 つあれば黙って選ばず、保存先の側の時刻つきで並べて `--sent <送った時刻>` を打たせます（書き込み用のアクセスキーを取られると、偽のペアを足されうるため。侵入の前の時刻のものを選ぶ）。名前の時刻と保存先の側の時刻が食い違うペアは「疑わしい」と出ます。暗号化されていない・開けた跡の無い・形の違うペアは置きません
- 写真を取らないなら `--no-files`
- キットのファイル（と、渡したならパスフレーズのファイル）は、戻し終わったら戻す先のマシンから消す
- **記録の連鎖の先頭と照らす**（中央の DB ごと書き換えられたと疑う時）: サーバーは 10 分ごとに、店ごとの記録の最後の番号と hash を `heads/<サーバーの名前>/<年>/<月>/<日>/<時刻>.tsv` に送っています（平文。個人情報は入りません。消せない期間は 400 日）。保存先のサービスの画面から、**疑う時点より前**の `.tsv` を 1 つ取り、戻したサーバー（か今のサーバー）に置いて `sudo ec-sugar restore-check --heads <取ったファイル>` を実行します。店ごとに `ok`（控えた番号の記録が同じ hash で今もある）か `NG`（無い＝末尾が切り落とされた・hash が違う＝書き換えられた）が出て、1 つでも `NG` なら終わりの値が 1 です
- **キットを使わずに、鍵を個別のファイルで渡す形**も残してあります（どれも root だけが読める 0600 のファイルで）: `sudo ec-sugar offsite pull --latest --credentials-file <読む鍵> --decrypt-key-file <開ける鍵> [--passphrase-file <開ける鍵のパスフレーズ>]` → `sudo ec-sugar restore <名前> --app-key-file <APP_KEY の控え>`。秘密鍵のファイルは、手元で `gpg --armor --export-secret-keys "EC-SUGAR offsite backup"` で書き出したもの。保存先を設定していないマシンでは、取る先を `--endpoint <入口> --bucket <バケット> --prefix <元のサーバーの名前>` で渡す（`--prefix` は元のサーバーの管理画面のホスト）

## サーバーの監視と通知

壊れたことは、オーナー全員へのメール（件名「[EC-SUGAR] 動きに問題があります」）と、管理画面の店のホームの上の赤いバナー・「運営ダッシュボード」で通知します（ADR 0043）。メールの設定（`MAIL_*`）が要ります。

| 何を | 誰が見るか | 近いうちに（画面だけ） | 要対応（画面とメール） |
|---|---|---|---|
| 画面のエラー（500）・バックグラウンド処理と定期の処理の失敗 | アプリの中（毎分） | — | 起きたら（同じものは 1 時間に 1 通まで） |
| 止まった支払い・確認が要る返金・記録の改竄・キューの詰まり（待ち 100 件） | アプリの中（10 分ごと） | 届いていない通知メール | 出たら（出ている間 1 回） |
| キューの止まり（5 分ごとに出す脈が 10 分片付かない。待ちが少なくても） | アプリの中（毎分） | — | 出たら（出ている間 1 回） |
| 定期の処理の止まり（最後に数えた時刻が 30 分より古い） | `ec-sugar-watch.timer`（10 分ごと） | — | 出たら（止まってから 30〜40 分でメール） |
| 証明書の期限（管理画面と最初のショップのホスト。リバースプロキシが今出しているもの） | `ec-sugar` | 残り 14 日未満・エンドポイントから読めない | 残り 3 日未満・切れた |
| 空き容量（Docker の保存先〔DB・写真〕と、別のファイルシステムなら `/srv/ec-sugar`〔バックアップ〕） | `ec-sugar` | 空きが 15% 未満 | 5% 未満 |
| 最後の更新 | `ec-sugar` | 取得元に 48 時間届いていない | 失敗・ロールバックした・途中で止めた（次の成功で消える。手でのロールバックは出さない） |
| 最後のバックアップ | `ec-sugar` | まだ一度も無い | 失敗した・48 時間より前 |
| サーバーの外のバックアップ（保存先を設定したサーバーだけ。上の「サーバーの外のバックアップ」。設定していなければカードも出ない） | `ec-sugar-offsite.timer`（10 分ごと。結果が変わった時に渡す） | 保存先に置けなかっただけの失敗（次に送れたら消える） | 待っても直らない失敗（設定・ツール・公開鍵・送り直しの回数の上限）・まだ 1 回も送れていないサーバーの失敗・最後に送れてから 48 時間より前・保存先が上書きか削除を断らなかった（ロックが掛かっていない・外された） |
| 監視の報告そのもの | アプリの中 | 48 時間来ていない | — |
| APP_KEY のバックアップ（サーバーの外に取ったか） | オーナーの回答（運営ダッシュボードの「バックアップを取りました」） | 回答するまで出し続ける（メールも 1 回だけ。「近いうちに」の節で） | — |
| Cloudflare Tunnel のコネクタ（Tunnel の形で、読むだけの鍵があるとき） | `ec-sugar-watch.timer`（10 分ごと） | 確認できない（Cloudflare の API に届かない・鍵の権限が足りない）・監視が 1 時間止まった | よそから接続されている（トークンが漏れたかもしれない）・コネクタが 0（トンネルが落ちている） |
| おかしな動き（ADR 0103。下の「おかしな動きの通知」） | アプリの中（毎分。返金は 10 分ごと） | — | 見つけたら（最後に見つけてから 24 時間出る。出ている間 1 回） |
| サーバーの書き換え（内部の整合性チェック。下の「内部の整合性チェック」） | `ec-sugar-integrity.timer`（毎分） | — | 基準の状態と違う（違いの名前ごとに 1 回）・外部監視が一度は来たのに 30 分来ていない |

- `ec-sugar` が見る分は、毎晩の更新の確認（本番は夜に 1 回、ステージングは 15 分ごと。更新が無くても）と、更新・バックアップ・ロールバックの終わりに（失敗して止まったときも）、app のコンテナの中の `php artisan operations:host-report` へ渡します。HTTP のエンドポイントは使いません。渡すのはホスト名・日時・割合・バイト数・決まった語（`ok` など）・コミットのハッシュだけで、証明書の中身や鍵、更新の一言は渡しません。形の違う値は断られ、前の報告がそのまま残ります
- 通知を送るジョブ（`operations:alert`）は定期の処理の中で毎分動きます。定期の処理が止まるとジョブも止まるので、`ec-sugar-watch.timer` が 10 分ごとに app の中でジョブを直に実行します（`sudo ec-sugar watch`。どの形でも入り、前のバージョンで入れたサーバーにも更新の後に入ります）。同時に動いても、ジョブの中の鍵で通知は 1 通です
- キューの処理（queue）と定期の処理（scheduler）のコンテナは、落ちたら Docker が再起動します（`restart: unless-stopped`）。キューの処理は 1 時間ごとに自分で終わって起き直します（`--max-time=3600`。もれた記憶を溜めない）
- 一度も報告が無いあいだ（入れた直後・手元の開発）は、運営ダッシュボードに「まだ見ていません」と出ます。すぐ見たいときは `sudo ec-sugar report`
- 通知が来たときにすることは、下の「通知が来たときの対応」
- **サーバーそのものが止まったときは、中からは通知できません**（アプリもメールも止まるため）。外からの死活の監視（`https://<管理画面のホスト>/up` を数分ごとに開いて、200 でなければ通知する外のサービス。無料のものもあります）を別に用意してください。SaaS の形では私たちが持ちます

### 通知が来たときの対応

まず `sudo ec-sugar status`（サーバー）と、管理画面の「運営ダッシュボード」（何が・いつから）を見ます。

| 通知 | まず見る | 直し方 |
|---|---|---|
| 画面のエラー（500）・バックグラウンド処理と定期の処理の失敗 | 運営ダッシュボードの問題（種類・場所・回数）、`sudo ec-sugar logs app queue scheduler` | 新しいバージョンの後から出たなら `sudo ec-sugar rollback`。1 つの店だけなら、その店の設定（決済代行の鍵・App）を見る |
| 止まった支払い・確認が要る返金 | 管理画面のその店のホームのやること | 画面の案内どおりに（決済代行の画面で確認してから、支払いを接続する・返金を確認する） |
| 記録の改竄 | 運営ダッシュボードの「記録の検証」（どの店・どこで切れたか） | 触らずに残す（証拠）。`sudo ec-sugar backup` で今の状態を残し、いつから切れたかを前のバックアップで確認する（隔離した複製環境で `restore-check --verify`） |
| キューの詰まり・止まり | `sudo ec-sugar status` の queue、`sudo ec-sugar logs queue redis` | `sudo systemctl restart ec-sugar`。続くなら Redis の空き・メモリを見る |
| 定期の処理の止まり | `sudo ec-sugar logs scheduler` | `sudo systemctl restart ec-sugar` |
| 証明書の期限 | `sudo ec-sugar status` の証明書、`sudo ec-sugar logs caddy` | 自動で取る形は Caddy の記録の理由（DNS・ポート・回数の上限）を直す。手で用意した形は新しい証明書を置いて `install.sh` を再実行する |
| 空き容量 | `df -h /var/lib/docker /srv/ec-sugar`、`sudo du -sh /srv/ec-sugar/backup/*` | 古いバックアップを外へ移すか消す（いちばん新しいものは残す）。写真が多いなら店の写真の上限（`MEDIA_SHOP_QUOTA_MB`）を見直す。ディスクを足す |
| 最後の更新（失敗・ロールバック・止めた） | 上の「更新に失敗した時の手順」 | 同じ |
| 最後のバックアップ（失敗・48 時間より前） | `sudo ec-sugar backup` を実行して理由を見る、`systemctl status ec-sugar-backup.timer` | 空きが足りないなら上の空き容量と同じ。タイマーが読めないなら `sudo ec-sugar install-units` |
| サーバーの外のバックアップ（送れていない・保存先のロックが効いていない） | `sudo ec-sugar offsite status`（最後の回の結果と理由） | 上の「サーバーの外のバックアップ」の「送れない時」。ロックは保存先の画面で、消せない期間の決まりが `db/`・`files/`・`heads/` の 3 つに掛かっているかを確認し、直したら `sudo ec-sugar offsite test` |
| Cloudflare Tunnel（よそからのコネクタ・0） | `sudo ec-sugar status` の Cloudflare Tunnel | よそからなら「Cloudflare Tunnel で出す形」の「トークンが漏れた時」。0 なら回線と `sudo ec-sugar logs cloudflared` |
| おかしな動き（返金・支払いの鍵・拒否・AI エージェント・変化の束） | 下の「おかしな動きの通知」 | 同じ |
| サーバーの中身が基準の状態と違う・外部監視が来ていない | 下の「内部の整合性チェック」の「違いが出た時」 | 同じ |
| 止めた通知・届け直しを待つ通知（注文の後のメール・App の処理） | 運営ダッシュボードの要対応・近いうちに、「エラー」の「通知の処理の失敗」 | 下の「実行し損ねた・止めた通知」 |
| APP_KEY のバックアップをまだ確認していない | `sudo ec-sugar app-key`（説明） | 上の「後から変えにくい設定」の手順で控えをサーバーの外へ移し、運営ダッシュボードで「バックアップを取りました」を押す |
| 外からの監視（サーバーごと止まった） | VM を載せたマシンで `virsh list`・VM の画面（クラウドなら管理の画面） | VM を起動する（`virsh start`）。起きなければ新しい VM に入れ直し、サーバーの外に置いたバックアップから `restore` |

直したら `sudo ec-sugar report` で運営ダッシュボードを今に合わせます（画面の問題は、出なくなれば次の確認で消えます）。

### おかしな動きの通知（ADR 0103）

盗まれたログインや鍵が使われた時に早く気づくため、決まった 5 つの形を毎分数え、越えたら運営ダッシュボードの要対応に出してオーナーへメールします（ほかの問題と同じ 1 通に入ります）。最後に見つけてから 24 時間は出たままで、その間は同じものを 2 通は送りません。記録とメールにあるのは種類・店・件数・時刻だけで、接続元・鍵・誰がは入っていません。しきい値は小さな店に合わせた決まった値で、まだ店ごとには変えられません。

| 通知 | 出る時（どれも「この 1 時間に」） | まず見る | 心当たりが無ければ |
|---|---|---|---|
| 返金が多い（店ごと） | 返金が 10 件以上か、合計 ¥300,000 以上（テスト用の鍵の支払いの返金は数えない） | その店の取引の一覧、ログイン履歴・AI エージェントの鍵の一覧 | 怪しいメンバーを止める・鍵を取り消す・「ほかの端末を全部ログアウトさせる」。Stripe のダッシュボードの返金の記録も見る |
| 支払いの設定の鍵が変わった（店ごと） | Stripe の鍵・テスト用の鍵・Stripe へ接続を、日本の時刻の 0〜6 時か、いつもと違う接続元から入ったセッションで変えた（1 回で） | その店の設定 › 支払い、ログイン履歴 | Stripe のダッシュボードで鍵と Webhook の秘密を作り直して置き直す。Stripe へ接続の行き先を確認する |
| 同じ接続元から失敗が続く（全体で 1 つ） | 同じ回線から 3 つ以上の店で断られたか、ログインの失敗・店への拒否・AI エージェントの鍵の違う要求が合わせて 30 回以上 | ログイン履歴（接続元）、`sudo ec-sugar logs app` | 管理画面の接続元の制限（`ADMIN_ALLOWED_IPS`）を見直す。知っているメンバーなら、権限を確認する |
| AI エージェントの書く操作が多い（店ごと） | 1 つの鍵から書く操作が 60 件以上か、取消・返金・クーポンが 10 件以上 | その店の AI エージェントの鍵の一覧（書く操作を承認してから反映する形なら、承認を待つ案も） | 鍵を取り消す。承認を待つ案は断る |
| 運営と鍵の変化が重なった（全体で 1 つ） | 運営の追加・オーナーへの変更・回復の申し込み・AI エージェントの鍵の発行のうち 3 種類以上 | メンバーの一覧・ログイン履歴・鍵の一覧 | 知らないメンバーを止める・権限を戻す・鍵を取り消す |

- 返金は店の数を集める回（10 分ごと）で数えるので、通知は最大 10 分ほど遅れます
- 自分でしたこと（深夜に鍵を変えた・一斉に取り消した）なら対応は要りません。24 時間で消えます

### 実行し損ねた・止めた通知（ADR 0089）

注文の後の仕事（ご注文の確認のメール・店への通知・チケットやギフトカードの発行・ダウンロードのリンク・App の処理）は、書き込みの直後に流れ、落ちて実行し損ねた分は毎分の定期チェック（`events:relay`）が届け直します。受信側が落ちた時は 1・5・30・120 分と間を空けて届け直し、5 回落ちたら止めます。

- **見方**: 運営ダッシュボードの「近いうちに」に「届け直しを待つ通知が n 件」（自動で届け直します）、「要対応」に「何度も失敗して止めた通知が n 件」が出ます（数は 10 分ごと）。どこで落ちたかは「エラー」の「通知の処理の失敗」の行（場所・回数・関係する記録）と、ログの `記録の受け手が落ちた` の行（`receiver`・`sequence` と処理の ID）で見ます
- **直し方**: 原因（外のサービスが止まっていた・設定の誤り・不具合）を直してから、運営ダッシュボードの「再実行する」（オーナーだけ・パスキーの再確認）を押します。止めた通知を待ちに戻し、その場で実行します。サーバーの上で app のコンテナの中から `php artisan events:relay --tenant=<店の ID> --resume` を実行しても同じです（`ec-sugar` コマンドにはまだ口がありません）
- 受信側は同じ通知を 2 回受けても同じ結果になるように作ってあります（メールは 1 通だけ）。再実行を何度押しても二重には送りません
- 定期チェックが止まっている（定期の処理が止まっている）と、実行し損ねた分は届きません。定期の処理の止まりは上の表のとおり通知します

### 処理の ID で障害を調べる（ADR 0087）

要求・定期の処理・CLI のコマンドごとに、処理の ID（26 文字の英数字。例 `01jc8x2m3n4p5q6r7s8t9v0w1x`）が決まり、そこから積んだバックグラウンド処理・注文の記録（イベント）・監査ログ・送った通知メール・支払いと返金の依頼・決済代行の通知・エラーの記録に同じ ID が残ります。ログの各行にも `trace_id`（店の中の処理なら `tenant_id` も）が付きます。名前・メールアドレスなどは載りません。

1. **ID を見つける**（どれか 1 つ）
   - 運営ダッシュボードの「エラー」: 店の中で起きたものは「関係する記録」から開けます（最後に起きた時の ID）
   - 管理画面の操作: ブラウザの開発者ツールの「ネットワーク」で、その要求の応答のヘッダー `X-Request-Id`（管理画面だけに付きます。ショップには付けません）
   - ログ: `sudo ec-sugar logs app`・`queue`・`scheduler` の行の終わりの `"trace_id":"…"`
2. **関係する記録を並べる**: 管理画面で `/tenants/<店の ID>/traces/<処理の ID>` を開きます（オーナーだけ）。その店のイベント（注文の ID つき）・監査ログ・送ったメール・支払いの画面と返金の冪等キー・決済代行の通知・エラー・失敗したバックグラウンド処理が時刻の順に並びます。ほかの店の記録は出ません
3. **ログを同じ ID で絞る**: `sudo ec-sugar logs --tail all --since 24h app queue scheduler | grep <処理の ID>`（`logs` は既定で各サービスの最後の 200 行なので、`--tail all` と `--since` で広げる）。要求で積んだバックグラウンド処理（メールなど）は、queue のログに同じ ID で出ます。決済代行に断られた時の行には、Stripe の要求の ID（`request`）も出るので、Stripe のダッシュボードの記録と突き合わせられます
4. 失敗したバックグラウンド処理を app のコンテナの中の `php artisan queue:retry <uuid>` でやり直すと、同じ ID のまま動きます（uuid は 2. の「失敗したバックグラウンド処理」の ID）

外から来る `X-Request-Id` の見出しは使いません（だれでも好きな値を付けられるため）。前段（Cloudflare など）の記録と突き合わせるときは、時刻と URL で合わせてください。

## 内部の整合性チェック（入られた後の書き換えに気づく。ADR 0105）

`ec-sugar-integrity.timer`（毎分、どの形でも。入れた時と更新の後の `install-units` で入る）が、サーバーの**基準の状態**と今の状態を照合し、結果に Ed25519 で署名して出します。入られてイメージ・compose・リバースプロキシの設定・unit・運用のスクリプトを差し替えられたら、雑な手口なら 1 分ほどで気づきます。内部の整合性チェックは root を取られれば無効にされるので、別のマシンに置く外部監視（`ec-sugar-sentinel`）が署名したステータスを取りに来て確認し、お互いの生存も見ます。

**基準の状態**（`/srv/ec-sugar/trust/integrity/expected`。root だけ）に記録するもの:

- 動くはずの compose のサービスと各々のイメージ ID（compose が作ったコンテナ。止まっているものも「動くはず」に入る）
- compose のファイル（使っている形の重ね書きも）・リバースプロキシの設定（Caddy の設定の保存先の全部・nginx の雛形と外の口。Cloudflare Tunnel の形は cloudflared の行き先と元の証明書）・systemd の unit と drop-in（`ec-sugar` で始まるもの全部）・運用のスクリプト（`/usr/local/bin/ec-sugar` の指す先・`lib`・compose のセキュリティチェック）・タグの署名で信頼する鍵の sha256
- 世代（`generation`）。記録するたびに 1 つ上がる（中身が前と同じなら上がらない）

**記録し直す時**: 入れた時（`install.sh`）・更新とロールバックの後・`install-units` の後は、確認したコミットから作り直したのでいつも。`admin-ips`・`shops`・`trust add`・`trust remove`・`restore` の後は、**最後のチェックで違いが出ていなかった時だけ**（違いを黙って「基準の状態」にしない）。source チャネル（`CHANNEL=source`）で作業ツリーに手で変えた所があれば記録しません。手で記録し直すのは `sudo ec-sugar integrity record`（違いを調べて、正しい変更と分かった時だけ）。

**チェックすること**と、違った時に出る問題の名前（値・パス・秘密は出しません）:

| 名前 | 意味 |
|---|---|
| `image:<サービス>` | そのサービスのコンテナのイメージ ID が記録と違う（同じイメージでない一度きりのコンテナも） |
| `service:missing:<サービス>` | 動くはずのサービスのコンテナが動いていない |
| `container:extra` | compose の外で、許していないコンテナが動いている（記録に無いサービスのラベルのもの・同じサービスが増えたものも）。許すものは設定ファイルの `INTEGRITY_ALLOW_CONTAINERS`（名前か `*` の付いた形のカンマ区切り） |
| `file:<短い名前>` | ファイルが記録と違う・消えた・増えた（例 `file:compose`・`file:caddy-caddyfile`・`file:nginx-prod.conf`・`file:unit-ec-sugar.service`・`file:tool-ec-sugar`・`file:tool-common.sh`・`file:trust-signers`・`file:tunnel-config`） |
| `docker:unreachable`・`expected:missing` | Docker に聞けない・記録が無い |

更新・バックアップ・`restore` などが動いている間（`ec-sugar` の鍵を持っている間）は照らさず、前の結果に `"busy":true` を足して署名だけ新しくします（切り替えの途中を違いにしない）。

**署名したステータス**は、どのホスト（管理画面・どのショップ）でも次の 2 つで返します（受付の nginx が、読み取り専用で差し込んだ保存先からそのまま返す。PHP を通さない・キャッシュなし・GET だけ・接続元ごとに毎秒 2 回まで）:

```
https://<ホスト>/.well-known/ec-sugar/integrity.json       1 行の JSON（末尾に改行なし）
https://<ホスト>/.well-known/ec-sugar/integrity.json.sig   integrity.json のバイト列そのものへの Ed25519 の署名を base64 の 1 行で
```

```json
{"format":1,"server":"<16 桁の 16 進>","generation":12,"checked_at":"2026-11-13T05:00:00Z","ok":true,"problems":[]}
```

`server` は入れた時に作るこのサーバーの識別子（変わらない）、`checked_at` はチェックした時刻（UTC）。チェックしていない間は `"busy":true` が足されます。コミットやバージョンは載せません（公開の URL で版を教えない）。

**公開の鍵**は `sudo ec-sugar integrity key` で出します（PEM は標準出力、識別子は標準エラー）。外部監視に置きます:

```sh
sudo ec-sugar integrity key > ec-sugar-integrity.pub.pem
# 手で確認する（外部監視と同じ確認の方法。openssl 3 が要る）
curl -s https://<ホスト>/.well-known/ec-sugar/integrity.json -o integrity.json
curl -s https://<ホスト>/.well-known/ec-sugar/integrity.json.sig | openssl base64 -d -A -out integrity.sig.bin
openssl pkeyutl -verify -pubin -inkey ec-sugar-integrity.pub.pem -rawin -in integrity.json -sigfile integrity.sig.bin
```

署名の鍵（`/srv/ec-sugar/trust/integrity/key`、root の 0400）はどのコンテナにも差し込まないので、アプリ（PHP）が乗っ取られても署名できません。鍵をローテーションしたい時は、鍵を消してから `sudo ec-sugar integrity check` を実行すると新しい鍵で署名し直します（外部監視に新しい公開の鍵を置き直す。識別子は変わらない）。

**外部監視が来ているか**: nginx は、外部監視が 2 つのファイルを取りに来た時刻だけを web の中のメモリの記録（`/var/log/ec-sugar-integrity/`。ホストには書かない）に残し、毎分のチェックが読んで空にします。外部監視が一度でも来たことがあって、30 分来ていなければ要対応（監視のマシンが止まったか、無効にされた）。一度も来ていない（外部監視を置いていない）間は何も出しません。この通知は状態（JSON）には載せません（監視が戻った時に誤報にしないため）。

違いと外部監視の不在は、結果が変わった時だけ運営ダッシュボードへ渡し（`operations:host-report`。毎分は渡さない）、要対応とオーナーへのメールに出します（違いは名前ごとに 1 回）。`sudo ec-sugar status` にも最後の結果が出ます。

### 違いが出た時

1. `sudo ec-sugar status` と `sudo ec-sugar integrity check` で、何が違うか（名前）を見る
2. 自分たちの更新・設定の変更に心当たりがあれば（手で compose や unit を変えた・`docker run` で別のコンテナを動かした）、元に戻すか、正しい変更なら `sudo ec-sugar integrity record` で記録し直す（世代が上がり、外部監視は「変わった」を 1 回通知する）
3. 心当たりが無ければ、入られたかもしれません。触らずに `sudo ec-sugar backup` で今の状態を残し、`sudo docker ps -a`・`sudo journalctl`・`/var/log/auth.log` で何が足されたかを見ます。ツールや unit が差し替えられていたら、そのサーバーの中のものは信頼せず、新しい VM に入れ直して、サーバーの外に置いたバックアップから `restore` するのが確かです

限り: root を取って署名の鍵まで使う相手には、内部の整合性チェックと状態は偽れます。その時も、お客さんに害が出る形（ショップへのスクリプトの差し込み）は外部監視が店頭の指紋で見ます。

## 外部監視を置く（ADR 0105）

サーバーの内部の整合性チェック（`ec-sugar integrity`。毎分、動いているイメージ・compose・リバースプロキシの設定・運用のスクリプトを、基準の状態と照合する）と、上の監視と通知は、どれも**同じサーバーの中**にいます。root を取られると、チェックの結果も通知も無効にされます。そこで、サーバーが毎分出す**署名したステータス**（`https://<ホスト>/.well-known/ec-sugar/integrity.json` と `integrity.json.sig`）と、**外から見える店頭**を、別のマシンからチェックします。これが外部監視 `ec-sugar-sentinel` です。

- **別のマシンに置く**: 安い VPS・手元の Mac・家の別の PC など、EC-SUGAR のサーバーとは別の、いつも動いているマシン。同じサーバーや同じ VM の上に置くと、一緒に無効にされます。私たちがホストする監視はありません（配った後の人が私たちに頼らないため）。家の中の PC でもよい（取りに行く形なので、エンドポイントは要りません）
- **見ること**:
  - 状態: 署名が公開の鍵で通る・`format` が 1・`server`（入れた時に作る識別子）が基準と同じ・`ok` が真・`checked_at` が 5 分以内（2 分より先でもない）・busy（更新の途中）が 30 分より続かない。世代（`generation`。基準の状態を記録し直すたびに上がる）が変わったら 1 回だけ通知する
  - 店頭の指紋: 決めたページの応答のヘッダー（CSP・`Refresh`・`Link`・`Content-Type`）・`<script src>` の宛先・同じ取得元のスクリプトの中身・インラインのスクリプト・`<form action>`・`<iframe>` の取得元・`meta http-equiv`（refresh など）・`LINKS_PAGE` のページだけ外へのリンクの取得元。基準（`accept` で取る）と違えば、足された・消えた・変わったを並べて通知する。**入られた後に、支払いの前のページへスクリプトを差し込む・フォームの送信先を替える・偽の支払いの画面へ飛ばす**、といった、お客さんに害の出る書き換えに気づくため
  - 隠した要素: 要素は、HTML の注釈や `<![CDATA[` の中も含めて生の HTML から拾います。HTML を解く部品（python3 の HTMLParser）とブラウザで注釈の終わりの解き方が違い（`<!-->`・`<!--->`・`--!>` など）、解く部品には注釈に見えてもブラウザでは生きる要素があるためです。解いた結果に無い要素が生の HTML にあるか、注釈（`<!--` から次の `-->` まで）か CDATA の中に要素があれば、それだけで「隠した要素があります」と通知し、基準の取り直し（accept）も断ります。解く部品の読み方は python3 の版で変わる（3.15 は注釈の終わりをブラウザに寄せた）ので、注釈と CDATA の範囲は自分で拾い、版によらず同じ結果にしています。ページが UTF-8 でない（`Content-Type`・BOM・`meta charset` のどれかが別の文字コード、どれも記載していない、UTF-8 として読めない）時も通知します（監視とブラウザで HTML の読み方が変わるため）
- **見ないこと**: 文・写真・値段（店の人が直すもの）、`LINKS_PAGE` でないページのリンク、同じ取得元へのリンク、CSRF のトークンのように毎回変わる値、JSON-LD の中身（数だけ）、CSS、外の取得元のスクリプトの中身（宛先だけ）、設定に書いていないページ。監視のマシンそのものを取られた時は、正しい指紋を外から得られません（release チャネルで、署名した「店頭の指紋のもと」を出す時に解く。ADR 0105）
- **監視にだけきれいな設定ファイルを返す手口**: 入られたサーバーは、監視の接続元・User-Agent・来る時刻を覚えて、監視にだけ書き換える前の店頭を返せます（お客さんには書き換えた店頭）。監視の User-Agent は既定でふつうのブラウザの形にし、パスフレーズはページの取得には付けません。それでも接続元は見分けられるので、気になるときは、**ページを別の接続元からも取ります**: 別の回線・別の VPS にもう 1 つ監視を置く（同じ公開の鍵、`STATE_DIR` は別）、ふだん使っている回線（家・店）のマシンから実行する、など。ステータスの取得（署名で確認する）とページの取得は、同じマシンでなくてよいです

### 要るもの

- bash（macOS の 3.2 でよい）・curl・**OpenSSL 3 以上**・python3（標準のライブラリだけ。macOS の `/usr/bin/python3` の 3.9 でよい）
  - Ubuntu・Debian: `sudo apt install curl openssl python3`（Ubuntu 22.04・Debian 12 から openssl は 3）
  - macOS: 入っている openssl は LibreSSL で使えない（Ed25519 の確認が無い）。`brew install openssl@3`（監視が自分で探します。ほかの所に入れたら設定の外で `OPENSSL=<道筋>` を渡す）
- 本番のサーバーの公開の鍵。サーバーで `sudo ec-sugar integrity key` が PEM で出します（秘密の鍵はサーバーの root だけが読める所にあり、外へ出しません）
- 監視のトークン（任意）。サーバーで `sudo ec-sugar integrity token` が 32 桁の 16 進で出します。監視が状態を取る時に見出し `X-EC-Sugar-Sentinel` で付けると、サーバーの中が「外部監視が 30 分来ていない」に気づけます（監視が無効にされた時に中が通知する。ADR 0105 の 4）。無くても監視は動きます（中の「来ていない」の通知が出ないだけ）
- 通知の道: Slack の Incoming Webhook の URL（同じ形の `{"text": "…"}` を受けるほかのサービスでも）か、本文を標準入力で受けるコマンド（`mail` など）

### 置き方

```sh
# 監視のマシンで（このリポジトリの deploy/sentinel/ec-sugar-sentinel を 1 つコピーするだけ）
sudo install -m 0755 ec-sugar-sentinel /usr/local/bin/ec-sugar-sentinel
sudo useradd --system --home-dir /var/lib/ec-sugar-sentinel --create-home --shell /usr/sbin/nologin ec-sentinel   # Linux なら専用の利用者で
sudo install -d -m 0755 /etc/ec-sugar-sentinel

# 本番のサーバーで公開の鍵を出し、監視のマシンの /etc/ec-sugar-sentinel/integrity.pub にコピーする
sudo ec-sugar integrity key

# Webhook の URL を 1 行で書いたファイル（監視を実行する利用者だけが読める 0600。中身は記録にも通知にも出さない）
sudo install -m 0600 -o ec-sentinel /dev/null /etc/ec-sugar-sentinel/webhook
sudo -e /etc/ec-sugar-sentinel/webhook

# 監視のトークン（任意）。本番のサーバーで sudo ec-sugar integrity token が出す 1 行を、同じく 0600 のファイルに
sudo install -m 0600 -o ec-sentinel /dev/null /etc/ec-sugar-sentinel/token
sudo -e /etc/ec-sugar-sentinel/token

# 設定（deploy/sentinel/sentinel.conf.example をコピーして直す）
sudo install -m 0644 sentinel.conf.example /etc/ec-sugar-sentinel/shop.conf
sudo -e /etc/ec-sugar-sentinel/shop.conf

# 試す: 通知が届くか → 店頭の基準を取る（指紋を並べて見せる）→ 確認する（「ok」で 0 で終わる）
sudo -u ec-sentinel ec-sugar-sentinel test-notify /etc/ec-sugar-sentinel/shop.conf
sudo -u ec-sentinel ec-sugar-sentinel accept /etc/ec-sugar-sentinel/shop.conf
sudo -u ec-sentinel ec-sugar-sentinel check /etc/ec-sugar-sentinel/shop.conf
```

**sudo が無いマシンに置くとき**（共用のホスティングの利用者など）: 上の `/usr/local/bin`・`/etc`・`/var/lib` は決まりではありません。パスはどれも設定で決めるので、利用者の持ち物のフォルダ 1 つにまとめて置けます（スクリプト・`integrity.pub`・`token`・設定・`STATE_DIR`。フォルダは 0700、鍵と設定は 0600）。実行するのは利用者の cron です。

```sh
# 利用者の cron に 1 行（5 分ごと）。<保存先> は自分の持ち物のフォルダ
*/5 * * * * <保存先>/ec-sugar-sentinel check <保存先>/shop.conf >> <保存先>/log 2>&1
```

本番は外部監視が 30 分来ないと問題として通知するので、**試しに手で 1 回実行したら、cron まで続けて入れてください**（手で実行しただけで止めると 30 分後に通知が飛びます）。

設定（key=value。`#` で始まる行と空の行は飛ばす。シェルとしては読まないので、`$` や `` ` `` は書いたまま渡ります）:

| 名前 | 中身 |
|---|---|
| `STATUS_URL` | 状態の URL（`https://<どれかのホスト>/.well-known/ec-sugar/integrity.json`。署名は同じ URL に `.sig` を足した所から取る）。https だけ |
| `PUBLIC_KEY` | 公開の鍵の PEM のパス（Ed25519 でなければ断る） |
| `SENTINEL_TOKEN_FILE` | 監視のトークン（`sudo ec-sugar integrity token` が出す 32 桁の 16 進）を書いたファイル（任意）。0600 の本人のファイル。状態の 2 つの取得にだけ見出し `X-EC-Sugar-Sentinel` で付け、ページの取得には付けない |
| `PAGE` | 指紋を取るページ（何行でも、`LINKS_PAGE` と合わせて 20 まで）。トップ・よく売れる商品など、**ログインの要らない画面**にする（マイページなどは人ごとに変わる）。複数の店の形なら店ごとに並べるか、店ごとに設定を分ける |
| `LINKS_PAGE` | `PAGE` と同じに指紋を取り、加えて外の取得元へのリンク（`<a href>`）の取得元も指紋に入れるページ。カートなど、外へのリンクが決まっていて、偽の支払いの画面へ誘われると困るページにする（同じ取得元へのリンクとパスは見ない） |
| `WEBHOOK_FILE` | Slack 形式の Webhook の URL を 1 行で書いたファイル。監視を実行する利用者の持ち物で、ほかの人が読めない（0600）こと。リンクは断る |
| `NOTIFY_COMMAND` | 本文を標準入力で渡すコマンド（`sh -c` で実行する）。件名は `$EC_SENTINEL_SUBJECT`、名前は `$EC_SENTINEL_NAME` で渡す。例 `NOTIFY_COMMAND=mail -s "$EC_SENTINEL_SUBJECT" you@example.jp`。`WEBHOOK_FILE` と両方でもよい |
| `STATE_DIR` | 状態（通知した問題・数・世代）と店頭の基準（`baseline.json`）を置くディレクトリ。無ければ 0700 で作る。監視を実行する利用者の持ち物で、ほかの人が書けないこと |
| `NAME` | 通知に出す名前（既定は `STATUS_URL` のホスト） |
| `TIME_LIMIT` | 1 回の確認の時間の上限（秒、既定 120）。越えたら「時間の上限を越えました」を通知、見られなかったページは次の回に |
| `USER_AGENT` | 取る時の User-Agent（既定はふつうのブラウザの形。監視だと記載しない） |

- 設定・Webhook の URL・パスフレーズ・コマンドは、記録（画面に出す行）にも通知にも出しません。Webhook の URL とトークンは `curl` へ設定として標準入力で渡し、コマンドの引数（`ps` で見える）に載せません
- 取る時は https だけ・転送を追わない・1 つの取得は 30 秒まで・1 回全体は `TIME_LIMIT` まで・大きさの上限（状態 64 KB・ページ 2 MB・スクリプト 4 MB）。HTML や JSON の中身はシェルに渡しません
- **状態の結果は、ページを見る前に通知します**（遅いページで、状態の問題の通知を遅らせられないように）。1 回で通知が 2 通になることがあります
- Slack には、通知の中の URL を展開させません（`unfurl_links`・`unfurl_media` を切る。偽の宛先を Slack に開かせない）
- Cloudflare のボットの確認（チャレンジ）を掛けているなら、監視のマシンの接続元を許す決まりを足します（止められると「開けない」が続きます）
- 終わりの値: 0 は問題無し、1 は問題がある（通知を送れなかった時も）、2 は使い方か設定の誤り。同時に 2 つは流れません（前の回が動いていれば飛ばす）。**前の回が 15 分終わらない**（ロックが取れない）と「前の回の確認が終わりません」を 1 回通知して 1 で終わり、終わったら「終わりました」を通知します

### 定期に実行する（5 分ごとを勧める）

cron（Linux・macOS）:

```sh
# sudo crontab -u ec-sentinel -e
*/5 * * * * /usr/local/bin/ec-sugar-sentinel check /etc/ec-sugar-sentinel/shop.conf > /dev/null
```

systemd の timer（Linux）:

```ini
# /etc/systemd/system/ec-sugar-sentinel.service
[Unit]
Description=EC-SUGAR の外部監視
Wants=network-online.target
After=network-online.target

[Service]
Type=oneshot
User=ec-sentinel
ExecStart=/usr/local/bin/ec-sugar-sentinel check /etc/ec-sugar-sentinel/shop.conf
# 問題があると 1 で終わる（通知は送った後）。unit を失敗の扱いにしない
SuccessExitStatus=1
NoNewPrivileges=yes
ProtectSystem=strict
ReadWritePaths=/var/lib/ec-sugar-sentinel
PrivateTmp=yes

# /etc/systemd/system/ec-sugar-sentinel.timer
[Unit]
Description=EC-SUGAR の外部監視（5 分ごと）

[Timer]
OnBootSec=2min
OnUnitActiveSec=5min

[Install]
WantedBy=timers.target
```

```sh
sudo systemctl daemon-reload && sudo systemctl enable --now ec-sugar-sentinel.timer
journalctl -u ec-sugar-sentinel -n 20    # 最後の結果
```

launchd（macOS。ログインしている人の分で動かすなら `~/Library/LaunchAgents/`。`STATE_DIR` と Webhook のファイルはその人の持ち物にする）:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<!-- ~/Library/LaunchAgents/jp.ec-sugar.sentinel.plist。launchctl load ~/Library/LaunchAgents/jp.ec-sugar.sentinel.plist -->
<plist version="1.0">
<dict>
  <key>Label</key><string>jp.ec-sugar.sentinel</string>
  <key>ProgramArguments</key>
  <array>
    <string>/usr/local/bin/ec-sugar-sentinel</string>
    <string>check</string>
    <string>/Users/you/.config/ec-sugar-sentinel/shop.conf</string>
  </array>
  <key>EnvironmentVariables</key>
  <dict><key>PATH</key><string>/opt/homebrew/bin:/usr/bin:/bin</string></dict>
  <key>StartInterval</key><integer>300</integer>
  <key>StandardOutPath</key><string>/Users/you/Library/Logs/ec-sugar-sentinel.log</string>
  <key>StandardErrorPath</key><string>/Users/you/Library/Logs/ec-sugar-sentinel.log</string>
</dict>
</plist>
```

Mac は閉じると止まります。止まっている間は何も見ないので、いつも動いているマシン（小さな VPS など）が確かです。

### 更新の後の accept

更新・ロールバック・設定の変更（`admin-ips`・`shops` など）の後は、サーバーの世代が上がり、監視から「基準の状態が変わりました（世代 12 → 13）」が 1 回だけ届きます。心当たりがあればそれで終わりです。

更新でスクリプトのファイルの名前（`/build/assets/…`）や中身が替わると、店頭の指紋も基準と違うと通知が来ます。**違いを見てから**取り直します:

```sh
sudo -u ec-sentinel ec-sugar-sentinel accept /etc/ec-sugar-sentinel/shop.conf
```

`accept` は前の基準との違いを並べて見せます。更新の後なら、替わるのは同じ取得元の `/build/assets/…` のファイルの名前と中身くらいです。**知らない外のドメインのスクリプト・フォームの送信先・iframe・CSP に足された宛先があれば、取り直さずに調べてください。** 次のときも取り直します:

- 解析・広告のタグの App を使い始めた・やめた・ID を変えた（スクリプトと CSP が変わる）
- 動画・地図のセクションを足した・外した（iframe と CSP の frame-src が変わる）
- テーマを替えた、ページの構成を大きく変えた

`accept` は、署名が通らない・形が違う時は基準を書き換えずに止まります。次の時も断ります。調べた上で取るなら `accept <設定> --force` で越えます:

- サーバーが問題を出している（`ok: false`。busy の途中でも）
- 状態が古い（`checked_at` が 5 分より前）か、2 分より先
- ページに注釈などに隠した要素がある・UTF-8 でない

サーバーを入れ直した（`server` が替わった）ときも、確認してから `accept` すると新しい `server` を記録します。

### 通知の受け方と対応

同じ問題は続いている間 1 回だけ、戻ったら「戻りました」を 1 回送ります（違いの中身が変わったら、もう一度）。送れなかった通知は、次の回に送り直します。

| 通知 | いつ | まずすること |
|---|---|---|
| 状態の署名が公開の鍵で通りません | すぐ（1 秒後に取り直しても通らない時） | 鍵をローテーションした心当たりが無ければ、状態が書き換えられている。サーバーで `sudo ec-sugar status` と `sudo ec-sugar integrity check` を見て、入られた見込みとして調べる |
| 状態の形が違います | すぐ | 監視かサーバーのバージョンが古い・新しすぎる見込み。どちらかを合わせる |
| 状態の server が基準と違います | すぐ | 入れ直した・別のサーバーを向いた心当たりがあれば `accept`。無ければ、DNS やトンネルが別のマシンへ向けられていないか |
| サーバーの内部の整合性チェックが問題を出しています（`image`・`service`・`container`・`config`・`code`・`file`・`docker`・`expected`・`lock` などの種類） | すぐ | 公開の状態には種類だけが載ります。細かい名前（どのサービス・どのファイル）はサーバーで `sudo ec-sugar status`（`sudo ec-sugar integrity check` でも）。更新やファイルの手直しに心当たりが無ければ、書き換えられたものとして調べる |
| 状態が古いままです・時刻が先に進みすぎています | 2 回続いたら | 内部の整合性チェック（毎分の timer）が止まっている・サーバーの時計がずれている。どちらでもなく直らなければ、無効にされた見込み |
| 状態を取れません | 2 回続いたら | サーバーごと止まっている・回線・Cloudflare。上の「通知が来たときの対応」の外からの監視と同じ |
| 更新などの途中（busy）が 30 分を超えています | 30 分続いたら | 更新が途中で止まった見込み。`sudo ec-sugar status` と「更新に失敗した時の手順」 |
| 基準の状態が変わりました（世代） | 1 回だけ | 更新・設定の変更の心当たりが無ければ調べる。あれば、店頭の通知を見てから `accept` |
| 店頭の指紋が基準と違います | すぐ | 違いの行を見る。知らない外の宛先・フォームの送信先・インラインのスクリプトなら、**お客さんの入力を抜かれている見込み**。ショップを準備中にする（管理画面の設定）か、サーバーを止めてから調べる。更新や App の変更の心当たりがあれば `accept` |
| 店頭に、注釈などに隠した要素があります | すぐ | ショップのテンプレートには HTML の注釈も CDATA も無いので、まず書き換えを疑う。通知の「隠れた」の行（宛先）を見て、店頭の指紋が違う時と同じに |
| 店頭のページが UTF-8 ではありません | すぐ | リバースプロキシ（Caddy・前段のリバースプロキシ・Cloudflare）の設定で `Content-Type` の charset が変わっていないか。心当たりが無ければ書き換えを疑う |
| 1 回の確認が時間の上限を越えました | すぐ | ページの応答が遅い。サーバーの重さか、監視を遅らせる手口。続くなら `TIME_LIMIT` を見直す前に、ブラウザでも遅いかを確認する |
| 前の回の確認が終わりません | 15 分続いたら | 監視のマシンで、残った `ec-sugar-sentinel` の処理と記録を見る（遅い設定ファイルで止められている・マシンが詰まっている） |
| 店頭の基準がありません | 1 回だけ | `accept` で取る |
| 店頭のページを開けません | 2 回続いたら | ページの URL が変わっていないか（商品を消した・パスを変えた）。消したなら設定から外す |


## 前の形（nginx がリバースプロキシ）から移る

より前に入れたサーバーは、nginx が https を受け、証明書を手で渡す形です。**自動の更新ではリバースプロキシの 2 段構成に移れません**（前のバージョンの `ec-sugar` は Caddy を知らないので、新しいバージョンにヘルスチェックを通させられず、前のバージョンへロールバックして止めます。動き続けるので壊れはしません）。次の手順で移ります。

```sh
cd ~/ec-sugar && git pull          # リバースプロキシの 2 段構成のバージョンを取る（実行した人のリポジトリ）
./deploy/install.sh --check        # 確認だけ（80・443 番は「EC-SUGAR の入口が使っている」と出る）
sudo ./deploy/install.sh           # 前の形と分かると、このリポジトリのバージョンへ ec-sugar update で上げて移る
```

- 設定ファイルの `TLS_CERT`・`TLS_KEY` はそのまま使われます（手で用意した証明書の取り方 manual で移る）。Let's Encrypt の自動に替えるなら、移った後に 2 行を消して再実行する（先に DNS の 2 行を確認する。3/5）。ステージングは dns か manual のまま
- 移るときは更新と同じ順です（バックアップ → マイグレーション → 切り替え → ヘルスチェック → 駄目なら前のバージョンへロールバック）。切り替えでは nginx がポートを手放し、Caddy が 80・443 番を受けます
- **すでにある Web サーバーの後ろに置く形を b03d51d のバージョン（ソケットのディレクトリの持ち主が nginx の利用者だったバージョン）で入れたサーバー**は、自動の更新では新しいディレクトリの形（root・専用のグループ・ACL）に移れません（更新は「env に足りない項目がある」と止まり、前のバージョンのまま動きます）。新しいバージョンのリポジトリで `sudo ./deploy/install.sh` を再実行してから更新します
- **このバージョンより前のツールから最初に更新したとき**は、朝のバックアップのタイムゾーンの drop-in と、ホストのファイアウォール（どの形でも有効にした）が入らないことがあります（前のバージョンのツールは新しい unit の入れ方を知らない）。更新の後に `sudo ec-sugar install-units` を実行するか、次の更新を待ちます（`status` が「タイマーを読めていません」と出したら実行する）
- **前のバージョンへロールバックする**（`sudo ec-sugar rollback`）と、前の形の compose に戻り、Caddy のコンテナは片付けられ（`--remove-orphans`）、nginx がまた 80・443 番を受けます。前の形の証明書の保存先（`/srv/ec-sugar/secrets/tls`）と env の `SUGAR_EC_TLS_CERT`・`SUGAR_EC_TLS_KEY` の行（前の形が読む前の名前）は消さないので、戻してもそのまま動きます

## 非公開のリポジトリから取る

自動の更新は、利用者 `ec-sugar` が取得元から取ります。非公開のリポジトリでは、その利用者に読み取りだけの鍵（deploy key）を持たせます。

```sh
sudo ec-sugar deploy-key        # 鍵を作り、公開の側と、取得元を ssh の形に替えるコマンドを出す
# GitHub のリポジトリの Settings → Deploy keys → Add deploy key に貼る（Allow write access は付けない）
# 出たコマンドで取得元を git@github.com:… に替え、届くかを確認する
```

GitHub の**組織**のリポジトリでは、組織の設定で deploy key が使えないことがあります（新しい組織の既定。`Deploy keys are disabled for this repository` と断られる）。その場合は組織の設定で deploy key を開けるか、公開のリポジトリから取る形（公開版の `CHANNEL=release`。署名したタグだけを取るので鍵が要らない）にします。

取得元に届かない間は `sudo ec-sugar status` の「注意」に出ます。付け替えて届くようになれば、次の更新（自動でも `sudo ec-sugar update` でも）で消えます。

## 失敗しがちなポイント

- **Plesk などのサーバーで「80・443 番が使われている」と出る**: Web サーバーを止めないでください（ほかのサイトがすべて止まります）。すでにある Web サーバーの後ろに置く形（`FRONT=proxy`）にします
- **クラウドのファイアウォールで 80・443 番を開け忘れる**: DigitalOcean の Cloud Firewall、AWS のセキュリティグループ、Google Cloud のファイアウォールのルールは、サーバーの中の ufw とは別です。両方で開ける。開けていないと 5/5 で「https で開けません」になります（Let's Encrypt が確認に来られない）
- **DNS の反映を待たずに先へ進めたい**: 3/5 は公開の DNS で見ています。DNS の会社の画面で足した直後は、反映に数分〜1 時間かかることがあります。Ctrl-C で抜けて、後で再実行できば続きから進みます
- **AAAA レコードが残っている**: ドメインを買ったときの初期の AAAA があると、Let's Encrypt が IPv6 で確認に行って失敗します。消す
- **Let's Encrypt の回数の上限**: 失敗を短い間に何度も繰り返すと、しばらく取れなくなります（1 時間ほど待つ）。DNS とファイアウォールを先に直してから再実行する。証明書のボリューム（`ec-sugar_caddy_data`）を消さない
- **compose のファイルにポートを足す**: Docker は ufw を迂回するので、`-p 5432:5432` のように IP アドレスの無いポートを足すと DB が世界に出ます。ポートは `EC_SUGAR_BIND` のリバースプロキシ（caddy）だけ（CI の検査が監視しています）
- **リバースプロキシの前のネットワークがほかのネットワークと重なって起きない**（`Address already in use`・`invalid ip-range` など）: リバースプロキシの前のネットワークは `172.30.255.224/28` を使います。重なるときは env の `EC_SUGAR_FRONT_SUBNET`・`EC_SUGAR_FRONT_RANGE`・`EC_SUGAR_CADDY_ADDR` の 3 つをそろえて書く（Caddy の IP アドレスは範囲の中で、自動で配る範囲の外）
- **AWS・Google Cloud の待ち受けるアドレス**: 公開の IP はサーバーのネットワークの口には付いていません（外で付け替えている）。待ち受けるアドレスは既定のまま（サーバーのプライベート IP アドレス）にし、DNS には 3/5 が出す「外から見た IP アドレス」を書く
- **ステージングを VM に入れ、ホストが 443 番を VM へ渡す形で、LAN の範囲を VM のネットワークにする**: 接続元は見る端末の IP アドレスのまま届くので、`LAN_CIDR` と `ADMIN_ALLOWED_IPS` は見る端末のいる LAN（例 192.168.0.0/24）にする。VM のネットワーク（例 10.0.0.0/24）にすると、80・443 番が DOCKER-USER の決まりで落ちる
- **初めて起動したサーバーで apt が待たされる**: 起動の直後は自動の更新が apt を使っています。インストーラーは最大 10 分待ちます
- **メモリが足りずにイメージを作るのが落ちる**（記録に `exit code: 137`）: メモリ 4 GB 以上のプランにする
- **管理画面を開けない**: 接続元が `ADMIN_ALLOWED_IPS` に無い（家の IP が変わった・携帯の回線から開いた）。設定ファイルを直して再実行する
- **踏み台（`ssh -J`）を経由して実行し、管理画面を開ける接続元に踏み台の IP アドレスを入れてしまう**: 許すのは「ブラウザで見る端末」の IP アドレスです。SSH の接続元がプライベート IP アドレスかサーバーと同じネットワークの IP アドレスなら、インストーラーは既定を出さずに聞きます（2026-10-29、ステージングの VM で、VM を載せたマシンのプライベート IP アドレスが既定になり、見る端末から開けなかった）
- **APP_KEY のバックアップを取らずにサーバーを消す・失う**: バックアップがあっても個人情報が戻せなくなります（APP_KEY はバックアップに入りません）。入れた直後に `sudo ec-sugar app-key export <ファイル>` でコピーしてサーバーの外へ移し、運営ダッシュボードで「バックアップを取りました」を押す。押すまで運営ダッシュボードに通知が出続けます
- **APP_KEY のバックアップを画面に出してコピーする**（`cat` で出して貼り付ける）: 端末の記録や貼り付けの履歴に鍵が残ります。`sudo ec-sugar app-key export <ファイル>` でファイルにコピーし、ファイルのまま移す
- **手で `docker system prune -a` を実行する**: 前のバージョンのイメージが消え、`rollback` で戻れなくなります（`ec-sugar update` は今と前のバージョンのイメージを残して、それより古いものを片付けます。イメージをビルドする時のキャッシュ〔Docker の build cache〕も、1 週間より前を消したうえで全体を 4 GB〔`EC_BUILD_CACHE_MAX`〕までに収めます）
- **時計がずれている**: パスキーの登録が通りません。`sudo timedatectl set-ntp true`
- **パスキーの登録が「取り消されたか、時間切れ」になる**（Touch ID まで済んだのに）: ブラウザがその https を信頼していない（アドレスバーが「保護されていない通信」）。パスキーは信頼された https のページでしか登録できません。auto と dns の取り方は Let's Encrypt の証明書なので起きません。manual で自分で作った認証局を使うと、OS が信頼してもブラウザが信頼しないことがあります（2026-10-29、ステージングで Brave と Safari で起きた）
- **非公開のリポジトリで、最初の `git clone` ができない**: サーバーはまだ GitHub の鍵を持っていません。手元で `git bundle` にして渡して取り出し、`git remote set-url origin git@github.com:…` で取得元を向けてから実行する。入れた後に `sudo ec-sugar deploy-key` で読み取りだけの鍵を登録します。URL に GitHub のパスワード（個人の鍵）を入れて取らない（記録に残ります）
- **同じサーバーのほかの利用者から EC-SUGAR のコンテナへ直に届く**（どの形でも）: Docker のネットワークのコンテナの IP アドレスには、ホストのどの利用者からも届きます。EC-SUGAR は、PHP（php-fpm）を TCP で受けず、nginx とだけ分け合うソケット（`fpm`、メモリの上）で受けます。そのうえでホストのファイアウォール（`ec-sugar-firewall.service`。OUTPUT のチェーン。上の「ホストのファイアウォール」）で、root でない利用者が始めた、この compose のネットワークへの接続を断ります（db・redis・nginx にも届かない。コンテナから始めた接続の返りは通す）。3 回目の見直しで、前は root でない利用者から 9000 番へ FastCGI を直に送り、接続元を偽って管理画面を開き、PHP の設定を変えて好きなファイルを読み込めたことが分かったので直しました
- **umask が 077 のサーバー**（セキュリティを固めたサーバーでよくある）: インストーラーと `ec-sugar` は自分で umask を 022 に決め、取り出したソースの権限もそろえるので、そのまま動きます（2026-10-29、ステージングで umask 077 のまま実行して DB が起きなかったので直した）。インストーラーは実行した人のリポジトリを読むだけで、git の索引も書き換えない

## 動作を確認したサーバー

| サーバー | 使い方 | 日付 | 確認したこと |
|---|---|---|---|
| Ubuntu 24.04（OrbStack の仮のマシン、arm64、Docker 29.9・compose 5.6） | ステージング | 2026-10-29 | まっさらから 1 回で立つ（約 2 分半）・2 回実行しても同じ・https で管理画面とショップ・更新・立たないバージョンから自動で戻る・失敗するマイグレーションが取り消される・手で戻す・バックアップから DB ごと戻す・バックアップの片付け・待ち受けが LAN だけ（DOCKER-USER）・権限とパスワードが画面や記録や ps に出ない |
| Ubuntu 26.04（同） | ステージング | 2026-10-29 | 上と同じ（約 4 分半） |
| Ubuntu 24.04（同） | 本番 | 2026-10-29 | まっさらから 1 回で立つ・HSTS 1 年・メールを捨てる・ufw（SSH は回数で絞る）・夜の自動の更新 |
| Ubuntu 24.04（同、Pebble〔Let's Encrypt の試験用の認証局〕に向けて） | 本番（リバースプロキシの 2 段構成） | 2026-10-29 | `--check` が root でも root でなくても何も変えない・DNS が違う IP アドレスのとき足す行を出して待ち、直ると進む・管理画面の証明書を起動で取り、店の証明書をその場で取る・知らない名前は ask で断られて取らない（握手で断る）・内側の問い合わせの経路は外から 404、同じネットワークの app やホストから 403・偽の X-Forwarded-For・X-Real-IP・Forwarded で管理画面の接続元の制限を越えられない（別の接続元から、許された IP アドレスを装っても 404）・http は https へ・SNI と Host が違えば 421・Server と Via を出さない・Caddy は uid 65532・権限なし・読み取り専用・管理の API なし・途中で止まった後の再実行・設定ファイルを直した再実行で証明書を取り直さない・`ec-sugar update` で Caddy を作り直しても証明書はそのまま・dns（偽の鍵で 5/5 が止まり直し方を出す。鍵は記録・環境変数・ps に出ない。形の悪い鍵は中身を出さずに 4/5 で止まり、取り方も替えない）・manual（渡した証明書を出す）・ステージングで auto は 2/5 で止まる・まっさらから端末で答えて約 3 分（間違えた設定ファイルは聞き直す）・マシンを再起動すると自分で立ち上がり証明書も残る（前は web の type: image の差し込みが古いパスを指して起きなかったので、起動のたびに web を作り直す形に直した） |
| Ubuntu 24.04（同、Pebble。見直しの後） | 本番（前の形から移る） | 2026-10-29 | 前の形（8d87f61、手で渡した証明書）を入れ、新しいバージョンで `install.sh` を実行すると更新でリバースプロキシの 2 段構成に移る（約 1 分半。同じ証明書を Caddy が出し、nginx はポートを手放す）・`ec-sugar rollback` で前の形に戻ると Caddy は片付き nginx が 80・443 番を受ける・また進める・auto に替えると管理画面と店の証明書をその場で取る（管理画面がショップのドメインの下でも）・外の名前（ほかのドメイン・独自ドメイン・ショップのドメインそのもの）は問い合わせずに握手で断る・8081 番は管理画面と `*.ショップのドメイン` 以外を 404、100 回を一度に送ると 21 回だけ通り残りは 429・`/index.php/…` のパスは 404・SSH の接続元がプライベート IP アドレスなら接続元の既定を出さずに理由を出し、表示名も既定なしで聞き直す |
| Ubuntu 22.04（同、arm64） | 本番（すでにある nginx の後ろ、複数の店） | 2026-10-29 | 前の nginx が 80・443 番とほかのサイトを持ったまま、`--check` は止めることを勧めずに「後ろに置く形」を案内・Docker を公式のリポジトリ（jammy）から入れる・EC-SUGAR は 127.0.0.1:8088 だけ・ufw と DOCKER-USER に触らない・ほかのサイトは動いたまま・5/5 で偽の X-Forwarded-For を付けても本当の接続元が届く・前の nginx が X-Forwarded-For をそのまま渡す設定だと 5/5 が見つけて止まる・別の接続元から許された IP アドレスを装っても 404（約 3 分半） |
| Ubuntu 24.04（同） | 本番（1 店舗・Caddy・Pebble） | 2026-10-29 | 端末で答えて約 3 分・管理画面とショップのホストの 2 つを起動のときに取る（その場の取得 0 回）・問い合わせの経路に 1 回も届かない（届いても 404）・ほかの名前は握手で断る・nginx の受ける名前は 2 つちょうど・中央のホストを置かない |
| Ubuntu 24.04（同） | 本番（1 店舗・すでにある nginx の後ろ） | 2026-10-29 | Plesk のサーバーだと見分けて「止めないで」と案内・80・443 番が使われていると端末で後ろに置くかを聞く（既定 proxy）・5/5 の Plesk の手順は「ドメインを 2 つ・ワイルドカード不要」・定期の仕事は `Asia/Tokyo` で書き、サーバーを UTC にしても 03:30・04:15（日本時間）に動く・`ec-sugar status` の次の時刻も日本時間 |
| Ubuntu 24.04（同、2 回目の見直しの後） | 本番（1 店舗・すでにある nginx の後ろ・ソケット） | 2026-10-29 | 前の nginx の働き手のグループ（www-data）を見分けて、ソケットのディレクトリを 2750 で作る・php-fpm が同じグループで動いていると注意を出す・**グループの外の root でない利用者（attacker）はソケットに入れず（Permission denied）、前の形の 127.0.0.1:8088 も、コンテナの IP アドレスの 8080 番にも、許された IP アドレスを装って入れない**・www-data からは接続する（だから PHP を同じグループで動かさない）・`check-front` は X-Forwarded-For をそのまま渡す設定と X-Forwarded-Proto を渡さない設定を見つけて止め、正しい設定では管理画面とショップの両方で ✓・Mac から前の nginx を通して許された IP アドレスを装っても 404・env の新しい項目が無い・バックアップのタイマーが読めない名残から `ec-sugar update` で直る（env を補い、新しく入ったツールが unit を入れ直す）・`status` は読めないタイマーと、env が足りず記録を読めないことを出す |
| Ubuntu 24.04（同、3 回目の見直しの後） | 本番（1 店舗・すでにある nginx の後ろ・ソケット） | 2026-10-29 | **直す前**（b03d51d）: root でない利用者 attacker から、app の 9000 番（edge と内側のネットワークの両方）に FastCGI が届き、REMOTE_ADDR を許された IP アドレスに偽ると管理画面が 200、PHP_ADMIN_VALUE の auto_prepend_file で app のファイルを読み込めた。db 5432・redis 6379 にも届いた。**直した後**: 9000 番で待たない（ソケットだけ）、ファイアウォールで db・redis・nginx の 8080 にも届かない（ファイアウォールを外しても 9000 は閉じたまま）、root からは届く、再起動しても決まりが戻る。ネットワークの作り方の値（gateway_mode）を変える案は、動いているサーバーでネットワークを作り直せず更新が止まったので選ばなかった。`check-front` は X-Forwarded-For を落とす設定と X-Forwarded-Proto をそのまま渡す設定を見つけて止める。働き手でない利用者が所属するグループ・共有のグループ（users）・php-fpm が同じグループで動くサーバーでは止まる。443 番の pid から見分けたグループは www-data（前の `ps -C nginx` はコンテナの nginx を数えて messagebus を選んでいた） |
| Ubuntu 24.04（同） | 本番（複数の店・Caddy・Pebble） | 2026-10-29 | 設定ファイルの `SHOPS=multi` で、管理画面と店をその場で取る・ほかの名前は握手で断る・中央のホスト `api.<ドメイン>` |
| Ubuntu 24.04（同、4 回目の見直しの後。iptables 1.8.10 nf_tables） | ステージング（1 店舗・Caddy・手で用意した証明書） | 2026-10-29 | まっさらから約 3 分。**直す前**: コンテナ（web・queue）からホストの root でない利用者の待ち受けへ接続すると返りが断たれた（届かない）、IPv6 を付けたネットワークでは root でない利用者からコンテナへ IPv6 で届いた（200）、LAN_CIDR が読めないとファイアウォールの当て方が `ec_die` で抜けた、ondemand の php-fpm のプール・Apache の envvars・crontab を見分けなかった。**直した後**: 返りは 200、root でない利用者（attacker）から db 5432・redis 6379・nginx 8080／8081・Caddy 443・Mailpit 8025／1025・app 9000 へは接続できず root からは接続される（app の 9000 は誰からも。TCP で待たない）、IPv6 も断ち決まりを外すと届く、前の形の決まりは `ec-sugar firewall` で片付く、決まりを 1 つ消すと `status` が ✗ と直し方を出し `ec-sugar update`（もう最新です）で戻る、LAN_CIDR が読めなくても `update` は注意を出して続き `firewall` は理由を出して止まる、Mailpit の画面はパスワードなしで 401・正しいパスワードで 200、平文とハッシュのファイルは attacker から読めず `docker inspect` と env に出ない、イメージの php-fpm は既定でソケット（`php-fpm -tt` で `listen = /run/php-fpm/fpm.sock`、`EC_SUGAR_FPM_LISTEN=9000` で 9000）、`docker/check-image.sh` が通る。イメージの ExposedPorts の 9000 は土台の公式イメージから引き継ぐ印で消せない（待ち受けはしない） |
| Ubuntu 26.04（KVM の VM、amd64、4 GB・空きを返す設定、Docker 29.9・compose 5.6） | 本番（1 店舗・Cloudflare Tunnel・DNS の鍵の証明書） | 2026-11-04 | まっさらから約 15 分（イメージを作るのが大半）。メモリが 3 GB だと 1/5 で止まる（4 GB 以上）。3/5 は CNAME の 2 行を出して飛ばす。cloudflared は東京の拠点に 4 本接続し healthy。外から管理画面とショップを https で開ける（Let's Encrypt）。サーバーの中から偽の `CF-Connecting-IP` を付けても接続元は偽れず、外を回ると本当の IP アドレスが届く（5/5）。管理画面の接続元の制限（IPv4 と IPv6 の /64）で許した回線から入れ、パスキーを登録できた。手元のルーターの DNS が足す前の「無い」の応答を覚えていて、30 分ほど開けなかった（ブラウザの安全な DNS で回避）。メールは別のサーバーのメールの箱から 465 番（証明書を確認する）で送り、DKIM の署名つきで届いた |

外部監視（ec-sugar-sentinel）は、python3 の 3.10・3.12・3.13・3.14・3.15 で、確認のスクリプト（`docker/check-sentinel.sh`）が同じ結果になることを確認しました（2026-11-24。CI では 3.14 と 3.10 の 2 つで流します）。3.10 は Ubuntu 22.04 の python3 です（古い LTS のサーバーにも置けるように）。

OrbStack の仮のマシンは、ホストとカーネルを分け合うコンテナ型で、SSH も無く、クラウドのファイアウォールもありません。本物の SSH の接続元を締め出さないか、本物の VM の再起動の後に立ち上がるか、メモリ 4 GB でイメージを作れるか、公開の DNS と本物の Let's Encrypt・Cloudflare での動きは、本物の VM（KVM の VM・DigitalOcean）で確認します。

## AI エージェントから接続する

店の人が、自分の AI エージェント（Claude Desktop・ターミナルの `claude` コマンドなど、MCP を話すツール）から店の商品・在庫・注文・売上を読み、書ける鍵なら商品・在庫・発送・取消・返金・クーポンを変えられるようにするリバースプロキシの使い方。設計と決めた理由は [ADR 0100](adr/0100-店の人は自分のAIエージェントからMCPで店を読める.md)・[ADR 0101](adr/0101-AIエージェントは書く操作もでき、店はすぐ反映か承認かを選ぶ.md)、防御は [脅威モデルの「AI エージェントの鍵」「AI エージェントの書き込み」](security/threat-model.md)。

鍵は**読むだけ**か**書ける**のどちらか。書ける鍵の変更は、店の設定で「承認してから」（既定。案を作るだけで、管理画面の「AI エージェントの案」で承認すると反映）か「すぐ反映」を選ぶ。注文の取消・返金・クーポンは、店の設定にかかわらず承認が要る。

### 店の人がすること（AI エージェント）

1. 管理画面の「設定」›「AI エージェント」を開く（どのメンバーにも出る。説明はヘルプの「AI エージェントから接続する」）
2. 「鍵を作る」に名前（例「Claude Desktop」。1〜40 文字）・店（見られる店が 2 つ以上のときだけ選ぶ）・種類（読むだけ／書ける。**書ける鍵はその店で「運用」か「会計」の権限の人だけ**）・期限（読むだけは 30・90・180・365 日で既定 90、書けるは 7・30・90 日で既定 30）を入れて「鍵を作る」。パスキーでもう一度本人確認をする
3. 画面の上に出た鍵（`ecs_agent_` で始まる）を「鍵をコピー」でコピーし、AI エージェントの設定に貼る。**鍵はこの時だけ表示**し、閉じると二度と見られない（サーバーにはハッシュだけが残る）
4. AI エージェントの設定に、MCP サーバーとして URL と鍵を入れる（下の「設定の例」。画面の「接続方法」にも、その管理画面の URL を入れた例が出る）

一覧には、名前と店と種類・鍵の頭の 8 文字（`ecs_agent_` の後ろ）・最後に使った日時・期限・状態（使える・期限切れ・取り消し済み）が出る。オーナーには、ほかのメンバーの鍵も持ち主の名前つきで出る。使わなくなった鍵・漏れたかもしれない鍵は「取り消す」。次の呼び出しから使えない。

### claude.ai・ChatGPT などの「コネクタを追加」から接続する（OAuth。鍵は要らない）

claude.ai・ChatGPT など、MCP の OAuth 2.1 に対応した AI エージェントは、鍵を作らずに「コネクタを追加」から接続する（ADR 0101 の作って決めたこと）。

1. AI エージェントの「コネクタを追加」（名前はツールごとに違う。「カスタムコネクタ」など）に、MCP の URL（管理画面のホストの `/mcp`。例 `https://admin.example.jp/mcp`）を入れる。クライアント ID と秘密の欄は空のまま（自動で登録する）
2. ブラウザで EC-SUGAR の許可の画面（「AI エージェントの接続」）が開く。ログインしていなければパスキーでログインすると、その画面に戻る。**許可の画面は管理画面と同じく接続元の制限の中**なので、管理画面を開けるネットワークから開く
3. **今自分で「コネクタを追加」を押していなければ「拒否する」**（人から送られてきたリンクで許可しない）。大きく出る「ここへ鍵を渡します」の行き先（登録できる名前。下に戻り先の URL の全部）が、今使っている AI エージェントのサービス（例 `claude.ai`・`chatgpt.com`）か確認する。アプリの名前は、接続する側が自分で申告したもので確認していない（登録された日時も出る。ふつうは「いま登録されたばかり」）
4. 「接続する店」で、見られる店の中から AI エージェントに見せる店にチェックを付ける（いくつでも。はじめは何も選んでいない。後から店を足すときは接続し直す。ADR 0104）。「読むだけ／書ける」（はじめはいつも読むだけ。**書けるは選んだ店の全部で「運用」か「会計」の権限の人だけ**）を選び、「許可する」。パスキーでもう一度本人確認をすると、AI エージェントの画面へ戻って接続される

店を 2 つ以上選んだ接続では、AI エージェントはツール `list_shops` で店を見て、ほかのツールの `shop` で呼び出しごとに 1 店を選ぶ（1 回の呼び出しで読む・変えるのは 1 店だけ。設定ファイルにはどの店のデータかが付く）。2 店以上を選んだ「書ける」接続の変更は、店の設定が「すぐ反映」でも、いつも「AI エージェントの案」で承認してから反映する。

接続したものは「設定」›「AI エージェント」の「コネクタで接続した AI エージェント」に、申告した名前・行き先のホスト・選んだ店・種類と並ぶ（オーナーにはほかのメンバーの分も）。「取り消す」で、その AI エージェントは次の呼び出しから店を読めない。鍵は 1 時間ごとに入れ替わり、30 日使わないと切れる。使い続けても、書ける接続は 90 日・読むだけは 365 日で切れる（また使うときはコネクタから接続し直す）。同じ AI エージェントで接続し直すと前の接続は切れ、1 人の 1 つの店で接続するのは（その店を含む接続で）5 つまで（古いものから切れる）。管理画面の鍵と同じく、ほかの端末を全部ログアウト・パスキーの削除・回復で戻る・メンバーを止める・選んだ店のどれかで権限を下げる／外すと、接続ごと切れる。できることは、許可した人のそれぞれの店での今の権限の中だけ。

サーバーの側で足す設定は無い。次のエンドポイントだけが**管理画面の接続元の制限（`ADMIN_ALLOWED_IPS`）の外**で、AI エージェントのサービスのサーバーから届く（防御は鍵と OAuth と回数の上限）:

| エンドポイント | 中身 |
|---|---|
| `POST /mcp` | MCP のエンドポイント（鍵が要る。鍵の無い 401 は下のメタデータの場所を教える） |
| `GET /.well-known/oauth-protected-resource`（と後ろに `/mcp`） | 保護された資源のメタデータ（RFC 9728） |
| `GET /.well-known/oauth-authorization-server` | 認可サーバーのメタデータ（RFC 8414） |
| `POST /oauth/register` | 動的なクライアント登録（RFC 7591。公開のクライアントだけ・行き先は https か手元の loopback だけ・接続元〔IPv6 は /64〕ごとに 1 時間 20 件・/48 ごとに 100 件・全体で 1 日 200 件〔使われない登録は 1 時間で消して枠を空ける〕） |
| `POST /oauth/token` | 鍵の受け渡し（認可コード ＋ PKCE S256、リフレッシュトークンのローテーション） |
| `POST /oauth/revoke` | 鍵の取り消し（RFC 7009） |

許可の画面（`GET /oauth/authorize` と画面・ログイン）は制限の中のまま。本番で `ADMIN_ALLOWED_IPS` が空だと許可の画面が閉じたままなので、接続しない（マシンのエンドポイントだけが開く）。管理画面のホストの前に、別の防御（Cloudflare の WAF の決まりや Access で管理画面のホストを丸ごと絞るなど）を置いているときは、上の 6 つのパスを通す決まりを足す（足さないと、AI エージェントのサービスから登録・鍵の受け渡し・`/mcp` に届かない）。`/.well-known/` は nginx が静的のファイルだけを返す場所なので、自分で前に置いた Web サーバーで `/.well-known/` を別の所へ向けているときは、`/.well-known/oauth-` で始まるパスを EC-SUGAR へ通す。使い終わった依頼・リフレッシュトークン・期限の切れた鍵・使われない登録は、1 時間ごとの `agent:prune-oauth` が消す。`/mcp` と鍵の受け渡しで断った回は、接続元（IPv6 は /64 と /48）ごとに数えて上限で 429 にし、正しい鍵は同じ接続元からでも止めない。

### 店の設定（AI エージェント）

同じ画面の「この店の設定」で、店ごとに 2 つを選ぶ（読むのは閲覧、変えるのは運用の権限。変えたことは店の記録と中央の監査ログに残る）:

- **AI エージェントの書き込み**: 「承認してから」（既定）は、書くツールが案を作るだけ。「すぐ反映」は、商品・在庫・発送の変更をその場で反映する。取消・返金・クーポンはどちらでも案になる（今の値段の半分より下げる変更も案になる）
- **お客さんの情報を渡す**: 既定は「渡さない」。「渡す」にすると、お客さんのツール（`search_customers`・`get_customer`）が使え、`list_orders`・`get_order` に注文者の名前とメールが入る（どちらも鍵の持ち主がその店で**運用以上**のときだけ。見たことは中央の監査ログに、どのお客さんか・どの鍵かと一緒に残る）。ショップの「外部送信について」（`/legal/external`）に AI エージェントの行が出る。渡す店では、商品を作る・直す・発送済みにする（外の人に届く値を書くツール）も「すぐ反映」でも承認が要る。**渡し始めるのは「会計」の権限の人かオーナーだけ**で、どちらの設定も変えるのはパスキーの再確認の後

### 承認の一覧（AI エージェントの案）

管理画面のメニューの「AI エージェントの案」（運用以上の人に出る）。ホームの「やること」にも承認待ちの数が出る。

1. 一覧から案を開き、要約（「「クッキー缶」の値段を ¥2,000 → ¥2,200」のように元の値と新しい値）と引数・作った人と鍵を確認する
2. 「承認して反映する」か「却下する」を押す。**取消・返金の案の承認は「会計」の権限の人だけで、パスキーの再確認も要る**（管理画面の取消・返金と同じ）
3. 押した時に、案を作った時から店が変わっていないか（商品の版・在庫の数・注文の状態・返せる残りの額）を確認し直す。変わっていれば反映せずに「反映できなかった」になる。AI エージェントにもう一度依頼する

取り消した鍵の案・作った人の権限が足りなくなった案は、承認すると反映せずに却下になる。返金・取消で Stripe の応答が分からないときは「承認して反映した（確認中）」になり、返金は自動で確認し直す（注文の画面で確認する）。待ちの案は鍵ごとに 50 件・店ごとに 200 件まで、同じ中身の待ちの案は 1 つだけ。

案は 7 日で期限切れ（1 時間ごとの `agent:expire-proposals`。期限を過ぎた案はその前でも承認できない）。AI エージェントは `get_proposal` で結果を見られる（自分の案だけ）。

### AI エージェントの設定の例

MCP のエンドポイントは**管理画面のホストの `/mcp`**（例 `https://admin.example.jp/mcp`。ショップのホストには無い）。Streamable HTTP の MCP サーバーで、鍵は `Authorization: Bearer <鍵>` の見出しで送る（Cookie・URL の中の鍵は受けない）。

鍵はコマンドの引数に直に書かない（シェルの履歴とプロセスの一覧に残る）。環境変数か、設定のファイルの `env` の欄で渡す。下の例の環境変数の名前は `EC_SUGAR_AGENT_KEY`（好きな名前でよい）。

ターミナルの `claude` コマンド（1 行目を実行してから鍵を貼り、Enter。鍵は画面にも履歴にも残らない）:

```sh
read -rs EC_SUGAR_AGENT_KEY && export EC_SUGAR_AGENT_KEY
claude mcp add --transport http ec-sugar https://admin.example.jp/mcp --header "Authorization: Bearer $EC_SUGAR_AGENT_KEY"
```

HTTP の MCP サーバーを設定のファイルに書けるツール（プロジェクトの `.mcp.json` など）。鍵はファイルに書かず、`${…}` で環境変数から読む（`.mcp.json` はツールを起動した時の環境変数に置き換える。ファイルをリポジトリに入れても鍵は載らない）:

```json
{
  "mcpServers": {
    "ec-sugar": {
      "type": "http",
      "url": "https://admin.example.jp/mcp",
      "headers": {
        "Authorization": "Bearer ${EC_SUGAR_AGENT_KEY}"
      }
    }
  }
}
```

手元の MCP サーバー（コマンド）だけを書けるアプリ（Claude Desktop の `claude_desktop_config.json` など）は、HTTP の MCP サーバーへ中継する `mcp-remote` を通す（使う人の端末に Node.js が要る。サーバーには要らない）。`mcp-remote` はバージョンを固定する（出て 7 日より後のもの。ADR 0037 の基準。管理画面の例も同じバージョン）。鍵は `env` の欄に置き、引数には `${…}` の名前だけを書く（`mcp-remote` が中で置き換えるので、プロセスの一覧に鍵が出ない。`:` の前後に空白を入れない）:

```json
{
  "mcpServers": {
    "ec-sugar": {
      "command": "npx",
      "args": ["-y", "mcp-remote@0.16.0", "https://admin.example.jp/mcp", "--header", "Authorization:${EC_SUGAR_AGENT_KEY_HEADER}"],
      "env": {
        "EC_SUGAR_AGENT_KEY_HEADER": "Bearer ここに鍵"
      }
    }
  }
}
```

設定のファイル（`claude_desktop_config.json`・`~/.claude.json` など）には鍵が残るので、ほかの人と分け合わない・リポジトリに入れない。

ツールごとに書く場所と形は少し違う。それぞれのツールの説明も見る。

### AI エージェントのツール

| ツール | 中身 | 要る権限 |
|---|---|---|
| `search_products` | 商品を名前で探す（公開の状態・値段・在庫の数・種類の数。50 件まで） | 閲覧 |
| `get_product` | 1 つの商品（説明の文・種類・在庫・税区分） | 閲覧 |
| `low_stock` | 在庫が少ない種類（しきい値は引数。既定 3） | 閲覧 |
| `list_orders` | 期間と状態で注文の一覧（100 件まで） | 閲覧 |
| `get_order` | 1 つの注文（明細・合計・税の内訳・支払いと配送の状態） | 閲覧 |
| `sales_summary` | 日ごと・月ごとの売上（テストの支払いは除く） | お金 |
| `search_customers` | お客さんを名前かメールの**完全一致**で探す（暗号化しているので部分一致は無い）。お客さんの情報を渡す店だけ | 運用 |
| `get_customer` | 1 人のお客さん（メール・名前・電話・住所・届け先・注文の履歴）。お客さんの情報を渡す店だけ | 運用 |
| `create_product` | 商品を下書きで作る（名前・値段・説明・税区分）。書ける鍵 | 運用（店の設定） |
| `update_product` | 名前・値段・説明・税区分・公開と非公開（`version` を渡すと、その後に直された商品は断る）。書ける鍵 | 運用（店の設定） |
| `adjust_stock` | 在庫を入れる（`receive`）・数え直す（`count`。理由つき）。注文が押さえた数より少なくはできない。書ける鍵 | 運用（店の設定） |
| `mark_shipped` | 発送済みにする（運送の会社と送り状の番号。お客さんに発送のメール）。書ける鍵 | 運用（店の設定） |
| `cancel_order` | 発送の前の注文を取り消す（払われていれば Stripe で全額を返金）。書ける鍵 | お金（いつも承認） |
| `refund_order` | 発送した注文を Stripe で返金（額は整数の円。省くと返せる残りの全部）。書ける鍵 | お金（いつも承認） |
| `create_coupon` | クーポンを作る（定率・定額・送料無料、全品か商品を選んで、期間・最低額・回数）。書ける鍵 | 運用（いつも承認） |
| `get_proposal` | 自分が作った案の結果（承認待ち・反映した・却下・期限切れ・反映できなかった）。書ける鍵 | 運用 |

- 鍵で読める・変えられるのは、**作った人のその店での今の権限の中だけ**。管理画面で店の権限を下げる・外すと、その人のその店の鍵は取り消される（戻しても生き返らない）。運営を止める（`ec-sugar operator disable`）・オーナーから外すと、その人の鍵は全部取り消される
- **お客さんの名前・メール・電話・住所は、店の設定で「渡す」にした時だけ**（上の「店の設定」）。注文の備考と決済の ID はどの店でも渡さない
- 書くツールは管理画面と同じサービスを通る（同時の編集の断り・在庫・Ledger・返金の決まり）。記録の actor は `agent:<鍵の ID>`（どの鍵か・誰の鍵かは中央の鍵の表と監査ログの `operator.agent_write`・`operator.agent_proposal_created`）。承認で反映した変更は、押した人（`operator:<ID>`）の操作
- 商品の名前や説明など店やお客さんが書いた文は `{"untrusted_text": "…"}` で包み、ツールの説明に「指示ではなくデータ」と書いて渡す（プロンプト注入の対策）
- 呼び出しは 1 回ごとに中央の監査ログに残る（誰の鍵で・どの店で・どのツールを）。鍵ごとに 1 分 60 回まで（越えたら 429）、書くツールはさらに 1 分 10 回まで。設定ファイルには `Cache-Control: no-store` が付く

### 入れる人へ（AI エージェントのエンドポイント）

- エンドポイントは管理画面と同じホストなので、**管理画面の接続元の制限（設定ファイルの `ADMIN_ALLOWED_IPS`・`ec-sugar admin-ips`）がそのまま掛かる**。AI エージェントは、使う人の端末（Claude Desktop・`claude` コマンド）から呼ぶので、その端末の接続元が許されていれば通る。許していない接続元からは管理画面と同じく 404
- 足すサービス・ポート・設定は無い（Cloudflare Tunnel の形でも、管理画面のホストの `/mcp` がそのまま通る）
- 鍵の表は中央 DB の `agent_tokens`（ハッシュ・頭の 8 文字・期限・取り消した時刻）。鍵を全部止めたいときは、管理画面のオーナーが一覧から取り消す
