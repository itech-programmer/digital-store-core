<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: 'Digital Store Core API',
    version: '2.0.0',
    description: 'Этапы 1-2: multi-item orders, partial/refund, untrusted supplier, admin reconcile/recover, rate-limit progress, point-in-time.',
)]
#[OA\Server(url: 'http://localhost:8080', description: 'Local Docker')]
#[OA\Tag(name: 'Health', description: 'Проверка доступности сервиса')]
#[OA\Tag(name: 'Orders', description: 'Создание и просмотр заказов (items[] / legacy sku)')]
#[OA\Tag(name: 'Payment', description: 'Платежные webhook от провайдера')]
#[OA\Tag(name: 'Catalog', description: 'Витрина: SKU, цены и остатки')]
#[OA\Tag(name: 'Admin', description: 'Reconcile, recovery, delivery-progress, as-of, finance period (X-Admin-Token)')]
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
            properties: [
                new OA\Property(property: 'sku', description: 'SKU одного товара (legacy). Либо sku, либо items.', type: 'string', example: 'KEY-CS2-PRIME'),
                new OA\Property(
                    property: 'items',
                    description: 'Позиции заказа (задача 1 этапа 2: несколько товаров)',
                    type: 'array',
                    items: new OA\Items(
                        required: ['sku'],
                        properties: [
                            new OA\Property(property: 'sku', type: 'string', example: 'KEY-CS2-PRIME'),
                            new OA\Property(property: 'qty', type: 'integer', example: 1),
                        ],
                        type: 'object',
                    ),
                ),
                new OA\Property(property: 'public_id', type: 'string', nullable: true, example: 'ord_abc123xyz0'),
            ],
        ),
        new OA\Schema(
            schema: 'OrderItem',
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'sku', type: 'string', example: 'KEY-CS2-PRIME'),
                new OA\Property(property: 'quantity', type: 'integer', example: 1),
                new OA\Property(property: 'unit_price', type: 'number', format: 'float', example: 1290),
                new OA\Property(property: 'amount', type: 'number', format: 'float', example: 1290),
                new OA\Property(property: 'currency', type: 'string', example: 'RUB'),
                new OA\Property(property: 'status', type: 'string', example: 'pending'),
                new OA\Property(property: 'issued_code', type: 'string', nullable: true),
                new OA\Property(property: 'supplier', type: 'string', nullable: true, example: 'primary'),
                new OA\Property(property: 'delivered_at', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'refunded_at', type: 'string', format: 'date-time', nullable: true),
            ],
        ),
        new OA\Schema(
            schema: 'Order',
            properties: [
                new OA\Property(property: 'id', type: 'string', example: 'ord_abc123xyz0'),
                new OA\Property(property: 'sku', type: 'string', example: 'KEY-CS2-PRIME'),
                new OA\Property(property: 'amount', type: 'number', format: 'float', example: 1500),
                new OA\Property(property: 'currency', type: 'string', example: 'RUB'),
                new OA\Property(property: 'status', type: 'string', example: 'partially_delivered', description: 'created|paid|delivering|delivered|partially_delivered|refunded|payment_failed|...'),
                new OA\Property(property: 'issued_code', type: 'string', nullable: true, example: 'CODE-123', description: 'Legacy single-item; для multi-item смотри items[].issued_code'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/OrderItem')),
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
                new OA\Property(property: 'unbalanced_orders', type: 'array', items: new OA\Items(type: 'string'), description: 'Заказы где paid != delivered + refunded'),
                new OA\Property(property: 'ledger_balanced', type: 'boolean', example: true),
                new OA\Property(property: 'ledger_payment_sum', type: 'number', format: 'float'),
                new OA\Property(property: 'ledger_delivery_sum', type: 'number', format: 'float'),
                new OA\Property(property: 'ledger_refund_sum', type: 'number', format: 'float'),
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
        new OA\Schema(
            schema: 'DeliveryProgressResponse',
            properties: [
                new OA\Property(property: 'queue_depth', type: 'integer'),
                new OA\Property(property: 'orders_paid_waiting', type: 'integer'),
                new OA\Property(property: 'orders_delivering', type: 'integer'),
                new OA\Property(property: 'orders_delivered', type: 'integer'),
                new OA\Property(property: 'orders_partially_delivered', type: 'integer'),
                new OA\Property(property: 'items_delivered', type: 'integer'),
                new OA\Property(property: 'items_pending', type: 'integer'),
                new OA\Property(property: 'generated_at', type: 'string', format: 'date-time'),
            ],
        ),
        new OA\Schema(
            schema: 'OrderAsOfResponse',
            properties: [
                new OA\Property(property: 'as_of', type: 'string', format: 'date-time'),
                new OA\Property(property: 'order', type: 'object'),
                new OA\Property(property: 'money', type: 'object'),
                new OA\Property(property: 'events_applied', type: 'integer'),
            ],
        ),
        new OA\Schema(
            schema: 'FinancePeriodResponse',
            properties: [
                new OA\Property(property: 'from', type: 'string', format: 'date-time'),
                new OA\Property(property: 'to', type: 'string', format: 'date-time'),
                new OA\Property(property: 'payment_received', type: 'number'),
                new OA\Property(property: 'delivery_completed', type: 'number'),
                new OA\Property(property: 'refund_issued', type: 'number'),
                new OA\Property(property: 'net', type: 'number'),
                new OA\Property(property: 'counts', type: 'object'),
            ],
        ),
    ],
)]
class OpenApiInfo {}
