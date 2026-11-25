{{--
    会員ランクが上がった知らせ（文の形。text/plain なので HTML として読まれない。& や < をそのまま見せるため、RankUpMail が値を HtmlString で渡す）
--}}
いつも {{ $text['shopName'] }} をご利用いただき、ありがとうございます。

会員ランクが「{{ $text['rankName'] }}」になりました。

@if ($text['benefits'] !== [])
このランクの特典:
@foreach ($text['benefits'] as $benefit)
・{{ $benefit }}
@endforeach

@endif
ランクは、直近 12 か月のお買い物の額で毎日更新しています。
今のランクと次のランクまでの額は、マイページの「会員ランク」でご覧いただけます。
{{ $text['pageUrl'] }}

――――――――――――――――――――
このメールは、{{ $text['shopName'] }} の会員の方に、会員ランクが変わったことをお知らせしています。
