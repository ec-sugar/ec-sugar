<?php

declare(strict_types=1);

/*
 * ブラウザのテスト用の偽の Stripe。PHP の組み込みのサーバーで動かす（playwright.config.js）。
 * 本物の Stripe の代わりに支払いの画面を作って答え、支払いの画面（/pay/{id}）を出す。「支払う」で署名した通知（Webhook）を店へ送る。
 * 本番では使えない（STRIPE_API_BASE は本番で断る）。
 * 状態は一時ディレクトリのファイルに置く（組み込みのサーバーは複数の処理で動くので、メモリでは分け合えない）。
 */

$dir = sys_get_temp_dir().'/ec-sugar-fake-stripe-'.(getenv('E2E_STRIPE_PORT') ?: '8092');
@mkdir($dir, 0700, true);
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$base = 'http://'.($_SERVER['HTTP_HOST'] ?? '127.0.0.1');

$json = static function (array $body, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

// 起動の確認（Playwright が待つ）
if ($path === '/v1/up') {
    $json(['ok' => true]);

    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/v1/checkout/sessions') {
    if (! str_starts_with($_SERVER['HTTP_AUTHORIZATION'] ?? '', 'Bearer sk_test_')) {
        $json(['error' => ['type' => 'invalid_request_error', 'code' => 'api_key_invalid', 'message' => 'Invalid API Key']], 401);

        return;
    }

    // 同じ冪等キーには同じ画面（本物と同じ）
    $key = preg_replace('/[^A-Za-z0-9_-]/', '', $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
    if ($key !== '' && is_file("{$dir}/key-{$key}")) {
        $json(json_decode((string) file_get_contents("{$dir}/key-{$key}"), true));

        return;
    }

    parse_str((string) file_get_contents('php://input'), $params);
    $total = 0;
    foreach ($params['line_items'] ?? [] as $item) {
        $total += (int) $item['price_data']['unit_amount'] * (int) $item['quantity'];
    }
    $id = 'cs_test_'.bin2hex(random_bytes(12));
    $session = [
        'id' => $id,
        'object' => 'checkout.session',
        'url' => "{$base}/pay/{$id}",
        'amount_total' => $total,
        'currency' => 'jpy',
        'expires_at' => (int) ($params['expires_at'] ?? time() + 3600),
        'status' => 'open',
        'payment_status' => 'unpaid',
        'client_reference_id' => $params['client_reference_id'] ?? null,
        'metadata' => $params['metadata'] ?? [],
        'success_url' => $params['success_url'] ?? null,
        'cancel_url' => $params['cancel_url'] ?? null,
    ];
    file_put_contents("{$dir}/{$id}.json", json_encode($session));
    if ($key !== '') {
        file_put_contents("{$dir}/key-{$key}", json_encode($session));
    }
    $json($session);

    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#\A/pay/(cs_test_[a-f0-9]{24})\z#', $path, $m) === 1 && is_file("{$dir}/{$m[1]}.json")) {
    $session = json_decode((string) file_get_contents("{$dir}/{$m[1]}.json"), true);
    $h = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>偽の Stripe</title>'
        .'<h1>偽の Stripe の支払いの画面</h1>'
        .'<p data-test="fake-stripe-amount">¥'.number_format((int) $session['amount_total']).'</p>'
        .'<p data-test="fake-stripe-reference">'.$h($session['client_reference_id']).'</p>'
        .'<a data-test="fake-stripe-back" href="'.$h($session['cancel_url']).'">戻る</a> '
        .'<a data-test="fake-stripe-done" href="/pay/'.$h($session['id']).'/complete">支払う</a>';

    return;
}

// 返金（同じ冪等キーには同じ返金）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/v1/refunds') {
    $key = preg_replace('/[^A-Za-z0-9_-]/', '', $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
    if ($key !== '' && is_file("{$dir}/key-{$key}")) {
        $json(json_decode((string) file_get_contents("{$dir}/key-{$key}"), true));

        return;
    }
    parse_str((string) file_get_contents('php://input'), $params);
    $refund = ['id' => 're_test_'.bin2hex(random_bytes(8)), 'object' => 'refund', 'amount' => (int) ($params['amount'] ?? 0), 'payment_intent' => $params['payment_intent'] ?? null, 'status' => 'succeeded'];
    if ($key !== '') {
        file_put_contents("{$dir}/key-{$key}", json_encode($refund));
    }
    $json($refund);

    return;
}

// 支払いの画面の問い合わせと、閉じる
if (preg_match('#\A/v1/checkout/sessions/(cs_test_[a-f0-9]{24})(/expire)?\z#', $path, $m) === 1 && is_file("{$dir}/{$m[1]}.json")) {
    $session = json_decode((string) file_get_contents("{$dir}/{$m[1]}.json"), true);
    if (($m[2] ?? '') === '/expire' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $session['status'] = 'expired';
        file_put_contents("{$dir}/{$m[1]}.json", json_encode($session));
    }
    $json($session);

    return;
}

// 「支払う」: 本物と同じ形の通知（checkout.session.completed）を、店の Webhook の署名の鍵で署名して店へ送り、戻り先へ移る
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('#\A/pay/(cs_test_[a-f0-9]{24})/complete\z#', $path, $m) === 1 && is_file("{$dir}/{$m[1]}.json")) {
    $session = json_decode((string) file_get_contents("{$dir}/{$m[1]}.json"), true);
    $event = (string) json_encode([
        'id' => 'evt_'.bin2hex(random_bytes(12)),
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'livemode' => false,
        'created' => time(),
        'data' => ['object' => ['payment_status' => 'paid', 'payment_intent' => 'pi_test_'.bin2hex(random_bytes(8))] + $session],
    ]);
    $time = time();
    $signature = "t={$time},v1=".hash_hmac('sha256', "{$time}.{$event}", (string) getenv('E2E_WEBHOOK_SECRET'));
    // 送信先は戻り先と同じ店。*.localhost の名前を引けない環境（CI の Linux）があるので、127.0.0.1 へつなぎ、店の名前は Host で渡す
    $parts = parse_url((string) $session['success_url']);
    $hostHeader = $parts['host'].(isset($parts['port']) ? ":{$parts['port']}" : '');
    $webhook = 'http://127.0.0.1'.(isset($parts['port']) ? ":{$parts['port']}" : '').'/webhooks/stripe';
    $sent = @file_get_contents($webhook, false, stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Host: {$hostHeader}\r\nContent-Type: application/json\r\nStripe-Signature: {$signature}\r\n",
        'content' => $event,
        'ignore_errors' => true,
        'timeout' => 10,
    ]]));
    file_put_contents("{$dir}/{$m[1]}.json", json_encode(['status' => 'complete', 'payment_status' => 'paid'] + $session));
    if ($sent === false || ! str_contains($http_response_header[0] ?? '', ' 200 ')) {
        http_response_code(502);
        echo '店が通知を受け取らなかった: '.htmlspecialchars($http_response_header[0] ?? '（つながらない）');

        return;
    }
    header('Location: '.$session['success_url'], true, 303);

    return;
}

$json(['error' => ['type' => 'invalid_request_error', 'message' => 'Unrecognized request URL']], 404);
