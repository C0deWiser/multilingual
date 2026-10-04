# Locales

A locale is just an array key. The only ICU in this package is
`locale_canonicalize()` and `locale_filter_matches()`, both `ext-intl` functions
called from `Multilingual::search()`. Verified on PHP 8.2.30, ICU 78.1; the
failure modes below are structural rather than version-specific.

## The four steps of a lookup

`search($values, $locale)`:

1. canonicalize, then look for an **exact key**
2. truncate the locale at the first `_`, leaving a language
3. keep every stored key that `locale_filter_matches($key, $truncated)`, shortest
   first
4. otherwise `null`

The exact-key stage is load-bearing, not an optimization. With `en_GB` and
`en_US` stored, a request for `en_US` returns `en_US`; without stage 1 the
filter would see two equal-length matches and return `en_GB`.

## Canonicalization is not validation

`locale_canonicalize()` never fails and never returns null:

| Input | Output |
| --- | --- |
| `ru-RU` | `ru_RU` |
| `EN_gb` | `en_GB` |
| `sr_Latn_RS` | `sr_Latn_RS` — it does not fold down to a language |
| `de-DEVA` | `de_Deva` — the script subtag is title-cased |
| `ca_ES@valencia` | `ca_ES_VALENCIA` — the variant is uppercased |
| `und`, `root` | `''` |
| `''` | **`en_US_POSIX`** |
| `!!!` | `!!!` |

So garbage in is garbage out, an empty string resolves to a real locale rather
than to nothing, and a script subtag comes back with different casing than it
went in. Consequence: a key stored as `de-DEVA` is *not* reachable through the
exact-key stage and only through language matching. Do not add an
`is_string()` guard and assume it filters bad input.

## The filter is asymmetric

`locale_filter_matches($locale, $lang)` answers "is `$lang` a prefix of
`$locale`", never the reverse:

| Call | Result |
| --- | --- |
| `locale_filter_matches('en_GB', 'en')` | `true` |
| `locale_filter_matches('en', 'en_GB')` | `false` |
| `locale_filter_matches('de_DEVA', 'de_DE')` | `false` |
| `locale_filter_matches('ru', 'ru_RU')` | `false` |

Hence the argument order in `search()`: the stored key is first, the requested
locale is second. And hence step 2 — the requested locale has to be reduced to a
language before it is usable as a filter, or a request for `en_US` never matches
a stored `en_GB`. The test is `strpos($locale, '_') > 0`, so a locale with no
`_` passes through untouched and a leading `_` is not truncated either.

## Shortest match wins

Matches are sorted by `strlen` ascending. For a request of `de`, `de_DE` beats
`de_DEVA`: the least specific variant wins over a more specific one, the same way
`en` beats `en_GB`. Ties return `0` from the comparator and PHP sorts are stable,
so equal-length keys keep their stored order. `testGet` pins all of it.

## Where the key goes

| | canonicalizes the key? |
| --- | --- |
| constructor | no, keys are stored verbatim |
| `offsetSet()`, `offsetUnset()` | yes |
| `offsetGet()` | yes, then falls back to language matching |
| `offsetExists()` | no, delegates to `search()` |

So `['en_GB' => 'a', 'en-GB' => 'b']` keeps two values, while `$alt['en-GB']`
finds either one of them. `offsetExists()` goes through `search()` on purpose,
so it agrees with `offsetGet()` instead of disagreeing on raw keys.
`testKeysThatCanonicalizeAlikeAreBothKept` and
`testOffsetExistsAgreesWithOffsetGet` hold this in place — do not tidy the
constructor to canonicalize as well.

## get() adds fallbacks, array access does not

`get()` retries `search()` against the fallback locale and then `reset($values)`,
but only when `strict` is off. `offsetGet()`, `offsetExists()` and `missing()` go
through `search()` alone and never fall back.

That asymmetry is the feature: `$alt['it']` answers `null` where `get()` would
answer anything convenient, and `missing(['en_GB'])` agrees with `offsetGet()`.

Two traps in the `get()` fallback:

- `reset($values)` on `[]` is `false`, not `null`, so the empty map needs the
  explicit `$values === [] ? null :` guard or an empty attribute would report
  `false` as a translation.
- `Multilingual::isEmpty()` uses `array_filter`, and `array_filter(['a' => 0])`
  is `[]`. A translation of `0` or `'0'` therefore does not count, which is
  consistent with `empty('0')` but surprising for a numeric attribute.

## Strict

`strict` removes those two fallbacks and nothing else. Language tags still
resolve under it — `en-GB` still finds the stored `en_GB` — so strict means "no
convenient value", not "exact key only". It travels as a literal `strict`
argument in the cast string and is handed to the object at `get()` time, because
a plain read resolves the locale during `get()` rather than later.

## Backed enums

Every locale-shaped argument — `missing()` and all four `offset*` methods —
unwraps a `BackedEnum` to `->value` first. `missing()` then echoes the input
back into the result: enums come back as enums and a raw `de-DEVA` comes back
uncanonicalized. Deliberate, since the question being asked is "which of *these*
are missing" and the answer has to be in the caller's own vocabulary. Note the
inconsistency that follows: `present()` returns raw keys, never canonicalized
and never enums.

## Container, not app

`getLocale()` and `getFallbackLocale()` read `Container::getInstance()` and
answer `'en'` when it is not a `Foundation\Application` — a bare container
carries no locale state. Canonicalization is applied to the `'en'` fallback too,
so a container-less read still gets a canonical locale.

## Moved out

The `ext-intl` formatters (`DateFormatter`, `NumberFormatter`,
`CurrencyFormatter`, `LocaleFormatter`, `Transliterator`, `PeriodFormatter`),
the `lang/{en,ru}` relative and calendar messages, and the `intl()` helper were
part of this package until it was split in two. Their failure modes — the
unconstructed `IntlDateFormatter` on an unknown locale, the bare zero swallowed
by `?:`, the missing `other` branch in a `select`, the subtag readers that are
not named symmetrically with the display ones — are documented in the
`codewiser/intl` repository now, and none of it applies here.