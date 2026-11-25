{{-- 核の汎用の区画「商品の並び」（ADR 0039 区切り 3）。商品は公開中のものだけ（核が組み立て済み）。幅に合わせて列の数が変わる --}}
<section class="sugar-section sugar-products" data-section="{{ $section->id }}" data-section-type="basic_products" data-pattern="{{ $section->pattern }}">
    @if ($section['heading'])
        <h2 class="sugar-section__heading">{{ $section['heading'] }}</h2>
    @endif
    @if ($section['products'] === [])
        <p class="sugar-section__body">表示できる商品がありません。</p>
    @else
        <ul class="sugar-products__grid">
            @foreach ($section['products'] as $card)
                <li>
                    <a class="sugar-products__card" href="{{ $card->url() }}">
                        @if ($card->photo)
                            <img src="{{ $card->photo->url('medium') }}" srcset="{{ $card->photo->srcset() }}" sizes="(min-width: 768px) 25vw, 50vw"
                                 width="{{ $card->photo->width }}" height="{{ $card->photo->height }}" alt="{{ $card->photo->alt }}" loading="lazy">
                        @else
                            <span class="sugar-section__placeholder" aria-hidden="true"></span>
                        @endif
                        <span class="sugar-products__title">{{ $card->title }}</span>
                        <span class="sugar-products__price">{{ $card->priceLabel() }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</section>
