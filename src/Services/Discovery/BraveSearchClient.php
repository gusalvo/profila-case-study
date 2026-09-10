<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Services\Discovery\DTOs\BraveResult;

/**
 * BraveSearchClient — Brave web-search HTTP boundary contract.
 *
 * NEVER throws — returns empty array on any failure (DISC-01: non-blocking).
 * Implementations: RealBraveSearchClient (Http + Brave API)
 * DemoBraveSearchClient (per-vertical fixtures, no HTTP call).
 *
 * Binding in AppServiceProvider::boot mirrors the AiClient → DemoAiClient
 * swap under config('ai.mode') === 'demo'.
 */
interface BraveSearchClient
{
    /**
     * Execute a web search query and return normalized results.
     * NEVER throws — returns empty array on any failure (DISC-01).
     *
     * @return list<BraveResult>
     */
    public function search(string $query): array;
}
