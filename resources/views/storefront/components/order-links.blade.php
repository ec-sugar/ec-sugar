{{--
    App のご注文のリンク（核の部品。2026-11-04。「レビューを書く」「領収書」など）。ご注文の確認（テーマの order の画面）とマイページの注文の履歴に置く。
    使い方（テーマ）:
        <x-storefront::order-links :links="$order->links" class="…" />
    - $links: list<OrderLink>（plugin・label・path・newTab）。空なら何も描かない。入るのは注文を見てよい人の画面だけ（核の OrderLinks）
    - 行き先は App のショップの経路（/p/<名前>/…）だけ（OrderLink が形を確かめている）。文字は {{ }} で出す
    - newTab のリンク（印刷用のページなど）は新しいタブで開く。どのリンクも rel="noreferrer"（署名つきのご注文の画面の URL を次の画面へ渡さない）
    - 見た目は核の CSS（resources/storefront/apps.css の [data-order-links]）が付け、テーマは同じ印で上書きできる
--}}
@props(['links' => []])
@if ($links !== [])
    <nav {{ $attributes->merge(['data-order-links' => '', 'data-test' => 'order-links', 'aria-label' => 'このご注文について']) }}>
        @foreach ($links as $link)
            <a href="{{ $link->path }}" @if ($link->newTab) target="_blank" rel="noopener noreferrer" @else rel="noreferrer" @endif data-test="order-link-{{ $link->plugin }}">{{ $link->label }}</a>
        @endforeach
    </nav>
@endif
