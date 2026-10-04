<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Filament\Resources\AdAudienceSyncResource\Pages\ListAdAudienceSyncs;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Marketing\Models\AdAudienceSync;
use Odden\Marketing\Models\MarketingSubscription;
use Odden\Marketing\Support\AdAudienceFile;

class AdAudienceFileDownloadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $emails
     */
    private function sync(array $emails, string $platform = 'google'): AdAudienceSync
    {
        $list = CrmList::create(['name' => 'Buyers', 'type' => 'static']);

        foreach ($emails as $i => $email) {
            $list->addMember(Contact::create(['first_name' => "C{$i}", 'email' => $email]));
        }

        return AdAudienceSync::create(['name' => 'Ads: buyers', 'platform' => $platform, 'list_id' => $list->id, 'is_active' => true]);
    }

    public function test_download_hashed_file_gives_the_chosen_platforms_csv_without_the_unsubscribed(): void
    {
        $sync = $this->sync(['ann@example.com', 'bob@example.com', 'out@example.com']);
        MarketingSubscription::unsubscribe('out@example.com');
        $name = (new AdAudienceFile($sync, 'meta'))->filename();

        Livewire::actingAs(User::factory()->create())
            ->test(ListAdAudienceSyncs::class)
            ->callTableAction('downloadFile', $sync, ['format' => 'meta'])
            ->assertFileDownloaded($name, "email\n".hash('sha256', 'ann@example.com')."\n".hash('sha256', 'bob@example.com')."\n");
    }

    public function test_the_format_defaults_to_the_syncs_own_platform(): void
    {
        $sync = $this->sync(['ann@example.com'], 'linkedin');

        Livewire::actingAs(User::factory()->create())
            ->test(ListAdAudienceSyncs::class)
            ->mountTableAction('downloadFile', $sync)
            ->assertTableActionDataSet(['format' => 'linkedin']);
    }

    public function test_an_empty_list_says_so_instead_of_giving_a_file_with_only_a_header(): void
    {
        $sync = $this->sync([]);

        Livewire::actingAs(User::factory()->create())
            ->test(ListAdAudienceSyncs::class)
            ->callTableAction('downloadFile', $sync, ['format' => 'google'])
            ->assertNotified(Notification::make()->title('Nothing to download')->body('The source list has no contacts with an email address.')->warning())
            ->assertNoFileDownloaded();
    }

    public function test_someone_who_may_not_edit_the_sync_cannot_download_its_file(): void
    {
        $sync = $this->sync(['ann@example.com']);
        $this->denyAbilities([AdAudienceSync::class], ['update']);

        Livewire::actingAs(User::factory()->create())
            ->test(ListAdAudienceSyncs::class)
            ->assertTableActionHidden('downloadFile', $sync);
    }
}
