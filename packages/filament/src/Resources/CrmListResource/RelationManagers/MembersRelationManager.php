<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\CrmListResource\RelationManagers;

use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\ListMembership;

class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'memberships';

    protected static ?string $recordTitleAttribute = 'member_id';

    protected static ?string $title = 'List members';

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('member_id')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('member'))
            ->defaultSort('added_at', 'desc')
            ->columns([
                TextColumn::make('member')
                    ->label('Record')
                    ->state(function (ListMembership $record): string {
                        $member = $record->member;

                        if ($member instanceof Contact) {
                            return "{$member->full_name} ({$member->email})";
                        }

                        if ($member instanceof Company) {
                            return (string) $member->name;
                        }

                        return "#{$record->member_id}";
                    })
                    ->weight('bold'),
                TextColumn::make('member_type')
                    ->label('Entity type')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => class_basename((string) $state)),
                TextColumn::make('added_at')
                    ->label('Added date')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                DeleteAction::make()->label('Remove'),
            ]);
    }
}
