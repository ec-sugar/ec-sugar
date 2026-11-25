{{-- 新着「2 列の大」。店の人が商品を選んでいなければ、トップでは新着（$listings）を並べる --}}
@include('theme-kinari::partials.rail-large', ['cards' => $section['products'] ?: ($listings ?? []), 'eyebrow' => 'NEW ARRIVALS', 'title' => $section['heading'] ?? '新着', 'anchor' => 'new', 'test' => 'new-arrivals'])
