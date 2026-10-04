<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Contracts\TenantContext;
use Odden\Filament\Resources\ContactResource;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Marketing\Models\MarketingTemplate;
use Odden\Marketing\Support\EmailPreviewFrame;

class EmailPreviewSandboxTest extends TestCase
{
    use RefreshDatabase;

    private const string PAYLOAD = '<img src="x" onerror="alert(document.cookie)"><script>alert(1)</script><p>Hello</p>';

    private function parse(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($dom);
    }

    public function test_the_preview_draws_template_html_in_a_script_less_sandboxed_frame(): void
    {
        $template = MarketingTemplate::create(['name' => 'T', 'subject' => 'S', 'body_html' => self::PAYLOAD]);

        $page = view('odden-marketing::template-preview', ['renderedHtml' => self::PAYLOAD, 'template' => $template])->render();
        $xpath = $this->parse($page);

        // Nothing from the template is part of the admin's page itself.
        $this->assertSame(0, $xpath->query('//img[@onerror] | //script[contains(., "alert(1)")]')->length);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page);

        $frames = $xpath->query('//iframe');
        $this->assertGreaterThanOrEqual(2, $frames->length);

        foreach ($frames as $frame) {
            $this->assertTrue($frame->hasAttribute('sandbox'));
            $this->assertSame('', $frame->getAttribute('sandbox'), 'an empty sandbox allows nothing: no scripts, same-origin, forms or popups');
            $this->assertStringContainsString('Hello', $frame->getAttribute('srcdoc'));
            $this->assertStringContainsString('script-src \'none\'', $frame->getAttribute('srcdoc'));
        }
    }

    public function test_the_inline_editor_preview_is_sandboxed_too(): void
    {
        $template = MarketingTemplate::create(['name' => 'T', 'subject' => 'S', 'body_html' => self::PAYLOAD]);

        $page = view('odden-marketing::template-inline-preview', [
            'getRecord' => fn () => $template,
            'get' => fn () => null,
        ])->render();
        $xpath = $this->parse($page);

        $this->assertSame(0, $xpath->query('//img[@onerror]')->length);
        $this->assertGreaterThanOrEqual(2, $xpath->query('//iframe[@sandbox=""]')->length);
    }

    public function test_dark_simulation_is_part_of_the_frame_document(): void
    {
        $light = EmailPreviewFrame::document('<p>x</p>');
        $dark = EmailPreviewFrame::document('<p>x</p>', dark: true);

        $this->assertStringNotContainsString('#0f172a !important', $light);
        $this->assertStringContainsString('#0f172a !important', $dark);
    }

    public function test_the_contact_owner_dropdown_goes_through_the_tenant_context(): void
    {
        $mine = User::factory()->create(['name' => 'Visible Person']);
        User::factory()->create(['name' => 'Hidden Person']);

        // A multi-tenant host narrows users to the active tenant's members.
        $this->app->instance(TenantContext::class, new class($mine->id) implements TenantContext
        {
            public function __construct(private int $userId) {}

            public function id(): int
            {
                return 1;
            }

            public function scopeUsers(Builder $users): Builder
            {
                return $users->whereKey($this->userId);
            }
        });

        $this->actingAs($mine)->get(ContactResource::getUrl('create'))
            ->assertOk()
            ->assertSee('Visible Person')
            ->assertDontSee('Hidden Person');
    }

    public function test_the_revision_history_view_names_who_made_each_revision(): void
    {
        $author = User::factory()->create(['name' => 'Rae Reviser']);
        $template = MarketingTemplate::create(['name' => 'T', 'subject' => 'S', 'body_html' => '<p>x</p>']);
        $template->createRevision('First draft', $author->id);

        $page = view('odden-marketing::template-analytics-history', ['getRecord' => fn () => $template])->render();

        $this->assertStringContainsString('by Rae Reviser', $page);
    }
}
