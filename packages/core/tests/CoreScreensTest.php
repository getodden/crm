<?php

declare(strict_types=1);

use Odden\Core\Exceptions\InvalidScreenRegistrationException;
use Odden\Core\Exceptions\ScreenNotAvailableException;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Core\Models\PropertyDefinition;
use Odden\Core\Screens\NoScreenTenantParameters;
use Odden\Core\Screens\Screen;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Core\Screens\Screens;
use Odden\Core\Screens\ScreenTenantParameters;

it('defines the core screens', function (): void {
    $keys = array_map(fn (Screen $screen): string => $screen->key, app(ScreenRegistry::class)->all());

    expect($keys)->toBe([
        'contacts.index', 'contacts.create', 'contacts.view', 'contacts.edit',
        'companies.index', 'companies.create', 'companies.view', 'companies.edit',
        'lists.index', 'lists.create', 'lists.view', 'lists.edit',
        'custom-properties.index', 'custom-properties.create', 'custom-properties.edit',
        'executive-overview',
        'data-quality',
    ]);
});

it('labels, groups and orders the core screens', function (): void {
    $registry = app(ScreenRegistry::class);

    expect($registry->get('contacts.index')->label)->toBe('Contacts')
        ->and($registry->get('contacts.index')->model)->toBe(Contact::class)
        ->and($registry->get('companies.index')->label)->toBe('Companies')
        ->and($registry->get('companies.index')->model)->toBe(Company::class)
        ->and($registry->get('lists.index')->label)->toBe('Lists')
        ->and($registry->get('lists.index')->model)->toBe(CrmList::class)
        ->and($registry->get('lists.index')->sort)->toBe(3)
        ->and($registry->get('custom-properties.index')->label)->toBe('Custom properties')
        ->and($registry->get('custom-properties.index')->group)->toBe('Settings')
        ->and($registry->get('custom-properties.index')->model)->toBe(PropertyDefinition::class)
        ->and($registry->get('executive-overview')->group)->toBe('Executive')
        ->and($registry->get('executive-overview')->sort)->toBe(-10)
        ->and($registry->get('data-quality')->group)->toBe('CRM')
        ->and($registry->get('data-quality')->sort)->toBe(10);
});

it('freezes the registry once the application has booted', function (): void {
    $registry = app(ScreenRegistry::class);

    expect($registry->isFrozen())->toBeTrue()
        ->and(Screens::isFrozen())->toBeTrue()
        ->and(fn () => Screens::define(Screen::page('late', 'Late')))->toThrow(InvalidScreenRegistrationException::class);
});

it('serves one registry and, by default, no tenant parameters', function (): void {
    expect(app(ScreenRegistry::class))->toBe(app(ScreenRegistry::class))
        ->and(app(ScreenTenantParameters::class))->toBeInstanceOf(NoScreenTenantParameters::class);
});

it('reads through the static facade', function (): void {
    expect(Screens::has('contacts.index'))->toBeTrue()
        ->and(Screens::get('contacts.index')->label)->toBe('Contacts')
        ->and(Screens::available('contacts.index'))->toBeFalse()
        ->and(fn () => Screens::url('contacts.index'))->toThrow(ScreenNotAvailableException::class);
});
