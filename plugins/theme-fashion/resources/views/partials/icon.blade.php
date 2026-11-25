{{-- 線の絵柄（飾り。意味は隣の文字か aria-label で伝える）。$name: menu・search・account・heart・bag・arrow --}}
<svg aria-hidden="true" focusable="false" width="{{ $size ?? 20 }}" height="{{ $size ?? 20 }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
    @switch($name)
        @case('menu')
            <path d="M3 7h18M3 12h18M3 17h18" />
            @break
        @case('search')
            <circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" />
            @break
        @case('account')
            <circle cx="12" cy="8" r="4" /><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6" />
            @break
        @case('heart')
            <path d="M12 20s-7-4.5-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.5-7 10-7 10z" />
            @break
        @case('bag')
            <path d="M5 8h14l-1 12H6L5 8z" /><path d="M9 8V6a3 3 0 0 1 6 0v2" />
            @break
        @default
            <path d="M4 12h16M14 6l6 6-6 6" />
    @endswitch
</svg>
