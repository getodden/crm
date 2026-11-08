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

function panelWriteCounts(): array
{
    $counts = [];

    foreach (panelSourceFiles() as $path => $contents) {
        $writes = preg_match_all('/->(?:update|save|delete|create|updateOrCreate|firstOrCreate|insert|upsert|increment|decrement|forceDelete|push|sync|attach|detach)(?:Quietly)?\(|::(?:create|destroy|insert)\(|DB::table\(/', $contents);

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
        $kinds = str_contains($path, '/RelationManagers/') ? 'Create|Edit|Delete' : 'Delete';

        $chains = preg_split('/(?=\b(?:'.$kinds.')Action::make\()/', $contents);
        array_shift($chains);

        $implicit = 0;

        foreach ($chains as $chain) {
            $statement = preg_split('/\n\s*(?:\]\)|\]|\}|;)|\bAction::make\(|\n\s*(?:Create|Edit|Delete|Action|ForceDelete|Restore|View)\w*Action::make\(/', substr($chain, 1))[0];

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

it('deletes and edits through an action: no modal or delete action keeps the default write', function (): void {
    $allowed = [];

    expect(actionsWithoutUsing())->toBe($allowed);
});
