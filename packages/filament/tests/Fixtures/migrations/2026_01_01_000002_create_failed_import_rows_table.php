<?php

declare(strict_types=1);

use Filament\Actions\ActionsServiceProvider;

return require dirname((new ReflectionClass(ActionsServiceProvider::class))->getFileName(), 2).'/database/migrations/create_failed_import_rows_table.php';
