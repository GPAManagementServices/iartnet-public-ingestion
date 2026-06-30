<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\MirrorRecord;
use App\Models\MirrorRecordKv;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

class RecordDetailPage extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationLabel = null;

    protected static bool $shouldRegisterNavigation = false;

    public ?string $schema = null;

    public ?string $recordId = null;

    public ?object $record = null;

    public function mount(?string $schema = null, ?string $record = null): void
    {
        $this->schema = $schema;
        $this->recordId = $record;

        if ($this->schema && $this->recordId) {
            $this->loadRecord();
        }
    }

    protected function loadRecord(): void
    {
        if (!$this->schema || !$this->recordId) {
            return;
        }

        $model = MirrorRecord::forSchema($this->schema);
        $this->record = $model->find($this->recordId);
    }

    public function getTitle(): string | Htmlable
    {
        return 'Dettaglio Scheda';
    }

    protected string $view = 'filament.pages.record-detail-page';

    public function form($form)
    {
        return $form
            ->schema([
                Section::make('Record')
                    ->schema([
                        Placeholder::make('scheda_idx')
                            ->label('Scheda Index')
                            ->content(fn () => $this->record?->scheda_idx ?? '-'),
                        Placeholder::make('normativa_code')
                            ->label('Normativa')
                            ->content(function () {
                                if (!$this->record) {
                                    return '-';
                                }
                                $code = $this->record->normativa_code ?? '-';
                                $version = $this->record->normativa_version ?? null;
                                return $version ? "{$code} v{$version}" : $code;
                            }),
                        Placeholder::make('title')
                            ->label('Titolo')
                            ->content(fn () => $this->record?->title ?? '-'),
                        Placeholder::make('valid_xsd')
                            ->label('Valida XSD')
                            ->content(fn () => ($this->record?->valid_xsd ?? false) ? 'Sì' : 'No'),
                        Placeholder::make('error_count')
                            ->label('Errori')
                            ->content(fn () => $this->record?->error_count ?? 0),
                    ])
                    ->columns(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getKvTableQuery())
            ->columns([
                TextColumn::make('xpath')
                    ->label('XPath')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('occurrence_idx')
                    ->label('Occurrence Index')
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('value_text')
                    ->label('Valore')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
            ])
            ->defaultSort('occurrence_idx', 'asc')
            ->paginated([10, 25, 50, 100]);
    }

    protected function getKvTableQuery()
    {
        if (!$this->schema || !$this->recordId) {
            $model = new MirrorRecordKv();
            $model->setTable('information_schema.tables');
            return $model->newQuery()->whereRaw('1 = 0');
        }

        $model = MirrorRecordKv::forSchema($this->schema);
        return $model->newQuery()->where('record_id', $this->recordId);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdministrator() ?? false;
    }
}
