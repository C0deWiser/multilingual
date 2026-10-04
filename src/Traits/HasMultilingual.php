<?php

namespace Codewiser\Multilingual\Traits;

use Closure;
use Codewiser\Multilingual\Casts\AsCollection;
use Codewiser\Multilingual\Casts\AsMultilingual;
use Codewiser\Multilingual\Casts\CastsMultilingual;
use Codewiser\Multilingual\Casts\Multilingual;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Localizable;
use InvalidArgumentException;

/**
 * Model has multilingual attributes casted with Multilingual.
 *
 * A model defining its own getAttributes() overrides the one below, and then
 * the write back has to be called from there.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasMultilingual
{
    use Localizable;

    /**
     * Get all of the current attributes on the model.
     *
     * Hydrated objects are written back here, which is the one place every
     * reader, the dirty check and save() go through.
     *
     * @return array<string, mixed>
     */
    public function getAttributes()
    {
        $this->flushMultilingualAttributes();

        return parent::getAttributes();
    }

    /**
     * Discard attribute changes and reset the attributes to their original state.
     *
     * @return $this
     */
    public function discardChanges()
    {
        CastsMultilingual::drop($this);

        return parent::discardChanges();
    }

    /**
     * Write hydrated multilingual objects back into the raw attributes.
     *
     * An object whose raw attribute moved away is stale, and is dropped rather
     * than written back.
     */
    protected function flushMultilingualAttributes(): void
    {
        foreach (CastsMultilingual::entries($this) as $key => ['raw' => $raw, 'object' => $object]) {
            if (! array_key_exists($key, $this->attributes) || $this->attributes[$key] !== $raw) {
                CastsMultilingual::drop($this, $key);

                continue;
            }

            // An empty attribute is written back as null, so writing it would
            // dirty a column nobody touched.
            if ($object->isEmpty() && $this->isEmptyMultilingualValue($raw)) {
                continue;
            }

            // Goes through the cast, so the whole value is re-encoded.
            $this->setAttribute($key, $object);

            // The raw value moved, so the object is hydrated from the new one.
            CastsMultilingual::store($this, $key, $this->attributes[$key], $object);
        }
    }

    /**
     * Does the raw attribute hold no translations at all?
     */
    protected function isEmptyMultilingualValue(mixed $raw): bool
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return count(array_filter($raw ?: [])) === 0;
    }

    /**
     * Get a multilingual attribute as a Multilingual object.
     *
     *     $model->name;                        // 'Michael'
     *     $model->multilingual('name');         // Multilingual<string>
     *     $model->multilingual('name')->get(); // 'Michael'
     *     $model->multilingual('name')['es'];  // 'Miguel'
     *
     * Pass a callback to hydrate several attributes at once. Serialization is
     * never hydrated implicitly, wrap it when the whole map is wanted:
     *
     *     $model->multilingual(fn () => [$model->name, $model->description]);
     *     $model->multilingual(fn () => $model->toArray());
     *
     * @param  string|Closure  $key  Attribute name, or a callback reading attributes.
     *
     * @return Multilingual|Collection<array-key, Multilingual>
     *
     * @throws InvalidArgumentException When the attribute is not casted as Multilingual.
     */
    public function multilingual(string|Closure $key): mixed
    {
        if ($key instanceof Closure) {
            return Multilingual::of($key);
        }

        if (! $this->isMultilingual($key)) {
            throw new InvalidArgumentException(
                "Attribute [{$key}] is not casted as Multilingual."
            );
        }

        return Multilingual::of(fn () => $this->getAttribute($key));
    }

    /**
     * Is the attribute casted as Multilingual or its Collection?
     *
     * Matches a direct cast, and Multilingual nested in a collection cast.
     */
    protected function isMultilingual(string $key): bool
    {
        $cast = $this->getCasts()[$key] ?? null;

        if (! is_string($cast)) {
            return false;
        }

        $classes = array_map(
            fn(string $part) => strstr($part, ':', true) ?: $part,
            explode(',', $cast)
        );

        return
            in_array(AsMultilingual::class, $classes, true) ||
            in_array(AsCollection::class, $classes, true);
    }
}
