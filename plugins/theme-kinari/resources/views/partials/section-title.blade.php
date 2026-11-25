{{-- 区画の見出し: 英字の小見出し（飾り）と日本語の見出し。$eyebrow・$title・$id --}}
<div class="flex flex-col gap-0.5">
    <span class="font-display text-[15px] tracking-[0.18em] text-ink-soft" aria-hidden="true">{{ $eyebrow }}</span>
    <h2 id="{{ $id }}" class="font-mincho text-[26px] font-medium tracking-[0.06em]">{{ $title }}</h2>
</div>
