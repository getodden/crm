<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Models\Contact;
use Odden\Marketing\Models\LandingPage;
use Odden\Marketing\Models\MarketingForm;

class LandingPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_landing_page_renders_and_increments_views(): void
    {
        $form = MarketingForm::create([
            'title' => 'Product Demo Form',
            'slug' => 'product-demo-form',
            'fields_schema' => [
                ['name' => 'first_name', 'label' => 'First Name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Work Email', 'type' => 'email', 'required' => true],
            ],
            'submit_button_text' => 'Get Started',
        ]);

        $landingPage = LandingPage::create([
            'title' => 'Unified CRM Launch',
            'slug' => 'unified-crm-launch',
            'headline' => 'Scale Faster With Unified RevOps',
            'subheadline' => 'The complete platform for high-velocity teams',
            'body_content' => '<p>Experience blazing-fast pipeline velocity and customer satisfaction.</p>',
            'form_id' => $form->id,
            'meta_title' => 'Unified CRM Launch | Odden',
            'is_published' => true,
        ]);

        $response = $this->get('/p/unified-crm-launch');

        $response->assertStatus(200);
        $response->assertSee('Scale Faster With Unified RevOps');
        $response->assertSee('Unified CRM Launch | Odden');
        $response->assertSee('Get Started');

        $landingPage->refresh();
        $this->assertSame(1, $landingPage->views_count);

        // Verify web inbound visit tracking captured
        $this->assertDatabaseHas('odden_marketing_page_views', [
            'path' => '/p/unified-crm-launch',
        ]);
    }

    public function test_landing_page_form_submission_creates_contact_with_sms_consent(): void
    {
        $form = MarketingForm::create([
            'title' => 'Early Access Registration',
            'slug' => 'early-access',
            'fields_schema' => [
                ['name' => 'first_name', 'label' => 'First Name', 'type' => 'text', 'required' => true],
                ['name' => 'last_name', 'label' => 'Last Name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                ['name' => 'phone', 'label' => 'Mobile Phone', 'type' => 'tel', 'required' => false],
            ],
        ]);

        $page = LandingPage::create([
            'title' => 'Early Access 2026',
            'slug' => 'early-access-2026',
            'form_id' => $form->id,
            'is_published' => true,
            'views_count' => 10,
        ]);

        $response = $this->post('/p/early-access-2026/submit', [
            'first_name' => 'Tim',
            'last_name' => 'Berners-Lee',
            'email' => 'timbl@cern.ch',
            'phone' => '+14155552671',
            'sms_consent' => '1',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Contact created with phone and SMS consent
        $contact = Contact::query()->where('email', 'timbl@cern.ch')->first();
        $this->assertNotNull($contact);
        $this->assertSame('+14155552671', $contact->phone);
        $this->assertTrue($contact->sms_consent);
        $this->assertNotNull($contact->sms_consent_at);

        // Landing page submissions and conversion rate
        $page->refresh();
        $this->assertSame(1, $page->submissions_count);
        $this->assertSame(10.0, $page->conversion_rate); // 1 / 10 * 100 = 10%
    }

    public function test_unpublished_landing_page_returns_404(): void
    {
        LandingPage::create([
            'title' => 'Draft Page',
            'slug' => 'draft-preview',
            'is_published' => false,
        ]);

        $response = $this->get('/p/draft-preview');
        $response->assertStatus(404);
    }

    public function test_landing_page_embed_snippet_generates_valid_iframe(): void
    {
        $page = LandingPage::create([
            'title' => 'Embed Test Page',
            'slug' => 'embed-test',
            'is_published' => true,
        ]);

        $snippet = $page->getEmbedSnippet();
        $this->assertStringContainsString('<iframe', $snippet);
        $this->assertStringContainsString('/p/embed-test', $snippet);
    }

    public function test_landing_page_submission_is_validated_against_the_form_fields(): void
    {
        $form = MarketingForm::create([
            'title' => 'Validated Form',
            'slug' => 'validated-form',
            'fields_schema' => [
                ['name' => 'first_name', 'label' => 'First Name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
            ],
        ]);

        $page = LandingPage::create(['title' => 'Validated', 'slug' => 'validated', 'form_id' => $form->id, 'is_published' => true]);

        $this->post('/p/validated/submit', [])->assertSessionHasErrors(['first_name', 'email']);
        $this->post('/p/validated/submit', ['first_name' => 'Tim', 'email' => 'not-an-email'])->assertSessionHasErrors('email');

        $this->assertDatabaseCount('odden_marketing_form_submissions', 0);
        $this->assertSame(0, $page->fresh()->submissions_count);
    }
}
