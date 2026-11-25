{{-- 核の共通の区画「ギャラリー」。パターンは grid（格子）・masonry（石垣。写真の縦横の比のまま段に詰める）・scroll（横に流れる）。
     写真はショップの写真から。写真を選んだブロックだけ出す（編集の画面のプレビューでは、選ぶまでの枠も出す）。1 枚も無ければ何も出さない --}}
@if (array_filter($section->blocks, static fn ($p) => $p->key === 'photo' && ($p['image'] !== null || ($preview ?? false))) !== [])
    <section class="sugar-section sugar-gallery" data-section="{{ $section->id }}" data-section-type="basic_gallery" data-pattern="{{ $section->pattern }}">
        @if ($section['heading'])
            <h2 class="sugar-section__heading sugar-section__heading--center">{{ $section['heading'] }}</h2>
        @endif
        <ul class="sugar-gallery__list" role="list">
            @foreach ($section->blocks as $photo)
                @continue($photo->key !== 'photo' || ($photo['image'] === null && ! ($preview ?? false)))
                <li class="sugar-gallery__item" data-motion-reveal>
                    <figure class="sugar-gallery__figure">
                        @if ($photo['image'])
                            <img src="{{ $photo['image']->url('medium') }}" srcset="{{ $photo['image']->srcset() }}" sizes="(min-width: 768px) 33vw, 50vw"
                                 width="{{ $photo['image']->width }}" height="{{ $photo['image']->height }}" alt="{{ $photo['image']->alt ?? $photo['caption'] ?? '' }}" loading="lazy">
                        @else
                            <span class="sugar-section__placeholder" aria-hidden="true"></span>
                        @endif
                        @if ($photo['caption'])
                            <figcaption class="sugar-gallery__caption">{{ $photo['caption'] }}</figcaption>
                        @endif
                    </figure>
                </li>
            @endforeach
        </ul>
    </section>
@endif
