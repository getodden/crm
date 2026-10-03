---
title: Quotes
description: Generate quotes from deals, manage quote totals, and let customers view and accept quotes online.
---

An `Odden\Sales\Models\Quote` is a proposal attached to a deal. Each quote gets a random public token, and the package serves a public page where the customer can review the quote and accept it by typing their name and email. Acceptance closes the deal as won.

## Quote attributes

| Attribute | Type | Notes |
| --- | --- | --- |
| `deal_id` | int | |
| `quote_number` | string | Unique. Generated as `Q-{year}-{5 random characters}` if empty on create. |
| `title` | string | |
| `status` | `QuoteStatus` | Defaults to `draft`. |
| `subtotal`, `discount_amount`, `tax_amount`, `total_amount` | decimal | |
| `currency` | string(3) | |
| `terms`, `notes` | text, nullable | Shown on the public page. |
| `public_token` | string | Generated with `Str::random(40)` if empty on create. |
| `expires_at` | date, nullable | |
| `accepted_at` | datetime, nullable | |
| `signed_by_name`, `signed_by_email` | string, nullable | Set on acceptance. |
| `user_id` | int, nullable | The rep who prepared the quote. |

Quotes are soft-deletable. Relations: `deal()`, `items()` (ordered by `sort_order`), and `user()`. `$deal->quotes` returns a deal's quotes, newest first.

`Odden\Sales\Enums\QuoteStatus` has `Draft`, `Sent`, `Approved`, `Accepted`, `Declined`, and `Expired`, with `label()`, `color()`, `isAccepted()`, and `isTerminal()` (true for accepted, declined, and expired). The package sets `sent`, `accepted`, and `expired` itself; `approved` and `declined` are only set by your code.

## Generating a quote from a deal

`Odden\Sales\Actions\GenerateQuoteFromDealAction` creates a draft quote and copies the deal's [products](deals.md#products) into quote items:

```php
public function execute(
    Deal $deal,
    ?string $title = null,
    ?string $terms = null,
    ?string $notes = null,
    ?CarbonInterface $expiresAt = null,
    int|string|null $userId = null,
): Quote
```

```php
use Odden\Sales\Actions\GenerateQuoteFromDealAction;

$quote = app(GenerateQuoteFromDealAction::class)->execute(
    deal: $deal,
    title: 'Acme platform proposal',
    expiresAt: now()->addDays(14),
);
```

Defaults when you omit arguments:

- `title`: `Quote for {deal name}`.
- `terms`: `Payment due net 30 days from signature.`
- `expires_at`: 30 days from now.
- `user_id`: the deal's `owner_id`, then the authenticated user.
- `currency`: the deal's currency.

If the deal has no products but a positive `amount`, the quote gets a single item named after the deal for that amount. Discount and tax start at zero.

## Quote items and totals

`Odden\Sales\Models\QuoteItem` has the same fields as a deal product: `name`, `sku`, `description`, `unit_price`, `quantity`, `discount_percent`, and `sort_order`. Its `total_price` is calculated on save the same way, and `quantity` defaults to `1`.

Saving or deleting an item calls `$quote->recalculateTotals()`, which sets:

- `subtotal` to the sum of item totals, and
- `total_amount` to `max(0, subtotal − discount_amount) + tax_amount`.

`discount_amount` and `tax_amount` are amounts you set; the package does not calculate tax. Call `recalculateTotals()` after changing them:

```php
use Odden\Sales\Models\QuoteItem;

$quote->update(['discount_amount' => 500, 'tax_amount' => 640]);
$quote->recalculateTotals();

QuoteItem::create([
    'quote_id' => $quote->id,
    'name' => 'Premium support',
    'unit_price' => 1200,
    'quantity' => 1,
]); // Totals are recalculated automatically.
```

Quote items are a snapshot. Changing the deal's products later does not change existing quotes.

## Sharing the quote

Send the customer the URL of the `odden.quotes.show` route:

```php
$url = route('odden.quotes.show', ['token' => $quote->public_token]);
```

The package does not email quotes; send the link with your own mail or notification.

When the page is opened (`GET /quotes/{token}`):

- A `draft` quote is changed to `sent` (quietly, without model events).
- A note activity titled `Proposal Viewed by Customer` is logged on the deal, with the quote ID and visitor IP in its metadata. Only one view note is logged per quote every two hours.
- The page shows the line items, totals, terms, and notes. If the quote is accepted it shows the signature. If the quote can't be accepted (see [Which quotes can be accepted](#which-quotes-can-be-accepted)) it shows a "Proposal Declined" or "Proposal Expired" notice saying the quote is no longer available for acceptance, and no form. Otherwise it shows an acceptance form.

The page is the Blade view `odden-sales::quotes.public-portal`. Override it by creating `resources/views/vendor/odden-sales/quotes/public-portal.blade.php` in your app.

## Accepting a quote

The form posts to `odden.quotes.accept` (`POST /quotes/{token}/accept`), which is rate limited by Core's `odden-public` limiter. It validates:

| Field | Rules |
| --- | --- |
| `signed_name` | required, string, 2–255 characters |
| `signed_email` | required, email, max 255 |
| `agree_terms` | accepted |

It then calls `Odden\Sales\Actions\AcceptQuoteAction` and redirects back to the quote page:

- On success, with a `status` flash message.
- If the quote can't be accepted, with an `error` validation error holding the `QuoteNotAcceptableException` message, such as "This quote proposal has expired."
- On any other failure (for example a [stage requirement](#what-acceptance-does) on the closed won stage), the exception is passed to `report()`, so it reaches your log and error tracker, and the visitor sees a generic `error`: "We could not complete the acceptance of this proposal. Please contact your sales representative." Internal error messages are never shown on the public page.

You can call the action directly, for example to record a signature captured elsewhere:

```php
use Odden\Sales\Actions\AcceptQuoteAction;

$quote = app(AcceptQuoteAction::class)->execute(
    $quote->public_token,
    'Alice Johnson',
    'alice@acme.com',
);
```

`AcceptQuoteAction::accept(Quote $quote, string $name, string $email)` applies the same rules to a quote you already hold, for example when an agent signs on a customer's behalf; the Filament **Accept & Sign** actions use it. `Quote::accept()` itself only changes the quote and also refuses accepted, declined and expired quotes.

Both throw `Odden\Sales\Exceptions\QuoteNotAcceptableException` (a subclass of `InvalidArgumentException`) if no quote has the token or the quote can't be accepted. Its messages are safe to show to the customer.

### Which quotes can be accepted

A quote can be accepted when `$quote->isAcceptable()` is `true`:

- its status is not terminal (`accepted`, `declined`, or `expired`; see `QuoteStatus::isTerminal()`), and
- its expiry date has not passed (`$quote->hasPassedExpiryDate()` is `false`).

A quote is valid through the whole of its `expires_at` day and expires once that date has passed, so a quote with `expires_at` of 30 June can be accepted until the end of 30 June. The `sales:expire-quotes` command uses the same rule (see [Expiring stale quotes](#expiring-stale-quotes)). Dates are compared in your app's timezone.

A quote that is already accepted can't be accepted again, so the original signature is kept. If a `draft`, `sent`, or `approved` quote is past its expiry date when someone tries to accept it, the action also sets its status to `expired`, as the command would have.

### What acceptance does

All of the following runs in one database transaction, with the quote row locked and its status checked again inside the transaction, so two simultaneous submissions can't both accept it:

1. Sets the quote to `accepted`, with `accepted_at`, the trimmed signer name, and the trimmed, lower-cased email.
2. Moves the deal to the pipeline's closed won stage with `moveToStage()`, if the pipeline has one. This runs that stage's [stage automations](pipelines-and-stages.md#stage-automations) and dispatches `DealMovedStage` and `DealWon` with a `null` user ID.
3. Sets the deal's `status` to `won`, `closed_at` to now, and `amount` to the quote's `total_amount`.
4. Logs a note activity on the deal (`Quote #… Accepted & Signed`).
5. If the deal has an owner, logs a pending task for the owner, due now, to start onboarding.

If any step fails, for example a stage requirement on the closed won stage throws `StageRequirementException`, the whole transaction rolls back: the quote keeps its previous status and no signature, and the deal stays open in its current stage. The public page reports the failure to your logs and shows the customer the generic message above. Avoid requirements on closed won stages that a customer signature can't satisfy, such as `RequireDealProducts` on deals without products. Because the quote is marked accepted before the deal moves, a `RequireAcceptedQuote` requirement on the closed won stage is satisfied.

`DealMovedStage` and `DealWon` are dispatched inside the transaction. Listeners that run synchronously and fail roll the acceptance back too.

## Expiring stale quotes

```bash
php artisan sales:expire-quotes
```

The command sets `status` to `expired` for every `draft`, `sent`, or `approved` quote whose expiry date has passed, that is, whose `expires_at` is before today (the `Quote::pastExpiryDate()` query scope). A quote expiring today is left alone until tomorrow, matching what acceptance allows. It updates rows in bulk, so no model events fire. The package does not schedule it; see [Scheduling](configuration.md#scheduling).

`$quote->isExpired()` returns `true` when the quote is not accepted and either its status is `expired` or its expiry date has passed (regardless of the stored status).
