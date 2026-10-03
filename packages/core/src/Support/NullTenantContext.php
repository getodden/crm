<?php

declare(strict_types=1);

namespace Odden\Core\Support;

use Odden\Core\Contracts\TenantContext;

/**
 * The default tenant context: Odden is not multi-tenant and there is never an active tenant.
 */
final class NullTenantContext implements TenantContext
{
    public function id(): int|string|null
    {
        return null;
    }
}
