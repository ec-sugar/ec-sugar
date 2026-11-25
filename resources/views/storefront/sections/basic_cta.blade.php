{{-- 核の共通の区画「呼びかけの帯」。パターンは band（色の帯。テーマの --color-accent）・photo（写真の上。写真を暗く敷いて文字を重ねる）。
     「写真の上」で写真を選んでいなければ、色の帯で描く（編集の画面のプレビューでは写真を選ぶまでの枠の色）。
     見出し・文・ボタンのどれも無ければ何も出さない --}}
@if ($section['heading'] || $section['body'] || ($section['button_label'] && $section['button_link']))
    <section class="sugar-section sugar-cta" data-section="{{ $section->id }}" data-section-type="basic_cta" data-pattern="{{ $section->pattern }}">
        <div class="sugar-cta__panel" @if ($section->pattern === 'photo' && $section['image']) data-photo @endif>
            @if ($section->pattern === 'photo' && $section['image'])
                <img class="sugar-cta__image" src="{{ $section['image']->url('large') }}" srcset="{{ $section['image']->srcset() }}" sizes="100vw"
                     width="{{ $section['image']->width }}" height="{{ $section['image']->height }}" alt="" loading="lazy">
                <span class="sugar-cta__shade" aria-hidden="true"></span>
            @endif
            <div class="sugar-cta__content">
                @if ($section['heading'])
                    <h2 class="sugar-cta__heading">{{ $section['heading'] }}</h2>
                @endif
                @if ($section['body'])
                    <p class="sugar-cta__body">{{ $section['body'] }}</p>
                @endif
                @if ($section['button_label'] && $section['button_link'])
                    <p><a class="sugar-cta__button" href="{{ $section['button_link'] }}">{{ $section['button_label'] }}</a></p>
                @endif
            </div>
        </div>
    </section>
@elseif ($preview ?? false)
    <div class="sugar-section sugar-cta" data-section="{{ $section->id }}" data-section-type="basic_cta" data-pattern="{{ $section->pattern }}">
        <p class="sugar-section__empty">「CTA バナー」は、見出しか文章を入力するとここに表示されます</p>
    </div>
@endif
