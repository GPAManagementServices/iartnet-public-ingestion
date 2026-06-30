<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\NarrationResource\Pages;
use App\Models\Narration;
use App\Rules\ValidJson;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Risorsa Filament per iartnet_master.narrations.
 * CRUD + View nella sezione Master.
 */
class NarrationResource extends Resource
{
    protected static ?string $model = Narration::class;

    protected static ?string $navigationLabel = 'Narrations';

    public static function getNavigationGroup(): ?string
    {
        return 'Master';
    }

    public static function getNavigationSort(): ?int
    {
        return 11;
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-document-text';
    }

    protected static ?string $modelLabel = 'Narration';

    protected static ?string $pluralModelLabel = 'Narrations';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('description')
                    ->label('Description')
                    ->rows(5)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('ext_json')
                    ->label('Ext JSON')
                    ->rows(18)
                    ->columnSpanFull()
                    ->helperText('JSON libero. Visualizzazione formattata: modifica direttamente il testo (sintassi JSON valida).')
                    ->formatStateUsing(function (mixed $state): string {
                        if ($state === null || $state === '') {
                            return '{}';
                        }
                        if (is_array($state) || is_object($state)) {
                            return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        }
                        if (is_string($state)) {
                            $decoded = json_decode($state, true);
                            return is_array($decoded) || is_object($decoded)
                                ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                : $state;
                        }
                        return '{}';
                    })
                    ->dehydrateStateUsing(function (mixed $state): array {
                        if ($state === null || (is_string($state) && trim($state) === '')) {
                            return [];
                        }
                        if (is_string($state)) {
                            $decoded = json_decode($state, true);
                            if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
                                return (array) $decoded;
                            }
                        }
                        if (is_array($state)) {
                            return $state;
                        }
                        return [];
                    })
                    ->rule([new ValidJson]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->searchable(false)
                    ->sortable(false)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->limit(60)
                    ->tooltip(fn (Narration $record): string => $record->name ?? ''),
                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(80)
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNarrations::route('/'),
            'create' => Pages\CreateNarration::route('/create'),
            'view' => Pages\ViewNarration::route('/{record}'),
            'edit' => Pages\EditNarration::route('/{record}/edit'),
            'story-editor' => Pages\StoryEditorNarration::route('/{record}/story-editor'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->canAccessOperatoreSections() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->canAccessOperatoreSections() ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->canAccessOperatoreSections() ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->canAccessOperatoreSections() ?? false;
    }

    public static function canView($record): bool
    {
        return auth()->user()?->canAccessOperatoreSections() ?? false;
    }
}
