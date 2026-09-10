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
 * AnthropicClient
 * BriefGenerator
 * Brief\Show Livewire component
 */
class AiException extends Exception
{
}
