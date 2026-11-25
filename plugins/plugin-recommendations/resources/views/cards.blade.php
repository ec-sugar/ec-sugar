{{--
    おすすめの区画の商品の並び（App「おすすめの区画」。ADR 0071）。3 つの区画の部品が含める（区画の外に置き、composer を 2 度動かさない）。$section（SectionView）・$recommended（ListingCard の並び。composer が区画の部品に渡し、含めたこの部品に届く）・$type・$empty（空の時の文。null なら区画ごと出さない）。
    見た目は核の区画「商品の並び」と同じ（sections.css の sugar-products。色と文字はテーマのトークン）。パターン scroll は横に流れる（sugar-products--scroll）。
    札の外枠（sugar-card）の中に、App の押す・戻すのボタン（お気に入りのハートなど。核の部品 card-options）を置く。テーマは同じ名前の部品で描き直せる
--}}
@if (($recommended ?? []) !== [] || $empty !== null)
    <section class="sugar-section sugar-products sugar-app-section" data-section="{{ $section->id }}" data-section-type="{{ $type }}" data-pattern="{{ $section->pattern }}" data-test="{{ $type }}">
        @if ($section['heading'])
            <h2 class="sugar-section__heading">{{ $section['heading'] }}</h2>
        @endif
        @if (($recommended ?? []) === [])
            <p class="sugar-section__body">{{ $empty }}</p>
        @else
            <ul class="sugar-products__grid{{ $section->pattern === 'scroll' ? ' sugar-products__grid--scroll' : '' }}">
                @foreach ($recommended as $card)
                    <li data-test="recommended-card" data-listing="{{ $card->id }}">
                        <div class="sugar-card">
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
                            <x-storefront::card-options :listing="$card->id" />
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endif
