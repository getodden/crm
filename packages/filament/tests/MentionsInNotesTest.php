<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Odden\Core\Areas\AreaAccess;
use Odden\Core\Areas\Areas;
use Odden\Core\Areas\DataArea;
use Odden\Core\Contracts\TenantContext;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Events\UserMentioned;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Core\Models\Mention;
use Odden\Filament\Resources\ContactResource\Pages\EditContact;
use Odden\Filament\Resources\RelationManagers\ActivitiesRelationManager;
use Odden\Filament\Support\MentionEditor;
use Odden\Filament\Tests\Fixtures\User;

/**
 * A document as the editor sends it: text, and mentions of people by id.
 *
 * @param  list<string|array{0: int, 1: string}>  $pieces  Text, or [id, label] for a mention.
 * @return array<string, mixed>
 */
function noteDocument(array $pieces): array
{
    return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => array_map(
        fn (string|array $piece): array => is_string($piece)
            ? ['type' => 'text', 'text' => $piece]
            : ['type' => 'mention', 'attrs' => ['id' => (string) $piece[0], 'label' => $piece[1], 'char' => '@']],
        $pieces,
    )]]];
}

it('keeps a note as plain text and the people picked in it by id, and tells each of them once', function (): void {
    Event::fake([UserMentioned::class]);
    $author = User::factory()->create(['name' => 'Ann Author']);
    $jane = User::factory()->create(['name' => 'Jane Doe']);
    $john = User::factory()->create(['name' => 'John Roe']);
    $contact = Contact::factory()->create();

    $component = Livewire::actingAs($author)
        ->test(ActivitiesRelationManager::class, ['ownerRecord' => $contact, 'pageClass' => EditContact::class])
        ->callAction(TestAction::make('create')->table(), [
            'type' => ActivityType::Note->value,
            'title' => 'Renewal',
            'status' => ActivityStatus::Completed->value,
            // A forged label is replaced with the person's real name.
            'body' => noteDocument(['Ask ', [$jane->id, 'The CEO'], ' about the renewal']),
        ])
        ->assertHasNoFormErrors();

    $note = Activity::query()->sole();
    expect($note->body)->toBe('Ask @Jane Doe about the renewal')
        ->and(Mention::query()->where('mentionable_id', $note->id)->pluck('user_id')->all())->toBe([$jane->id]);
    Event::assertDispatchedTimes(UserMentioned::class, 1);
    Event::assertDispatched(UserMentioned::class, fn (UserMentioned $event): bool => $event->mentionedUserId === $jane->id
        && $event->actorId === $author->id && $event->subject->is($contact) && $event->mentionable->is($note));

    // Editing it opens with the mention in place; adding John tells John only.
    $component->mountAction(TestAction::make('edit')->table($note))
        ->assertSchemaStateSet(['body' => MentionEditor::document('Ask @Jane Doe about the renewal', $note)])
        ->callMountedAction();
    Event::assertDispatchedTimes(UserMentioned::class, 1);

    $component->callAction(TestAction::make('edit')->table($note), [
        'body' => noteDocument(['Ask ', [$jane->id, 'Jane Doe'], ' and ', [$john->id, 'John Roe']]),
    ])->assertHasNoFormErrors();

    expect($note->refresh()->body)->toBe('Ask @Jane Doe and @John Roe');
    Event::assertDispatchedTimes(UserMentioned::class, 2);
    Event::assertDispatched(UserMentioned::class, fn (UserMentioned $event): bool => $event->mentionedUserId === $john->id);
});

it('shows a mention in bold and everything else in the note as text', function (): void {
    $jane = User::factory()->create(['name' => 'Jane Doe']);
    $contact = Contact::factory()->create();

    Livewire::actingAs($jane)
        ->test(ActivitiesRelationManager::class, ['ownerRecord' => $contact, 'pageClass' => EditContact::class])
        ->callAction(TestAction::make('create')->table(), [
            'type' => ActivityType::Note->value,
            'title' => 'Escaping',
            'status' => ActivityStatus::Completed->value,
            'body' => noteDocument(['<b>loud</b> ', [$jane->id, 'Jane Doe']]),
        ])
        ->assertSeeHtml('&lt;b&gt;loud&lt;/b&gt; <strong>@Jane Doe</strong>')
        ->assertDontSeeHtml('<b>loud</b>');
});

it('turns the editor document into text, one line per paragraph, and back', function (): void {
    $jane = User::factory()->create(['name' => 'Jane Doe']);
    $document = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hi '], ['type' => 'mention', 'attrs' => ['id' => (string) $jane->id, 'label' => 'Jane Doe', 'char' => '@']]]],
        ['type' => 'paragraph'],
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'one']]]]]]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'a'], ['type' => 'hardBreak'], ['type' => 'text', 'text' => 'b']]],
        // Not a teammate: stays as text, mentions no one.
        ['type' => 'paragraph', 'content' => [['type' => 'mention', 'attrs' => ['id' => '999999', 'label' => 'Ghost', 'char' => '@']]]],
    ]];

    expect(MentionEditor::parse($document))->toBe(['text' => "Hi @Jane Doe\n\none\na\nb\n@Ghost", 'user_ids' => [$jane->id]])
        ->and(MentionEditor::parse('plain'))->toBe(['text' => 'plain', 'user_ids' => []])
        ->and(MentionEditor::document("Line one\n\nLine two")['content'])->toBe([
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Line one']]],
            ['type' => 'paragraph'],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Line two']]],
        ]);
});

it('opens a note outside the person\'s data areas with an empty editor', function (): void {
    $contact = Contact::factory()->create();
    $note = $contact->logNote('Private pricing talk');
    app()->instance(AreaAccess::class, new class implements AreaAccess
    {
        public function allows(DataArea $area, int|string|null $userId = null): bool
        {
            return $area === DataArea::Identity;
        }

        public function mayReveal(DataArea $area, int|string|null $userId = null): bool
        {
            return false;
        }
    });
    app()->forgetInstance(Areas::class);

    Livewire::actingAs(User::factory()->create())
        ->test(ActivitiesRelationManager::class, ['ownerRecord' => $contact, 'pageClass' => EditContact::class])
        ->mountAction(TestAction::make('edit')->table($note))
        ->assertSchemaStateSet(function (array $state): array {
            expect(json_encode($state))->not->toContain('Private pricing talk');

            return [];
        });
});

it('offers only the active tenant\'s members when typing @ in a note', function (): void {
    $jane = User::factory()->create(['name' => 'Jane Doe']);
    $elsewhere = User::factory()->create(['name' => 'Jane Elsewhere']);
    $contact = Contact::factory()->create();
    $searchAs = function (array $members) use ($jane, $contact): array {
        app()->instance(TenantContext::class, new class($members) implements TenantContext
        {
            /** @param list<int> $members */
            public function __construct(private array $members) {}

            public function id(): int
            {
                return $this->members[0];
            }

            public function scopeUsers(Builder $users): Builder
            {
                return $users->whereIn('id', $this->members);
            }
        });

        $component = Livewire::actingAs($jane)
            ->test(ActivitiesRelationManager::class, ['ownerRecord' => $contact, 'pageClass' => EditContact::class])
            ->mountAction(TestAction::make('create')->table());

        return array_column((array) $component->instance()->callSchemaComponentMethod('mountedActionSchema0.body', 'getMentionSearchResultsForJs', ['search' => 'jane']), 'label');
    };

    expect($searchAs([$jane->id]))->toBe(['Jane Doe'])
        ->and($searchAs([$elsewhere->id]))->toBe(['Jane Elsewhere']);
});
