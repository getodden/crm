<?php

declare(strict_types=1);

namespace Odden\Marketing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, NpsResponse> $responses
 * @property-read int $nps_score
 */
class NpsSurvey extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-marketing.tables.nps_surveys', 'odden_marketing_nps_surveys');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Survey feedback responses.
     *
     * @return HasMany<NpsResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(NpsResponse::class, 'survey_id');
    }

    /**
     * Calculate Net Promoter Score (-100 to +100) from the people who answered. Recipients who were sent the
     * survey but never responded are not part of the score.
     */
    public function calculateNpsScore(): int
    {
        $answered = $this->responses()->whereNotNull('responded_at');

        $total = (clone $answered)->count();
        if ($total === 0) {
            return 0;
        }

        $promoters = (clone $answered)->where('category', 'promoter')->count();
        $detractors = (clone $answered)->where('category', 'detractor')->count();

        $promoterPct = ($promoters / $total) * 100;
        $detractorPct = ($detractors / $total) * 100;

        return (int) round($promoterPct - $detractorPct);
    }
}
