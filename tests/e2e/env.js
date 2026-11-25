// ブラウザのテストで立てる PHP のサーバーと、下ごしらえ（fixtures.php）に渡す環境。
// DB は ec_e2e（開発用の DB とテスト用の ec_test には触らない）。値は開発用の Postgres（make up）が既定で、CI は E2E_* で上書きする。
//
// 作業ごとに分けるとき（並べて作業するときに、自分の場面だけを走らせる）:
//   E2E_DB=ec_e2e_s E2E_PORT=8191 E2E_STRIPE_PORT=8192 npx playwright test -g "場面の名前"
// 店の DB の頭（ec_e2e_s_t_）と写真の置き場（media-e2e-s）は E2E_DB から決まる（組でないとテストの入口の守りが止める。
// tests/Support/TestEnvironmentGuard）。DB は先に作っておく（本体では make e2e-db E2E_DB=ec_e2e_s。作業場所の写し〔worktree〕では
// docker compose が別のプロジェクトになるので docker exec で作る。docs/development/team.md）
export const database = process.env.E2E_DB || 'ec_e2e';

// 守り（TestEnvironmentGuard）と同じ形だけを受ける。サーバーを立てる前に、分かる言葉で止める
if (!/^ec_e2e(?:_(?!t$)[a-z0-9]+)?$/.test(database)) {
    throw new Error(`E2E_DB（${database}）は ec_e2e か ec_e2e_<英小文字と数字>（t は除く）にしてください（例: E2E_DB=ec_e2e_s）`);
}

export const tenantPrefix = `${database}_t_`;
// ec_e2e → media-e2e、ec_e2e_s → media-e2e-s
export const mediaDirectory = `media-e2e${database.slice('ec_e2e'.length).replace('_', '-')}`;

export const port = Number(process.env.E2E_PORT ?? 8091);
export const adminUrl = `http://admin.localhost:${port}`;
// 偽の Stripe（tests/e2e/fake-stripe.php）
export const stripePort = Number(process.env.E2E_STRIPE_PORT ?? 8092);
export const stripeUrl = `http://127.0.0.1:${stripePort}`;
// 偽の Stripe が通知に署名する鍵（tests/e2e/fixtures.php の --stripe と同じ。鍵の形はその場で組み立てる）
export const webhookSecret = ['whsec', 'FakeForE2eOnly00001234'].join('_');

export const serverEnv = {
    APP_ENV: 'local',
    APP_DEBUG: 'false',
    APP_URL: `http://localhost:${port}`,
    ...(process.env.E2E_APP_KEY ? { APP_KEY: process.env.E2E_APP_KEY } : {}),
    DB_CONNECTION: 'pgsql',
    DB_URL: '',
    DB_HOST: process.env.E2E_DB_HOST ?? '127.0.0.1',
    DB_PORT: process.env.E2E_DB_PORT ?? '54329',
    DB_DATABASE: database,
    DB_USERNAME: 'ec_app',
    DB_PASSWORD: process.env.E2E_DB_PASSWORD ?? 'ec_dev_only',
    TENANT_DB_PREFIX: tenantPrefix,
    // 写真の置き場も開発（storage/app/media）と分ける。下ごしらえの reset は全部の店を消すので、同じ ID の開発の店の写真を消さない
    MEDIA_DIRECTORY: mediaDirectory,
    // テナントに入るたびに stancl/tenancy が Redis の接頭辞を切り替えるので、本番と同じく Redis が要る
    REDIS_HOST: process.env.E2E_REDIS_HOST ?? '127.0.0.1',
    REDIS_PORT: process.env.E2E_REDIS_PORT ?? '63799',
    CENTRAL_DOMAINS: 'localhost,127.0.0.1',
    ADMIN_DOMAIN: 'admin.localhost',
    ADMIN_URL: adminUrl,
    ADMIN_ALLOWED_IPS: '',
    // 認証の回数制限（1 分 10 回）はテストの間に溜まるので、キャッシュはリクエストごとに消える array にする（429 は PHP のテストで確かめている）
    CACHE_STORE: 'array',
    SESSION_DRIVER: 'file',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'array',
    LOG_CHANNEL: 'stderr',
    // 支払いは偽の Stripe へ（本番では使えない設定。店頭の CSP の form-action にも足す）
    STRIPE_API_BASE: stripeUrl,
    STOREFRONT_TEST_FORM_ACTION: stripeUrl,
    // 区画を並べられる見本のテーマ（tests/Support/Themes/sample）を登録する。ページの編集の画面の足す・並べ替えを確かめる（ADR 0039。本番では使えない設定）
    STOREFRONT_SAMPLE_THEME: 'true',
    // 手元の .env に「Stripe へ接続」を試す鍵があっても、テストは店が鍵を貼る形で（Connect を試す場面は自分で入れる）
    STRIPE_PLATFORM_SECRET_KEY: '',
    STRIPE_CONNECT_WEBHOOK_SECRET: '',
};
