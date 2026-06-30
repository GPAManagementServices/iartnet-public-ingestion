<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\MirrorInstanceResource\Pages;
use App\Models\MirrorInstance;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class MirrorInstanceResource extends Resource
{
    protected static ?string $model = MirrorInstance::class;

    protected static ?string $navigationLabel = 'Mirror Instances';

    public static function getNavigationGroup(): ?string
    {
        return 'Admin';
    }

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-square-3-stack-3d';
    }

    protected static ?string $modelLabel = 'Mirror Instance';

    protected static ?string $pluralModelLabel = 'Mirror Instances';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label('Schema Name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->helperText('Technical name of the PostgreSQL schema (lowercase, alphanumeric, underscores only)')
                    ->regex('/^[a-z][a-z0-9_]*$/')
                    ->validationMessages([
                        'regex' => 'Schema name must start with a letter and contain only lowercase letters, numbers, and underscores.',
                    ])
                    ->disabled(fn (?MirrorInstance $record): bool => $record !== null)
                    ->dehydrated(fn (?MirrorInstance $record): bool => $record === null)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('display_name')
                    ->label('Display Name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Human-readable name for the UI')
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('description')
                    ->label('Description')
                    ->rows(3)
                    ->columnSpanFull(),
                Forms\Components\Select::make('institution_id')
                    ->label('Institution')
                    ->relationship('institution', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->columnSpanFull(),
                // Data provider: stessa gestione delle Institutions (SIRBEC, SIGEC, SBN, JSON)
                Forms\Components\Select::make('data_provider')
                    ->label('Data Provider')
                    ->options([
                        'SIRBEC' => 'SIRBEC',
                        'SIGEC' => 'SIGEC',
                        'SBN' => 'SBN',
                        'JSON' => 'JSON',
                    ])
                    ->searchable()
                    ->placeholder('Select a data provider')
                    ->helperText('Standard/source of the data stored in this mirror instance'),
                Forms\Components\Toggle::make('is_protected')
                    ->label('Protected')
                    ->helperText('Protected instances cannot be deleted')
                    ->default(false)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('display_name')
                    ->label('Display Name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('name')
                    ->label('Schema Name')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->icon('heroicon-o-code-bracket'),
                Tables\Columns\TextColumn::make('institution.name')
                    ->label('Institution')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('data_provider')
                    ->label('Data Provider')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('is_protected')
                    ->label('Protected')
                    ->boolean()
                    ->sortable(),
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
            ->filters([
                Tables\Filters\SelectFilter::make('institution_id')
                    ->label('Institution')
                    ->relationship('institution', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\TernaryFilter::make('is_protected')
                    ->label('Protected')
                    ->placeholder('All')
                    ->trueLabel('Protected only')
                    ->falseLabel('Not protected'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalHeading('Delete Mirror Instance')
                    ->modalDescription(fn (MirrorInstance $record): string => 
                        "Are you sure you want to delete '{$record->display_name}'? This will also delete the PostgreSQL schema '{$record->name}'. This action cannot be undone."
                    )
                    ->modalSubmitActionLabel('Delete')
                    ->disabled(fn (MirrorInstance $record): bool => $record->is_protected),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->requiresConfirmation()
                        ->modalHeading('Delete Mirror Instances')
                        ->modalDescription('This will also delete the associated PostgreSQL schemas. This action cannot be undone.')
                        ->modalSubmitActionLabel('Delete'),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMirrorInstances::route('/'),
            'create' => Pages\CreateMirrorInstance::route('/create'),
            'edit' => Pages\EditMirrorInstance::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdministrator() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isAdministrator() ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->isAdministrator() ?? false;
    }

    public static function canDelete($record): bool
    {
        if ($record->is_protected ?? false) {
            return false;
        }

        return auth()->user()?->isAdministrator() ?? false;
    }
}
