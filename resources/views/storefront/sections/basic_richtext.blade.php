{{-- 核の共通の区画「見たまま書ける文」。パターンは narrow（文の幅）・wide（横いっぱい）。中身の形と整え方は「自由な HTML」と同じ
     （$section['body'] は核が描く前に整えた HtmlString。{{ }} でそのまま出る）。見た目は核の CSS の .sugar-html。空なら何も出さない --}}
@if ($section['body'])
    <section class="sugar-section sugar-custom" data-section="{{ $section->id }}" data-section-type="basic_richtext" data-pattern="{{ $section->pattern }}">
        <div class="sugar-html">{{ $section['body'] }}</div>
    </section>
@elseif ($preview ?? false)
    <div class="sugar-section sugar-custom" data-section="{{ $section->id }}" data-section-type="basic_richtext" data-pattern="{{ $section->pattern }}">
        <p class="sugar-section__empty">「リッチテキスト」は、文章を入力するとここに表示されます</p>
    </div>
@endif
