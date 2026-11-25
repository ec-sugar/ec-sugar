{{-- 核の共通の区画「特長」。パターンは three（3 列）・four（4 列）・list（2 列のリスト）。狭い画面では列を減らす。
     絵は核が持つ Lucide の決まった数の中から（<x-storefront::section-icon>。テーマも店の人も SVG を持ち込まない）。
     見出しも説明も空のブロックは出さない。1 つも無ければ何も出さない（編集の画面のプレビューでは、入れるまでの枠） --}}
@if (array_filter($section->blocks, static fn ($f) => $f->key === 'feature' && ($f['title'] !== null || $f['text'] !== null)) !== [])
    <section class="sugar-section sugar-features" data-section="{{ $section->id }}" data-section-type="basic_features" data-pattern="{{ $section->pattern }}">
        @if ($section['heading'])
            <h2 class="sugar-section__heading sugar-section__heading--center">{{ $section['heading'] }}</h2>
        @endif
        <ul class="sugar-features__list" role="list">
            @foreach ($section->blocks as $feature)
                @continue($feature->key !== 'feature' || ($feature['title'] === null && $feature['text'] === null))
                <li class="sugar-features__item" data-motion-reveal>
                    @if ($feature['icon'])
                        <span class="sugar-features__icon"><x-storefront::section-icon :name="$feature['icon']" /></span>
                    @endif
                    <div class="sugar-features__text">
                        @if ($feature['title'])
                            <h3 class="sugar-features__title">{{ $feature['title'] }}</h3>
                        @endif
                        @if ($feature['text'])
                            <p class="sugar-features__body">{{ $feature['text'] }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
@elseif ($preview ?? false)
    <div class="sugar-section sugar-features" data-section="{{ $section->id }}" data-section-type="basic_features" data-pattern="{{ $section->pattern }}">
        <p class="sugar-section__empty">「特長」は、見出しか説明を入力するとここに表示されます</p>
    </div>
@endif
