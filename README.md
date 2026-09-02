# Digital Store Core

Ядро магазина цифровых товаров: заказы, платежный webhook, интеграция с поставщиками, автоматическая выдача ключей.

**PHP 8.4 - Laravel 11 - PostgreSQL 16 - Redis - Docker Compose**

## Стек

| Компонент | Технология |
|-----------|------------|
| API | PHP 8.4, Laravel 11 |
| БД | PostgreSQL 16 |
| Кэш / сессии / очереди | Redis |
| Поставщики | HTTP-заглушки primary и fallback |
| Проверка гонок | Python 3 (`tools/concurrency/race_test.py`) |
| Документация API | OpenAPI / Swagger UI |

## Структура репозитория

```
digital-store-core/
├── docker-compose.yml
├── Makefile
├── .env.example
├── README.md
├── services/
│   ├── store-api/              Laravel API, nginx, php-fpm
│   ├── supplier-primary/       поставщик A :8081
│   ├── supplier-fallback/      поставщик B :8082
│   └── supplier-stub/          общий код заглушки POST /issue
└── tools/
    ├── webhook/send_webhook.php
    └── concurrency/race_test.py
```

У каждого сервиса свой `.docker/` и свой `docker-compose.yml`. Корневой compose поднимает Postgres, Redis и все сервисы.

## Быстрый старт

### Требования

- Docker и Docker Compose
- Python 3 для `race_test.py` (стандартная библиотека, без pip)

### Запуск

```bash
cp .env.example .env
cp services/store-api/.env.example services/store-api/.env
docker compose up -d --build
docker compose exec store-api composer install
docker compose exec store-api php artisan key:generate
docker compose exec store-api php artisan migrate --seed
docker compose exec store-api php artisan test
```

После `php artisan test` база пустая из-за `RefreshDatabase`. Перед demo или race_test:

```bash
docker compose exec store-api php artisan migrate:fresh --seed
python tools/concurrency/race_test.py
```

### URL

| Сервис | URL |
|--------|-----|
| API health | http://localhost:8080/api/v1/health |
| Swagger UI | http://localhost:8080/api/documentation |
| Primary supplier | http://localhost:8081/health |
| Fallback supplier | http://localhost:8082/health |

### Makefile (Linux / macOS / WSL)

`make up`, `make install`, `make fresh`, `make test`, `make race`, `make catalog-bulk`, `make logs`, `make shell`

## Тесты

```bash
docker compose exec store-api php artisan test
```

## Сценарии приемки

| # | Сценарий | Как проверить |
|---|----------|---------------|
| 1 | 50 parallel paid по одному заказу - одна выдача | `python tools/concurrency/race_test.py`, `WebhookRaceTest` |
| 2 | Повтор с тем же event_id - no-op | `WebhookRaceTest`, `OrderPaymentDeliveryTest` |
| 3 | Webhook раньше заказа или вне порядка | `pending_webhooks`, `OrderPaymentDeliveryTest` |
| 4 | Timeout поставщика, retry с тем же request_id - без дубля | `SupplierTimeoutFallbackTest`, idempotency stub |
| 5 | Primary недоступен - fallback, одна выдача | `SupplierTimeoutFallbackTest` |
| 6 | Пустой остаток - out_of_stock, без падения | `OrderPaymentDeliveryTest` |

### Примеры команд

```bash
# гонки (50 webhook)
python tools/concurrency/race_test.py

# webhook вручную
php tools/webhook/send_webhook.php --order-id=ord_xxx --event-id=evt_001 --status=paid

# сверка и recovery
curl -H "X-Admin-Token: dev-admin-token" http://localhost:8080/api/v1/admin/reconcile
curl -X POST -H "X-Admin-Token: dev-admin-token" "http://localhost:8080/api/v1/admin/recover?stale_minutes=10"
docker compose exec store-api php artisan orders:recover-stuck

# каталог под нагрузкой
docker compose exec store-api php artisan catalog:seed-bulk --count=5000 --keys=2
docker compose exec store-api php artisan catalog:rebuild-stock
curl "http://localhost:8080/api/v1/catalog/stock?page=1&per_page=100"
```

Для воспроизведения timeout/fallback на заглушках подними `SUPPLIER_PRIMARY_TIMEOUT_RATE` или `SUPPLIER_PRIMARY_ERROR_RATE` в корневом `.env` и перезапусти compose.

Документация API: http://localhost:8080/api/documentation

## Ключевые решения

### Идемпотентность webhook

`processed_webhook_events.event_id` UNIQUE. `insertOrIgnore` - повторный webhook с тем же event_id не меняет заказ и не запускает выдачу повторно. После commit транзакции webhook ставит `DeliverOrderJob` в очередь и сразу отвечает 200.

### Exactly-once выдача

- Atomic claim: `UPDATE orders SET status = delivering WHERE status IN (paid, out_of_stock, delivery_failed)`
- Резерв ключа из `product_keys` с `lockForUpdate` - один ключ на один заказ
- `delivery_attempts.request_id` UNIQUE
- Параллельные webhook: только один поток проходит claim

### Timeout и fallback

- Primary: `request_id` вида `req_{order_id}-1`, retry с тем же id при timeout
- Fallback: новый `request_id` `req_{order_id}-2`
- Заглушка хранит ответ по request_id - повтор возвращает тот же code
- HTTP timeout клиента 5 сек, stub sleep до 30 сек - timeout через ConnectionException, не как финальная ошибка

### Webhook раньше заказа

Неизвестный order_id пишется в `pending_webhooks`. При создании заказа pending события применяются.

### Recovery

- Зависший `delivering` сбрасывается в `paid`
- Повторная выдача для `paid`, `out_of_stock`, `delivery_failed`
- Artisan: `orders:recover-stuck`, HTTP: `POST /admin/recover`, schedule job каждые 5 минут

### Каталог под нагрузкой

Таблица `product_stock_cache(sku, available_count)` вместо COUNT на каждый запрос. Обновление при reserve, deliver, release и через `catalog:rebuild-stock`.

Индексы PostgreSQL:

- `product_stock_cache (available_count DESC) WHERE available_count > 0`
- `product_keys (sku, id) WHERE status = available`

Пример витринного запроса:

```sql
EXPLAIN (ANALYZE, BUFFERS)
SELECT p.sku, p.name, p.type, p.price, p.currency, COALESCE(s.available_count, 0) AS stock
FROM products p
LEFT JOIN product_stock_cache s ON s.sku = p.sku
WHERE p.is_active = true
ORDER BY p.sku
LIMIT 100 OFFSET 0;
```

После `catalog:seed-bulk` сравнить с naive `GROUP BY product_keys`.

### Масштабирование

Горизонтальное масштабирование API и queue workers за nginx, read replica для catalog/stock, outbox для интеграций. Worker: `docker compose --profile workers up -d`.

Конфигурация: `.env.example` и `services/store-api/.env.example`.

## Фактическое время

42 часа (этапы 0-5, Docker, тесты, Swagger, README).
