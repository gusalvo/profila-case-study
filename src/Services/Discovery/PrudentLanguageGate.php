<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use Normalizer;

/**
 * PrudentLanguageGate — deterministic forbidden-phrase gate.
 *
 * Blocks any text containing an forbidden phrase before it can reach
 * the DB or the PDF. Zero AI — this is a pure deterministic string scan.
 *
 * Matching is
 * Substring-based (phrase anywhere in the text).
 * Accent-insensitive via NFD accent-fold + mb_strtolower (Italian-safe, multi-byte).
 * The normalize() implementation is VERBATIM from TerritoryPresenceMapper::normalize()
 * (the codebase-canonical accent-fold, per 26-PATTERNS.md shared-patterns section).
 *
 * Forbidden phrases are stored in their natural form (some already folded)
 * both the input text and each phrase are normalized at runtime, so accented
 * variants ("è debole") and their ASCII equivalents ("e debole") are equivalent.
 */
final class PrudentLanguageGate
{
    /**
 * forbidden phrases.
 *
 * Stored in natural Italian; normalized at runtime via normalize().
 * Update this list only via ADR amendment — it is a locked product decision.
 *
 * @var list<string>
     */
    private const FORBIDDEN_PHRASES = [
        'comunica male',
        'non ha strategia',
        'è debole',          // accented form — normalize() folds to 'e debole'
        'e debole',          // already folded — covered by normalization anyway
        'puoi superarlo',
        'questo competitor domina',
        'il mercato è libero',
        'il mercato è scoperto',
        'ci sono quote disponibili',
        'hai un vantaggio competitivo',
        'quote di mercato',
        'spazi liberi nel mercato',
    ];

    /**
 * Returns true if the text passes the prudent-language gate (no forbidden phrases).
 * Returns false if any forbidden phrase is found (accent-insensitive, substring).
     */
    public function passes(string $text): bool
    {
        $normalizedText = $this->normalize($text);

        foreach (self::FORBIDDEN_PHRASES as $phrase) {
            $normalizedPhrase = $this->normalize($phrase);

            if (mb_strpos($normalizedText, $normalizedPhrase, 0, 'UTF-8') !== false) {
                return false;
            }
        }

        return true;
    }

    /**
 * Normalize text to lowercase accent-stripped form for Italian string matching.
 *
 * VERBATIM copy from TerritoryPresenceMapper::normalize() — the codebase-canonical
 * NFD accent-fold (NFD decompose → strip combining marks → mb_strtolower UTF-8).
 *
 * @see \App\Services\Discovery\TerritoryPresenceMapper::normalize()
     */
    private function normalize(string $text): string
    {
        $nfd = Normalizer::normalize($text, Normalizer::FORM_D);
        $stripped = preg_replace('/\p{Mn}/u', '', $nfd ?? $text);

        return mb_strtolower($stripped ?? $text, 'UTF-8');
    }
}
