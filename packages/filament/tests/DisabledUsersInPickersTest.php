<?php

declare(strict_types=1);

use Filament\Forms\Components\Select;
use Livewire\Livewire;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Core\Models\Mention;
use Odden\Core\Support\Mentions;
use Odden\Filament\Resources\ContactResource\Pages\CreateContact;
use Odden\Filament\Resources\ContactResource\Pages\EditContact;
use Odden\Filament\Resources\DealResource\Pages\CreateDeal;
use Odden\Filament\Resources\DealResource\Pages\EditDeal;
use Odden\Filament\Resources\LeadRoutingRuleResource\Pages\CreateLeadRoutingRule;
use Odden\Filament\Resources\LeadRoutingRuleResource\Pages\EditLeadRoutingRule;
use Odden\Filament\Resources\SalesMeetingLinkResource\Pages\CreateSalesMeetingLink;
use Odden\Filament\Resources\SalesQuotaResource\Pages\CreateSalesQuota;
use Odden\Filament\Support\MentionEditor;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Enums\LeadRoutingStrategy;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\LeadRoutingRule;
use Odden\Sales\Models\Pipeline;

beforeEach(function (): void {
    config(['odden-core.users.disabled_column' => 'disabled_at']);
    $this->viewer = User::factory()->create(['name' => 'Vera Viewer']);
    $this->gone = User::factory()->create(['name' => 'Dan Disabled', 'disabled_at' => now()]);
});

/**
 * @return Closure(Select): bool
 */
function offers(User $user, bool $offered = true): Closure
{
    return fn (Select $field): bool => array_key_exists($user->id, $field->getOptions()) === $offered;
}

it('offers enabled users and not disabled ones as the contact owner on a new contact', function (): void {
    Livewire::actingAs($this->viewer)->test(CreateContact::class)
        ->assertFormFieldExists('owner_id', offers($this->viewer))
        ->assertFormFieldExists('owner_id', offers($this->gone, false));
});

it('keeps a disabled owner on the contact and saves it unchanged', function (): void {
    $contact = Contact::factory()->create(['owner_id' => $this->gone->id, 'first_name' => 'Ada']);

    Livewire::actingAs($this->viewer)->test(EditContact::class, ['record' => $contact->getRouteKey()])
        ->assertFormFieldExists('owner_id', offers($this->gone))
        ->assertFormFieldExists('owner_id', fn (Select $field): bool => $field->getState() === $this->gone->id && $field->getOptionLabel() === 'Dan Disabled')
        ->fillForm(['first_name' => 'Adele'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contact->refresh())->first_name->toBe('Adele')->owner_id->toBe($this->gone->id);
});

it('does not offer a disabled user on a contact owned by an enabled one', function (): void {
    $contact = Contact::factory()->create(['owner_id' => $this->viewer->id]);

    Livewire::actingAs($this->viewer)->test(EditContact::class, ['record' => $contact->getRouteKey()])
        ->assertFormFieldExists('owner_id', offers($this->gone, false));
});

it('offers enabled users and not disabled ones as the deal owner, and keeps a disabled current owner', function (): void {
    Livewire::actingAs($this->viewer)->test(CreateDeal::class)
        ->assertFormFieldExists('owner_id', offers($this->viewer))
        ->assertFormFieldExists('owner_id', offers($this->gone, false));

    $pipeline = Pipeline::factory()->withStages()->create(['is_default' => true]);
    $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages->first()->id, 'owner_id' => $this->gone->id, 'name' => 'Big']);

    Livewire::actingAs($this->viewer)->test(EditDeal::class, ['record' => $deal->getRouteKey()])
        ->assertFormFieldExists('owner_id', offers($this->gone))
        ->fillForm(['name' => 'Bigger'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($deal->refresh())->name->toBe('Bigger')->owner_id->toBe($this->gone->id);
});

it('does not offer disabled users in the quota, meeting link or routing rule pickers', function (): void {
    Livewire::actingAs($this->viewer)->test(CreateSalesQuota::class)
        ->assertFormFieldExists('user_id', offers($this->viewer))
        ->assertFormFieldExists('user_id', offers($this->gone, false));

    Livewire::actingAs($this->viewer)->test(CreateSalesMeetingLink::class)
        ->assertFormFieldExists('user_id', offers($this->viewer))
        ->assertFormFieldExists('user_id', offers($this->gone, false));

    Livewire::actingAs($this->viewer)->test(CreateLeadRoutingRule::class)
        ->assertFormFieldExists('assigned_user_ids', offers($this->viewer))
        ->assertFormFieldExists('assigned_user_ids', offers($this->gone, false));
});

it('keeps a disabled rep on an existing routing rule', function (): void {
    $rule = LeadRoutingRule::create([
        'name' => 'Inbound',
        'strategy' => LeadRoutingStrategy::RoundRobin,
        'assigned_user_ids' => [$this->viewer->id, $this->gone->id],
        'sort_order' => 1,
        'is_active' => true,
    ]);

    Livewire::actingAs($this->viewer)->test(EditLeadRoutingRule::class, ['record' => $rule->getRouteKey()])
        ->assertFormFieldExists('assigned_user_ids', offers($this->gone));
});

it('offers every user everywhere while no disabled column is configured', function (): void {
    config(['odden-core.users.disabled_column' => null]);

    Livewire::actingAs($this->viewer)->test(CreateContact::class)
        ->assertFormFieldExists('owner_id', offers($this->gone));
    Livewire::actingAs($this->viewer)->test(CreateDeal::class)
        ->assertFormFieldExists('owner_id', offers($this->gone));
});

it('does not offer a disabled person in the mention picker but still resolves an existing mention of them', function (): void {
    $contact = Contact::factory()->create();
    $note = Activity::factory()->create([
        'subject_type' => $contact->getMorphClass(),
        'subject_id' => $contact->id,
        'type' => ActivityType::Note,
        'body' => 'Ask @Dan Disabled about it',
    ]);
    Mention::query()->create([
        'mentionable_type' => $note->getMorphClass(),
        'mentionable_id' => $note->id,
        'user_id' => $this->gone->id,
        'label' => 'Dan Disabled',
    ]);

    expect(Mentions::teammates('dan'))->toBe([])
        ->and(Mentions::teammates('vera'))->toBe([$this->viewer->id => 'Vera Viewer'])
        ->and(MentionEditor::document((string) $note->body, $note)['content'][0]['content'][1]['attrs'])->toMatchArray(['id' => (string) $this->gone->id, 'label' => 'Dan Disabled'])
        ->and(MentionEditor::labels([$this->gone->id]))->toBe([$this->gone->id => 'Dan Disabled']);
});
