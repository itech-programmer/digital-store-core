<?php

declare(strict_types=1);

$countHint = <<<'TXT'
Stage2 demo: multi-item order + pay (partial/refund depends on stock).
Usage (repo root, API up, migrate:fresh --seed):
  php tools/scenarios/stage2_partial.php [base=http://localhost:8080]

Optional: wipe one SKU stock first so one line refunds:
  docker compose exec store-api php artisan tinker --execute="App\Models\Catalog\ProductKey::where('sku','SUB-SPOTIFY-1M')->delete();"
TXT;

$base = rtrim((string) ($argv[1] ?? 'http://localhost:8080'), '/');
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
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => is_string($raw) ? json_decode($raw, true) : null];
}

echo $countHint."\n\n";

$create = httpJson('POST', $base.'/api/v1/orders', [
    'items' => [
        ['sku' => 'KEY-CS2-PRIME', 'qty' => 1],
        ['sku' => 'KEY-GTA5', 'qty' => 1],
        ['sku' => 'SUB-SPOTIFY-1M', 'qty' => 1],
    ],
]);

if ($create['code'] !== 201) {
    fwrite(STDERR, 'create failed: HTTP '.$create['code'].' '.json_encode($create['body'])."\n");
    exit(1);
}

$orderId = $create['body']['data']['id'] ?? null;
$amount = $create['body']['data']['amount'] ?? 0;
echo "created order={$orderId} amount={$amount}\n";

$pay = httpJson('POST', $base.'/api/v1/webhook/payment', [
    'event_id' => 'evt_stage2_'.bin2hex(random_bytes(4)),
    'order_id' => $orderId,
    'status' => 'paid',
    'amount' => $amount,
    'currency' => 'RUB',
    'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
]);
echo "pay HTTP {$pay['code']}\n";

usleep(500_000);

$show = httpJson('GET', $base.'/api/v1/orders/'.$orderId);
echo "order HTTP {$show['code']}:\n";
echo json_encode($show['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";

$reconcile = httpJson('GET', $base.'/api/v1/admin/reconcile', null, [
    'X-Admin-Token: '.$admin,
]);
echo "reconcile HTTP {$reconcile['code']}:\n";
echo json_encode($reconcile['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
