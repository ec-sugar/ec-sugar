<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <title>{{ config('app.name') }} 管理</title>
    {{-- タブのアイコンは管理画面の殻にだけ（public/favicon.ico はショップにも配られるので EC-SUGAR の印を置かない） --}}
    <link rel="icon" type="image/png" href="/brand/admin-icon.png">
    <link rel="apple-touch-icon" href="/brand/admin-apple-touch-icon.png">
    @vite('resources/admin/main.js')
</head>
<body>
    <div id="app"></div>
    <noscript>管理画面は JavaScript を有効にしてお使いください。</noscript>
</body>
</html>
