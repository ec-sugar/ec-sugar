// 商品の 1 枚のフォームの保存。1 つの「保存する」で、変えた所の API を決まった順に 1 つずつ呼ぶ。
//
// 決まり:
// - 手順（step）は { label, section, run(listing) } の形。run は今の商品（直前の手順が返したもの）を受け取り、保存した後の商品を返す。
//   バージョン（version）は毎回その商品から取るので、手順の間で同時の編集の衝突（409）の見張りが途切れない
// - 手順は前から順に呼び、1 つが失敗したらそこで止める（後ろの手順は送らない）。済んだ手順は取り消さない（API ごとに保存は確定している）。
//   画面は「保存できたもの・保存できなかったもの（理由）・まだ保存していないもの」を通知、もう一度押すと変えた所の残りだけを送る
// - 1 つの手順が済むたびに onSaved(商品, 手順) で画面へ返す（済んだ所の「保存していない変更」の印を消し、次の手順のバージョンをそろえる）。
//   手順の途中まで済んで失敗したときは onSaved(商品, null)

export async function runSteps(steps, listing, onSaved) {
    const done = [];
    let current = listing;

    for (const [index, step] of steps.entries()) {
        try {
            current = (await step.run(current)) ?? current;
            done.push(step);
            onSaved?.(current, step);
        } catch (error) {
            // 手順の中で一部だけ済んだとき（種類の行を 1 つずつ送る）は、済んだ所までの商品を error.partial に載せる
            if (error?.partial) {
                current = error.partial;
                onSaved?.(current, null);
            }

            return { listing: current, done, failed: { step, error }, pending: steps.slice(index + 1) };
        }
    }

    return { listing: current, done, failed: null, pending: [] };
}

// 手順の名前を「・」でつなぐ（通知の文に）
export function labelsOf(steps) {
    return steps.map((step) => step.label).join('・');
}
