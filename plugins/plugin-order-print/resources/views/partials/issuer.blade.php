{{-- 発行者（店）。$issuer（Issuer）・$logoUrl（ロゴの写真の URL か null） --}}
<div class="from" data-test="issuer">
    @if ($logoUrl)
        <img class="logo" src="{{ $logoUrl }}" alt="" data-test="issuer-logo">
    @endif
    <strong class="pre">{{ $issuer->name }}</strong>
    @if ($issuer->address)
        <div class="pre">{{ $issuer->address }}</div>
    @endif
    @if ($issuer->phone)
        <div>電話 {{ $issuer->phone }}</div>
    @endif
    @if ($issuer->registrationNumber)
        <div data-test="registration-number">登録番号 {{ $issuer->registrationNumber }}</div>
    @endif
</div>
