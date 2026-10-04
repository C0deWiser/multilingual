<?php

namespace Codewiser\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

/**
 * Round trip through the database.
 *
 * Changes made to a mapped object are written back into the raw attributes
 * rather than into the model, so only a real save() proves they are stored.
 */
class MultilingualPersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->setLocale('en');
        $this->app->setFallbackLocale('en');

        Schema::create('test_multilingual_models', function (Blueprint $table) {
            $table->increments('id');

            // Text, not json: sqlite has no json type, and any other declared
            // type would coerce the raw JSON on the way in and out.
            $table->text('string')->nullable();
            $table->text('boolean')->nullable();
            $table->text('numeric')->nullable();
            $table->text('name')->nullable();
            $table->text('strings')->nullable();
            $table->text('collection')->nullable();
            $table->text('names')->nullable();

            $table->timestamps();
        });
    }

    public function testMappedMutationIsPersisted(): void
    {
        $model = new TestMultilingualModel;

        $model->name = [
            'en' => ['first_name' => 'John', 'last_name' => 'Smith'],
            'ru' => ['first_name' => 'Иван', 'last_name' => 'Кузнецов'],
        ];

        $model->save();

        $this->assertSame(
            '{"en":{"first_name":"John","last_name":"Smith"},"ru":{"first_name":"\u0418\u0432\u0430\u043d",'
            .'"last_name":"\u041a\u0443\u0437\u043d\u0435\u0446\u043e\u0432"}}',
            $model->getRawOriginal('name'),
            'Sanity check: what was assigned is what was stored'
        );

        $model->name->first_name = 'Test';

        $this->assertTrue($model->isDirty('name'), 'A change made to a mapped object dirties the attribute');

        $model->save();

        $this->assertFalse($model->isDirty('name'));

        $model->refresh();

        $this->assertSame('Test', $model->name->first_name, 'The change reached the database');

        $this->assertSame('Smith', $model->name->last_name);

        $this->assertSame(
            'Кузнецов',
            $model->withLocale('ru', fn () => $model->name->last_name),
            'Other locales are kept'
        );
    }

    public function testMappedCollectionMutationIsPersisted(): void
    {
        $model = new TestMultilingualModel;

        $model->names = [
            ['en' => ['first_name' => 'John', 'last_name' => 'Smith']],
            ['en' => ['first_name' => 'Gregory', 'last_name' => 'Johnson']],
        ];

        $model->save();

        $model->names->first()->first_name = 'Test';

        $this->assertTrue($model->isDirty('names'));

        $model->save();

        $model->refresh();

        $this->assertSame('Test', $model->names->first()->first_name, 'The change reached the database');

        $this->assertSame(
            ['first_name' => 'Gregory', 'last_name' => 'Johnson'],
            $model->names->last()->toArray(),
            'The other item is kept'
        );
    }

    public function testAssigningTheAttributeIsPersistedOverAHydratedObject(): void
    {
        $model = new TestMultilingualModel;

        $model->name = ['en' => ['first_name' => 'John', 'last_name' => 'Smith']];
        $model->save();

        $model->name;

        $model->name = ['en' => ['first_name' => 'Jane', 'last_name' => 'Doe']];

        $model->save();

        $model->refresh();

        $this->assertSame(['first_name' => 'Jane', 'last_name' => 'Doe'], $model->name->toArray());
    }

    public function testOnlyTheChangedAttributeIsDirty(): void
    {
        $model = new TestMultilingualModel;

        $model->string = 'en-test';
        $model->save();

        $model->name;

        $model->withLocale('ru', fn () => $model->string = 'ru-test');

        $this->assertSame(['string'], array_keys($model->getDirty()),
            'Writing hydrated objects back does not dirty the untouched attributes'
        );

        $model->save();

        $model->refresh();

        $this->assertSame('ru-test', $model->withLocale('ru', fn () => $model->string));
        $this->assertSame('en-test', $model->string);
    }
}
