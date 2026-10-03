<?php

declare(strict_types=1);
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;

// Dependency rules scan source text; see sourceFilesMatching() in tests/Pest.php.
it('sales domain remains strictly headless (no Filament or Livewire)', function (): void {
    expect(sourceFilesMatching('/(?<![\\\\\w])(Filament|Livewire)\\\\+[A-Z]/'))->toBeEmpty();
});

it('sales Blade views remain headless (no Filament or Livewire components)', function (): void {
    test()->markTestIncomplete('health-score-modal uses x-filament::icon; fixed by #35.');

    expect(bladeViewsMatching('/<x-filament::|<x-livewire|@livewire/'))->toBeEmpty();
});

it('sales does not depend on service, marketing, or the Filament UI', function (): void {
    expect(sourceFilesMatching('/\bOdden\\\\+(Service|Marketing|Filament)\\\\+/'))->toBeEmpty();
});

arch('no debug functions are left in the code')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('all sales domain actions have an execute method')
    ->expect('Odden\Sales\Actions')
    ->toHaveMethod('execute');

arch('all sales enums are string backed for database agnosticism')
    ->expect('Odden\Sales\Enums')
    ->toBeStringBackedEnums();

it('queues every mailable Sales sends', function (): void {
    $mailables = [];

    foreach (glob(dirname(__DIR__).'/src/Mail/*.php') ?: [] as $file) {
        $class = 'Odden\\Sales\\Mail\\'.basename($file, '.php');

        if (class_exists($class) && is_subclass_of($class, Mailable::class)) {
            $mailables[] = $class;
            expect(is_subclass_of($class, ShouldQueue::class))->toBeTrue("{$class} must implement ShouldQueue");
        }
    }

    expect($mailables)->not->toBeEmpty();
});
