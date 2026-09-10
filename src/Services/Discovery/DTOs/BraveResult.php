<?php

declare(strict_types=1);

namespace App\Services\Discovery\DTOs;

/**
 * BraveResult — normalized result from a Brave web-search API call (DISC-01).
 *
 * Produced by RealBraveSearchClient from `web.results[]` JSON items.
 * The `normalizedHost` is computed via parse_url($url, PHP_URL_HOST) + mb_strtolower
 * at parse time so downstream filters can do cheap host-level comparisons without
 * re-parsing the URL.
 *
 * This DTO contains no logic — pure value object.
 */
readonly class BraveResult
{
    public function __construct(
        public string $title,
        public string $url,
        public string $description,
        public string $normalizedHost, // parse_url($url, PHP_URL_HOST), mb_strtolower
    ) {
    }
}
