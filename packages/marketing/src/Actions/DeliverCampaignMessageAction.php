<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\RecipientStatus;
use Odden\Marketing\Mail\MarketingMessageMailable;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Marketing\Models\MarketingSubscription;
use Odden\Marketing\Support\ContactPreferences;
use Odden\Marketing\Support\MarketingMailer;
use Throwable;

/**
 * The single delivery path for campaign email: standard dispatch, the local-time
 * wave release in marketing:dispatch-scheduled and the A/B winner rollout all
 * hand each recipient to this action.
 *
 * It compiles the recipient's message with CompileCampaignMessageAction and queues
 * a MarketingMessageMailable. A recipient is sent at most once: the row is claimed
 * (sent_at set) with a conditional update, so a retry, a re-run or a concurrent
 * worker finds it already claimed and sends nothing. If queueing fails the claim is
 * released and the exception propagates, so the recipient is retried next run.
 *
 * The mailable is queued after commit (UsesMarketingMailQueue). Outside a transaction, which
 * is how every caller in this package runs it, that means immediately, inside the try below.
 * Called inside your own transaction, the claim and the push both wait for the commit: a
 * rollback undoes the claim and drops the message together.
 */
class DeliverCampaignMessageAction
{
    public const QUEUED = 'queued';

    public const ALREADY_SENT = 'already_sent';

    public const SUPPRESSED = 'suppressed';

    public function __construct(
        protected CompileCampaignMessageAction $compiler,
    ) {}

    /**
     * Whether a contact may receive this campaign: a deliverable address that is not
     * unsubscribed, bounced or on the suppression list, subscribed to the campaign's
     * topic, and (when enabled and $applyFatigue is true) within the fatigue policy.
     */
    public function canReceive(Campaign $campaign, string $email, ?Contact $contact, bool $applyFatigue = true): bool
    {
        $email = mb_strtolower(trim($email));

        if ($email === '' || MarketingSubscription::isSuppressed($email, $campaign->topic_id)) {
            return false;
        }

        if ($contact !== null && ! empty($campaign->topic) && ! ContactPreferences::isSubscribedToTopic($contact, (string) $campaign->topic)) {
            return false;
        }

        if ($applyFatigue && $contact !== null && config('odden-marketing.fatigue_protection.enabled', false)) {
            return app(CheckFatiguePolicyAction::class)->execute($contact)['can_send'];
        }

        return true;
    }

    /**
     * Queue the campaign message for one recipient.
     *
     * @param  string|null  $variant  A/B variant to send (and record on the recipient); null keeps the recipient's own.
     * @param  bool  $checkEligibility  Re-check suppression and topic subscription first. Callers that send
     *                                  staged recipients later (time-zone waves, A/B rollout) keep this on, so
     *                                  an unsubscribe between dispatch and send is honored.
     * @return self::QUEUED|self::ALREADY_SENT|self::SUPPRESSED
     */
    public function execute(Campaign $campaign, CampaignRecipient $recipient, ?string $variant = null, bool $checkEligibility = true, ?string $activityTitle = null): string
    {
        /** @var Contact|null $contact */
        $contact = $recipient->contact;

        if ($recipient->sent_at !== null) {
            return self::ALREADY_SENT;
        }

        if ($checkEligibility && ! $this->canReceive($campaign, $recipient->email, $contact, applyFatigue: false)) {
            $claimed = CampaignRecipient::query()
                ->whereKey($recipient->getKey())
                ->whereNull('sent_at')
                ->update(['status' => RecipientStatus::Suppressed->value]);

            if ($claimed !== 1) {
                return self::ALREADY_SENT;
            }

            $recipient->forceFill(['status' => RecipientStatus::Suppressed])->syncOriginal();

            return self::SUPPRESSED;
        }

        $previousStatus = $recipient->getOriginal('status');
        $previousVariant = $recipient->getOriginal('variant');

        if ($variant !== null) {
            $recipient->variant = $variant;
        }

        $sentAt = now();
        $claimed = CampaignRecipient::query()
            ->whereKey($recipient->getKey())
            ->whereNull('sent_at')
            ->update([
                'status' => RecipientStatus::Sent->value,
                'variant' => $recipient->variant,
                'sent_at' => $sentAt,
                'updated_at' => $sentAt,
            ]);

        if ($claimed !== 1) {
            return self::ALREADY_SENT;
        }

        try {
            $html = $this->compiler->execute($campaign, $recipient);
            $subject = $this->compiler->subjectFor($campaign, $recipient);

            MarketingMailer::queue(new MarketingMessageMailable(
                subjectLine: $subject,
                htmlBody: $html,
                textBody: $this->compiler->plainText($html),
                fromEmail: $campaign->sender_email ?: (string) config('odden-marketing.defaults.sender_email'),
                fromName: $campaign->sender_name ?: (string) config('odden-marketing.defaults.sender_name'),
                replyToEmail: $campaign->reply_to_email ?: null,
                listUnsubscribeUrl: $recipient->getOneClickUnsubscribeUrl(),
                oneClickUnsubscribe: true,
                trackingToken: $recipient->tracking_token,
            ), $recipient->email, $this->displayName($contact));
        } catch (Throwable $e) {
            CampaignRecipient::query()->whereKey($recipient->getKey())->update([
                'status' => $previousStatus instanceof RecipientStatus ? $previousStatus->value : RecipientStatus::Pending->value,
                'variant' => $previousVariant,
                'sent_at' => null,
            ]);
            $recipient->variant = $previousVariant;

            throw $e;
        }

        $recipient->forceFill(['status' => RecipientStatus::Sent, 'sent_at' => $sentAt])->syncOriginal();

        if ($contact !== null) {
            $contact->logTask(
                title: $activityTitle ?? "Marketing Campaign: {$campaign->name}".($recipient->variant !== null ? " (Variant {$recipient->variant})" : ''),
                dueAt: now(),
                body: "Queued email with subject: \"{$subject}\"",
            );

            $contact->updateQuietly(['last_marketing_email_sent_at' => $sentAt]);
        }

        return self::QUEUED;
    }

    private function displayName(?Contact $contact): ?string
    {
        $name = $contact !== null ? trim("{$contact->first_name} {$contact->last_name}") : '';

        return $name !== '' ? $name : null;
    }
}
