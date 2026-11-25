import { runFixture } from './support/fixtures.js';

// 終わった後に、場面が作ったテナントを DB とロールごと消す（残すと 1 回の通しで 70 ほど溜まり、DB を使い回す CI の走らせ役では回をまたいで増える）。
// 落ちた場面を調べるときは trace（test-results/）を見る。DB を残したいときは E2E_KEEP_TENANTS=1
export default function globalTeardown() {
    if (process.env.E2E_KEEP_TENANTS) {
        return;
    }

    runFixture('cleanup');
}
