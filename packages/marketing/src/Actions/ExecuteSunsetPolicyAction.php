<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Core\Models\Contact;
use Odden\Marketing\Mail\MarketingMessageMailable;
use Odden\Marketing\Models\MarketingSubscription;
use Odden\Marketing\Support\ContactPreferences;
use Odden\Marketing\Support\MarketingMailer;

class ExecuteSunsetPolicyAction
{
    /**
     * Execute sunset policy progression on an unengaged contact.
     * Transitions: flagged -> reengagement_sent -> suppressed
     */
    public function execute(Contact $contact, bool $forceSuppress = false): Contact
    {
        return DB::transaction(function () use ($contact, $forceSuppress): Contact {
            if ($forceSuppress || $contact->sunset_stage === 'reengagement_sent') {
                $contact->update([
                    'is_unengaged' => true,
                    'sunset_stage' => 'suppressed',
                ]);

                // Suppression must hold whether or not fatigue protection is on: unsubscribe the address.
                $email = mb_strtolower(trim((string) $contact->email));
                if ($email !== '') {
                    MarketingSubscription::unsubscribe($email, $contact->id);
                }

                $contact->logTask(
                    title: 'Sunset Policy: Contact Suppressed',
                    dueAt: now(),
                    body: 'Contact automatically suppressed under deliverability sunset policy after prolonged inactivity to protect sender domain reputation.'
                );
            } elseif ($contact->sunset_stage === 'flagged' || $contact->is_unengaged) {
                $contact->update([
                    'sunset_stage' => 'reengagement_sent',
                ]);

                $this->sendReengagementEmail($contact);

                $contact->logTask(
                    title: 'Sunset Policy: Re-engagement Step Triggered',
                    dueAt: now(),
                    body: 'Triggered 14-day re-engagement verification sequence for dormant subscriber.'
                );
            }

            return $contact->fresh() ?? $contact;
        });
    }

    /**
     * Ask the contact whether they still want to hear from us, with a link to their preference center.
     */
    protected function sendReengagementEmail(Contact $contact): void
    {
        $email = mb_strtolower(trim((string) $contact->email));
        if ($email === '' || MarketingSubscription::isSuppressed($email)) {
            return;
        }

        $url = ContactPreferences::preferenceCenterUrl($contact);
        $name = trim((string) $contact->first_name) !== '' ? trim((string) $contact->first_name) : 'there';
        $subject = 'Do you still want to hear from us?';

        $text = "Hi {$name},\n\nWe've noticed you haven't opened our recent emails. If you'd like to keep receiving them, "
            ."you don't need to do anything. If not, you can choose what you hear about, or unsubscribe, here:\n\n{$url}\n\n"
            .'If we don\'t hear from you, we\'ll stop emailing you so our messages only reach people who want them.';

        MarketingMailer::queue(new MarketingMessageMailable(
            subjectLine: $subject,
            htmlBody: '<p>'.nl2br(e($text), false).'</p>',
            textBody: $text,
            fromEmail: (string) config('odden-marketing.defaults.sender_email'),
            fromName: (string) config('odden-marketing.defaults.sender_name'),
            replyToEmail: config('odden-marketing.defaults.reply_to') ?: null,
            listUnsubscribeUrl: $url,
        ), $email, trim($contact->first_name.' '.$contact->last_name) ?: null);
    }
}
