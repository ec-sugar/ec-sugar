# EC-SUGAR の入口（Caddy）のイメージ（ADR 0031）
#
# 公式の Caddy に、Cloudflare の DNS の部品（caddy-dns/cloudflare。外から届かないステージングで、DNS で確かめてワイルドカードを取るため）を足す。
# バージョンはここで固定する（Caddy・部品・土台のイメージは digest まで）。上げるときは 3 つをそろえて変え、
# docker-compose.prod.yml の caddy の image の札（ec-sugar-caddy:<Caddy のバージョン>-cf<部品のバージョン>-r<改訂>）も同じバージョンに変える（docker/check-compose.sh が見張る）。
# バージョンを変えずにこのファイルを直したときは IMAGE_REVISION を 1 つ上げる（サーバーは札が同じイメージを作り直さないので、上げないと古いまま動く）
#
# 中で動く利用者は 65532（root でない。ホストのふつうの利用者の番号と重ならない所）。権限は持たない（compose で cap_drop ALL・no-new-privileges）。
# 80・443 番は、コンテナの網の中だけの決まり（net.ipv4.ip_unprivileged_port_start）で root でなくても受ける
ARG CADDY_VERSION=2.11.7
ARG CLOUDFLARE_VERSION=0.2.4
ARG IMAGE_REVISION=2

# 作る段: 公式の作る道具（xcaddy）で、決めたバージョンの Caddy に部品を足して作る。Go の部品は sum.golang.org の控えで確かめられる
FROM caddy:2.11.7-builder-alpine@sha256:ef9b65adf9a224770c6d5f6d1ae1bf99bc3caaa78d8c4bae3ea949ee6984f301 AS build
ARG CADDY_VERSION
ARG CLOUDFLARE_VERSION
# Go の部品の取り込みを固く: go.mod を勝手に書き換えさせない（-mod=readonly）。部品の控え（sum.golang.org）での確かめを切っていないこと、
# 確かめを飛ばす名前（GONOSUMDB・GONOSUMCHECK・GOINSECURE・GOPRIVATE）が無いこと、取り先が公式の置き場であることを、作る前に確かめる
ENV GOFLAGS=-mod=readonly
RUN test "$(go env GOSUMDB)" != off \
    && test -z "$(go env GONOSUMDB)$(go env GONOSUMCHECK)$(go env GOINSECURE)$(go env GOPRIVATE)" \
    && go env GOPROXY | grep -q '^https://proxy.golang.org'
RUN xcaddy build "v${CADDY_VERSION}" --with "github.com/caddy-dns/cloudflare@v${CLOUDFLARE_VERSION}" --output /usr/bin/caddy \
    && /usr/bin/caddy list-modules --packages | grep -q 'dns.providers.cloudflare'

# 動かす段: 公式の Caddy のイメージ（同じバージョン）の実行ファイルを、作ったものに差し替える
FROM caddy:2.11.7-alpine@sha256:eb4d35b7452d1ab6171fbdbe8d9275b2301dad6dad92115d91e19fb9b24d510f
COPY --from=build /usr/bin/caddy /usr/bin/caddy
# 実行ファイルに付いた「1024 番より下を開ける」権限（file capabilities）を外す。cap_drop ALL・no-new-privileges の下では、
# この印があるだけで実行そのものを断られる（operation not permitted。2026-10-29 に使い捨ての Ubuntu で起きた）。
# 80・443 番は compose の sysctls（net.ipv4.ip_unprivileged_port_start）で開ける
RUN setcap -r /usr/bin/caddy \
    && ! getcap /usr/bin/caddy | grep -q cap_
# 証明書と ACME のアカウントの鍵の置き場（/data）と、Caddy の作業の置き場（/config）を、動かす利用者の持ち物にする。
# compose の名前つきの置き場は、初めて差し込むときにこの持ち主ごと写される
RUN mkdir -p /data/caddy /config/caddy \
    && chown -R 65532:65532 /data /config \
    && chmod 0700 /data /data/caddy
USER 65532:65532
# 設定（Caddyfile）はイメージに入れず、compose が取り出したバージョンの docker/caddy/etc/ を /etc/caddy に読み取り専用で差し込む
# （nginx の設定と同じ。設定を直してもイメージを作り直さずに済む）。管理の API（2019 番）は Caddyfile で切ってある
CMD ["caddy", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
