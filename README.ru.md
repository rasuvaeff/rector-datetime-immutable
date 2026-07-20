# rasuvaeff/rector-datetime-immutable

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/rector-datetime-immutable.svg)](https://packagist.org/packages/rasuvaeff/rector-datetime-immutable)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/rector-datetime-immutable.svg)](https://packagist.org/packages/rasuvaeff/rector-datetime-immutable)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/rector-datetime-immutable/build.yml?branch=master)](https://github.com/rasuvaeff/rector-datetime-immutable/actions)
[![Static Analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/rector-datetime-immutable/static-analysis.yml?branch=master)](https://github.com/rasuvaeff/rector-datetime-immutable/actions)
[![Psalm Level](https://img.shields.io/badge/Psalm-level%201-brightgreen.svg)](psalm.xml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/rector-datetime-immutable/php)](https://packagist.org/packages/rasuvaeff/rector-datetime-immutable)
[![License](https://img.shields.io/packagist/l/rasuvaeff/rector-datetime-immutable.svg)](LICENSE.md)
[English version](README.md)

Правила [Rector](https://getrector.com), мигрирующие мутабельный `DateTime` в
`DateTimeImmutable` — и **автоматически чинящие потерянные мутации**, которые
создаёт миграция: классическую тихую ошибку, когда `$date->modify('+1 day');`
выбрасывает новый экземпляр:

```php
// before — mutable construction, in-place mutation
$deadline = new \DateTime('2026-01-01');
$deadline->modify('+1 month');

// after (both rules) — immutable, and the mutation result is kept
$deadline = new \DateTimeImmutable('2026-01-01');
$deadline = $deadline->modify('+1 month');
```

PHPStan (level 4) и Psalm *сообщают* про проигнорированные результаты
`DateTimeImmutable`-мутаторов; этот пакет — то, что **чинит их массово** во
время миграции.

> Используете AI-ассистента? [llms.txt](llms.txt) содержит компактный
> API-справочник, который можно передать как контекст.

## TL;DR

Два способа запустить миграцию:

| Способ | Как |
|---|---|
| **CLI-обёртка** (рекомендуется) | `vendor/bin/rector-datetime-immutable src` — boundary-предполёт, миграция до сходимости и диагностический проход одной командой; см. [Миграция одной командой](#миграция-одной-командой) |
| **Ручной `rector.php`** | зарегистрируйте правила сами; см. [Ручная настройка Rector](#ручная-настройка-rector) |

**Предупреждение для ручной настройки:** один запуск Rector не может одновременно
мигрировать и чинить — запускайте `vendor/bin/rector process` **пока он не
сообщит об отсутствии изменений** (обычно дважды), иначе потерянные мутации,
созданные первым проходом, останутся в коде. Обёртка делает это за вас.

## Оглавление

- [Требования](#требования)
- [Установка](#установка)
- [Использование](#использование)
  - [Миграция одной командой](#миграция-одной-командой)
  - [Предпросмотр dry-run](#предпросмотр-dry-run)
  - [Вывод CI](#вывод-ci)
  - [Разрешение находок предполёта](#разрешение-находок-предполёта)
  - [Совместная миграция столбцов Doctrine](#совместная-миграция-столбцов-doctrine)
  - [Ручная настройка Rector](#ручная-настройка-rector)
  - [`MutableDateTimeBoundaryRector`](#mutabledatetimeboundaryrector)
  - [`DateTimeImmutableRector`](#datetimeimmutablerector)
  - [`LostDateTimeMutationRector`](#lostdatetimemutationrector)
  - [Маркеры](#маркеры)
- [Безопасность](#безопасность)
- [Примеры](#примеры)
- [Разработка](#разработка)
- [Лицензия](#лицензия)

## Требования

- PHP 8.3 - 8.5 для запуска правил
- `rector/rector` ^2.5
- `webmozart/assert` ^1.11 || ^2.0
- `proc_open` включён при использовании обёртки сходимости — доступен в
  дефолтной сборке PHP, если только хост не отключил его через `disable_functions`

## Установка

```bash
composer require --dev rasuvaeff/rector-datetime-immutable
```

## Использование

### Миграция одной командой

Установленный Composer-бинарник сначала запускает read-only предполёт по
мутабельным границам, затем многократно применяет миграцию по умолчанию до
чистого подтверждающего прохода, после чего запускает `LostDateTimeMutationRector`
в `MODE_REPORT`, ничего не меняя в файлах:

```bash
vendor/bin/rector-datetime-immutable src
```

Команда редактирует выбранные пути. Сначала закоммитьте или спрячьте несвязанную
работу. Типичный вывод:

```text
Preflight: no mutable DateTime boundaries found.
Migration pass 1: 12 changed file(s).
Migration pass 2: 4 changed file(s).
Migration pass 3: 0 changed file(s).
Converged after 2 change-producing pass(es).
Diagnostic pass: no manual review cases found.
Summary: 14 file(s) changed across 2 change-producing pass(es); 0 manual review case(s).
```

Если предполёт находит native, унаследованный, abstract/interface или
vendor-callable, чей параметр принимает `DateTime`, но отвергает
`DateTimeImmutable`, либо параметр метода, питающий свойство, которое миграция
сохраняет мутабельным, он печатает записи `file:line` плюс resolution-подсказку
по каждой категории находок, выходит с кодом `2` и не меняет файлы. Тот же
exit-код используется после сходимости, когда отчёт о потерянных мутациях
находит кейс, который нельзя безопасно назначить.

| Exit | Значение |
|---|---|
| `0` | миграция сошлась, ручных кейсов не осталось |
| `1` | сбой Rector/process/JSON |
| `2` | предполёт заблокировал миграцию либо осталась ручная проверка после миграции |
| `3` | миграция не сошлась в пределах лимита проходов |
| `64` | некорректные аргументы обёртки |

Полезные опции:

```bash
vendor/bin/rector-datetime-immutable --dry-run src           # full preview, no writes
vendor/bin/rector-datetime-immutable --acknowledge-boundaries src
vendor/bin/rector-datetime-immutable --doctrine-columns src  # co-migrate ORM columns
vendor/bin/rector-datetime-immutable --format=github src     # or --format=json
vendor/bin/rector-datetime-immutable --max-passes=8 src tests
vendor/bin/rector-datetime-immutable --no-report src
vendor/bin/rector-datetime-immutable \
    --preflight-config=rector-preflight.php \
    --config=rector-migration.php \
    --report-config=rector-report.php \
    src
```

Упакованные дефолты — `config/preflight.php`, `config/migration.php` и
`config/report.php. Используйте кастомные конфиги для проектных skip'ов,
постадийных опций или `ALLOW_SUBCLASS`.

### Предпросмотр dry-run

`--dry-run` копирует пути во временное рабочее пространство, прогоняет там весь
поток — предполёт, сходимость, диагностический проход — печатает все потенциальные
diff'ы с путями, отмапленными обратно на оригиналы, и не меняет ни одного
проектного файла. Exit-коды сохраняют смысл, поэтому предпросмотр также
сообщает, чем закончился бы реальный запуск. Объявления вне скопированных
путей (vendor-классы, родителя в директориях, которые вы не передали) всё равно
читаются из своих оригинальных файлов; write-прогон остаётся авторитетным.

### Вывод CI

`--format=github` сохраняет человекочитаемый вывод и дополнительно эммитит
workflow-аннотации `::error file=…,line=…::…` для предполётных блокировок и
`::warning …` для кейсов ручной проверки, поэтому в migration-PR каждая находка
видна инлайн.

`--format=json` подавляет повествование и печатает один машиночитаемый объект
в stdout: `status` (`clean`, `blocked`, `manual-review`, `not-converged`,
`acknowledged`), `exitCode`, `passes` по каждому проходу, `changedFiles` и
находки `preflight`/`manualReview`/`acknowledged` как `{file, line, message,
category}`, где `category` — одно из `requires-datetime`, `feeds-mutable-property`,
`lost-mutation`, `diagnostic`. При `--dry-run` объект также несёт потенциальные
`diffs`.

### Разрешение находок предполёта

| Находка | Решение |
|---|---|
| `parameter $x feeds mutable property $y` | пометьте охватывающий метод `@mutable-datetime` — его сигнатура и связанные аргументы call-site остаются мутабельными — ко-мигрируйте ORM-столбцы через `--doctrine-columns`, либо сначала мигрируйте storage-контракт |
| `parameter $x requires DateTime` | перепишите вызов на `DateTimeImmutable`-безопасный API, либо ревьюните flow и acknowledge'ните его |

`@mutable-datetime` на **вызывающем** методе не глушит находку
`requires DateTime`: маркер сохраняет собственный контракт метода, тогда как
находка указывает на вызываемый native/vendor/inherited-параметр. Сама миграция
держит значения, подключённые к такому callable простыми присваиваниями,
мутабельными, поэтому после ревью flow acknowledge'ните это:

```bash
vendor/bin/rector-datetime-immutable --acknowledge-boundaries src
```

Это пишет самодокументирующий комментарий над каждым boundary-call и
перепрогоняет предполёт:

```php
// @mutable-datetime-boundary: parameter $object requires DateTime
date_modify($moment, '+1 hour');
```

Оператор с `@mutable-datetime-boundary` пропускается всеми дальнейшими
предполётами — ревью живёт в коде и переживает перезапуски. Находки вида
`feeds mutable property` **никогда** не acknowledge'ятся автоматически:
заглушить их значило бы позволить миграции сломать присваивание свойства в
runtime, поэтому у них остаются собственные разрешения выше.

Скип на уровне файла через кастомный предполёт-конфиг остаётся грубой
альтернативой:

```php
// rector-preflight.php
<?php

declare(strict_types=1);

use Rasuvaeff\RectorDateTimeImmutable\MutableDateTimeBoundaryRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withRules([
        MutableDateTimeBoundaryRector::class,
    ])
    ->withSkip([
        MutableDateTimeBoundaryRector::class => [
            __DIR__ . '/src/Legacy/SdkAdapter.php',
        ],
    ]);
```

```bash
vendor/bin/rector-datetime-immutable --preflight-config=rector-preflight.php src
```

После сходимости тот же exit `2` сообщает потерянные мутации, которые fix-режим
не может безопасно назначить, — разрешите их, присвоив результат мутатора сами
(`$date = $date->modify(...)`).

### Совместная миграция столбцов Doctrine

По умолчанию ORM-mapped-члены сохраняются. `--doctrine-columns` (опция
`DOCTRINE_COLUMNS` обоих правил) включают совместную миграцию столбцов с
attribute-mapping'ом: свойство, его accessors и связанные параметры конструктора
мигрируют вместе с mapping'ом, который переезжает на нативный иммутабельный
DBAL-вариант — та же схема БД, иммутабельная гидратация.

```php
#[ORM\Column(type: 'datetime')]              // → type: 'datetime_immutable'
private \DateTime $expiresAt;                // → private \DateTimeImmutable $expiresAt;

#[ORM\Column(type: Types::DATETIME_MUTABLE)] // → Types::DATETIME_IMMUTABLE
#[ORM\Column]                                // no type: Doctrine infers it from the PHP type
```

Покрытые mapping'и: `datetime`, `date`, `time`, `datetimetz` как строковые
литералы или соответствующие `Types::*_MUTABLE`-константы, плюс столбцы без
аргумента `type`. Кастомные строки типов, динамические выражения типа,
позиционные аргументы атрибутов и docblock-аннотации `@ORM\Column` остаются
сохранёнными. Требуется `doctrine/dbal` ≥ 2.6 (нативные `*_immutable`-типы).
Проверьте lifecycle-код, который мутировал даты сущности на месте — диагностический
проход сообщит о них как о потерянных мутациях.

### Ручная настройка Rector

```php
// rector.php
<?php

declare(strict_types=1);

use Rasuvaeff\RectorDateTimeImmutable\DateTimeImmutableRector;
use Rasuvaeff\RectorDateTimeImmutable\LostDateTimeMutationRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withRules([
        DateTimeImmutableRector::class,
        LostDateTimeMutationRector::class,
    ]);
```

Без обёртки запускайте `vendor/bin/rector process` **пока он не сообщит об
отсутствии изменений** (обычно дважды): в пределах одного прогона type-inference
ещё видит домиграционные типы, поэтому потерянные мутации, созданные
construction-миграцией, становятся видны *следующему* прогону.

Запустите `MutableDateTimeBoundaryRector` отдельно через `--dry-run` перед
ручной миграцией. Не объединяйте его с миграционными правилами: его комментарии —
диагностические diff-маркеры, а не source-изменения на коммит.

### `MutableDateTimeBoundaryRector`

Сообщает про аргументы, текущие в стабильные callable, чей объявленный параметр
принимает `DateTime`, но отвергает `DateTimeImmutable`. Стабильные callable —
это native PHP-функции, vendor-функции/методы, interface- или abstract-методы, а
также методы, ограниченные предком или помеченные `@mutable-datetime`. Другие
локальные конкретные callable не сообщаются, потому что их объявления мигрируют
вместе со своими call-site'ами.

Анализ поддерживает позиционные, именованные и variadic-аргументы в функциях,
instance/static-методах и конструкторах. CLI запускает это правило как
обязательный dry-run перед изменением файлов.

Правило также сообщает про параметры методов, питающие свойство, которое
миграция сохраняет мутабельным — ORM-столбцы, объявления `@mutable-datetime`,
унаследованные свойства (`$this->ormColumn = $param;`, включая `??`/ternary-ветки):
миграция такого параметра гарантирует `TypeError` на присваивании свойства.
Решение — пометить метод `@mutable-datetime` (его сигнатура и связанные
call-site-аргументы тогда остаются мутабельными), ко-мигрировать ORM-столбцы
через `--doctrine-columns` либо мигрировать сам storage-контракт.

Операторы с комментарием `@mutable-datetime-boundary` пропускаются как уже
проверенные boundary-call'ы. Опция `MODE` правила выбирает `report` (по
умолчанию — навешивать `@todo` diff-маркеры) или `acknowledge` (писать
комментарий `@mutable-datetime-boundary` над каждым boundary-call'ом;
используется CLI'ным `--acknowledge-boundaries`). Feed-находки никогда не
пишутся в acknowledge-режиме.

### `DateTimeImmutableRector`

Мигрирует конструирование `DateTime` и конкретные объявления типов в
`DateTimeImmutable`.

| Опция | По умолчанию | Что включает |
|---|---|---|
| `CONSTRUCTORS` | `true` | `new \DateTime(...)`, разделяемые статические фабрики включая `createFromTimestamp()`, и две процедурные `date_create*()`-фабрики |
| `TYPEHINTS` | `true` | `\DateTime` в именованных функциях, методах, замыканиях, arrow-функциях и enum-методах (вкл. nullable и union-типы) |
| `PROPERTIES` | `true` | `\DateTime` в типизированных свойствах и promoted-параметрах конструктора |
| `ALLOW_SUBCLASS` | `false` | переписать `class X extends \DateTime` на `extends \DateTimeImmutable` (рискованно — downstream-мутации на месте ломаются; парите с `LostDateTimeMutationRector`) |
| `DOCTRINE_COLUMNS` | `false` | ко-мигрировать attribute-mapped Doctrine-столбцы вместе с их mapping-типом (см. «Совместная миграция столбцов Doctrine») |

Миграция также держит файл консистентным:

- `@var`/`@param`/`@return` docblock-типы мигрированного объявления (включая
  варианты тега `@psalm-`/`@phpstan-`) переписываются на `DateTimeImmutable` —
  меняется только токен типа, описания остаются; docblock-only объявления без
  нативного типа никогда не переписываются;
- `use DateTime;`-импорт (с алиасом или без) удаляется, как только в файле на
  него больше ничего не ссылается — код, docblock и комментарии все идут в счёт,
  и сканер скорее сохранит импорт зря, чем удалит нужный.

```php
->withConfiguredRule(DateTimeImmutableRector::class, [
    DateTimeImmutableRector::CONSTRUCTORS => true,
    DateTimeImmutableRector::TYPEHINTS => true,
    DateTimeImmutableRector::PROPERTIES => true,
    DateTimeImmutableRector::ALLOW_SUBCLASS => false,
])
```

Явное отключение отдельной категории поддерживается для постадийных миграций,
но промежуточная стадия может оказаться не исполняемой, пока связанные
construction и type-объявления не будут мигрированы. Запускайте статический
анализ и тесты после каждой стадии.

Никогда не трогается:

| Кейс | Почему |
|---|---|
| `class X extends \DateTime` (без `ALLOW_SUBCLASS`) | переписывание родителя ломает мутацию на месте у подкласса |
| Сигнатуры и свойства, объявленные предком/интерфейсом/трейтом | реализации обязаны сохранять унаследованные контракты |
| Интерфейсы, трейты, абстрактные классы | их сигнатуры — контракты для реализаций |
| `#[Column]` / `@ORM\Column` mapped-члены | ORM решает конкретный класс по mapping-типу |
| Всё, чей docblock несёт `@mutable-datetime` | явный opt-out-маркер |
| `\DateTime::createFromImmutable(...)` | не имеет `DateTimeImmutable`-аналога; содержащий return-тип также остаётся мутабельным |
| Конструирование внутри anonymous/abstract/trait-скоупов, плюс default'ы, прямые property-присваивания и return'ы, питающие сохранённые мутабельные контракты | не даёт иммутабельному значению инжектиться в пропущенное объявление, не блокируя несвязанные миграции в том же классе/методе |
| Значения, подключённые простыми присваиваниями к стабильному `DateTime`-only callable вроде `date_modify()` или vendor-API | связанные параметры, свойства, return'ы и конструирование остаются мутабельными |
| Union'ы, уже содержащие `\DateTimeImmutable`, включая внутри DNF-intersection | переписывание создаст дублирующий или избыточный тип |
| Return-типы, чей `return` напрямую отдаёт сохранённое мутабельное свойство, вкл. `??`/ternary-ветки | runtime-значение остаётся `DateTime`; мигрированное объявление гарантированно даст `TypeError` |
| Docblock-типы на объявлениях без мигрированного нативного типа | docblock-only-контракт не имеет runtime-доказательства; теги на мигрированных объявлениях синхронизируются автоматически |
| `new $class()`, intersection-типы | статически недоказуемо |

### `LostDateTimeMutationRector`

Находит statement-level вызовы мутаторов на `DateTimeImmutable`, чей return
выбрасывается: `modify`, `add`, `sub`, `setDate`, `setTime`, `setISODate`,
`setTimezone`, `setTimestamp`, `setMicrosecond`.

| Режим | Поведение |
|---|---|
| `MODE_FIX` (по умолчанию) | переписывает `$d->modify(...);` в `$d = $d->modify(...);` для напрямую инициализированных точных built-in-переменных и final-подклассов/union'ов; никогда не присваивает `$this` |
| `MODE_REPORT` | вместо этого навешивает комментарий-маркер `// @todo lost DateTimeImmutable mutation…`; запускайте с `--dry-run`, чтобы валить CI, не трогая код |

```php
->withConfiguredRule(LostDateTimeMutationRector::class, [
    LostDateTimeMutationRector::MODE => LostDateTimeMutationRector::MODE_REPORT,
])
```

Пропускается в обоих режимах: использованные результаты, мутабельные ресиверы,
не-подтипы (включая PHPStan `@mixin`-обёртки) и статически видимые переопределения
мутаторов. Fix-режим также пропускает `$this`, property/call-ресиверы, открытые
объявленные типы вроде параметра `DateTimeImmutable` и локальные значения,
полученные из открытого return-типа: runtime-подкласс может переопределить
мутатор и легально мутировать на месте. Локал становится точным только после
безусловного top-level-присваивания из прямой точной built-in-конструкции,
разделяемой статической фабрики, процедурной `date_create_immutable*()`-фабрики,
`clone` точного значения или иного доказанно точного выражения. Простой алиас
(`$b = $a;`) намеренно не устанавливает точности: в домиграционной мутабельной
программе оба имени разделяли один мутируемый объект, поэтому receiver-only
присваивание могло бы молча разойтись с легаси-поведением — такие statement'ы
остаются в отчёте. Присваивания, вложенные в conditionals, циклы,
switch/try/match-ветки и short-circuit-выражения, никогда не устанавливают
точности и консервативно инвалидируют открытое доказательство. Поэтому
присваивание и потерянная мутация в одной условной ветке могут намеренно
остаться без изменений. Final-подклассы и union'ы final-подклассов безопасно
чинить. Report-режим может диагностически помечать открытый подтип, поскольку
он не меняет программу. Nullsafe-вызовы (`$d?->modify(...)`) вне области
применения.

`MODE_REPORT` пересекается с PHPStan level 4 («call on a separate line has no
effect») — используйте его, только если ваш pipeline гоняет Rector без
статического анализатора.

### Маркеры

Добавьте `@mutable-datetime` в docblock, чтобы намеренно сохранить объявление
мутабельным:

```php
/**
 * @mutable-datetime — third-party SDK mutates this in place
 */
private \DateTime $sdkClock;
```

Добавьте `@mutable-datetime-boundary` как комментарий на statement-вызова, чтобы
отметить проверенный boundary-call — предполёт тогда его пропустит.
`--acknowledge-boundaries` пишет эти комментарии за вас:

```php
// @mutable-datetime-boundary: parameter $object requires DateTime
date_modify($moment, '+1 hour');
```

## Безопасность

Это меняющая контракт миграция. Дефолты мигрируют construction и конкретные
локальные объявления вместе. Типизированные native/vendor/inherited callable-границы,
унаследованные свойства/сигнатуры, ORM-mapping'и и динамические имена
охраняются. Динамические вызовы, magic-диспетчер, рефлексия и нетипизированные
внешние data-flow'ы не могут быть доказаны source-to-source-правилом.
Ревьюньте diff и запускайте полную сборку проекта после каждого прохода,
особенно при использовании постадийных опций или `ALLOW_SUBCLASS`, которые
намеренно меняют runtime-поведение подклассов `DateTime`.

## Примеры

Исполняемые скрипты — в [`examples/`](examples/README.md).

## Разработка

```bash
make install   # composer install (Docker, no local PHP needed)
make build     # validate + normalize + require-checker + cs + psalm + tests
make test      # testo (unit + e2e fixtures)
make mutation  # infection, minMsi=100 — gates the Internal/ decision core;
               # the rule shells run inside rector subprocesses and are
               # covered by the e2e fixture suites instead
```

Мутационное тестирование по дизайну ограничено `src/Internal/`: ядро принятия
решений (каталог мутаторов, фабричный map, type/docblock-переписчики, детектор
Doctrine-столбцов, matcher маркеров) работает in-process и гейтится
`minMsi = 100`. Публичные классы правил и CLI исполняются внутри subprocess'ов
Rector, которые Infection наблюдать не может — они покрываются e2e fixture-наборами
(байтовое сравнение вывода, `php -l` на каждом трансформированном файле,
исполняемые runtime-фикстуры). Поэтому числа Infection сертифицируют ядро
`Internal/`, а не package-wide mutation-score; обоснование — см. [AGENTS.md](AGENTS.md).

## Лицензия

[BSD-3-Clause](LICENSE.md)
