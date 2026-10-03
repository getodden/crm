<?php

declare(strict_types=1);

use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\WorkflowStepType;
use Odden\Marketing\Enums\WorkflowTriggerType;
use Odden\Marketing\Models\MarketingWorkflow;

test('lead webhook works without a source segment and falls back to the payload source', function () {
    $this->postJson('/api/marketing/leads/webhook', [
        'email' => 'no-segment@example.com',
        'source' => 'typeform',
    ])->assertOk()->assertJsonPath('success', true)->assertJsonPath('is_new', true);

    $contact = Contact::query()->where('email', 'no-segment@example.com')->firstOrFail();
    expect($contact->getProperty('lead_source'))->toBe('typeform');
});

test('lead webhook without a source segment or payload source defaults to webhook', function () {
    $this->postJson('/api/marketing/leads/webhook', ['email' => 'default-source@example.com'])->assertOk();

    $contact = Contact::query()->where('email', 'default-source@example.com')->firstOrFail();
    expect($contact->getProperty('lead_source'))->toBe('webhook');
});

test('lead webhook source segment takes precedence over the payload source', function () {
    $this->postJson('/api/marketing/leads/webhook/zapier', [
        'email' => 'with-segment@example.com',
        'source' => 'ignored',
    ])->assertOk();

    $contact = Contact::query()->where('email', 'with-segment@example.com')->firstOrFail();
    expect($contact->getProperty('lead_source'))->toBe('zapier');
});

test('workflow enrollment webhook persists submitted properties on new and existing contacts', function () {
    $workflow = MarketingWorkflow::create([
        'name' => 'Trial Onboarding',
        'is_active' => true,
        'trigger_type' => WorkflowTriggerType::InboundWebhook,
    ]);
    $workflow->steps()->create([
        'step_number' => 1,
        'type' => WorkflowStepType::UpdateContact,
        'config' => ['field' => 'lead_status', 'value' => 'connected'],
    ]);

    $this->postJson("/api/marketing/workflows/{$workflow->id}/enroll", [
        'email' => 'trial@startup.io',
        'properties' => ['plan_tier' => 'scale', 'seats' => 12, 'nested' => ['ignored' => true]],
    ])->assertCreated();

    $contact = Contact::query()->where('email', 'trial@startup.io')->firstOrFail();
    expect($contact->getProperty('plan_tier'))->toBe('scale')
        ->and($contact->getProperty('seats'))->toBe(12)
        ->and($contact->getProperty('nested'))->toBeNull();

    $existing = Contact::create(['email' => 'existing@startup.io', 'properties' => ['region' => 'emea']]);

    $this->postJson("/api/marketing/workflows/{$workflow->id}/enroll", [
        'email' => 'existing@startup.io',
        'properties' => ['plan_tier' => 'growth'],
    ])->assertCreated();

    $existing->refresh();
    expect($existing->getProperty('plan_tier'))->toBe('growth')
        ->and($existing->getProperty('region'))->toBe('emea');
});

test('external leads skip form-specific workflows and report only real enrollments', function () {
    $makeWorkflow = function (string $name, array $triggerConfig, bool $withStep = true): MarketingWorkflow {
        $workflow = MarketingWorkflow::create([
            'name' => $name,
            'is_active' => true,
            'trigger_type' => WorkflowTriggerType::FormSubmitted,
            'trigger_config' => $triggerConfig,
        ]);

        if ($withStep) {
            $workflow->steps()->create([
                'step_number' => 1,
                // A delay keeps the enrollment active, so a repeat lead is already in the workflow.
                'type' => WorkflowStepType::Delay,
                'config' => ['delay_minutes' => 60],
            ]);
            $workflow->steps()->create([
                'step_number' => 2,
                'type' => WorkflowStepType::UpdateContact,
                'config' => ['field' => 'lead_status', 'value' => 'connected'],
            ]);
        }

        return $workflow;
    };

    $anyForm = $makeWorkflow('Any form', []);
    $oneForm = $makeWorkflow('Demo form only', ['form_id' => 42]);
    $makeWorkflow('No steps', [], withStep: false);

    $first = $this->postJson('/api/marketing/leads/webhook/zapier', ['email' => 'lead@example.com'])->assertOk();
    $first->assertJsonPath('enrolled_workflows', 1);

    expect($anyForm->fresh()->enrollments_count)->toBe(1)
        ->and($oneForm->fresh()->enrollments_count)->toBe(0);

    // The same lead again is already in the workflow, so nothing new is reported.
    $this->postJson('/api/marketing/leads/webhook/zapier', ['email' => 'lead@example.com'])
        ->assertOk()
        ->assertJsonPath('enrolled_workflows', 0);
});
