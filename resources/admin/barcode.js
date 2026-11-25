// QR コードをカメラで読めるか（App「デジタルチケット」の受付）。読むのはブラウザの標準の BarcodeDetector だけで、ライブラリを入れない。
// 無いブラウザ（iPhone の Safari など）では、カメラのボタンを出さず、番号の入力か、キーボードとして打つ読み取り機で受け付ける
export function canScanQr() {
    return typeof window !== 'undefined'
        && typeof window.BarcodeDetector === 'function'
        && typeof navigator.mediaDevices?.getUserMedia === 'function'
        && window.isSecureContext === true;
}
