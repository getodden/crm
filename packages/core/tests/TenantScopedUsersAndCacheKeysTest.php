<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Odden\Core\Contracts\TenantContext;
use Odden\Core\Support\CacheKey;
use Odden\Core\Support\UserModel;
use Odden\Core\Tests\Fixtures\User;

/**
 * @param  list<int>  $userIds  The users who belong to the tenant.
 */
function actingInTenant(int|string $tenant, array $userIds): void
{
    app()->instance(TenantContext::class, new class($tenant, $userIds) implements TenantContext
    {
        /** @param list<int> $userIds */
        public function __construct(private int|string $tenant, private array $userIds) {}

        public function id(): int|string|null
        {
            return $this->tenant;
        }

        public function scopeUsers(Builder $users): Builder
        {
            return $users->whereIn('id', $this->userIds);
        }
    });
}

it('lists every user when there is no active tenant', function (): void {
    User::factory()->count(3)->create();

    expect(UserModel::query()->count())->toBe(3);
});

it('lists only the active tenant\'s users', function (): void {
    $users = User::factory()->count(4)->create();
    actingInTenant(1, [$users[0]->id, $users[1]->id]);

    expect(UserModel::query()->pluck('id')->all())->toBe([$users[0]->id, $users[1]->id])
        ->and(UserModel::query()->find($users[3]->id))->toBeNull();
});

it('keeps cache keys unchanged without a tenant', function (): void {
    expect(CacheKey::for('odden-marketing:handoff-owner-index'))->toBe('odden-marketing:handoff-owner-index');
});

it('gives each tenant its own cache key', function (): void {
    actingInTenant(5, []);
    $five = CacheKey::for('counter');
    actingInTenant('acme', []);

    expect($five)->toBe('tenant:5:counter')
        ->and(CacheKey::for('counter'))->toBe('tenant:acme:counter');
});
