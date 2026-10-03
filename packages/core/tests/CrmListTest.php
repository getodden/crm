<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Actions\EvaluateActiveListAction;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Enums\ListType;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;

it('can manage static list members', function (): void {
    $list = CrmList::create([
        'name' => 'Q4 Event Invitees',
        'entity_type' => 'contact',
        'type' => ListType::Static,
    ]);

    $contact1 = Contact::factory()->create();
    $contact2 = Contact::factory()->create();

    $list->addMember($contact1);

    expect($list->hasMember($contact1))->toBeTrue()
        ->and($list->hasMember($contact2))->toBeFalse()
        ->and($list->contacts)->toHaveCount(1);

    $list->removeMember($contact1);

    expect($list->hasMember($contact1))->toBeFalse();
});

it('evaluates active smart list criteria dynamically', function (): void {
    $activeList = CrmList::create([
        'name' => 'High Value Leads',
        'entity_type' => 'contact',
        'type' => ListType::Active,
        'criteria' => [
            [
                'property' => 'lifecycle_stage',
                'operator' => '=',
                'value' => 'lead',
            ],
            [
                'property' => 'lead_score',
                'operator' => '>=',
                'value' => 50,
            ],
        ],
    ]);

    // Matching contact: lead with score 80
    $match = Contact::factory()->create([
        'lifecycle_stage' => LifecycleStage::Lead,
        'properties' => ['lead_score' => 80],
    ]);

    // Non-matching 1: lead with score 30
    Contact::factory()->create([
        'lifecycle_stage' => LifecycleStage::Lead,
        'properties' => ['lead_score' => 30],
    ]);

    // Non-matching 2: customer with score 90
    Contact::factory()->create([
        'lifecycle_stage' => LifecycleStage::Customer,
        'properties' => ['lead_score' => 90],
    ]);

    $syncedCount = $activeList->syncActiveMembers();

    expect($syncedCount)->toBe(1)
        ->and($activeList->contacts)->toHaveCount(1)
        ->and($activeList->contacts->first()->id)->toBe($match->id);
});

it('evaluates behavioral list rules against a renamed contacts table', function (): void {
    Schema::create('custom_asset_downloads', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('contact_id');
    });
    Schema::create('custom_event_registrations', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('contact_id');
        $table->string('status');
    });

    $downloaded = Contact::factory()->create();
    $attended = Contact::factory()->create();
    $neither = Contact::factory()->create();

    DB::table('custom_asset_downloads')->insert(['contact_id' => $downloaded->id]);
    DB::table('custom_event_registrations')->insert(['contact_id' => $attended->id, 'status' => 'attended']);

    Schema::rename('odden_contacts', 'renamed_contacts');
    config([
        'odden-core.tables.contacts' => 'renamed_contacts',
        'odden-marketing.tables.asset_downloads' => 'custom_asset_downloads',
        'odden-marketing.tables.event_registrations' => 'custom_event_registrations',
    ]);

    $evaluate = function (string $property) use ($neither): array {
        $list = CrmList::create([
            'name' => $property,
            'entity_type' => 'contact',
            'type' => ListType::Active,
            'criteria' => [['property' => $property, 'operator' => '=', 'value' => true]],
        ]);

        app(EvaluateActiveListAction::class)->execute($list);

        return $list->contacts()->pluck('renamed_contacts.id')->all();
    };

    expect($evaluate('has_downloaded_asset'))->toBe([$downloaded->id])
        ->and($evaluate('has_attended_event'))->toBe([$attended->id]);
});
