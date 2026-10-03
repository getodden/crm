<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Odden\MailBuilder\MailBuilder;
use Odden\MailBuilder\MergeTags\MergeTagRegistry;

class MergeTagRegistrationTest extends TestCase
{
    public function test_marketing_registers_crm_merge_tag_groups_before_built_ins(): void
    {
        $registry = app(MergeTagRegistry::class);

        $this->assertSame(['Contact', 'Company', 'Event', 'Sender / Owner', 'System & Legal'], array_keys($registry->all()));
        $this->assertArrayHasKey('{{contact.first_name}}', $registry->flattened());
        $this->assertArrayHasKey('{{company.name}}', $registry->flattened());
    }

    public function test_crm_sample_context_drives_template_previews(): void
    {
        $preview = MailBuilder::interpolate('Welcome to {{company.name}}, {{contact.first_name}}!');

        $this->assertSame('Welcome to Acme Corporation, Alex!', $preview);
    }
}
