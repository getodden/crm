<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Odden\MailBuilder\Mail\TemplateMailable;
use Odden\Marketing\Actions\GenerateAiSubjectLinesAction;
use Odden\Marketing\Actions\SuggestSubjectLinesAction;
use Odden\Marketing\Models\MarketingTemplate;

class TemplateAndTransactionalPolishTest extends TestCase
{
    use RefreshDatabase;

    public function test_plain_text_follows_the_html_until_someone_edits_it(): void
    {
        $template = MarketingTemplate::create(['name' => 'Text sync', 'subject' => 'Hi', 'body_html' => '<p>First version</p>']);
        $this->assertStringContainsString('First version', (string) $template->body_text);

        // Never edited by hand: it is regenerated when the HTML changes.
        $template->update(['body_html' => '<p>Second version</p>']);
        $this->assertStringContainsString('Second version', (string) $template->fresh()->body_text);
        $this->assertStringNotContainsString('First version', (string) $template->fresh()->body_text);

        // Edited by hand: it is left alone.
        $template->update(['body_text' => 'My own plain text']);
        $template->update(['body_html' => '<p>Third version</p>']);
        $this->assertSame('My own plain text', $template->fresh()->body_text);
    }

    public function test_plain_text_is_regenerated_for_slot_templates_too(): void
    {
        $slot = fn (string $text): array => [['type' => 'body_text', 'data' => ['content' => "<p>{$text}</p>"]]];

        $template = MarketingTemplate::create(['name' => 'Slot text sync', 'subject' => 'Hi', 'slots' => $slot('Alpha copy')]);
        $this->assertStringContainsString('Alpha copy', (string) $template->body_text);

        $template->update(['slots' => $slot('Beta copy')]);
        $this->assertStringContainsString('Beta copy', (string) $template->fresh()->body_text);
        $this->assertStringNotContainsString('Alpha copy', (string) $template->fresh()->body_text);
    }

    public function test_slot_built_transactional_emails_carry_the_preview_text(): void
    {
        Mail::fake();

        $template = MarketingTemplate::create([
            'name' => 'Preview text',
            'subject' => 'Welcome',
            'preview_text' => 'Everything you need to get started',
            'slots' => [['type' => 'body_text', 'data' => ['content' => '<p>Hello!</p>']]],
        ]);
        $url = route('odden.marketing.templates.send', ['template' => $template->id]);

        $this->postJson($url, ['to' => 'a@example.com'])->assertOk();
        $this->postJson($url, ['to' => 'b@example.com', 'preview_text' => 'A one-off preheader'])->assertOk();

        Mail::assertQueued(TemplateMailable::class, fn (TemplateMailable $mail): bool => $mail->hasTo('a@example.com') && str_contains($mail->compiledHtml, 'Everything you need to get started'));
        Mail::assertQueued(TemplateMailable::class, fn (TemplateMailable $mail): bool => $mail->hasTo('b@example.com') && str_contains($mail->compiledHtml, 'A one-off preheader') && ! str_contains($mail->compiledHtml, 'Everything you need to get started'));
    }

    public function test_the_transactional_api_reports_a_webhook_that_was_not_sent(): void
    {
        Mail::fake();
        Http::fake(['https://hooks.example.com/*' => Http::response('ok', 200)]);
        config(['odden-marketing.webhooks.secret' => null]);

        $template = MarketingTemplate::create(['name' => 'Webhook status', 'subject' => 'Hi', 'body_html' => '<p>Hi</p>']);
        $url = route('odden.marketing.templates.send', ['template' => $template->id]);

        // No signing secret: the email is queued, but the webhook is skipped and the response says so.
        $this->postJson($url, ['to' => 'a@example.com', 'webhook_url' => 'https://hooks.example.com/in'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('webhook_dispatched', false)
            ->assertJsonStructure(['webhook_warning']);
        Http::assertNothingSent();

        $this->postJson($url, ['to' => 'b@example.com', 'webhook_url' => 'https://hooks.example.com/in', 'webhook_secret' => 'whsec_1'])
            ->assertOk()
            ->assertJsonPath('webhook_dispatched', true)
            ->assertJsonMissingPath('webhook_warning');

        // Without a webhook_url there is nothing to report.
        $this->postJson($url, ['to' => 'c@example.com'])->assertOk()->assertJsonMissingPath('webhook_dispatched');

        $batch = route('odden.marketing.templates.send-batch', ['template' => $template->id]);
        $this->postJson($batch, ['recipients' => [['to' => 'd@example.com']], 'webhook_url' => 'https://hooks.example.com/in'])
            ->assertOk()
            ->assertJsonPath('webhook_dispatched', false);
    }

    public function test_the_old_subject_line_action_name_still_works(): void
    {
        $this->assertSame(
            (new SuggestSubjectLinesAction)->execute('Spring Sale', 'bold'),
            (new GenerateAiSubjectLinesAction)->execute('Spring Sale', 'bold')
        );
    }
}
