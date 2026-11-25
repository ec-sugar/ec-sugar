{{-- 住所の欄（ご依頼主とお届け先で使い回す）。$form、$prefix（buyer・recipient）。郵便番号を入れると核の部品が住所を入れる --}}
<div class="grid grid-cols-1 gap-5 sm:grid-cols-2" data-postal-group>
    @include('theme-default::partials.field', ['key' => "{$prefix}.name", 'label' => 'お名前', 'autocomplete' => $prefix === 'buyer' ? 'name' : 'shipping name', 'maxlength' => 60])
    @include('theme-default::partials.field', ['key' => "{$prefix}.phone", 'label' => '電話番号', 'type' => 'tel', 'autocomplete' => $prefix === 'buyer' ? 'tel' : 'shipping tel', 'inputmode' => 'tel', 'hint' => 'ハイフンは無くてもかまいません'])
    @include('theme-default::partials.field', ['key' => "{$prefix}.postal", 'label' => '郵便番号', 'autocomplete' => $prefix === 'buyer' ? 'postal-code' : 'shipping postal-code', 'inputmode' => 'numeric', 'maxlength' => 8, 'postal' => 'code', 'hint' => '7 桁を入力すると、住所が自動で入ります'])
    <label for="{{ $form->id("{$prefix}.prefecture") }}" class="flex flex-col gap-1.5 text-[13px]">
        <span>都道府県</span>
        <select id="{{ $form->id("{$prefix}.prefecture") }}" name="{{ $form->name("{$prefix}.prefecture") }}" autocomplete="{{ $prefix === 'buyer' ? 'address-level1' : 'shipping address-level1' }}"
                @if ($form->error("{$prefix}.prefecture")) aria-invalid="true" @endif
                class="h-12 rounded-xl border border-line bg-canvas px-3 text-sm text-ink aria-invalid:border-sold" data-postal-prefecture data-test="{{ $form->id("{$prefix}.prefecture") }}">
            <option value="">選んでください</option>
            @foreach ($form->prefectures() as $prefecture)
                <option value="{{ $prefecture }}" @selected($form->value("{$prefix}.prefecture") === $prefecture)>{{ $prefecture }}</option>
            @endforeach
        </select>
        @if ($form->error("{$prefix}.prefecture"))
            <span class="text-xs text-sold" role="alert">{{ $form->error("{$prefix}.prefecture") }}</span>
        @endif
    </label>
    @include('theme-default::partials.field', ['key' => "{$prefix}.city", 'label' => '市区町村', 'autocomplete' => $prefix === 'buyer' ? 'address-level2' : 'shipping address-level2', 'maxlength' => 50, 'postal' => 'city'])
    @include('theme-default::partials.field', ['key' => "{$prefix}.line1", 'label' => '町名・番地', 'autocomplete' => $prefix === 'buyer' ? 'address-line1' : 'shipping address-line1', 'maxlength' => 100, 'postal' => 'line1'])
    <div class="sm:col-span-2">
        @include('theme-default::partials.field', ['key' => "{$prefix}.line2", 'label' => '建物名・部屋番号', 'autocomplete' => $prefix === 'buyer' ? 'address-line2' : 'shipping address-line2', 'maxlength' => 100, 'optional' => true])
    </div>
    <p class="text-xs text-ink-soft sm:col-span-2" data-postal-status aria-live="polite"></p>
</div>
