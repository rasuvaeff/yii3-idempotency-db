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

> **Внимание: сниппет выше пока не находит миграцию.** Это правильная
> конфигурация, и она заработает без единой правки с вашей стороны, как только
> починят описанный ниже баг апстрима — но сегодня `./yii migrate:up` печатает
> «Your system is up-to-date», возвращает 0 и не создаёт таблиц.
>
> `yiisoft/db-migration` (2.0.x) резолвит namespace в каталог так: берёт первую
> запись в `composer/autoload_psr4.php`, с которой namespace начинается,
> сравнивая с ключом без завершающего разделителя, а остаток отрезает по
> *необрезанной* длине. Обрезание разделителя стирает границу сегмента, поэтому
> `Rasuvaeff\Yii3Idempotency\` совпадает с `Rasuvaeff\Yii3IdempotencyDb\Migration`
> так, будто является его родителем — а этот пакет от него зависит, то есть
> коллизия есть всегда. Полученного каталога не существует, несуществующие
> каталоги discovery пропускает молча, и ничего не применяется.

Пока это не починено в апстриме, применяйте поставляемую миграцию сами:

```php
// src/Console/MigrateCommand.php (фрагмент)
use Rasuvaeff\Yii3IdempotencyDb\Migration\M260611000000CreateIdempotencyKeysTable;
use Yiisoft\Db\Migration\Informer\ConsoleMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Injector\Injector;

$builder = new MigrationBuilder($db, new ConsoleMigrationInformer());
$injector = new Injector($container);

foreach ([
    M260611000000CreateIdempotencyKeysTable::class,
] as $class) {
    $injector->make($class)->up($builder);
}
```

`Injector::make()` обязателен вместо `new`: он резолвит value object имени
таблицы из вашей конфигурации. Держите цикл идемпотентным (пропускать, если
таблица уже есть) — собственной истории миграций у него нет.

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
| `body` | `TEXT` | Тело ответа |
| `expires_at` | `VARCHAR(30)` | Timestamp истечения (UTC, `Y-m-d H:i:s`) |
| `claimed` | `BOOLEAN` | Захвачен ли ключ (идёт обработка) |

### Интеграция с Yii3

Пакет предоставляет `config/di.php` и `config/params.php` для `yiisoft/config`.

Параметры по умолчанию:

```php
// config/params.php
return [
    'rasuvaeff/yii3-idempotency-db' => [
        'table' => 'idempotency_keys',
        'claimTtlSeconds' => 3600,
    ],
];
```

DI-конфигурация связывает `IdempotencyStorage::class` с `DbIdempotencyStorage`.

## Как это работает

1. **Claim**: `INSERT` с уникальным PK по `key` и `expires_at = now + claimTtlSeconds`.
   Если вставка успешна, ключ захватывается атомарно. Дубликат ключа вызывает
   DB integrity error, который `claim()` преобразует в `false`; любая другая DB-ошибка
   прокидывается дальше.
2. **Store**: после завершения обработчика ответ upsert'ится в строку, а `claimed`
   устанавливается в `0`; `expires_at` становится TTL-дедлайном записи.
3. **Load**: при последующем запросе с тем же ключом `load()` читает строку.
   Активный захват (`claimed = 1`, дедлайн не достигнут) возвращает `null` без
   удаления строки — тогда middleware fails на собственном `claim()` и отвечает 409.
   Stale-захват (дедлайн прошёл — упавший процесс) удаляется и может быть захвачен заново.
   Завершённая запись восстанавливается через `IdempotencyRecord::restore()` и
   проверяется на TTL; истёкшие записи удаляются.
4. **Release**: если обработчик бросает исключение (или возвращает 5xx), `release()`
   удаляет строку захвата.
5. **Cleanup**: `deleteExpired()` удаляет все строки с прошедшим `expires_at` (использует
   индекс `idx_idempotency_expires_at`) — вызывайте из cron-задачи.

## Безопасность

- Ключи идемпотентности валидируются ядром (`IdempotencyKey`).
- Fingerprint'ы — SHA-256 хеши; кроме ключа, сырой пользовательский ввод не хранится.
- Тела ответов хранятся как есть; избегайте хранения чувствительных данных без
  шифрования на уровне приложения.
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
