# Digital goods core

Минимальное ядро магазина цифровых товаров на PHP 8.3/PDO. Для быстрого запуска используется SQLite; `DATABASE_DSN` позволяет подключить PostgreSQL.

## Запуск

```bash
cp .env.example .env # необязательно
php -S 127.0.0.1:8080 -t public
```

Пример:

```bash
curl -X POST http://127.0.0.1:8080/orders -H 'Content-Type: application/json' \
  -d '{"sku":"GAME-100","customer":"player@example.com"}'
# затем: curl -X POST http://127.0.0.1:8080/webhooks/payment \
#   -H 'Content-Type: application/json' -d '{"order_id":"<id>","payment_id":"pay-1","status":"succeeded"}'
```

## API

- `POST /orders` — `{sku, customer}`
- `GET /orders/{id}`
- `POST /webhooks/payment` — `{order_id, payment_id, status}`; повтор безопасен
- `GET /catalog?limit=50&offset=0`
- `POST /reconcile` — сверка и возврат списка проблем
- `POST /worker` — безопасно повторяет зависшие выдачи

## Проверка гонки

```bash
php tests/concurrency.php http://127.0.0.1:8080
```

Тест отправляет 20 параллельных webhook-запросов и проверяет, что в заказе ровно одна выдача и один reservation у поставщика.

Для сценариев поставщиков доступны `SUPPLIER_A_MODE`/`SUPPLIER_B_MODE` (или общий `SUPPLIER_MODE`): `normal`, `fail`, `timeout`. В режиме `timeout` reservation сохраняется до имитации таймаута, поэтому повтор использует тот же код.

## Ключевые решения

- Уникальные ключи `orders.id`, `payments.payment_id`, `payment_events(event_id)`, `supplier_reservations(provider, idempotency_key)` и транзакционная блокировка заказа обеспечивают exactly-once на уровне бизнес-результата.
- Поставщик получает стабильный ключ `delivery:{order_id}`. Таймаут после фактической выдачи не создаёт новый код: повторный вызов возвращает ранее сохранённый reservation.
- A/B выбираются по порядку; ошибки и таймауты имеют ограниченный backoff. Если A уже выдал товар, B не вызывается. Recovery повторяет заказ с тем же ключом.
- Сверка ищет `paid` без `delivery` и `delivered` без подтверждённой оплаты. Структурированные события пишутся в `audit_log`.
- Каталог индексирован по `(active, stock DESC, id)` и SKU; витрина читает только нужные поля с LIMIT.
