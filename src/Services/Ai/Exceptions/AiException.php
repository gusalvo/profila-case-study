<?php

namespace App\Services\Ai\Exceptions;

use Exception;

/**
 * Base AI exception.
 *
 * All AI-failure exceptions extend this so a single catch-all is possible
 * if ever needed (e.g. centralized logging) — but the BriefGenerator + UI
 * layer catches the typed children individually to map each one to its IT
 * verbatim message (of).
 *
 * Consumed
 * AnthropicClient (Plan 03 — throws typed children)
 * BriefGenerator (Plan 04 — catches typed children, logs via AiUsageLogger)
 * Brief\Show Livewire component (Plan 05 — maps to IT messages)
 */
class AiException extends Exception
{
}
