<?php

declare(strict_types=1);

function panelWriteCounts(): array
{
    $source = dirname(__DIR__).'/src';
    $counts = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $path = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));

        if ($file->getExtension() !== 'php' || str_starts_with($path, 'Imports/')) {
            continue;
        }

        $writes = preg_match_all('/->update\(|->save\(\)|->delete\(\)|::create\(/', (string) file_get_contents($file->getPathname()));

        if ($writes > 0) {
            $counts[$path] = $writes;
        }
    }

    ksort($counts);

    return $counts;
}

it('writes through actions: the panel holds only the model writes on this list, and the list only shrinks', function (): void {
    $allowed = [
        'Pages/SalesCockpit.php' => 7,
        'Resources/ContactResource/Pages/EditContact.php' => 1,
        'Resources/DealResource/Pages/CreateDeal.php' => 1,
        'Resources/PipelineResource.php' => 1,
        'Resources/RelationManagers/ActivitiesRelationManager.php' => 2,
        'Resources/SalesMeetingLinkResource.php' => 2,
    ];

    expect(panelWriteCounts())->toBe($allowed);
});
