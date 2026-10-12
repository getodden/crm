<?php

declare(strict_types=1);

namespace Odden\Filament\Support;

use Closure;
use Filament\Facades\Filament;
use Filament\Resources\Resource as FilamentResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Filament\Resources\CompanyResource;
use Odden\Filament\Resources\ContactResource;
use Odden\Filament\Resources\DealResource;
use Odden\Filament\Resources\QuoteResource;
use Odden\Filament\Resources\SalesQuotaResource;
use Odden\Filament\Resources\SalesSequenceResource;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Quote;
use Odden\Sales\Models\SalesQuota;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\SalesSequenceEnrollment;

use function Filament\get_authorization_response;

/**
 * One place for the plugin's authorization checks outside Filament's built-in record actions.
 *
 * Checks follow Filament's own resource rules: when a policy with the ability's method is
 * registered for the model, it decides; when none is, the ability is allowed (unless the
 * panel uses strict authorization, or a `Gate::before()` callback denies). Every check
 * requires an authenticated panel user.
 */
final class OddenAuthorization
{
    /**
     * @var array<class-string<Model>, class-string<FilamentResource>>
     */
    private const RESOURCES = [
        Contact::class => ContactResource::class,
        Company::class => CompanyResource::class,
        Deal::class => DealResource::class,
        Quote::class => QuoteResource::class,
        SalesSequence::class => SalesSequenceResource::class,
        SalesQuota::class => SalesQuotaResource::class,
    ];

    /**
     * Whether the current user may perform the ability on the record or model class.
     *
     * When a resource is given, its own authorization rules are used (including
     * `skipAuthorization()` and `checkPolicyExistence()`).
     *
     * @param  Model|class-string<Model>  $model
     * @param  class-string<FilamentResource>|null  $resource
     */
    public static function allows(string $ability, Model|string $model, ?string $resource = null): bool
    {
        if (Filament::auth()->guest()) {
            return false;
        }

        if ($resource !== null) {
            return $resource::can($ability, $model instanceof Model ? $model : null);
        }

        return get_authorization_response($ability, $model)->allowed();
    }

    /**
     * A closure for an action's `->authorize()` that checks the ability on the action's record.
     *
     * @param  class-string<FilamentResource>|null  $resource
     * @return Closure(Model): bool
     */
    public static function forRecord(string $ability, ?string $resource = null): Closure
    {
        return static fn (Model $record): bool => self::allows($ability, $record, $resource);
    }

    /**
     * Abort with a 403 unless the current user may perform the ability.
     *
     * @param  Model|class-string<Model>  $model
     * @param  class-string<FilamentResource>|null  $resource
     */
    public static function authorize(string $ability, Model|string $model, ?string $resource = null): void
    {
        abort_unless(self::allows($ability, $model, $resource), 403);
    }

    /**
     * Whether the current user passes `viewAny` on every given resource whose model is installed.
     *
     * @param  list<class-string<FilamentResource>>  $resources
     */
    public static function canViewAny(array $resources): bool
    {
        if (Filament::auth()->guest()) {
            return false;
        }

        foreach ($resources as $resource) {
            if (! class_exists($resource::getModel())) {
                continue;
            }

            if (! $resource::canViewAny()) {
                return false;
            }
        }

        return true;
    }

    /**
     * The model's query, limited to records in the resource's Eloquent query.
     *
     * `getEloquentQuery()` is where a resource applies tenant or user scoping; constraining
     * by it keeps lookups by ID inside that scope.
     *
     * @template TModel of Model
     *
     * @param  class-string<FilamentResource>  $resource
     * @param  class-string<TModel>  $model
     * @return Builder<TModel>
     */
    public static function query(string $resource, string $model): Builder
    {
        $scoped = $resource::getEloquentQuery();

        return $model::query()->whereIn(
            (new $model)->getQualifiedKeyName(),
            $scoped->select($scoped->getModel()->getQualifiedKeyName()),
        );
    }

    /**
     * The visible records of a model the panel has a resource for: Contact, Company, Deal, Quote,
     * SalesSequence and SalesQuota. Pages and widgets that query these models directly should use this
     * so tenant or user scoping in the resource's `getEloquentQuery()` is honoured.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return Builder<TModel>
     */
    public static function scoped(string $model): Builder
    {
        $resource = self::RESOURCES[$model] ?? null;

        if ($resource === null) {
            throw new \InvalidArgumentException("No panel resource is registered for [{$model}].");
        }

        return self::query($resource, $model);
    }

    /**
     * Constrain a query on one of the panel's models to the records the user can see (see scoped()). A query on a
     * model the panel has no resource for is returned unchanged.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function constrain(Builder $query): Builder
    {
        $model = $query->getModel();

        if (! isset(self::RESOURCES[$model::class])) {
            return $query;
        }

        return $query->whereIn(
            $model->getQualifiedKeyName(),
            self::scoped($model::class)->select($model->getQualifiedKeyName()),
        );
    }

    /**
     * Activities whose subject (contact, company or deal) is visible to the panel user. Activities
     * on other subject types are not shown.
     *
     * @return Builder<Activity>
     */
    public static function activities(): Builder
    {
        $query = Activity::query();

        $query->where(function (Builder $where): void {
            $any = false;

            foreach ([
                Contact::class,
                Company::class,
                Deal::class,
            ] as $model) {
                if (! class_exists($model)) {
                    continue;
                }

                $any = true;
                $keys = self::scoped($model)->select((new $model)->getQualifiedKeyName());
                $where->orWhere(fn (Builder $q) => $q
                    ->where('subject_type', (new $model)->getMorphClass())
                    ->whereIn('subject_id', $keys));
            }

            if (! $any) {
                $where->whereRaw('1 = 0');
            }
        });

        return $query;
    }

    /**
     * Sequence enrollments whose sequence and contact are both visible to the panel user.
     *
     * @return Builder<SalesSequenceEnrollment>
     */
    public static function enrollments(): Builder
    {
        $contact = new Contact;
        $sequence = new SalesSequence;

        return SalesSequenceEnrollment::query()
            ->whereIn('contact_id', self::scoped(Contact::class)->select($contact->getQualifiedKeyName()))
            ->whereIn('sequence_id', self::scoped(SalesSequence::class)->select($sequence->getQualifiedKeyName()));
    }

    /**
     * Find a record by key within the resource's Eloquent query, or abort with a 404.
     *
     * @template TModel of Model
     *
     * @param  class-string<FilamentResource>  $resource
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    public static function find(string $resource, string $model, int|string $key): Model
    {
        $record = self::query($resource, $model)->find($key);

        abort_if($record === null, 404);

        return $record;
    }

    /**
     * Find a record within the resource's Eloquent query and authorize the ability on it (404 / 403 otherwise).
     *
     * @template TModel of Model
     *
     * @param  class-string<FilamentResource>  $resource
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    public static function findAndAuthorize(string $resource, string $model, int|string $key, string $ability): Model
    {
        $record = self::find($resource, $model, $key);

        self::authorize($ability, $record, $resource);

        return $record;
    }

    /**
     * The authenticated panel user's key, or a 403 when nobody is signed in.
     */
    public static function userId(): int|string
    {
        $id = Filament::auth()->id();

        abort_if($id === null, 403);

        return $id;
    }
}
