<?php

declare(strict_types=1);

namespace Odden\Core\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Odden\Core\Database\Factories\ContactFactory;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Support\UserModel;
use Odden\Core\Traits\AuditsProperties;
use Odden\Core\Traits\BelongsToTeam;
use Odden\Core\Traits\HasActivities;
use Odden\Core\Traits\HasAssociations;
use Odden\Core\Traits\HasCustomProperties;
use Odden\Core\Traits\QualifiesRelatedColumns;
use Odden\Core\Traits\HasLifecycleStageTransitions;

/**
 * @property int $id
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $job_title
 * @property string $email
 * @property string|null $phone
 * @property string|null $linkedin_url
 * @property string|null $timezone
 * @property LifecycleStage $lifecycle_stage
 * @property LeadStatus $lead_status
 * @property int $lead_score
 * @property CarbonInterface|null $lead_score_updated_at
 * @property array<string, mixed>|null $properties
 * @property CarbonInterface|null $last_contacted_at
 * @property int|null $owner_id
 * @property int|null $team_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property CarbonInterface|null $marketing_email_verified_at
 * @property string|null $marketing_verification_token
 * @property list<string>|null $marketing_topics
 * @property CarbonInterface|null $last_marketing_email_sent_at
 * @property bool $is_unengaged
 * @property CarbonInterface|null $unengaged_since
 * @property string|null $sunset_stage
 * @property bool $sms_consent
 * @property CarbonInterface|null $sms_consent_at
 * @property CarbonInterface|null $became_subscriber_at
 * @property CarbonInterface|null $became_lead_at
 * @property CarbonInterface|null $became_marketing_qualified_lead_at
 * @property CarbonInterface|null $became_sales_qualified_lead_at
 * @property CarbonInterface|null $became_opportunity_at
 * @property CarbonInterface|null $became_customer_at
 * @property CarbonInterface|null $became_evangelist_at
 * @property CarbonInterface|null $became_other_at
 * @property-read CarbonInterface|null $became_mql_at
 * @property-read CarbonInterface|null $became_sql_at
 * @property-read string $full_name
 */
class Contact extends Model
{
    use AuditsProperties;
    use BelongsToTeam;
    use HasActivities;
    use HasAssociations;
    use HasCustomProperties;
    use QualifiesRelatedColumns;

    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    use HasLifecycleStageTransitions;
    use Notifiable;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'job_title',
        'email',
        'phone',
        'linkedin_url',
        'timezone',
        'lifecycle_stage',
        'became_subscriber_at',
        'became_lead_at',
        'became_marketing_qualified_lead_at',
        'became_sales_qualified_lead_at',
        'became_opportunity_at',
        'became_customer_at',
        'became_evangelist_at',
        'became_other_at',
        'lead_status',
        'lead_score',
        'lead_score_updated_at',
        'sms_consent',
        'sms_consent_at',
        'marketing_email_verified_at',
        'marketing_verification_token',
        'marketing_topics',
        'last_marketing_email_sent_at',
        'is_unengaged',
        'unengaged_since',
        'sunset_stage',
        'properties',
        'last_contacted_at',
        'owner_id',
        'team_id',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-core.tables.contacts', 'odden_contacts');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lifecycle_stage' => LifecycleStage::class,
            'became_subscriber_at' => 'datetime',
            'became_lead_at' => 'datetime',
            'became_marketing_qualified_lead_at' => 'datetime',
            'became_sales_qualified_lead_at' => 'datetime',
            'became_opportunity_at' => 'datetime',
            'became_customer_at' => 'datetime',
            'became_evangelist_at' => 'datetime',
            'became_other_at' => 'datetime',
            'lead_status' => LeadStatus::class,
            'lead_score' => 'integer',
            'lead_score_updated_at' => 'datetime',
            'sms_consent' => 'boolean',
            'sms_consent_at' => 'datetime',
            'marketing_email_verified_at' => 'datetime',
            'marketing_topics' => 'array',
            'last_marketing_email_sent_at' => 'datetime',
            'is_unengaged' => 'boolean',
            'unengaged_since' => 'datetime',
            'properties' => 'array',
            'last_contacted_at' => 'datetime',
        ];
    }

    /**
     * Mark this contact as contacted with timestamp.
     */
    public function markContacted(?CarbonInterface $at = null): self
    {
        $this->update([
            'last_contacted_at' => $at ?? now(),
        ]);

        return $this;
    }

    /**
     * Get the contact's full name, falling back to email.
     */
    public function getFullNameAttribute(): string
    {
        $name = trim("{$this->first_name} {$this->last_name}");

        return $name !== '' ? $name : $this->email;
    }

    /**
     * Alias for became_marketing_qualified_lead_at.
     */
    public function getBecameMqlAtAttribute(): ?CarbonInterface
    {
        return $this->became_marketing_qualified_lead_at;
    }

    /**
     * Alias for became_sales_qualified_lead_at.
     */
    public function getBecameSqlAtAttribute(): ?CarbonInterface
    {
        return $this->became_sales_qualified_lead_at;
    }

    /**
     * The owner / assigned sales rep.
     *
     * @return BelongsTo<Model, $this>
     */
    public function owner(): BelongsTo
    {
        $userModel = UserModel::className();

        return $this->belongsTo($userModel, 'owner_id');
    }

    /**
     * Associated companies.
     *
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(
            Company::class,
            config('odden-core.tables.associations', 'odden_associations'),
            'parent_id',
            'child_id'
        )
            ->wherePivot('parent_type', $this->getMorphClass())
            ->wherePivot('child_type', (new Company)->getMorphClass())
            ->withPivot(['id', 'type'])
            ->withTimestamps();
    }

    /**
     * Scope query to find contact by email.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeWhereEmail(Builder $query, string $email): Builder
    {
        return $query->where('email', strtolower(trim($email)));
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ContactFactory
    {
        return ContactFactory::new();
    }
}
