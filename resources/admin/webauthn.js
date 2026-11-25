// パスキー（WebAuthn）。サーバーが返すオプションは JSON 形式（base64url）なので、ブラウザ標準の
// parse*FromJSON / toJSON() で変換する（変換のためのライブラリを入れない）。

export function passkeysSupported() {
    return typeof window.PublicKeyCredential === 'function'
        && typeof window.PublicKeyCredential.parseCreationOptionsFromJSON === 'function'
        && typeof window.PublicKeyCredential.parseRequestOptionsFromJSON === 'function';
}

class PasskeyError extends Error {
    constructor(userMessage, cause) {
        super(userMessage, { cause });
        this.userMessage = userMessage;
    }
}

// 登録の前に、このブラウザで登録できるかを確認する（ADR 0031）。secure と supported が偽なら先へ進めない。
// platform は「この端末の指紋・顔・暗証番号で作れるか」。偽でもスマートフォンやセキュリティキーで登録できるので止めない
export async function passkeyEnvironment() {
    const supported = passkeysSupported();
    let platform = false;

    if (supported && typeof window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable === 'function') {
        try {
            platform = await window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
        } catch {
            platform = false;
        }
    }

    return { secure: window.isSecureContext === true, supported, platform };
}

function translate(error) {
    // Chrome 系は、証明書の誤りのあるページ（警告を飛ばして開いた・信頼されない認証局）でのパスキーを断り、説明の文に certificate と入れる
    if (/certificate|TLS/i.test(error?.message ?? '')) {
        return new PasskeyError('この接続はブラウザに信頼されていないため、パスキーを使えません。アドレスバーに「保護されていない通信」などの警告が出ていないか確認してください。サーバーの証明書を修正すると登録できます。', error);
    }

    switch (error?.name) {
        case 'NotAllowedError':
            return new PasskeyError('パスキーの操作が取り消されたか、時間切れになりました。もう一度お試しください。何度試しても同じときは、アドレスバーに「保護されていない通信」などの警告が出ていないか確認してください（信頼されていない接続では、ブラウザがパスキーを断ります）。', error);
        case 'InvalidStateError':
            // 登録済みのパスキーと同じ保存先（iCloud キーチェーンなどで同期されたものも含む）には二重に作れない
            return new PasskeyError('この保存先には、あなたのパスキーがもう登録されています（iCloud キーチェーンなどで同期されたものも含みます）。別の保存先（別のパスワード管理アプリ、セキュリティキー、別のアカウントのスマートフォンなど）を選んでください。', error);
        case 'SecurityError':
            return new PasskeyError('このアドレスではパスキーを使えません。管理画面の URL を確認してください。', error);
        default:
            return new PasskeyError('パスキーを使えませんでした。', error);
    }
}

export async function createPasskey(options) {
    try {
        const credential = await navigator.credentials.create({
            publicKey: window.PublicKeyCredential.parseCreationOptionsFromJSON(options),
        });

        return credential.toJSON();
    } catch (error) {
        throw translate(error);
    }
}

export async function usePasskey(options) {
    try {
        const credential = await navigator.credentials.get({
            publicKey: window.PublicKeyCredential.parseRequestOptionsFromJSON(options),
        });

        return credential.toJSON();
    } catch (error) {
        throw translate(error);
    }
}
