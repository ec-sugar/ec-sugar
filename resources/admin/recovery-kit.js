// 復元のキットをブラウザの中で作る・開け直して確認する（ADR 0111）。
//
// キットは、APP_KEY と「サーバーの外のバックアップを開ける鍵」を 1 つの JSON にまとめ、合い言葉で暗号化したファイル。
// 形はサーバーの道具（deploy/recovery-kit の check・ec-sugar restore --kit）と同じで、gpg の --symmetric でも開けられる。
//
// 守ること:
// - 開ける鍵（秘密の半分）と合い言葉は、このブラウザの外へ出さない（サーバーへ送るのは公開の半分・指紋・印だけ）
// - 値を console・ブラウザの保存領域・URL に置かない。使い終えた変数は呼ぶ側が捨てる
// - 暗号の部品（openpgp）はこの画面で要る時にだけ読む（ふだんの画面の重さを増やさない）
const KIND = 'ec-sugar-recovery-kit';
const FORMAT = 1;
const PROOF_PREFIX = 'ec-sugar-recovery-kit:';
const KEY_NAME = 'EC-SUGAR offsite backup';
const KIT_MAX = 256 * 1024;

export const PASSPHRASE_MIN = 12;

export class KitError extends Error {
    constructor(message) {
        super(message);
        this.userMessage = message;
    }
}

let loading = null;

function pgp() {
    loading ??= import('openpgp');

    return loading;
}

// 合い言葉の決まり（サーバーの道具と同じ）。だめなら理由の文、よければ null
export function passphraseProblem(passphrase) {
    if (passphrase.length < PASSPHRASE_MIN) {
        return `パスフレーズは ${PASSPHRASE_MIN} 文字以上にしてください。`;
    }

    // eslint-disable-next-line no-control-regex
    if (/[\x00-\x1f\x7f]/.test(passphrase)) {
        return 'パスフレーズに使えない文字（改行など）があります。';
    }

    return null;
}

function sealConfig(openpgp) {
    // gpg --symmetric --cipher-algo AES256 と同じ形（どの gpg でも開けられる）。合い言葉から鍵を作る回数は gpg が受ける上限
    return {
        preferredSymmetricAlgorithm: openpgp.enums.symmetric.aes256,
        preferredCompressionAlgorithm: openpgp.enums.compression.uncompressed,
        aeadProtect: false,
        s2kType: openpgp.enums.s2k.iterated,
        s2kIterationCountByte: 255,
    };
}

// 新しいキットを作る。返すのは保存するファイルの中身と、開ける鍵の指紋
export async function createKit({ appKey, server, passphrase }) {
    const problem = passphraseProblem(passphrase);

    if (problem !== null) {
        throw new KitError(problem);
    }

    const openpgp = await pgp();
    // RSA 4096 にする: サーバーの gpg（2.4）は、この部品が作った Curve25519 の秘密の鍵を開ける時に断る（Bad secret key。2.5 では開く。2026-11-20 に実測）。
    // RSA はどの版の gpg でも開ける。期限は付けない（キットを開ける日がいつになるか分からない）
    const { privateKey } = await openpgp.generateKey({ type: 'rsa', rsaBits: 4096, userIDs: [{ name: KEY_NAME }], format: 'armored', keyExpirationTime: 0 });
    const kit = {
        format: FORMAT,
        kind: KIND,
        created_at: new Date().toISOString().replace(/\.\d+Z$/, 'Z'),
        server,
        decrypt_key: privateKey,
        decrypt_key_passphrase: '',
        app_key: appKey,
    };
    const message = await openpgp.createMessage({ binary: new TextEncoder().encode(JSON.stringify(kit)) });
    const bytes = await openpgp.encrypt({ message, passwords: [passphrase], format: 'binary', config: sealConfig(openpgp) });
    const opened = await openKit(bytes, passphrase);

    return { bytes, fingerprint: opened.fingerprint };
}

// 保存したキットを合い言葉で開けて、中身が使えることを確認する。
// 返すのは、サーバーへ送ってよい値（公開鍵・指紋）と、印を作るための APP_KEY（呼ぶ側は印を作ったら捨てる）
export async function openKit(bytes, passphrase) {
    if (!(bytes instanceof Uint8Array) || bytes.length === 0 || bytes.length > KIT_MAX) {
        throw new KitError('復元のキットのファイルではないようです。');
    }

    const openpgp = await pgp();
    let plain;

    try {
        const message = await openpgp.readMessage({ binaryMessage: bytes });
        ({ data: plain } = await openpgp.decrypt({ message, passwords: [passphrase], format: 'binary' }));
    } catch {
        throw new KitError('復号できませんでした。パスフレーズが違うか、復元のキットのファイルではありません。');
    }

    let kit;

    try {
        kit = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(plain));
    } catch {
        throw new KitError('ファイルの中身が復元のキットの形式ではありません。');
    }

    // 形の確認は、戻す側の道具（deploy/recovery-kit の kit_problems・ec-sugar の ec_kit_open）と同じ決まりにする。
    // ここで通したものを戻す日に道具が断る、を作らない（知らない欄・足りない欄・形の違う値）
    const known = ['app_key', 'created_at', 'decrypt_key', 'decrypt_key_passphrase', 'format', 'kind', 'offsite', 'server'];
    const text = (value) => typeof value === 'string';

    if (kit === null || typeof kit !== 'object' || Array.isArray(kit) || kit.kind !== KIND || kit.format !== FORMAT
        || Object.keys(kit).some((key) => !known.includes(key))
        || !text(kit.app_key) || !/^base64:[A-Za-z0-9+/]{43}=$/.test(kit.app_key)
        || !text(kit.created_at) || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(kit.created_at)
        || !text(kit.server) || !/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/.test(kit.server) || kit.server.includes('..')
        || !text(kit.decrypt_key) || !kit.decrypt_key.startsWith('-----BEGIN PGP PRIVATE KEY BLOCK-----')
        // eslint-disable-next-line no-control-regex
        || !text(kit.decrypt_key_passphrase ?? '') || /[\x00-\x1f\x7f]/.test(kit.decrypt_key_passphrase ?? '')) {
        throw new KitError('ファイルの中身が復元のキットの形式ではありません。');
    }

    let key;

    try {
        key = await openpgp.readPrivateKey({ armoredKey: kit.decrypt_key });

        if (!key.isDecrypted()) {
            key = await openpgp.decryptKey({ privateKey: key, passphrase: kit.decrypt_key_passphrase ?? '' });
        }

        // 開ける鍵で、本当に開けられることを確認する（公開の半分で暗号化した印を、秘密の半分で開ける）
        const mark = crypto.getRandomValues(new Uint8Array(32));
        const sealed = await openpgp.encrypt({ message: await openpgp.createMessage({ binary: mark }), encryptionKeys: key.toPublic(), format: 'binary' });
        const { data } = await openpgp.decrypt({ message: await openpgp.readMessage({ binaryMessage: sealed }), decryptionKeys: key, format: 'binary' });

        if (data.length !== mark.length || data.some((byte, index) => byte !== mark[index])) {
            throw new Error('mismatch');
        }
    } catch {
        throw new KitError('キットの中の復号の鍵が使えません。キットを作り直してください。');
    }

    return {
        appKey: kit.app_key,
        server: kit.server,
        createdAt: kit.created_at,
        fingerprint: key.getFingerprint().toUpperCase(),
        publicKey: key.toPublic().armor(),
    };
}

// 開け直したキットの APP_KEY と指紋から作る印（サーバーの RecoveryKit::proof と同じ式）
export async function proofOf(appKey, fingerprint) {
    const encoder = new TextEncoder();
    const key = await crypto.subtle.importKey('raw', encoder.encode(appKey), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
    const mac = new Uint8Array(await crypto.subtle.sign('HMAC', key, encoder.encode(PROOF_PREFIX + fingerprint)));

    return Array.from(mac, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

// ブラウザに保存させる（保存先は利用者が選ぶ）
export function saveKit(bytes, name) {
    const url = URL.createObjectURL(new Blob([bytes], { type: 'application/octet-stream' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = name;
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

export function kitFileName(server, now = new Date()) {
    const day = `${now.getFullYear()}${String(now.getMonth() + 1).padStart(2, '0')}${String(now.getDate()).padStart(2, '0')}`;
    const host = /^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/.test(server) ? server : 'server';

    return `ec-sugar-recovery-kit-${host}-${day}.gpg`;
}
