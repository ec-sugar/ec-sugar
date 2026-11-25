{{-- 安心の一行（パターンごとの部品から読む）。$class（並べ方）。見出しのあるお約束だけ（絵柄を選んでいなければ送料の絵柄） --}}
@if ($promises = array_filter($section->items, static fn (array $promise): bool => $promise['title'] !== null))
    <ul class="{{ $class }}" data-test="promises" data-pattern="{{ $section->pattern }}" data-section="{{ $section->id }}">
        @foreach ($promises as $promise)
            <li class="flex items-center gap-4">
                <span class="shrink-0">@include('theme-botanica::partials.promise-icon', ['icon' => $promise['icon'] ?? 'shipping'])</span>
                <div><p class="text-sm">{{ $promise['title'] }}</p>
                    @if ($promise['body'])
                        <p class="mt-1 text-xs text-ink-soft">{{ $promise['body'] }}</p>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
@endif
