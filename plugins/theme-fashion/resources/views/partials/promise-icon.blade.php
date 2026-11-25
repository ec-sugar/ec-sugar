{{-- 安心の一行の絵柄（飾り。意味は隣の文字で伝える）。$icon は shipping・returns・secure・support のどれか --}}
<svg aria-hidden="true" focusable="false" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
    @switch($icon)
        @case('shipping')
            <path d="M2 6h12v10H2zM14 10h4l3 3v3h-7z" /><circle cx="6" cy="17.5" r="1.8" /><circle cx="17" cy="17.5" r="1.8" />
            @break
        @case('returns')
            <path d="M4 12a8 8 0 1 0 2.5-5.8" /><path d="M4 4v4h4" />
            @break
        @case('secure')
            <rect x="5" y="10" width="14" height="10" rx="1.5" /><path d="M8 10V7a4 4 0 0 1 8 0v3" />
            @break
        @default
            <path d="M4 14v-2a8 8 0 0 1 16 0v2" /><rect x="3" y="13" width="4" height="6" rx="1" /><rect x="17" y="13" width="4" height="6" rx="1" />
    @endswitch
</svg>
