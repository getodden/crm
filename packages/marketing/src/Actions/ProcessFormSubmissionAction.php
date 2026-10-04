<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Odden\Core\Enums\LeadStatus;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\LeadScoringEventType;
use Odden\Marketing\Models\FormSubmission;
use Odden\Marketing\Models\MarketingForm;
use Odden\Marketing\Support\VisitorToken;

class ProcessFormSubmissionAction
{
    /**
     * Process an incoming form submission, auto-provision contact/company, and record activity.
     *
     * Pass $contact only when the caller has verified the submitter's identity (for
     * example with a signed ContactToken). Submitted data never selects a contact by id.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(
        MarketingForm $form,
        array $data,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?Contact $contact = null,
    ): FormSubmission {
        $email = isset($data['email']) ? mb_strtolower(trim((string) $data['email'])) : null;
        $firstName = isset($data['first_name']) ? trim((string) $data['first_name']) : null;
        $lastName = isset($data['last_name']) ? trim((string) $data['last_name']) : null;
        $phone = isset($data['phone']) ? trim((string) $data['phone']) : null;
        $companyName = isset($data['company']) ? trim((string) $data['company']) : null;

        unset($data['contact_id'], $data['contact']);

        $isVerified = $contact !== null;
        $isNewContact = false;

        if ($contact === null && ! empty($email)) {
            /** @var Contact|null $contact */
            $contact = Contact::query()->where('email', $email)->first();

            if ($contact === null) {
                $contact = Contact::create([
                    'email' => $email,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $phone,
                    'lead_status' => LeadStatus::New,
                    'lifecycle_stage' => LifecycleStage::Lead,
                ]);
                $isNewContact = true;
            } else {
                $updates = [];
                if (empty($contact->first_name) && ! empty($firstName)) {
                    $updates['first_name'] = $firstName;
                }
                if (empty($contact->last_name) && ! empty($lastName)) {
                    $updates['last_name'] = $lastName;
                }
                if (empty($contact->phone) && ! empty($phone)) {
                    $updates['phone'] = $phone;
                }
                if (! empty($updates)) {
                    $contact->update($updates);
                }
            }

            // An address typed into a public form is unverified. For a contact that already exists it can neither record
            // their consent to texts (a legal claim made on their behalf) nor link them to a company of the sender's
            // choosing. A new contact is the sender's own. (A contact identified by a signed link never gets here.)
            $mayRecordClaims = $isNewContact;

            // Handle SMS Consent if opted in
            if (! empty($data['sms_consent']) && $mayRecordClaims) {
                $contact->update([
                    'sms_consent' => true,
                    'sms_consent_at' => now(),
                ]);
            }

            // Stitch anonymous visitor sessions if a well-formed visitor_token was passed (malformed values,
            // including arrays, are ignored)
            $visitorToken = $data['visitor_token'] ?? null;
            if (VisitorToken::isValid($visitorToken)) {
                app(StitchVisitorToContactAction::class)->execute($visitorToken, $contact);
            }

            // Link company if provided, or perform Lead-to-Account domain auto-match
            if (! empty($companyName) && $mayRecordClaims) {
                /** @var Company|null $company */
                $company = Company::query()->where('name', $companyName)->first();
                if ($company === null) {
                    $company = Company::create(['name' => $companyName]);
                }

                if (! $contact->isAssociatedWith($company)) {
                    $contact->associateWith($company);
                }
            } else {
                app(AutoMatchLeadToCompanyAction::class)->execute($contact);
            }

            // Log activity on Contact timeline
            $contact->logTask(
                title: "Form Submission: {$form->title}",
                dueAt: now(),
                body: "Contact submitted marketing form [{$form->title}]."
            );

            // Apply Lead Scoring Event (+15 pts default)
            app(ApplyLeadScoringEventAction::class)->execute(
                contact: $contact,
                eventType: LeadScoringEventType::FormSubmission,
                description: "Submitted form: {$form->title}",
            );

            // Company intent sums its contacts' scores, so recalculate it once this submission's points are in.
            /** @var Company|null $matchedCompany */
            $matchedCompany = $contact->companies()->first();
            if ($matchedCompany !== null) {
                app(CalculateCompanyIntentScoreAction::class)->execute($matchedCompany);
            }
        }

        if ($contact !== null) {
            $excludedKeys = [
                '_token', 'email', 'first_name', 'last_name', 'phone', 'company',
                'sms_consent', 'visitor_token', 'contact_id',
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            ];

            // Only fields the form declares become custom properties; anything else
            // stays on the submission record only.
            $declaredKeys = array_diff($this->declaredFieldNames($form), $excludedKeys);
            $customProps = array_intersect_key($data, array_flip($declaredKeys));

            // Without a signed token the submitter has not proven they own this contact
            // (matched by email or just created), so existing values are only filled in,
            // never overwritten.
            if (! $isVerified) {
                $customProps = array_filter(
                    $customProps,
                    fn (string $key): bool => $this->isBlank($contact->getProperty($key)),
                    ARRAY_FILTER_USE_KEY,
                );
            }

            if (! empty($customProps)) {
                $contact->setProperties($customProps)->save();
            }
        }

        /** @var FormSubmission $submission */
        $submission = $form->submissions()->create([
            'contact_id' => $contact?->id,
            'form_data' => $data,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'utm_source' => isset($data['utm_source']) ? (string) $data['utm_source'] : null,
            'utm_medium' => isset($data['utm_medium']) ? (string) $data['utm_medium'] : null,
            'utm_campaign' => isset($data['utm_campaign']) ? (string) $data['utm_campaign'] : null,
            'utm_term' => isset($data['utm_term']) ? (string) $data['utm_term'] : null,
            'utm_content' => isset($data['utm_content']) ? (string) $data['utm_content'] : null,
        ]);

        $form->increment('submissions_count');

        if ($contact !== null) {
            app(EnrollContactInWorkflowAction::class)->triggerFormWorkflows($form, $contact);
        }

        return $submission;
    }

    /**
     * Field names declared in the form's schema and progressive profiling queue.
     *
     * @return list<string>
     */
    private function declaredFieldNames(MarketingForm $form): array
    {
        $names = [];

        foreach ([...($form->fields_schema ?? []), ...($form->progressive_fields ?? [])] as $field) {
            $name = $field['name'] ?? null;

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
