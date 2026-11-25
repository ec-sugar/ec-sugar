{{-- 入力欄。$form（CheckoutForm）、$key（buyer.name のように）、$label、任意: $type・$autocomplete・$inputmode・$hint・$maxlength・$optional・$postal（code・city・line1。郵便番号から住所の自動入力の印） --}}
<label for="{{ $form->id($key) }}" class="flex flex-col gap-1.5 text-[13px]">
    <span>{{ $label }} @if ($optional ?? false)<span class="text-xs text-ink-soft">任意</span>@endif</span>
    <input id="{{ $form->id($key) }}" name="{{ $form->name($key) }}" type="{{ $type ?? 'text' }}" value="{{ $form->value($key) }}"
           @if (isset($autocomplete)) autocomplete="{{ $autocomplete }}" @endif
           @if (isset($inputmode)) inputmode="{{ $inputmode }}" @endif
           @if (isset($maxlength)) maxlength="{{ $maxlength }}" @endif
           @if (($postal ?? null) === 'code') data-postal-code @elseif (($postal ?? null) === 'city') data-postal-city @elseif (($postal ?? null) === 'line1') data-postal-line1 @endif
           @if ($form->error($key)) aria-invalid="true" aria-describedby="{{ $form->id($key) }}-error" @endif
           class="h-12 rounded-xl border border-line bg-canvas px-3 text-sm text-ink aria-invalid:border-sold focus:border-ink focus:outline-none" data-test="{{ $form->id($key) }}">
    @if ($form->error($key))
        <span id="{{ $form->id($key) }}-error" class="text-xs text-sold" role="alert">{{ $form->error($key) }}</span>
    @elseif (isset($hint))
        <span class="text-xs text-ink-soft">{{ $hint }}</span>
    @endif
</label>
