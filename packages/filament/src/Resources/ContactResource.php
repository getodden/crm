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
use Filament\Forms\Components\DatePicker;
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
use Odden\Core\Areas\Areas;
use Odden\Core\Contracts\SummarizesTimeline;
use Odden\Core\Contracts\TenantContext;
use Odden\Core\Enums\EmailStatus;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Contact;
use Odden\Core\Support\UserModel;
use Odden\Filament\Resources\ContactResource\Pages\CreateContact;
use Odden\Filament\Resources\ContactResource\Pages\EditContact;
use Odden\Filament\Resources\ContactResource\Pages\ListContacts;
use Odden\Filament\Resources\ContactResource\Pages\ViewContact;
use Odden\Filament\Resources\ContactResource\RelationManagers\CompaniesRelationManager;
use Odden\Filament\Resources\ContactResource\RelationManagers\SalesSequenceEnrollmentsRelationManager;
use Odden\Filament\Resources\RelationManagers\ActivitiesRelationManager;
use Odden\Filament\Resources\RelationManagers\DealsRelationManager;
use Odden\Filament\Resources\RelationManagers\PropertyHistoryRelationManager;
use Odden\Filament\Support\AreaGuard;
use Odden\Filament\Support\CustomPropertyFieldBuilder;
use Odden\Filament\Support\EncryptedSearch;
use Odden\Filament\Support\Modules;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Filament\Support\OddenPackages;
use Odden\Sales\Actions\ExecuteSalesPlaybookAction;
use Odden\Sales\Actions\RouteLeadAction;
use Odden\Sales\Models\SalesPlaybook;
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
                        AreaGuard::field(TextInput::make('first_name'), Contact::class)
                            ->label('First name')
                            ->maxLength(255),
                        AreaGuard::field(TextInput::make('last_name'), Contact::class)
                            ->label('Last name')
                            ->maxLength(255),
                        AreaGuard::field(TextInput::make('job_title'), Contact::class)
                            ->label('Job title')
                            ->placeholder('e.g. VP of Sales')
                            ->maxLength(255),
                        AreaGuard::field(TextInput::make('email'), Contact::class)
                            ->label('Email address')
                            ->email()
                            ->required(fn (?Contact $record): bool => ! AreaGuard::masked(Contact::class, 'email', $record))
                            ->maxLength(255),
                        AreaGuard::field(TextInput::make('phone'), Contact::class)
                            ->label('Phone number')
                            ->tel()
                            ->maxLength(50),
                        AreaGuard::field(DatePicker::make('date_of_birth'), Contact::class)
                            ->label('Date of birth')
                            ->native(false)
                            ->maxDate(now())
                            ->helperText('Optional. Used to keep messages of the wrong kind from reaching a child. It is personal data: leave it empty if you do not need it.'),
                        AreaGuard::field(TextInput::make('linkedin_url'), Contact::class)
                            ->label('LinkedIn profile URL')
                            ->url()
                            ->placeholder('https://linkedin.com/in/...'),
                        Select::make('lifecycle_stage')
                            ->label('Lifecycle stage')
                            ->options(collect(LifecycleStage::cases())->mapWithKeys(
                                fn (LifecycleStage $stage) => [$stage->value => $stage->label()]
                            ))
                            ->default(LifecycleStage::Lead->value)
                            ->required(),
                        Select::make('lead_status')
                            ->label('Lead outreach status')
                            ->options(collect(LeadStatus::cases())->mapWithKeys(
                                fn (LeadStatus $status) => [$status->value => $status->label()]
                            ))
                            ->default(LeadStatus::New->value)
                            ->required(),
                        Select::make('owner_id')
                            ->label('Contact owner')
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
                            ->label('Lead score')
                            ->numeric()
                            ->integer()
                            ->suffix('pts')
                            ->default(0)
                            // Left empty, it is 0: a quick new contact doesn't need a score.
                            ->dehydrateStateUsing(fn (mixed $state): int => (int) ($state ?? 0)),
                        // These columns come with Odden Marketing; without it (or with it turned off here) they are not shown or saved.
                        // Proof of consent: set only when the contact confirms by email, so nobody can type one in or change it here.
                        DateTimePicker::make('marketing_email_verified_at')
                            ->label('Email verified date')
                            ->helperText('Set when the contact confirms their address from the email we send. It can\'t be changed here.')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (): bool => self::marketingEnabled()),
                        DateTimePicker::make('last_marketing_email_sent_at')
                            ->label('Last marketing email')
                            ->visible(fn (): bool => self::marketingEnabled())
                            ->disabled(),
                    ])
                    ->columns(3),
                ...CustomPropertyFieldBuilder::makeSection('contact'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('No contacts yet')
            ->emptyStateDescription('Add people one at a time, or import a CSV from a spreadsheet or your old CRM.')
            ->columns([
                TextColumn::make('full_name')
                    ->label('Name')
                    ->description(fn (Contact $record): ?string => AreaGuard::masked(Contact::class, 'job_title', $record) ? null : $record->job_title)
                    ->formatStateUsing(fn (mixed $state, Contact $record): mixed => AreaGuard::masked(Contact::class, 'first_name', $record) ? Areas::MASK : $state)
                    // The names are encrypted: a search matches the start of a name (or a whole address or phone number) through the
                    // search index, and sorting decrypts the records in view and sorts them here, last name first.
                    ->searchable(query: EncryptedSearch::contacts())
                    // full_name is an accessor, not a column: sort by last name, then first name.
                    ->sortable(query: EncryptedSearch::keepsOrder()),
                AreaGuard::column(TextColumn::make('email'), Contact::class)
                    ->label('Email')
                    ->copyable(fn (Contact $record): bool => ! AreaGuard::masked(Contact::class, 'email', $record))
                    ->searchable(query: EncryptedSearch::contacts(), isIndividual: true, isGlobal: false),
                TextColumn::make('email_status')
                    ->label('Email status')
                    ->badge()
                    ->formatStateUsing(fn ($state): ?string => $state instanceof EmailStatus ? $state->label() : null)
                    ->color(fn ($state): string => $state instanceof EmailStatus ? $state->color() : 'gray')
                    ->tooltip(fn (Contact $record): ?string => $record->email_status_reason?->label())
                    ->placeholder('Not checked')
                    ->sortable()
                    ->toggleable(),
                AreaGuard::column(TextColumn::make('phone'), Contact::class)
                    ->label('Phone')
                    ->searchable(query: EncryptedSearch::contacts(), isIndividual: true, isGlobal: false),
                TextColumn::make('lead_status')
                    ->label('Lead status')
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
                    ->label('Last touch')
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
                    ->label('Lifecycle stage')
                    ->badge()
                    ->formatStateUsing(fn (LifecycleStage|string|null $state): string => $state instanceof LifecycleStage ? $state->label() : ($state ?? '—'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('sunset_stage')
                    ->label('List hygiene')
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
                    ->label('Lead status')
                    ->options(collect(LeadStatus::cases())->mapWithKeys(
                        fn (LeadStatus $status) => [$status->value => $status->label()]
                    )),
                SelectFilter::make('lifecycle_stage')
                    ->options(collect(LifecycleStage::cases())->mapWithKeys(
                        fn (LifecycleStage $stage) => [$stage->value => $stage->label()]
                    )),
                SelectFilter::make('email_status')
                    ->label('Email status')
                    ->options([
                        ...collect(EmailStatus::cases())->mapWithKeys(
                            fn (EmailStatus $status) => [$status->value => $status->label()]
                        )->all(),
                        'unchecked' => 'Not checked',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'unchecked' => $query->whereNull('email_status'),
                        default => $query->where('email_status', $data['value']),
                    }),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('run_playbook')
                    ->label('Playbook')
                    ->icon(Heroicon::BookOpen)
                    ->color('primary')
                    ->visible(fn (): bool => Modules::salesEnabled())
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
                            ->title('Playbook completed')
                            ->body("Recorded [{$playbook->name}] notes on {$record->full_name}.")
                            ->success()
                            ->send();
                    }),
                Action::make('route_lead')
                    ->label('Auto-route')
                    ->icon(Heroicon::ArrowsRightLeft)
                    ->color('gray')
                    ->visible(fn (): bool => Modules::salesEnabled())
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->requiresConfirmation()
                    ->modalHeading('Auto-route contact owner')
                    ->modalDescription('Run active lead routing rules to assign this contact to a sales representative based on criteria, round-robin, or quota attainment.')
                    ->action(function (Contact $record): void {
                        $result = app(RouteLeadAction::class)->execute($record);

                        if ($result !== null) {
                            /** @var object{name: string}|null $user */
                            $user = UserModel::query()->find($result['assigned_user_id']);
                            $name = $user !== null ? $user->name : "User #{$result['assigned_user_id']}";

                            Notification::make()
                                ->title('Contact routed')
                                ->body("Assigned to {$name} via rule [{$result['rule']->name}].")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('No routing match')
                                ->body('No active lead routing rules matched this contact.')
                                ->warning()
                                ->send();
                        }
                    }),
                Action::make('ai_briefing')
                    ->label('AI briefing')
                    ->icon(Heroicon::Sparkles)
                    ->color('info')
                    ->authorize(OddenAuthorization::forRecord('view', self::class))
                    ->modalHeading(fn (Contact $record): string => "Odden Breeze: {$record->full_name}")
                    ->modalDescription('AI timeline and relationship intelligence summary.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (Contact $record) => view('odden-filament::components.ai-briefing-modal', [
                        'briefing' => app(SummarizesTimeline::class)->execute($record),
                    ])),
                Action::make('merge')
                    ->label('Merge')
                    ->icon(Heroicon::ArrowsRightLeft)
                    ->color('warning')
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    // A record in the trash is not the one to keep: merging into it would hide both.
                    ->hidden(fn (Contact $record): bool => $record->trashed())
                    ->modalHeading('Merge a duplicate into this contact')
                    ->modalDescription('Its activities, deals, tickets and links move to this contact, and details this contact is missing are filled in from it. The duplicate is then deleted: it goes to the deleted contacts, where it can be restored, but without what moved.')
                    ->form([
                        Select::make('secondary_contact_id')
                            ->label('Duplicate to merge in')
                            ->searchable()
                            ->getSearchResultsUsing(fn (Contact $record, string $search): array => self::searchContactOptions($search, except: $record))
                            ->getOptionLabelUsing(fn (mixed $value): ?string => self::contactOptionLabel($value))
                            ->required(),
                    ])
                    ->action(function (Contact $record, array $data): void {
                        // The duplicate is deleted by the merge, so it needs `delete` as well.
                        $secondary = OddenAuthorization::findAndAuthorize(self::class, Contact::class, $data['secondary_contact_id'], 'delete');
                        abort_if($secondary->is($record), 422);

                        app(MergeContactsAction::class)->execute($record, $secondary);

                        Notification::make()
                            ->title('Contacts merged')
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

    protected static function marketingEnabled(): bool
    {
        return OddenPackages::hasMarketing() && Modules::enabledOnCurrentPanel('marketing');
    }

    /**
     * Contacts matching a picker search (name or email), within the contacts the user can see. Searching on the
     * server keeps every contact reachable, not just the first page of a preloaded list.
     *
     * @return array<int|string, string>
     */
    public static function searchContactOptions(string $search, ?Contact $except = null, int $limit = 50): array
    {
        $query = OddenAuthorization::query(static::class, Contact::class)
            ->when($except !== null, fn (Builder $query): Builder => $query->whereKeyNot($except?->getKey()))
            ->searchIdentity($search);

        return EncryptedSearch::limitedAndSorted($query, ['last_name', 'first_name'], $limit)
            ->mapWithKeys(fn (Contact $c): array => [$c->getKey() => self::formatContactOption($c)])
            ->all();
    }

    /**
     * The picker label of a selected contact, if the user can see it.
     */
    public static function contactOptionLabel(mixed $key): ?string
    {
        if (! is_int($key) && ! is_string($key)) {
            return null;
        }

        /** @var Contact|null $contact */
        $contact = OddenAuthorization::query(static::class, Contact::class)->find($key);

        return $contact !== null ? self::formatContactOption($contact) : null;
    }

    protected static function formatContactOption(Contact $contact): string
    {
        return trim("{$contact->first_name} {$contact->last_name}")." ({$contact->email})";
    }

    public static function getRelations(): array
    {
        $relations = [
            CompaniesRelationManager::class,
        ];

        if (Modules::salesEnabled()) {
            $relations[] = DealsRelationManager::class;
            $relations[] = SalesSequenceEnrollmentsRelationManager::class;
        }

        // Added by the packages that are installed separately (Marketing).
        array_push($relations, ...Modules::contactRelationManagers());

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
