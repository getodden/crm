<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Encryption\BusinessDataColumns;
use Odden\Core\Encryption\Casts\CustomProperties;
use Odden\Core\Encryption\Casts\EncryptedAmount;
use Odden\Core\Encryption\Casts\EncryptedCast;
use Odden\Core\Encryption\FieldEncrypter;
use Odden\Core\Enums\PropertyType;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\PropertyDefinition;
use Odden\Core\Support\ModelRegistry;
use Odden\Sales\Models\Deal;

uses(RefreshDatabase::class);

/*
 * Every column of every Odden table is classified, so a new column cannot quietly hold personal data (PLAN-encryption-at-rest.md,
 * sections 3 and 7). A column is one of:
 *
 *   structural  stays plain on purpose: ids, foreign keys, timestamps, enums, counters, configuration, and text that is not about a person
 *   encrypted   has an encrypted cast under the given field name, and a text column to hold the longer stored form
 *   json        a JSON column of custom property values: the values of properties the workspace marks sensitive are encrypted one by
 *               one (the CustomProperties cast, PLAN-encryption-at-rest.md section 6), the rest is structural
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
            'encrypted' => ['input' => 'action_log.input', 'error' => 'action_log.error'],
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
            'encrypted' => ['name' => 'companies.name', 'domain' => 'companies.domain', 'phone' => 'companies.phone'],
            'json' => ['properties' => 'custom properties of a company'],
        ],
        'odden_contacts' => [
            'structural' => ['id', 'lifecycle_stage', 'owner_id', 'team_id', 'lead_status', 'last_contacted_at', 'became_subscriber_at', 'became_lead_at', 'became_marketing_qualified_lead_at', 'became_sales_qualified_lead_at', 'became_opportunity_at', 'became_customer_at', 'became_evangelist_at', 'became_other_at', 'lead_score', 'lead_score_updated_at', 'email_status', 'email_status_reason', 'email_checked_at', 'deleted_at', ...$timestamps],
            'encrypted' => [
                'date_of_birth' => 'contacts.date_of_birth', 'first_name' => 'contacts.first_name', 'last_name' => 'contacts.last_name',
                'email' => 'contacts.email', 'phone' => 'contacts.phone', 'job_title' => 'contacts.job_title',
                'linkedin_url' => 'contacts.linkedin_url', 'timezone' => 'contacts.timezone',
            ],
            'json' => ['properties' => 'custom properties of a contact'],
        ],
        'odden_custom_object_definitions' => [
            'structural' => ['id', 'name', 'singular_label', 'plural_label', 'description', 'primary_display_property', 'secondary_display_properties', 'icon', 'team_id', ...$timestamps],
        ],
        'odden_custom_object_records' => [
            'structural' => ['id', 'definition_id', 'owner_id', 'team_id', 'deleted_at', ...$timestamps],
            // A custom object can hold anything, so its name and all its values are encrypted whole (EncryptedJson, not CustomProperties).
            'encrypted' => ['name' => 'custom_object_records.name', 'properties' => 'custom_object_records.properties'],
        ],
        'odden_deal_products' => [
            'structural' => ['id', 'deal_id', 'sku', 'unit_price', 'quantity', 'discount_percent', 'total_price', 'sort_order', 'deleted_at', ...$timestamps],
            'encrypted' => ['name' => 'deal_products.name', 'description' => 'deal_products.description'],
        ],
        'odden_deal_stage_history' => [
            'structural' => ['id', 'deal_id', 'from_stage_id', 'to_stage_id', 'user_id', 'duration_in_stage_seconds', 'entered_at', 'exited_at', ...$timestamps],
        ],
        'odden_deals' => [
            // The lost reason is encrypted too (it accepts any text); the forecast counts the reasons in PHP.
            // amount stays plain unless the workspace turns on business-data encryption (step 6): then the value moves to amount_encrypted.
            'structural' => ['id', 'pipeline_id', 'stage_id', 'amount', 'currency', 'status', 'expected_close_date', 'closed_at', 'owner_id', 'team_id', 'deleted_at', ...$timestamps],
            'encrypted' => ['name' => 'deals.name', 'lost_reason' => 'deals.lost_reason', 'lost_notes' => 'deals.lost_notes'],
            'json' => ['properties' => 'custom properties of a deal'],
            'amount' => ['amount' => 'deals.amount'],
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
        'odden_mentions' => [
            'structural' => ['id', 'mentionable_type', 'mentionable_id', 'user_id', 'mentioned_by', 'created_at'],
        ],
        'odden_lists' => [
            'structural' => ['id', 'name', 'description', 'entity_type', 'type', 'created_by_id', ...$timestamps],
            'encrypted' => ['criteria' => 'lists.criteria'],
        ],
        'odden_pipeline_stages' => [
            'structural' => ['id', 'pipeline_id', 'name', 'code', 'probability', 'sort_order', 'is_closed_won', 'is_closed_lost', 'rot_after_days', ...$timestamps],
        ],
        'odden_pipelines' => [
            'structural' => ['id', 'name', 'code', 'is_default', 'is_active', 'team_id', ...$timestamps],
        ],
        'odden_properties' => [
            'structural' => ['id', 'entity_type', 'name', 'label', 'type', 'group_name', 'options', 'description', 'is_required', 'is_searchable', 'is_sensitive', 'data_area', 'sort_order', ...$timestamps],
        ],
        'odden_property_history' => [
            'structural' => ['id', 'auditable_type', 'auditable_id', 'property_name', 'user_id', 'source', 'created_at'],
            'encrypted' => ['old_value' => 'property_history.old_value', 'new_value' => 'property_history.new_value'],
        ],
        'odden_proposed_actions' => [
            'structural' => ['id', 'action', 'status', 'requested_by_kind', 'requested_by_user_id', 'via', 'idempotency_key', 'decided_by_user_id', 'decided_at', 'expires_at', ...$timestamps],
            'encrypted' => ['input' => 'proposed_actions.input', 'error' => 'proposed_actions.error'],
        ],
        'odden_quote_items' => [
            'structural' => ['id', 'quote_id', 'sku', 'unit_price', 'quantity', 'discount_percent', 'total_price', 'sort_order', ...$timestamps],
            'encrypted' => ['name' => 'quote_items.name', 'description' => 'quote_items.description'],
        ],
        'odden_quotes' => [
            // terms are the seller's standard wording, shown to the customer; the notes are written about the customer.
            'structural' => ['id', 'deal_id', 'quote_number', 'status', 'subtotal', 'discount_amount', 'tax_amount', 'total_amount', 'currency', 'terms', 'public_token', 'expires_at', 'accepted_at', 'user_id', 'deleted_at', ...$timestamps],
            'encrypted' => ['title' => 'quotes.title', 'notes' => 'quotes.notes', 'internal_notes' => 'quotes.internal_notes', 'signed_by_name' => 'quotes.signed_by_name', 'signed_by_email' => 'quotes.signed_by_email'],
        ],
        'odden_search_index' => [
            // Keyed hashes (HMAC-SHA-256 with a per-tenant key) of normalized values, and the record each belongs to: no value can be read back.
            'structural' => ['field', 'token', 'record_id'],
        ],
        'odden_sales_email_templates' => [
            'structural' => ['id', 'name', 'subject', 'body_html', 'category', 'user_id', 'is_shared', ...$timestamps],
        ],
        'odden_sales_lead_routing_rules' => [
            'structural' => ['id', 'name', 'strategy', 'assigned_user_ids', 'last_assigned_index', 'is_active', 'sort_order', ...$timestamps],
            'encrypted' => ['criteria' => 'lead_routing_rules.criteria'],
        ],
        'odden_sales_meeting_bookings' => [
            'structural' => ['id', 'uid', 'meeting_link_id', 'user_id', 'contact_id', 'activity_id', 'starts_at', 'ends_at', 'timezone', 'cancelled_at', ...$timestamps],
            'encrypted' => ['invitee_name' => 'meeting_bookings.invitee_name', 'invitee_email' => 'meeting_bookings.invitee_email'],
        ],
        'odden_sales_meeting_links' => [
            'structural' => ['id', 'user_id', 'slug', 'title', 'duration_minutes', 'description', 'working_hours', 'is_active', 'buffer_minutes', 'timezone', ...$timestamps],
        ],
        'odden_sales_playbooks' => [
            'structural' => ['id', 'name', 'slug', 'category', 'framework', 'description', 'questions', 'is_active', 'user_id', ...$timestamps],
        ],
        'odden_sales_quotas' => [
            'structural' => ['id', 'user_id', 'pipeline_id', 'period_type', 'period_start', 'period_end', 'target_amount', 'currency', ...$timestamps],
            'amount' => ['target_amount' => 'sales_quotas.target_amount'],
        ],
        'odden_sales_sequence_enrollments' => [
            'structural' => ['id', 'sequence_id', 'contact_id', 'current_step', 'status', 'next_step_due_at', 'enrolled_by_id', 'enrolled_at', ...$timestamps],
        ],
        'odden_sales_sequences' => [
            'structural' => ['id', 'name', 'description', 'steps', 'is_active', 'user_id', ...$timestamps],
        ],
        'odden_stage_automations' => [
            'structural' => ['id', 'stage_id', 'name', 'event_trigger', 'action_type', 'is_active', 'sort_order', ...$timestamps],
            'encrypted' => ['action_payload' => 'stage_automations.action_payload'],
        ],
    ];
}

it('classifies every column of every Odden table, and nothing that does not exist', function (): void {
    $classified = oddenColumnClassification();
    $found = [];

    // MySQL lists the tables of every database on the server, so keep to this connection's own.
    $database = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? DB::getDatabaseName() : null;

    foreach (Schema::getTables() as $table) {
        if (! str_starts_with($table['name'], 'odden_') || ($database !== null && $table['schema'] !== $database)) {
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
            ...array_keys($classified[$table]['json'] ?? []),
            ...array_map(fn (string $column): string => EncryptedAmount::companion($column), array_keys($classified[$table]['amount'] ?? [])),
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

it('keeps every business-data figure behind the EncryptedAmount cast with a text companion column', function (): void {
    $models = [];

    foreach (app(ModelRegistry::class)->models() as $class) {
        $models[(new $class)->getTable()] = new $class;
    }

    foreach (oddenColumnClassification() as $table => $groups) {
        foreach ($groups['amount'] ?? [] as $column => $field) {
            $companion = EncryptedAmount::companion($column);
            $type = collect(Schema::getColumns($table))->firstWhere('name', $companion)['type_name'] ?? null;

            expect($type)->toBeIn(['text', 'longtext', 'mediumtext'], "{$table}.{$companion} must be a text column");

            $cast = $models[$table]->getCasts()[$column] ?? '';

            expect(is_string($cast) && explode(':', $cast, 2)[0] === EncryptedAmount::class)->toBeTrue("{$table}.{$column} needs the EncryptedAmount cast")
                ->and(explode(':', $cast, 2)[1] ?? null)->toBe($field);
            expect(collect(app(BusinessDataColumns::class)->all())->contains(fn (array $entry): bool => $entry['table'] === $table && $entry['column'] === $column && $entry['field'] === $field))->toBeTrue("{$table}.{$column} must be registered for conversion");
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

/*
 * The Identity area (PLAN-encryption-at-rest.md, section 3): who a contact or company is. These columns can only ever be classified
 * as encrypted, and a record saved through the model must keep none of their values in plain text.
 */
function oddenIdentityColumns(): array
{
    return [
        'odden_contacts' => ['first_name', 'last_name', 'email', 'phone', 'job_title', 'linkedin_url', 'timezone'],
        'odden_companies' => ['name', 'domain', 'phone'],
    ];
}

it('classifies every Identity column as encrypted', function (): void {
    foreach (oddenIdentityColumns() as $table => $columns) {
        expect(array_keys(oddenColumnClassification()[$table]['encrypted'] ?? []))->toEqualCanonicalizing(
            array_values(array_unique([...$columns, ...array_intersect(['date_of_birth'], array_keys(oddenColumnClassification()[$table]['encrypted']))])),
            "{$table}: an Identity column is not classified as encrypted",
        );
    }
});

it('stores no Identity value in plain text', function (): void {
    $contact = Contact::factory()->create([
        'first_name' => 'Zebulon', 'last_name' => 'Quillfeather', 'email' => 'zeb.quill@identity-test.example', 'phone' => '+1 555 019 7788',
        'job_title' => 'Chief Wombat', 'linkedin_url' => 'https://linkedin.com/in/zebulon-q', 'timezone' => 'America/Denver',
    ]);
    $company = Company::factory()->create(['name' => 'Wombat Holdings', 'domain' => 'wombat-holdings.example', 'phone' => '+1 555 019 0000']);

    foreach ([['odden_contacts', $contact->id, $contact->getAttributes()], ['odden_companies', $company->id, $company->getAttributes()]] as [$table, $id, $plain]) {
        $row = (array) DB::table($table)->where('id', $id)->first();

        foreach (oddenIdentityColumns()[$table] as $column) {
            expect(app(FieldEncrypter::class)->isEncrypted($row[$column]))->toBeTrue("{$table}.{$column} is stored in plain text");
        }

        expect(implode(' ', array_map('strval', $row)))->not->toContain('Zebulon')->not->toContain('Wombat')->not->toContain('identity-test')->not->toContain('555');
    }

    // And the search index holds only keyed hashes.
    $index = DB::table('odden_search_index')->get();
    expect($index->count())->toBeGreaterThan(10)
        ->and($index->pluck('token')->every(fn (string $token): bool => preg_match('/^[0-9a-f]{64}$/', $token) === 1))->toBeTrue();
});

/*
 * The custom property columns (PLAN-encryption-at-rest.md, section 6): a property the workspace marks sensitive is stored encrypted.
 * Every JSON column of property values is classified, uses the CustomProperties cast, and keeps no sensitive value in plain text.
 */
it('uses the CustomProperties cast on every column classified as json, and on no other', function (): void {
    $withCast = [];

    foreach (app(ModelRegistry::class)->models() as $class) {
        $cast = (new $class)->getCasts()['properties'] ?? null;

        if ($cast === CustomProperties::class) {
            $withCast[(new $class)->getTable()] = 'properties';
        }
    }

    $classified = [];

    foreach (oddenColumnClassification() as $table => $groups) {
        foreach (array_keys($groups['json'] ?? []) as $column) {
            $classified[$table] = $column;
        }
    }

    // A custom object's values are encrypted whole (EncryptedJson), so it is classified as encrypted, not json.
    expect($withCast)->toBe($classified);
});

it('stores no value of a sensitive property in plain text, on any record type that has custom properties', function (): void {
    $secret = 'ZX-4417-SECRET';

    foreach (['contact', 'company', 'deal'] as $entity) {
        PropertyDefinition::query()->create(['entity_type' => $entity, 'name' => 'tax_id', 'label' => 'Tax ID', 'type' => PropertyType::Text, 'is_sensitive' => true]);
    }

    $records = [
        'odden_contacts' => Contact::factory()->create(['properties' => ['tax_id' => $secret, 'nickname' => 'open']]),
        'odden_companies' => Company::factory()->create(['properties' => ['tax_id' => $secret, 'nickname' => 'open']]),
        'odden_deals' => Deal::factory()->create(['properties' => ['tax_id' => $secret, 'nickname' => 'open']]),
    ];

    foreach ($records as $table => $record) {
        $record->update(['properties' => ['tax_id' => $secret.'-2', 'nickname' => 'open']]);
        $row = (array) DB::table($table)->where('id', $record->id)->first();

        expect(implode(' ', array_map('strval', $row)))->not->toContain('ZX-4417')
            ->and(json_decode((string) $row['properties'], true)['nickname'])->toBe('open')
            ->and(app(FieldEncrypter::class)->isEncrypted(json_decode((string) $row['properties'], true)['tax_id']))->toBeTrue("{$table}: a sensitive property is stored in plain text");
    }

    // The history of the change is encrypted too, and so is every other copy the tables hold.
    foreach (DB::table('odden_property_history')->get() as $history) {
        expect(implode(' ', array_map('strval', (array) $history)))->not->toContain('ZX-4417');
    }
});

/*
 * A new copy of customer content should not get in by being filed as "structural". A structural column whose name says it holds an
 * address, a phone number, a name, a message or a payload has to be listed here with the reason it is safe in plain text; anything
 * else must be encrypted (or this list reviewed).
 */
function oddenPlainByDecision(): array
{
    return [
        'odden_contacts.email_status' => 'a verdict (valid, bounced, ...), not an address',
        'odden_contacts.email_status_reason' => 'a provider code for the verdict, not an address',
        'odden_contacts.email_checked_at' => 'a time',
        'odden_sales_email_templates.subject' => "the seller's own template wording, written once for everyone",
        'odden_sales_email_templates.body_html' => "the seller's own template wording, written once for everyone",
        'odden_quotes.terms' => "the seller's standard wording, shown to the customer",
        'odden_sales_meeting_links.description' => 'the host\'s public booking page text',
        'odden_custom_object_definitions.description' => 'the schema description, not a record',
        'odden_properties.description' => 'the property definition, not a record',
        'odden_lists.description' => 'what the list is for, written by the workspace (reviewed: names a segment, not a person)',
        'odden_pipelines.name' => 'configuration',
        'odden_sales_playbooks.description' => "the seller's own playbook wording",
        'odden_sales_sequences.description' => "the seller's own sequence wording",
    ];
}

it('keeps every structural column that looks like personal data on the reviewed list', function (): void {
    $pattern = '/(mail|phone|first_name|last_name|full_name|address|ip_address|user_agent|referer|message|payload|feedback|transcript|recording|signed_by|invitee|comment|body|subject(?!_)|note|criteria|description)/';
    $decided = oddenPlainByDecision();
    $unexplained = [];

    foreach (oddenColumnClassification() as $table => $groups) {
        foreach ($groups['structural'] ?? [] as $column) {
            if (preg_match($pattern, $column) === 1 && ! array_key_exists("{$table}.{$column}", $decided)) {
                $unexplained[] = "{$table}.{$column}";
            }
        }
    }

    expect($unexplained)->toBe([], 'structural columns that look like personal data: encrypt them, or list them in oddenPlainByDecision() with the reason');

    // And the list names nothing that is gone.
    $classified = oddenColumnClassification();

    foreach (array_keys($decided) as $key) {
        [$table, $column] = explode('.', $key, 2);

        expect(in_array($column, $classified[$table]['structural'] ?? [], true))->toBeTrue("{$key} is on the reviewed list but is not a structural column");
    }
});

it('has no pending column: every column is plain on purpose or encrypted', function (): void {
    foreach (oddenColumnClassification() as $table => $groups) {
        expect($groups['pending'] ?? [])->toBe([], "{$table} still has a column pending encryption");
    }
});
