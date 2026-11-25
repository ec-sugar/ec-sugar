{!! $name !!} さん

@if (count($items) + $more + count($troubles) > 0)
EC-SUGAR の動作に問題があります。管理画面の「運営ダッシュボード」で、発生した場所と回数を確認してください。
@else
EC-SUGAR から、近いうちに確認してほしいことがあります。
@endif
@if (count($items) > 0)

■ エラー
@foreach ($items as $item)
・{{ ['http_error' => '画面や API のエラー', 'job_failed' => 'バックグラウンド処理の失敗', 'command_failed' => '定期の処理の失敗'][$item['kind']] ?? $item['kind'] }}@if ($item['shop'] !== null)（ショップ「{!! $item['shop'] !!}」）@endif

  {!! $item['exception'] !!}（{!! $item['location'] !!}）
@if ($item['added'] === null)
  {{ $item['count'] }} 回・最初 {{ $item['first_at'] }}
@else
  前回の通知から {{ $item['added'] }} 回（合計 {{ $item['count'] }} 回）・最初 {{ $item['first_at'] }}
@endif
@endforeach
@if ($more > 0)
・ほかに {{ $more }} 件あります（運営ダッシュボードで確認できます）
@endif
@endif
@if (count($troubles) > 0)

■ 要対応
@foreach ($troubles as $trouble)
・{!! $trouble !!}
@endforeach
@endif
@if (count($reminders) > 0)

■ 近いうちに
@foreach ($reminders as $reminder)
・{!! $reminder !!}
@endforeach
@endif

同じエラーが続けて起きても、通知は 1 時間に 1 通にまとめます。

{{ $adminUrl }}

このメールは送信専用です。
