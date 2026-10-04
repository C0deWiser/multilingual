<?php

namespace Codewiser\Multilingual\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

abstract class CastsMultilingual implements CastsAttributes
{
    protected bool $strict;

    public bool $withoutObjectCaching = true;

    public function __construct(protected array $arguments)
    {
        $this->arguments = array_pad(array_values($this->arguments), 2, '');
        $this->strict = in_array('strict', $this->arguments);
    }

    /**
     * @template TObject
     *
     * @param TObject $object
     *
     * @return TObject
     */
    protected function applyMapping($object)
    {
        if (isset($this->arguments[0]) && $this->arguments[0]) {

            if (is_string($this->arguments[0])) {
                $this->arguments[0] = Str::parseCallback($this->arguments[0]);
            }

            $object = is_callable($this->arguments[0])
                ? $object->map($this->arguments[0])
                : $object->mapInto($this->arguments[0][0]);
        }

        return $object;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes)
    {
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        return $value;
    }

    # Hydration repository

    /**
     * Hydrated objects, keyed by model instance, then by attribute.
     *
     * @var null|\WeakMap<Model, array<string, array{raw: mixed, object: Multilingual|Collection}>>
     */
    protected static ?\WeakMap $hydrated = null;

    /**
     * Get the object hydrated from an attribute, building it when the raw value
     * moved away from the one it was built from.
     *
     * @param  Model  $model
     * @param  string  $key
     * @param  mixed  $raw  Raw value the object is built from.
     * @param  callable():(Multilingual|Collection)  $build  Builds the object on a miss.
     */
    public static function hydrate(
        Model $model,
        string $key,
        mixed $raw,
        callable $build
    ): Multilingual|Collection {
        $entry = static::entries($model)[$key] ?? null;

        if ($entry !== null && $entry['raw'] === $raw) {
            return $entry['object'];
        }

        return static::store($model, $key, $raw, $build());
    }

    /**
     * Keep the object as the one hydrated from the attribute.
     *
     * @param  Model  $model
     * @param  string  $key
     * @param  mixed  $raw  Raw value the object was built from.
     * @param  Multilingual|Collection  $object
     *
     * @return Multilingual|Collection The very same object.
     */
    public static function store(
        Model $model,
        string $key,
        mixed $raw,
        Multilingual|Collection $object
    ): Multilingual|Collection {
        static::$hydrated ??= new \WeakMap();

        static::$hydrated[$model] = [
            ...static::entries($model),
            $key => ['raw' => $raw, 'object' => $object],
        ];

        return $object;
    }

    /**
     * Entries hydrated from the model, keyed by attribute name.
     *
     * @return array<string, array{raw: mixed, object: Multilingual|Collection}>
     */
    public static function entries(Model $model): array
    {
        return isset(static::$hydrated[$model]) ? static::$hydrated[$model] : [];
    }

    /**
     * Stop hydrating objects from the model, or from one of its attributes.
     *
     * @param  null|string  $key  Attribute name, or null for every attribute.
     */
    public static function drop(Model $model, ?string $key = null): void
    {
        if (! isset(static::$hydrated[$model])) {
            return;
        }

        if ($key === null) {
            unset(static::$hydrated[$model]);

            return;
        }

        $hydrated = static::$hydrated[$model];

        unset($hydrated[$key]);

        static::$hydrated[$model] = $hydrated;
    }
}