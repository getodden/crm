<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Odden\Core\Contracts\TenantContext;
use Odden\Core\Support\NullTenantContext;

it('has no active tenant by default', function (): void {
    expect(app(TenantContext::class))->toBeInstanceOf(NullTenantContext::class)
        ->and(app(TenantContext::class)->id())->toBeNull();
});

it('lets a host application rebind the tenant context', function (): void {
    app()->instance(TenantContext::class, new class implements TenantContext
    {
        public function id(): int|string|null
        {
            return 42;
        }

        public function scopeUsers(Builder $users): Builder
        {
            return $users;
        }
    });

    expect(app(TenantContext::class)->id())->toBe(42);
});
