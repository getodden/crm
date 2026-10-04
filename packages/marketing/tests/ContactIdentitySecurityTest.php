<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Models\Contact;
use Odden\Marketing\Models\LandingPage;
use Odden\Marketing\Models\MarketingAsset;
use Odden\Marketing\Models\MarketingForm;
use Odden\Marketing\Support\ContactToken;

/**
 * Public endpoints must not let a caller choose which contact they act as.
 */
class ContactIdentitySecurityTest extends TestCase
{
    use RefreshDatabase;

    private MarketingForm $form;

    private Contact $victim;

    protected function setUp(): void
    {
        parent::setUp();

        $this->form = MarketingForm::create([
            'title' => 'Demo Request',
            'slug' => 'demo-request',
            'fields_schema' => [
                ['name' => 'first_name', 'label' => 'First Name', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Work Email', 'type' => 'email', 'required' => true],
                ['name' => 'company', 'label' => 'Company', 'type' => 'text', 'required' => false],
            ],
            'progressive_profiling_enabled' => true,
            'progressive_fields' => [
                ['name' => 'budget', 'label' => 'Annual Budget', 'type' => 'text', 'required' => false],
            ],
        ]);

        $this->victim = Contact::create([
            'first_name' => 'Victoria',
            'email' => 'victoria@example.com',
        ]);
        $this->victim->setProperties(['budget' => '$50k'])->save();
    }

    public function test_hosted_form_does_not_identify_a_contact_by_id_or_email(): void
    {
        $this->get('/forms/demo-request?contact_id='.$this->victim->id)
            ->assertOk()
            ->assertDontSee('Victoria')
            ->assertDontSee('Welcome back');

        $this->get('/forms/demo-request?email=victoria@example.com')
            ->assertOk()
            ->assertDontSee('Victoria')
            ->assertDontSee('Welcome back');
    }

    public function test_hosted_form_identifies_a_contact_from_a_signed_link(): void
    {
        $this->get($this->form->getPublicUrl($this->victim))
            ->assertOk()
            ->assertSee('Welcome back, <strong>Victoria</strong>!', false);
    }

    public function test_hosted_form_rejects_tampered_and_cross_form_tokens(): void
    {
        $other = Contact::create(['first_name' => 'Mallory', 'email' => 'mallory@example.com']);
        [, $signature] = explode('.', ContactToken::make($other, ContactToken::forForm($this->form->id)));

        $this->get('/forms/demo-request?contact='.$this->victim->id.'.'.$signature)
            ->assertOk()
            ->assertDontSee('Victoria');

        $otherForm = MarketingForm::create(['title' => 'Other', 'slug' => 'other', 'fields_schema' => []]);
        $tokenForOtherForm = ContactToken::make($this->victim, ContactToken::forForm($otherForm->id));

        $this->get('/forms/demo-request?contact='.urlencode($tokenForOtherForm))
            ->assertOk()
            ->assertDontSee('Victoria');
    }

    public function test_schema_does_not_reveal_a_contacts_profile_by_id_or_email(): void
    {
        $baseFields = ['first_name', 'email', 'company'];

        $byId = $this->getJson(route('odden.marketing.forms.schema', 'demo-request').'?contact_id='.$this->victim->id);
        $this->assertSame($baseFields, array_column($byId->json('fields'), 'name'));

        $byEmail = $this->getJson(route('odden.marketing.forms.schema', 'demo-request').'?email=victoria@example.com');
        $this->assertSame($baseFields, array_column($byEmail->json('fields'), 'name'));
    }

    public function test_submissions_cannot_attach_to_or_modify_another_contact_by_id(): void
    {
        $payload = [
            'contact_id' => $this->victim->id,
            'first_name' => 'Mallory',
            'email' => 'mallory@example.com',
            'budget' => '$0',
        ];

        $this->post('/forms/demo-request', $payload)->assertOk();
        $this->postJson(route('odden.marketing.forms.api-submit', 'demo-request'), $payload)->assertOk();

        $this->victim->refresh();
        $this->assertSame('$50k', $this->victim->getProperty('budget'));
        $this->assertSame(0, $this->form->submissions()->where('contact_id', $this->victim->id)->count());

        $mallory = Contact::query()->where('email', 'mallory@example.com')->sole();
        $this->assertSame(2, $this->form->submissions()->where('contact_id', $mallory->id)->count());
        $this->assertArrayNotHasKey('contact_id', $this->form->submissions()->first()->form_data);
    }

    public function test_landing_page_submissions_cannot_attach_to_another_contact_by_id(): void
    {
        LandingPage::create([
            'title' => 'Launch',
            'slug' => 'launch',
            'headline' => 'Launch',
            'form_id' => $this->form->id,
            'is_published' => true,
        ]);

        $this->post('/p/launch/submit', [
            'contact_id' => $this->victim->id,
            'first_name' => 'Mallory',
            'email' => 'mallory@example.com',
            'budget' => '$0',
        ])->assertRedirect();

        $this->victim->refresh();
        $this->assertSame('$50k', $this->victim->getProperty('budget'));
        $this->assertSame(0, $this->form->submissions()->where('contact_id', $this->victim->id)->count());
    }

    public function test_signed_submission_updates_the_verified_contact(): void
    {
        $this->post('/forms/demo-request', [
            'contact' => ContactToken::make($this->victim, ContactToken::forForm($this->form->id)),
            'budget' => '$250k',
        ])->assertOk();

        $this->victim->refresh();
        $this->assertSame('$250k', $this->victim->getProperty('budget'));
        $this->assertSame(1, $this->form->submissions()->where('contact_id', $this->victim->id)->count());
    }

    public function test_submissions_matched_by_email_only_fill_empty_properties(): void
    {
        $this->victim->setProperties(['lead_owner' => 'alice'])->save();

        $payload = [
            'first_name' => 'Mallory',
            'email' => 'victoria@example.com',
            'budget' => '$0',
            'lead_owner' => 'mallory',
        ];

        $this->post('/forms/demo-request', $payload)->assertOk();
        $this->postJson(route('odden.marketing.forms.api-submit', 'demo-request'), $payload)->assertOk();

        $this->victim->refresh();
        $this->assertSame('Victoria', $this->victim->first_name);
        $this->assertSame('$50k', $this->victim->getProperty('budget'));
        $this->assertSame('alice', $this->victim->getProperty('lead_owner'));

        // Raw data is still kept on the submission.
        $this->assertSame('$0', $this->form->submissions()->latest('id')->first()->form_data['budget']);
    }

    public function test_unverified_submissions_fill_empty_declared_properties_only(): void
    {
        $this->victim->properties = null;
        $this->victim->save();

        $this->post('/forms/demo-request', [
            'first_name' => 'Victoria',
            'email' => 'victoria@example.com',
            'budget' => '$75k',
            'is_vip' => '1',
        ])->assertOk();

        $this->victim->refresh();
        $this->assertSame('$75k', $this->victim->getProperty('budget'));
        $this->assertNull($this->victim->getProperty('is_vip'));

        $this->post('/forms/demo-request', [
            'first_name' => 'Newbie',
            'email' => 'newbie@example.com',
            'budget' => '$10k',
            'lead_score_override' => '999',
        ])->assertOk();

        $newbie = Contact::query()->where('email', 'newbie@example.com')->sole();
        $this->assertSame('$10k', $newbie->getProperty('budget'));
        $this->assertNull($newbie->getProperty('lead_score_override'));
    }

    public function test_landing_page_submissions_cannot_overwrite_properties_by_email(): void
    {
        LandingPage::create([
            'title' => 'Launch',
            'slug' => 'launch',
            'headline' => 'Launch',
            'form_id' => $this->form->id,
            'is_published' => true,
        ]);

        $this->post('/p/launch/submit', [
            'first_name' => 'Mallory',
            'email' => 'victoria@example.com',
            'budget' => '$0',
            'is_vip' => '1',
        ])->assertRedirect();

        $this->victim->refresh();
        $this->assertSame('$50k', $this->victim->getProperty('budget'));
        $this->assertNull($this->victim->getProperty('is_vip'));
    }

    public function test_signed_submission_cannot_write_undeclared_properties(): void
    {
        $this->post('/forms/demo-request', [
            'contact' => ContactToken::make($this->victim, ContactToken::forForm($this->form->id)),
            'budget' => '$250k',
            'is_vip' => '1',
        ])->assertOk();

        $this->victim->refresh();
        $this->assertSame('$250k', $this->victim->getProperty('budget'));
        $this->assertNull($this->victim->getProperty('is_vip'));
    }

    public function test_an_unverified_submission_cannot_record_sms_consent_or_a_company_for_an_existing_contact(): void
    {
        $this->post('/forms/demo-request', [
            'first_name' => 'Mallory',
            'email' => 'victoria@example.com',
            'company' => 'Mallory Holdings',
            'sms_consent' => '1',
        ])->assertOk();

        $this->victim->refresh();
        $this->assertFalse((bool) $this->victim->sms_consent, 'Typing someone\'s address does not give consent for them');
        $this->assertNull($this->victim->sms_consent_at);
        $this->assertCount(0, $this->victim->companies, 'Nor does it link them to a company the sender chose');
    }

    public function test_a_new_contact_can_record_its_own_consent_and_company(): void
    {
        // Someone new: the sender's own details.
        $this->post('/forms/demo-request', [
            'first_name' => 'Nia',
            'email' => 'nia@example.com',
            'company' => 'Nia Ltd',
            'sms_consent' => '1',
        ])->assertOk();

        $nia = Contact::query()->where('email', 'nia@example.com')->firstOrFail();
        $this->assertTrue((bool) $nia->sms_consent);
        $this->assertCount(1, $nia->companies);
    }

    public function test_asset_downloads_are_attributed_only_with_a_valid_signature(): void
    {
        $asset = MarketingAsset::create([
            'name' => 'Pricing Report',
            'slug' => 'pricing-report',
            'external_url' => 'https://example.com/report.pdf',
        ]);

        $this->get(route('odden.marketing.assets.download', ['slug' => 'pricing-report', 'contact_id' => $this->victim->id]))
            ->assertRedirect('https://example.com/report.pdf');
        $this->get(route('odden.marketing.assets.download', ['slug' => 'pricing-report', 'contact_id' => $this->victim->id, 'signature' => str_repeat('0', 64)]))
            ->assertRedirect('https://example.com/report.pdf');

        $this->assertSame(0, $asset->fresh()->unique_leads_count);

        $this->get($asset->getDownloadUrl($this->victim))->assertRedirect('https://example.com/report.pdf');

        $this->assertSame(1, $asset->fresh()->unique_leads_count);
    }
}
