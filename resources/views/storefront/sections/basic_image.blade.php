{{-- 核の汎用の区画「写真」（ADR 0039 区切り 3）。パターンは wide（横いっぱい）・narrow（文の幅）。写真が無ければ何も出さない --}}
<figure class="sugar-section sugar-image" data-section="{{ $section->id }}" data-section-type="basic_image" data-pattern="{{ $section->pattern }}">
    @if ($section['image'])
        <img src="{{ $section['image']->url('large') }}" srcset="{{ $section['image']->srcset() }}" sizes="100vw"
             width="{{ $section['image']->width }}" height="{{ $section['image']->height }}" alt="{{ $section['image']->alt ?? '' }}" loading="lazy">
    @elseif ($preview)
        {{-- 写真を選ぶまでの枠（編集の画面のプレビューだけ） --}}
        <div class="sugar-section__placeholder" aria-hidden="true"></div>
    @endif
    @if ($section['caption'])
        <figcaption class="sugar-image__caption">{{ $section['caption'] }}</figcaption>
    @endif
</figure>
