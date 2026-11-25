{{-- 核の汎用の区画「ボタン」（ADR 0039 区切り 3）。パターンは center（中央）・left（左寄せ）。文字と行き先がそろったときだけボタンを出す --}}
<div class="sugar-section sugar-button" data-section="{{ $section->id }}" data-section-type="basic_button" data-pattern="{{ $section->pattern }}">
    @if ($section['label'] && $section['link'])
        <a class="sugar-section__button" href="{{ $section['link'] }}">{{ $section['label'] }}</a>
    @endif
</div>
