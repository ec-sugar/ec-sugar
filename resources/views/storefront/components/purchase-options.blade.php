{{--
    App の買い方（ADR 0050。定期便の「定期便で申し込む」など）。核の部品で、テーマの商品の画面が「カートに入れる」の近くに置く。
    この店で使っている App の分だけ。App が返すのは文字と自分の /p/ の下の道筋だけ（HTML は返さない）。
    売り切れの商品では sold-out を付けて置き、売り切れの時にも出す印（whenSoldOut）のある買い方だけを出す（「入荷したら知らせる」など）。
    押す・戻すの形（pressed のある買い方。App「お気に入り」など。ADR 0071）は、リンクでなく POST のフォームのボタン（核の部品 toggle-button）で描く
--}}
@props(['options' => [], 'soldOut' => false])
@if (\App\Core\Plugin\PurchaseOption::shown($options, $soldOut) !== [])
    <div class="sugar-purchase-options" data-test="purchase-options">
        @foreach (\App\Core\Plugin\PurchaseOption::shown($options, $soldOut) as $option)
            @if ($option->isToggle())
                <x-storefront::toggle-button :option="$option" />
            @else
                <a href="{{ $option->path }}" class="sugar-purchase-options__button" data-test="purchase-option-{{ $option->plugin }}">
                    <span>{{ $option->label }}</span>
                    @if ($option->note)
                        <span class="sugar-purchase-options__note">{{ $option->note }}</span>
                    @endif
                </a>
            @endif
        @endforeach
    </div>
@endif
