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
 * The normalize implementation is the codebase-canonical accent-fold, copied
 * verbatim from TerritoryPresenceMapper::normalize().
 *
 * Forbidden phrases are stored in their natural form (some already folded)
 * both the input text and each phrase are normalized at runtime, so accented
 * variants ("è debole") and their ASCII equivalents ("e debole") are equivalent.
 */
final class PrudentLanguageGate
{
    /**
     * Forbidden phrases, in natural Italian; normalized at runtime via normalize().
     *
     * The complete list is a product decision and is not part of these extracts.
     * Two representative entries remain, enough to show the shape and to exercise
     * the accent-insensitive matching below.
     *
     * @var list<string>
     */
    private const FORBIDDEN_PHRASES = [
        'il mercato è libero',   // forma accentata — normalize() la riduce a 'e libero'
        'quote di mercato',
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
     * verbatim copy from TerritoryPresenceMapper::normalize — the codebase-canonical
     * NFD accent-fold (NFD decompose → strip combining marks → mb_strtolower UTF-8).
     *
     * @see \App\Services\Discovery\TerritoryPresenceMapper::normalize
     */
    private function normalize(string $text): string
    {
        $nfd = Normalizer::normalize($text, Normalizer::FORM_D);
        $stripped = preg_replace('/\p{Mn}/u', '', $nfd ?? $text);

        return mb_strtolower($stripped ?? $text, 'UTF-8');
    }
}
