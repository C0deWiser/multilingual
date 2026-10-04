<?php

namespace Codewiser\Multilingual\Casts;

/**
 * Hydration hands out the whole multilingual attribute instead of a plain value
 * in the current locale.
 */
trait Hydrate
{
    /**
     * Are multilingual attributes hydrated right now?
     *
     * @see static::of()
     */
    protected static bool $hydrate = false;

    /**
     * Run the callback with multilingual attributes casted to objects.
     *
     *     $model->name;                           // 'Michael'
     *     Multilingual::of(fn () => $model->name); // Multilingual<string>
     *
     * @see static::hydrated()
     */
    public static function of(callable $callback): mixed
    {
        $hydrated = self::$hydrate;

        self::$hydrate = true;

        try {
            return $callback();
        } finally {
            self::$hydrate = $hydrated;
        }
    }

    /**
     * Are multilingual attributes casted to objects right now?
     */
    public static function hydrated(): bool
    {
        return self::$hydrate;
    }
}