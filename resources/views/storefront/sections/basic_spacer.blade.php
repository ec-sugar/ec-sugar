{{-- 核の共通の区画「余白・区切り」。パターンは space（余白）・line（線）。大きさは s・m・l（小さく・ふつう・大きく）。
     中身の欄が無いので、いつも出す。余白は読み上げない。線は <hr>（区切りとして読み上げる）。
     編集の画面のプレビューでは、余白が見えないと押して選べないので、点線の枠と「余白」の文字を出す --}}
<div @class(['sugar-section sugar-spacer', 'sugar-spacer--preview' => $preview ?? false]) data-section="{{ $section->id }}" data-section-type="basic_spacer" data-pattern="{{ $section->pattern }}" data-size="{{ $section['size'] ?? 'm' }}" @if ($section->pattern !== 'line') aria-hidden="true" @endif>
    @if ($section->pattern === 'line')
        <hr class="sugar-spacer__line">
    @elseif ($preview ?? false)
        <span class="sugar-spacer__label">余白</span>
    @endif
</div>
