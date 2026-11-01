# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- Mutation testing now counts code the tests execute without naming it in `#[Covers]`
  (`infection --with-uncovered`), with `PublicVisibility` off and default-parameter
  signature lines ignored; unit test added for the docblock splitter failing (`preg_split` returning false). No `src/` changes.

## 1.0.1 — 2026-09-27

- Package `type` changed to `rector-extension` so the rule pack is discoverable on Packagist (`type=rector-extension`) and recognised by `rector/extension-installer`. No rules are auto-applied: no `extra.rector.includes` key is declared, so `rector.php` still opts in explicitly.

## 1.0.0 — 2026-07-15

- Initial release: `DateTimeImmutableRector`, `LostDateTimeMutationRector`,
  `MutableDateTimeBoundaryRector` and the `rector-datetime-immutable`
  convergence CLI.
