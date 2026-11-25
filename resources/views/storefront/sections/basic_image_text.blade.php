{{-- 核の汎用の区画「写真と文」（ADR 0039 区切り 3）。パターンは image_left（写真が左）・image_right（写真が右）。狭い画面では縦に積む。
     写真が無ければ文だけ（編集の画面のプレビューでは、写真を選ぶまでの枠を出す） --}}
<section class="sugar-section sugar-image-text" data-section="{{ $section->id }}" data-section-type="basic_image_text" data-pattern="{{ $section->pattern }}">
    @if ($section['image'])
        <div class="sugar-image-text__media">
            <img src="{{ $section['image']->url('large') }}" srcset="{{ $section['image']->srcset() }}" sizes="(min-width: 768px) 50vw, 100vw"
                 width="{{ $section['image']->width }}" height="{{ $section['image']->height }}" alt="{{ $section['image']->alt ?? '' }}" loading="lazy">
        </div>
    @elseif ($preview)
        <div class="sugar-image-text__media"><div class="sugar-section__placeholder" aria-hidden="true"></div></div>
    @endif
    <div class="sugar-image-text__text">
        @if ($section['heading'])
            <h2 class="sugar-section__heading">{{ $section['heading'] }}</h2>
        @endif
        @if ($section['body'])
            <p class="sugar-section__body">{{ $section['body'] }}</p>
        @endif
        @if ($section['button_label'] && $section['button_link'])
            <p><a class="sugar-section__button" href="{{ $section['button_link'] }}">{{ $section['button_label'] }}</a></p>
        @endif
    </div>
</section>
