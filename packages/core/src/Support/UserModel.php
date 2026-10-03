<?php

declare(strict_types=1);

namespace Odden\Core\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Contracts\TenantContext;
use RuntimeException;

/**
 * Resolves the host application's user model so Odden packages never
 * reference a concrete App\ class.
 *
 * Resolution order: config('odden-core.user_model'), then the default
 * auth provider's model (config('auth.providers.users.model')).
 */
final class UserModel
{
    /**
     * @return class-string<Model>
     */
    public static function className(): string
    {
        $class = config('odden-core.user_model') ?? config('auth.providers.users.model');

        if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
            throw new RuntimeException(
                'Odden could not resolve an Eloquent user model. Set "odden-core.user_model" or "auth.providers.users.model".'
            );
        }

        return $class;
    }

    /**
     * A query on the user model, narrowed to the active tenant's users when there is one.
     *
     * @return Builder<Model>
     */
    public static function query(): Builder
    {
        return app(TenantContext::class)->scopeUsers(self::className()::query());
    }

    public static function make(): Model
    {
        $class = self::className();

        return new $class;
    }

    public static function table(): string
    {
        return self::make()->getTable();
    }

    /**
     * The user's "name" attribute, or the fallback when there is no user or no usable name.
     */
    public static function displayName(?Model $user, string $fallback = 'Unknown'): string
    {
        $name = $user?->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : $fallback;
    }
}
