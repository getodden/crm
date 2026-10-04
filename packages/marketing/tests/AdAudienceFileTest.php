<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Marketing\Enums\SubscriptionStatus;
use Odden\Marketing\Events\AdAudienceExported;
use Odden\Marketing\Models\AdAudienceSync;
use Odden\Marketing\Models\EmailSuppression;
use Odden\Marketing\Models\MarketingSubscription;
use Odden\Marketing\Support\AdAudienceFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdAudienceFileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string|null>  $emails
     */
    private function sync(array $emails, string $platform = 'google'): AdAudienceSync
    {
        $list = CrmList::create(['name' => 'High Intent Buyers', 'type' => 'static']);

        foreach ($emails as $i => $email) {
            $list->addMember(Contact::create(['first_name' => "C{$i}", 'email' => $email]));
        }

        return AdAudienceSync::create(['name' => 'Ads: buyers', 'platform' => $platform, 'list_id' => $list->id, 'is_active' => true]);
    }

    private function body(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    public function test_each_platform_gets_the_address_the_way_it_expects_it_before_hashing(): void
    {
        // Google drops the dots before the @ of a gmail.com or googlemail.com address; the others keep them.
        $this->assertSame('johnsmith@gmail.com', AdAudienceFile::normalize('  John.Smith@Gmail.com ', 'google'));
        $this->assertSame('johnsmith@googlemail.com', AdAudienceFile::normalize('john.smith@googlemail.com', 'google'));
        $this->assertSame('john.smith@company.com', AdAudienceFile::normalize('John.Smith@Company.com', 'google'));
        $this->assertSame('john.smith@gmail.com', AdAudienceFile::normalize(' John.Smith@Gmail.com ', 'meta'));
        $this->assertSame('a.b@x.com', AdAudienceFile::normalize("A.B@X.com\t", 'linkedin'));
    }

    public function test_hashes_are_sha256_of_the_normalised_address_in_each_platforms_hex_case(): void
    {
        $this->assertSame(hash('sha256', 'abc@example.com'), AdAudienceFile::hash(' ABC@Example.com ', 'meta'));
        $this->assertSame(hash('sha256', 'abc@example.com'), AdAudienceFile::hash('abc@example.com', 'google'));
        $this->assertSame(strtoupper(hash('sha256', 'abc@example.com')), AdAudienceFile::hash('abc@example.com', 'linkedin'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', AdAudienceFile::hash('abc@example.com', 'meta'));
    }

    public function test_the_file_has_the_platforms_header_and_one_hash_per_person(): void
    {
        $sync = $this->sync(['ann@example.com', 'bob@example.com']);

        $csv = $this->body((new AdAudienceFile($sync, 'google'))->stream());
        $lines = explode("\n", rtrim($csv, "\n"));

        $this->assertSame('Email', $lines[0]);
        $this->assertSame([hash('sha256', 'ann@example.com'), hash('sha256', 'bob@example.com')], array_slice($lines, 1));

        $this->assertStringStartsWith('email', $this->body((new AdAudienceFile($sync, 'meta'))->stream()));
    }

    public function test_people_who_must_not_be_advertised_to_are_left_out_and_counted(): void
    {
        $sync = $this->sync(['keep@example.com', 'gone@example.com', 'bounced@example.com', 'complained@example.com', 'noemail@placeholder.test']);
        Contact::query()->where('email', 'noemail@placeholder.test')->update(['email' => '']);
        MarketingSubscription::unsubscribe('gone@example.com');
        MarketingSubscription::updateOrCreate(['email' => 'bounced@example.com'], ['status' => SubscriptionStatus::Bounced, 'unsubscribed_at' => now()]);
        EmailSuppression::suppress(email: 'Complained@Example.com', reason: 'spam_complaint', source: 'test');

        $file = new AdAudienceFile($sync, 'meta');
        $hashes = iterator_to_array($file->hashes(), false);

        $this->assertSame([hash('sha256', 'keep@example.com')], $hashes);
        $this->assertSame(1, $file->included);
        $this->assertSame(3, $file->suppressed, 'The blank address is skipped, not counted as suppressed');
    }

    public function test_addresses_that_hash_the_same_after_normalising_appear_once(): void
    {
        $sync = $this->sync(['john.smith@gmail.com', 'johnsmith@gmail.com', 'other@example.com']);

        $google = new AdAudienceFile($sync, 'google');
        $meta = new AdAudienceFile($sync, 'meta');

        $this->assertCount(2, iterator_to_array($google->hashes(), false), 'Google treats the two gmail addresses as one person');
        $this->assertCount(3, iterator_to_array($meta->hashes(), false), 'Meta does not');
    }

    public function test_sending_the_file_tells_the_host_who_took_it_and_how_many_it_covered(): void
    {
        $sync = $this->sync(['ann@example.com', 'out@example.com']);
        MarketingSubscription::unsubscribe('out@example.com');
        Event::fake([AdAudienceExported::class]);

        $this->body((new AdAudienceFile($sync, 'linkedin'))->stream());

        Event::assertDispatched(AdAudienceExported::class, fn (AdAudienceExported $event): bool => $event->sync->is($sync) && $event->format === 'linkedin' && $event->included === 1 && $event->suppressed === 1);
    }

    public function test_it_knows_its_name_its_default_format_and_whether_anyone_is_in_it(): void
    {
        $sync = $this->sync(['ann@example.com'], 'linkedin');
        $this->assertSame('linkedin', AdAudienceFile::formatFor($sync));
        $this->assertStringStartsWith('high-intent-buyers-meta-hashed-', (new AdAudienceFile($sync, 'meta'))->filename());
        $this->assertStringEndsWith('.csv', (new AdAudienceFile($sync, 'meta'))->filename());
        $this->assertTrue((new AdAudienceFile($sync, 'meta'))->hasContacts());

        $other = AdAudienceSync::create(['name' => 'TikTok', 'platform' => 'tiktok', 'list_id' => CrmList::create(['name' => 'Empty', 'type' => 'static'])->id, 'is_active' => true]);
        $this->assertSame('meta', AdAudienceFile::formatFor($other), 'An unknown platform gets the plainest format');
        $this->assertFalse((new AdAudienceFile($other, 'meta'))->hasContacts());
    }

    public function test_an_unknown_format_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AdAudienceFile($this->sync(['ann@example.com']), 'tiktok');
    }

    public function test_a_sync_whose_list_is_gone_has_nobody_in_it(): void
    {
        $sync = $this->sync(['ann@example.com']);
        $sync->setRelation('list', null);

        $this->assertFalse((new AdAudienceFile($sync, 'google'))->hasContacts());
        $this->assertSame('ads-buyers-google-hashed-', substr((new AdAudienceFile($sync, 'google'))->filename(), 0, 25), 'The sync\'s name stands in for the list\'s');
    }
}
