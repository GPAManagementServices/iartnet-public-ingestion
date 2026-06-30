<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Institution;
use App\Models\MirrorInstance;
use App\Models\MirrorRecord;
use App\Support\IngestionPaths;
use App\Models\MirrorRecordAsset;
use App\Models\MirrorRecordKv;
use App\Models\MirrorAddedKv;
use App\Enums\MirrorImageSyncMode;
use App\Jobs\SyncMirrorImagesJob;
use App\Services\Mirror\UnsynchronizeMirrorImagesService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class DataManagementWizard extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;
    use WithFileUploads;

    protected string $view = 'filament.pages.data-management-wizard';

    protected static ?string $navigationLabel = 'Mirror Data';

    public static function getNavigationGroup(): ?string
    {
        return 'Ingestion';
    }

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    protected static ?string $title = 'Mirror Data';

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-document-magnifying-glass';
    }

    public ?array $data = [];

    public ?string $institutionId = null;

    public ?string $mirrorInstanceId = null;

    // Proprietà pubbliche per Livewire entangle
    public ?string $institution_id = null;

    public ?string $mirror_instance_id = null;

    public ?string $targetSchema = null;

    public ?string $selectedRecordId = null;

    // Paginazione per la tabella DETAILS
    public int $detailsPage = 1;
    
    public int $detailsPerPage = 20;

    // Ricerca per la tabella DETAILS
    public string $detailsSearch = '';

    // Paginazione per la tabella ADDED FIELDS
    public int $addedFieldsPage = 1;
    
    public int $addedFieldsPerPage = 20;

    // Ricerca per la tabella ADDED FIELDS
    public string $addedFieldsSearch = '';

    // Controllo visibilità tabelle MAIN e DETAILS
    public bool $showMainTable = true;
    
    public bool $showDetailsTable = false;

    // Controllo visibilità scheda IMPORT DATA TO MASTER
    public bool $showImportToMaster = false;

    // Tab attiva per la visualizzazione dettagli
    public string $activeDetailsTab = 'details';

    // Risultati importazione Master
    public ?array $importResults = null;

    // Add Media modal (Record Details)
    public bool $showAddMediaModal = false;

    /** @var TemporaryUploadedFile|null */
    public $addMediaFile = null;

    // Conferma sincronizzazione (Import Data To Master)
    public bool $showSyncConfirmModal = false;

    /** Azione in attesa di conferma: 'importDataToMasterDB' | 'importAddedFieldsMasterDB' | 'importImagesToMasterDB' */
    public ?string $pendingSyncAction = null;

    // Un-Synchronize Images: scope e lista record_id (Import Data To Master)
    public bool $showUnsynchronizeImagesModal = false;

    /** 'all' | 'list' */
    public string $unsynchronizeScope = 'all';

    public string $unsynchronizeRecordIdsList = '';

    // Synchronize Images: modalità copy | vips
    public bool $showSyncImagesModal = false;

    /** 'copy' | 'vips' */
    public string $syncImagesMode = 'copy';

    public function mount(): void
    {
        $this->form->fill();

        $scopedInstitutionId = auth()->user()?->getScopedInstitutionId();
        if ($scopedInstitutionId !== null) {
            $this->form->fill(['institution_id' => $scopedInstitutionId]);
            $this->institution_id = $scopedInstitutionId;
            $this->institutionId = $scopedInstitutionId;
        } else {
            $this->institution_id = $this->data['institution_id'] ?? null;
            if (isset($this->data['institution_id'])) {
                $this->institutionId = (string) $this->data['institution_id'];
            }
        }

        $this->mirror_instance_id = $this->data['mirror_instance_id'] ?? null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    Wizard\Step::make('select')
                        ->label('Select Schema Mirror')
                        ->schema([
                            Select::make('institution_id')
                                ->label('Institution')
                                ->options(fn (): array => auth()->user()?->allowedInstitutionOptions() ?? [])
                                ->default(fn (): ?string => auth()->user()?->getScopedInstitutionId())
                                ->required()
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function ($state, $set) {
                                    $this->institutionId = (string) $state;
                                    $this->institution_id = (string) $state;
                                    $set('mirror_instance_id', null);
                                    $this->mirrorInstanceId = null;
                                    $this->mirror_instance_id = null;
                                }),
                            Select::make('mirror_instance_id')
                                ->label('Mirror Schema')
                                ->options(function ($get) {
                                    $institutionId = $get('institution_id');
                                    if (empty($institutionId)) {
                                        return [];
                                    }
                                    return MirrorInstance::query()
                                        ->where('institution_id', (string) $institutionId)
                                        ->get()
                                        ->mapWithKeys(function ($instance) {
                                            return [
                                                (string) $instance->id => $instance->display_name.' ('.$instance->name.')',
                                            ];
                                        })
                                        ->toArray();
                                })
                                ->required()
                                ->searchable()
                                ->reactive()
                                ->live()
                                ->afterStateUpdated(function ($state) {
                                    $this->mirrorInstanceId = $state ? (string) $state : null;
                                    $this->mirror_instance_id = $state ? (string) $state : null;
                                    if ($state) {
                                        $this->loadSchemaData();
                                    }
                                }),
                        ]),
                    Wizard\Step::make('manage')
                        ->label('Mirror Data')
                        ->schema([
                            Placeholder::make('Mirror Instance records')
                                ->label('')
                                ->content(fn () => new \Illuminate\Support\HtmlString(
                                    view('filament.components.data-management-table', [
                                        'component' => $this,
                                    ])->render()
                                ))
                                ->dehydrated(false),
                        ]),
                ])
                    ->submitAction(null)
                    ->cancelAction(null),
            ]);
    }


    protected function loadSchemaData(): void
    {
        if (!$this->mirrorInstanceId) {
            return;
        }

        try {
            auth()->user()?->assertCanAccessInstitution($this->institutionId);

            $mirrorInstance = MirrorInstance::find($this->mirrorInstanceId);
            if (!$mirrorInstance) {
                throw new \RuntimeException('Mirror instance not found');
            }

            // Validate that user has access to this mirror instance
            if ($mirrorInstance->institution_id !== $this->institutionId) {
                throw new \RuntimeException('Mirror instance does not belong to selected institution');
            }

            $this->targetSchema = $mirrorInstance->name;
        } catch (\Exception $e) {
            Notification::make()
                ->title('Errore')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                TextColumn::make('record_id')
                    ->label('Codice')
                    ->sortable()
                    ->searchable()
                    ->alignCenter(),
                TextColumn::make('normativa_code')
                    ->label('Normativa')
                    ->formatStateUsing(function ($record) {
                        $code = $record->normativa_code ?? '-';
                        $version = $record->normativa_version ?? null;
                        return $version ? "{$code} v{$version}" : $code;
                    })
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Titolo')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                BadgeColumn::make('promoted')
                    ->label('Promoted')
                    ->formatStateUsing(fn ($state) => $state ? 'Sì' : 'No')
                    ->colors([
                        'success' => fn ($state) => $state === true,
                        'danger' => fn ($state) => $state === false,
                    ])
                    ->sortable(),
                TextColumn::make('error_count')
                    ->label('Errori')
                    ->sortable()
                    ->alignCenter(),
            ])
            ->filters([
                // Filtri opzionali
            ])
            ->actions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('primary')
                    ->action(function ($record) {
                        $this->selectedRecordId = $record->record_id;
                        $this->detailsPage = 1; // Reset paginazione
                        $this->detailsSearch = ''; // Reset ricerca
                        $this->addedFieldsPage = 1; // Reset paginazione added fields
                        $this->addedFieldsSearch = ''; // Reset ricerca added fields
                        $this->showMainTable = false; // Nascondi MAIN
                        $this->showDetailsTable = true; // Mostra DETAILS
                        $this->activeDetailsTab = 'details'; // Reset tab attiva
                    }),
                DeleteAction::make()
                    ->label('Delete')
                    ->action(function ($record) {
                        $this->deleteRecord($record);
                    }),
            ])
            ->defaultSort('record_id', 'asc')
            ->paginated([10, 25, 50, 100]);
    }

    protected function getTableQuery()
    {
        if (!$this->targetSchema) {
            // Se non c'è schema selezionato, restituisci un query builder vuoto
            // Usa information_schema.tables che esiste sempre in PostgreSQL
            $model = new MirrorRecord();
            $model->setTable('information_schema.tables');
            return $model->newQuery()->whereRaw('1 = 0');
        }

        // Crea un'istanza del modello e imposta lo schema dinamico usando setSchema()
        $model = MirrorRecord::forSchema($this->targetSchema);
        
        // Restituisci il query builder dal modello con schema già impostato
        return $model->newQuery();
    }

    protected function getDetailsTableQuery()
    {
        // DEBUG: Log dei parametri
        \Log::info('getDetailsTableQuery - DEBUG', [
            'targetSchema' => $this->targetSchema,
            'selectedRecordId' => $this->selectedRecordId,
            'selectedRecordId_type' => gettype($this->selectedRecordId),
        ]);

        if (!$this->targetSchema || !$this->selectedRecordId) {
            // Se non c'è schema selezionato o record selezionato, restituisci un query builder vuoto
            \Log::warning('getDetailsTableQuery - Parametri mancanti', [
                'targetSchema' => $this->targetSchema,
                'selectedRecordId' => $this->selectedRecordId,
            ]);
            $model = new MirrorRecordKv();
            $model->setTable('information_schema.tables');
            return $model->newQuery()->whereRaw('1 = 0');
        }

        // Crea un'istanza del modello e imposta lo schema dinamico
        $model = MirrorRecordKv::forSchema($this->targetSchema);
        
        // DEBUG: Verifica la tabella impostata
        \Log::info('getDetailsTableQuery - Modello creato', [
            'table' => $model->getTable(),
            'schema' => $this->targetSchema,
        ]);
        
        // Restituisci il query builder filtrato per record_id
        $query = $model->newQuery()->where('record_id', $this->selectedRecordId);
        
        // DEBUG: Log della query SQL generata
        \Log::info('getDetailsTableQuery - Query SQL', [
            'sql' => $query->toSql(),
            'bindings' => $query->getBindings(),
        ]);
        
        // DEBUG: Conta i record trovati
        $count = $query->count();
        \Log::info('getDetailsTableQuery - Record count', [
            'count' => $count,
        ]);
        
        return $query;
    }

    public function detailsTable(Table $table): Table
    {
        return $table
            ->query($this->getDetailsTableQuery())
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

    public function getDetailsRecords()
    {
        if (!$this->targetSchema || !$this->selectedRecordId) {
            return collect([]);
        }

        $query = $this->getDetailsTableQuery();
        
        // Applica il filtro di ricerca se presente
        if (!empty($this->detailsSearch)) {
            $searchTerm = '%' . $this->detailsSearch . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('xpath', 'ILIKE', $searchTerm)
                  ->orWhere('value_text', 'ILIKE', $searchTerm);
            });
        }
        
        // Esegui la query e ottieni i risultati
        $total = $query->count();
        $records = $query
            ->orderBy('occurrence_idx', 'asc')
            ->offset(($this->detailsPage - 1) * $this->detailsPerPage)
            ->limit($this->detailsPerPage)
            ->get();

        return [
            'records' => $records,
            'total' => $total,
            'perPage' => $this->detailsPerPage,
            'currentPage' => $this->detailsPage,
            'lastPage' => (int) ceil($total / $this->detailsPerPage),
        ];
    }

    public function updatedDetailsSearch(): void
    {
        // Reset alla prima pagina quando cambia la ricerca
        $this->detailsPage = 1;
    }

    protected function getAddedFieldsTableQuery()
    {
        if (!$this->targetSchema || !$this->selectedRecordId) {
            $model = new MirrorAddedKv();
            $model->setTable('information_schema.tables');
            return $model->newQuery()->whereRaw('1 = 0');
        }

        // Crea un'istanza del modello e imposta lo schema dinamico
        $model = MirrorAddedKv::forSchema($this->targetSchema);
        
        // Restituisci il query builder filtrato per record_id
        return $model->newQuery()->where('record_id', $this->selectedRecordId);
    }

    public function getAddedFieldsRecords()
    {
        if (!$this->targetSchema || !$this->selectedRecordId) {
            return collect([]);
        }

        $query = $this->getAddedFieldsTableQuery();
        
        // Applica il filtro di ricerca se presente
        if (!empty($this->addedFieldsSearch)) {
            $searchTerm = '%' . $this->addedFieldsSearch . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('field_name', 'ILIKE', $searchTerm)
                  ->orWhere('value_text', 'ILIKE', $searchTerm);
            });
        }
        
        // Esegui la query e ottieni i risultati
        $total = $query->count();
        $records = $query
            ->orderBy('field_name', 'asc')
            ->offset(($this->addedFieldsPage - 1) * $this->addedFieldsPerPage)
            ->limit($this->addedFieldsPerPage)
            ->get();

        return [
            'records' => $records,
            'total' => $total,
            'perPage' => $this->addedFieldsPerPage,
            'currentPage' => $this->addedFieldsPage,
            'lastPage' => (int) ceil($total / $this->addedFieldsPerPage),
        ];
    }

    public function goToAddedFieldsPage(int $page): void
    {
        $this->addedFieldsPage = $page;
    }

    public function updatedAddedFieldsSearch(): void
    {
        // Reset alla prima pagina quando cambia la ricerca
        $this->addedFieldsPage = 1;
    }

    public function goToDetailsPage(int $page): void
    {
        $this->detailsPage = $page;
    }

    protected function deleteRecord($record): void
    {
        if (!$this->targetSchema) {
            Notification::make()
                ->title('Errore')
                ->body('Schema non selezionato')
                ->danger()
                ->send();

            return;
        }

        try {
            $recordId = $record->record_id;

            // Ottieni l'import_run_id dal record PRIMA di eliminarlo
            $recordModel = MirrorRecord::forSchema($this->targetSchema);
            $recordData = $recordModel->where('record_id', $recordId)->first();
            $importRunId = $recordData?->import_run_id;

            // Elimina tutti i record asset collegati
            $assetModel = MirrorRecordAsset::forSchema($this->targetSchema);
            $assetModel->where('record_id', $recordId)->delete();

            // Elimina tutti i record added_kv collegati
            $addedKvModel = MirrorAddedKv::forSchema($this->targetSchema);
            $addedKvModel->where('record_id', $recordId)->delete();

            // Elimina tutti i record KV collegati
            $kvModel = MirrorRecordKv::forSchema($this->targetSchema);
            $kvModel->where('record_id', $recordId)->delete();

            // Elimina il record MAIN
            $recordModel->where('record_id', $recordId)->delete();

            // Se esiste un import_run_id e non ci sono altri record con lo stesso import_run_id, elimina l'import_run
            if ($importRunId !== null) {
                // Crea una nuova istanza del modello per verificare se ci sono altri record
                $checkModel = MirrorRecord::forSchema($this->targetSchema);
                $remainingRecords = $checkModel->where('import_run_id', $importRunId)->count();
                if ($remainingRecords === 0) {
                    DB::table($this->targetSchema.'.import_run')
                        ->where('import_run_id', $importRunId)
                        ->delete();
                }
            }

            // Se il record eliminato era quello selezionato, resetta la selezione e torna alla MAIN
            if ($this->selectedRecordId === $recordId) {
                $this->selectedRecordId = null;
                $this->showMainTable = true;
                $this->showDetailsTable = false;
            }

            Notification::make()
                ->title('Record eliminato')
                ->body('Il record e tutti i dati collegati sono stati eliminati.')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Errore nell\'eliminazione')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function backToMain(): void
    {
        $this->showMainTable = true;
        $this->showDetailsTable = false;
        $this->showImportToMaster = false;
        $this->selectedRecordId = null;
        $this->detailsPage = 1;
        $this->detailsSearch = '';
        $this->addedFieldsPage = 1;
        $this->addedFieldsSearch = '';
        $this->activeDetailsTab = 'details';
    }

    public function openImportToMaster(): void
    {
        $this->showMainTable = false;
        $this->showDetailsTable = false;
        $this->showImportToMaster = true;
    }

    public function backToWizard(): void
    {
        $this->showMainTable = true;
        $this->showDetailsTable = false;
        $this->showImportToMaster = false;
    }

    /**
     * Avvia l'import verso Master in base al data_provider della mirror instance selezionata.
     * Usa il campo data_provider di mirror_instances (deprecato: data_provider di institutions).
     */
    /**
     * Apre il modale di conferma prima di eseguire un'azione di sincronizzazione.
     *
     * @param  string  $action  'importDataToMasterDB' | 'importAddedFieldsMasterDB' | 'importImagesToMasterDB'
     */
    public function openSyncConfirmModal(string $action): void
    {
        $allowed = ['importDataToMasterDB', 'importAddedFieldsMasterDB'];
        if (! in_array($action, $allowed, true)) {
            return;
        }
        $this->pendingSyncAction = $action;
        $this->showSyncConfirmModal = true;
    }

    /** Chiude il modale di conferma sincronizzazione senza eseguire l'azione. */
    public function closeSyncConfirmModal(): void
    {
        $this->showSyncConfirmModal = false;
        $this->pendingSyncAction = null;
    }

    /** Esegue l'azione di sincronizzazione confermata dall'utente e chiude il modale. */
    public function confirmSyncAndProceed(): void
    {
        $action = $this->pendingSyncAction;
        $this->closeSyncConfirmModal();
        if ($action === 'importDataToMasterDB') {
            $this->importDataToMasterDB();
        } elseif ($action === 'importAddedFieldsMasterDB') {
            $this->importAddedFieldsMasterDB();
        }
    }

    public function openSyncImagesModal(): void
    {
        $this->syncImagesMode = MirrorImageSyncMode::Copy->value;
        $this->showSyncImagesModal = true;
    }

    public function closeSyncImagesModal(): void
    {
        $this->showSyncImagesModal = false;
        $this->syncImagesMode = MirrorImageSyncMode::Copy->value;
    }

    public function confirmSyncImages(): void
    {
        $mode = MirrorImageSyncMode::tryFromString($this->syncImagesMode) ?? MirrorImageSyncMode::Copy;
        $this->closeSyncImagesModal();
        $this->importImagesToMasterDB($mode);
    }

    public function openUnsynchronizeImagesModal(): void
    {
        $this->unsynchronizeScope = 'all';
        $this->unsynchronizeRecordIdsList = '';
        $this->showUnsynchronizeImagesModal = true;
    }

    public function closeUnsynchronizeImagesModal(): void
    {
        $this->showUnsynchronizeImagesModal = false;
        $this->unsynchronizeScope = 'all';
        $this->unsynchronizeRecordIdsList = '';
    }

    public function confirmUnsynchronizeImages(): void
    {
        $recordIdsFilter = null;
        if ($this->unsynchronizeScope === 'list') {
            $recordIdsFilter = $this->parseUnsynchronizeRecordIdsList($this->unsynchronizeRecordIdsList);
            if ($recordIdsFilter === []) {
                Notification::make()
                    ->title('Errore')
                    ->body('Specificare almeno un record_id nella lista (valori separati da virgola).')
                    ->danger()
                    ->send();

                return;
            }
        }

        $this->closeUnsynchronizeImagesModal();
        $this->unsynchronizeImagesFromMasterDB($recordIdsFilter);
    }

    /**
     * @return list<string>
     */
    private function parseUnsynchronizeRecordIdsList(string $list): array
    {
        $parts = array_map('trim', explode(',', $list));

        return array_values(array_filter($parts, static fn (string $id): bool => $id !== ''));
    }

    public function importDataToMasterDB(): void
    {
        if (! $this->institutionId || ! $this->targetSchema) {
            return;
        }

        $mirrorInstance = \App\Models\MirrorInstance::query()
            ->where('institution_id', $this->institutionId)
            ->where('name', $this->targetSchema)
            ->first();

        if (! $mirrorInstance || empty($mirrorInstance->data_provider)) {
            return;
        }

        $dataProvider = strtoupper(trim($mirrorInstance->data_provider));

        if ($dataProvider === 'SIRBEC' || $dataProvider === 'SIGEC') {
            $this->importICCDToMasterDB();
        } elseif ($dataProvider === 'SBN') {
            $this->importSBNToMasterDB();
        } elseif ($dataProvider === 'JSON') {
            $this->importJsonToMasterDB();
        }
    }

    private function importICCDToMasterDB(): void
    {
        // Reset risultati precedenti
        $this->importResults = null;

        if (!$this->targetSchema || !$this->institutionId) {
            \Filament\Notifications\Notification::make()
                ->title('Errore')
                ->body('Schema Mirror o Institution non selezionati')
                ->danger()
                ->send();

            $this->importResults = [
                'success' => false,
                'error' => 'Schema Mirror o Institution non selezionati',
                'processed' => 0,
                'success_count' => 0,
                'error_count' => 0,
                'warnings' => 0,
            ];

            return;
        }

        \Log::info('ImportICCDToMasterDB: Dispatching import job', [
            'target_schema' => $this->targetSchema,
            'institution_id' => $this->institutionId,
        ]);

        try {
            \App\Jobs\ImportMirrorToMasterJob::dispatch(
                $this->targetSchema,
                'iccd-to-master.yaml',
                $this->institutionId,
                null
            );

            $this->importResults = [
                'success' => true,
                'background_started' => true,
                'error' => null,
                'processed' => 0,
                'success_count' => 0,
                'error_count' => 0,
                'warnings' => 0,
            ];

            \Filament\Notifications\Notification::make()
                ->title('Importazione avviata')
                ->body('L\'importazione ICCD → Master è stata accodata e verrà eseguita dal worker. Controlla i log per l\'esito.')
                ->success()
                ->send();
        } catch (\Exception $e) {
            \Log::error('ImportICCDToMasterDB: Error dispatching job', [
                'target_schema' => $this->targetSchema,
                'institution_id' => $this->institutionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->importResults = [
                'success' => false,
                'error' => $e->getMessage(),
                'processed' => 0,
                'success_count' => 0,
                'error_count' => 1,
                'warnings' => 0,
            ];

            \Filament\Notifications\Notification::make()
                ->title('Errore durante l\'avvio dell\'importazione')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Importa i campi aggiuntivi dalla tabella added_kv per le schede già importate nel Master.
     *
     * Utilizza il mapping definito in added-fields-to-master.yaml per importare
     * i campi aggiuntivi nel Master, seguendo la stessa logica dell'importazione ICCD.
     * Importa solo i record con promoted = false e aggiorna promoted = true dopo l'importazione.
     * Alla fine imposta importResults per mostrare in box Info il riepilogo (come importICCDToMasterDB).
     *
     * @return void
     */
    public function importAddedFieldsMasterDB(): void
    {
        // Reset risultati precedenti: il box Info mostrerà l'esito di questa operazione
        $this->importResults = null;

        if (!$this->targetSchema || !$this->institutionId) {
            \Log::warning('ImportAddedFieldsMasterDB: Schema Mirror o Institution non selezionati');

            \Filament\Notifications\Notification::make()
                ->title('Errore')
                ->body('Schema Mirror o Institution non selezionati')
                ->danger()
                ->send();

            $this->importResults = [
                'success' => false,
                'error' => 'Schema Mirror o Institution non selezionati',
                'processed' => 0,
                'success_count' => 0,
                'error_count' => 0,
                'warnings' => 0,
            ];

            return;
        }

        \Log::info('ImportAddedFieldsMasterDB: Starting added fields import', [
            'target_schema' => $this->targetSchema,
            'institution_id' => $this->institutionId,
        ]);

        try {
            // Usa lo stesso importer ma con il metodo per i campi aggiuntivi
            $importer = new \App\Services\Import\MirrorToMasterImporter(
                $this->targetSchema,
                'iccd-to-master.yaml', // Non usato per added fields, ma necessario per il costruttore
                $this->institutionId
            );

            $stats = $importer->importAddedFields();

            \Log::info('ImportAddedFieldsMasterDB: Added fields import completed', $stats);

            // Riepilogo in importResults: stesso formato di importICCDToMasterDB per il box Info
            $this->importResults = [
                'success' => true,
                'error' => null,
                'processed' => $stats['processed'] ?? 0,
                'success_count' => $stats['success'] ?? 0,
                'error_count' => $stats['errors'] ?? 0,
                'warnings' => $stats['warnings'] ?? 0,
            ];

            \Filament\Notifications\Notification::make()
                ->title('Importazione campi aggiuntivi completata')
                ->body(sprintf(
                    'Processati: %d | Successi: %d | Errori: %d | Warning: %d',
                    $this->importResults['processed'],
                    $this->importResults['success_count'],
                    $this->importResults['error_count'],
                    $this->importResults['warnings']
                ))
                ->success()
                ->send();
        } catch (\Exception $e) {
            \Log::error('ImportAddedFieldsMasterDB: Error', [
                'target_schema' => $this->targetSchema,
                'institution_id' => $this->institutionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Riepilogo errore in importResults per il box Info (come importICCDToMasterDB)
            $this->importResults = [
                'success' => false,
                'error' => $e->getMessage(),
                'processed' => 0,
                'success_count' => 0,
                'error_count' => 1,
                'warnings' => 0,
            ];

            \Filament\Notifications\Notification::make()
                ->title('Errore durante l\'importazione campi aggiuntivi')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Importa le schede Mirror con normativa_code = 'MARC21' (SBN) nel Master usando il mapping sbn-to-master.yaml.
     * Stessa logica di importICCDToMasterDB: lettura KV dal Mirror, mapping e scrittura nello schema Master.
     * I campi non definiti in sbn-to-master.yaml vengono importati con kv_key = xpath.
     */
    private function importSBNToMasterDB(): void
    {
        $this->importResults = null;

        if (! $this->targetSchema || ! $this->institutionId) {
            \Filament\Notifications\Notification::make()
                ->title('Errore')
                ->body('Schema Mirror o Institution non selezionati')
                ->danger()
                ->send();

            $this->importResults = [
                'success' => false,
                'error' => 'Schema Mirror o Institution non selezionati',
                'processed' => 0,
                'success_count' => 0,
                'error_count' => 0,
                'warnings' => 0,
            ];

            return;
        }

        \Log::info('ImportSBNToMasterDB: Dispatching import job', [
            'target_schema' => $this->targetSchema,
            'institution_id' => $this->institutionId,
        ]);

        try {
            \App\Jobs\ImportMirrorToMasterJob::dispatch(
                $this->targetSchema,
                'sbn-to-master.yaml',
                $this->institutionId,
                'MARC21'
            );

            $this->importResults = [
                'success' => true,
                'background_started' => true,
                'error' => null,
                'processed' => 0,
                'success_count' => 0,
                'error_count' => 0,
                'warnings' => 0,
            ];

            \Filament\Notifications\Notification::make()
                ->title('Importazione avviata')
                ->body('L\'importazione SBN → Master è stata accodata e verrà eseguita dal worker. Controlla i log per l\'esito.')
                ->success()
                ->send();
        } catch (\Exception $e) {
            \Log::error('ImportSBNToMasterDB: Error dispatching job', [
                'target_schema' => $this->targetSchema,
                'institution_id' => $this->institutionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->importResults = [
                'success' => false,
                'error' => $e->getMessage(),
                'processed' => 0,
                'success_count' => 0,
                'error_count' => 1,
                'warnings' => 0,
            ];

            \Filament\Notifications\Notification::make()
                ->title('Errore durante l\'avvio dell\'importazione')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Importa le schede Mirror con normativa_code = 'JSON' nel Master usando il mapping json-to-master.yaml.
     * Stessa logica di importICCDToMasterDB: lettura KV dal Mirror, mapping e scrittura nello schema Master.
     */
    private function importJsonToMasterDB(): void
    {
        $this->importResults = null;

        if (!$this->targetSchema || !$this->institutionId) {
            \Filament\Notifications\Notification::make()
                ->title('Errore')
                ->body('Schema Mirror o Institution non selezionati')
                ->danger()
                ->send();

            $this->importResults = [
                'success' => false,
                'error' => 'Schema Mirror o Institution non selezionati',
                'processed' => 0,
                'success_count' => 0,
                'error_count' => 0,
                'warnings' => 0,
            ];

            return;
        }

        \Log::info('ImportJSONToMasterDB: Dispatching import job', [
            'target_schema' => $this->targetSchema,
            'institution_id' => $this->institutionId,
        ]);

        try {
            \App\Jobs\ImportMirrorToMasterJob::dispatch(
                $this->targetSchema,
                'json-to-master.yaml',
                $this->institutionId,
                'JSON'
            );

            $this->importResults = [
                'success' => true,
                'background_started' => true,
                'error' => null,
                'processed' => 0,
                'success_count' => 0,
                'error_count' => 0,
                'warnings' => 0,
            ];

            \Filament\Notifications\Notification::make()
                ->title('Importazione avviata')
                ->body('L\'importazione JSON → Master è stata accodata e verrà eseguita dal worker. Controlla i log per l\'esito.')
                ->success()
                ->send();
        } catch (\Exception $e) {
            \Log::error('ImportJSONToMasterDB: Error dispatching job', [
                'target_schema' => $this->targetSchema,
                'institution_id' => $this->institutionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->importResults = [
                'success' => false,
                'error' => $e->getMessage(),
                'processed' => 0,
                'success_count' => 0,
                'error_count' => 1,
                'warnings' => 0,
            ];

            \Filament\Notifications\Notification::make()
                ->title('Errore durante l\'avvio dell\'importazione')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Accoda la sincronizzazione immagini Mirror → Master (copy o vips).
     */
    public function importImagesToMasterDB(MirrorImageSyncMode $mode = MirrorImageSyncMode::Copy): void
    {
        $this->importResults = null;

        if (! $this->targetSchema || ! $this->institutionId) {
            $this->importResults = [
                'success' => false,
                'error' => 'Schema Mirror o Institution non selezionati',
                'processed' => 0,
                'success_count' => 0,
                'error_count' => 0,
                'skipped_count' => 0,
                'error_details' => [],
            ];

            Notification::make()
                ->title('Errore')
                ->body('Schema Mirror o Institution non selezionati')
                ->danger()
                ->send();

            return;
        }

        \Log::info('ImportImagesToMasterDB: dispatching sync job', [
            'target_schema' => $this->targetSchema,
            'institution_id' => $this->institutionId,
            'mode' => $mode->value,
        ]);

        try {
            SyncMirrorImagesJob::dispatch(
                $this->targetSchema,
                $this->institutionId,
                $mode
            );

            $this->importResults = [
                'success' => true,
                'background_started' => true,
                'sync_mode' => $mode->value,
                'error' => null,
                'processed' => 0,
                'success_count' => 0,
                'error_count' => 0,
                'skipped_count' => 0,
                'error_details' => [],
            ];

            Notification::make()
                ->title('Sincronizzazione immagini avviata')
                ->body(sprintf(
                    'Modalità: %s. Operazione accodata; verrà eseguita dal worker. Controlla i log per l\'esito.',
                    $mode->label()
                ))
                ->success()
                ->send();
        } catch (\Exception $e) {
            \Log::error('ImportImagesToMasterDB: error dispatching job', [
                'target_schema' => $this->targetSchema,
                'institution_id' => $this->institutionId,
                'mode' => $mode->value,
                'error' => $e->getMessage(),
            ]);

            $this->importResults = [
                'success' => false,
                'error' => $e->getMessage(),
                'processed' => 0,
                'success_count' => 0,
                'error_count' => 1,
                'skipped_count' => 0,
                'error_details' => [],
            ];

            Notification::make()
                ->title('Errore durante l\'avvio della sincronizzazione immagini')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Operazione inversa della sincronizzazione immagini: rimuove file in IMAGES_ROOT e righe in
     * iartnet_master.web_resources in base agli URL IIIF nel campo filename della tabella asset Mirror.
     *
     * @param  list<string>|null  $recordIdsFilter  Se valorizzato, limita alle righe asset con record_id in elenco.
     */
    public function unsynchronizeImagesFromMasterDB(?array $recordIdsFilter = null): void
    {
        $this->importResults = null;

        if (! $this->targetSchema || ! $this->institutionId) {
            $this->importResults = [
                'success' => false,
                'error' => 'Schema Mirror o Institution non selezionati',
                'processed' => 0,
                'success_count' => 0,
                'error_count' => 0,
                'skipped_count' => 0,
                'error_details' => [],
            ];

            Notification::make()
                ->title('Errore')
                ->body('Schema Mirror o Institution non selezionati')
                ->danger()
                ->send();

            return;
        }

        \Log::info('UnsynchronizeImagesFromMasterDB: starting', [
            'target_schema' => $this->targetSchema,
            'institution_id' => $this->institutionId,
            'record_ids_filter' => $recordIdsFilter,
        ]);

        $service = new UnsynchronizeMirrorImagesService();
        $result = $service->execute($this->targetSchema, $this->institutionId, $recordIdsFilter);

        $this->importResults = [
            'success' => $result['success'] && ($result['error'] === null),
            'error' => $result['error'],
            'processed' => $result['processed'],
            'success_count' => $result['success_count'],
            'error_count' => $result['error_count'],
            'skipped_count' => $result['skipped_count'],
            'error_details' => $result['error_details'],
        ];

        if ($result['error'] !== null) {
            Notification::make()
                ->title('Errore durante un-synchronize immagini')
                ->body($result['error'])
                ->danger()
                ->send();

            return;
        }

        $notification = Notification::make()
            ->title('Un-synchronize immagini completato')
            ->body(sprintf(
                'Processate: %d | Completate: %d | Errori: %d | Saltate (non IIIF): %d',
                $result['processed'],
                $result['success_count'],
                $result['error_count'],
                $result['skipped_count']
            ));

        if ($result['error_count'] > 0) {
            $notification->warning();
        } else {
            $notification->success();
        }

        $notification->send();
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeDetailsTab = $tab;
    }

    /**
     * Recupera l'import_run_id dal record MAIN selezionato.
     *
     * @return string|null
     */
    /**
     * Apre il modale Add Media (Record Details).
     */
    public function openAddMediaModal(): void
    {
        $this->addMediaFile = null;
        $this->showAddMediaModal = true;
    }

    /**
     * Chiude il modale Add Media senza salvare.
     */
    public function closeAddMediaModal(): void
    {
        $this->showAddMediaModal = false;
        $this->addMediaFile = null;
    }

    /**
     * Estensioni ammesse per Add Media (immagini).
     */
    private static function allowedImageExtensions(): array
    {
        return ['jpg', 'jpeg', 'png', 'tiff', 'tif', 'gif', 'webp', 'bmp'];
    }

    /**
     * Conferma l'aggiunta del file: salva il file in immagini/ e inserisce un record in asset.
     */
    public function confirmAddMedia(): void
    {
        if (! $this->targetSchema || ! $this->selectedRecordId) {
            Notification::make()
                ->title('Errore')
                ->body('Nessun record selezionato.')
                ->danger()
                ->send();
            $this->closeAddMediaModal();
            return;
        }

        if (! $this->addMediaFile instanceof TemporaryUploadedFile) {
            Notification::make()
                ->title('Errore')
                ->body('Selezionare un file immagine.')
                ->danger()
                ->send();
            return;
        }

        $ext = strtolower($this->addMediaFile->getClientOriginalExtension());
        if (! in_array($ext, self::allowedImageExtensions(), true)) {
            Notification::make()
                ->title('Errore')
                ->body('Estensione non consentita. Consentite: '.implode(', ', self::allowedImageExtensions()).'.')
                ->danger()
                ->send();
            return;
        }

        $importRunId = $this->getSelectedRecordImportRunId();
        if (! $importRunId) {
            Notification::make()
                ->title('Errore')
                ->body('Impossibile determinare la cartella di destinazione per il record.')
                ->danger()
                ->send();
            $this->closeAddMediaModal();
            return;
        }

        $extractionPath = IngestionPaths::extractionPath($importRunId);
        $immaginiPath = $extractionPath.DIRECTORY_SEPARATOR.'immagini';
        if (! is_dir($immaginiPath)) {
            if (! mkdir($immaginiPath, 0755, true)) {
                Notification::make()
                    ->title('Errore')
                    ->body('Impossibile creare la cartella immagini.')
                    ->danger()
                    ->send();
                $this->closeAddMediaModal();
                return;
            }
        }

        $baseName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $this->addMediaFile->getClientOriginalName()) ?: 'image';
        if (! str_contains($baseName, '.')) {
            $baseName .= '.'.$ext;
        }
        $destPath = $immaginiPath.DIRECTORY_SEPARATOR.$baseName;
        $counter = 1;
        while (file_exists($destPath)) {
            $baseName = pathinfo($baseName, PATHINFO_FILENAME).'_'.$counter.'.'.$ext;
            $destPath = $immaginiPath.DIRECTORY_SEPARATOR.$baseName;
            $counter++;
        }

        try {
            $tmpPath = $this->addMediaFile->getRealPath();
            if (! $tmpPath || ! is_file($tmpPath)) {
                throw new \RuntimeException('File temporaneo non disponibile');
            }
            if (! copy($tmpPath, $destPath)) {
                throw new \RuntimeException('Copia in cartella immagini fallita');
            }
        } catch (\Throwable $e) {
            \Log::error('Add Media: failed to save file', [
                'record_id' => $this->selectedRecordId,
                'dest' => $destPath,
                'error' => $e->getMessage(),
            ]);
            Notification::make()
                ->title('Errore')
                ->body('Impossibile salvare il file: '.$e->getMessage())
                ->danger()
                ->send();
            $this->closeAddMediaModal();
            return;
        }

        $sizeBytes = file_exists($destPath) ? filesize($destPath) : null;

        try {
            DB::statement("
                INSERT INTO \"{$this->targetSchema}\".asset
                (record_id, filename, exists_flag, promoted, size_bytes)
                VALUES (?, ?, true, false, ?)
            ", [
                $this->selectedRecordId,
                $baseName,
                $sizeBytes,
            ]);
        } catch (\Exception $e) {
            \Log::error('Add Media: failed to insert asset', [
                'record_id' => $this->selectedRecordId,
                'filename' => $baseName,
                'error' => $e->getMessage(),
            ]);
            Notification::make()
                ->title('Errore')
                ->body('File salvato ma inserimento in tabella asset fallito: '.$e->getMessage())
                ->danger()
                ->send();
            $this->closeAddMediaModal();
            return;
        }

        $this->closeAddMediaModal();
        Notification::make()
            ->title('Media aggiunto')
            ->body('Il file è stato salvato e associato al record.')
            ->success()
            ->send();
    }

    protected function getSelectedRecordImportRunId(): ?string
    {
        if (!$this->targetSchema || !$this->selectedRecordId) {
            return null;
        }

        try {
            $model = MirrorRecord::forSchema($this->targetSchema);
            $record = $model->newQuery()
                ->where('record_id', $this->selectedRecordId)
                ->first(['import_run_id']);

            return $record?->import_run_id;
        } catch (\Exception $e) {
            \Log::error('Error retrieving import_run_id', [
                'schema' => $this->targetSchema,
                'record_id' => $this->selectedRecordId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Recupera le immagini asset per il record selezionato.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getAssetImages()
    {
        if (!$this->targetSchema || !$this->selectedRecordId) {
            return collect([]);
        }

        try {
            $model = MirrorRecordAsset::forSchema($this->targetSchema);
            $assets = $model->newQuery()
                ->where('record_id', $this->selectedRecordId)
                ->where('exists_flag', true)
                ->orderBy('filename', 'asc')
                ->get(['id', 'filename', 'exists_flag', 'promoted']);

            return $assets;
        } catch (\Exception $e) {
            \Log::error('Error retrieving asset images', [
                'schema' => $this->targetSchema,
                'record_id' => $this->selectedRecordId,
                'error' => $e->getMessage(),
            ]);

            return collect([]);
        }
    }

    /**
     * Risolve il path del file immagine nella cartella di estrazione: cerca prima nella root, poi nel subfolder 'immagini'.
     *
     * @param  string  $extractionPath  Path della cartella di estrazione (folder della scheda)
     * @param  string  $filename  Nome file
     * @return string|null  Path completo se il file esiste, null altrimenti
     */
    private function resolveImagePathInExtraction(string $extractionPath, string $filename): ?string
    {
        $sep = DIRECTORY_SEPARATOR;
        $candidates = [
            $extractionPath.$sep.$filename,
            $extractionPath.$sep.'immagini'.$sep.$filename,
        ];
        foreach ($candidates as $path) {
            if (file_exists($path) && is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Costruisce il path completo del file immagine (per il record selezionato).
     * Cerca prima nella root del folder della scheda, poi nel subfolder 'immagini'.
     *
     * @param  string  $filename
     * @return string|null
     */
    public function getImagePath(string $filename): ?string
    {
        $importRunId = $this->getSelectedRecordImportRunId();
        if (! $importRunId) {
            return null;
        }
        $extractionPath = IngestionPaths::extractionPath($importRunId);
        return $this->resolveImagePathInExtraction($extractionPath, $filename);
    }

    /**
     * Restituisce l'URL (o data URI) da usare per il preview dell'immagine nella tab Images Preview.
     * - promoted = true: filename è l'URL IIIF → normalizzato per uso in <img src> (deve essere URL assoluto).
     * - promoted = false: filename è il nome file in ingestion → data URI dal file.
     *
     * @param  object  $asset  Oggetto con filename e promoted (da getAssetImages)
     * @return string|null  URL assoluto IIIF, data URI, o null se preview non disponibile
     */
    public function getImagePreviewSrc(object $asset): ?string
    {
        if ($asset->promoted ?? false) {
            $url = trim((string) $asset->filename);
            if ($url === '') {
                return null;
            }

            return $this->normalizeIiifUrlForPreview($url);
        }

        return $this->getImageDataUri((string) $asset->filename);
    }

    /**
     * Normalizza l'URL IIIF per uso in <img src>: il browser richiede uno schema (http/https).
     * Se l'URL salvato è tipo "localhost:8182/iiif/2/..." senza schema, viene preposto "http://".
     * Images Preview: IIIF size "max" → "800," per ridurre il carico in anteprima.
     *
     * @param  string  $url  URL IIIF (eventualmente senza scheme)
     * @return string  URL assoluto con scheme
     */
    private function normalizeIiifUrlForPreview(string $url): string
    {
        $url = trim($url);
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = 'http://'.ltrim($url, '/');
        }

        return str_replace('/max/', '/800,/', $url);
    }

    /**
     * Genera un data URI per l'immagine (per visualizzazione nel browser).
     *
     * @param  string  $filename
     * @return string|null
     */
    public function getImageDataUri(string $filename): ?string
    {
        $imagePath = $this->getImagePath($filename);
        if (!$imagePath) {
            return null;
        }

        try {
            $imageData = file_get_contents($imagePath);
            if ($imageData === false) {
                return null;
            }

            $imageInfo = getimagesize($imagePath);
            $mimeType = $imageInfo ? $imageInfo['mime'] : 'image/jpeg';

            return 'data:'.$mimeType.';base64,'.base64_encode($imageData);
        } catch (\Exception $e) {
            \Log::error('Error generating image data URI', [
                'filename' => $filename,
                'path' => $imagePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessFilament() ?? false;
    }
}
