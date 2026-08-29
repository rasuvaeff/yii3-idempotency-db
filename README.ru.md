# rasuvaeff/yii3-idempotency-db

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-idempotency-db.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-idempotency-db)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-idempotency-db.svg)](https://packagist.org/packages/rasuvaeff/yii3-idempotency-db)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-idempotency-db/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-idempotency-db/actions)
[![Static analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-idempotency-db/static-analysis.yml?branch=master&label=psalm)](https://github.com/rasuvaeff/yii3-idempotency-db/actions)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-idempotency-db.svg)](https://github.com/rasuvaeff/yii3-idempotency-db/blob/master/LICENSE.md)
[English version](README.md)

Database-backed хранилище идемпотентности для Yii3 API. Реализует
`IdempotencyStorage` из `rasuvaeff/yii3-idempotency` с атомарным захватом
через `INSERT` (уникальный PK), воспроизведением ответа и истечением по TTL.

> Используете AI-ассистента? В [llms.txt](llms.txt) — компактный API-справочник,
> который можно вставить в промпт.

## Требования

- PHP 8.3+
- `rasuvaeff/yii3-idempotency` ^1.0
- `yiisoft/db` ^2.0
- `yiisoft/db-migration` ^2.0
- `psr/clock` ^1.0

## Установка

```bash
composer require rasuvaeff/yii3-idempotency-db
```

## Использование

### Базовая настройка

```php
use Rasuvaeff\Yii3IdempotencyDb\DbIdempotencyStorage;
use Rasuvaeff\Yii3Idempotency\HeaderIdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyMiddleware;

$storage = new DbIdempotencyStorage(
    db: $connection,           // yiisoft/db ConnectionInterface
    clock: $clock,             // PSR-20 ClockInterface
    table: 'idempotency_keys',
    claimTtlSeconds: 3600,     // deadline for in-flight claims (stale-claim recovery)
    gcDivisor: 1000,           // ~1 успешный claim из 1000 попутно чистит истёкшие строки; 0 — выключить
);

$middleware = new IdempotencyMiddleware(
    keyExtractor: new HeaderIdempotencyKeyExtractor(),
    storage: $storage,
    responseFactory: $responseFactory,
    clock: $clock,
    ttlSeconds: 3600,
);
```

### Запуск миграции

Регистрируйте поставляемую миграцию **по namespace** — без путей в `vendor/`:

```php
// config/common/di/migration.php
use Yiisoft\Db\Migration\Service\MigrationService;

return [
    MigrationService::class => [
        'setSourceNamespaces()' => [[
            'App\\Migration',
            'Rasuvaeff\\Yii3IdempotencyDb\\Migration',
        ]],
    ],
];
```

```bash
./yii migrate:up
./yii migrate:down --limit=1
```

Пакет содержит две миграции, и **обе обязательны**:
`M260611000000CreateIdempotencyKeysTable` создаёт таблицу, а
`M260822000000AddBodyEncodingColumn` добавляет колонку `body_encoding`, которую
пишет каждый claim. Установка, созданная до появления этой колонки, обязана
выполнить `migrate:up` до деплоя этой версии — см. [UPGRADE.md](UPGRADE.md).
Откат второй миграции требует драйвера с поддержкой `DROP COLUMN`, которой в
`yiisoft/db-sqlite` нет.

`yiisoft/db-migration` строит миграцию через `Injector::make()`, поэтому она
получает value object имени таблицы из контейнера так же, как и хранилище —
никакой ручной проводки сверх `setSourceNamespaces()` выше не нужно.

Имя таблицы задаётся в params — `config/di.php` превращает его в
`IdempotencyKeysTableName`, который получают и миграция, и
`DbIdempotencyStorage`:

```php
// config/common/params.php
'rasuvaeff/yii3-idempotency-db' => [
    'table' => 'my_idempotency_keys',
    'table_prefix' => '',   // добавляется перед `table`; например 'rsv_' → rsv_my_idempotency_keys
],
```

Имена индексов следуют за именем таблицы (idx_my_idempotency_keys_expires_at), поэтому две
инсталляции могут делить одну схему PostgreSQL — там имена индексов уникальны
в пределах схемы, а не таблицы.

> **Не настраивайте миграцию через DI-контейнер.**
> `M...::class => ['__construct()' => ['table' => ...]]` не работает: миграцию
> создаёт `Injector::make()`, который резолвит аргументы по типу и никогда не
> читает определение контейнера по имени класса самой миграции. Хуже того,
> добавление такого определения роняет контейнер на этапе сборки в **каждом**
> запросе, потому что класс не автозагружается, пока его не подключит раннер
> миграций. Этот рецепт был описан в 1.x и никогда не работал.

### Схема таблицы

| Столбец | Тип | Описание |
|---|---|---|
| `key` | `VARCHAR(255)` PK | Значение ключа идемпотентности |
| `fingerprint` | `VARCHAR(64)` | SHA-256 хеш method + path + query + body |
| `status_code` | `SMALLINT` | HTTP status code ответа |
| `headers` | `TEXT` | JSON-закодированные заголовки ответа (`array<string, list<string>>`) |
| `body` | `TEXT` | Тело ответа (base64, если так говорит `body_encoding`) |
| `body_encoding` | `VARCHAR(16)` | `plain` или `base64` — как хранится `body` |
| `expires_at` | `VARCHAR(30)` | Timestamp истечения (UTC, `Y-m-d H:i:s`) |
| `claimed` | `BOOLEAN` | Захвачен ли ключ (идёт обработка) |

У `headers` и `body` намеренно нет DEFAULT: MySQL запрещает литеральный DEFAULT
у TEXT-колонки (ошибка 1101), а нужды в нём нет — каждый INSERT этого пакета
пишет обе колонки явно.

### Интеграция с Yii3

Пакет предоставляет `config/di.php` и `config/params.php` для `yiisoft/config`.

Параметры по умолчанию:

```php
// config/params.php
return [
    'rasuvaeff/yii3-idempotency-db' => [
        'table' => 'idempotency_keys',
        'claimTtlSeconds' => 3600,
        'gcDivisor' => 1000,
    ],
];
```

DI-конфигурация связывает `IdempotencyStorage::class` с `DbIdempotencyStorage`.

## Как это работает

1. **Claim**: `INSERT` с уникальным PK по `key` и `expires_at = now + claimTtlSeconds`.
   Если вставка успешна, ключ захватывается атомарно. Дубликат ключа вызывает
   DB integrity error, который `claim()` преобразует в `false`; любая другая DB-ошибка
   прокидывается дальше.
2. **Store**: после завершения обработчика ответ записывается только в захват,
   которым владеет этот экземпляр: условный `UPDATE` по ключу, `claimed = 1` и
   точному `expires_at`, который записал его собственный `claim()`. Если такой
   строки больше нет — перехват удалил её после истечения дедлайна, — запись
   вставляется только при отсутствии ключа; проигрыш гонки по duplicate-key
   более новому захвату конкурента оставляет его строку нетронутой. `claimed`
   становится `0`, а `expires_at` — TTL-дедлайном записи.
3. **Load**: при последующем запросе с тем же ключом `load()` читает строку.
   Активный захват (`claimed = 1`, дедлайн не достигнут) возвращает `null` без
   удаления строки — тогда middleware fails на собственном `claim()` и спрашивает
   `claimedFingerprint()`: тот же payload отвечает 409, другой — 422
   (повтор никогда не смог бы завершиться). Stale-захват (дедлайн прошёл —
   упавший процесс) удаляется и может быть захвачен заново.
   Завершённая запись восстанавливается через `IdempotencyRecord::restore()` и
   проверяется на TTL; истёкшие записи удаляются.
4. **Release**: если обработчик бросает исключение (или возвращает 5xx), `release()`
   удаляет строку захвата — но только тот захват, который взял этот экземпляр
   хранилища. Записанный им `expires_at` работает токеном владения: перехват
   после протухания захвата обязательно пишет более поздний дедлайн, поэтому
   запоздалый release от прежнего владельца не находит строки и не может удалить
   ни захват конкурента, ни уже сохранённый им ответ.
5. **Cleanup**: `deleteExpired()` удаляет все строки с прошедшим `expires_at` (использует
   индекс экспирации — `idx_idempotency_expires_at` на таблице по умолчанию,
   `idx_<table>_expires_at` для кастомной). Примерно один успешный claim из
   `gcDivisor` вызывает его попутно, так что таблица не растёт без cron-задачи;
   `gcDivisor: 0` выключает это, и уборка остаётся на вас.

Каждое удаление, кроме `deleteExpired()`, соответствует предикату владения,
но два вида различаются:

| Операция | Условие |
|---|---|
| Чистка протухших (`load()`, перезахват) | Флаг `claimed`, который прочитал вызывающий, плюс `expires_at <= now` — строка всё ещё та самая просроченная, которую он оценил |
| Освобождение захвата (`release()`) | **Точный** `expires_at`, записанный собственным `claim()` этого экземпляра, — токен владения; перехват обязательно пишет более поздний |
| Сохранение ответа (`store()`) | Тот же точный дедлайн в `UPDATE`; запасной INSERT — только при отсутствии ключа |

Безусловный `DELETE WHERE key = :k` не отличает оценённую строку от свежей,
которую конкурент создал в промежутке, поэтому на границе TTL два запроса могли
удалить захваты друг друга и оба выполнить handler.

## Безопасность

- Ключи идемпотентности валидируются ядром (`IdempotencyKey`).
- Fingerprint'ы — SHA-256 хеши; кроме ключа, сырой пользовательский ввод не хранится.
- Тела ответов хранятся как есть; избегайте хранения чувствительных данных без
  шифрования на уровне приложения.
- Тело, не являющееся валидным UTF-8 (PDF, ZIP, что угодно с NUL-байтом),
  кодируется в base64 при записи и декодируется при чтении. Колонка `text` такие
  байты хранить не может — PostgreSQL их отвергает, — а падение на этом шаге
  завалило бы запрос, side effects которого уже зафиксированы.
- Все timestamp'ы хранятся в UTC — поведение хранилища не зависит от часового пояса
  PHP по умолчанию.

## Примеры

См. [examples/](examples/) — запускаемые скрипты.

## Разработка

```bash
make install        # composer install
make build          # full gate (validate + normalize + cs + psalm + test)
make cs-fix         # fix code style
make psalm          # static analysis
make test           # run testo
make test-coverage  # testo with coverage
make mutation       # mutation testing
make release-check  # build + rector + bc-check + mutation
```

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
