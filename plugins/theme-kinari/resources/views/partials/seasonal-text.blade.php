{{-- 季節の特集の文（小見出し・見出し・文・大きな一言・注記）。$section（季節の特集の区画） --}}
@if ($section['label'])
    <span class="text-xs tracking-[0.12em] text-ink-muted">{{ $section['label'] }}</span>
@endif
<h2 id="seasonal-{{ $section->id }}" class="font-display text-[28px] leading-snug font-medium tracking-[0.04em] text-balance [overflow-wrap:anywhere] sm:text-[32px]">{{ $section['heading'] }}</h2>
@if ($section['body'])
    <p class="text-[13px] whitespace-pre-line text-ink-muted">{{ $section['body'] }}</p>
@endif
@if ($section['highlight'])
    <p class="font-display text-[40px] leading-tight [overflow-wrap:anywhere]">{{ $section['highlight'] }}</p>
@endif
@if ($section['note'])
    <p class="text-xs text-ink-muted">{{ $section['note'] }}</p>
@endif
