<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'Digital Store Core API',
    version: '1.0.0',
)]
#[OA\Server(url: 'http://localhost:8080', description: 'Local Docker')]
#[OA\Tag(name: 'Health', description: 'Проверка доступности сервиса')]
#[OA\Tag(name: 'Orders', description: 'Создание и просмотр заказов')]
#[OA\Tag(name: 'Payment', description: 'Платежные webhook от провайдера')]
#[OA\Tag(name: 'Catalog', description: 'Витрина: SKU, цены и остатки')]
#[OA\Tag(name: 'Admin', description: 'Сверка ledger и recovery (X-Admin-Token)')]
#[OA\Components(
    securitySchemes: [
        new OA\SecurityScheme(
            securityScheme: 'AdminToken',
            type: 'apiKey',
            name: 'X-Admin-Token',
            in: 'header',
            description: 'Admin token. По умолчанию: dev-admin-token',
        ),
    ],
    schemas: [
        new OA\Schema(
            schema: 'CreateOrderRequest',
            required: ['sku'],
            properties: [
                new OA\Property(property: 'sku', description: 'SKU товара', type: 'string', example: 'KEY-CS2-PRIME'),
                new OA\Property(property: 'public_id', type: 'string', nullable: true, example: 'ord_abc123xyz0'),
            ],
        ),
        new OA\Schema(
            schema: 'Order',
            properties: [
                new OA\Property(property: 'id', type: 'string', example: 'ord_abc123xyz0'),
                new OA\Property(property: 'sku', type: 'string', example: 'KEY-CS2-PRIME'),
                new OA\Property(property: 'amount', type: 'number', format: 'float', example: 1500),
                new OA\Property(property: 'currency', type: 'string', example: 'RUB'),
                new OA\Property(property: 'status', type: 'string', example: 'delivered'),
                new OA\Property(property: 'issued_code', type: 'string', nullable: true, example: 'CODE-123'),
                new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'paid_at', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'delivered_at', type: 'string', format: 'date-time', nullable: true),
            ],
        ),
        new OA\Schema(
            schema: 'OrderResponse',
            properties: [new OA\Property(property: 'data', ref: '#/components/schemas/Order')],
        ),
        new OA\Schema(
            schema: 'ErrorMessage',
            properties: [new OA\Property(property: 'message', type: 'string', example: 'SKU not found')],
        ),
        new OA\Schema(
            schema: 'PaymentWebhookRequest',
            required: ['event_id', 'order_id', 'status', 'amount', 'currency', 'created_at'],
            properties: [
                new OA\Property(property: 'event_id', type: 'string', example: 'evt_001'),
                new OA\Property(property: 'order_id', type: 'string', example: 'ord_abc123xyz0'),
                new OA\Property(property: 'status', type: 'string', enum: ['paid', 'failed'], example: 'paid'),
                new OA\Property(property: 'amount', type: 'integer', example: 1500),
                new OA\Property(property: 'currency', type: 'string', example: 'RUB'),
                new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-01-01T12:00:00Z'),
            ],
        ),
        new OA\Schema(
            schema: 'WebhookAcceptedResponse',
            properties: [new OA\Property(property: 'status', type: 'string', example: 'accepted')],
        ),
        new OA\Schema(
            schema: 'CatalogStockItem',
            properties: [
                new OA\Property(property: 'sku', type: 'string', example: 'KEY-CS2-PRIME'),
                new OA\Property(property: 'name', type: 'string', example: 'CS2 Prime'),
                new OA\Property(property: 'type', type: 'string', example: 'key'),
                new OA\Property(property: 'price', type: 'number', format: 'float', example: 1500),
                new OA\Property(property: 'currency', type: 'string', example: 'RUB'),
                new OA\Property(property: 'stock', type: 'integer', example: 42),
            ],
        ),
        new OA\Schema(
            schema: 'PaginationMeta',
            properties: [
                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                new OA\Property(property: 'per_page', type: 'integer', example: 100),
                new OA\Property(property: 'total', type: 'integer', example: 5000),
                new OA\Property(property: 'last_page', type: 'integer', example: 50),
            ],
        ),
        new OA\Schema(
            schema: 'CatalogStockResponse',
            properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CatalogStockItem')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginationMeta'),
            ],
        ),
        new OA\Schema(
            schema: 'ReconcileResponse',
            properties: [
                new OA\Property(property: 'paid_not_delivered', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'delivered_not_paid', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'ledger_balanced', type: 'boolean', example: true),
                new OA\Property(property: 'ledger_payment_sum', type: 'number', format: 'float'),
                new OA\Property(property: 'ledger_delivery_sum', type: 'number', format: 'float'),
                new OA\Property(property: 'generated_at', type: 'string', format: 'date-time'),
            ],
        ),
        new OA\Schema(
            schema: 'RecoveryResponse',
            properties: [
                new OA\Property(property: 'recovered', type: 'integer', example: 2),
                new OA\Property(property: 'order_ids', type: 'array', items: new OA\Items(type: 'string')),
            ],
        ),
    ],
)]
class OpenApiInfo {}
