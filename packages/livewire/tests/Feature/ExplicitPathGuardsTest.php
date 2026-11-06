<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Feature;

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Odden\Core\Exceptions\InvalidScreenRegistrationException;
use Odden\Core\Screens\Screen;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Livewire\Screens\LivewireScreens;
use Odden\Livewire\Tests\Fixtures\FixtureScreensServiceProvider;
use Odden\Livewire\Tests\Fixtures\PlainComponent;
use Odden\Livewire\Tests\Fixtures\Screens\Notes;
use Odden\Livewire\Tests\Fixtures\Screens\Things;
use Odden\Livewire\Tests\Fixtures\Screens\ThingsView;
use Odden\Livewire\Tests\Fixtures\Screens\WrongKey;
use Odden\Livewire\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;

class ExplicitPathGuardsTest extends TestCase
{
    protected function freshRegistry(): ScreenRegistry
    {
        $screens = new ScreenRegistry;
        $this->app->instance(ScreenRegistry::class, $screens);

        return $screens;
    }

    protected function drawRoutes(?array $only = null): void
    {
        app('router')->setRoutes(new RouteCollection);
        LivewireScreens::routes('crm', ['web'], $only);
        app('router')->getRoutes()->refreshNameLookups();
    }

    protected function defineSamePathTwice(): void
    {
        FixtureScreensServiceProvider::$configure = function (ScreenRegistry $screens): void {
            $screens->define(Screen::page('notes', 'Notes'), Screen::page('things', 'Things'));
            LivewireScreens::component($screens, 'notes', Notes::class, path: 'shared');
            LivewireScreens::component($screens, 'things', Things::class, path: 'shared');
        };
    }

    protected function definePathMatchingAKeyDerivedPath(): void
    {
        FixtureScreensServiceProvider::$configure = function (ScreenRegistry $screens): void {
            $screens->define(Screen::page('notes', 'Notes'), Screen::page('things', 'Things'));
            LivewireScreens::component($screens, 'notes', Notes::class, path: 'things');
            LivewireScreens::component($screens, 'things', Things::class);
        };
    }

    protected function defineRecordPathWithoutRecord(): void
    {
        FixtureScreensServiceProvider::$configure = function (ScreenRegistry $screens): void {
            $screens->define(new Screen(key: 'things.view', label: 'Thing', record: true));
            LivewireScreens::component($screens, 'things.view', ThingsView::class, path: 'thing');
        };
    }

    protected function defineExplicitPath(): void
    {
        FixtureScreensServiceProvider::$configure = function (ScreenRegistry $screens): void {
            $screens->define(Screen::page('notes', 'Notes'), Screen::page('things', 'Things'));
            LivewireScreens::component($screens, 'notes', Notes::class, path: 'team-notes');
            LivewireScreens::component($screens, 'things', Things::class);
        };
    }

    #[DefineEnvironment('defineSamePathTwice')]
    public function test_two_screens_claiming_the_same_explicit_path_are_refused(): void
    {
        $this->expectException(InvalidScreenRegistrationException::class);
        $this->expectExceptionMessage('[shared]');

        $this->drawRoutes();
    }

    #[DefineEnvironment('definePathMatchingAKeyDerivedPath')]
    public function test_an_explicit_path_colliding_with_a_derived_path_is_refused(): void
    {
        $this->expectException(InvalidScreenRegistrationException::class);
        $this->expectExceptionMessage('[things]');

        $this->drawRoutes();
    }

    #[DefineEnvironment('defineRecordPathWithoutRecord')]
    public function test_a_record_screen_path_without_the_record_placeholder_is_refused(): void
    {
        $this->expectException(InvalidScreenRegistrationException::class);
        $this->expectExceptionMessage('[things.view]');
        $this->expectExceptionMessage('{record}');

        $this->drawRoutes();
    }

    public function test_a_valid_explicit_path_is_accepted(): void
    {
        $screens = $this->freshRegistry();
        $screens->define(Screen::page('notes', 'Notes'), new Screen(key: 'things.view', label: 'Thing', record: true));
        LivewireScreens::component($screens, 'notes', Notes::class, path: 'team-2/notes');
        LivewireScreens::component($screens, 'things.view', ThingsView::class, path: 'thing/{record}/detail');

        $this->drawRoutes();

        $this->assertTrue(Route::has('odden-livewire.notes'));
        $this->assertTrue(Route::has('odden-livewire.things.view'));
    }

    /**
     * @return array<string, array{string, bool, string}>
     */
    public static function invalidPaths(): array
    {
        return [
            'empty' => ['', false, '[]'],
            'leading slash' => ['/team-notes', false, '[/team-notes]'],
            'trailing slash' => ['team-notes/', false, '[team-notes/]'],
            'double slash' => ['crm//notes', false, '[crm//notes]'],
            'parent segment' => ['crm/../admin', false, '[crm/../admin]'],
            'uppercase' => ['Team-Notes', false, '[Team-Notes]'],
            'query string' => ['notes?x=1', false, '[notes?x=1]'],
            'fragment' => ['notes#top', false, '[notes#top]'],
            'whitespace' => ['team notes', false, '[team notes]'],
            'doubled hyphen' => ['team--notes', false, '[team--notes]'],
            'repeated prefix parameter' => ['{workspace}/notes', false, '[{workspace}/notes]'],
            'other parameter on a page' => ['notes/{foo}', false, '[notes/{foo}]'],
            'record on a page' => ['notes/{record}', false, '[notes/{record}]'],
            'record twice' => ['thing/{record}/{record}', true, '[thing/{record}/{record}]'],
            'other parameter instead of record' => ['thing/{id}', true, '[thing/{id}]'],
            'record screen without record' => ['thing', true, '[thing]'],
            'trailing newline' => ["notes\n", false, "[notes\n]"],
            'leading hyphen' => ['-notes', false, '[-notes]'],
            'trailing hyphen' => ['notes-', false, '[notes-]'],
            'record as the whole path' => ['{record}', true, '[{record}]'],
            'record as the first segment' => ['{record}/edit', true, '[{record}/edit]'],
        ];
    }

    #[DataProvider('invalidPaths')]
    public function test_an_invalid_explicit_path_is_refused_naming_the_key_and_the_path(string $path, bool $record, string $quotedPath): void
    {
        $key = $record ? 'things.view' : 'notes';
        $screens = $this->freshRegistry();
        $screens->define(new Screen(key: $key, label: 'Screen', record: $record));
        LivewireScreens::component($screens, $key, $record ? ThingsView::class : Notes::class, path: $path);

        try {
            $this->drawRoutes();
            $this->fail('The path was accepted.');
        } catch (InvalidScreenRegistrationException $exception) {
            $this->assertStringContainsString("[{$key}]", $exception->getMessage());
            $this->assertStringContainsString($quotedPath, $exception->getMessage());
            $this->assertStringContainsString('not allowed', $exception->getMessage());
        }
    }

    public function test_an_explicit_path_does_not_bypass_the_authorization_guard(): void
    {
        $screens = $this->freshRegistry();
        $screens->define(Screen::page('notes', 'Notes'));
        LivewireScreens::component($screens, 'notes', PlainComponent::class, path: 'team-notes');

        $this->expectException(InvalidScreenRegistrationException::class);
        $this->expectExceptionMessage('permission check');

        $this->drawRoutes();
    }

    public function test_an_explicit_path_does_not_bypass_the_screen_key_guard(): void
    {
        $screens = $this->freshRegistry();
        $screens->define(Screen::page('notes', 'Notes'));
        LivewireScreens::component($screens, 'notes', WrongKey::class, path: 'team-notes');

        $this->expectException(InvalidScreenRegistrationException::class);
        $this->expectExceptionMessage('authorizes the screen [contacts.view] instead');

        $this->drawRoutes();
    }

    #[DefineEnvironment('defineExplicitPath')]
    public function test_a_screen_with_an_explicit_path_that_is_not_listed_gets_no_route(): void
    {
        $this->drawRoutes(['things']);

        $this->assertFalse(Route::has('odden-livewire.notes'));
        $this->assertTrue(Route::has('odden-livewire.things'));
    }
}
