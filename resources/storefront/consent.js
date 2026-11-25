// 同意の帯（ADR 0034 の 4）。テーマではなく核が持つ JavaScript（ADR 0020）。
// 同意を決めるのはフォーム（POST、CSRF つき）で、この部品が無くても動く。ここでするのは、フッターの「Cookie の設定」
// （[data-sugar-consent-open]）を押したとき、帯のある画面ではページを移らずに帯を開き直すことだけ。
// 同意の Cookie は HttpOnly で、ここからは読まない・書かない（外のタグにも書き換えさせない）。HTML の文字列は差し込まない。
import './apps.css';

document.addEventListener('click', (event) => {
    const opener = event.target instanceof Element ? event.target.closest('[data-sugar-consent-open]') : null;
    const banner = document.querySelector('[data-sugar-consent]');

    if (!opener || !banner) {
        return;
    }

    event.preventDefault();
    banner.hidden = false;
    banner.querySelector('button')?.focus();
});
