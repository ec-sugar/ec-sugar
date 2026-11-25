<section data-test="rail" data-section="{{ $section->id }}">
    <h2>{{ $section['title'] ?? '新着' }}</h2>
    @foreach ($section['products'] as $card)
        <p data-test="rail-item">{{ $card->title }}</p>
    @endforeach
</section>
