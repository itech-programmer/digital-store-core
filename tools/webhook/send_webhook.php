<?php

declare(strict_types=1);

$opts = getopt('', ['order-id:', 'event-id:', 'status:', 'amount:', 'currency::', 'url::']);

$orderId = $opts['order-id'] ?? null;
$eventId = $opts['event-id'] ?? ('evt_' . bin2hex(random_bytes(4)));
$status = $opts['status'] ?? 'paid';
$amount = isset($opts['amount']) ? (int) $opts['amount'] : 500;
$currency = $opts['currency'] ?? 'RUB';
$url = $opts['url'] ?? 'http://localhost:8080/api/v1/webhook/payment';

if ($orderId === null) {
    fwrite(STDERR, "Ошибка: нужен --order-id\n");
    exit(1);
}

$payload = [
    'event_id' => $eventId,
    'order_id' => $orderId,
    'status' => $status,
    'amount' => $amount,
    'currency' => $currency,
    'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);

$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($body === false) {
    fwrite(STDERR, "Ошибка cURL: {$err}\n");
    exit(1);
}

echo "HTTP {$code}\n{$body}\n";
exit($code >= 200 && $code < 300 ? 0 : 1);
