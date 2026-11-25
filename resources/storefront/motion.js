// ショップの動き。テーマではなく核が持つ JavaScript（ADR 0020・ADR 0028 の 4）。テーマは CSS と data- の印で選ぶだけ。
// - 強さはテーマの theme.json の motion。外枠の <html data-motion="none|subtle|rich"> から読む。動く量と時間は CSS の変数（motion.css の --motion-*）から読む
// - 端末の「視差効果を減らす」（prefers-reduced-motion: reduce）と強さ none では動かさない（値はすぐ最後の形にする）
// - 動かすのは transform と opacity だけ。終わったら動きの跡（要素の style に残る値）を消す
// - スクロールで浮かぶ（[data-motion-reveal]。rich だけ）は IntersectionObserver＝画面に入ったかを知らせるブラウザの仕組み で見る。
//   最初から見えている所は動かさず、まだ見えていない所だけを、この部品が data-motion-ready を付けてから隠す（JS が動かなければ全部見えたまま）
// - スマホのメニュー（<details data-motion-menu>）は、開く動きを CSS が、閉じる動きをここが付ける（JS が無くても開け閉めできる）
//
// 店頭の CSP と Trusted Types のまま動くように、HTML の文字列は差し込まず、style は CSSOM（要素の style の項目）でだけ触る。
import './motion.css';
// Motion の小さい版（ブラウザの Web Animations＝要素を動かす標準の仕組み だけを使う。ADR 0037）
import { animate } from 'motion/mini';

const root = document.documentElement;
const LEVELS = ['none', 'subtle', 'rich'];

export function prefersReducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
}

// 今の強さ（none・subtle・rich）。動きを減らす設定なら none
export function motionLevel() {
    if (prefersReducedMotion()) {
        return 'none';
    }

    const level = root.getAttribute('data-motion');

    return LEVELS.includes(level) ? level : 'subtle';
}

// CSS の変数を数で読む（時間は秒、長さは px）。読めなければ決めた値
function cssNumber(name, unit, fallback) {
    const value = getComputedStyle(root).getPropertyValue(name).trim();
    const match = /^(-?\d*\.?\d+)(ms|s|px)?$/.exec(value);

    if (!match) {
        return fallback;
    }

    const number = Number.parseFloat(match[1]);

    if (unit === 's') {
        return match[2] === 'ms' ? number / 1000 : number;
    }

    return number;
}

function timing() {
    return { duration: cssNumber('--motion-duration', 's', 0.2), ease: [0.2, 0.7, 0.2, 1] };
}

// 要素ごとに今の動きを 1 つだけ持つ（続けて変わったら前の動きを止めて今の形から始め直す）
const running = new WeakMap();

function play(element, keyframes, options) {
    running.get(element)?.stop();
    const animation = animate(element, keyframes, options);
    running.set(element, animation);

    animation.finished.then(() => {
        // 終わると最後の値が要素の style に残るので消す（テーマの CSS の値に戻す）。別の動きに替わっていたら触らない
        if (running.get(element) === animation) {
            running.delete(element);
            element.style.removeProperty('opacity');
            element.style.removeProperty('transform');
        }
    });

    return animation;
}

// 値が変わった（価格・合計・小計など）: 少し下から薄く出る
export function settle(element) {
    if (!element || motionLevel() === 'none') {
        return;
    }

    const nudge = cssNumber('--motion-nudge', 'px', 3);
    play(element, { opacity: [0.35, 1], transform: [`translateY(${nudge}px)`, 'translateY(0px)'] }, timing());
}

// 文字を入れ替え、変わったときだけ settle で知らせる
export function setText(element, text) {
    if (!element) {
        return;
    }

    const next = text ?? '';

    if (element.textContent !== next) {
        element.textContent = next;
        settle(element);
    }
}

// カートの数が変わった: 一度だけふくらむ
export function pop(element) {
    if (!element || motionLevel() === 'none') {
        return;
    }

    const scale = cssNumber('--motion-pop-scale', '', 1.15);
    const { duration, ease } = timing();
    play(element, { transform: ['scale(1)', `scale(${scale})`, 'scale(1)'] }, { duration: duration * 1.5, ease });
}

// 写真を入れ替えた（種類を選んだ時）: 新しい写真が読めてから、薄い所から出す。強さ rich では少しゆっくり
export function photoChanged(image) {
    if (!image || motionLevel() === 'none') {
        return;
    }

    const { duration, ease } = timing();
    const show = () => play(image, { opacity: [0.25, 1] }, { duration: duration * 1.5, ease });
    (image.decode ? image.decode() : Promise.resolve()).then(show, show);
}

// 押して新しく出たもの（App の区画の埋め込みの枠。ADR 0040）: 薄い所から出す。動きを減らす設定では出すだけ
export function appear(element) {
    if (!element || motionLevel() === 'none') {
        return;
    }

    play(element, { opacity: [0, 1] }, timing());
}

// スクロールで浮かぶ（[data-motion-reveal]。rich だけ）
function wireReveal() {
    const targets = [...document.querySelectorAll('[data-motion-reveal]')];

    if (targets.length === 0 || motionLevel() !== 'rich' || !('IntersectionObserver' in window)) {
        return;
    }

    const stagger = cssNumber('--motion-stagger', 's', 0.08) * 1000;
    const height = window.innerHeight || root.clientHeight;
    const waiting = [];

    for (const target of targets) {
        const box = target.getBoundingClientRect();

        // 最初から画面に入っている所は動かさない（見えている中身を一度隠すことをしない）
        if (box.bottom > 0 && box.top < height && box.height > 0) {
            target.setAttribute('data-motion-shown', '');
        } else {
            waiting.push(target);
        }
    }

    if (waiting.length === 0) {
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        // 同じ時に入ってきたものを、並びの順に少しずつずらして浮かべる（長く待たせないよう 6 つ目からは同じ間）
        const entering = entries.filter((entry) => entry.isIntersecting).map((entry) => entry.target);

        entering.forEach((target, index) => {
            observer.unobserve(target);
            target.style.setProperty('transition-delay', `${Math.min(index, 5) * stagger}ms`);
            target.setAttribute('data-motion-shown', '');
            target.addEventListener('transitionend', () => target.style.removeProperty('transition-delay'), { once: true });
        });
    }, { rootMargin: '0px 0px -8% 0px' });

    for (const target of waiting) {
        observer.observe(target);
    }

    // ここで初めて「まだ見えていない所」を隠す（印が無ければ CSS は何も隠さない）
    root.setAttribute('data-motion-ready', '');
}

// スマホのメニュー（<details data-motion-menu> の中の <summary> と [data-motion-menu-panel]）。閉じる時に上へ薄く消してから閉じる。Esc でも閉じる
function wireMenus() {
    for (const menu of document.querySelectorAll('details[data-motion-menu]')) {
        const summary = menu.querySelector(':scope > summary');
        const panel = menu.querySelector('[data-motion-menu-panel]');

        if (!summary || !panel) {
            continue;
        }

        let closing = null;

        const close = () => {
            if (!menu.open || closing) {
                return;
            }

            if (motionLevel() === 'none') {
                menu.open = false;

                return;
            }

            const nudge = cssNumber('--motion-nudge', 'px', 3);
            const animation = play(panel, { opacity: [1, 0], transform: ['translateY(0px)', `translateY(-${nudge}px)`] }, timing());
            closing = animation;
            animation.finished.then(() => {
                if (closing === animation) {
                    menu.open = false;
                }
            });
        };

        summary.addEventListener('click', (event) => {
            if (menu.open) {
                event.preventDefault();
                close();
            }
        });

        // 同じページの中へのリンク（#about など）を押したら、メニューを閉じて行き先を見せる
        panel.addEventListener('click', (event) => {
            if (event.target instanceof Element && event.target.closest('a')) {
                menu.open = false;
            }
        });

        menu.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && menu.open) {
                close();
                summary.focus();
            }
        });

        // どの道で閉じても、次に開く時のために閉じる動きの跡を消す
        menu.addEventListener('toggle', () => {
            if (!menu.open) {
                closing = null;
                running.get(panel)?.stop();
                running.delete(panel);
                panel.style.removeProperty('opacity');
                panel.style.removeProperty('transform');
            }
        });
    }
}

wireReveal();
wireMenus();
