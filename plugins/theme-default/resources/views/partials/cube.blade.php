{{-- 角砂糖ひとつ（飾り。意味は隣の文字で伝える）。$tone: mark（ロゴ）・inverse（黒い地のロゴ）・soft（写真の無い所）、$size は横幅（px） --}}
<svg aria-hidden="true" focusable="false" width="{{ $size ?? 28 }}" height="{{ round(($size ?? 28) * 1.1) }}" viewBox="0 0 100 110" stroke-linejoin="round"
     class="{{ match ($tone ?? 'soft') { 'mark' => 'stroke-ink', 'inverse' => 'stroke-canvas', default => 'stroke-cube' } }}" stroke-width="{{ ($tone ?? 'soft') === 'soft' ? 4 : 5 }}">
    <polygon points="50,4 94,29 50,54 6,29" class="{{ ($tone ?? 'soft') === 'inverse' ? 'fill-ink' : 'fill-canvas' }}" />
    <polygon points="6,29 50,54 50,105 6,80" class="{{ match ($tone ?? 'soft') { 'mark' => 'fill-line', 'inverse' => 'fill-ink-muted', default => 'fill-canvas' } }}" />
    <polygon points="94,29 50,54 50,105 94,80" class="{{ match ($tone ?? 'soft') { 'mark' => 'fill-ink-faint', 'inverse' => 'fill-ink-soft', default => 'fill-canvas' } }}" />
</svg>
