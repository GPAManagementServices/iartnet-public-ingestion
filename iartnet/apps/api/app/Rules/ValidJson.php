<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida che il valore sia una stringa JSON valida (o vuota/null).
 */
class ValidJson implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }
        if (! is_string($value)) {
            return;
        }
        json_decode($value);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $fail(__('Il valore deve essere JSON valido.'));
        }
    }
}
