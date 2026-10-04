<?php

namespace Codewiser\Multilingual\Casts;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;

class AsMultilingual implements Castable
{
    public static function strict(): string
    {
        return static::using('', true);
    }

    /**
     * Specify the type of object each item in the collection should be mapped to.
     *
     * @param  array{class-string, string}|class-string  $map
     * @param  bool  $strict
     *
     * @return string
     */
    public static function using(array|string $map, bool $strict = false): string
    {
        if (is_array($map) && is_callable($map)) {
            $map = $map[0].'@'.$map[1];
        }

        $strict = $strict ? 'strict' : '';

        return static::class.':'.implode(',', [$map, $strict]);
    }

    public static function collect(array|string $map = '', bool $strict = false): string
    {
        return AsCollection::using($map, $strict);
    }

    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class($arguments) extends CastsMultilingual {
            public function get(Model $model, string $key, mixed $value, array $attributes): mixed
            {
                if (! $value) {
                    return null;
                }

                $raw = $value;

                if (is_string($value)) {
                    $value = json_decode($value, true);
                }

                if (! is_array($value)) {
                    return null;
                }

                $instance = self::hydrate(
                    $model,
                    $key,
                    $raw,
                    fn () => $this->applyMapping(new Multilingual($value, $this->strict))
                );

                return Multilingual::hydrated()
                    ? $instance
                    : $instance->get();
            }

            public function set(Model $model, string $key, mixed $value, array $attributes): ?string
            {
                $value = parent::set($model, $key, $value, $attributes);

                if (is_array($value) && ! array_is_list($value)) {
                    // Full replace
                    $values = $value;
                } else {
                    // Replace current locale
                    $values = $attributes[$key] ?? null;
                    $values = $values ? json_decode($values, true) : [];
                    $values = new Multilingual($values, $this->strict);

                    $values[$values->getLocale()] = $value;
                    $values = $values->jsonSerialize();
                }

                return is_array($values) ? json_encode($values) : null;
            }
        };
    }
}