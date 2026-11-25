{{--
    フッターのリンク（ADR 0034 の 4・5）。テーマはフッターの案内に 1 行 <x-storefront.app-links :apps="$apps" class="…" /> を置くだけ。
    class などの属性は各リンクに付く（テーマのフッターの見た目に合わせる）。出すのは要るときだけ:
    外部送信に関する公表（外へ送る App を使っている店）・Cookie の設定（同意が要る App を使っている店。帯のある画面では核の部品が帯を開き直す）
--}}
@props(['apps'])
@if ($apps->disclosureUrl)
    <a href="{{ $apps->disclosureUrl }}" {{ $attributes->merge(['data-test' => 'disclosure-link']) }}>外部送信について</a>
@endif
@if ($apps->consentUrl)
    <a href="{{ $apps->consentUrl }}" data-sugar-consent-open {{ $attributes->merge(['data-test' => 'consent-link']) }}>Cookie の設定</a>
@endif
