{{-- 核の共通の区画「1 つの商品を大きく」。パターンは split（写真が左）・full（写真が全面に、名前とカートの箱を重ねる）。
     $section['product'] は選んだ商品の詳細（ProductView。公開中だけ）か null。選んでいない・公開でなくなった・消した商品なら何も出さない
     （編集の画面のプレビューでは、選ぶまでの枠）。
     「カートに入れる」・種類（色・サイズ）・オプションは、商品の画面と同じ核の部品（variant-picker・addon-fields）と同じフォームの形（cart.js が動かす）。
     種類の部品はページの価格・写真の印（data-variant-price・data-variant-photo）を読むので、この区画は 1 つのページに 1 つまで（宣言の limit） --}}
@if ($section['product'] !== null)
    <section class="sugar-section sugar-product" data-section="{{ $section->id }}" data-section-type="basic_product" data-pattern="{{ $section->pattern }}">
        <div class="sugar-product__media">
            @if ($section['product']->photos !== [])
                <img class="sugar-product__photo" src="{{ $section['product']->photos[0]->url('large') }}" srcset="{{ $section['product']->photos[0]->srcset() }}"
                     sizes="{{ $section->pattern === 'full' ? '100vw' : '(min-width: 768px) 55vw, 100vw' }}"
                     width="{{ $section['product']->photos[0]->width }}" height="{{ $section['product']->photos[0]->height }}" alt="{{ $section['product']->photos[0]->alt }}" loading="lazy" data-variant-photo>
                @if ($section->pattern === 'split' && count($section['product']->photos) > 1)
                    <ul class="sugar-product__thumbs" role="list">
                        @foreach (array_slice($section['product']->photos, 1, 4) as $photo)
                            <li><img src="{{ $photo->url('medium') }}" width="{{ $photo->width }}" height="{{ $photo->height }}" alt="{{ $photo->alt }}" loading="lazy"></li>
                        @endforeach
                    </ul>
                @endif
            @else
                <span class="sugar-section__placeholder sugar-product__photo" aria-hidden="true"></span>
            @endif
        </div>

        <div class="sugar-product__panel">
            @if ($section['kicker'])
                <p class="sugar-product__kicker">{{ $section['kicker'] }}</p>
            @endif
            <h2 class="sugar-product__title"><a href="{{ $section['product']->url }}">{{ $section['product']->card->title }}</a></h2>
            <p class="sugar-product__price"><span data-variant-price>{{ $section['product']->card->priceLabel() }}</span> <span class="sugar-product__tax">税込</span></p>
            <p class="sugar-product__availability" data-variant-availability @if ($section['product']->card->availability === 'sold_out') data-sold-out @endif>
                {{-- 在庫の具合の文（予約の商品は「予約商品（11月20日ごろ発送）」。ADR 0073） --}}
                {{ $section['product']->card->availabilityLabel() }}
            </p>
            @if ($section['body'] ?? $section['product']->card->summary)
                <p class="sugar-product__body">{{ $section['body'] ?? $section['product']->card->summary }}</p>
            @endif

            @if ($section['product']->maxQuantity > 0)
                <form method="post" action="{{ route('storefront.cart.add') }}" class="sugar-product__form" data-cart-form data-cart-page="{{ route('storefront.cart') }}">
                    @csrf
                    <input type="hidden" name="listing_id" value="{{ $section['product']->card->id }}">
                    <x-storefront::variant-picker :product="$section['product']" />
                    <x-storefront::addon-fields :product="$section['product']" />
                    <div class="sugar-product__buy">
                        <div class="sugar-product__stepper" data-stepper>
                            <button type="button" aria-label="1 つ減らす" data-step="-1">−</button>
                            <input type="number" name="quantity" value="1" min="1" max="{{ $section['product']->maxQuantity }}" inputmode="numeric" aria-label="数量">
                            <button type="button" aria-label="1 つ増やす" data-step="1">+</button>
                        </div>
                        <button type="submit" class="sugar-section__button sugar-product__add">{{ $section['product']->card->addToCartLabel() }}</button>
                    </div>
                </form>
            @else
                <p><button type="button" class="sugar-section__button sugar-product__add" disabled>売り切れ</button></p>
            @endif
            @if ($flash['error'] ?? null)
                <p role="alert" class="sugar-product__error">{{ $flash['error'] }}</p>
            @endif
            <a class="sugar-product__more" href="{{ $section['product']->url }}">商品のページを見る</a>
        </div>
    </section>
@elseif ($preview ?? false)
    <div class="sugar-section sugar-product" data-section="{{ $section->id }}" data-section-type="basic_product" data-pattern="{{ $section->pattern }}">
        <p class="sugar-section__empty">「1 つの商品を大きく」は、公開中の商品を選ぶとここに表示されます</p>
    </div>
@endif
