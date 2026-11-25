{{-- 核の共通の区画「数字で見せる」。パターンは row（3 つ並び。数が多ければ折り返す）・photo（写真が左、数字が右に 2 列）。
     数字・単位・何の数かのブロック。数字の無いブロックは出さない。1 つも無ければ何も出さない（編集の画面のプレビューでは、入れるまでの知らせ）。
     「写真と数字」で写真を選んでいなければ数字だけ（プレビューでは写真を選ぶまでの枠） --}}
@if (array_filter($section->blocks, static fn ($s) => $s->key === 'stat' && $s['value'] !== null) !== [])
    <section class="sugar-section sugar-numbers" data-section="{{ $section->id }}" data-section-type="basic_numbers" data-pattern="{{ $section->pattern }}">
        @if ($section->pattern === 'photo' && ($section['image'] || ($preview ?? false)))
            <div class="sugar-numbers__media">
                @if ($section['image'])
                    <img src="{{ $section['image']->url('large') }}" srcset="{{ $section['image']->srcset() }}" sizes="(min-width: 768px) 45vw, 100vw"
                         width="{{ $section['image']->width }}" height="{{ $section['image']->height }}" alt="{{ $section['image']->alt ?? '' }}" loading="lazy">
                @else
                    <span class="sugar-section__placeholder" aria-hidden="true"></span>
                @endif
            </div>
        @endif
        <div class="sugar-numbers__text">
            @if ($section['heading'])
                <h2 @class(['sugar-section__heading', 'sugar-section__heading--center' => $section->pattern === 'row'])>{{ $section['heading'] }}</h2>
            @endif
            @if ($section['body'])
                <p class="sugar-section__body sugar-numbers__intro">{{ $section['body'] }}</p>
            @endif
            <ul class="sugar-numbers__list" role="list">
                @foreach ($section->blocks as $stat)
                    @continue($stat->key !== 'stat' || $stat['value'] === null)
                    <li class="sugar-numbers__item" data-motion-reveal>
                        <p class="sugar-numbers__figure">
                            <span class="sugar-numbers__value">{{ $stat['value'] }}</span>
                            @if ($stat['unit'])
                                <span class="sugar-numbers__unit">{{ $stat['unit'] }}</span>
                            @endif
                        </p>
                        @if ($stat['label'])
                            <p class="sugar-numbers__label">{{ $stat['label'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@elseif ($preview ?? false)
    <div class="sugar-section sugar-numbers" data-section="{{ $section->id }}" data-section-type="basic_numbers" data-pattern="{{ $section->pattern }}">
        <p class="sugar-section__empty">「実績の数字」は、数字を入力するとここに表示されます</p>
    </div>
@endif
