import { runFixture } from './support/fixtures.js';

// ec_e2e（E2E_DB で分けたときはその DB。env.js）を 1 回だけ作り直す。運営とテナントは場面ごとに support/fixtures.js が作る
export default function globalSetup() {
    runFixture('reset');
}
