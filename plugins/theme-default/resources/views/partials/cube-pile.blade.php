{{-- 角砂糖の積み木（ヒーローの写真が無いときの絵。飾り）。大きい角砂糖が核、まわりの小さい角砂糖がプラグイン --}}
<svg aria-hidden="true" focusable="false" viewBox="0 0 400 300" class="h-auto w-full max-w-[440px] stroke-cube" stroke-width="1.3" stroke-linejoin="round">
    @foreach ([[150, 50, 1], [106, 75, 1], [194, 75, 1], [150, 100, 1], [106, 24, 1], [318, 168, 0.46], [46, 190, 0.38], [290, 22, 0.3]] as [$x, $y, $scale])
        <g transform="translate({{ $x }} {{ $y }}) scale({{ $scale }})">
            <polygon points="50,4 94,29 50,54 6,29" class="fill-canvas" />
            <polygon points="6,29 50,54 50,105 6,80" class="fill-cube-left" />
            <polygon points="94,29 50,54 50,105 94,80" class="fill-cube-right" />
        </g>
    @endforeach
</svg>
