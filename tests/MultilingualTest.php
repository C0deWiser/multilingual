<?php

namespace Codewiser\Tests;

use Codewiser\Multilingual\Casts\AsMultilingual;
use Codewiser\Multilingual\Casts\Multilingual;
use Codewiser\Multilingual\Traits\HasMultilingual;
use Illuminate\Container\Container;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Localizable;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use RuntimeException;

class MultilingualTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->setLocale('en');
        $this->app->setFallbackLocale('en');
    }

    /**
     * Locales may be given as a language, a region or a language tag.
     */
    protected function fixture(bool $strict = false): Multilingual
    {
        return new Multilingual([
            'ru'      => 'one',
            'ru_RU'   => 'two',
            'en_GB'   => 'four',
            'en_US'   => 'six',
            'de_DEVA' => 'nine',
            'de_DE'   => 'ten',
        ], $strict);
    }

    // ---------------------------------------------------------------- locale

    public function testGetLocale(): void
    {
        $alt = $this->fixture();

        $this->app->setLocale('ru_RU');
        $this->app->setFallbackLocale('de_DE');

        $this->assertSame('ru_RU', $alt->getLocale());
        $this->assertSame('de_DE', $alt->getFallbackLocale());

        // Language tags are canonicalized
        $this->app->setLocale('en-GB');
        $this->assertSame('en_GB', $alt->getLocale());
    }

    public function testGetLocaleWithoutApplication(): void
    {
        $original = Container::getInstance();

        try {
            Container::setInstance(new Container);

            $alt = new Multilingual(['ru' => 'raz']);

            // A bare container carries no locale state, so it falls back to "en".
            $this->assertSame('en', $alt->getLocale());
            $this->assertSame('en', $alt->getFallbackLocale());
            $this->assertSame('raz', $alt->get());
        } finally {
            Container::setInstance($original);
        }
    }

    // ------------------------------------------------------------------- get

    public function testGet(): void
    {
        $alt = $this->fixture();

        $this->app->setLocale('ru');
        $this->app->setFallbackLocale('ru');
        $this->assertSame('one', $alt->get());

        $this->app->setLocale('ru_RU');
        $this->assertSame('two', $alt->get());

        // Language tag is canonicalized to en_GB
        $this->app->setLocale('en-GB');
        $this->assertSame('four', $alt->get());

        // No exact match, best matching language tag wins
        $this->app->setLocale('en');
        $this->assertSame('four', $alt->get());

        $this->app->setLocale('de');
        $this->assertSame('ten', $alt->get());
    }

    public function testGetFallsBackToFallbackLocale(): void
    {
        $alt = $this->fixture();

        $this->app->setLocale('it');
        $this->app->setFallbackLocale('en');

        $this->assertSame('four', $alt->get());
    }

    public function testGetFallsBackToFirstValue(): void
    {
        $alt = $this->fixture();

        $this->app->setLocale('it');
        $this->app->setFallbackLocale('it');

        $this->assertSame('one', $alt->get(), 'Nothing matched, so the first value is used');
    }

    public function testGetReturnsNullWhenEmpty(): void
    {
        $this->app->setLocale('it');
        $this->app->setFallbackLocale('it');

        $this->assertNull((new Multilingual([]))->get());
    }

    public function testGetKeepsWhitespace(): void
    {
        $alt = new Multilingual(['ru' => '  raz ']);

        $this->app->setLocale('ru');
        $this->assertSame('  raz ', $alt->get(), 'Values are stored verbatim');
    }

    public function testGetStrict(): void
    {
        $alt = $this->fixture(strict: true);

        $this->app->setLocale('ru');
        $this->assertSame('one', $alt->get());

        $this->app->setLocale('ru_RU');
        $this->assertSame('two', $alt->get());

        // Strict still resolves language tags, it only refuses to fall back
        $this->app->setLocale('en-GB');
        $this->assertSame('four', $alt->get());

        $this->app->setLocale('it');
        $this->app->setFallbackLocale('en');
        $this->assertNull($alt->get(), 'Strict mode does not use the fallback locale');

        $this->app->setFallbackLocale('it');
        $this->assertNull($alt->get(), 'Strict mode does not use the first value either');
    }

    public function testWithLocaleRestoresPreviousLocale(): void
    {
        $alt = $this->fixture();

        $this->app->setLocale('en');

        $this->assertSame('two', $alt->withLocale('ru_RU', fn() => $alt->get()));
        $this->assertSame('en', $alt->getLocale(), 'Locale is restored afterwards');

        $this->assertSame('two', $alt->withLocale('ru_RU', fn() => (string) $alt));
        $this->assertSame('en', $alt->getLocale());

        $this->assertSame('one', $alt->withLocale('ru', fn() => $alt->get()));
        $this->assertSame('en', $alt->getLocale());
    }

    public function testWithLocaleRestoresLocaleOnException(): void
    {
        $alt = $this->fixture();

        $this->app->setLocale('en');

        try {
            $alt->withLocale('ru', fn() => throw new \RuntimeException('boom'));
            $this->fail('Exception should propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame('en', $alt->getLocale(), 'Locale is restored even on failure');
    }

    public function testToStringDelegatesToGet(): void
    {
        $alt = $this->fixture();

        $this->app->setLocale('ru');
        $this->assertSame($alt->get(), $alt->toString());
    }

    // ----------------------------------------------------------- constructor

    public function testKeysAreStoredAsGiven(): void
    {
        $alt = new Multilingual(['en-GB' => 'four', 'ru-ru' => 'one']);

        $this->assertSame(['en-GB', 'ru-ru'], $alt->present());
        $this->assertSame(['en-GB' => 'four', 'ru-ru' => 'one'], $alt->toArray());
    }

    public function testKeysThatCanonicalizeAlikeAreBothKept(): void
    {
        $alt = new Multilingual(['en_GB' => 'four', 'en-GB' => 'five']);

        $this->assertSame(['en_GB' => 'four', 'en-GB' => 'five'], $alt->toArray());
    }

    public function testEmptyMultilingual(): void
    {
        $alt = new Multilingual([]);

        $this->assertTrue($alt->isEmpty());
        $this->assertSame([], $alt->toArray());
        $this->assertSame([], $alt->present());
        $this->assertNull($alt->get());
        $this->assertNull($alt->jsonSerialize());
        $this->assertSame('', (string) $alt);
        $this->assertSame(['ru', 'en'], $alt->missing(['ru', 'en']));
        $this->assertFalse(isset($alt['ru']));
        $this->assertNull($alt['ru']);
    }

    // ------------------------------------------------------ empty / presence

    public function testIsEmpty(): void
    {
        $this->assertTrue((new Multilingual([]))->isEmpty());
        $this->assertFalse((new Multilingual(['ru' => 'raz']))->isEmpty());
        $this->assertTrue(
            (new Multilingual(['ru' => '', 'en' => null]))->isEmpty(),
            'Falsy values do not count as translations'
        );
    }

    public function testJsonSerialize(): void
    {
        $this->assertNull((new Multilingual([]))->jsonSerialize());
        $this->assertSame(
            ['ru' => 'raz', 'en' => 'one'],
            (new Multilingual(['ru' => 'raz', 'en' => 'one']))->jsonSerialize()
        );
    }

    public function testMissing(): void
    {
        $alt = new Multilingual(['ru' => 'raz', 'en' => 'one']);

        $this->assertSame(['de'], $alt->missing('de'));
        $this->assertSame(['de'], $alt->missing(['ru', 'de']), 'Result is re-indexed');
        $this->assertSame([], $alt->missing('ru'));
        $this->assertSame([], $alt->missing([]));
        $this->assertSame(['de', 'fr'], $alt->missing([7 => 'de', 3 => 'ru', 'x' => 'fr']),
            'Every gap is closed'
        );
    }

    public function testMissingAcceptsBackedEnums(): void
    {
        $alt = new Multilingual(['ru' => 'raz', 'en' => 'one']);

        $this->assertSame([], $alt->missing(TestLocale::Russian));
        $this->assertSame([], $alt->missing([TestLocale::Russian, TestLocale::Russian]));
        $this->assertSame([TestLocale::Devanagari], $alt->missing(TestLocale::Devanagari), 'Input is echoed back');
        $this->assertSame(['de-DEVA'], $alt->missing('de-DEVA'), 'Input is echoed back, not canonicalized');
    }

    public function testMissingAgreesWithOffsetGetOnRawLanguageTagKeys(): void
    {
        $alt = new Multilingual(['en-GB' => 'four']);

        $this->assertSame('four', $alt['en_GB']);
        $this->assertSame([], $alt->missing(['en_GB']), 'A locale that is found is not missing');
    }

    public function testPresent(): void
    {
        $alt = new Multilingual(['ru' => 'raz', 'en' => 'one']);

        $this->assertSame(['ru', 'en'], $alt->present());
        $this->assertSame([], (new Multilingual([]))->present());
    }

    // ---------------------------------------------------------- array access

    public function testOffsetExists(): void
    {
        $alt = new Multilingual(['ru' => 'raz', 'ru_RU' => 'two', 'en_GB' => 'four']);

        $this->assertTrue(isset($alt['ru']));
        $this->assertTrue(isset($alt['ru_RU']));
        $this->assertTrue(isset($alt['en-GB']), 'Offsets are canonicalized');
        $this->assertFalse(isset($alt['it']));

        $this->assertTrue(isset($alt[TestLocale::Russian]));
        $this->assertTrue(isset($alt[TestLocale::British]));
        $this->assertFalse(isset($alt[TestLocale::Devanagari]));
    }

    public function testOffsetGet(): void
    {
        $alt = new Multilingual(['ru' => 'raz', 'en_GB' => 'four']);

        $this->assertSame('raz', $alt['ru']);
        $this->assertSame('four', $alt['en_GB']);
        $this->assertSame('four', $alt['en'], 'Best matching language tag');
        $this->assertNull($alt['it'], 'No fallback for array access');

        $this->assertSame('raz', $alt[TestLocale::Russian]);
    }

    public function testOffsetGetResolvesRawLanguageTagKey(): void
    {
        $alt = new Multilingual(['en-GB' => 'four']);

        $this->assertSame('four', $alt['en-GB'], 'Raw key is found via language matching');
        $this->assertSame('four', $alt['en_GB'], 'And via the canonicalized offset');
    }

    public function testOffsetExistsAgreesWithOffsetGet(): void
    {
        $alt = new Multilingual(['en-GB' => 'four']);

        $this->assertTrue(isset($alt['en_GB']), 'Found via offsetGet, so offsetExists must agree');
        $this->assertTrue(isset($alt['en-GB']), 'Raw key is found too');
        $this->assertFalse(isset($alt['it']));
    }

    public function testOffsetSet(): void
    {
        $alt = new Multilingual(['ru' => 'raz']);

        $alt['de-DE'] = 'drei';
        $this->assertSame(['ru' => 'raz', 'de_DE' => 'drei'], $alt->toArray(), 'Offset is canonicalized');

        $alt[TestLocale::Russian] = 'razved';
        $this->assertSame('razved', $alt['ru']);
    }

    public function testOffsetUnset(): void
    {
        $alt = new Multilingual(['ru' => 'raz', 'en' => 'one']);

        unset($alt['ru-RU']);
        $this->assertSame(['ru' => 'raz', 'en' => 'one'], $alt->toArray(), 'Unknown offset is a no-op');

        unset($alt['ru']);
        $this->assertSame(['en' => 'one'], $alt->toArray());

        unset($alt[TestLocale::Russian]);
        $this->assertSame(['en' => 'one'], $alt->toArray(), 'Already removed, still a no-op');
    }

    public function testOffsetUnsetRemovesEnumKey(): void
    {
        $alt = new Multilingual(['en_GB' => 'four']);

        unset($alt[TestLocale::British]);
        $this->assertSame([], $alt->toArray());
    }

    // ------------------------------------------------------------ __toString

    public function testToString(): void
    {
        $this->app->setLocale('ru');
        $this->app->setFallbackLocale('ru');

        $this->assertSame('one', (string) $this->fixture());

        // Arrays are collapsed to JSON
        $arrays = new Multilingual(['ru' => ['one', 'two']]);
        $this->assertSame('["one","two"]', (string) $arrays);

        // Non-string scalars are JSON encoded
        $this->assertSame('true', (string) new Multilingual(['ru' => true]));
        $this->assertSame('1', (string) new Multilingual(['ru' => 1.0]));
        $this->assertSame('0', (string) new Multilingual(['ru' => 0]));
    }

    // --------------------------------------------------------------- casting

    protected function cast(array $arguments = []): CastsAttributes
    {
        return AsMultilingual::castUsing($arguments);
    }

    protected function model(): TestMultilingualModel
    {
        return new TestMultilingualModel;
    }

    public function testCastGet(): void
    {
        $value = ['ru' => 'raz', 'en' => 'one'];

        $casted = $this->cast()->get($this->model(), 'string', json_encode($value), []);

        dump($casted);

        $this->assertSame('one', $casted, 'Returns a plain value in the current locale');
    }

    public function testCastGetAcceptsArray(): void
    {
        $casted = $this->cast()->get($this->model(), 'string', ['ru' => 'raz'], []);

        $this->assertSame('raz', $casted);
    }

    public function testCastGetOnNull(): void
    {
        $casted = $this->cast()->get($this->model(), 'string', null, []);

        $this->assertNull($casted, 'A missing attribute must blow up');
    }

    public function testCastGetEmptyJson(): void
    {
        $casted = $this->cast()->get($this->model(), 'string', '[]', []);

        $this->assertNull($casted, 'An empty map has no value to return');
    }

    public function testCastGetHydratesObject(): void
    {
        $value = ['ru' => 'raz', 'en' => 'one'];

        $casted = Multilingual::of(
            fn() => $this->cast()->get($this->model(), 'string', json_encode($value), [])
        );

        $this->assertEquals(new Multilingual($value), $casted);
        $this->assertSame($value, $casted->toArray());
    }

    public function testCastGetPropagatesStrict(): void
    {
        $json = '{"en":"one","ru":"raz"}';

        // The locale is read while casting, not on a later ->get().
        $this->app->setLocale('it');
        $this->app->setFallbackLocale('en');

        $loose = $this->cast()->get($this->model(), 'string', $json, []);
        $strict = $this->cast(['', 'strict'])->get($this->model(), 'boolean', $json, []);

        $this->assertSame('one', $loose, 'Loose mode uses the fallback locale');
        $this->assertNull($strict, 'Strict mode does not');
    }

    public function testCastSetReplacesWholeMap(): void
    {
        $casted = $this->cast()->set($this->model(), 'string', ['en' => 'e', 'ru' => 'r'], [
            'string' => '{"de":"d"}',
        ]);

        $this->assertSame('{"en":"e","ru":"r"}', $casted, 'Associative array fully replaces');
    }

    public function testCastSetReplacesCurrentLocale(): void
    {
        $this->app->setLocale('en');

        $casted = $this->cast()->set($this->model(), 'string', 'e', ['string' => '{"en":"old","ru":"r"}']);
        $this->assertSame('{"en":"e","ru":"r"}', $casted);

        $this->app->setLocale('ru');
        $casted = $this->cast()->set($this->model(), 'string', 'new', ['string' => '{"en":"e","ru":"old"}']);
        $this->assertSame('{"en":"e","ru":"new"}', $casted);
    }

    public function testCastSetListReplacesCurrentLocale(): void
    {
        $this->app->setLocale('en');

        $casted = $this->cast()->set($this->model(), 'strings', ['one', 'two'], [
            'strings' => '{"en":["a"],"ru":["b"]}',
        ]);

        $this->assertSame('{"en":["one","two"],"ru":["b"]}', $casted);
    }

    public function testCastSetWithoutExistingAttribute(): void
    {
        $this->app->setLocale('en');

        $this->assertSame('{"en":"e"}', $this->cast()->set($this->model(), 'string', 'e', []));
    }

    public function testCastSetAcceptsMultilingual(): void
    {
        $casted = $this->cast()->set($this->model(), 'string', new Multilingual(['en' => 'e']), []);

        $this->assertSame('{"en":"e"}', $casted);
    }

    public function testCastSetAcceptsArrayable(): void
    {
        $casted = $this->cast()->set($this->model(), 'string', collect(['en' => 'e']), []);

        $this->assertSame('{"en":"e"}', $casted);
    }

    public function testCastSetReturnsNullOnNull(): void
    {
        $this->assertNull($this->cast()->set($this->model(), 'string', null, []));
    }

    public function testCastSetDropsAttributeWhenOnlyFalsyValuesRemain(): void
    {
        $this->app->setLocale('en');

        // Overwriting the only translation with a falsy value empties the attribute
        $casted = $this->cast()->set($this->model(), 'boolean', false, ['boolean' => '{"en":true}']);

        $this->assertNull($casted);
    }

    // --------------------------------------------------------------- statics

    public function testStatics(): void
    {
        $this->assertSame(AsMultilingual::class.':,strict', AsMultilingual::strict());
    }

    // ----------------------------------------------------------------- model

    public function testModel(): void
    {
        $model = new TestMultilingualModel();

        // String

        $model->string = 'en-test';
        $model->withLocale('ru', fn() => $model->string = 'ru-test');

        $this->assertSame('en-test', $model->string,
            'Should return plain value in current locale'
        );
        $this->assertSame('ru-test', $model->withLocale('ru', fn() => $model->string),
            'Should return plain value in given locale'
        );

        // Boolean (strict)

        $model->boolean = true;
        $model->withLocale('de', fn() => $model->boolean = true);
        $model->withLocale('ru', fn() => $model->boolean = false);

        $this->assertTrue($model->boolean,
            'Should return plain value in current locale'
        );
        $this->assertFalse($model->withLocale('ru', fn() => $model->boolean));
        $this->assertNull($model->withLocale('it', fn() => $model->boolean),
            'Should return exact value, strict mode don\'t use fallback'
        );

        // Numeric (strict)

        $model->numeric = 1.0;
        $model->withLocale('ru', fn() => $model->numeric = 1.5);

        // A JSON column has no float type, so 1.0 comes back as 1.
        $this->assertEquals(1.0, $model->numeric,
            'Should return plain value in current locale'
        );
        $this->assertEquals(1.5, $model->withLocale('ru', fn() => $model->numeric));
        $this->assertNull($model->withLocale('it', fn() => $model->numeric),
            'Should return exact value, strict mode don\'t use fallback'
        );

        // Array

        $model->strings = ['en-1', 'en-2'];
        $model->withLocale('ru', fn() => $model->strings = ['ru-1', 'ru-2']);

        $this->assertSame(['en-1', 'en-2'], $model->strings,
            'Should return plain value in current locale'
        );
        $this->assertSame(['ru-1', 'ru-2'], $model->withLocale('ru', fn() => $model->strings));

        // Collection of strings. AsCollection::of() maps into the class directly,
        // so it never routes through the cast and always holds objects.
        $model->collection = [
            ['en' => 'en-1', 'ru' => 'ru-1'],
            ['en' => 'en-2', 'ru' => 'ru-2'],
        ];

        $this->assertEquals('en-1', $model->collection->first()->get(),
            'Should return value in current locale'
        );
        $this->assertSame('ru-1', $model->withLocale('ru', fn() => $model->collection->first()->get()),
            'Should return value in given locale'
        );
        $this->assertSame([['en' => 'en-1', 'ru' => 'ru-1'], ['en' => 'en-2', 'ru' => 'ru-2']],
            $model->collection->toArray(),
            'Should return all values as array'
        );
    }

    public function testModelHydrated(): void
    {
        $model = new TestMultilingualModel();

        $model->string = 'en-test';
        $model->withLocale('ru', fn() => $model->string = 'ru-test');

        $this->assertEquals('en-test', $model->multilingual('string')->get(),
            'Should return value in current locale'
        );
        $this->assertEquals('en-test', $model->multilingual('string')['en'],
            'Should return value in given locale'
        );
        $this->assertEquals('en-test', $model->multilingual('string')['en-GB'],
            'Should return value in locale, best matched to a given language tag'
        );
        $this->assertNull($model->multilingual('string')['it'],
            'Shouldn\'t return any fallback locale except given'
        );
        $this->assertEquals('en-test', $model->withLocale('it', fn() => $model->multilingual('string')->get()),
            'Should return any possible value using fallback locale'
        );
        $this->assertEquals(['en' => 'en-test', 'ru' => 'ru-test'], $model->multilingual('string')->toArray(),
            'Should return all values as array'
        );

        // Boolean (strict)

        $model->boolean = true;
        $model->withLocale('de', fn() => $model->boolean = true);
        $model->withLocale('ru', fn() => $model->boolean = false);

        $this->assertTrue($model->multilingual('boolean')->get(),
            'Should return value in current locale'
        );
        $this->assertTrue($model->multilingual('boolean')['en'],
            'Should return value in given locale'
        );
        $this->assertEquals('true', (string) $model->multilingual('boolean'),
            'Should return json value'
        );
        $this->assertEquals(['en' => true, 'de' => true, 'ru' => false], $model->multilingual('boolean')->toArray(),
            'Should return all values as array'
        );
        $this->assertNull($model->withLocale('it', fn() => $model->multilingual('boolean')->get()),
            'Should return exact value, strict mode don\'t use fallback'
        );

        // Numeric (strict)

        $model->numeric = 1.0;
        $model->withLocale('ru', fn() => $model->numeric = 1.5);

        $this->assertEquals(1.0, $model->multilingual('numeric')->get(),
            'Should return value in current locale'
        );
        $this->assertEquals('1', (string) $model->multilingual('numeric'),
            'Should return json value'
        );
        $this->assertEquals(['en' => 1.0, 'ru' => 1.5], $model->multilingual('numeric')->toArray(),
            'Should return all values as array'
        );

        // Array

        $model->strings = ['en-1', 'en-2'];
        $model->withLocale('ru', fn() => $model->strings = ['ru-1', 'ru-2']);

        $this->assertEquals(['en-1', 'en-2'], $model->multilingual('strings')->get(),
            'Should return value in current locale'
        );
        $this->assertEquals(['en-1', 'en-2'], $model->multilingual('strings')['en_US'],
            'Should return value in locale, best matched to a given language tag'
        );
        $this->assertEquals('["en-1","en-2"]', (string) $model->multilingual('strings'),
            'Should return json value'
        );
    }

    public function testModelEmptyAttribute(): void
    {
        // A fresh model carries "[]" for every translated column
        $model = new TestMultilingualModel;

        $this->assertNull($model->string);
        $this->assertTrue($model->multilingual('string')->isEmpty());
        $this->assertSame([], $model->multilingual('string')->toArray());
        $this->assertNull($model->multilingual('string')->get());
        $this->assertEquals([], $model->multilingual('boolean')->toArray());
    }

    public function testModelNullAttribute(): void
    {
        $model = new TestMultilingualModel;

        $model->setRawAttributes(['string' => null], sync: true);

        $this->assertNull($model->string);
    }

    // -+ of

    public function testOfReturnsObjectAndPlainReadReturnsValue(): void
    {
        $model = $this->filled();

        $this->assertSame('en-test', $model->string);
        $this->assertEquals(new Multilingual(['en' => 'en-test']), $model->multilingual('string'));
        $this->assertEquals(new Multilingual(['en' => 'en-test']), Multilingual::of(fn() => $model->string));
    }

    public function testOfDoesNotLeakIntoLaterReads(): void
    {
        // Eloquent memoizes casted objects in $model->classCastCache, which would
        // hand the object back after the callback returned.
        $model = $this->filled();

        $this->assertEquals(new Multilingual(['en' => 'en-test']), $model->multilingual('string'));
        $this->assertSame('en-test', $model->string, 'Must not return the hydrated object');
        $this->assertEquals(new Multilingual(['en' => 'en-test']), $model->multilingual('string'));
    }

    public function testOfRestoresFlagOnException(): void
    {
        $model = $this->filled();

        try {
            $model->multilingual(fn() => throw new RuntimeException('boom'));
            $this->fail('Exception should propagate');
        } catch (RuntimeException) {
            //
        }

        $this->assertFalse(Multilingual::hydrated());
        $this->assertSame('en-test', $model->string);
    }

    public function testOfNests(): void
    {
        $model = $this->filled();

        $type = $model->multilingual(
            fn() => $model->multilingual(fn() => get_debug_type($model->string))
        );

        $this->assertSame(Multilingual::class, $type);
        $this->assertFalse(Multilingual::hydrated());
    }

    public function testPlainReadsFollowTheLocale(): void
    {
        $model = $this->filled();

        $this->assertSame('en-test', $model->string);

        $this->app->setLocale('ru');
        $this->assertSame('en-test', $model->string, 'Loose mode falls back');
        $this->assertNull($model->numeric, 'Strict mode does not');
    }

    public function testHydratedReadsFollowTheLocale(): void
    {
        $model = $this->filled();

        $this->assertSame('en-test', $model->multilingual('string')->get());

        $this->app->setLocale('ru');
        $this->assertSame('en-test', $model->multilingual('string')->get(), 'Loose mode falls back');
        $this->assertNull($model->multilingual('numeric')->get(), 'Strict mode does not');
        $this->assertSame('en-test', $model->multilingual('string')['en']);
    }

    public function testOfHydratesSeveralAttributes(): void
    {
        $model = $this->filled();

        $model->withLocale('ru', fn() => $model->numeric = 1.5);

        $this->assertEquals(
            [new Multilingual(['en' => 'en-test']), new Multilingual(['ru' => 1.5], true)],
            $model->multilingual(fn() => [$model->string, $model->numeric])
        );
    }

    public function testMultilingualRejectsForeignAttributes(): void
    {
        $model = $this->filled();

        $this->expectException(InvalidArgumentException::class);

        $model->multilingual('id');
    }

    public function testMultilingualAcceptsCollectionAttributes(): void
    {
        $model = $this->filled();

        $this->expectException(InvalidArgumentException::class);
        $model->multilingual('collection');
    }

    public function testTraitComposesWithLocalizable(): void
    {
        // Models migrating from `use Localizable` keep both traits.
        $model = new class extends Model {
            use HasMultilingual;
            use Localizable;

            protected $table = 'users';

            protected function casts(): array
            {
                return ['string' => AsMultilingual::class];
            }
        };

        $model->string = 'en-test';
        $model->withLocale('ru', fn() => $model->string = 'ru-test');

        $this->assertSame('ru-test', $model->withLocale('ru', fn() => $model->string));
        $this->assertSame('en-test', $model->multilingual('string')['en']);
    }

    public function testSerializationIsNotHydratedImplicitly(): void
    {
        $model = $this->filled();

        $this->assertSame('en-test', $model->toArray()['string'],
            'toArray() holds plain values by default'
        );
        $this->assertSame(['en' => 'en-test'], $model->multilingual(fn() => $model->toArray())['string'],
            'Hydrate to serialize every locale'
        );
    }

    /**
     * A model carrying one translation per attribute.
     */
    protected function filled(): TestMultilingualModel
    {
        $model = new TestMultilingualModel;

        $model->string = 'en-test';
        $model->collection = [['en' => 'en-1', 'ru' => 'ru-1']];

        return $model;
    }

    // --------------------------------------------------------------- helpers

    public function testLocaleFilterMatches(): void
    {
        $this->assertEquals('ru', locale_canonicalize('ru'));
        $this->assertEquals('ru_RU', locale_canonicalize('ru_RU'));
        $this->assertEquals('ru_RU', locale_canonicalize('ru-RU'));

        $this->assertTrue(locale_filter_matches('ru', 'ru'));
        $this->assertTrue(locale_filter_matches('ru-RU', 'ru'));
        $this->assertTrue(locale_filter_matches('ru_RU', 'ru'));

        $this->assertFalse(locale_filter_matches('ru', 'ru_RU'));
        $this->assertFalse(locale_filter_matches('ru', 'ru-RU'));
    }

    public function testModelMapInto()
    {
        $this->assertEquals(
            AsMultilingual::class.':'.Username::class.',',
            AsMultilingual::using(Username::class)
        );

        $model = new TestMultilingualModel();

        $model->name = [
            'en' => ['first_name' => 'John', 'last_name' => 'Smith'],
            'ru' => ['first_name' => 'Иван', 'last_name' => 'Кузнецов'],
        ];

        $this->assertInstanceOf(Username::class, $model->name);

        $this->assertEquals('John', $model->name->first_name);
        $this->assertEquals('Smith', $model->name->last_name);

        $this->assertEquals([
            'first_name' => 'John', 'last_name' => 'Smith'
        ], $model->name->toArray());

        $this->assertEquals([
            'first_name' => 'Иван', 'last_name' => 'Кузнецов'
        ], $model->withLocale('ru', fn() => $model->name->toArray()));

        $this->assertEquals([
            'en' => ['first_name' => 'John', 'last_name' => 'Smith'],
            'ru' => ['first_name' => 'Иван', 'last_name' => 'Кузнецов'],
        ], $model->multilingual('name')->toArray());

        // A mapped object is mutable, and the same one is handed out on every
        // read, so a change made to it is seen by the attribute afterwards and
        // is written back into the raw value.
        $model->name->first_name = 'Test';

        $this->assertEquals([
            'first_name' => 'Test', 'last_name' => 'Smith'
        ], $model->name->toArray());

        $this->assertEquals([
            'en' => ['first_name' => 'Test', 'last_name' => 'Smith'],
            'ru' => ['first_name' => 'Иван', 'last_name' => 'Кузнецов'],
        ], $model->multilingual('name')->toArray());

    }

    public function testModelCollectionMapInto()
    {
        $model = new TestMultilingualModel();

        $original = [
            [
                'en' => ['first_name' => 'John', 'last_name' => 'Smith'],
                'ru' => ['first_name' => 'Иван', 'last_name' => 'Кузнецов']
            ],
            [
                'en' => ['first_name' => 'Gregory', 'last_name' => 'Johnson'],
                'ru' => ['first_name' => 'Григорий', 'last_name' => 'Иванов'],
                'es' => ['first_name' => 'Gregorio', 'last_name' => 'Ibáñez']
            ],
        ];

        $model->names = $original;

        $this->assertInstanceOf(Username::class, $model->names->first());
        $this->assertInstanceOf(Multilingual::class, $model->multilingual('names')->first());
        $this->assertEquals(['first_name' => 'John', 'last_name' => 'Smith'], $model->names->first()->toArray());
        $this->assertEquals(['first_name' => 'Иван', 'last_name' => 'Кузнецов'],
            $model->withLocale('ru', fn() => $model->names->first()->toArray())
        );
        $this->assertEquals([
            $original[0]['en'],
            $original[1]['en']
        ], $model->names->toArray());

        $this->assertEquals($original, $model->multilingual('names')->toArray());

        // The same mapped objects are handed out on every read, so a change
        // made to one of them is written back into the raw value.
        $model->names->first()->first_name = 'Test';

        $this->assertEquals(
            ['first_name' => 'Test', 'last_name' => 'Smith'],
            $model->names->first()->toArray()
        );

        $this->assertEquals(
            [
                'en' => ['first_name' => 'Test', 'last_name' => 'Smith'],
                'ru' => ['first_name' => 'Иван', 'last_name' => 'Кузнецов']
            ],
            $model->multilingual('names')->first()->toArray()
        );
    }

    // ---------------------------------------------------------- hydrated live

    /**
     * A model carrying a name mapped into an object.
     */
    protected function mapped(): TestMultilingualModel
    {
        $model = new TestMultilingualModel;

        $model->name = [
            'en' => ['first_name' => 'John', 'last_name' => 'Smith'],
            'ru' => ['first_name' => 'Иван', 'last_name' => 'Кузнецов'],
        ];

        return $model;
    }

    public function testHydratedObjectIsTheSameOnEveryRead(): void
    {
        $model = $this->mapped();

        $this->assertSame($model->name, $model->name,
            'A mapped object is built once, not on every read'
        );

        $this->assertSame($model->multilingual('name'), $model->multilingual('name'),
            'The plain read gives back the very object held by the Multilingual'
        );
    }

    public function testMappedMutationReachesRawAttribute(): void
    {
        $model = $this->mapped();

        $this->assertSame('John', $model->name->first_name);

        $model->syncOriginal();

        $this->assertSame([], $model->getDirty(), 'Reading alone dirties nothing');

        $model->name->first_name = 'Test';

        $this->assertTrue($model->isDirty('name'), 'A change made to a mapped object dirties the attribute');

        $this->assertSame(
            '{"en":{"first_name":"Test","last_name":"Smith"},"ru":{"first_name":"\u0418\u0432\u0430\u043d",'
            .'"last_name":"\u041a\u0443\u0437\u043d\u0435\u0446\u043e\u0432"}}',
            $model->getDirty()['name'],
            'Every locale is written back, not only the current one'
        );

        $this->assertSame('Test', $model->toArray()['name']['first_name'],
            'Serialization reaches the change too'
        );
    }

    public function testHydratedMultilingualOffsetSetIsWrittenBack(): void
    {
        $model = $this->mapped();

        $model->multilingual('name')['en'] = new Username(['first_name' => 'Jane', 'last_name' => 'Doe']);

        $this->assertSame('Jane', $model->name->first_name);

        $this->assertSame(
            '{"en":{"first_name":"Jane","last_name":"Doe"},"ru":{"first_name":"\u0418\u0432\u0430\u043d",'
            .'"last_name":"\u041a\u0443\u0437\u043d\u0435\u0446\u043e\u0432"}}',
            $model->getAttributes()['name']
        );
    }

    public function testAssigningTheAttributeDropsTheHydratedObject(): void
    {
        $model = $this->mapped();

        $hydrated = $model->name;

        $model->name = ['en' => ['first_name' => 'Jane', 'last_name' => 'Doe']];

        $this->assertNotSame($hydrated, $model->name, 'The object is built from the new value');

        $this->assertSame('Jane', $model->name->first_name);
    }

    public function testDiscardingChangesDropsTheHydratedObject(): void
    {
        $model = $this->mapped();

        $model->syncOriginal();

        $model->name->first_name = 'Test';

        $model->discardChanges();

        $this->assertSame('John', $model->name->first_name, 'The change is gone');

        $this->assertSame([], $model->getDirty());
    }

    public function testUnsettingTheAttributeIsNotUndoneByTheWriteBack(): void
    {
        $model = $this->mapped();

        $model->name->first_name = 'Test';

        unset($model->name);

        $this->assertArrayNotHasKey('name', $model->getAttributes(),
            'A stale object must not bring the attribute back'
        );
    }

    public function testReadingAnEmptyAttributeDoesNotDirtyTheModel(): void
    {
        $model = new TestMultilingualModel;

        $model->syncOriginal();

        $this->assertNull($model->string);

        $this->assertSame([], $model->getDirty(), 'An empty attribute is written back as is');

        $this->assertSame('[]', $model->getAttributes()['string']);
    }

    public function testEmptyingAHydratedAttributeIsWrittenBack(): void
    {
        $model = new TestMultilingualModel;

        $model->string = 'en-test';
        $model->syncOriginal();

        $model->multilingual('string')->offsetUnset('en');

        $this->assertTrue($model->isDirty('string'));

        $this->assertNull($model->getAttributes()['string'], 'An emptied attribute is dropped');
    }

    // ---------------------------------------------------- hydrated collection

    public function testCollectionGivesAMultilingualCollectionWhenHydrated(): void
    {
        $model = new TestMultilingualModel;

        $model->names = [['en' => ['first_name' => 'John', 'last_name' => 'Smith']]];

        $this->assertInstanceOf(Collection::class, $model->multilingual('names'),
            'Hydrated items live in a MultilingualCollection'
        );

        $this->assertInstanceOf(Collection::class, $model->names);

        $this->assertSame('John', $model->names->first()->first_name,
            'A mapped item is the very object held by the Multilingual'
        );

        $this->assertSame($model->names->first(), $model->names->first());
    }

    public function testMappedCollectionMutationReachesRawAttribute(): void
    {
        $model = new TestMultilingualModel;

        $model->names = [
            ['en' => ['first_name' => 'John', 'last_name' => 'Smith']],
            ['en' => ['first_name' => 'Gregory', 'last_name' => 'Johnson']],
        ];

        $this->assertSame('John', $model->names->first()->first_name);

        $model->syncOriginal();

        $this->assertSame([], $model->getDirty(), 'Reading alone dirties nothing');

        $model->names->first()->first_name = 'Test';

        $this->assertTrue($model->isDirty('names'));

        $this->assertSame(
            '[{"en":{"first_name":"Test","last_name":"Smith"}},'
            .'{"en":{"first_name":"Gregory","last_name":"Johnson"}}]',
            $model->getDirty()['names']
        );
    }
}
