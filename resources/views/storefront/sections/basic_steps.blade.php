{{-- 核の共通の区画「手順・流れ」。パターンは row（横に番号。広い画面では番号の丸を線でつないで横に並べ、狭い画面では縦に積む）・
     line（縦の線。広い画面では左に見出しと説明、右に番号の丸を縦の線でつないだ手順）。番号は並びの順に核が付ける（<ol> の順）。
     名前の無い手順は出さない。1 つも無ければ何も出さない（編集の画面のプレビューでは、入れるまでの知らせ） --}}
@if (array_filter($section->blocks, static fn ($s) => $s->key === 'step' && $s['title'] !== null) !== [])
    <section class="sugar-section sugar-steps" data-section="{{ $section->id }}" data-section-type="basic_steps" data-pattern="{{ $section->pattern }}">
        @if ($section['heading'] || $section['body'])
            <div class="sugar-steps__intro">
                @if ($section['heading'])
                    <h2 @class(['sugar-section__heading', 'sugar-section__heading--center' => $section->pattern === 'row'])>{{ $section['heading'] }}</h2>
                @endif
                @if ($section['body'])
                    <p class="sugar-section__body">{{ $section['body'] }}</p>
                @endif
            </div>
        @endif
        <ol class="sugar-steps__list">
            @foreach (array_values(array_filter($section->blocks, static fn ($s) => $s->key === 'step' && $s['title'] !== null)) as $i => $step)
                <li class="sugar-steps__item" data-motion-reveal>
                    <span class="sugar-steps__number" aria-hidden="true">{{ $i + 1 }}</span>
                    <div class="sugar-steps__text">
                        <h3 class="sugar-steps__title">{{ $step['title'] }}</h3>
                        @if ($step['text'])
                            <p class="sugar-steps__body">{{ $step['text'] }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </section>
@elseif ($preview ?? false)
    <div class="sugar-section sugar-steps" data-section="{{ $section->id }}" data-section-type="basic_steps" data-pattern="{{ $section->pattern }}">
        <p class="sugar-section__empty">「手順・流れ」は、手順の名前を入力するとここに表示されます</p>
    </div>
@endif
