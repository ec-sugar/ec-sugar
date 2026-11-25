{{-- 区画の見出し: 英字（飾り）・短い線・日本語の見出し。$eyebrow・$title・$id、$align は center（既定）か start --}}
<h2 id="{{ $id }}" class="flex flex-col gap-2 {{ ($align ?? 'center') === 'center' ? 'items-center text-center' : 'items-start' }}">
    <span class="font-display text-[22px] font-medium tracking-[0.24em]" aria-hidden="true">{{ $eyebrow }}</span>
    <span class="h-px w-6 bg-ink" aria-hidden="true"></span>
    <span class="text-xs font-medium text-ink-soft">{{ $title }}</span>
</h2>
