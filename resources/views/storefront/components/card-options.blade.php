{{--
    一覧の札に App が置く「押す・戻す」のボタン（ADR 0071。App「お気に入り」のハートなど）。核の部品で、テーマの札（partials.product-card）が
    札の外枠（.sugar-card。札のリンクの外。フォームはリンクの中に置けない）の中、リンクの後ろに置く。見た目は核の CSS（apps.css の .sugar-card-options。写真の右上に重ねる）。
    この店で使っている App のボタンだけ（無ければ何も出さず、テーマが中に書いた飾り〔$slot〕を出す）
--}}
@props(['listing'])
@if (($cardOptions = app(\App\Storefront\Catalog\CardOptions::class)->for($listing)) !== [])
    <div class="sugar-card-options" data-test="card-options">
        @foreach ($cardOptions as $option)
            <x-storefront::toggle-button :option="$option" compact />
        @endforeach
    </div>
@else
    {{ $slot }}
@endif
