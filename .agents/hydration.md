# Hydration

`$model->name` returns a plain value in the current locale. Wrapping the read
returns the whole map instead:

    $model->name;                            // 'Michael'
    $model->multilingual('name');             // Multilingual<string>
    $model->multilingual('name')->get();     // 'Michael'
    $model->multilingual('name')['es'];      // 'Miguel'

`AsCollection` behaves the same way for a list of maps: hydrated it returns a
`Collection` of `Multilingual`, unhydrated a `Collection` of plain values.

Hydrating several attributes at once — or serialization — needs the callback
form, which is the only one that takes a `Closure`:

    $model->multilingual(fn () => [$model->name, $model->description]);
    $model->multilingual(fn () => $model->toArray());

## The flag

`Hydrate` is one property. `protected static bool $hydrate`, saved, set, and
restored in a `finally`, so `of()` nests and nothing leaks out of an exception.

    $hydrated = self::$hydrate;
    self::$hydrate = true;
    try { return $callback(); } finally { self::$hydrate = $hydrated; }

`hydrated()` is what both casts read to decide what to return. It is
`self::$hydrate`, not `static::`, so every class using the trait shares one flag
— which is what lets `AsCollection::get()` ask `Multilingual::hydrated()` about a
flag it never set.

The cast builds the object either way; the flag only decides whether `get()`
returns it or `$instance->get()`. That is deliberate: the object has to exist
before hydration is asked for, because the registry hands the same one out on
every read (see `testHydratedObjectIsTheSameOnEveryRead`).

## The problem

A mapped object is mutable, and mutating it tells the model nothing:

    $model->name;                     // Username(['first_name' => 'John', ...])
    $model->name->first_name = 'Johnny';
    $model->name->first_name;         // 'Johnny', from the object
    $model->getDirty();               // [] — the model never heard of it
    $model->save();                   // nothing written

Laravel does not help here. With `withoutObjectCaching = true` (set in
`CastsMultilingual`) it unsets `classCastCache[$key]` on every read, so the
object is rebuilt from the raw JSON on every single attribute access — see
[casts.md](casts.md).

## The registry

`CastsMultilingual`, used by `Multilingual`, keeps one entry per model and
attribute: the raw value it was built from, and the object built out of it.

    static::hydrate($model, $key, $raw, $build)  // get-or-build
    static::store($model, $key, $raw, $object)   // remember it
    static::entries($model)                      // list
    static::drop($model, $key = null)            // forget one / all

`object` is a `Multilingual` or, for a collection cast, a `Collection` of them —
which is why `hydrate()` has no `static` in its return type and `store()`
type-hints the union.

The cast passes the raw value in, so the registry never has to re-read it.
`hydrate()` is called unconditionally, even on an unhydrated read, so a model
that is read plainly and then hydrated gets the same object back rather than a
second one.

## The invariant

> An entry is good only while `attributes[$key]` still strictly equals the raw
> value the object was built from.

One check covers every way the attribute can move away, because they all end up
writing to `$model->attributes` or replacing it:

| Action | What happens to `attributes[$key]` |
| --- | --- |
| `setAttribute()`, `offsetSet()`, `offsetUnset()` | new string via the cast |
| `unset()` | key gone |
| `setRawAttributes()`, `refresh()`, `replicate()` | whole array replaced |
| `discardChanges()` | whole array replaced by the original |

A mismatch means the object is stale, so it is dropped instead of written back.
That is what makes assigning an attribute win over an object handed out earlier.

`===` is strict on purpose. A cast that wrote `null` over `'[]'` is a different
raw value, and treating it as unchanged would resurrect an object the attribute
no longer describes.

## The write-back

`flushMultilingualAttributes()` loops the registry and re-encodes each live
object through the cast. It is called from exactly one place: the
`getAttributes()` override in `HasMultilingual`.

That single hook is enough because everything funnels through it —
`getDirty()`, `isDirty()`, `toArray()`, `save()`, `insert`, `syncOriginal()`,
`replicate()`. And `setAttribute()` does *not* call `getAttributes()`, so there
is no recursion.

Writing back through `setAttribute()` rather than by hand keeps the encoding in
one place. The raw value then moved, so the entry is re-stored against the new
raw value.

## Empty values

`Multilingual::jsonSerialize()` returns `null` for an empty map, so writing an
emptied object back stores SQL `NULL`. An untouched empty attribute is `'[]'`
in the database, so writing it back would dirty a column nobody changed. Hence
the guard: skip when the object is empty *and* the raw value is empty too.
`isEmptyMultilingualValue()` is the model-side half of that check, and it
decodes the JSON rather than comparing strings, so `[]` and `null` agree.

## Serialization is not implicitly hydrated

`$model->toArray()` holds plain values, always. Nothing in `attributesToArray()`
goes through `of()`, so the default serialized shape of a multilingual model
matches an uncast one and existing consumers do not suddenly receive a map
instead of a string.

Hydrating for output is therefore opt-in:

    $model->multilingual(fn () => $model->toArray());

## WeakMap

The registry is a static `WeakMap` keyed by model instance. Weak, so a
discarded model takes its objects with it and nothing is left behind after the
request. It lives in `CastsMultilingual`, so the caster works on any model.

The cost is one live `Multilingual` per translated attribute per model that was
read, held for the rest of the request — worth remembering when a page hydrates
a large result set.

## Dead ends

- **`remember()`, `forget()`, `put()` as the registry API.** `Illuminate\Support\Collection`
  already has those as non-static methods, and redeclaring them static is a
  fatal error. Hence `hydrate()` / `drop()` / `store()`.
- **`classCastCache` instead of our own registry.** Caching is per model
  instance and cleared in `setRawAttributes()` (HasAttributes:2047) and
  `discardChanges()` (:2225), and it stores no raw snapshot to compare
  against, so it cannot express the invariant above. Enabling caching instead
  also pushes our object back through `set()` from `mergeAttributesFromClassCasts()`
  (:1925), called by `syncOriginal()`, which duplicates the write-back path.
- **A `HydratedMultilingualCollection` subclass.** `AsCollection` returns a plain
  `Collection` and hydration is a flag on the return value, not a type. A
  subclass would have to survive `json_encode()` and the cast's own
  reconstruction, and `testCollectionGivesAMultilingualCollectionWhenHydrated`
  only ever asserts `Collection`. The name in that assertion is historical.

## Known limitations

- A model that defines its own `getAttributes()` overrides the trait's one and
  silently loses the write-back. `flushMultilingualAttributes()` has to be called
  from there.
- `multilingual($key)` only accepts a direct `AsMultilingual`/`AsCollection`
  cast. Laravel's own `AsCollection::of()` is rejected — it never routes through
  our cast, so there is no raw snapshot to register and nothing to write back.