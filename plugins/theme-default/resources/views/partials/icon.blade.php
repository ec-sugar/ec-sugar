{{-- 線の絵柄（飾り。意味は隣の文字か aria-label で伝える）。$name: search・bag・account・arrow・check・lock・shipping・returns・secure・support --}}
<svg aria-hidden="true" focusable="false" width="{{ $size ?? 18 }}" height="{{ $size ?? 18 }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="{{ $stroke ?? 1.8 }}" stroke-linecap="round" stroke-linejoin="round">
    @switch($name)
        @case('search')
            <circle cx="11" cy="11" r="6.5" /><path d="M16 16l4.5 4.5" />
            @break
        @case('bag')
            <path d="M5 8h14l-1.2 12H6.2z" /><path d="M9 8V6.5a3 3 0 0 1 6 0V8" />
            @break
        @case('account')
            <circle cx="12" cy="8" r="3.8" /><path d="M4.5 20.5c1.4-3.8 4.2-5.6 7.5-5.6s6.1 1.8 7.5 5.6" />
            @break
        @case('check')
            <path d="M5 12.5l4.5 4.5L19 7.5" />
            @break
        @case('lock')
        @case('secure')
            <rect x="5" y="10.5" width="14" height="10" rx="1.5" /><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5" />
            @break
        @case('shipping')
            <path d="M2 6h12v10H2zM14 10h4l3 3v3h-7z" /><circle cx="6" cy="17.5" r="1.8" /><circle cx="17" cy="17.5" r="1.8" />
            @break
        @case('returns')
            <path d="M4 12a8 8 0 1 0 2.5-5.8" /><path d="M4 4v4h4" />
            @break
        @case('support')
            <path d="M4 14v-2a8 8 0 0 1 16 0v2" /><rect x="3" y="13" width="4" height="6" rx="1" /><rect x="17" y="13" width="4" height="6" rx="1" />
            @break
        @default
            <path d="M4 12h15M14 7l5 5-5 5" />
    @endswitch
</svg>
