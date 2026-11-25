{{-- 核の共通の区画「入口を並べる」。パターンは two（2 つ）・three（3 つ）。数が列に合わないときは、余りが幅を分け合う。
     写真に名前とボタンを重ねた入口（行き先は店の中の道筋か https だけ。保存のときに核が確かめた href をそのまま出す）。
     写真が無ければ帯の色の面に名前。名前も写真も無いブロックは出さない（編集の画面のプレビューでは、写真を選ぶまでの枠） --}}
@if (array_filter($section->blocks, static fn ($e) => $e->key === 'entry' && ($e['title'] !== null || $e['image'] !== null)) !== [])
    <section class="sugar-section sugar-entries" data-section="{{ $section->id }}" data-section-type="basic_entries" data-pattern="{{ $section->pattern }}">
        @if ($section['heading'])
            <h2 class="sugar-section__heading sugar-section__heading--center">{{ $section['heading'] }}</h2>
        @endif
        <ul class="sugar-entries__list" role="list">
            @foreach ($section->blocks as $entry)
                @continue($entry->key !== 'entry' || ($entry['title'] === null && $entry['image'] === null))
                <li class="sugar-entries__item" data-motion-reveal>
                    <{{ $entry['link'] ? 'a' : 'div' }} class="sugar-entries__card" @if ($entry['link']) href="{{ $entry['link'] }}" @endif @if ($entry['image'] || ($preview ?? false)) data-photo @endif data-motion-card>
                        @if ($entry['image'])
                            <img class="sugar-entries__image" src="{{ $entry['image']->url('large') }}" srcset="{{ $entry['image']->srcset() }}" sizes="(min-width: 768px) 50vw, 100vw"
                                 width="{{ $entry['image']->width }}" height="{{ $entry['image']->height }}" alt="" loading="lazy">
                        @elseif ($preview ?? false)
                            <span class="sugar-section__placeholder sugar-entries__image" aria-hidden="true"></span>
                        @endif
                        <span class="sugar-entries__text">
                            @if ($entry['title'])
                                <span class="sugar-entries__title">{{ $entry['title'] }}</span>
                            @endif
                            @if ($entry['link'])
                                <span class="sugar-entries__button" aria-hidden="true">{{ $entry['button_label'] ?? '見る' }}</span>
                            @endif
                        </span>
                    </{{ $entry['link'] ? 'a' : 'div' }}>
                </li>
            @endforeach
        </ul>
    </section>
@elseif ($preview ?? false)
    <div class="sugar-section sugar-entries" data-section="{{ $section->id }}" data-section-type="basic_entries" data-pattern="{{ $section->pattern }}">
        <p class="sugar-section__empty">「リンクカード」は、名前か写真を入力するとここに表示されます</p>
    </div>
@endif
