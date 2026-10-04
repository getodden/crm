<?php

declare(strict_types=1);

namespace Odden\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Odden\Core\Actions\MergeContactsAction;
use Odden\Core\Actions\SummarizeTimelineAction;
use Odden\Core\Contracts\TenantContext;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Contact;
use Odden\Core\Support\UserModel;
use Odden\Filament\Resources\ContactResource\Pages\CreateContact;
use Odden\Filament\Resources\ContactResource\Pages\EditContact;
use Odden\Filament\Resources\ContactResource\Pages\ListContacts;
use Odden\Filament\Resources\ContactResource\Pages\ViewContact;
use Odden\Filament\Resources\ContactResource\RelationManagers\CompaniesRelationManager;
use Odden\Filament\Resources\ContactResource\RelationManagers\FormSubmissionsRelationManager;
use Odden\Filament\Resources\ContactResource\RelationManagers\LeadScoreLogsRelationManager;
use Odden\Filament\Resources\ContactResource\RelationManagers\MarketingCampaignsRelationManager;
use Odden\Filament\Resources\ContactResource\RelationManagers\SalesSequenceEnrollmentsRelationManager;
use Odden\Filament\Resources\RelationManagers\ActivitiesRelationManager;
use Odden\Filament\Resources\RelationManagers\DealsRelationManager;
use Odden\Filament\Resources\RelationManagers\PropertyHistoryRelationManager;
use Odden\Filament\Support\CustomPropertyFieldBuilder;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Filament\Support\OddenPackages;
use Odden\Marketing\Models\Campaign;
use Odden\Sales\Actions\ExecuteSalesPlaybookAction;
use Odden\Sales\Actions\RouteLeadAction;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\SalesPlaybook;
use Odden\Sales\Models\SalesSequence;
use UnitEnum;

class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::UserGroup;

    protected static UnitEnum|string|null $navigationGroup = 'CRM';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Contact Details')
                    ->schema([
                        TextInput::make('first_name')
                            ->label('First Name')
                            ->maxLength(255),
                        TextInput::make('last_name')
                            ->label('Last Name')
                            ->maxLength(255),
                        TextInput::make('job_title')
                            ->label('Job Title')
                            ->placeholder('e.g. VP of Sales')
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email Address')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label('Phone Number')
                            ->tel()
                            ->maxLength(50),
                        TextInput::make('linkedin_url')
                            ->label('LinkedIn Profile URL')
                            ->url()
                            ->placeholder('https://linkedin.com/in/...'),
                        Select::make('lifecycle_stage')
                            ->label('Lifecycle Stage')
                            ->options(collect(LifecycleStage::cases())->mapWithKeys(
                                fn (LifecycleStage $stage) => [$stage->value => $stage->label()]
                            ))
                            ->default(LifecycleStage::Lead->value)
                            ->required(),
                        Select::make('lead_status')
                            ->label('Lead Outreach Status')
                            ->options(collect(LeadStatus::cases())->mapWithKeys(
                                fn (LeadStatus $status) => [$status->value => $status->label()]
                            ))
                            ->default(LeadStatus::New->value)
                            ->required(),
                        Select::make('owner_id')
                            ->label('Contact Owner')
                            // A relationship select queries the user model directly; keep it to the users this
                            // installation offers (a multi-tenant host narrows UserModel::query() to the active tenant).
                            ->relationship('owner', 'name', modifyQueryUsing: fn (Builder $query): Builder => app(TenantContext::class)->scopeUsers($query))
                            ->searchable()
                            ->preload(),
                    ])
                    ->columns(2),
                Section::make('Marketing & Lead Qualification')
                    ->schema([
                        TextInput::make('lead_score')
                            ->label('Lead Score')
                            ->numeric()
                            ->suffix('pts')
                            ->default(0),
                        DateTimePicker::make('marketing_email_verified_at')
                            ->label('Email Verified Date')
                            ->helperText('Confirmed via double opt-in email verification'),
                        DateTimePicker::make('last_marketing_email_sent_at')
                            ->label('Last Marketing Email')
                            ->disabled(),
                    ])
                    ->columns(3),
                ...CustomPropertyFieldBuilder::makeSection('contact'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')
                    ->label('Name')
                    ->description(fn (Contact $record): ?string => $record->job_title)
                    ->searchable(['first_name', 'last_name', 'email'])
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('Phone')
                    ->searchable(),
                TextColumn::make('lead_status')
                    ->label('Lead Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof LeadStatus ? $state->label() : ucfirst((string) $state))
                    ->color(fn ($state): string => match ($state instanceof LeadStatus ? $state : LeadStatus::tryFrom((string) $state)) {
                        LeadStatus::Connected => 'success',
                        LeadStatus::InProgress, LeadStatus::AttemptedContact => 'warning',
                        LeadStatus::Unqualified => 'danger',
                        LeadStatus::New => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('last_contacted_at')
                    ->label('Last Touch')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('lead_score')
                    ->label('Score')
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 100 => 'danger',
                        $state >= 50 => 'success',
                        $state >= 20 => 'info',
                        default => 'gray',
                    })
                    ->suffix(' pts')
                    ->sortable(),
                TextColumn::make('lifecycle_stage')
                    ->label('Lifecycle Stage')
                    ->badge()
                    ->formatStateUsing(fn (LifecycleStage|string|null $state): string => $state instanceof LifecycleStage ? $state->label() : ($state ?? '—'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sunset_stage')
                    ->label('List Hygiene')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'suppressed' => 'Suppressed (Sunset)',
                        'reengagement_sent' => 'Re-engagement Sent',
                        'flagged' => 'Dormant (Flagged)',
                        default => 'Active',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'suppressed' => 'danger',
                        'reengagement_sent' => 'warning',
                        'flagged' => 'gray',
                        default => 'success',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                ...CustomPropertyFieldBuilder::searchableColumns('contact'),
            ])
            ->filters([
                SelectFilter::make('lead_status')
                    ->label('Lead Status')
                    ->options(collect(LeadStatus::cases())->mapWithKeys(
                        fn (LeadStatus $status) => [$status->value => $status->label()]
                    )),
                SelectFilter::make('lifecycle_stage')
                    ->options(collect(LifecycleStage::cases())->mapWithKeys(
                        fn (LifecycleStage $stage) => [$stage->value => $stage->label()]
                    )),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('run_playbook')
                    ->label('Playbook')
                    ->icon(Heroicon::BookOpen)
                    ->color('primary')
                    ->visible(fn (): bool => OddenPackages::hasSales())
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->form(function (): array {
                        $playbooks = SalesPlaybook::query()->where('is_active', true)->get();
                        if ($playbooks->isEmpty()) {
                            return [
                                TextInput::make('no_playbooks')
                                    ->label('Notice')
                                    ->disabled()
                                    ->default('No active playbooks found. Create one in Sales > Playbooks first.'),
                            ];
                        }

                        return [
                            Select::make('playbook_id')
                                ->label('Playbook')
                                ->options($playbooks->pluck('name', 'id'))
                                ->required()
                                ->live(),
                            Group::make()
                                ->schema(function (callable $get): array {
                                    $playbookId = $get('playbook_id');
                                    if (! $playbookId) {
                                        return [];
                                    }

                                    /** @var SalesPlaybook|null $playbook */
                                    $playbook = SalesPlaybook::find($playbookId);
                                    if (! $playbook || empty($playbook->questions)) {
                                        return [];
                                    }

                                    $fields = [];
                                    foreach ($playbook->questions as $q) {
                                        $id = 'answers.'.$q['id'];
                                        $label = $q['label'];
                                        if ($q['type'] === 'textarea') {
                                            $fields[] = Textarea::make($id)->label($label)->rows(3);
                                        } else {
                                            $fields[] = TextInput::make($id)->label($label);
                                        }
                                    }

                                    return $fields;
                                }),
                        ];
                    })
                    ->action(function (Contact $record, array $data): void {
                        if (empty($data['playbook_id'])) {
                            return;
                        }

                        /** @var SalesPlaybook $playbook */
                        $playbook = SalesPlaybook::findOrFail($data['playbook_id']);
                        $answers = isset($data['answers']) && is_array($data['answers']) ? $data['answers'] : [];

                        app(ExecuteSalesPlaybookAction::class)->execute($record, $playbook, $answers, OddenAuthorization::userId());

                        Notification::make()
                            ->title('Playbook Completed')
                            ->body("Recorded [{$playbook->name}] notes on {$record->full_name}.")
                            ->success()
                            ->send();
                    }),
                Action::make('route_lead')
                    ->label('Auto-Route')
                    ->icon(Heroicon::ArrowsRightLeft)
                    ->color('gray')
                    ->visible(fn (): bool => OddenPackages::hasSales())
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->requiresConfirmation()
                    ->modalHeading('Auto-Route Contact Owner')
                    ->modalDescription('Run active lead routing rules to assign this contact to a sales representative based on criteria, round-robin, or quota attainment.')
                    ->action(function (Contact $record): void {
                        $result = app(RouteLeadAction::class)->execute($record);

                        if ($result !== null) {
                            /** @var object{name: string}|null $user */
                            $user = UserModel::query()->find($result['assigned_user_id']);
                            $name = $user !== null ? $user->name : "User #{$result['assigned_user_id']}";

                            Notification::make()
                                ->title('Contact Routed')
                                ->body("Assigned to {$name} via rule [{$result['rule']->name}].")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('No Routing Match')
                                ->body('No active lead routing rules matched this contact.')
                                ->warning()
                                ->send();
                        }
                    }),
                Action::make('ai_briefing')
                    ->label('AI Briefing')
                    ->icon(Heroicon::Sparkles)
                    ->color('info')
                    ->authorize(OddenAuthorization::forRecord('view', self::class))
                    ->modalHeading(fn (Contact $record): string => "Odden Breeze: {$record->full_name}")
                    ->modalDescription('AI timeline and relationship intelligence summary.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (Contact $record) => view('odden-filament::components.ai-briefing-modal', [
                        'briefing' => app(SummarizeTimelineAction::class)->execute($record),
                    ])),
                Action::make('merge')
                    ->label('Merge')
                    ->icon(Heroicon::ArrowsRightLeft)
                    ->color('warning')
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->modalHeading('Merge Duplicate Contact')
                    ->modalDescription('Merge another duplicate contact into this record. All activities, deals, tickets, and associations will be reparented and preserved.')
                    ->form([
                        Select::make('secondary_contact_id')
                            ->label('Select Duplicate Contact to Merge into This Record')
                            ->options(fn (Contact $record): array => OddenAuthorization::query(self::class, Contact::class)
                                ->whereKeyNot($record->getKey())
                                ->orderBy('last_name')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (Contact $c): array => [$c->id => "{$c->first_name} {$c->last_name} ({$c->email})"])
                                ->all())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Contact $record, array $data): void {
                        // The duplicate is deleted by the merge, so it needs `delete` as well.
                        $secondary = OddenAuthorization::findAndAuthorize(self::class, Contact::class, $data['secondary_contact_id'], 'delete');
                        abort_if($secondary->is($record), 422);

                        app(MergeContactsAction::class)->execute($record, $secondary);

                        Notification::make()
                            ->title('Contacts Merged')
                            ->body("Merged duplicate {$secondary->email} into this record.")
                            ->success()
                            ->send();
                    }),
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        $relations = [
            CompaniesRelationManager::class,
        ];

        if (class_exists(Deal::class)) {
            $relations[] = DealsRelationManager::class;
        }

        if (class_exists(SalesSequence::class)) {
            $relations[] = SalesSequenceEnrollmentsRelationManager::class;
        }

        if (class_exists(Campaign::class)) {
            $relations[] = MarketingCampaignsRelationManager::class;
            $relations[] = FormSubmissionsRelationManager::class;
            $relations[] = LeadScoreLogsRelationManager::class;
        }

        $relations[] = ActivitiesRelationManager::class;
        $relations[] = PropertyHistoryRelationManager::class;

        return $relations;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContacts::route('/'),
            'create' => CreateContact::route('/create'),
            'view' => ViewContact::route('/{record}'),
            'edit' => EditContact::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
