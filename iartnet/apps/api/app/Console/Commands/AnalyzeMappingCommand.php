<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AnalyzeMappingCommand extends Command
{
    protected $signature = 'mapping:analyze {schema=brmirro1 : Nome dello schema mirror}';

    protected $description = 'Analizza gli xpath nello schema mirror per aggiornare i file di mapping';

    public function handle(): int
    {
        $schemaName = $this->argument('schema');

        try {
            // Verifica se lo schema esiste
            $schemaExists = DB::selectOne("
                SELECT EXISTS (
                    SELECT 1 
                    FROM information_schema.schemata 
                    WHERE schema_name = ?
                ) as exists
            ", [$schemaName]);

            if (!$schemaExists || !$schemaExists->exists) {
                $this->error("Schema '{$schemaName}' non trovato.");
                return 1;
            }

            // Estrae tutti gli xpath distinti
            $xpaths = DB::select("
                SELECT DISTINCT xpath, COUNT(*) as count
                FROM \"{$schemaName}\".kv
                WHERE xpath IS NOT NULL
                GROUP BY xpath
                ORDER BY xpath ASC
            ");

            $this->info("=== XPath trovati nello schema '{$schemaName}' ===");
            $this->info("Totale xpath distinti: " . count($xpaths));
            $this->newLine();

            $xpathList = [];
            foreach ($xpaths as $row) {
                $this->line(sprintf("%-80s (occorrenze: %d)", $row->xpath, $row->count));
                $xpathList[] = $row->xpath;
            }

            // Salva in un file JSON per analisi
            $outputPath = storage_path('mapping/'.$schemaName.'-xpaths.json');
            file_put_contents($outputPath, json_encode($xpathList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->newLine();
            $this->info("XPath salvati in: {$outputPath}");

            return 0;
        } catch (\Exception $e) {
            $this->error("Errore: " . $e->getMessage());
            return 1;
        }
    }
}
