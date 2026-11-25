// 贈り物の受け取りのリンクを「写す」（ADR 0055）。核の部品 <x-storefront::gift-share> の [data-gift-copy] があるときだけ動く。
// JavaScript が無いときはボタンを隠したまま（欄の文字を選んで写せる）。写せないブラウザでは欄の文字を選んだ状態にする
export function wireGiftCopy(root = document) {
    for (const share of root.querySelectorAll('[data-gift-share]')) {
        const button = share.querySelector('[data-gift-copy]');
        const link = share.querySelector('[data-gift-link]');
        const status = share.querySelector('[data-gift-copied]');

        if (!button || !link) {
            continue;
        }

        button.hidden = false;
        button.addEventListener('click', async () => {
            link.select();

            try {
                await navigator.clipboard.writeText(link.value);
                status && (status.textContent = 'リンクをコピーしました。メールや LINE に貼り付けて送ってください。');
            } catch {
                status && (status.textContent = 'リンクを選択しました。端末の「コピー」でコピーしてください。');
            }
        });
    }
}

wireGiftCopy();
