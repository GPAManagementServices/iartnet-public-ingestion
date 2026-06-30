<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Console\Migrations\MigrateCommand as BaseMigrateCommand;
use Illuminate\Database\Migrations\Migrator;

/**
 * Wrapper del comando migrate che rispetta MIGRATIONS_ENABLED.
 * Quando MIGRATIONS_ENABLED=false (es. dopo un restore da backup),
 * il comando non esegue le migration e termina con exit 0 (nessun prompt, safe per Docker/CI).
 * Costruttore compatibile con Laravel 12 (Migrator + Dispatcher).
 */
class MigrateCommand extends BaseMigrateCommand
{
    public function __construct(Migrator $migrator, Dispatcher $dispatcher)
    {
        parent::__construct($migrator, $dispatcher);
    }

    /**
     * Execute the console command.
     * In headless/Docker: se migrations disabilitate, solo messaggio e exit 0 (no confirm/prompt).
     */
    public function handle(): int
    {
        if (! config('database.migrations_enabled', true)) {
            $this->components->warn(
                'Migrations are disabled (MIGRATIONS_ENABLED=false). Set MIGRATIONS_ENABLED=true to run them.'
            );

            return self::SUCCESS;
        }

        return parent::handle();
    }
}
