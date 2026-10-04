<?php

namespace Codewiser\Tests;

use Codewiser\Multilingual\Casts\AsMultilingual;
use Codewiser\Multilingual\Casts\Multilingual;
use Codewiser\Multilingual\Traits\HasMultilingual;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @property string|null $string
 * @property bool|null $boolean
 * @property float|null $numeric
 * @property Username|null $name
 * @property array|null $strings
 * @property Collection<int, string|null> $collection
 * @property Collection<int, Username> $names
 */
class TestMultilingualModel extends Model
{
    use HasMultilingual;

    protected $attributes = [
        'string' => '[]',
        'boolean' => '[]',
        'numeric' => '[]',
        'name' => '[]',
        'strings' => '[]',
        'collection' => '[]',
        'names' => '[]',
    ];
    protected function casts(): array
    {
        return [
            'string' => AsMultilingual::class,
            'boolean' => AsMultilingual::strict(),
            'numeric' => AsMultilingual::strict(),
            // map
            'name' => AsMultilingual::using(Username::class),
            // map into collection
            'names' => AsMultilingual::collect(Username::class),
            // array
            'strings' => AsMultilingual::class,
            // collection
            'collection' => AsCollection::of(Multilingual::class),
        ];
    }
}
