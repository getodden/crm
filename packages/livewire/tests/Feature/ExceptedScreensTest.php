<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Odden\Core\Screens\Screens;
use Odden\Livewire\Tests\Fixtures\FixtureScreensServiceProvider;
use Odden\Livewire\Tests\RoutedTestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class ExceptedScreensTest extends RoutedTestCase
{
    protected function exceptContacts(): void
    {
        FixtureScreensServiceProvider::$exceptions = ['contacts.*'];
    }

    #[DefineEnvironment('exceptContacts')]
    public function test_an_excepted_screen_gets_no_route(): void
    {
        $this->assertFalse(Route::has('odden-livewire.contacts.index'));
        $this->assertFalse(Screens::available('contacts.index'));
        $this->get('/crm/contacts')->assertNotFound();
    }
}
