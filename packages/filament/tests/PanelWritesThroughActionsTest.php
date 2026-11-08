<?php

declare(strict_types=1);

function panelSourceFiles(): array
{
    $source = dirname(__DIR__).'/src';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    $found = [];

    foreach ($files as $file) {
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));

        if ($file->getExtension() === 'php' && ! str_starts_with($path, 'Imports/')) {
            $found[$path] = (string) file_get_contents($file->getPathname());
        }
    }

    ksort($found);

    return $found;
}

function modelWritesIn(string $contents): int
{
    return (int) preg_match_all('/->(?:update|save|saveMany|delete|create|createMany|forceCreate|updateOrCreate|firstOrCreate|insert|upsert|increment|decrement|forceDelete|push|sync|syncWithoutDetaching|toggle|updateExistingPivot|attach|detach|restore|touch)(?:OrFail)?(?:Quietly)?\(|::(?:create|forceCreate|destroy|insert)\(|DB::table\(|->(?:createOptionForm|editOptionForm)\(/', $contents);
}

function panelWriteCounts(): array
{
    $counts = [];

    foreach (panelSourceFiles() as $path => $contents) {
        $writes = modelWritesIn($contents);

        if ($writes > 0) {
            $counts[$path] = $writes;
        }
    }

    return $counts;
}

function pagesWithoutWriteHook(): array
{
    $missing = [];

    foreach (panelSourceFiles() as $path => $contents) {
        if (! str_contains($path, '/Pages/')) {
            continue;
        }

        if (preg_match('/extends CreateRecord\b/', $contents) && ! str_contains($contents, 'function handleRecordCreation(')) {
            $missing[] = $path;
        }

        if (preg_match('/extends EditRecord\b/', $contents) && ! str_contains($contents, 'function handleRecordUpdate(')) {
            $missing[] = $path;
        }
    }

    return $missing;
}

function actionsWithoutUsing(): array
{
    $counts = [];

    foreach (panelSourceFiles() as $path => $contents) {
        $kinds = str_contains($path, '/RelationManagers/')
            ? 'Create|Edit|Delete|DeleteBulk|ForceDelete|ForceDeleteBulk|Restore|RestoreBulk|Attach|Detach|DetachBulk'
            : 'Delete|DeleteBulk|ForceDelete|ForceDeleteBulk|Restore|RestoreBulk';

        $chains = preg_split('/(?=\b(?:'.$kinds.')Action::make\()/', $contents);
        array_shift($chains);

        $implicit = 0;

        foreach ($chains as $chain) {
            $statement = preg_split('/\n\s*(?:\]\)|\]|\}|;)|\bAction::make\(|\n\s*(?:Create|Edit|Delete|Action|ForceDelete|Restore|View|Attach|Detach)\w*Action::make\(/', substr($chain, 1))[0];

            if (! str_contains($statement, '->using(')) {
                $implicit++;
            }
        }

        if ($implicit > 0) {
            $counts[$path] = $implicit;
        }
    }

    return $counts;
}

function relationshipFieldsKeepingTheDefaultSave(string $contents): int
{
    $repeaters = preg_match_all('/Repeater::make\([^)]*\)(?:(?!Repeater::make\().)*?->relationship\(/s', $contents);
    $handledRepeaters = preg_match_all('/Repeater::make\([^)]*\)(?:(?!Repeater::make\().)*?->saveRelationshipsUsing\(/s', $contents);

    $unhandledOptionFields = 0;

    foreach (preg_split('/(?=\b[A-Z]\w*::make\()/', $contents) as $field) {
        $manyToMany = preg_match('/^CheckboxList::make\(/', $field) === 1
            || (preg_match('/^Select::make\(/', $field) === 1 && str_contains($field, '->multiple()'));

        if ($manyToMany && str_contains($field, '->relationship(') && ! str_contains($field, '->saveRelationshipsUsing(')) {
            $unhandledOptionFields++;
        }
    }

    return max(0, $repeaters - $handledRepeaters) + $unhandledOptionFields;
}

it('writes through actions: the panel holds only the model writes on this list, and the list only shrinks', function (): void {
    $allowed = [
        'Resources/ContactResource/Pages/EditContact.php' => 1,
        'Resources/DealResource/Pages/CreateDeal.php' => 1,
        'Resources/PipelineResource.php' => 1,
        'Resources/RelationManagers/ActivitiesRelationManager.php' => 2,
        'Resources/SalesMeetingLinkResource.php' => 2,
    ];

    expect(panelWriteCounts())->toBe($allowed);
});

it('saves create and edit pages through an action: the default save is not left in place', function (): void {
    $allowed = [
    ];

    expect(pagesWithoutWriteHook())->toBe($allowed);
});

it('deletes and edits through an action: only the bulk, restore, force-delete and attach actions on this list keep the default write, and the list only shrinks', function (): void {
    $allowed = [
        'Resources/CompanyResource.php' => 2,
        'Resources/CompanyResource/Pages/EditCompany.php' => 2,
        'Resources/CompanyResource/RelationManagers/ContactsRelationManager.php' => 2,
        'Resources/ContactResource.php' => 2,
        'Resources/ContactResource/Pages/EditContact.php' => 2,
        'Resources/ContactResource/RelationManagers/CompaniesRelationManager.php' => 2,
        'Resources/DealResource.php' => 3,
        'Resources/DealResource/Pages/EditDeal.php' => 1,
        'Resources/DealResource/Pages/ViewDeal.php' => 1,
        'Resources/DealResource/RelationManagers/DealCompaniesRelationManager.php' => 2,
        'Resources/DealResource/RelationManagers/DealContactsRelationManager.php' => 2,
        'Resources/QuoteResource.php' => 2,
        'Resources/QuoteResource/Pages/EditQuote.php' => 1,
        'Resources/QuoteResource/Pages/ViewQuote.php' => 1,
        'Resources/RelationManagers/DealsRelationManager.php' => 2,
    ];

    expect(actionsWithoutUsing())->toBe($allowed);
});

it('saves relationship repeaters and multi-selects through an action: the default relationship save is not left in place', function (): void {
    $found = [];

    foreach (panelSourceFiles() as $path => $contents) {
        if (relationshipFieldsKeepingTheDefaultSave($contents) > 0) {
            $found[] = $path;
        }
    }

    expect($found)->toBe([]);
});

it('notices a relationship repeater or multi-select that keeps the default save', function (): void {
    $repeater = "Repeater::make('items')->relationship('items')->schema([]);";
    $handled = "Repeater::make('items')->relationship('items')->saveRelationshipsUsing(fn () => null);";
    $select = "Select::make('tags')->multiple()->relationship('tags', 'name');";
    $belongsTo = "Select::make('pipeline')->relationship('pipeline', 'name');";
    $checkboxes = "CheckboxList::make('tags')->relationship('tags', 'name');";
    $maskedByNeighbour = "Select::make('a')->multiple()->relationship('a', 'name'); Select::make('b')->multiple()->relationship('b', 'name')->saveRelationshipsUsing(fn () => null);";

    expect(relationshipFieldsKeepingTheDefaultSave($repeater))->toBe(1)
        ->and(relationshipFieldsKeepingTheDefaultSave($select))->toBe(1)
        ->and(relationshipFieldsKeepingTheDefaultSave($handled))->toBe(0)
        ->and(relationshipFieldsKeepingTheDefaultSave($belongsTo))->toBe(0)
        ->and(relationshipFieldsKeepingTheDefaultSave($checkboxes))->toBe(1)
        ->and(relationshipFieldsKeepingTheDefaultSave($maskedByNeighbour))->toBe(1);
});

it('notices the writes a narrower pattern would miss', function (string $source): void {
    expect(modelWritesIn($source))->toBe(1);
})->with([
    '$deal->contacts()->syncWithoutDetaching($ids);',
    '$deal->contacts()->toggle($ids);',
    '$deal->items()->saveMany($items);',
    '$deal->items()->createMany($rows);',
    '$deal->touch();',
    '$deal->restore();',
    '$deal->updateOrFail($x);',
    'Contact::forceCreate($x);',
    "Select::make('a')->createOptionForm([]);",
    "Select::make('a')->editOptionForm([]);",
]);
