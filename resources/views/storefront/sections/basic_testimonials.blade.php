{{-- 核の共通の区画「お客さんの声」。パターンは cards（札を 3 列）・quote（大きな引用）・scroll（横に流れる）。
     声の文が空のブロックは出さない。星の数は持たない（作り話のレビューに見えないよう、いただいた言葉と名前だけを載せる）。
     声が 1 つも無ければ何も出さない（編集の画面のプレビューでは、入れるまでの枠）。見た目は核の CSS（resources/storefront/sections.css） --}}
@if (array_filter($section->blocks, static fn ($voice) => $voice->key === 'voice' && $voice['quote'] !== null) !== [])
    <section class="sugar-section sugar-voices" data-section="{{ $section->id }}" data-section-type="basic_testimonials" data-pattern="{{ $section->pattern }}">
        @if ($section['heading'])
            <h2 class="sugar-section__heading sugar-section__heading--center">{{ $section['heading'] }}</h2>
        @endif
        <ul class="sugar-voices__list" role="list">
            @foreach ($section->blocks as $voice)
                @continue($voice->key !== 'voice' || $voice['quote'] === null)
                <li class="sugar-voices__item" data-motion-reveal>
                    <figure class="sugar-voices__card">
                        <blockquote class="sugar-voices__quote"><p>{{ $voice['quote'] }}</p></blockquote>
                        @if ($voice['name'] || $voice['detail'])
                            <figcaption class="sugar-voices__who">
                                @if ($voice['name'])
                                    <span class="sugar-voices__name">{{ $voice['name'] }}</span>
                                @endif
                                @if ($voice['detail'])
                                    <span class="sugar-voices__detail">{{ $voice['detail'] }}</span>
                                @endif
                            </figcaption>
                        @endif
                    </figure>
                </li>
            @endforeach
        </ul>
    </section>
@elseif ($preview ?? false)
    <div class="sugar-section sugar-voices" data-section="{{ $section->id }}" data-section-type="basic_testimonials" data-pattern="{{ $section->pattern }}">
        <p class="sugar-section__empty">「お客さんの声」は、お客さんの声を入力するとここに表示されます</p>
    </div>
@endif
