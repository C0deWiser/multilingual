<?php

namespace Codewiser\Multilingual\Casts;

use ArrayAccess;
use BackedEnum;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Traits\Localizable;
use JsonSerializable;

/**
 * Multilingual attribute holds an array of localized values:
 *
 * [
 *  'en' => 'Michael',
 *  'ru' => 'Михаил',
 *  'es' => 'Miguel'
 * ]
 *
 * It may hold localized arrays too:
 *
 * [
 *   'en' => ['one', 'two'],
 *   'ru' => ['раз', 'два'],
 *   'es' => ['uno', 'dos']
 * ]
 *
 * Reading a casted attribute gives a plain value in the current locale:
 *
 *     $model->name; // 'Michael'
 *
 * Wrap the read to get this object instead:
 *
 *     $model->multilingual(fn () => $model->name); // Multilingual<string>
 *     $model->multilingual('name');                // Multilingual<string>
 *
 * @see Hydrate::of()
 *
 * @template TType of scalar|array
 */
class Multilingual implements Arrayable, JsonSerializable, ArrayAccess
{
    use Hydrate;
    use Localizable;

    protected function app(): ContainerContract|ApplicationContract
    {
        return Container::getInstance();
    }

    public function getLocale(): string
    {
        $app = $this->app();

        return locale_canonicalize($app instanceof Application ? $app->getLocale() : 'en');
    }

    public function getFallbackLocale(): string
    {
        $app = $this->app();

        return locale_canonicalize($app instanceof Application ? $app->getFallbackLocale() : 'en');
    }

    /**
     * Locale keys are stored as given, so `en-GB` and `en_GB` may both be kept.
     *
     * @param  array<string, TType>  $values
     * @param  bool  $strict  Do not fall back to the fallback locale or the first value.
     */
    public function __construct(protected array $values, protected bool $strict = false)
    {
        //
    }

    public function __toString(): string
    {
        $value = $this->get() ?? '';

        if (! is_string($value)) {
            $value = json_encode($value);
        }

        return $value;
    }

    /**
     * Get value in current locale.
     *
     * @return null|TType
     * @deprecated use get()
     */
    public function toString()
    {
        return $this->get();
    }

    /**
     * @param  array  $values
     * @param  string  $locale  May be as locale, as language tag.
     *
     * @return null|mixed
     */
    protected function search(array $values, string $locale)
    {
        $locale = locale_canonicalize($locale);

        // Direct match
        if (isset($values[$locale])) {
            return $values[$locale];
        }

        // If locale was a language tag?
        $locale = ($i = strpos($locale, '_')) > 0
            ? substr($locale, 0, $i)
            : $locale;

        // Filter matches
        $matches = array_filter(
            array_keys($values),
            fn($lang) => locale_filter_matches($lang, $locale),
        );

        // From short to long
        usort($matches, function ($a, $b) {
            if (strlen($a) == strlen($b)) {
                return 0;
            }
            return (strlen($a) < strlen($b)) ? -1 : 1;
        });

        if ($matches) {
            // Return best match
            return $values[$matches[0]];
        }

        return null;
    }

    /**
     * Get value in current locale.
     *
     * @return null|TType
     */
    public function get()
    {
        $values = $this->values;

        $value = $this->search($values, $this->getLocale());

        if ($value === null && ! $this->strict) {
            // current([]) is false, not null, so the empty case needs a guard.
            $value = $this->search($values, $this->getFallbackLocale())
                ?? ($values === [] ? null : reset($values));
        }

        return $value;
    }

    /**
     * Has no values?
     */
    public function isEmpty(): bool
    {
        return count(array_filter($this->values)) === 0;
    }

    /**
     * Get locales without translations.
     *
     * @param  string|array<array-key, string|BackedEnum>  $locales  Locales to inspect.
     *
     * @return array<int, string|BackedEnum> Locales without translations, re-indexed.
     */
    public function missing(string|BackedEnum|array $locales): array
    {
        if (is_string($locales) || $locales instanceof BackedEnum) {
            $locales = [$locales];
        }

        // search() canonicalizes and unwraps enums itself.
        return array_values(array_filter(
            $locales,
            fn($locale) => $this->search(
                    $this->values,
                    $locale instanceof BackedEnum ? $locale->value : $locale
                ) === null
        ));
    }

    /**
     * Get locales with translations.
     *
     * @return array<int, string>
     */
    public function present(): array
    {
        return array_keys($this->values);
    }

    /**
     * Run a map over each of the items.
     */
    public function map(callable $callback): static
    {
        return new static(Arr::map($this->values, $callback), $this->strict);
    }

    /**
     * Map the values into a new class.
     *
     * @template TMapIntoValue
     *
     * @param  class-string<TMapIntoValue>  $class
     *
     * @return static<TMapIntoValue>
     */
    public function mapInto(string $class): static
    {
        return $this->map(fn ($value) => new $class($value));
    }

    public function all(): array
    {
        return $this->values;
    }

    /**
     * @return array<string, TType>
     */
    public function toArray(): array
    {
        return array_map(
            fn($value) => $value instanceof Arrayable ? $value->toArray() : $value,
            $this->all()
        );
    }

    public function jsonSerialize(): ?array
    {
        return $this->isEmpty()
            ? null
            : array_map(fn($value) => match (true) {
                $value instanceof JsonSerializable => $value->jsonSerialize(),
                $value instanceof Jsonable         => json_decode($value->toJson(), true),
                $value instanceof Arrayable        => $value->toArray(),
                default                            => $value,
            }, $this->all());
    }

    /**
     * @param  string|BackedEnum  $offset
     *
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        $offset = $offset instanceof BackedEnum ? $offset->value : $offset;

        // Resolved through search(), so this agrees with offsetGet(): a raw
        // language tag stored as-is is still reported as present.
        return $this->search($this->values, $offset) !== null;
    }

    /**
     * @param  string|BackedEnum  $offset
     *
     * @return null|TType
     */
    public function offsetGet(mixed $offset): mixed
    {
        $offset = $offset instanceof BackedEnum ? $offset->value : $offset;
        $offset = locale_canonicalize($offset);

        return $this->search($this->values, $offset);
    }

    /**
     * @param  string|BackedEnum  $offset
     * @param  null|TType  $value
     *
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $offset = $offset instanceof BackedEnum ? $offset->value : $offset;
        $offset = locale_canonicalize($offset);

        $this->values[$offset] = $value;
    }

    /**
     * @param  string|BackedEnum  $offset
     *
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        $offset = $offset instanceof BackedEnum ? $offset->value : $offset;
        $offset = locale_canonicalize($offset);

        if (isset($this->values[$offset])) {
            unset($this->values[$offset]);
        }
    }
}
