{{-- 核の共通の区画「よくある質問」。パターンは list（開閉の 1 列）・side（見出しが左）。
     開閉は <details>（JavaScript が無くても開く。開いた時の動きは核の CSS で、動きを減らす設定では動かさない）。
     質問の文が空のブロックは出さない。質問が 1 つも無ければ何も出さない（編集の画面のプレビューでは、入れるまでの枠） --}}
@if (array_filter($section->blocks, static fn ($q) => $q->key === 'question' && $q['question'] !== null) !== [])
    <section class="sugar-section sugar-faq" data-section="{{ $section->id }}" data-section-type="basic_faq" data-pattern="{{ $section->pattern }}">
        @if ($section['heading'] || $section['body'] || ($section['button_label'] && $section['button_link']))
            <div class="sugar-faq__intro">
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
        @endif
        <div class="sugar-faq__list">
            @foreach ($section->blocks as $question)
                @continue($question->key !== 'question' || $question['question'] === null)
                <details class="sugar-faq__item">
                    <summary class="sugar-faq__question"><span>{{ $question['question'] }}</span><span class="sugar-faq__sign" aria-hidden="true"></span></summary>
                    @if ($question['answer'])
                        <div class="sugar-faq__answer"><p>{{ $question['answer'] }}</p></div>
                    @endif
                </details>
            @endforeach
        </div>
    </section>
@elseif ($preview ?? false)
    <div class="sugar-section sugar-faq" data-section="{{ $section->id }}" data-section-type="basic_faq" data-pattern="{{ $section->pattern }}">
        <p class="sugar-section__empty">「よくある質問」は、質問を入力するとここに表示されます</p>
    </div>
@endif
