<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Feature;

use Odden\Core\Screens\Screens;
use Odden\Livewire\Tests\Fixtures\FixtureScreensServiceProvider;
use Odden\Livewire\Tests\RoutedTestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class ShellPrecedenceTest extends RoutedTestCase
{
    protected function drawContactListInFixtureShell(): void
    {
        FixtureScreensServiceProvider::drawInFixtureShell('contacts.index');
    }

    #[DefineEnvironment('drawContactListInFixtureShell')]
    public function test_livewire_wins_over_a_lower_priority_shell_once_its_routes_exist(): void
    {
        $this->assertSame('/crm/contacts', Screens::url('contacts.index', absolute: false));
    }
}
