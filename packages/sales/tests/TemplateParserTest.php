<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\SalesEmailTemplate;
use Odden\Sales\Services\TemplateParser;
use Odden\Sales\Tests\Fixtures\User;

class TemplateParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_parse_contact_deal_and_user_merge_tags(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Sarah',
            'last_name' => 'Connor',
            'job_title' => 'VP of Security',
            'lead_status' => LeadStatus::Open,
        ]);

        $company = Company::factory()->create([
            'name' => 'Cyberdyne Systems',
            'industry' => 'Defense Systems',
        ]);
        $contact->companies()->attach($company->id, ['parent_type' => $contact->getMorphClass(), 'child_type' => $company->getMorphClass()]);

        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages->first()->id,
            'name' => 'Enterprise Defense System',
            'amount' => 75000.00,
        ]);

        $user = User::factory()->create([
            'name' => 'John Connor',
            'email' => 'john@resistance.org',
        ]);

        $parser = new TemplateParser;
        $context = $parser->buildContext($contact, $deal, $user);

        $template = 'Hi {{ contact.first_name }}, regarding {{ deal.name }} (${{ deal.amount }}) at {{ company.name }}, best regards {{ sender.name }}.';
        $rendered = $parser->parse($template, $context);

        $this->assertSame('Hi Sarah, regarding Enterprise Defense System ($75000.00) at Cyberdyne Systems, best regards John Connor.', $rendered);
    }

    public function test_parse_html_escapes_merge_values_while_parse_keeps_plain_text(): void
    {
        $parser = new TemplateParser;
        $context = ['contact' => ['first_name' => '<script>alert("x")</script> & Co']];

        $this->assertSame(
            '<p>Hi &lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; Co</p>',
            $parser->parseHtml('<p>Hi {{ contact.first_name }}</p>', $context)
        );
        $this->assertSame(
            'Hi <script>alert("x")</script> & Co',
            $parser->parse('Hi {{ contact.first_name }}', $context)
        );
    }

    public function test_render_with_context_escapes_body_html_but_not_subject(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => '<img src=x onerror=alert(1)>',
            'last_name' => 'O\'Brien & Sons',
            'lead_status' => LeadStatus::Open,
        ]);

        $template = SalesEmailTemplate::create([
            'name' => 'Intro',
            'subject' => 'Hello {{ contact.full_name }}',
            'body_html' => '<p>Hi {{ contact.first_name }} {{ contact.last_name }}</p>',
            'category' => 'prospecting',
            'is_shared' => true,
        ]);

        $rendered = $template->renderWithContext($contact);

        $this->assertSame('Hello <img src=x onerror=alert(1)> O\'Brien & Sons', $rendered['subject']);
        $this->assertSame('<p>Hi &lt;img src=x onerror=alert(1)&gt; O&#039;Brien &amp; Sons</p>', $rendered['body_html']);
    }

    public function test_deal_formatted_amount_uses_the_deal_currency_instead_of_a_hardcoded_dollar_sign(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages->first()->id,
            'amount' => 1234.50,
            'currency' => 'EUR',
        ]);

        $context = (new TemplateParser)->buildContext(null, $deal);

        $this->assertStringNotContainsString('$', $context['deal']['formatted_amount']);
        $this->assertStringContainsString('1,234.50', $context['deal']['formatted_amount']);
        $this->assertTrue(
            str_contains($context['deal']['formatted_amount'], 'EUR') || str_contains($context['deal']['formatted_amount'], '€'),
            'formatted_amount must reflect the deal currency',
        );
    }
}
