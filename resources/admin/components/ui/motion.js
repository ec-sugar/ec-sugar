// 管理画面の動きの決まった値と小さな道具（使い方は docs/design/admin-ui.md の「動き」）。
// 管理画面は控えめ: 押した・開いた・変わった時だけ、0.15〜0.25 秒、移動は数 px で、ふわっと出す。
// 動かすのは transform と opacity だけ（レイアウトをずらさない）。「視差効果を減らす」を選んだ人には動かさない

// 長さ（秒。motion-v の transition は秒で書く）。fast は押した手ごたえや小さな印、base は出る・開く・画面の切り替え
export const DURATION = { fast: 0.15, base: 0.2 };

// イージング（動きの速さの付け方）。始めに速く、終わりにゆっくり止まる（ふわっと）
export const EASE_OUT = [0.22, 1, 0.36, 1];

// 動かす距離（px）。数 px だけ
export const SHIFT = 4;

// motion-v の m.* の :transition にそのまま渡す値
export const TRANSITION = { duration: DURATION.base, ease: EASE_OUT };
export const TRANSITION_FAST = { duration: DURATION.fast, ease: EASE_OUT };

// 端末の「動きを減らす」が選ばれているか（Web Animations で直に動かすときに見る。motion-v は App.vue の MotionConfig が見る）
export function reducesMotion() {
    return typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;
}

// 要素を下から数 px 浮かせて出す（画面の切り替え）。ブラウザの Web Animations で動かし、終われば何も残さない（style の文字を書かないので CSP の下で動く）
export function fadeUp(element) {
    if (!element?.animate || reducesMotion()) {
        return;
    }

    element.animate(
        [{ opacity: 0, transform: `translateY(${SHIFT}px)` }, { opacity: 1, transform: 'none' }],
        { duration: DURATION.base * 1000, easing: `cubic-bezier(${EASE_OUT.join(', ')})` },
    );
}

// 済んだ通知（AppNotice の success）が出た回数。AppButton は、回していた（loading）間にこれが増えたら、ボタンの中にチェックを一瞬出す。
// 画面の側は何も書かなくてよい（済んだ通知を出せば、押したボタンに印が付く）
let doneCount = 0;

export function markDone() {
    doneCount++;
}

export function doneSoFar() {
    return doneCount;
}
