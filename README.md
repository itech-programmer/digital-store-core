# Digital Store Core

Ядро магазина цифровых товаров по тестовому заданию (этапы 1 и 2).

В заказе может быть несколько товаров, часть позиций можно выдать, а за невыданные вернуть деньги. Поставщик считается недоверенным: коды проверяются у нас. Есть восстановление после сбоев. Бонусы: ограничение частоты запросов к поставщику и просмотр состояния на дату.

**Репозиторий:** https://github.com/itech-programmer/digital-store-core

Стек: PHP 8.4, Laravel 11, PostgreSQL 16, Redis, Docker Compose.

---

## 1. Запуск

### Требования

- Docker и Docker Compose
- Python 3 (только для скрипта `tools/concurrency/race_test.py`)

### Поднять стек

```bash
cp .env.example .env
cp services/store-api/.env.example services/store-api/.env
docker compose up -d --build
docker compose exec store-api composer install
docker compose exec store-api php artisan key:generate
docker compose exec store-api php artisan migrate --seed
docker compose exec store-api php artisan test
```

После `php artisan test` база очищается (`RefreshDatabase`). Перед демо заново накатайте данные:

```bash
docker compose exec store-api php artisan migrate:fresh --seed
```

Очередь выдачи (для асинхронной выдачи и демо rate limit):

```bash
docker compose --profile workers up -d
```

### URL

| Сервис | URL |
|--------|-----|
| API health | http://localhost:8080/api/v1/health |
| Swagger UI | http://localhost:8080/api/documentation |
| Primary supplier | http://localhost:8081/health |
| Fallback supplier | http://localhost:8082/health |

Admin-токен по умолчанию: заголовок `X-Admin-Token: dev-admin-token` (см. `services/store-api/.env`).

---

## 2. Воспроизведение частичного сбоя и недобросовестного поставщика

Поставщик отдаёт кандидата кода. Мы проверяем и сохраняем в `supplier_issuances`. Покупатель получает только `order_items.issued_code`. `product_keys` - остаток и резерв, не код покупателю. Каждый SKU идёт к своему поставщику через `products.preferred_supplier`.

После смены `.env` пересоздайте stub:

```bash
docker compose up -d --force-recreate supplier-primary supplier-fallback
```

### Переменные stub (корневой `.env`)

По умолчанию всё выключено (`0` / `0.0`). Для primary и fallback одни и те же смыслы.

| Переменная | Значение по умолчанию | Что делает |
|------------|----------------------|------------|
| `SUPPLIER_PRIMARY_ERROR_RATE` | `0.0` | доля ответов error (`supplier_unavailable`) |
| `SUPPLIER_PRIMARY_TIMEOUT_RATE` | `0.0` | доля таймаутов |
| `SUPPLIER_PRIMARY_TIMEOUT_SECONDS` | `30` | сколько секунд ждать при timeout |
| `SUPPLIER_PRIMARY_DUPLICATE_CODE_RATE` | `0.0` | отдать уже выданный code другому `request_id` (мы отклоняем и refund) |
| `SUPPLIER_PRIMARY_WRONG_CODE_RATE` | `0.0` | отдать чужой код с префиксом `WRONG-` (мы отклоняем) |
| `SUPPLIER_PRIMARY_LIE_ERROR_RATE` | `0.0` | code сохранить, в ответе error; берём через `GET /issuances/{request_id}` |
| `SUPPLIER_PRIMARY_RATE_LIMIT_PER_MINUTE` | `0` | лимит выдач в минуту; `0` = без лимита, иначе `429` |
| `SUPPLIER_FALLBACK_ERROR_RATE` | `0.0` | то же для fallback |
| `SUPPLIER_FALLBACK_TIMEOUT_RATE` | `0.0` | то же для fallback |
| `SUPPLIER_FALLBACK_TIMEOUT_SECONDS` | `30` | то же для fallback |
| `SUPPLIER_FALLBACK_DUPLICATE_CODE_RATE` | `0.0` | то же для fallback |
| `SUPPLIER_FALLBACK_WRONG_CODE_RATE` | `0.0` | то же для fallback |
| `SUPPLIER_FALLBACK_LIE_ERROR_RATE` | `0.0` | то же для fallback |
| `SUPPLIER_FALLBACK_RATE_LIMIT_PER_MINUTE` | `0` | то же для fallback |
| `SUPPLIER_CLIENT_RATE_LIMIT_PER_MINUTE` | `0` | лимит на стороне API-клиента к поставщику |

Примеры для демо: `SUPPLIER_PRIMARY_LIE_ERROR_RATE=1.0` или `SUPPLIER_PRIMARY_RATE_LIMIT_PER_MINUTE=5` (и то же для fallback при необходимости).

### Автотесты

```bash
docker compose exec store-api php artisan test --filter=MultiItemDeliveryTest
docker compose exec store-api php artisan test --filter=partial_fulfillment_money
docker compose exec store-api php artisan test --filter=UntrustedSupplierTest
docker compose exec store-api php artisan test --filter=CrashRecoveryTest
docker compose exec store-api php artisan test --filter=RateLimitDeliveryTest
docker compose exec store-api php artisan test --filter=PointInTimeTest
```

Живые сценарии (по желанию): `php tools/scenarios/stage2_partial.php`, `php tools/scenarios/rate_limit_burst.php 20`.

---

## 3. Как проверить, что деньги сходятся

Правило: сумма оплаты по заказу равна сумме выданных позиций плюс сумма возвратов по позициям.

```text
payment_received(order) = delivery_completed(lines) + refund_issued(lines)
```

В ledger у каждой записи уникальный `reference_id` (`pay_*`, `del_{order_item_id}`, `ref_{order_item_id}`), запись через `insertOrIgnore`, поэтому повтор шага не удваивает деньги.

```bash
curl -H "X-Admin-Token: dev-admin-token" http://localhost:8080/api/v1/admin/reconcile
```

Ожидаем: `ledger_balanced: true`, `unbalanced_orders: []`, сумма оплат равна сумме выдач плюс сумма возвратов (с небольшим допуском на округление).

Автотесты по деньгам и идемпотентности:

```bash
docker compose exec store-api php artisan test --filter=ReconcileAndRecoveryTest
docker compose exec store-api php artisan test --filter=CrashRecoveryTest
```

Полный прогон: `docker compose exec store-api php artisan test` (67 тестов).

---

## 4. Фактическое время

| Этап | Часы (оценка факта) |
|------|---------------------|
| Этап 1 (заказ, webhook, suppliers, recovery, Docker, тесты) | около 42 |
| Этап 2 обязательное (несколько товаров, refund, недоверенный поставщик, crash) | около 22 |
| Этап 2 бонусы (rate limit, point-in-time) | около 8 |
| README и подготовка сдачи | около 2 |
| Итого | около 74 |

---

## 5. Готовность к звонку

Готов на звонке внести небольшое изменение (контракт API, правило проверки кода, доп. проверка в reconcile, правка режима stub и т.п.) без переписывания архитектуры.

---

## Архитектура: слои, SOLID, DI

Запрос идёт по слоям без Action-классов:

```text
HTTP, FormRequest, DTO
Controller
ServiceInterface, Service
RepositoryInterface, Repository
Model, PostgreSQL
```

| Слой | Ответственность | Пример |
|------|-----------------|--------|
| Controller | HTTP вход и выход, валидация через FormRequest | `OrderController` |
| ServiceInterface и Service | бизнес-сценарий | `OrderServiceInterface`, реализация `OrderService` |
| RepositoryInterface и Repository | только доступ к БД | `OrderRepositoryInterface`, реализация `EloquentOrderRepository` |
| Model | сущность Eloquent | `Order`, `OrderItem` |

DI: Controllers и Services зависят от интерфейсов. Конкретные классы подключаются в `AppServiceProvider`.

SOLID коротко:

- S: тонкий Controller, отдельный Service, отдельный Repository
- O: новое поведение через новую реализацию и bind интерфейса, Controllers не ломаем
- L: тестовые подмены реализуют те же Contracts (например `SupplierClientInterface`)
- I: узкие контракты (`OrderServiceInterface`, `OrderRepositoryInterface` и другие)
- D: зависимость от `App\Contracts\*`, а не от Eloquent в Controllers и Services

---

## Архитектура этапа 2 (домен)

| Тема | Решение |
|------|---------|
| Несколько товаров в заказе | таблица `order_items`; API `items: [{sku, qty}]` (старый вариант с одним `sku` тоже работает) |
| Свой поставщик | `products.preferred_supplier` |
| Частичная выдача | позиция `delivered` или `refunded`; заказ `partially_delivered`, `refunded` или `delivered` |
| Код покупателю | только из `supplier_issuances` после проверки |
| HTTP к поставщику | вне длинной транзакции БД |
| Сбой | `RecoveryService` по позициям, освобождение или дожим orphan `reserved` |
| Rate limit | stub отвечает 429, клиентский limiter, job делает `release` |
| Состояние на дату | `domain_events` и ledger |

### Структура репозитория

```
digital-store-core/
  docker-compose.yml
  .env.example
  README.md
  services/
    store-api/
    supplier-primary/   порт 8081
    supplier-fallback/  порт 8082
    supplier-stub/      POST /issue, GET /issuances/{id}
  tools/
    webhook/send_webhook.php
    concurrency/race_test.py
    scenarios/
      stage2_partial.php
      rate_limit_burst.php
```

### Инструменты и автотесты

| Инструмент | Что проверяет | Роль |
|------------|---------------|------|
| `php artisan test` в store-api | полный набор сценариев | основной способ проверки |
| `tools/scenarios/stage2_partial.php` | заказ из нескольких товаров, оплата, reconcile | живое демо |
| `tools/scenarios/rate_limit_burst.php` | пачка заказов и delivery-progress | живое демо |
| `tools/concurrency/race_test.py` | 50 параллельных webhook | гонки |
| `tools/webhook/send_webhook.php` | ручная отправка payment webhook | хелпер |

`supplier-stub` - один PHP-файл с логикой поставщика. `supplier-primary` и `supplier-fallback` - два Docker-контейнера на этом же stub (разные порты и env: duplicate, wrong, lie, 429).

### Admin API

| Метод | Путь |
|-------|------|
| GET | `/api/v1/admin/reconcile` |
| POST | `/api/v1/admin/recover` |
| GET | `/api/v1/admin/delivery-progress` |
| GET | `/api/v1/admin/orders/{id}/as-of?at=` |
| GET | `/api/v1/admin/finance/period?from=&to=` |

Документация API: http://localhost:8080/api/documentation
