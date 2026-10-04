<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\TicketResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Odden\Core\Support\UserModel;
use Odden\Filament\Resources\TicketResource;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Service\Actions\ReplyTicketAction;
use Odden\Service\Contracts\DraftsTicketReply;
use Odden\Service\Enums\MessageSenderType;
use Odden\Service\Models\CannedResponse;
use Odden\Service\Models\Ticket;
use Odden\Service\Models\TicketMessage;

class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $recordTitleAttribute = 'body';

    protected static ?string $title = 'Conversation Thread & Notes';

    protected static bool $isLazy = false;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('body')
                ->label('Message Content')
                ->required()
                ->rows(4),
            Toggle::make('is_internal_note')
                ->label('Private Internal Note (Visible to team only)'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('body')
            ->defaultSort('created_at', 'asc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Timestamp')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                TextColumn::make('sender')
                    ->label('Sender')
                    ->badge()
                    ->formatStateUsing(function ($state, TicketMessage $record): string {
                        if ($record->is_internal_note) {
                            $author = UserModel::displayName($record->user, 'Agent');

                            return "🔒 Internal Note: {$author}";
                        }

                        return ($record->sender_type === MessageSenderType::Agent ? 'Agent: ' : 'Customer: ').$record->senderName();
                    })
                    ->color(fn (TicketMessage $record): string => $record->is_internal_note ? 'warning' : ($record->sender_type === MessageSenderType::Agent ? 'primary' : 'info')),
                TextColumn::make('body')
                    ->label('Message')
                    ->wrap(),
            ])
            ->headerActions([
                Action::make('addMessage')
                    ->label('Add Reply / Note')
                    ->authorize(fn (): bool => OddenAuthorization::allows('update', $this->getOwnerRecord(), TicketResource::class))
                    ->icon('heroicon-m-chat-bubble-left-ellipsis')
                    ->color('primary')
                    ->form([
                        Select::make('canned_response_id')
                            ->label('Insert Canned Response (Optional)')
                            ->placeholder('Select a pre-approved template to insert...')
                            ->options(function (): array {
                                return $this->cannedResponsesQuery()
                                    ->pluck('title', 'id')
                                    ->all();
                            })
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (callable $set, $state): void {
                                if (! $state) {
                                    return;
                                }

                                /** @var CannedResponse|null $canned */
                                $canned = $this->cannedResponsesQuery()->find($state);
                                if ($canned !== null) {
                                    $set('body', $canned->content);
                                }
                            }),
                        Textarea::make('body')
                            ->label('Reply / Note Content')
                            ->placeholder('Type your customer response or internal team note...')
                            ->rows(5)
                            ->required()
                            ->hintAction(
                                Action::make('draftReply')
                                    ->label('Draft a reply')
                                    ->icon(Heroicon::Sparkles)
                                    ->requiresConfirmation(fn (Get $get): bool => filled($get('body')))
                                    ->modalHeading('Replace what you have written?')
                                    ->modalDescription('The draft will replace the text in the reply box.')
                                    ->action(function (Set $set): void {
                                        /** @var Ticket $ticket */
                                        $ticket = $this->getOwnerRecord();
                                        $draft = app(DraftsTicketReply::class)->execute($ticket, auth()->user());

                                        $set('body', $draft['body']);

                                        Notification::make()
                                            ->title('Draft ready: check it before you send')
                                            ->body($draft['rationale'])
                                            ->info()
                                            ->send();
                                    })
                            ),
                        Toggle::make('is_internal_note')
                            ->label('Make this a Private Internal Note (Customer will not see this)')
                            ->default(false),
                    ])
                    ->action(function (array $data): void {
                        /** @var Ticket $ticket */
                        $ticket = $this->getOwnerRecord();
                        $replyAction = app(ReplyTicketAction::class);

                        $replyAction->execute(
                            ticket: $ticket,
                            body: (string) $data['body'],
                            senderType: MessageSenderType::Agent,
                            user: auth()->user(),
                            contact: null,
                            isInternalNote: (bool) ($data['is_internal_note'] ?? false)
                        );
                    }),
            ]);
    }

    /**
     * Shared canned responses plus the current agent's own.
     *
     * @return Builder<CannedResponse>
     */
    protected function cannedResponsesQuery(): Builder
    {
        $userId = OddenAuthorization::userId();

        return CannedResponse::query()
            ->where(function (Builder $query) use ($userId): void {
                $query->where('is_shared', true)
                    ->orWhere('user_id', $userId);
            });
    }
}
