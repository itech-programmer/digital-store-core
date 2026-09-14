<?php

declare(strict_types=1);

$count = max(1, (int) ($argv[1] ?? 20));
$base = rtrim((string) ($argv[2] ?? 'http://localhost:8080'), '/');
$admin = getenv('ADMIN_TOKEN') ?: 'dev-admin-token';

function httpJson(string $method, string $url, ?array $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    $hdrs = array_merge(['Accept: application/json', 'Content-Type: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $hdrs,
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => is_string($raw) ? json_decode($raw, true) : null];
}

echo "Burst {$count} orders against {$base}\n";

for ($i = 1; $i <= $count; $i++) {
    $create = httpJson('POST', $base.'/api/v1/orders', ['sku' => 'KEY-GTA5']);
    if ($create['code'] !== 201) {
        fwrite(STDERR, "create failed #{$i}: HTTP {$create['code']}\n");
        continue;
    }

    $orderId = $create['body']['data']['id'] ?? null;
    $amount = $create['body']['data']['amount'] ?? 1990;
    $pay = httpJson('POST', $base.'/api/v1/webhook/payment', [
        'event_id' => 'evt_burst_'.$i.'_'.bin2hex(random_bytes(4)),
        'order_id' => $orderId,
        'status' => 'paid',
        'amount' => $amount,
        'currency' => 'RUB',
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);

    echo sprintf("#%02d order=%s pay_http=%d\n", $i, $orderId, $pay['code']);
}

$progress = httpJson('GET', $base.'/api/v1/admin/delivery-progress', null, [
    'X-Admin-Token: '.$admin,
]);

echo "delivery-progress HTTP {$progress['code']}:\n";
echo json_encode($progress['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
