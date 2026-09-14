<?php

return [
    'primary' => [
        'url' => env('SUPPLIER_PRIMARY_URL'),
    ],
    'fallback' => [
        'url' => env('SUPPLIER_FALLBACK_URL'),
    ],
    'http_timeout' => (int) env('SUPPLIER_HTTP_TIMEOUT', 5),
    'max_retries' => (int) env('SUPPLIER_MAX_RETRIES', 3),
    'backoff_ms' => array_map(
        'intval',
        explode(',', (string) env('SUPPLIER_BACKOFF_MS', '500,1000,2000'))
    ),
    'client_rate_limit_per_minute' => (int) env('SUPPLIER_CLIENT_RATE_LIMIT_PER_MINUTE', 0),
];
