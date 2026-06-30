<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\Institution;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationLabel = 'Users';

    public static function getNavigationGroup(): ?string
    {
        return 'Admin';
    }

    public static function getNavigationSort(): ?int
    {
        return 4;
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-users';
    }

    protected static ?string $modelLabel = 'User';

    protected static ?string $pluralModelLabel = 'Users';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('password')
                    ->label('Password')
                    ->password()
                    ->required(fn (string $context): bool => $context === 'create')
                    ->dehydrated(fn ($state): bool => filled($state))
                    ->dehydrateStateUsing(fn ($state): string => Hash::make($state))
                    ->maxLength(255),
                Forms\Components\Select::make('roles')
                    ->label('Roles')
                    ->relationship('roles', 'display_name')
                    ->multiple()
                    ->preload()
                    ->searchable(),
                Forms\Components\Checkbox::make('flag_institution')
                    ->label('Associato a istituzione')
                    ->live()
                    ->afterStateUpdated(function ($state, $set): void {
                        if (! $state) {
                            $set('institution_id', null);
                        }
                    }),
                Forms\Components\Select::make('institution_id')
                    ->label('Istituzione')
                    ->options(fn (): array => Institution::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->visible(fn ($get): bool => (bool) $get('flag_institution'))
                    ->required(fn ($get): bool => (bool) $get('flag_institution'))
                    ->dehydrateStateUsing(fn ($state, $get) => $get('flag_institution') ? $state : null),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('roles.display_name')
                    ->label('Roles')
                    ->badge()
                    ->separator(',')
                    ->searchable(),
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
                Tables\Filters\SelectFilter::make('roles')
                    ->label('Role')
                    ->relationship('roles', 'display_name')
                    ->multiple()
                    ->preload(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
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
        return auth()->user()?->isAdministrator() ?? false;
    }
}
