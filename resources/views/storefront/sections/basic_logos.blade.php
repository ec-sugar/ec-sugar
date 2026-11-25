{{-- 核の共通の区画「ロゴの帯」。パターンは row（1 行。狭い画面では折り返す）・grid（格子。狭い画面で 2 列、広い画面で 4 列）。
     ロゴはショップの写真（透過の PNG は透過のまま。白地の JPEG も帯の色になじむよう、核の CSS が乗算で重ねる）。写真が無ければ名前を文字で出す。
     行き先があればリンクにする（保存のときに核が確かめた店の中の道筋か https の href をそのまま出す）。写真も名前も無いロゴは出さない --}}
@if (array_filter($section->blocks, static fn ($l) => $l->key === 'logo' && ($l['image'] !== null || $l['name'] !== null || ($preview ?? false))) !== [])
    <section class="sugar-section sugar-logos" data-section="{{ $section->id }}" data-section-type="basic_logos" data-pattern="{{ $section->pattern }}">
        @if ($section['heading'])
            <h2 class="sugar-section__heading sugar-section__heading--center sugar-logos__heading">{{ $section['heading'] }}</h2>
        @endif
        <ul class="sugar-logos__list" role="list">
            @foreach ($section->blocks as $logo)
                @continue($logo->key !== 'logo' || ($logo['image'] === null && $logo['name'] === null && ! ($preview ?? false)))
                <li class="sugar-logos__item" data-motion-reveal>
                    <{{ $logo['link'] ? 'a' : 'span' }} class="sugar-logos__logo" @if ($logo['link']) href="{{ $logo['link'] }}" @endif>
                        @if ($logo['image'])
                            <img class="sugar-logos__image" src="{{ $logo['image']->url('medium') }}" width="{{ $logo['image']->width }}" height="{{ $logo['image']->height }}"
                                 alt="{{ $logo['name'] ?? $logo['image']->alt ?? '' }}" loading="lazy">
                        @elseif ($logo['name'])
                            <span class="sugar-logos__name">{{ $logo['name'] }}</span>
                        @else
                            <span class="sugar-section__placeholder sugar-logos__image" aria-hidden="true"></span>
                        @endif
                    </{{ $logo['link'] ? 'a' : 'span' }}>
                </li>
            @endforeach
        </ul>
    </section>
@elseif ($preview ?? false)
    <div class="sugar-section sugar-logos" data-section="{{ $section->id }}" data-section-type="basic_logos" data-pattern="{{ $section->pattern }}">
        <p class="sugar-section__empty">「ロゴ一覧」は、ロゴを追加するとここに表示されます</p>
    </div>
@endif
