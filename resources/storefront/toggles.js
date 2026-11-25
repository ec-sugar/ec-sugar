// App の「押す・戻す」のボタン（ADR 0071。App「お気に入り」のハートなど）。核の部品 toggle-button の form[data-sugar-toggle] があるときだけ動く。
// JavaScript が無いときはフォームのまま送り、App が元の画面へ戻す。ここでは画面を移らずに送り、答え（{pressed, label}）で形と文字を替える。
// 同じ行き先のボタン（商品の画面のボタンと、下の並びの札のハートなど）は全部そろえる。
// ログインが要るとき（401 と login）は、答えの道筋（自分のオリジンだけ）へ移る。ほかの失敗はフォームをそのまま送り直す（JS の無い時と同じ道）

const JSON_HEADERS = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

// 自分のオリジンの道筋だけを許す（// で始まる別ホストや javascript: を入れない）
function safePath(value) {
    return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') && !value.startsWith('/\\') ? value : null;
}

function apply(path, pressed, label) {
    for (const form of document.querySelectorAll('form[data-sugar-toggle]')) {
        if (form.dataset.sugarToggle !== path) {
            continue;
        }

        form.querySelector('button')?.setAttribute('aria-pressed', pressed ? 'true' : 'false');
        const next = form.querySelector('[data-sugar-toggle-next]');

        if (next) {
            next.value = pressed ? '0' : '1';
        }

        const text = form.querySelector('[data-sugar-toggle-label]');

        if (text && typeof label === 'string' && label !== '') {
            text.textContent = label;
        }
    }
}

async function send(form) {
    const button = form.querySelector('button');
    button?.setAttribute('aria-busy', 'true');

    try {
        const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: JSON_HEADERS, credentials: 'same-origin', redirect: 'error' });
        const data = await response.json().catch(() => null);

        if (response.status === 401 && safePath(data?.login)) {
            window.location.assign(safePath(data.login));

            return;
        }

        if (!response.ok || typeof data?.pressed !== 'boolean') {
            throw new Error('toggle');
        }

        apply(form.dataset.sugarToggle, data.pressed, data.label);
    } catch {
        // 送れなかった・答えが読めない: フォームのまま送り直す（画面を移って、App の答えを出す）
        form.submit();
    } finally {
        button?.removeAttribute('aria-busy');
    }
}

export function wireToggles(root = document) {
    for (const form of root.querySelectorAll('form[data-sugar-toggle]')) {
        if (form.dataset.sugarToggleWired) {
            continue;
        }

        form.dataset.sugarToggleWired = '1';
        form.addEventListener('submit', (event) => {
            event.preventDefault();

            if (form.querySelector('button')?.getAttribute('aria-busy') !== 'true') {
                send(form);
            }
        });
    }
}

wireToggles();
