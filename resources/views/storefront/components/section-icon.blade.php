{{--
    核の共通の区画「特長」の絵。使い方: <x-storefront::section-icon :name="$block['icon']" class="…" />
    - 絵は核が持つ決まった数の Lucide の形だけ（App\Core\Section\SectionIcons）。知らない名前・空なら何も描かない
    - 線の色は文字の色（currentColor）。大きさはテーマが CSS で決める（既定は 1em 四方。属性 class などは svg に付く）
    - 飾りなので読み上げない（意味は隣の見出しで伝える）
--}}
@props(['name' => null])
@if (($nodes = \App\Core\Section\SectionIcons::nodes(is_string($name) ? $name : null)) !== null)
    <svg {{ $attributes->merge(['class' => 'sugar-icon']) }} viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" data-icon="{{ $name }}">
        @foreach ($nodes as [$tag, $a])
            @switch($tag)
                @case('path')
                    <path d="{{ $a['d'] }}" />
                    @break
                @case('circle')
                    <circle cx="{{ $a['cx'] }}" cy="{{ $a['cy'] }}" r="{{ $a['r'] }}" />
                    @break
                @case('rect')
                    <rect x="{{ $a['x'] }}" y="{{ $a['y'] }}" width="{{ $a['width'] }}" height="{{ $a['height'] }}" rx="{{ $a['rx'] ?? 0 }}" />
                    @break
                @case('polyline')
                    <polyline points="{{ $a['points'] }}" />
                    @break
            @endswitch
        @endforeach
    </svg>
@endif
