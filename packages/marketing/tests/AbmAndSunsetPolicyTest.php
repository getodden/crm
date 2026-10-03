<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\CalculateCompanyIntentScoreAction;
use Odden\Marketing\Actions\CheckFatiguePolicyAction;
use Odden\Marketing\Actions\DetectUnengagedContactsAction;
use Odden\Marketing\Actions\ExecuteSunsetPolicyAction;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;

class AbmAndSunsetPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_abm_company_intent_scoring_and_surge_detection(): void
    {
        $company = Company::create([
            'name' => 'Stripe Inc',
            'domain' => 'stripe.com',
            'account_tier' => 'tier_1', // Strategic Enterprise (+50 bonus)
        ]);

        // Create 2 contacts from Stripe
        $cto = Contact::create([
            'first_name' => 'Patrick',
            'last_name' => 'Collison',
            'email' => 'patrick@stripe.com',
            'lead_score' => 60,
            'last_contacted_at' => now()->subDays(2),
        ]);

        $director = Contact::create([
            'first_name' => 'Claire',
            'last_name' => 'Hughes',
            'email' => 'claire@stripe.com',
            'lead_score' => 40,
            'last_contacted_at' => now()->subDays(5),
        ]);

        $cto->associateWith($company, 'primary');
        $director->associateWith($company, 'primary');

        $action = new CalculateCompanyIntentScoreAction;
        $updated = $action->execute($company);

        // Score: 60 (CTO) + 40 (Director) + 50 (Tier 1 bonus) = 150 pts
        $this->assertSame(150, $updated->intent_score);
        $this->assertSame(2, $updated->buying_committee_size);
        $this->assertTrue($updated->intent_surge);
        $this->assertTrue($updated->isTargetAccount());
        $this->assertTrue($updated->isSurging());

        // Verify automated task logged for account owner
        $this->assertDatabaseHas('odden_activities', [
            'subject_id' => $company->id,
            'title' => 'ABM Intent Surge: Stripe Inc',
        ]);
    }

    public function test_sunset_policy_identifies_unengaged_contacts_and_suppresses_them(): void
    {
        // 1. Contact who opened a recent email, so they are engaged
        $activeContact = Contact::create([
            'first_name' => 'Active',
            'last_name' => 'User',
            'email' => 'active@example.com',
            'last_marketing_email_sent_at' => now()->subDays(3),
            'is_unengaged' => false,
        ]);
        $this->sendCampaignEmails($activeContact, [100, 60, 3], openedDaysAgo: 5);

        // 2. Dormant contact: mailed for months, never opens or clicks
        $dormantContact = Contact::create([
            'first_name' => 'Dormant',
            'last_name' => 'Lead',
            'email' => 'dormant@example.com',
            'last_marketing_email_sent_at' => now()->subDays(3),
            'is_unengaged' => false,
        ]);
        $this->sendCampaignEmails($dormantContact, [100, 60, 3]);

        $detector = new DetectUnengagedContactsAction;
        $unengaged = $detector->execute(daysInactive: 90);

        $this->assertCount(1, $unengaged);
        $this->assertSame($dormantContact->id, $unengaged->first()?->id);

        $dormantContact->refresh();
        $this->assertTrue($dormantContact->is_unengaged);
        $this->assertSame('flagged', $dormantContact->sunset_stage);

        $activeContact->refresh();
        $this->assertFalse($activeContact->is_unengaged);

        // 3. Execute sunset progression: flagged -> reengagement_sent
        $executor = new ExecuteSunsetPolicyAction;
        $executor->execute($dormantContact);

        $dormantContact->refresh();
        $this->assertSame('reengagement_sent', $dormantContact->sunset_stage);
        $this->assertDatabaseHas('odden_activities', [
            'subject_id' => $dormantContact->id,
            'title' => 'Sunset Policy: Re-engagement Step Triggered',
        ]);

        // 4. Execute final suppression: reengagement_sent -> suppressed
        $executor->execute($dormantContact);

        $dormantContact->refresh();
        $this->assertSame('suppressed', $dormantContact->sunset_stage);
        $this->assertDatabaseHas('odden_activities', [
            'subject_id' => $dormantContact->id,
            'title' => 'Sunset Policy: Contact Suppressed',
        ]);

        // 5. Verify CheckFatiguePolicyAction blocks suppressed contact from campaign broadcasts
        $fatigueChecker = new CheckFatiguePolicyAction;
        $fatigueResult = $fatigueChecker->execute($dormantContact);

        $this->assertFalse($fatigueResult['can_send']);
        $this->assertSame('Contact suppressed under deliverability sunset policy', $fatigueResult['reason']);
    }

    public function test_sunset_flags_mailed_but_never_engaged_contacts_and_ignores_contacts_no_longer_mailed(): void
    {
        $mailedNeverOpens = Contact::create([
            'first_name' => 'Mailed',
            'last_name' => 'Ghost',
            'email' => 'ghost@example.com',
            'last_marketing_email_sent_at' => now()->subDays(3),
            'is_unengaged' => false,
        ]);
        $stoppedMailing = Contact::create([
            'first_name' => 'Stopped',
            'last_name' => 'Mailing',
            'email' => 'stopped@example.com',
            'last_marketing_email_sent_at' => now()->subDays(120),
            'is_unengaged' => false,
        ]);

        foreach ([100, 60, 3] as $i => $daysAgo) {
            $campaign = Campaign::create([
                'name' => 'Newsletter '.$i,
                'subject' => 'Newsletter '.$i,
                'sender_name' => 'Odden',
                'sender_email' => 'news@odden.test',
            ]);
            CampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'contact_id' => $mailedNeverOpens->id,
                'email' => $mailedNeverOpens->email,
                'tracking_token' => 'tok_ghost_'.$i,
                'unsubscribe_token' => 'unsub_ghost_'.$i,
                'sent_at' => now()->subDays($daysAgo),
            ]);
        }

        $flagged = (new DetectUnengagedContactsAction)->execute(daysInactive: 90);

        $this->assertSame([$mailedNeverOpens->id], $flagged->pluck('id')->all());
        $this->assertTrue($mailedNeverOpens->refresh()->is_unengaged);
        $this->assertFalse($stoppedMailing->refresh()->is_unengaged);
    }

    public function test_sunset_reengagement_stage_sends_an_email(): void
    {
        Mail::fake();

        $contact = Contact::create([
            'first_name' => 'Quiet',
            'last_name' => 'Subscriber',
            'email' => 'quiet@example.com',
            'is_unengaged' => true,
            'sunset_stage' => 'flagged',
        ]);

        (new ExecuteSunsetPolicyAction)->execute($contact);

        $this->assertSame('reengagement_sent', $contact->refresh()->sunset_stage);
        $this->assertCount(
            1,
            Mail::sent(Mailable::class)->merge(Mail::queued(Mailable::class)),
            'Expected a re-engagement email to be sent to the contact.'
        );
    }

    /**
     * Record campaign emails sent to a contact, optionally with an open.
     *
     * @param  list<int>  $sentDaysAgo
     */
    private function sendCampaignEmails(Contact $contact, array $sentDaysAgo, ?int $openedDaysAgo = null): void
    {
        foreach ($sentDaysAgo as $i => $daysAgo) {
            $campaign = Campaign::create([
                'name' => "Helper {$contact->id}-{$i}",
                'subject' => 'Newsletter',
                'sender_name' => 'Odden',
                'sender_email' => 'news@odden.test',
            ]);

            CampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'email' => $contact->email,
                'tracking_token' => "tok_{$contact->id}_{$i}",
                'unsubscribe_token' => "unsub_{$contact->id}_{$i}",
                'sent_at' => now()->subDays($daysAgo),
                'opened_at' => $openedDaysAgo !== null && $i === count($sentDaysAgo) - 1 ? now()->subDays($openedDaysAgo) : null,
            ]);
        }
    }
}
