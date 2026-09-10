<?php

declare(strict_types=1);

namespace App\Services\Discovery\DTOs;

/**
 * BusinessCandidate — a similar-business result surfaced to the user for confirmation.
 *
 * Produced by SimilarBusinessFinder after BlocklistFilter + ConfidenceScorer have
 * processed the raw BraveResult list. Contains no logic — pure value object.
 *
 * Confidence labels map to Italian UI strings
 * 'alta' → "Pertinenza alta"
 * 'media' → "Da verificare"
 * 'bassa' → "Incerto"
 */
readonly class BusinessCandidate
{
    public function __construct(
        public string $name,        // estimated from result title
        public string $url,
        public string $reason,      // Italian: why this result was selected
        public string $confidence,  // 'alta' | 'media' | 'bassa'
    ) {
    }
}
