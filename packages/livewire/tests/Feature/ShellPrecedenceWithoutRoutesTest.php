<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Feature;

use Odden\Core\Screens\Screens;
use Odden\Livewire\Tests\Fixtures\FixtureScreensServiceProvider;
use Odden\Livewire\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class ShellPrecedenceWithoutRoutesTest extends TestCase
{
    protected function drawContactListInFixtureShell(): void
    {
        FixtureScreensServiceProvider::drawInFixtureShell('contacts.index');
    }

    #[DefineEnvironment('drawContactListInFixtureShell')]
    public function test_the_other_shell_keeps_the_screen_while_no_livewire_routes_are_registered(): void
    {
        $this->assertSame('https://fixture.test/contacts.index', Screens::url('contacts.index'));
    }
}
