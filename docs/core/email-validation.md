---
title: Email validation
description: How Core checks contact email addresses, what each status means, and how to run the check yourself.
---

Every contact carries an email status, so a list that was imported or typed in by hand can be told apart from addresses that are known to work. Core checks the address itself and, when allowed, asks DNS whether the domain accepts mail. It never contacts the mailbox.

## Statuses

| Status | Meaning |
| :--- | :--- |
| `valid` | No problem found: the address is well formed and its domain accepts mail. This does not prove the mailbox exists. |
| `risky` | Probably deliverable, but worth caution. See the reasons below. |
| `invalid` | Cannot be delivered: malformed, or the domain accepts no mail. |
| `unknown` | Not settled yet: the DNS check has not run, or the lookup failed. |
| `null` | Never checked. |

Each status can carry a reason, stored in `email_status_reason`:

| Reason | Status | Meaning |
| :--- | :--- | :--- |
| `syntax` | invalid | Not a valid email address |
| `no_mail_server` | invalid | No MX or address record, or a null MX (RFC 7505) |
| `typo` | risky | The domain is one typo away from a well-known provider, such as `gmial.com` |
| `disposable` | risky | A throwaway-inbox provider, including its subdomains |
| `role` | risky | A shared mailbox such as `info@` or `support@`; `support+billing@` counts as `support@` |
| `dns_unavailable` | unknown | The lookup could not be completed; try again later |
| `bounced` | invalid | A message to this address bounced. Set by the Marketing add-on from delivery events; a re-check never clears it, only a change of address does |
| `not_checked` | unknown | Only the offline checks have run |

`email_checked_at` records when the address was last checked.

In the admin panel the contact list shows the status as a column, with the reason as a tooltip, and can be filtered by it.

## When it runs

- **Every create and every change of address.** The contact model records the offline checks, so contacts created by the portal, chat, inbound mail, booking and imports all get a status. An address that changes is checked again, so a status is never carried over to a different address.
- **`CreateContactAction`** adds the DNS check and saves the settled status before the `ContactCreated` event fires.
- **On demand**, for contacts you already have:

```php
use Odden\Core\Actions\ValidateContactEmailAction;

app(ValidateContactEmailAction::class)->execute($contact);

// Offline checks only:
app(ValidateContactEmailAction::class)->execute($contact, checkDns: false);
```

A result saved by the action is written quietly, so it adds nothing to the property history and fires no update listeners. A status you pass in when creating a contact is kept as given.

## Checking an address without a contact

```php
use Odden\Core\Support\EmailValidator;

$check = app(EmailValidator::class)->check('dana@gmial.com');

$check->status;     // EmailStatus::Risky
$check->reason;     // EmailStatusReason::Typo
$check->suggestion; // 'dana@gmail.com'
```

Without the DNS check (`check($email, checkDns: false)`) a clean address is `unknown`, never `valid`, because nothing has shown its domain can receive mail.

## Configuration

Under `email_validation` in `config/odden-core.php`:

| Key | Default | What it does |
| :--- | :--- | :--- |
| `enabled` | `true` (`ODDEN_EMAIL_VALIDATION`) | Turn status recording off entirely |
| `check_dns` | `true` (`ODDEN_EMAIL_CHECK_DNS`) | Run the DNS check inside `CreateContactAction`. Set to `false` to keep DNS out of the request and run `ValidateContactEmailAction` from a queue |
| `dns_cache_minutes` | `1440` | How long a definite DNS answer is cached per domain. Failed lookups are never cached |
| `disposable_domains` | `[]` | Extra disposable-provider domains to flag |
| `role_local_parts` | `[]` | Extra shared-mailbox local parts to flag |

The bundled disposable list is a starter list of well-known providers, not an exhaustive one.

## Replacing the DNS lookup

The lookup sits behind `Odden\Core\Contracts\MailRouteResolver`. Bind your own implementation to use a different resolver, or to keep tests offline:

```php
$this->app->bind(MailRouteResolver::class, MyResolver::class);
```

`acceptsMail()` returns `true`, `false`, or `null` when the answer could not be determined.

## Upgrading

Run the new migration. It adds `email_status`, `email_status_reason` and `email_checked_at` to the contacts table; existing contacts stay `null` until they are checked.
