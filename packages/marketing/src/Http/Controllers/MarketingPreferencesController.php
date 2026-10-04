<?php

declare(strict_types=1);

namespace Odden\Marketing\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\ApplyLeadScoringEventAction;
use Odden\Marketing\Enums\LeadScoringEventType;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Marketing\Models\MarketingSubscription;
use Odden\Marketing\Models\MarketingSubscriptionTopic;

class MarketingPreferencesController extends Controller
{
    /**
     * Display the self-service subscriber preference center.
     */
    public function showPreferences(string $token): View
    {
        $contact = $this->resolveContact($token);

        if ($contact === null) {
            abort(404, 'Invalid preference token.');
        }

        // Viewing the page never writes: topics come from the table, which may be empty until
        // you create topics or run MarketingSubscriptionTopic::seedDefaults().
        $dbTopics = MarketingSubscriptionTopic::orderBy('sort_order')->get();

        $topics = [];
        $currentTopics = [];
        $email = $contact->email ?? '';

        foreach ($dbTopics as $t) {
            $topics[$t->slug] = [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description ?? '',
            ];
            if ($email !== '' && MarketingSubscriptionTopic::isSubscribed($email, $t->id)) {
                $currentTopics[] = $t->slug;
            } elseif ($email === '' && $t->is_default) {
                $currentTopics[] = $t->slug;
            }
        }

        $isSuppressed = MarketingSubscription::isSuppressed($contact->email);

        return view('odden-marketing::preferences', compact('contact', 'topics', 'currentTopics', 'token', 'isSuppressed'));
    }

    /**
     * Update topic preferences or execute a global opt-out.
     */
    public function updatePreferences(Request $request, string $token): RedirectResponse
    {
        $contact = $this->resolveContact($token);

        if ($contact === null) {
            abort(404, 'Invalid preference token.');
        }

        if ($request->boolean('opt_out_all')) {
            MarketingSubscription::unsubscribe($contact->email, $contact->id);
            $contact->updateQuietly(['marketing_topics' => []]);

            return redirect()->back()->with('success', 'You have been unsubscribed from all marketing communications.');
        }

        // A form posts a list of topic slugs or ids; anything else (a string, nested arrays) is refused, not crashed on.
        $validated = $request->validate([
            'topics' => ['nullable', 'array'],
            'topics.*' => ['string', 'max:255'],
        ]);

        /** @var list<string> $selectedTopics */
        $selectedTopics = array_values($validated['topics'] ?? []);

        $allTopics = MarketingSubscriptionTopic::all();
        foreach ($allTopics as $t) {
            $isSubscribed = in_array($t->slug, $selectedTopics, true) || in_array((string) $t->id, $selectedTopics, true);
            MarketingSubscriptionTopic::setSubscription($contact->email, $t->id, $isSubscribed, $contact->id);
        }

        $contact->update([
            'marketing_topics' => $selectedTopics,
        ]);

        return redirect()->back()->with('success', 'Your subscription preferences have been updated successfully.');
    }

    /**
     * Confirm double opt-in email address.
     */
    public function confirmEmail(string $token, ApplyLeadScoringEventAction $scoringAction): View
    {
        /** @var Contact $contact */
        $contact = Contact::query()
            ->where('marketing_confirmation_token', $token)
            ->firstOrFail();

        $isFirstVerification = $contact->marketing_email_verified_at === null;

        $contact->update([
            'marketing_email_verified_at' => $contact->marketing_email_verified_at ?? now(),
        ]);

        if ($isFirstVerification) {
            $scoringAction->execute(
                contact: $contact,
                eventType: LeadScoringEventType::PropertyMatch,
                description: 'Double Opt-In Email Verified (+10 pts)',
            );
        }

        return view('odden-marketing::confirmed', compact('contact'));
    }

    /**
     * Resolve contact record from verification token or recipient unsubscribe token.
     */
    protected function resolveContact(string $token): ?Contact
    {
        /** @var Contact|null $contact */
        $contact = Contact::query()->where('marketing_verification_token', $token)->first();
        if ($contact !== null) {
            return $contact;
        }

        /** @var CampaignRecipient|null $recipient */
        $recipient = CampaignRecipient::query()->where('unsubscribe_token', $token)->first();
        if ($recipient !== null) {
            return $recipient->contact;
        }

        return null;
    }
}
