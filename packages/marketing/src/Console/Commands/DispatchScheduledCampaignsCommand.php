<?php

declare(strict_types=1);

namespace Odden\Marketing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\DeliverCampaignMessageAction;
use Odden\Marketing\Actions\DispatchCampaignAction;
use Odden\Marketing\Enums\CampaignStatus;
use Odden\Marketing\Enums\RecipientStatus;
use Odden\Marketing\Exceptions\CampaignHasNoAudienceException;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;

class DispatchScheduledCampaignsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'marketing:dispatch-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch matured scheduled campaigns and sweep pending recipient timezone waves';

    /**
     * Execute the console command.
     */
    public function handle(DispatchCampaignAction $dispatcher, DeliverCampaignMessageAction $delivery): int
    {
        $exitCode = self::SUCCESS;

        // 1. Dispatch due scheduled campaigns
        /** @var Collection<int, Campaign> $dueCampaigns */
        $dueCampaigns = Campaign::query()
            ->where('status', CampaignStatus::Scheduled->value)
            ->where('scheduled_at', '<=', now())
            ->get();

        $dispatchedCount = 0;
        foreach ($dueCampaigns as $campaign) {
            try {
                $results = $dispatcher->execute($campaign);
            } catch (CampaignHasNoAudienceException $e) {
                // Left scheduled, so it goes out once a list is assigned.
                $this->error($e->getMessage());
                $exitCode = self::FAILURE;

                continue;
            }

            $this->info("Dispatched scheduled campaign [{$campaign->name}]: queued {$results['delivered_count']} message(s).");
            $dispatchedCount++;
        }

        // 2. Sweep campaigns with pending timezone / STO waves
        /** @var Collection<int, Campaign> $sendingCampaigns */
        $sendingCampaigns = Campaign::query()
            ->where('status', CampaignStatus::Sending->value)
            ->where(function ($q): void {
                $q->where('send_in_recipient_timezone', true)
                    ->orWhere('send_by_timezone', true)
                    ->orWhere('use_sto', true);
            })
            ->get();

        $timezoneDelivered = 0;
        foreach ($sendingCampaigns as $campaign) {
            /** @var Collection<int, CampaignRecipient> $pendingRecipients */
            $pendingQuery = $campaign->recipients()
                ->where('status', RecipientStatus::Pending->value)
                ->with('contact');

            // Before an A/B winner is chosen, only the test sample (recipients with a variant) is
            // released; the staged rest waits for marketing:evaluate-ab-tests.
            if ($campaign->is_ab_test && $campaign->ab_winner_variant === null) {
                $pendingQuery->whereNotNull('variant');
            }

            $pendingRecipients = $pendingQuery->get();

            $batchDelivered = 0;
            $modeLabel = $campaign->use_sto ? 'Send Time Optimization' : 'Local Timezone';
            foreach ($pendingRecipients as $recipient) {
                /** @var Contact|null $contact */
                $contact = $recipient->contact;
                $targetTime = $recipient->scheduled_send_at ?? $campaign->calculateScheduledTimeForContact($contact);

                // If local window has arrived (or is now past in their timezone)
                if ($targetTime->isPast() || now()->diffInMinutes($targetTime) <= 5) {
                    $outcome = $delivery->execute($campaign, $recipient, activityTitle: "Marketing Campaign ({$modeLabel}): {$campaign->name}");

                    if ($outcome === DeliverCampaignMessageAction::QUEUED) {
                        $batchDelivered++;
                        $timezoneDelivered++;
                    }
                }
            }

            if ($batchDelivered > 0) {
                $campaign->update(['delivered_count' => $campaign->recipients()->whereNotNull('sent_at')->count()]);
            }

            // Check if all recipients for this campaign have completed
            $remaining = $campaign->recipients()->where('status', RecipientStatus::Pending->value)->count();
            if ($remaining === 0) {
                $campaign->update(['status' => CampaignStatus::Sent]);
                $this->info("All timezone waves completed for campaign [{$campaign->name}]. Status marked Sent.");
            }
        }

        $this->info("Completed scheduled dispatcher run. Dispatched {$dispatchedCount} campaign(s), queued {$timezoneDelivered} timezone wave email(s).");

        return $exitCode;
    }
}
