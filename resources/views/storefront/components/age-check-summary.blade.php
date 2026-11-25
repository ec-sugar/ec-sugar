{{--
    年齢の確認の答え（ADR 0077）。核の部品で、テーマの注文の確認の画面が注文の項目の後に置く。
    $answer は注文の入力の答え（AgeAnswer。年齢の要る品が無ければ null で、何も出さない）。生年月日は持っていないので出さない
--}}
@props(['answer' => null])
@if ($answer instanceof \App\Core\Plugin\AgeCheck\AgeAnswer)
    <div class="sugar-order-fields-summary" data-test="age-check-summary">
        <p class="sugar-order-fields-summary__title">年齢の確認</p>
        <p class="sugar-order-fields-summary__value">{{ $answer->age }} 歳以上であることを確認しました{{ $answer->recipient ? '（贈り物を受け取る方も含む）' : '' }}。</p>
    </div>
@endif
