<?php

declare(strict_types=1);

$port = (int) getenv('PORT');
$errorRate = (float) getenv('ERROR_RATE');
$timeoutRate = (float) getenv('TIMEOUT_RATE');
$timeoutSeconds = (int) getenv('TIMEOUT_SECONDS');
$storeFile = sys_get_temp_dir().'/supplier_stub_'.$port.'.json';

function loadStore(string $file): array
{
    if (! is_file($file)) {
        return [];
    }

    $data = json_decode((string) file_get_contents($file), true);

    return is_array($data) ? $data : [];
}

function saveStore(string $file, array $store): void
{
    file_put_contents($file, json_encode($store), LOCK_EX);
}

function randomCode(): string
{
    $chunks = [];
    for ($i = 0; $i < 3; $i++) {
        $chunks[] = strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    }

    return implode('-', $chunks);
}

function jsonResponse(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($uri === '/health' && $method === 'GET') {
    jsonResponse(200, ['status' => 'ok', 'port' => $port]);
    exit;
}

if ($uri !== '/issue' || $method !== 'POST') {
    jsonResponse(404, ['error' => 'not_found']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (! is_array($body)) {
    jsonResponse(400, ['error' => 'invalid_json']);
    exit;
}

$requestId = $body['request_id'] ?? null;

if (! is_string($requestId) || $requestId === ''
    || ! is_string($body['sku'] ?? null)
    || ! is_string($body['order_id'] ?? null)) {
    jsonResponse(400, ['error' => 'missing_fields']);
    exit;
}

$store = loadStore($storeFile);

if (isset($store[$requestId])) {
    $saved = $store[$requestId];
    if (($saved['status'] ?? '') === 'ok') {
        jsonResponse(200, [
            'status' => 'ok',
            'request_id' => $requestId,
            'code' => $saved['code'],
        ]);
        exit;
    }

    jsonResponse(502, [
        'status' => 'error',
        'request_id' => $requestId,
        'reason' => $saved['reason'] ?? 'supplier_error',
    ]);
    exit;
}

if ($timeoutRate > 0 && (mt_rand() / mt_getrandmax()) < $timeoutRate) {
    sleep(max(1, $timeoutSeconds));
    jsonResponse(504, ['status' => 'error', 'reason' => 'timeout']);
    exit;
}

if ($errorRate > 0 && (mt_rand() / mt_getrandmax()) < $errorRate) {
    $reason = 'supplier_unavailable';
    $store[$requestId] = ['status' => 'error', 'reason' => $reason];
    saveStore($storeFile, $store);
    jsonResponse(502, ['status' => 'error', 'request_id' => $requestId, 'reason' => $reason]);
    exit;
}

$code = randomCode();
$store[$requestId] = ['status' => 'ok', 'code' => $code];
saveStore($storeFile, $store);

jsonResponse(200, [
    'status' => 'ok',
    'request_id' => $requestId,
    'code' => $code,
]);
