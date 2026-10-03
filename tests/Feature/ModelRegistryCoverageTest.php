<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Odden\Core\Support\ModelRegistry;

// A tenancy add-on acts on every table the registry lists. A table created by a package migration
// but missing from the registry would silently escape tenant scoping, so this fails until the
// new model (or model-less table) is registered.
it('covers every table the Odden packages create', function (): void {
    $created = collect(Schema::getTableListing())
        ->map(fn (string $table): string => preg_replace('/^.*\./', '', $table))
        ->filter(fn (string $table): bool => str_starts_with($table, 'odden_'))
        ->sort()->values()->all();

    $registered = app(ModelRegistry::class)->tables();

    expect(array_values(array_diff($created, $registered)))->toBe([], 'Tables created by package migrations but not registered');
    expect(array_values(array_diff($registered, $created)))->toBe([], 'Registered tables no migration creates');
});
