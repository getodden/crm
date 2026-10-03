<?php

declare(strict_types=1);

namespace Odden\Sales\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Odden\Sales\Enums\LeadRoutingStrategy;

/**
 * @property int $id
 * @property string $name
 * @property LeadRoutingStrategy $strategy
 * @property array<string, mixed>|null $criteria
 * @property list<int> $assigned_user_ids
 * @property int $last_assigned_index
 * @property bool $is_active
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class LeadRoutingRule extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'strategy',
        'criteria',
        'assigned_user_ids',
        'last_assigned_index',
        'is_active',
        'sort_order',
    ];

    /**
     * Default attribute values. -1 means "nobody assigned yet", so round robin starts at the first user.
     *
     * @var array<string, int>
     */
    protected $attributes = [
        'last_assigned_index' => -1,
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-sales.tables.lead_routing_rules', 'odden_sales_lead_routing_rules');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'strategy' => LeadRoutingStrategy::class,
            'criteria' => 'array',
            'assigned_user_ids' => 'array',
            'last_assigned_index' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
