<?php

declare(strict_types=1);

namespace Odden\Core\Contracts;

/**
 * Tells Odden which tenant the current request, job or command is acting for.
 *
 * Self-hosted installs are single-tenant and use the default implementation, which always
 * returns null. A multi-tenant host application (or a tenancy package) rebinds this contract
 * to an implementation that returns the active tenant's id, and Odden code that must behave
 * differently per tenant (cache keys, merge guards, user pickers) asks it instead of
 * assuming a single tenant.
 */
interface TenantContext
{
    /**
     * The active tenant's id, or null when no tenant is active (single-tenant installs, and
     * code running outside any tenant such as central administration).
     */
    public function id(): int|string|null;
}
