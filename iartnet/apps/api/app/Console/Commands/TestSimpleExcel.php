<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\SimpleExcel\SimpleExcelReader;

class TestSimpleExcel extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-simple-excel {file? : Path to XLSX file to read}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test reading XLSX files with spatie/simple-excel';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $filePath = $this->argument('file') ?? storage_path('addedFields/ICCD_OA300.xlsx');

        if (! file_exists($filePath)) {
            $this->error("File not found: {$filePath}");

            return Command::FAILURE;
        }

        $this->info("Reading file: {$filePath}");

        try {
            $reader = SimpleExcelReader::create($filePath)->getRows();

            $rowCount = 0;
            $maxRows = 10; // Limita a 10 righe per il test

            foreach ($reader as $row) {
                $rowCount++;

                if ($rowCount === 1) {
                    $this->info('Headers: '.json_encode(array_keys($row), JSON_PRETTY_PRINT));
                }

                $this->line("Row {$rowCount}: ".json_encode($row, JSON_PRETTY_PRINT));

                if ($rowCount >= $maxRows) {
                    $this->warn("Showing first {$maxRows} rows only...");

                    break;
                }
            }

            $this->info("Total rows read: {$rowCount}");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Error reading file: {$e->getMessage()}");
            $this->error("Stack trace: {$e->getTraceAsString()}");

            return Command::FAILURE;
        }
    }
}
