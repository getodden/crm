<?php

declare(strict_types=1);

namespace Odden\Core\Support;

use Illuminate\Database\Eloquent\Model;
use Odden\Core\Contracts\TenantContext;
use Odden\Core\Exceptions\CrossTenantException;

/**
 * Refuses operations that would cross a tenant boundary.
 *
 * A tenancy add-on stores each record's tenant in a `tenant_id` attribute (the column name is
 * fixed so Core can read it without knowing about the add-on). Models without that column report
 * null, and on single-tenant installs every record and the active tenant are null, so nothing
 * here ever throws for self-hosters.
 */
final class TenantGuard
{
    public const string COLUMN = 'tenant_id';

    public function __construct(private readonly TenantContext $context) {}

    /**
     * The tenant a record belongs to, or null when it has none.
     */
    public function tenantOf(Model $record): int|string|null
    {
        $tenant = $record->getAttribute(self::COLUMN);

        return is_int($tenant) || is_string($tenant) ? $tenant : null;
    }

    /**
     * Throw unless both records belong to the same tenant (two null tenants count as the same),
     * and, when a tenant is active, unless they belong to it.
     *
     * @throws CrossTenantException
     */
    public function assertSameTenant(Model $a, Model $b): void
    {
        if (! $this->same($this->tenantOf($a), $this->tenantOf($b))) {
            throw new CrossTenantException(sprintf(
                'Cannot link %s #%s and %s #%s: they belong to different tenants.',
                class_basename($a), $a->getKey(), class_basename($b), $b->getKey(),
            ));
        }

        $this->assertInActiveTenant($a);
        $this->assertInActiveTenant($b);
    }

    /**
     * When a tenant is active, throw unless the record belongs to it.
     *
     * @throws CrossTenantException
     */
    public function assertInActiveTenant(Model $record): void
    {
        $active = $this->context->id();

        if ($active !== null && ! $this->same($this->tenantOf($record), $active)) {
            throw new CrossTenantException(sprintf(
                '%s #%s does not belong to the active tenant.',
                class_basename($record), $record->getKey(),
            ));
        }
    }

    private function same(int|string|null $a, int|string|null $b): bool
    {
        return $a === null || $b === null ? $a === $b : (string) $a === (string) $b;
    }
}
