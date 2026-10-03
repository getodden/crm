<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Odden\Core\Models\Contact;
use Odden\Core\Support\ModelRegistry;

it('registers every core model and nothing else on boot', function (): void {
    $models = app(ModelRegistry::class)->models();

    expect($models)->toContain(Contact::class);

    foreach ($models as $class) {
        expect(is_subclass_of($class, Model::class))->toBeTrue()
            ->and(str_starts_with($class, 'Odden\\Core\\Models\\'))->toBeTrue();
    }

    $files = glob(dirname(__DIR__).'/src/Models/*.php');
    expect($models)->toHaveCount(count($files));
});

it('is a singleton so every package registers into the same list', function (): void {
    expect(app(ModelRegistry::class))->toBe(app(ModelRegistry::class));
});

it('reports table names as configured in this install', function (): void {
    config(['odden-core.tables.contacts' => 'crm_people']);

    expect(app(ModelRegistry::class)->tables())->toContain('crm_people')
        ->not->toContain('odden_contacts');
});

it('discovers only concrete Eloquent models', function (): void {
    $dir = sys_get_temp_dir().'/odden-registry-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/Widget.php', '<?php namespace OddenRegistryFixture; class Widget extends \Illuminate\Database\Eloquent\Model {}');
    file_put_contents($dir.'/Base.php', '<?php namespace OddenRegistryFixture; abstract class Base extends \Illuminate\Database\Eloquent\Model {}');
    file_put_contents($dir.'/Helper.php', '<?php namespace OddenRegistryFixture; class Helper {}');
    foreach (['Widget', 'Base', 'Helper'] as $name) {
        require_once $dir.'/'.$name.'.php';
    }

    $registry = new ModelRegistry;
    $registry->discover($dir, 'OddenRegistryFixture');

    expect($registry->models())->toBe(['OddenRegistryFixture\\Widget']);
});

it('rejects classes that are not concrete models', function (): void {
    (new ModelRegistry)->register(stdClass::class);
})->throws(InvalidArgumentException::class);

it('lists model-less tables alongside model tables, sorted and unique', function (): void {
    $registry = new ModelRegistry;
    $registry->register(Contact::class);
    $registry->registerTable('odden_pivot', 'odden_pivot');

    $tables = $registry->tables();

    expect($tables)->toContain('odden_pivot')
        ->and(array_count_values($tables)['odden_pivot'])->toBe(1)
        ->and($tables)->toBe(collect($tables)->sort()->values()->all());
});
