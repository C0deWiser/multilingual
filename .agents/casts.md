# Eloquent cast internals

Line numbers are for `laravel/framework` v12.69.3,
`src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php`. They drift with
every framework release, so re-grep rather than trusting them.

## The cast string

`resolveCasterClass()` (:1861) splits on the first `:` and then on `,`:

    $segments = explode(':', $castType, 2);
    $castType = $segments[0];
    $arguments = explode(',', $segments[1]);

so `AsMultilingual:Codewiser\Tests\Username,strict` arrives as
`castUsing(['Codewiser\Tests\Username', 'strict'])`. Arguments are positional
but `CastsMultilingual::__construct` pads to two and looks for the literal
string `strict` with `in_array()`, so strict is matched by value and its position
does not matter. `AsMultilingual::using()` joins a callable array as
`Class@method` before building the string — that is what keeps a callback from
being split into two arguments by the comma.

## A caster is rebuilt on every read and every write

`resolveCasterClass()` ends with `return new $castType(...$arguments);` (:1882),
and `AsMultilingual::castUsing()` returns `new class($arguments)`. So a
`CastsAttributes` instance lives for one call.

Consequence: per-model state cannot live on the caster. Which object was handed
out for which model and attribute has to be kept somewhere else — see
[hydration.md](hydration.md).

## classCastCache

`getClassCastableAttributeValue()` (:904):

    if (isset($this->classCastCache[$key]) && ! $objectCachingDisabled) {   // :910
        return $this->classCastCache[$key];
    } else {
        $value = $caster->get($this, $key, $value, $this->attributes);
        if ($caster instanceof CastsInboundAttributes
            || ! is_object($value) || $objectCachingDisabled) {
            unset($this->classCastCache[$key]);                             // :920
        } else {
            $this->classCastCache[$key] = $value;
        }
        return $value;
    }

`CastsMultilingual` sets `withoutObjectCaching = true`, so the cache is dropped
on every read and the object is rebuilt from JSON every time. That is the reason
this package needs its own registry.

`setClassCastableAttribute()` (:1249) re-casts on every assignment and unsets
the cache key the same way (:1263).

## mergeAttributesFromClassCasts

`mergeAttributesFromClassCasts()` (:1925) walks `classCastCache` and feeds each
entry back through `$caster->set()`. It is reached from `syncOriginal()`
(:2142), which does `$this->original = $this->getAttributes()`.

Relevant because it means a cached object would be written back by Laravel
itself — one more reason the registry is ours.

## discardChanges

`HasAttributes::discardChanges()` (:2221) resets `classCastCache` and
`attributeCastCache` (:2225). `HasMultilingual::discardChanges()` mirrors that for
our registry via `CastsMultilingual::drop($this)`.

## Set semantics

`AsMultilingual::set()` treats its input as one of three things:

    $model->name = ['en' => 'Michael'];        // associative → full replace
    $model->name = ['Michael', 'Johnny'];      // a list → current locale only
    $model->name = 'Michael';                  // a scalar → current locale only

The discriminator is `array_is_list()`, because a multilingual **array** value
(`['en' => ['one','two']]`) has to be assignable without being mistaken for the
whole map. A list therefore lands in the current locale as the locale's value.

Handing a whole `Multilingual` to `set()` replaces the map — `jsonSerialize()`
answers an associative array — which is what makes the write-back safe to route
through `setAttribute()`.

An empty map serializes to `null`, so an emptied attribute is stored as SQL
`NULL` while an untouched empty attribute stays `'[]'` in the database. The same
happens when the last surviving translation is falsy: overwriting the only
translation with `false` empties the map and the column is dropped.

## Strict

`strict` is not a second code path, only a flag on the object:
`new Multilingual($value, $this->strict)`. The locale is resolved *during*
`get()`, so the flag has to arrive with the cast string, not be applied later —
`testCastGetPropagatesStrict` pins that.

## Two classes called AsCollection

Laravel ships `Illuminate\Database\Eloquent\Casts\AsCollection` and so does this
package. They are not interchangeable:

| | Laravel `AsCollection::of(Multilingual::class)` | ours `AsCollection::of()` / `AsMultilingual::collect()` |
| --- | --- | --- |
| routes through `get()` | no, maps directly | yes |
| unhydrated read | `Multilingual` objects | plain value per item |
| mapped objects mutable through the attribute | no | yes |
| accepted by `multilingual()` | no, throws | yes |

`HasMultilingual::isMultilingual()` matches on the fully qualified class name,
which is the only thing telling the two apart since both are spelled
`AsCollection`. It splits the cast string on `,` and takes the part before `:`,
so `using(Username::class)`, `strict()` and `collect(Username::class)` are all
recognized. Keep the `in_array(..., true)` — a loose comparison here would match
every cast on a `Class@method` argument.

## json_encode and Cyrillic

`AsMultilingual::set()` calls plain `json_encode()` with no flags, so
non-ASCII is stored escaped: `{"ru":"\u0418\u0432\u0430\u043d"}`. Test
expectations must use the escaped form. Adding `JSON_UNESCAPED_UNICODE` would
be nicer on disk but is a separate decision.

The registry holds a `Collection` for a collection cast, and re-encoding goes
through the same `json_encode()` — `json_encode` calls `jsonSerialize()` on each
`Multilingual` itself, which is how a change to a mapped object inside a
collection reaches the column.

## Test database

`phpunit.xml` sets `DB_CONNECTION=testing` and `DB_DATABASE=:memory:`, so
persistence tests create their own table with `Schema::create()` in `setUp()`
— there are no migrations under `workbench/database/migrations`.

Use a `text` column, not `json`: sqlite will happily store a JSON string into
either, but a `json` column adds type affinity that only confuses the
assertions.

`TestMultilingualModel` declares `protected $attributes` with `'[]'` for every
translated column. That is what makes a fresh model read as empty instead of
null, and it is why `testReadingAnEmptyAttributeDoesNotDirtyTheModel` can assert
the column still holds `'[]'` rather than being rewritten to `NULL`.