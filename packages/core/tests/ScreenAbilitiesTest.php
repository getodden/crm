<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Odden\Core\Screens\NoScreenTenantParameters;
use Odden\Core\Screens\Screen;
use Odden\Core\Screens\ScreenDrawer;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Core\Tests\Fixtures\RecordingScreenShell;
use Odden\Core\Tests\Fixtures\Ticket;
use Odden\Core\Tests\Fixtures\User;

function abilityRegistry(): ScreenRegistry
{
    $registry = new ScreenRegistry(new NoScreenTenantParameters);
    $registry->shell('alpha', RecordingScreenShell::class);
    $registry->define(...[
        ...Screen::forModel('tickets', Ticket::class, 'Tickets'),
        Screen::page('overview', 'Overview'),
        Screen::page('reports', 'Reports', ability: 'view-reports'),
    ]);

    return $registry;
}

function userWithoutAbilities(): Authenticatable
{
    return new User;
}

it('denies a guest every screen', function (): void {
    $registry = abilityRegistry();

    expect($registry->can('overview', null))->toBeFalse()
        ->and($registry->can('tickets.index', null))->toBeFalse();
});

it('allows a screen with no ability', function (): void {
    expect(abilityRegistry()->can('overview', userWithoutAbilities()))->toBeTrue();
});

it('checks a gate ability when the screen has no model', function (): void {
    $registry = abilityRegistry();
    $user = userWithoutAbilities();

    expect($registry->can('reports', $user))->toBeFalse();

    Gate::define('view-reports', fn (): bool => true);

    expect($registry->can('reports', $user))->toBeTrue();
});

it('allows a model with no policy', function (): void {
    expect(abilityRegistry()->can('tickets.edit', userWithoutAbilities(), new Ticket))->toBeTrue();
});

it('allows an ability the policy does not define', function (): void {
    Gate::policy(Ticket::class, get_class(new class
    {
        public function viewAny(): bool
        {
            return false;
        }
    }));

    expect(abilityRegistry()->can('tickets.create', userWithoutAbilities()))->toBeTrue();
});

it('asks the policy and passes the record, or the model class without one', function (): void {
    $policy = new class
    {
        public static mixed $subject = null;

        public function viewAny(User $user): bool
        {
            return false;
        }

        public function update(User $user, Ticket $ticket): bool
        {
            self::$subject = $ticket;

            return $ticket->title === 'open';
        }
    };
    Gate::policy(Ticket::class, $policy::class);
    $registry = abilityRegistry();
    $user = userWithoutAbilities();

    expect($registry->can('tickets.index', $user))->toBeFalse()
        ->and($registry->can('tickets.edit', $user, new Ticket(['title' => 'open'])))->toBeTrue()
        ->and($registry->can('tickets.edit', $user, new Ticket(['title' => 'closed'])))->toBeFalse()
        ->and($policy::$subject)->toBeInstanceOf(Ticket::class);
});

it('leaves a screen the user cannot open out of the navigation', function (): void {
    $registry = abilityRegistry();
    $registry->draw('tickets.index', new ScreenDrawer('alpha', 'App\Tickets'));
    $registry->draw('reports', new ScreenDrawer('alpha', 'App\Reports'));
    $user = userWithoutAbilities();

    $keys = fn (): array => array_map(fn (Screen $screen): string => $screen->key, $registry->navigation($user)[0]['screens'] ?? []);

    expect($keys())->toBe(['tickets.index']);

    Gate::define('view-reports', fn (): bool => true);

    expect($keys())->toBe(['reports', 'tickets.index'])
        ->and(array_map(fn (Screen $screen): string => $screen->key, $registry->navigation()[0]['screens']))->toBe(['reports', 'tickets.index']);
});
