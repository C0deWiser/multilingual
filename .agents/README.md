# Codewiser\Multilingual

Laravel casts for localized attributes. An attribute holds a map of locales to
values, and the cast hands out a plain value in the current locale unless
hydration is asked for.

This was `codewiser/intl` and shipped a set of `ext-intl` formatters next to the
casts. Those moved into their own package; what is left is the casts and the
model trait. `codewiser/intl` and `codewiser/casts` are now in `suggest`, there
is no service provider to register (`composer.json` dropped `extra.laravel`), and
`src/helpers.php` with the `intl()` helper is gone.

## Commands

    vendor/bin/phpunit --no-coverage          # whole suite
    vendor/bin/phpunit --filter Multilingual # one group
    php -l src/Casts/Multilingual.php         # lint

## Layout

    src/Casts/     Multilingual, Hydrate, AsMultilingual, AsCollection,
                   CastsMultilingual
    src/Traits/    HasMultilingual (model side)
    tests/         flat, namespace Codewiser\Tests\ — MultilingualTest and
                   MultilingualPersistenceTest, plus the TestMultilingualModel,
                   TestLocale and Username fixtures

Namespaces are flat: the PSR-4 root is `Codewiser\Multilingual\` mapped to
`src/`, so `src/Casts/X.php` is `Codewiser\Multilingual\Casts\X`. The doubled
`Codewiser\Intl\Intl\*` namespace is gone with the old name, so there is nothing
left to leave alone.

There is no `lang/` directory, no `tests/Translator.php` and no
`workbench/database/migrations` — persistence tests build their own table.

## Conventions

- Comments in `src/` are short: what a thing is and how to use it. Reasoning,
  dead ends and framework internals belong in these notes, not in the code.
- Tests may be verbose, and assertion messages spell out what is being checked.
- PHP 8.2+, `laravel/framework` >= 12, `ext-intl` required — but for exactly two
  functions, `locale_canonicalize()` and `locale_filter_matches()`, see
  [locales.md](locales.md).

## Notes

- [hydration.md](hydration.md) — live objects, invalidation, write-back
- [casts.md](casts.md) — Eloquent cast internals that shaped this package
- [locales.md](locales.md) — locale keys, language tags, ext-intl behaviour

## Known gaps

- `README.md` still imports `Codewiser\Intl\Casts\*` and `Codewiser\Intl\Traits\*`.
  Every code sample in it is broken until it moves to the new namespace.
- `tests/MultilingualTest.php:407` has a leftover `dump($casted)` in
  `testCastGet`, so the suite prints a value mid-run.
- `testMultilingualAcceptsCollectionAttributes` asserts
  `InvalidArgumentException`, which is the opposite of its name. The behaviour is
  correct (Laravel's `AsCollection` is rejected, see [hydration.md](hydration.md));
  only the name lies.