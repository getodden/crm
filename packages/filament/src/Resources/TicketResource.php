<?php

declare(strict_types=1);

namespace Odden\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Support\UserModel;
use Odden\Filament\Resources\TicketResource\Pages\CreateTicket;
use Odden\Filament\Resources\TicketResource\Pages\EditTicket;
use Odden\Filament\Resources\TicketResource\Pages\KanbanTickets;
use Odden\Filament\Resources\TicketResource\Pages\ListTickets;
use Odden\Filament\Resources\TicketResource\RelationManagers\MessagesRelationManager;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Service\Actions\MergeTicketsAction;
use Odden\Service\Actions\ResolveTicketAction;
use Odden\Service\Actions\RouteTicketAction;
use Odden\Service\Enums\TicketPriority;
use Odden\Service\Enums\TicketSource;
use Odden\Service\Enums\TicketStatus;
use Odden\Service\Models\SlaPolicy;
use Odden\Service\Models\Ticket;
use UnitEnum;

class TicketResource extends Resource
{
    protected static ?string $model = Ticket::class;

    protected static ?string $modelLabel = 'Ticket';

    protected static ?string $pluralModelLabel = 'Tickets';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Ticket;

    protected static UnitEnum|string|null $navigationGroup = 'Service';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ticket Details')
                    ->schema([
                        TextInput::make('ticket_number')
                            ->label('Ticket #')
                            ->disabled()
                            ->dehydrated(false)
                            ->visibleOn('edit'),
                        TextInput::make('subject')
                            ->label('Subject')
                            ->placeholder('Brief summary of the issue')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Select::make('priority')
                            ->label('Priority')
                            ->options(collect(TicketPriority::cases())->mapWithKeys(
                                fn (TicketPriority $priority): array => [$priority->value => $priority->label()]
                            )->all())
                            ->default(TicketPriority::Medium->value)
                            ->required(),
                        Select::make('status')
                            ->label('Status')
                            ->options(collect(TicketStatus::cases())->mapWithKeys(
                                fn (TicketStatus $status): array => [$status->value => $status->label()]
                            )->all())
                            ->default(TicketStatus::New->value)
                            ->required(),
                        Select::make('source')
                            ->label('Inbound Channel')
                            ->options(collect(TicketSource::cases())->mapWithKeys(
                                fn (TicketSource $source): array => [$source->value => $source->label()]
                            )->all())
                            ->default(TicketSource::WebPortal->value)
                            ->required(),
                        Select::make('owner_id')
                            ->label('Assigned Support Agent')
                            ->options(fn (): array => UserModel::query()->pluck('name', 'id')->all())
                            ->searchable(),
                        Select::make('contact_id')
                            ->label('Customer Contact')
                            ->options(fn (): array => Contact::query()->get()->pluck('full_name', 'id')->all())
                            ->searchable(),
                        Select::make('company_id')
                            ->label('Customer Company')
                            ->options(fn (): array => Company::query()->pluck('name', 'id')->all())
                            ->searchable(),
                        Select::make('sla_policy_id')
                            ->label('SLA Policy')
                            ->options(fn (): array => SlaPolicy::query()->pluck('name', 'id')->all())
                            ->searchable(),
                    ])
                    ->columns(3),

                Section::make('Problem Description')
                    ->schema([
                        Textarea::make('description')
                            ->label('Initial Customer Inquiry')
                            ->placeholder('Detailed description of the customer issue...')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),

                Section::make('SLA & Resolution Info')
                    ->collapsed()
                    ->schema([
                        DateTimePicker::make('first_response_due_at')
                            ->label('First Response Due')
                            ->disabled(),
                        DateTimePicker::make('first_responded_at')
                            ->label('First Responded At')
                            ->disabled(),
                        DateTimePicker::make('resolution_due_at')
                            ->label('Resolution Due')
                            ->disabled(),
                        DateTimePicker::make('resolved_at')
                            ->label('Resolved At')
                            ->disabled(),
                        TextInput::make('csat_rating')
                            ->label('CSAT Score (1-5)')
                            ->disabled(),
                        Textarea::make('csat_comment')
                            ->label('CSAT Feedback')
                            ->disabled()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ticket_number')
                    ->label('#')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('subject')
                    ->label('Subject')
                    ->weight('bold')
                    ->searchable()
                    ->limit(60),
                TextColumn::make('contact.full_name')
                    ->label('Customer')
                    ->searchable(),
                TextColumn::make('company.name')
                    ->label('Company')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof TicketStatus ? $state->label() : (TicketStatus::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn ($state): string => ($state instanceof TicketStatus ? $state : TicketStatus::tryFrom((string) $state))?->color() ?? 'gray'),
                TextColumn::make('priority')
                    ->label('Priority')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof TicketPriority ? $state->label() : (TicketPriority::tryFrom((string) $state)?->label() ?? (string) $state))
                    ->color(fn ($state): string => ($state instanceof TicketPriority ? $state : TicketPriority::tryFrom((string) $state))?->color() ?? 'gray'),
                TextColumn::make('first_response_due_at')
                    ->label('Response SLA')
                    ->since()
                    ->sortable()
                    ->color(fn (Ticket $record): string => $record->isFirstResponseBreached() ? 'danger' : 'gray'),
                TextColumn::make('owner.name')
                    ->label('Agent')
                    ->searchable()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('resolveTicket')
                    ->label('Resolve')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->visible(fn (Ticket $record): bool => ! $record->status->isClosed())
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->form([
                        Textarea::make('resolution_note')
                            ->label('Resolution Summary Note')
                            ->placeholder('Describe the resolution provided to the customer...')
                            ->required(),
                    ])
                    ->action(function (Ticket $record, array $data): void {
                        app(ResolveTicketAction::class)->execute($record, (string) $data['resolution_note']);
                    }),
                Action::make('mergeTicket')
                    ->label('Merge')
                    ->icon('heroicon-m-arrows-pointing-in')
                    ->color('gray')
                    ->visible(fn (Ticket $record): bool => ! $record->status->isClosed() && $record->merged_into_ticket_id === null)
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->form([
                        Select::make('primary_ticket_id')
                            ->label('Primary Ticket (Destination)')
                            ->helperText('Select the destination ticket to merge this ticket into.')
                            ->options(fn (Ticket $record): array => OddenAuthorization::query(self::class, Ticket::class)
                                ->whereKeyNot($record->getKey())
                                ->whereNull('merged_into_ticket_id')
                                ->where('status', '!=', TicketStatus::Closed->value)
                                ->latest()
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (Ticket $t): array => [$t->id => "#{$t->ticket_number} - {$t->subject}"])
                                ->all())
                            ->searchable()
                            ->required(),
                        Textarea::make('merge_reason')
                            ->label('Reason for Merge')
                            ->placeholder('e.g. Duplicate customer inquiry submitted via email and portal.')
                            ->rows(2),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading('Merge Duplicate Ticket')
                    ->modalDescription('This action will transfer all conversation messages to the primary ticket and close this ticket.')
                    ->action(function (Ticket $record, array $data): void {
                        // The destination ticket receives this ticket's messages, so it needs `update` as well.
                        $primaryTicket = OddenAuthorization::findAndAuthorize(self::class, Ticket::class, (int) $data['primary_ticket_id'], 'update');
                        abort_if($primaryTicket->is($record), 422);
                        $reason = ! empty($data['merge_reason']) ? (string) $data['merge_reason'] : null;
                        $userId = (int) OddenAuthorization::userId();
                        app(MergeTicketsAction::class)->execute($primaryTicket, $record, $reason, $userId);
                    }),
                Action::make('portalLink')
                    ->label('Portal')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (Ticket $record): string => $record->getPortalUrl())
                    ->openUrlInNewTab(),
                Action::make('routeTicket')
                    ->label('Auto-Route')
                    ->icon('heroicon-m-arrows-right-left')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->modalHeading('Auto-Route Ticket Agent')
                    ->modalDescription('Run active ticket routing rules to assign this ticket to an available agent based on channel, priority, and keywords.')
                    ->action(function (Ticket $record): void {
                        $result = app(RouteTicketAction::class)->execute($record);

                        if ($result !== null) {
                            /** @var object{name: string}|null $user */
                            $user = UserModel::query()->find($result['assigned_user_id']);
                            $agentName = $user !== null ? $user->name : "Agent #{$result['assigned_user_id']}";

                            Notification::make()
                                ->title('Ticket Assigned')
                                ->body("Ticket #{$record->ticket_number} assigned to {$agentName} via rule [{$result['rule']->name}].")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('No Routing Match')
                                ->body('No active ticket routing rules matched this ticket.')
                                ->warning()
                                ->send();
                        }
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MessagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'board' => KanbanTickets::route('/board'),
            'create' => CreateTicket::route('/create'),
            'edit' => EditTicket::route('/{record}/edit'),
        ];
    }
}
