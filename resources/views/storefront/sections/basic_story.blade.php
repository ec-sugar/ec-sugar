{{-- 核の共通の区画「作り手・物語」。パターンは longform（写真と長文）・timeline（年表）。
     写真と長文: 広い画面では写真が左・文が右、狭い画面では写真が上。写真が無ければ文だけ（編集の画面のプレビューでは写真を選ぶまでの枠）。
     年表: 見出しと文の下に、年表の行（いつ・出来事・説明）を縦の線でつなぐ。写真はこのパターンでは出さない（欄の説明に書いた）。
     何も無ければ何も出さない --}}
@if ($section['heading'] || $section['body'] || ($section->pattern === 'longform' && $section['image']) || ($section->pattern === 'timeline' && array_filter($section->blocks, static fn ($m) => $m->key === 'milestone' && $m['title'] !== null) !== []))
    <section class="sugar-section sugar-story" data-section="{{ $section->id }}" data-section-type="basic_story" data-pattern="{{ $section->pattern }}">
        @if ($section->pattern === 'longform' && ($section['image'] || ($preview ?? false)))
            <div class="sugar-story__media">
                @if ($section['image'])
                    <img src="{{ $section['image']->url('large') }}" srcset="{{ $section['image']->srcset() }}" sizes="(min-width: 768px) 45vw, 100vw"
                         width="{{ $section['image']->width }}" height="{{ $section['image']->height }}" alt="{{ $section['image']->alt ?? '' }}" loading="lazy">
                @else
                    <span class="sugar-section__placeholder" aria-hidden="true"></span>
                @endif
            </div>
        @endif
        <div class="sugar-story__text">
            @if ($section['kicker'])
                <p class="sugar-story__kicker">{{ $section['kicker'] }}</p>
            @endif
            @if ($section['heading'])
                <h2 class="sugar-section__heading">{{ $section['heading'] }}</h2>
            @endif
            @if ($section['body'])
                <p class="sugar-section__body">{{ $section['body'] }}</p>
            @endif
        </div>
        @if ($section->pattern === 'timeline')
            <ol class="sugar-story__timeline">
                @foreach ($section->blocks as $milestone)
                    @continue($milestone->key !== 'milestone' || $milestone['title'] === null)
                    <li class="sugar-story__milestone" data-motion-reveal>
                        @if ($milestone['when'])
                            <span class="sugar-story__when">{{ $milestone['when'] }}</span>
                        @endif
                        <div class="sugar-story__event">
                            <h3 class="sugar-story__title">{{ $milestone['title'] }}</h3>
                            @if ($milestone['text'])
                                <p class="sugar-story__body">{{ $milestone['text'] }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
@elseif ($preview ?? false)
    <div class="sugar-section sugar-story" data-section="{{ $section->id }}" data-section-type="basic_story" data-pattern="{{ $section->pattern }}">
        <p class="sugar-section__empty">「ブランドストーリー」は、見出しか文章を入力するとここに表示されます</p>
    </div>
@endif
