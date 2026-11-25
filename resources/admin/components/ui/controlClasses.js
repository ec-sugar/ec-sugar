// 入力欄の共通の見た目（AppInput / AppSelect / AppTextarea）。地の色で区切り、選ぶと白くなって藍の枠が付く
export const CONTROL = [
    'block w-full rounded-lg bg-subtle px-3 text-sm text-ink placeholder:text-ink-faint',
    'ring-1 ring-transparent transition-colors focus:bg-surface focus:ring-accent focus:outline-none focus-visible:outline-none',
    'aria-invalid:bg-danger-soft aria-invalid:ring-danger disabled:opacity-60',
].join(' ');

// 押した手ごたえ。押している間だけわずかに縮む。色の移り変わりも一緒に 0.15 秒で（並びは background-color から始める。color を先頭にすると、色の直書きを止める検査の型に掛かる）。
// 「動きを減らす」を選んだ人には縮めない。PRESS_WIDE は幅の広い行（メニューの項目・目次の行）用で、縮み方をさらに小さくする
export const PRESS = 'transition-[background-color,color,box-shadow,opacity,scale] duration-150 ease-out active:scale-[0.97] motion-reduce:active:scale-100';
export const PRESS_WIDE = 'transition-[background-color,color,box-shadow,opacity,scale] duration-150 ease-out active:scale-[0.99] motion-reduce:active:scale-100';
