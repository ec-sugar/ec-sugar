{{-- 安心の一行（パターンは 1 つ。区画にする前のトップと同じ見た目）。見出しのあるお約束だけ（絵柄を選んでいなければ送料の絵柄）。
     見出しのあるお約束が無ければ区画を出さない --}}
@if ($promises = array_filter($section->items, static fn (array $promise): bool => $promise['title'] !== null))
    <ul class="mx-auto grid w-full max-w-[1360px] grid-cols-1 gap-6 px-5 py-2 first:mt-18 sm:grid-cols-2 sm:px-8 lg:grid-cols-4" data-section="{{ $section->id }}" data-test="promises">
        @foreach ($promises as $promise)
            <li class="flex min-w-0 items-center justify-center gap-3.5">
                <span class="flex size-10 shrink-0 items-center justify-center">@include('theme-kinari::partials.promise-icon', ['icon' => $promise['icon'] ?? 'shipping'])</span>
                <span class="flex min-w-0 flex-col">
                    <span class="text-[13px] font-semibold [overflow-wrap:anywhere]">{{ $promise['title'] }}</span>
                    @if ($promise['body'])
                        <span class="text-xs text-ink-soft [overflow-wrap:anywhere]">{{ $promise['body'] }}</span>
                    @endif
                </span>
            </li>
        @endforeach
    </ul>
@endif
