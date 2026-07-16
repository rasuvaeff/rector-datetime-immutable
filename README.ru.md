# rasuvaeff/rector-datetime-immutable

[English version](README.md)

Rector rules и CLI для безопасной миграции mutable `DateTime` на
`DateTimeImmutable`, включая repair потерянных результатов mutator calls.

## Требования

PHP 8.3 - 8.5 и Rector 2.x; точные constraints приведены в `composer.json`.

## Установка

```bash
composer require --dev rasuvaeff/rector-datetime-immutable
```

## Использование

```bash
vendor/bin/rector-datetime-immutable process src
```

Команда сначала запускает preflight boundaries, затем миграцию и confirmation
pass. Повторяйте command, пока Rector не перестанет менять files: lost mutation,
созданная migration, видна только на следующем run. `--dry-run` работает в
temporary workspace и не меняет исходники; `--format=human|github|json` задаёт
вывод для CI.

`MutableDateTimeBoundaryRector` report-only находит native, vendor, inherited,
interface и abstract contracts с mutable `DateTime`. `DateTimeImmutableRector`
мигрирует constructions, concrete typehints, properties и docblock tags.
`LostDateTimeMutationRector` в `fix` mode добавляет assignment, а в `report`
mode оставляет marker. `--doctrine-columns` включает согласованную migration
attributes Doctrine columns.

## Безопасность

Rules намеренно пропускают uncertain dispatch, mutable contracts, open base
types и отмеченные `@mutable-datetime` declarations. Проверяйте diff и tests
целевого приложения до применения migration.

## Примеры

Подробный workflow и все option constants: [README.md](README.md).

## Разработка

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
