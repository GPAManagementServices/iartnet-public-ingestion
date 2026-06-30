<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\MasterData\CardsStatService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class DataStatsPage extends Page
{
    protected string $view = 'filament.pages.data-stats-page';

    protected static ?string $navigationLabel = 'Stats';

    protected static ?string $title = 'Data_Stats';

    /** @var array<int, array{name:string|null, tot_cards:int}> */
    public array $stats = [];

    public static function getNavigationGroup(): ?string
    {
        return 'Master';
    }

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-chart-bar';
    }

    public function mount(CardsStatService $cardsStatService): void
    {
        try {
            $rows = $cardsStatService->getCardsStat();

            $this->stats = array_map(static function (array $row): array {
                return [
                    'name' => $row['name'] ?? null,
                    'tot_cards' => (int) ($row['tot_cards'] ?? 0),
                ];
            }, $rows);
        } catch (\Throwable $e) {
            $this->stats = [];

            Notification::make()
                ->title('Errore')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessOperatoreSections() ?? false;
    }
}

