<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * MultibyteMax — character-length validation rule that counts CHARACTERS
 * not BYTES.
 *
 * Lessons-learned: Italian content contains accented
 * characters (è, à, ù, ò, ì) that occupy 2 UTF-8 bytes each, plus emoji
 * (4 bytes). `strlen()` returns byte length, so `max:N` is unreliable for
 * user-typed content. This rule uses `mb_strlen($value, 'UTF-8')` so a
 * sentence like "perché" counts as 6 characters (not 7 bytes).
 *
 * Threat: UTF-8 truncation tampering and mis-validation.
 */
class MultibyteMax implements ValidationRule
{
    public function __construct(private int $max) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return; // let other rules (string) handle the type
        }

        if (mb_strlen($value, 'UTF-8') > $this->max) {
            $fail(__('Il contenuto può avere al massimo :max caratteri.', ['max' => $this->max]));
        }
    }
}
