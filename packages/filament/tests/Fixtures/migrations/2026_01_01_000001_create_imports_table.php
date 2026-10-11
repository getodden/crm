<?php

declare(strict_types=1);

use Filament\Actions\ActionsServiceProvider;

// Filament's own migrations have no timestamps, so they load alphabetically and create failed_import_rows before the
// imports table it references, which PostgreSQL and MySQL refuse. These two files give them an order.
return require dirname((new ReflectionClass(ActionsServiceProvider::class))->getFileName(), 2).'/database/migrations/create_imports_table.php';
