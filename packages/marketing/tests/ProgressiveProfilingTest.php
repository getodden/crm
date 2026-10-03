<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Models\Contact;
use Odden\Marketing\Models\MarketingForm;
use Odden\Marketing\Support\ContactToken;

class ProgressiveProfilingTest extends TestCase
{
    use RefreshDatabase;

    public function test_progressive_profiling_and_smart_forms(): void
    {
        /** @var MarketingForm $form */
        $form = MarketingForm::create([
            'title' => 'Enterprise Demo Request',
            'slug' => 'enterprise-demo',
            'fields_schema' => [
                ['name' => 'first_name', 'label' => 'First Name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Work Email', 'type' => 'email', 'required' => true],
                ['name' => 'company', 'label' => 'Company Name', 'type' => 'text', 'required' => false],
            ],
            'progressive_profiling_enabled' => true,
            'progressive_fields' => [
                ['name' => 'budget', 'label' => 'Annual Budget', 'type' => 'text', 'required' => true],
                ['name' => 'timeline', 'label' => 'Purchase Timeline', 'type' => 'select', 'options' => ['Immediate', '3-6 months'], 'required' => false],
                ['name' => 'crm_replaced', 'label' => 'Current CRM', 'type' => 'text', 'required' => false],
            ],
        ]);

        // 1. Unknown visitor: resolves base fields
        $fieldsForNewVisitor = $form->resolveFieldsForContact(null);
        $this->assertCount(3, $fieldsForNewVisitor);
        $this->assertSame('first_name', $fieldsForNewVisitor[0]['name']);
        $this->assertSame('email', $fieldsForNewVisitor[1]['name']);

        // 2. Known returning contact with first_name and email already captured
        $contact = Contact::create([
            'first_name' => 'Sarah',
            'last_name' => 'Connor',
            'email' => 'sarah@skynet.test',
        ]);

        $resolvedFields = $form->resolveFieldsForContact($contact);
        // Known fields (first_name, email) are swapped with progressive questions (budget, timeline)
        $this->assertCount(3, $resolvedFields);
        $this->assertSame('budget', $resolvedFields[0]['name']);
        $this->assertTrue($resolvedFields[0]['is_progressive']);
        $this->assertSame('timeline', $resolvedFields[1]['name']);
        $this->assertTrue($resolvedFields[1]['is_progressive']);
        $this->assertSame('company', $resolvedFields[2]['name']);

        // 3. Render the contact's signed personalized link
        $response = $this->get($form->getPublicUrl($contact));
        $response->assertOk();
        $response->assertSee('Welcome back, <strong>Sarah</strong>!', false);
        $response->assertSee('Annual Budget');
        $response->assertSee('Purchase Timeline');
        $response->assertSee('Smart Question');

        // 4. Submit progressive form data
        $postResponse = $this->post('/forms/enterprise-demo', [
            'contact' => ContactToken::make($contact, ContactToken::forForm($form->id)),
            'budget' => '$100,000+',
            'timeline' => 'Immediate',
            'crm_replaced' => 'Salesforce',
        ]);

        $postResponse->assertOk();

        // Verify contact's profile was progressively enriched
        $contact->refresh();
        $this->assertSame('$100,000+', $contact->getProperty('budget'));
        $this->assertSame('Immediate', $contact->getProperty('timeline'));
        $this->assertSame('Salesforce', $contact->getProperty('crm_replaced'));

        // Verify form submission recorded
        $this->assertDatabaseHas('odden_marketing_form_submissions', [
            'form_id' => $form->id,
            'contact_id' => $contact->id,
        ]);
    }

    public function test_returning_contact_without_a_signed_link_is_validated_against_the_fields_they_were_shown(): void
    {
        $form = MarketingForm::create([
            'title' => 'Enterprise Demo Request',
            'slug' => 'enterprise-demo-unsigned',
            'fields_schema' => [
                ['name' => 'first_name', 'label' => 'First Name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Work Email', 'type' => 'email', 'required' => true],
            ],
            'progressive_profiling_enabled' => true,
            'progressive_fields' => [
                ['name' => 'budget', 'label' => 'Annual Budget', 'type' => 'text', 'required' => true],
            ],
        ]);

        Contact::create(['first_name' => 'Sarah', 'last_name' => 'Connor', 'email' => 'sarah@skynet.test']);

        // No signed link: the visitor was shown the base form, so the progressive budget is not required.
        $this->post('/forms/enterprise-demo-unsigned', [
            'first_name' => 'Sarah',
            'email' => 'sarah@skynet.test',
        ])->assertOk();
    }
}
