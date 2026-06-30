<?php

declare(strict_types=1);

namespace App\Data\Iccd;

readonly class ValidationIssue
{
    public function __construct(
        public string $file,
        public string $severity, // 'error' | 'warning' | 'info'
        public string $message,
        public ?string $schedaId = null,
        public ?int $line = null,
        public ?int $column = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'severity' => $this->severity,
            'message' => $this->message,
            'scheda_id' => $this->schedaId,
            'line' => $this->line,
            'column' => $this->column,
        ];
    }
}
