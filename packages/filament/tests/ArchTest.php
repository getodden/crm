<?php

declare(strict_types=1);
use Illuminate\Contracts\Queue\ShouldBeEncrypted;

arch('no debug functions are left in the code')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

// A queued mail is stored whole in the jobs table (recipient, rendered subject and body), and again in failed_jobs when it fails.
// Encrypted, the stored copy is unreadable without the application key (PLAN-encryption-at-rest.md, section 7).
arch('queued mail is encrypted in the queue')
    ->expect('Odden\Sales\Mail')
    ->toImplement(ShouldBeEncrypted::class)
    ->ignoring(['Odden\Sales\Mail\SalesMail', 'Odden\Sales\Mail\ConfiguresSalesMail']);
