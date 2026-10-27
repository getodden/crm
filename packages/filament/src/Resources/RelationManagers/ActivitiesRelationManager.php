<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Odden\Core\Actions\LogActivityAction;
use Odden\Core\Actions\RecordMentionsAction;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Models\Activity;
use Odden\Core\Support\Mentions;
use Odden\Filament\Support\AreaGuard;
use Odden\Filament\Support\MentionEditor;
use Odden\Filament\Support\OddenAuthorization;

class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $recordTitleAttribute = 'title';

    protected static bool $isLazy = false;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->label('Activity type')
                ->options(collect(ActivityType::cases())->mapWithKeys(
                    fn (ActivityType $type) => [$type->value => $type->label()]
                ))
                ->default(ActivityType::Note->value)
                ->required(),
            AreaGuard::field(TextInput::make('title'), Activity::class)
                ->label('Subject / title')
                ->required()
                ->maxLength(255),
            AreaGuard::field(MentionEditor::make('body'), Activity::class)
                ->label('Details / body'),
            Select::make('status')
                ->label('Status')
                ->options(collect(ActivityStatus::cases())->mapWithKeys(
                    fn (ActivityStatus $status) => [$status->value => $status->label()]
                ))
                ->default(ActivityStatus::Completed->value)
                ->required(),
            DateTimePicker::make('due_at')
                ->label('Due date')
                ->nullable(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('mentions'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof ActivityType ? $state->label() : ucfirst((string) $state))
                    ->color(fn ($state): string => match ($state instanceof ActivityType ? $state : ActivityType::tryFrom((string) $state)) {
                        ActivityType::Task => 'warning',
                        ActivityType::Call => 'info',
                        ActivityType::Meeting => 'success',
                        default => 'gray',
                    }),
                AreaGuard::column(TextColumn::make('title'), Activity::class)
                    ->label('Subject')
                    ->weight('bold')
                    ->description(fn (Activity $record): ?HtmlString => AreaGuard::masked(Activity::class, 'body', $record) ? null : Mentions::highlight($record->body, Mentions::mentionedNames($record))),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof ActivityStatus ? $state->label() : ucfirst((string) $state))
                    ->color(fn ($state): string => match ($state instanceof ActivityStatus ? $state : ActivityStatus::tryFrom((string) $state)) {
                        ActivityStatus::Completed => 'success',
                        ActivityStatus::Cancelled => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('due_at')
                    ->label('Due date')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('creator.name')
                    ->label('Logged by')
                    ->placeholder('System')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Logged at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Log activity')
                    // Through LogActivityAction, so ActivityLogged fires and completed_at is set as everywhere else.
                    ->using(function (array $data): Activity {
                        $userId = OddenAuthorization::userId();
                        $userId = is_numeric($userId) ? (int) $userId : null;
                        $dueAt = filled($data['due_at'] ?? null) ? Carbon::parse($data['due_at']) : null;
                        $body = MentionEditor::parse($data['body'] ?? null);

                        // The note and its mentions are saved together, or not at all.
                        return DB::transaction(function () use ($data, $body, $dueAt, $userId): Activity {
                            $activity = app(LogActivityAction::class)->execute(
                                subject: $this->getOwnerRecord(),
                                type: (string) $data['type'],
                                title: (string) $data['title'],
                                body: $body['text'] !== '' ? $body['text'] : null,
                                status: (string) $data['status'],
                                dueAt: $dueAt,
                                creatorId: $userId,
                            );

                            app(RecordMentionsAction::class)->execute($activity, $this->getOwnerRecord(), $body['user_ids'], $userId, $body['labels']);

                            return $activity;
                        });
                    }),
            ])
            ->recordActions([
                Action::make('complete')
                    ->label('Complete')
                    ->icon(Heroicon::CheckCircle)
                    ->color('success')
                    ->visible(fn (Activity $record): bool => $record->status === ActivityStatus::Pending)
                    ->authorize(OddenAuthorization::forRecord('update'))
                    ->action(fn (Activity $record) => $record->update([
                        'status' => ActivityStatus::Completed,
                        'completed_at' => now(),
                    ])),
                EditAction::make()
                    // The body comes back from the editor as a document: kept as plain text, the people picked in it beside it.
                    ->using(function (Activity $record, array $data): Activity {
                        if (! array_key_exists('body', $data)) {
                            $record->update($data);

                            return $record;
                        }

                        $body = MentionEditor::parse($data['body']);
                        $userId = OddenAuthorization::userId();

                        return DB::transaction(function () use ($record, $data, $body, $userId): Activity {
                            $record->update(['body' => $body['text'] !== '' ? $body['text'] : null] + $data);
                            app(RecordMentionsAction::class)->execute($record, $this->getOwnerRecord(), $body['user_ids'], is_numeric($userId) ? (int) $userId : null, $body['labels']);

                            return $record;
                        });
                    }),
                DeleteAction::make(),
            ]);
    }
}
