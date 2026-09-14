<?php

declare(strict_types=1);

$port = (int) getenv('PORT');
$errorRate = (float) getenv('ERROR_RATE');
$timeoutRate = (float) getenv('TIMEOUT_RATE');
$timeoutSeconds = (int) getenv('TIMEOUT_SECONDS');
$duplicateCodeRate = (float) getenv('DUPLICATE_CODE_RATE');
$wrongCodeRate = (float) getenv('WRONG_CODE_RATE');
$lieErrorRate = (float) getenv('LIE_ERROR_RATE');
$rateLimitPerMinute = (int) getenv('RATE_LIMIT_PER_MINUTE');
$storeFile = sys_get_temp_dir().'/supplier_stub_'.$port.'.json';
$rateFile = sys_get_temp_dir().'/supplier_stub_rate_'.$port.'.json';

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

function jsonResponse(int $status, array $payload, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $name => $value) {
        header($name.': '.$value);
    }
    echo json_encode($payload, JSON_THROW_ON_ERROR);
}

function findExistingCode(array $store): ?string
{
    foreach ($store as $entry) {
        if (($entry['status'] ?? '') === 'ok' && is_string($entry['code'] ?? null) && $entry['code'] !== '') {
            return $entry['code'];
        }
    }

    return null;
}

function enforceRateLimit(string $rateFile, int $limitPerMinute): ?int
{
    if ($limitPerMinute <= 0) {
        return null;
    }

    $now = microtime(true);
    $windowStart = $now - 60.0;
    $hits = loadStore($rateFile);
    if (! isset($hits['ts']) || ! is_array($hits['ts'])) {
        $hits = ['ts' => []];
    }

    $hits['ts'] = array_values(array_filter(
        $hits['ts'],
        static fn ($t) => is_numeric($t) && (float) $t >= $windowStart
    ));

    if (count($hits['ts']) >= $limitPerMinute) {
        $oldest = (float) min($hits['ts']);
        $retryAfter = max(1, (int) ceil(60.0 - ($now - $oldest)));
        saveStore($rateFile, $hits);

        return $retryAfter;
    }

    $hits['ts'][] = $now;
    saveStore($rateFile, $hits);

    return null;
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($uri === '/health' && $method === 'GET') {
    jsonResponse(200, ['status' => 'ok', 'port' => $port]);
    exit;
}

if (preg_match('#^/issuances/([^/]+)$#', $uri, $matches) === 1 && $method === 'GET') {
    $requestId = rawurldecode($matches[1]);
    $store = loadStore($storeFile);
    if (! isset($store[$requestId]) || ($store[$requestId]['status'] ?? '') !== 'ok') {
        jsonResponse(404, ['error' => 'not_found']);
        exit;
    }

    jsonResponse(200, [
        'request_id' => $requestId,
        'status' => 'ok',
        'code' => $store[$requestId]['code'],
    ]);
    exit;
}

if ($uri !== '/issue' || $method !== 'POST') {
    jsonResponse(404, ['error' => 'not_found']);
    exit;
}

$retryAfter = enforceRateLimit($rateFile, $rateLimitPerMinute);
if ($retryAfter !== null) {
    jsonResponse(429, [
        'status' => 'error',
        'reason' => 'rate_limited',
        'retry_after' => $retryAfter,
    ], ['Retry-After' => (string) $retryAfter]);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (! is_array($body)) {
    jsonResponse(400, ['error' => 'invalid_json']);
    exit;
}

$requestId = $body['request_id'] ?? null;
$sku = $body['sku'] ?? null;

if (! is_string($requestId) || $requestId === ''
    || ! is_string($sku) || $sku === ''
    || ! is_string($body['order_id'] ?? null)) {
    jsonResponse(400, ['error' => 'missing_fields']);
    exit;
}

$store = loadStore($storeFile);

if (isset($store[$requestId])) {
    $saved = $store[$requestId];
    if (($saved['status'] ?? '') === 'ok') {
        if (! empty($saved['lie'])) {
            jsonResponse(502, [
                'status' => 'error',
                'request_id' => $requestId,
                'reason' => 'supplier_error',
            ]);
            exit;
        }

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
$mode = 'ok';

if ($duplicateCodeRate > 0 && (mt_rand() / mt_getrandmax()) < $duplicateCodeRate) {
    $existing = findExistingCode($store);
    if ($existing !== null) {
        $code = $existing;
        $mode = 'duplicate';
    }
} elseif ($wrongCodeRate > 0 && (mt_rand() / mt_getrandmax()) < $wrongCodeRate) {
    $code = 'WRONG-'.$sku.'-'.strtoupper(substr(md5($requestId), 0, 6));
    $mode = 'wrong';
}

$lie = $lieErrorRate > 0 && (mt_rand() / mt_getrandmax()) < $lieErrorRate;

$store[$requestId] = [
    'status' => 'ok',
    'code' => $code,
    'sku' => $sku,
    'mode' => $mode,
    'lie' => $lie,
];
saveStore($storeFile, $store);

if ($lie) {
    jsonResponse(502, [
        'status' => 'error',
        'request_id' => $requestId,
        'reason' => 'supplier_error',
    ]);
    exit;
}

if ($mode === 'wrong') {
    jsonResponse(200, [
        'status' => 'ok',
        'request_id' => $requestId,
        'code' => $code,
        'sku' => 'OTHER-SKU',
    ]);
    exit;
}

jsonResponse(200, [
    'status' => 'ok',
    'request_id' => $requestId,
    'code' => $code,
]);
