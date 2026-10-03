<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Core\Enums\ActivityType;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Exceptions\QuoteNotAcceptableException;
use Odden\Sales\Models\PipelineStage;
use Odden\Sales\Models\Quote;

class AcceptQuoteAction
{
    /**
     * Accept and sign a quote via public token.
     *
     * @throws QuoteNotAcceptableException
     */
    public function execute(string $token, string $signedByName, string $signedByEmail): Quote
    {
        /** @var Quote|null $quote */
        $quote = Quote::query()->where('public_token', $token)->first();

        if ($quote === null) {
            throw new QuoteNotAcceptableException('Invalid or expired quote link.');
        }

        return $this->accept($quote, $signedByName, $signedByEmail);
    }

    /**
     * Accept and sign a quote, whether the customer did it on the public page or an agent on their behalf.
     *
     * The quote acceptance and the deal's move to won happen in one transaction, so a failing
     * won-stage requirement leaves both the quote and the deal unchanged.
     *
     * @throws QuoteNotAcceptableException
     */
    public function accept(Quote $quote, string $signedByName, string $signedByEmail): Quote
    {
        $this->ensureAcceptable($quote);

        return DB::transaction(function () use ($quote, $signedByName, $signedByEmail): Quote {
            /** @var Quote $locked */
            $locked = Quote::query()
                ->whereKey($quote->getKey())
                ->lockForUpdate()
                ->with(['deal.pipeline.stages', 'deal.contacts'])
                ->firstOrFail();

            // Re-check under the row lock so concurrent submissions cannot both accept.
            $this->ensureAcceptable($locked);

            $locked->update([
                'status' => QuoteStatus::Accepted,
                'accepted_at' => now(),
                'signed_by_name' => trim($signedByName),
                'signed_by_email' => strtolower(trim($signedByEmail)),
            ]);

            return $this->closeDealAsWon($locked);
        });
    }

    /**
     * @throws QuoteNotAcceptableException
     */
    protected function ensureAcceptable(Quote $quote): void
    {
        if ($quote->status->isTerminal()) {
            throw new QuoteNotAcceptableException(match ($quote->status) {
                QuoteStatus::Accepted => 'This quote proposal has already been accepted.',
                QuoteStatus::Declined => 'This quote proposal has been declined and can no longer be accepted.',
                default => 'This quote proposal has expired.',
            });
        }

        if ($quote->hasPassedExpiryDate()) {
            $quote->update(['status' => QuoteStatus::Expired]);

            throw new QuoteNotAcceptableException('This quote proposal has expired.');
        }
    }

    protected function closeDealAsWon(Quote $quote): Quote
    {
        $deal = $quote->deal;
        /** @var PipelineStage|null $wonStage */
        $wonStage = $deal->pipeline->stages->firstWhere('is_closed_won', true);

        if ($wonStage !== null) {
            $deal->moveToStage($wonStage);
        }

        $deal->update([
            'status' => DealStatus::Won,
            'closed_at' => now(),
            'amount' => (float) $quote->total_amount,
        ]);

        $deal->logActivity(
            type: ActivityType::Note,
            title: "Quote #{$quote->quote_number} Accepted & Signed",
            body: "Electronically signed by {$quote->signed_by_name} ({$quote->signed_by_email}) for {$quote->currency} ".number_format((float) $quote->total_amount, 2).'.'
        );

        if ($deal->owner_id !== null) {
            $deal->logTask(
                title: "Deal Closed-Won: Quote #{$quote->quote_number} Signed!",
                dueAt: now(),
                body: "Customer {$quote->signed_by_name} ({$quote->signed_by_email}) has signed quote proposal #{$quote->quote_number} for {$quote->currency} ".number_format((float) $quote->total_amount, 2).'. Initiate customer onboarding and billing handoff immediately.',
                creatorId: (int) $deal->owner_id
            );
        }

        return $quote;
    }
}
