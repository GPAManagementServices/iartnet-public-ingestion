<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Jobs\TranslateRecordJob;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

/**
 * Pagina Filament "Translation worker".
 *
 * Permette di avviare (Start Translation) o fermare (Stop Translation) il worker
 * che traduce le schede IT → EN tramite Libre Translate e Laravel Queue.
 */
class TranslationWorkerPage extends Page
{
    protected string $view = 'filament.pages.translation-worker-page';

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-language';
    }

    protected static ?string $navigationLabel = 'Translation';

    public static function getNavigationGroup(): ?string
    {
        return 'Master';
    }

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    protected static ?string $title = 'Translation worker';

    public function getHeading(): string
    {
        return 'Translation worker';
    }

    /** Indica se il worker è attualmente abilitato (flag di sistema). */
    public function isWorkerEnabled(): bool
    {
        return (bool) Cache::get(TranslateRecordJob::CACHE_KEY_ENABLED, false);
    }

    /** Abilita il worker: lo scheduler potrà inviare job di traduzione. */
    public function startTranslation(): void
    {
        Cache::put(TranslateRecordJob::CACHE_KEY_ENABLED, true);

        Notification::make()
            ->title('Translation worker avviato')
            ->body('Il worker è attivo. Lo scheduler invierà un job ogni minuto per tradurre le schede con is_translated = false.')
            ->success()
            ->send();
    }

    /** Disabilita il worker: nessun nuovo job verrà inviato dallo scheduler. */
    public function stopTranslation(): void
    {
        Cache::put(TranslateRecordJob::CACHE_KEY_ENABLED, false);

        Notification::make()
            ->title('Translation worker fermato')
            ->body('Il worker è stato disattivato. I job già in coda verranno ancora eseguiti.')
            ->warning()
            ->send();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessOperatoreSections() ?? false;
    }
}
