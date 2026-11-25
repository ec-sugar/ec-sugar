{{-- 安心の一行（パターンは 1 つ。区画にする前のトップと同じ見た目）。見出しのあるお約束だけ（絵柄を選んでいなければ送料の絵柄）。
     見出しのあるお約束が無ければ区画を出さない --}}
@if ($promises = array_filter($section->items, static fn (array $promise): bool => $promise['title'] !== null))
    <ul class="grid grid-cols-1 gap-6 border-y border-line py-7 sm:grid-cols-2 lg:grid-cols-4" data-section="{{ $section->id }}" data-test="promises">
        @foreach ($promises as $promise)
            <li class="flex min-w-0 items-center gap-3.5">
                <span class="flex size-11 shrink-0 items-center justify-center border border-ink">@include('theme-default::partials.icon', ['name' => $promise['icon'] ?? 'shipping', 'size' => 20, 'stroke' => 1.5])</span>
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
