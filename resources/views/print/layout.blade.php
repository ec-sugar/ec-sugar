{{--
    印刷用のページの土台（核。App の納品書・領収書など。ADR 0051 の作って決めたこと「注文の書類」）。
    ブラウザの「印刷」で A4 の紙か PDF にする。スクリプトは使わず、見た目はこの <style> だけ（CSP は PrintPage が nonce つきの style だけを許す）。
    使い方（App のビュー）:
        @extends('print.layout')
        @section('toolbar') …画面でだけ出す欄（宛名の入力など。印刷には出ない）… @endsection
        @section('content') <section class="sheet">…1 枚分…</section> … @endsection
    - $nonce: PrintPage::nonce($request)、$title: タブの題
    - 1 枚は .sheet。何枚か続けると、印刷では 1 枚ごとに改ページする
    - 部品の印: .doc-head（題と番号）・.doc-title・.doc-meta・.parties（宛先と発行者の 2 列）・.lines（明細の表）・.totals（合計の表）・
      .amount（領収の金額の枠）・.note（小さな注記）・.stamp（再発行などの札）・.logo（ロゴの画像）・.field（画面の欄）
--}}
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <style nonce="{{ $nonce }}">
        @page { size: A4; margin: 12mm; }
        * { box-sizing: border-box; }
        html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body {
            margin: 0;
            background: #f4f4f5;
            color: #18181b;
            font-family: "Hiragino Kaku Gothic ProN", "Hiragino Sans", "Yu Gothic", "YuGothic", "Meiryo", "Noto Sans JP", sans-serif;
            font-size: 10.5pt;
            line-height: 1.6;
        }
        .toolbar {
            max-width: 210mm;
            margin: 16px auto 0;
            padding: 16px 20px;
            border: 1px solid #e4e4e7;
            border-radius: 12px;
            background: #fff;
            font-size: 14px;
        }
        .toolbar p { margin: 0 0 8px; }
        .toolbar form { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; margin: 12px 0 0; }
        .field { display: flex; flex-direction: column; gap: 4px; font-size: 13px; }
        .field input { min-width: 16em; min-height: 40px; padding: 0 10px; border: 1px solid #a1a1aa; border-radius: 8px; font: inherit; font-size: 15px; }
        .toolbar button { min-height: 40px; padding: 0 18px; border: 0; border-radius: 8px; background: #1e3a8a; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        .error { color: #b91c1c; font-weight: 600; }
        .hint { color: #52525b; font-size: 13px; }
        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 16px auto;
            padding: 16mm 16mm 14mm;
            background: #fff;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.12);
        }
        .doc-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 8mm; }
        .doc-title { margin: 0; font-size: 22pt; font-weight: 700; letter-spacing: 0.4em; }
        .doc-meta { margin: 0; text-align: right; font-size: 9.5pt; }
        .doc-meta div { white-space: nowrap; }
        .stamp { display: inline-block; margin-left: 12px; padding: 2px 10px; border: 2px solid #b91c1c; border-radius: 4px; color: #b91c1c; font-size: 12pt; font-weight: 700; letter-spacing: 0.1em; vertical-align: middle; }
        .parties { display: flex; justify-content: space-between; gap: 12mm; margin-bottom: 8mm; }
        .parties > div { flex: 1; }
        .to { font-size: 14pt; border-bottom: 1px solid #18181b; padding-bottom: 2px; min-height: 1.8em; }
        .from { text-align: right; font-size: 9.5pt; }
        .from strong { font-size: 11pt; }
        .logo { display: block; max-width: 48mm; max-height: 18mm; margin: 0 0 6px auto; object-fit: contain; }
        .pre { white-space: pre-line; }
        table { width: 100%; border-collapse: collapse; }
        .lines th, .lines td { padding: 6px 8px; border-bottom: 1px solid #d4d4d8; vertical-align: top; }
        .lines th { border-bottom: 2px solid #18181b; font-size: 9pt; font-weight: 600; text-align: left; }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .totals { width: auto; margin: 6mm 0 0 auto; min-width: 70mm; }
        .totals th, .totals td { padding: 4px 8px; }
        .totals th { font-weight: 400; text-align: left; }
        .totals .grand th, .totals .grand td { border-top: 2px solid #18181b; font-size: 12pt; font-weight: 700; }
        .amount { margin: 6mm 0; padding: 4mm 6mm; border: 2px solid #18181b; font-size: 20pt; font-weight: 700; text-align: center; font-variant-numeric: tabular-nums; }
        .amount small { font-size: 10pt; font-weight: 400; }
        .section-title { margin: 8mm 0 2mm; font-size: 10.5pt; font-weight: 700; }
        .note { margin: 4mm 0 0; color: #3f3f46; font-size: 8.5pt; }
        @media print {
            body { background: none; }
            .toolbar { display: none; }
            .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; break-after: page; }
            .sheet:last-of-type { break-after: auto; }
        }
    </style>
</head>
<body>
    @hasSection('toolbar')
        <div class="toolbar" data-test="print-toolbar">
            @yield('toolbar')
            <p class="hint">ブラウザの「印刷」（Windows は Ctrl＋P、Mac は ⌘＋P）で紙に印刷できます。PDF で保存するときは、印刷の画面で送信先（プリンター）を「PDF に保存」にしてください。この案内は印刷されません。</p>
        </div>
    @endif
    @yield('content')
</body>
</html>
