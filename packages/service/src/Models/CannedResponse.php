<?php

declare(strict_types=1);

namespace Odden\Service\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Odden\Core\Support\UserModel;

/**
 * @property int $id
 * @property string $title
 * @property string $shortcut
 * @property string $category
 * @property string $content
 * @property int|null $user_id
 * @property bool $is_shared
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Model|null $user
 */
class CannedResponse extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'shortcut',
        'category',
        'content',
        'user_id',
        'is_shared',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-service.tables.canned_responses', 'odden_service_canned_responses');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_shared' => 'boolean',
        ];
    }

    /**
     * The user / agent who created this canned response.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        $userModel = UserModel::className();

        return $this->belongsTo($userModel, 'user_id');
    }

    /**
     * Responses an agent may use: every shared one, plus the agent's own.
     *
     * @param  Builder<CannedResponse>  $query
     * @return Builder<CannedResponse>
     */
    public function scopeAvailableTo(Builder $query, int|string|null $userId): Builder
    {
        return $query->where(function (Builder $query) use ($userId): void {
            $query->where('is_shared', true);

            if ($userId !== null) {
                $query->orWhere('user_id', $userId);
            }
        });
    }

    /**
     * The content with `{{ tag }}` variables filled in for a ticket and the replying agent.
     *
     * Tags: contact.first_name, contact.last_name, contact.name, contact.email, company.name,
     * ticket.number, ticket.subject, ticket.status and agent.name. Spaces inside the braces are
     * optional, and tags that aren't known (or have no value) are left as written.
     */
    public function render(?Ticket $ticket = null, ?Model $agent = null): string
    {
        $contact = $ticket?->contact;

        $values = array_filter([
            'contact.first_name' => $contact?->first_name,
            'contact.last_name' => $contact?->last_name,
            'contact.name' => $contact !== null ? trim($contact->first_name.' '.$contact->last_name) : null,
            'contact.email' => $contact?->email,
            'company.name' => $ticket?->company?->name,
            'ticket.number' => $ticket?->ticket_number,
            'ticket.subject' => $ticket?->subject,
            'ticket.status' => $ticket?->status->label(),
            'agent.name' => $agent !== null ? UserModel::displayName($agent, '') : null,
        ], fn (mixed $value): bool => is_string($value) && $value !== '');

        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+\.[a-z_]+)\s*\}\}/i',
            fn (array $m): string => $values[strtolower($m[1])] ?? $m[0],
            $this->content
        );
    }
}
