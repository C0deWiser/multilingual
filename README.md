# Multilingual Cast

Multilingual attribute keeps a set of values in different locales.
Such an attribute is stored in a database as a JSON object.

| id | name                           |
|----| ------------------------------ |
| 1  | {"en":"Michael","es":"Miguel"} |

`Multilingual` lets you deal with such an attribute just like a plain one:

```php
$user->name;
// Michael

app()->setLocale('es');

$user->name = 'Miguel';

$user->name;
// Miguel

app()->setLocale('en');

$user->name;
// Michael
```

## Usage

Apply the cast and include `HasMultilingual`, which gives access to the lowdown
of a multilingual attribute.

```php
use Illuminate\Database\Eloquent\Model;
use Codewiser\Multilingual\Casts\Multilingual;
use Codewiser\Multilingual\Casts\AsMultilingual;
use Codewiser\Multilingual\Traits\HasMultilingual;

/**
 * @property null|string $name
 */
class User extends Model
{
    use HasMultilingual;

    protected function casts(): array
    {
        return [
            'name' => AsMultilingual::class
        ];
    }
}
```

## Storing

A new value will be implicitly stored in the current locale.

However, you may explicitly define a locale.

```php
// Set value in current locale
$user->name = 'Michael';

// Set value with explicit locale
$user->withLocale('en', fn() => $user->name = 'Michael');
$user->withLocale('es', fn() => $user->name = 'Miguel');

// Set values as array (or Arrayable) to replace all values
$user->name = [
    'en' => 'Michael',
    'es' => 'Miguel',
];
```

## Reading

A plain value will be implicitly retrieved in the current locale. It is enough
to properly apply the `Accept-Language` header from a User-Agent — and the user
will get content in a preferred language.

If there is no value for the requested locale, the fallback locale is tried
next, and then the first value in the map — even when that value is empty.

You may explicitly define a locale.

```php
// Get value in current locale
$name = $user->name;

// Get value in given locale
$nameInEn = $user->withLocale('en', fn() => $user->name);
$nameInEs = $user->withLocale('es', fn() => $user->name);
```

## Hydrating

Reading gives a plain value, so ask for the `Multilingual` object when you need
another locale, or every locale at once.

```php
// One attribute
$user->multilingual('name');
// Multilingual<string>

// Several attributes at once
$user->multilingual(fn() => [$user->name, $user->description]);

// From outside the model
Multilingual::of(fn() => $user->name);
```

A hydrated attribute gives back the whole object.

```php
$user->multilingual('name')->get();
// 'Michael'

$user->multilingual('name')['es'];
// 'Miguel'

$user->multilingual('name')->missing(['en', 'es', 'it']);
// ['it']

$user->multilingual('name')->toArray();
// ['en' => 'Michael', 'es' => 'Miguel']

$user->multilingual('name')->isEmpty();
// false
```

Hydrating a non-multilingual attribute throws an `InvalidArgumentException`.

## Serializing

Serialization is never hydrated implicitly — `$model->toArray()` holds plain
values.

```php
$user->toArray();
// ['id' => 1, 'name' => 'Michael']

$user->multilingual(fn() => $user->toArray());
// ['id' => 1, 'name' => ['en' => 'Michael', 'es' => 'Miguel']]
```

Ask for it explicitly wherever every locale belongs in the output.

## Language tags

You may access a hydrated `Multilingual` using language tags as well. The
package uses
[locale_filter_matches](https://www.php.net/manual/en/locale.filtermatches.php)
to find the best variant:

```php
$user->name = [
    'en'    => 'Michael',
    'en_US' => 'Mike',
];

$user->multilingual('name')['en'];
// Michael
$user->multilingual('name')['en_GB'];
// Michael
$user->multilingual('name')['en-US'];
// Mike
$user->multilingual('name')['it'];
// null
```

## Strict mode

If you try to get a value that is missing, the `Multilingual` will try to
return any convenient value — using the fallback locale or just the first one:

```php
$user->name = [
    'en' => 'Michael',
    'es' => 'Miguel'
];

$user->withLocale('it', fn() => $user->name);
// Michael (using fallback locale)
```

You may enable `strict` mode, and then `Multilingual` will return the exact
value:

```php
use Illuminate\Database\Eloquent\Model;
use Codewiser\Multilingual\Casts\Multilingual;
use Codewiser\Multilingual\Casts\AsMultilingual;
use Codewiser\Multilingual\Traits\HasMultilingual;

/**
 * @property null|float $score
 */
class User extends Model
{
    use HasMultilingual;

    protected function casts(): array
    {
        return [
            'score' => AsMultilingual::strict()
        ];
    }
}

$user->score = [
    'en' => 1.0,
    'es' => 1.1
];

$user->withLocale('en', fn() => $user->score);
// 1

$user->withLocale('it', fn() => $user->score);
// null (exact value, no fallback)
```

## Multilingual arrays

As we may keep multilingual scalars, we may keep multilingual arrays as well:

```php
use Illuminate\Database\Eloquent\Model;
use Codewiser\Multilingual\Casts\Multilingual;
use Codewiser\Multilingual\Casts\AsMultilingual;
use Codewiser\Multilingual\Traits\HasMultilingual;

/**
 * @property null|array $keywords
 */
class User extends Model
{
    use HasMultilingual;

    protected function casts(): array
    {
        return [
            'keywords' => AsMultilingual::class
        ];
    }
}

$user->keywords = [
    'en' => ['one', 'two'],
    'es' => ['uno', 'dos'],
];

$user->withLocale('en', fn() => $user->keywords);
// ['one', 'two']

$user->withLocale('es', fn() => $user->keywords);
// ['uno', 'dos']

$user->multilingual('keywords')['es'][0];
// 'uno'

$user->multilingual('keywords')->toArray();
// ['en' => ['one', 'two'], 'es' => ['uno', 'dos']]
```

## Multilingual objects

Multilingual array could be
[mapped into](https://laravel.com/framework/docs/13.x/collections#method-mapinto)
an object:

```php
use Illuminate\Database\Eloquent\Model;
use Codewiser\Multilingual\Casts\Multilingual;
use Codewiser\Multilingual\Casts\AsMultilingual;
use Codewiser\Multilingual\Traits\HasMultilingual;

/**
 * @property null|Username $name
 */
class User extends Model
{
    use HasMultilingual;

    protected function casts(): array
    {
        return [
            'name' => AsMultilingual::using(Username::class)
        ];
    }
}

$user->name = [
    'en' => ['first_name' => 'John', 'last_name' => 'Smith'],
    'es' => ['first_name' => 'Juan', 'last_name' => 'Herrera'],
];

$user->withLocale('en', fn() => $user->name);
// Username(['first_name' => 'John', 'last_name' => 'Smith'])

$user->withLocale('es', fn() => $user->name);
// Username(['first_name' => 'Juan', 'last_name' => 'Herrera'])
```

### Changing a mapped object

The very same object is handed out on every read, so changing it changes the
attribute. The change is written back right before the attributes are read,
dirtied, serialized or saved, so it behaves like any other change of the model.

```php
$user->name->first_name = 'Johnny';

$user->name->first_name;
// Johnny, the change was not thrown away by the next read

$user->isDirty('name');
// true

$user->multilingual('name')->toArray();
// ['en' => ['first_name' => 'Johnny', ...], 'es' => ['first_name' => 'Juan', ...]]

$user->save();
// the change is in the database
```

Assigning the attribute replaces the object, so a change made to the old one is
gone:

```php
$hydrated = $user->name;

$user->name = ['en' => ['first_name' => 'Jane', 'last_name' => 'Doe']];

$hydrated->first_name = 'Nobody';
// does not touch the attribute anymore
```

The write back hangs on `getAttributes()`, which is what `getDirty()`,
`toArray()`, `save()` and friends funnel through. A model defining its own
`getAttributes()` overrides the one from the trait, and then it has to call
`flushMultilingualAttributes()` itself.

## Multilingual collection

You may cast an attribute to a collection of `Multilingual` values. It is
possible to map every row into an object. Strict mode is supported as well:

```php
use Illuminate\Database\Eloquent\Model;
use Codewiser\Multilingual\Casts\AsMultilingual;
use Codewiser\Multilingual\Traits\HasMultilingual;
use Illuminate\Support\Collection;

/**
 * @property null|Collection<int, Username> $names
 */
class User extends Model
{
    use HasMultilingual;

    protected function casts(): array
    {
        return [
            'names' => AsMultilingual::collect(Username::class, strict: true),
        ];
    }
}

$user->names = [
    ['en' => ['first_name' => 'John', 'last_name' => 'Smith']],
    ['en' => ['first_name' => 'Gregory', 'last_name' => 'Johnson']],
];

$user->names->first();
// Username(['first_name' => 'John', 'last_name' => 'Smith'])

$user->multilingual('names')->first();
// Multilingual<Username> holding the very same object

$user->names->first()->first_name = 'Johnny';
// changes the attribute, just like a mapped attribute does
```

## Multilingual collection (Laravel's cast)

It is possible to cast `Multilingual` to a collection using Laravel's
`AsCollection`:

```php
use Illuminate\Database\Eloquent\Model;
use Codewiser\Multilingual\Casts\Multilingual;
use Codewiser\Multilingual\Traits\HasMultilingual;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Casts\AsCollection;

/**
 * @property null|Collection<int, Multilingual<string>> $keywords
 */
class User extends Model
{
    use HasMultilingual;

    protected function casts(): array
    {
        return [
            'keywords' => AsCollection::of(Multilingual::class)
        ];
    }
}

$user->keywords = [
    ['en' => 'one', 'es' => 'uno'],
    ['en' => 'two', 'es' => 'dos'],
];

$user->keywords->first()->get();
// one

$user->withLocale('es', fn() => $user->keywords->first()->get());
// uno
```

`AsCollection::of()` maps into the class directly, so it never routes through
the cast. Elements are always `Multilingual` objects here.

The attribute is not recognised as multilingual though, so `multilingual()`
throws an `InvalidArgumentException` for it, and `Multilingual::of()` leaves it
untouched. Use `AsMultilingual::collect()` above when the whole collection has to
be hydrated.
