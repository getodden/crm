<?php

declare(strict_types=1);

namespace Odden\Core\Support;

use Odden\Core\Contracts\TenantContext;

/**
 * Cache keys for state that belongs to one tenant, such as a round-robin position.
 *
 * Without an active tenant the key is returned unchanged, so single-tenant installs keep the
 * keys they already have. With one, the tenant id is part of the key, so tenants never share a
 * counter or a cached value.
 */
final class CacheKey
{
    public static function for(string $key): string
    {
        $tenant = app(TenantContext::class)->id();

        return $tenant === null ? $key : "tenant:{$tenant}:{$key}";
    }
}
