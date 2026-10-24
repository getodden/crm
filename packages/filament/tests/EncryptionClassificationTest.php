<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Encryption\Casts\EncryptedCast;
use Odden\Core\Encryption\FieldEncrypter;
use Odden\Core\Models\Contact;
use Odden\Core\Support\ModelRegistry;

uses(RefreshDatabase::class);

/*
 * Every column of every Odden table is classified, so a new column cannot quietly hold personal data (PLAN-encryption-at-rest.md,
 * sections 3 and 7). A column is one of:
 *
 *   structural  stays plain on purpose: ids, foreign keys, timestamps, enums, counters, configuration, and text that is not about a person
 *   encrypted   has an encrypted cast under the given field name, and a text column to hold the longer stored form
 *   pending     personal or free text that is not encrypted yet, with the step of the plan that does it
 *
 * Moving a column from pending to encrypted means adding the cast and a migration and changing one line here.
 */
function oddenColumnClassification(): array
{
    $timestamps = ['created_at', 'updated_at'];

    return [
        'odden_action_log' => [
            'structural' => ['id', 'action', 'actor_kind', 'actor_user_id', 'via', 'status', 'subject_type', 'subject_id', 'idempotency_key', 'proposal_id', 'created_at'],
            'pending' => ['input' => 'step 7: what an action was asked to do, can hold the values it changed', 'error' => 'step 7: error text can quote input'],
        ],
        'odden_activities' => [
            'structural' => ['id', 'subject_type', 'subject_id', 'type', 'status', 'due_at', 'completed_at', 'creator_id', 'marker', ...$timestamps],
            'encrypted' => ['title' => 'activities.title', 'body' => 'activities.body', 'metadata' => 'activities.metadata'],
        ],
        'odden_association_types' => [
            'structural' => ['id', 'name', 'label', 'reverse_label', 'cardinality', 'from_record_type', 'to_record_type', 'is_system', 'team_id', ...$timestamps],
        ],
        'odden_associations' => [
            'structural' => ['id', 'parent_type', 'parent_id', 'child_type', 'child_id', 'type', 'association_type_id', 'label', ...$timestamps],
        ],
        'odden_companies' => [
            'structural' => ['id', 'industry', 'owner_id', 'team_id', 'health_score', 'health_status', 'last_health_calculated_at', 'lifecycle_stage', 'became_subscriber_at', 'became_lead_at', 'became_marketing_qualified_lead_at', 'became_sales_qualified_lead_at', 'became_opportunity_at', 'became_customer_at', 'became_evangelist_at', 'became_other_at', 'account_tier', 'intent_score', 'intent_surge', 'buying_committee_size', 'last_intent_activity_at', 'deleted_at', ...$timestamps],
            'pending' => ['name' => 'step 3: identity, with a search index', 'domain' => 'step 3: identity, exact index', 'phone' => 'step 3: identity, exact index', 'properties' => 'step 6: custom properties marked sensitive'],
        ],
        'odden_contacts' => [
            'structural' => ['id', 'lifecycle_stage', 'owner_id', 'team_id', 'lead_status', 'last_contacted_at', 'became_subscriber_at', 'became_lead_at', 'became_marketing_qualified_lead_at', 'became_sales_qualified_lead_at', 'became_opportunity_at', 'became_customer_at', 'became_evangelist_at', 'became_other_at', 'lead_score', 'lead_score_updated_at', 'email_status', 'email_status_reason', 'email_checked_at', 'deleted_at', ...$timestamps],
            'encrypted' => ['date_of_birth' => 'contacts.date_of_birth'],
            'pending' => [
                'first_name' => 'step 3: identity, word and prefix index', 'last_name' => 'step 3: identity, word and prefix index',
                'email' => 'step 3: identity, exact index', 'phone' => 'step 3: identity, exact index',
                'job_title' => 'step 3: identity', 'linkedin_url' => 'step 3: identity', 'timezone' => 'step 3: identity',
                'properties' => 'step 6: custom properties marked sensitive',
            ],
        ],
        'odden_custom_object_definitions' => [
            'structural' => ['id', 'name', 'singular_label', 'plural_label', 'description', 'primary_display_property', 'secondary_display_properties', 'icon', 'team_id', ...$timestamps],
        ],
        'odden_custom_object_records' => [
            'structural' => ['id', 'definition_id', 'owner_id', 'team_id', 'deleted_at', ...$timestamps],
            'pending' => ['name' => 'step 6: a custom object can hold anything, so its name and values follow the sensitive switch', 'properties' => 'step 6: same'],
        ],
        'odden_deal_products' => [
            'structural' => ['id', 'deal_id', 'name', 'sku', 'description', 'unit_price', 'quantity', 'discount_percent', 'total_price', 'sort_order', 'deleted_at', ...$timestamps],
        ],
        'odden_deal_stage_history' => [
            'structural' => ['id', 'deal_id', 'from_stage_id', 'to_stage_id', 'user_id', 'duration_in_stage_seconds', 'entered_at', 'exited_at', ...$timestamps],
        ],
        'odden_deals' => [
            // lost_reason is picked from a list and counted by the forecast, so it stays plain; the free text beside it is encrypted.
            // amount stays plain unless the workspace turns on business-data encryption (step 6).
            'structural' => ['id', 'pipeline_id', 'stage_id', 'amount', 'currency', 'status', 'expected_close_date', 'closed_at', 'lost_reason', 'owner_id', 'team_id', 'deleted_at', ...$timestamps],
            'encrypted' => ['lost_notes' => 'deals.lost_notes'],
            'pending' => ['name' => 'step 3: deal names often carry a person or company name, and the list searches them', 'properties' => 'step 6: custom properties marked sensitive'],
        ],
        'odden_demo_records' => [
            'structural' => ['id', 'model_type', 'model_id', 'created_at'],
        ],
        'odden_lifecycle_stage_transitions' => [
            'structural' => ['id', 'record_type', 'record_id', 'from_stage', 'to_stage', 'duration_seconds', 'source', 'user_id', 'team_id', 'transitioned_at', ...$timestamps],
        ],
        'odden_list_memberships' => [
            'structural' => ['id', 'list_id', 'member_type', 'member_id', 'added_at'],
        ],
        'odden_lists' => [
            'structural' => ['id', 'name', 'description', 'entity_type', 'type', 'created_by_id', ...$timestamps],
            'pending' => ['criteria' => 'step 3: rules can hold an email or a name, and become index lookups'],
        ],
        'odden_pipeline_stages' => [
            'structural' => ['id', 'pipeline_id', 'name', 'code', 'probability', 'sort_order', 'is_closed_won', 'is_closed_lost', 'rot_after_days', ...$timestamps],
        ],
        'odden_pipelines' => [
            'structural' => ['id', 'name', 'code', 'is_default', 'is_active', 'team_id', ...$timestamps],
        ],
        'odden_properties' => [
            'structural' => ['id', 'entity_type', 'name', 'label', 'type', 'group_name', 'options', 'description', 'is_required', 'is_searchable', 'sort_order', ...$timestamps],
        ],
        'odden_property_history' => [
            'structural' => ['id', 'auditable_type', 'auditable_id', 'property_name', 'user_id', 'source', 'created_at'],
            'encrypted' => ['old_value' => 'property_history.old_value', 'new_value' => 'property_history.new_value'],
        ],
        'odden_proposed_actions' => [
            'structural' => ['id', 'action', 'status', 'requested_by_kind', 'requested_by_user_id', 'via', 'idempotency_key', 'decided_by_user_id', 'decided_at', 'expires_at', ...$timestamps],
            'pending' => ['input' => 'step 7: what an action was asked to do, can hold the values it changed', 'error' => 'step 7: error text can quote input'],
        ],
        'odden_quote_items' => [
            'structural' => ['id', 'quote_id', 'name', 'sku', 'description', 'unit_price', 'quantity', 'discount_percent', 'total_price', 'sort_order', ...$timestamps],
        ],
        'odden_quotes' => [
            // terms are the seller's standard wording, shown to the customer; the notes are written about the customer.
            'structural' => ['id', 'deal_id', 'quote_number', 'title', 'status', 'subtotal', 'discount_amount', 'tax_amount', 'total_amount', 'currency', 'terms', 'public_token', 'expires_at', 'accepted_at', 'user_id', 'deleted_at', ...$timestamps],
            'encrypted' => ['notes' => 'quotes.notes', 'internal_notes' => 'quotes.internal_notes'],
            'pending' => ['signed_by_name' => 'step 3: identity', 'signed_by_email' => 'step 3: identity'],
        ],
        'odden_sales_email_templates' => [
            'structural' => ['id', 'name', 'subject', 'body_html', 'category', 'user_id', 'is_shared', ...$timestamps],
        ],
        'odden_sales_lead_routing_rules' => [
            'structural' => ['id', 'name', 'strategy', 'criteria', 'assigned_user_ids', 'last_assigned_index', 'is_active', 'sort_order', ...$timestamps],
        ],
        'odden_sales_meeting_bookings' => [
            'structural' => ['id', 'uid', 'meeting_link_id', 'user_id', 'contact_id', 'activity_id', 'starts_at', 'ends_at', 'timezone', 'cancelled_at', ...$timestamps],
            'pending' => ['invitee_name' => 'step 3: identity', 'invitee_email' => 'step 3: identity'],
        ],
        'odden_sales_meeting_links' => [
            'structural' => ['id', 'user_id', 'slug', 'title', 'duration_minutes', 'description', 'working_hours', 'is_active', 'buffer_minutes', 'timezone', ...$timestamps],
        ],
        'odden_sales_playbooks' => [
            'structural' => ['id', 'name', 'slug', 'category', 'framework', 'description', 'questions', 'is_active', 'user_id', ...$timestamps],
        ],
        'odden_sales_quotas' => [
            'structural' => ['id', 'user_id', 'pipeline_id', 'period_type', 'period_start', 'period_end', 'target_amount', 'currency', ...$timestamps],
        ],
        'odden_sales_sequence_enrollments' => [
            'structural' => ['id', 'sequence_id', 'contact_id', 'current_step', 'status', 'next_step_due_at', 'enrolled_by_id', 'enrolled_at', ...$timestamps],
        ],
        'odden_sales_sequences' => [
            'structural' => ['id', 'name', 'description', 'steps', 'is_active', 'user_id', ...$timestamps],
        ],
        'odden_stage_automations' => [
            'structural' => ['id', 'stage_id', 'name', 'event_trigger', 'action_type', 'action_payload', 'is_active', 'sort_order', ...$timestamps],
        ],
    ];
}

it('classifies every column of every Odden table, and nothing that does not exist', function (): void {
    $classified = oddenColumnClassification();
    $found = [];

    foreach (Schema::getTables() as $table) {
        if (! str_starts_with($table['name'], 'odden_')) {
            continue;
        }

        $found[$table['name']] = array_column(Schema::getColumns($table['name']), 'name');
    }

    expect(array_keys($found))->toEqualCanonicalizing(array_keys($classified), 'tables without a classification (or classified but gone)');

    foreach ($found as $table => $columns) {
        $listed = [
            ...($classified[$table]['structural'] ?? []),
            ...array_keys($classified[$table]['encrypted'] ?? []),
            ...array_keys($classified[$table]['pending'] ?? []),
        ];

        expect(array_diff($columns, $listed))->toBe([], "{$table}: columns with no classification")
            ->and(array_diff($listed, $columns))->toBe([], "{$table}: classified columns that do not exist")
            ->and(count($listed))->toBe(count(array_unique($listed)), "{$table}: a column is classified twice");
    }
});

it('encrypts every column classified as encrypted: a text column, an encrypted cast, the agreed field name', function (): void {
    $models = [];

    foreach (app(ModelRegistry::class)->models() as $class) {
        $models[(new $class)->getTable()] = new $class;
    }

    foreach (oddenColumnClassification() as $table => $groups) {
        foreach ($groups['encrypted'] ?? [] as $column => $field) {
            $type = collect(Schema::getColumns($table))->firstWhere('name', $column)['type_name'] ?? null;

            expect($type)->toBeIn(['text', 'longtext', 'mediumtext'], "{$table}.{$column} must be a text column");
            expect($models)->toHaveKey($table);

            /** @var Model $model */
            $model = $models[$table];
            $cast = $model->getCasts()[$column] ?? '';

            expect(is_string($cast) && is_subclass_of(explode(':', $cast, 2)[0], EncryptedCast::class))->toBeTrue("{$table}.{$column} needs an encrypted cast")
                ->and(explode(':', $cast, 2)[1] ?? null)->toBe($field, "{$table}.{$column} cast field name");
        }
    }
});

it('stores the date of birth encrypted and still reads it as a date', function (): void {
    $contact = Contact::factory()->create(['date_of_birth' => '2011-03-02']);

    $stored = DB::table('odden_contacts')->where('id', $contact->id)->value('date_of_birth');

    expect(app(FieldEncrypter::class)->isEncrypted($stored))->toBeTrue()
        ->and($stored)->not->toContain('2011')
        ->and($contact->fresh()->date_of_birth->toDateString())->toBe('2011-03-02');
});
