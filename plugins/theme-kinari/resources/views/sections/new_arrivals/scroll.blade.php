{{-- 新着「横に流れる」。店の人が商品を選んでいなければ、トップでは新着（$listings）を 8 点まで並べる --}}
@include('theme-kinari::partials.rail-scroll', ['cards' => $section['products'] ?: array_slice($listings ?? [], 0, 8), 'eyebrow' => 'NEW ARRIVALS', 'title' => $section['heading'] ?? '新着', 'anchor' => 'new', 'test' => 'new-arrivals'])
