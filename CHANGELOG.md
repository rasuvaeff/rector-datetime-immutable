# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.1 — 2026-09-27

- Package `type` changed to `rector-extension` so the rule pack is discoverable on Packagist (`type=rector-extension`) and recognised by `rector/extension-installer`. No rules are auto-applied: no `extra.rector.includes` key is declared, so `rector.php` still opts in explicitly.

## 1.0.0 — 2026-07-15

- Initial release: `DateTimeImmutableRector`, `LostDateTimeMutationRector`,
  `MutableDateTimeBoundaryRector` and the `rector-datetime-immutable`
  convergence CLI.
