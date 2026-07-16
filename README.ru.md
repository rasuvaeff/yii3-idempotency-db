# rasuvaeff/yii3-идемпотентность-дб
[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-idempotency-db.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-idempotency-db)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-idempotency-db.svg)](https://packagist.org/packages/rasuvaeff/yii3-idempotency-db)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-idempotency-db/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-idempotency-db/actions)
[![Static analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-idempotency-db/static-analysis.yml?branch=master&label=psalm)](https://github.com/rasuvaeff/yii3-idempotency-db/actions)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-idempotency-db.svg)](https://github.com/rasuvaeff/yii3-idempotency-db/blob/master/LICENSE.md)
Хранилище идемпотентности на основе базы данных для API Yii3. Реализует
 `IdempotencyStorage` из `rasuvaeff/yii3-idempotency` с атомарным утверждением
 через `INSERT` (уникальный PK), воспроизведение ответа и срок действия на основе TTL.

 > Используете помощника по программированию с искусственным интеллектом? [llms.txt](llms.txt) содержит компактную ссылку на API
, которую можно вставить в приглашение. @@ЛИНИЯ@@
## Требования
- PHP 8.3+
 - `rasuvaeff/yii3-idempotency` ^1.0
 - `yiisoft/db` ^2.0
 - `yiisoft/db-migration` ^2.0
 - `psr/lock` ^1.0

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
### Запустить миграцию
```bash
yii migrate/up
```
Или используйте класс миграции напрямую:

```php
use M260611000000CreateIdempotencyKeysTable;

$migration = new M260611000000CreateIdempotencyKeysTable(table: 'idempotency_keys');
$migration->up($builder);
```
### Схема таблицы
| Столбец | Тип | Описание |
 |---|---|---|
 | `ключ` | `ВАРЧАР(255)` ПК | Ключевое значение идемпотентности |
 | `отпечаток пальца` | `ВАРЧАР(64)` | SHA-256 хеш метода + путь + запрос + тело |
 | `код_статуса` | `СМАЛЛИНТ` | Код состояния ответа HTTP |
 | `заголовки` | `ТЕКСТ` | Заголовки ответов в формате JSON (`array<string, list<string>>`) |
 | `тело` | `ТЕКСТ` | Тело ответа |
 | `expires_at` | `ВАРЧАР(30)` | Временная метка истечения срока действия (UTC, `Y-m-d H:i:s`) |
 | `заявлен` | `БУЛЕВАЯ` | Затребован ли ключ (в процессе) | @@ЛИНИЯ@@
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
Проводка DI связывает IdempotencyStorage::class с DbIdempotencyStorage. @@ЛИНИЯ@@
## Как это работает
1. **Утверждение**: INSERT с уникальным ПК для ключа и expires_at = now +claimTtlSeconds.
 Если вставка успешна, ключ запрашивается атомарно. Дубликат ключа вызывает ошибку целостности БД
, которую `claim()` преобразует в `false`; любая другая ошибка БД распространяется.
 2. **Сохранить**: после завершения работы обработчика ответ вставляется в строку
, а для параметра `claimed` устанавливается значение `0`; `expires_at` становится крайним сроком TTL записи.
 3. **Загрузка**: при последующем запросе с тем же ключом `load()` считывает строку.
 Активная заявка (`claimed = 1`, крайний срок не достигнут) возвращает `null` без
 удаления строки — тогда промежуточное программное обеспечение не выполняет свою собственную `claim()` и отвечает 409.
 Устаревшая заявка (крайний срок истек — сбой процесса) удаляется и может быть повторно востребована.
 Завершенная запись восстанавливается с помощью `IdempotencyRecord::restore()` и проверяется
 на соответствие ее TTL; просроченные записи удаляются.
 4. **Release**: если обработчик выдает (или возвращает 5xx), `release()` удаляет строку утверждения.
 5. **Очистка**: `deleteExpired()` удаляет все строки после `expires_at` (использует индекс
 `idx_idempotency_expires_at`) — вызовите ее из задачи cron. @@ЛИНИЯ@@
## Безопасность
- Ключи идемпотентности проверяются ядром («IdempotencyKey»).
 — Отпечатки пальцев представляют собой хэши SHA-256 — необработанный пользовательский ввод не сохраняется за пределами ключа.
 — тела ответов хранятся как есть; избегайте хранения конфиденциальных данных без шифрования
 на уровне приложений.
 — все временные метки хранятся в формате UTC — поведение хранения не зависит от часового пояса PHP
 по умолчанию. @@ЛИНИЯ@@
## Примеры
См. [examples/](examples/) для работоспособных сценариев. @@ЛИНИЯ@@
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
BSD-3-пункт. См. [LICENSE.md](LICENSE.md).
