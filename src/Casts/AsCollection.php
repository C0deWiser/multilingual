<?php

namespace Codewiser\Multilingual\Casts;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AsCollection implements Castable
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

    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class ($arguments) extends CastsMultilingual {

            public function get(Model $model, string $key, mixed $value, array $attributes)
            {
                if (! isset($attributes[$key])) {
                    return null;
                }

                if (is_string($attributes[$key])) {
                    $value = json_decode($attributes[$key], true);
                }

                if (! is_array($value)) {
                    return null;
                }

                $instance = self::hydrate(
                    $model,
                    $key,
                    $attributes[$key],
                    fn () => (new Collection($value))
                        ->map(fn ($item) => $this->applyMapping(new Multilingual($item)))
                );

                // A plain value per item in the current locale, so a plain
                // collection, the way AsMultilingual hands out a plain value.
                return Multilingual::hydrated()
                    ? $instance
                    : new Collection($instance->map(fn (Multilingual $item) => $item->get())->all());
            }

            public function set(Model $model, string $key, mixed $value, array $attributes)
            {
                $value = parent::set($model, $key, $value, $attributes);

                return is_array($value) ? json_encode($value) : null;
            }
        };
    }
}