// 会員のパスキー（ADR 0044 の 3）。核の部品 <x-storefront::passkey-form> の [data-passkey] のフォームがあるときだけ動く。
// パスキーを使えるブラウザでだけフォームを出し（hidden を外す）、押されたらオプションをもらってブラウザのパスキーを呼び、答えを隠し欄に入れて
// そのフォームで送る（移り先と CSRF はふつうのフォームのまま）。変換はブラウザ標準の parse*FromJSON / toJSON() だけ（管理画面と同じ。ライブラリを入れない）。
// 文は textContent だけに入れる（HTML を差し込まない。店頭の CSP と Trusted Types のまま動く）

function supported() {
    return typeof window.PublicKeyCredential === 'function'
        && typeof window.PublicKeyCredential.parseCreationOptionsFromJSON === 'function'
        && typeof window.PublicKeyCredential.parseRequestOptionsFromJSON === 'function'
        && window.isSecureContext === true;
}

function message(error) {
    switch (error?.name) {
        case 'NotAllowedError':
            return 'パスキーの操作が取り消されたか、時間切れになりました。もう一度お試しください。';
        case 'InvalidStateError':
            return 'この端末には、このショップのパスキーがもう登録されています。';
        default:
            return 'パスキーを使えませんでした。メールの確認コードでお試しください。';
    }
}

// 個人情報の画面（会員の画面）では、オプションの経路が画面を開く要求のほかを断る（ADR 0088）。その画面に書いた画面の鍵を見出しで添える。
// 鍵の無い画面（ログインの画面）では何も足さない
function pageKey(form) {
    const key = form.dataset.sugarPageKey ?? '';

    return key === '' ? {} : { 'X-Sugar-Page-Key': key };
}

async function run(form) {
    const error = form.querySelector('[data-passkey-error]');
    const button = form.querySelector('button[type="submit"]');
    const token = form.querySelector('input[name="_token"]')?.value ?? '';

    error.hidden = true;
    button.disabled = true;

    try {
        const response = await fetch(form.dataset.passkeyOptions, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token, ...pageKey(form) },
        });

        if (!response.ok) {
            throw new Error('options');
        }

        const { options } = await response.json();
        const credential = form.dataset.passkey === 'create'
            ? await navigator.credentials.create({ publicKey: window.PublicKeyCredential.parseCreationOptionsFromJSON(options) })
            : await navigator.credentials.get({ publicKey: window.PublicKeyCredential.parseRequestOptionsFromJSON(options) });

        form.querySelector('input[name="credential"]').value = JSON.stringify(credential.toJSON());
        form.submit();
    } catch (e) {
        error.textContent = message(e);
        error.hidden = false;
        button.disabled = false;
    }
}

const forms = document.querySelectorAll('form[data-passkey]');

if (forms.length > 0 && supported()) {
    for (const form of forms) {
        form.hidden = false;
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            run(form);
        });
    }

    for (const note of document.querySelectorAll('[data-passkey-unsupported]')) {
        note.hidden = true;
    }
}
