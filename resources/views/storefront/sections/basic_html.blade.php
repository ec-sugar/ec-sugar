{{-- 核の共通の区画「自由な HTML」。パターンは narrow（文の幅）・wide（横いっぱい）。見た目は核の CSS（resources/storefront/sections.css の .sugar-html）。
     $section['html'] は核が描く前に整えた HtmlString（決まったタグ・属性・class だけ。SectionHtml::render）なので {{ }} でそのまま出る。
     エスケープしない出し方の書き方は使わない（整えていない文字を出す道を作らない。CoreSectionsDeclarationTest が見張る）。空なら何も出さない --}}
@if ($section['html'])
    <section class="sugar-section sugar-custom" data-section="{{ $section->id }}" data-section-type="basic_html" data-pattern="{{ $section->pattern }}">
        <div class="sugar-html">{{ $section['html'] }}</div>
    </section>
@elseif ($preview ?? false)
    <div class="sugar-section sugar-custom" data-section="{{ $section->id }}" data-section-type="basic_html" data-pattern="{{ $section->pattern }}">
        <p class="sugar-section__empty">「自由な HTML」は、HTML を入力するとここに表示されます</p>
    </div>
@endif
