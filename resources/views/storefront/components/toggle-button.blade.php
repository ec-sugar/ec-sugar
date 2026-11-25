{{--
    App の「押す・戻す」のボタン（トグル。ADR 0071。App「お気に入り」のハートなど）。核の部品で、買い方の部品（purchase-options）と札の部品（card-options）が使う。
    App の行き先（/p/<名前>/…）へ POST するフォーム（CSRF の合い言葉つき）。pressed は押した後の形（今押してあれば 0 を送る）、back は戻る先（App が自分のショップの中だけに絞る）。
    JavaScript があれば核の部品（toggles.js）が画面を移らずに送り、答え（{pressed, label}）で形と文字を替える。同じ行き先のボタンは画面の中でそろえる。
    $compact は札の上の小さな形（文字は読み上げだけ）
--}}
@props(['option', 'compact' => false])
<form method="post" action="{{ $option->path }}" class="sugar-toggle{{ $compact ? ' sugar-toggle--compact' : '' }}" data-sugar-toggle="{{ $option->path }}" data-test="toggle-{{ $option->plugin }}">
    @csrf
    <input type="hidden" name="pressed" value="{{ $option->pressed ? '0' : '1' }}" data-sugar-toggle-next>
    <input type="hidden" name="back" value="{{ request()->getRequestUri() }}">
    <button type="submit" class="sugar-toggle__button" aria-pressed="{{ $option->pressed ? 'true' : 'false' }}" data-test="toggle-{{ $option->plugin }}-button">
        <svg class="sugar-toggle__icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M12 20.5s-7.5-4.6-9.3-9.2C1.5 8 3.6 4.5 7.2 4.5c2 0 3.6 1.1 4.8 2.8 1.2-1.7 2.8-2.8 4.8-2.8 3.6 0 5.7 3.5 4.5 6.8-1.8 4.6-9.3 9.2-9.3 9.2z" /></svg>
        <span class="sugar-toggle__label{{ $compact ? ' sugar-toggle__label--hidden' : '' }}" data-sugar-toggle-label>{{ $option->label }}</span>
    </button>
    @if (! $compact && $option->note)
        <span class="sugar-purchase-options__note">{{ $option->note }}</span>
    @endif
</form>
