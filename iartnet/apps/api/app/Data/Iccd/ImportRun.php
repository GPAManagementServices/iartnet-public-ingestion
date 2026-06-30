<?php

declare(strict_types=1);

namespace App\Data\Iccd;

readonly class ImportRun
{
    public function __construct(
        public string $runId,
        public string $targetSchema,
        public string $extractionPath,
        public string $tmpPath,
        public string $runStoragePath,
        public int $totalFiles = 0,
        public int $xmlFiles = 0,
        public int $mediaFiles = 0,
        public array $warnings = [],
    ) {
    }

    public function getPackageJsonPath(): string
    {
        return $this->runStoragePath.'/package.json';
    }

    public function getValidationJsonPath(): string
    {
        return $this->runStoragePath.'/validation.json';
    }

    public function getImportJsonPath(): string
    {
        return $this->runStoragePath.'/import.json';
    }

    public function getLogsPath(): string
    {
        return $this->runStoragePath.'/logs.txt';
    }
}
