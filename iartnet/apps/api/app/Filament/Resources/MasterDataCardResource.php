<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\MasterDataCardResource\Pages;
use App\Models\MasterDcRecord;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class MasterDataCardResource extends Resource
{
    protected static ?string $model = MasterDcRecord::class;

    protected static ?string $navigationLabel = 'Master Data';

    public static function getNavigationGroup(): ?string
    {
        return 'Master';
    }

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-document-text';
    }

    protected static ?string $modelLabel = 'Data Card';

    protected static ?string $pluralModelLabel = 'Data Cards';

    /**
     * Table configuration - readonly, no create/edit/delete.
     * Dati dalla view iartnet_master.v_dc_rec_table.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('stable_id')
                    ->label('Stable ID')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->limit(40)
                    ->tooltip(fn (MasterDcRecord $record): string => $record->stable_id ?? ''),
                Tables\Columns\TextColumn::make('c_type')
                    ->label('Type')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('institution')
                    ->label('Institution')
                    ->searchable()
                    ->sortable()
                    ->limit(40)
                    ->tooltip(fn (MasterDcRecord $record): string => $record->institution ?? ''),
                Tables\Columns\TextColumn::make('resolved_title')
                    ->label('Title')
                    ->searchable(true, function (Builder $query, string $search): void {
                        $like = '%'.addcslashes($search, '%_\\').'%';
                        $query->where(function (Builder $q) use ($like): void {
                            $q->where('title', 'ilike', $like)
                                ->orWhere('subject', 'ilike', $like)
                                ->orWhere('subjectb', 'ilike', $like);
                        });
                    })
                    ->sortable(true, function (Builder $query, string $direction): void {
                        $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
                        // Stesso ordine di priorità del fallback PHP: title → subject → subjectb
                        $query->orderByRaw('COALESCE(title, subject, subjectb) '.$dir.' NULLS LAST');
                    })
                    ->limit(50)
                    ->tooltip(fn (MasterDcRecord $record): string => $record->resolved_title ?? ''),
                Tables\Columns\TextColumn::make('publish_state')
                    ->label('Publish State')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'draft' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('is_translated')
                    ->label('Translated')
                    ->formatStateUsing(fn ($state): string => $state ? 'Yes' : 'No')
                    ->sortable(),
            ])
            ->actions([
                Action::make('togglePublishState')
                    ->label(fn (MasterDcRecord $record): string => $record->publish_state === 'draft' ? 'Publish' : 'Set draft')
                    ->icon(fn (MasterDcRecord $record): string => $record->publish_state === 'draft' ? 'heroicon-o-arrow-up-circle' : 'heroicon-o-arrow-down-circle')
                    ->color(fn (MasterDcRecord $record): string => $record->publish_state === 'draft' ? 'success' : 'warning')
                    ->action(function (MasterDcRecord $record): void {
                        $newState = $record->publish_state === 'draft' ? 'published' : 'draft';
                        DB::table('iartnet_master.records')
                            ->where('id', $record->id)
                            ->update(['publish_state' => $newState]);
                        Notification::make()
                            ->title('State updated')
                            ->body("Publish state set to \"{$newState}\".")
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('stable_id', 'asc');
    }

    /**
     * Get the pages for this resource.
     * Only List page, no Create/Edit pages.
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMasterDataCards::route('/'),
            'view' => Pages\ViewMasterDataCard::route('/view/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->canAccessFilament() ?? false;
    }

    public static function canView($record): bool
    {
        return auth()->user()?->canAccessFilament() ?? false;
    }

    /**
     * Disable create action.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Disable edit action.
     */
    public static function canEdit($record): bool
    {
        return false;
    }

    /**
     * Disable delete action.
     */
    public static function canDelete($record): bool
    {
        return false;
    }
}
