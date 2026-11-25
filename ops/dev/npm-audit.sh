#!/usr/bin/env bash
# npm の依存の脆弱性を確かめる（make audit と CI から）。
#
# ふだんは `npm audit --audit-level=low` と同じで、1 件でもあれば落ちる。ただし
# **直ったバージョンがまだ無い** advisory だけは、理由を書いて `security/npm-audit-accepted.json` に載せれば通す。
# 載せた advisory も、直った版が出た時点で落ちる（npm の答えの fixAvailable が本物のバージョンになったら許さない）。
# 検査を緩めるのではなく、「直せない間だけ・直せるようになったら必ず気づく」形にしている（ADR 0015 と conventions.md の 8）。
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
accepted="${root}/security/npm-audit-accepted.json"
report="$(mktemp)"
trap 'rm -f "${report}"' EXIT

# npm audit は見つかると終了の値が 1 になるので、答えだけ受け取って自分で判断する
npm audit --json > "${report}" 2>/dev/null || true

node --input-type=commonjs -e '
const fs = require("fs");
const [reportPath, acceptedPath] = process.argv.slice(1);
const report = JSON.parse(fs.readFileSync(reportPath, "utf8"));
const accepted = fs.existsSync(acceptedPath) ? JSON.parse(fs.readFileSync(acceptedPath, "utf8")) : { accepted: [] };
const allowed = new Map((accepted.accepted ?? []).map((entry) => [entry.id, entry]));

const problems = [];
const stale = new Set(allowed.keys());

for (const [name, vuln] of Object.entries(report.vulnerabilities ?? {})) {
  for (const via of vuln.via ?? []) {
    if (typeof via === "string" || !via.url) {
      continue;
    }

    const id = via.url.split("/").pop();
    const entry = allowed.get(id);
    stale.delete(id);

    if (!entry) {
      problems.push(`${name} ${via.range}: ${via.title} (${via.severity}) ${via.url}`);
      continue;
    }

    // そのまま上げるだけで直るなら（fixAvailable が true）、許さずに上げる。
    // パッケージとバージョンを挙げてくる形（isSemVerMajor。ほかのものを大きく動かす・鎖が消えないこともある）は
    // 機械では決められないので、見直しの日（review_by）で必ず戻ってくる形にしている
    if (vuln.fixAvailable === true) {
      problems.push(`${name}: 許していた ${id} は、そのまま上げれば直ります（npm audit fix）。security/npm-audit-accepted.json から外してください`);
      continue;
    }

    if (typeof entry.review_by !== "string" || !/^\d{4}-\d{2}-\d{2}$/.test(entry.review_by)) {
      problems.push(`security/npm-audit-accepted.json の ${id} に見直しの日（review_by、YYYY-MM-DD）がありません`);
      continue;
    }

    if (new Date().toISOString().slice(0, 10) > entry.review_by) {
      problems.push(`security/npm-audit-accepted.json の ${id} は見直しの日（${entry.review_by}）を過ぎました。直ったバージョンが出ていないかを確認して、日を延ばすか行を消してください`);
    }
  }
}

for (const id of stale) {
  problems.push(`security/npm-audit-accepted.json の ${id} は、もう npm audit に出ません。行を消してください`);
}

if (problems.length > 0) {
  console.error("npm の依存の脆弱性:");
  for (const problem of [...new Set(problems)]) {
    console.error(`  - ${problem}`);
  }
  process.exit(1);
}

const count = allowed.size;
console.log(count === 0 ? "npm audit: 見つかりませんでした" : `npm audit: 直ったバージョンがまだ無い ${count} 件だけを、理由つきで通しました（security/npm-audit-accepted.json）`);
' "${report}" "${accepted}"
