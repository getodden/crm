<?php

declare(strict_types=1);

function livewireWriteCounts(): array
{
    $root = dirname(__DIR__);
    $counts = [];

    foreach (['src', 'resources/views'] as $folder) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$folder}", FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $source = (string) file_get_contents($file->getPathname());
            $writes = preg_match_all('/->(?:update|save|delete|create|updateOrCreate|firstOrCreate|insert|upsert|increment|decrement|forceDelete|push|sync|attach|detach)(?:Quietly)?\(|::(?:create|destroy|insert)\(|DB::table\(|Audit::/', $source);

            if ($writes > 0) {
                $counts[substr($file->getPathname(), strlen($root) + 1)] = $writes;
            }
        }
    }

    ksort($counts);

    return $counts;
}

it('writes nothing itself: no model writes and no audit records in the source or the views', function (): void {
    expect(livewireWriteCounts())->toBe([]);
});

arch('no debug functions are left in the code')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('the screens do not use the DB facade')
    ->expect('Odden\Livewire')
    ->not->toUse('Illuminate\Support\Facades\DB');
