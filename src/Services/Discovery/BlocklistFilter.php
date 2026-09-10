<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Models\Brand;
use App\Services\Discovery\DTOs\BraveResult;

/**
 * BlocklistFilter — removes blocked/own-domain/listicle results from Brave results (DISC-03, DISC-04).
 *
 * Execution order is LOAD-BEARING (/ DISC-04)
 * 1. Domain blocklist check (config('discovery.blocklist_domains'))
 * 2. Brand own-domain exclusion (Brand.website host vs BraveResult.normalizedHost)
 * 3. Listicle-title rejection (config('discovery.listicle_patterns'))
 *
 * Step 3 MUST run before ConfidenceScorer — a listicle result can never become 'alta'.
 *
 * Blocklist matching modes (declared in config/discovery.php)
 * Exact host: 'booking.com' → matches booking.com AND www.booking.com
 * Wildcard TLD: 'tripadvisor.*' → matches tripadvisor.it, tripadvisor.com, etc.
 * Wildcard prefix: '*welcome.com' → matches bolognawelcome.com, etc.
 */
final class BlocklistFilter
{
    /**
     * Filter a list of BraveResult, removing blocked/listicle/own-domain entries.
     *
     * @param list<BraveResult> $results
     * @return list<BraveResult>
     */
    public function filter(array $results, Brand $brand): array
    {
        if ($results === []) {
            return [];
        }

 /** @var list<string> $blocklist*/
        $blocklist = config('discovery.blocklist_domains', []);

 /** @var list<string> $listiclePatterns*/
        $listiclePatterns = config('discovery.listicle_patterns', []);

        // Compute the brand's own normalized host once
        $brandHost = $this->normalizeBrandHost($brand);

        $filtered = [];

        foreach ($results as $result) {
            $host = $result->normalizedHost;
            // Strip leading www. for comparisons
            $bareHost = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

            // ── Step 1: Domain blocklist ────────────────────────────────────────
            if ($this->isBlocklisted($bareHost, $blocklist)) {
                continue;
            }

            // ── Step 2: Brand own-domain exclusion ──────────────────────────────
            if ($brandHost !== '' && ($bareHost === $brandHost || $host === $brandHost)) {
                continue;
            }

            // ── Step 3: Listicle-title rejection (MUST be BEFORE scoring) ───────
            if ($this->isListicle($result->title, $listiclePatterns)) {
                continue;
            }

            $filtered[] = $result;
        }

        return array_values($filtered);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Normalize the brand website to a bare host (no www., mb_strtolower, UTF-8).
     */
    private function normalizeBrandHost(Brand $brand): string
    {
        if (empty($brand->website)) {
            return '';
        }

        $host = mb_strtolower(parse_url($brand->website, PHP_URL_HOST) ?? '', 'UTF-8');

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * Check whether a bare host matches any blocklist pattern.
     *
     * Three matching modes
     * Exact: 'booking.com' matches 'booking.com'
     * Wildcard TLD: 'tripadvisor.*' matches 'tripadvisor.it', 'tripadvisor.com'
     * Wildcard prefix:'*welcome.com' matches 'bolognawelcome.com'
     *
     * @param list<string> $blocklist
     */
    private function isBlocklisted(string $bareHost, array $blocklist): bool
    {
        foreach ($blocklist as $pattern) {
            if (str_ends_with($pattern, '.*')) {
                // Wildcard TLD: 'tripadvisor.*' → prefix-match on 'tripadvisor.'
                $prefix = substr($pattern, 0, -2); // strip '.*'
                if (str_starts_with($bareHost, $prefix . '.') || $bareHost === $prefix) {
                    return true;
                }
            } elseif (str_starts_with($pattern, '*')) {
                // Wildcard prefix: '*welcome.com' → suffix-match
                $suffix = substr($pattern, 1); // strip '*'
                if (str_ends_with($bareHost, $suffix)) {
                    return true;
                }
            } else {
                // Exact match: 'booking.com'
                if ($bareHost === $pattern) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check whether a result title matches any listicle regex pattern.
     *
     * Listicle check MUST run before scoring (/ DISC-04).
     *
     * @param list<string> $listiclePatterns preg_match-compatible patterns
     */
    private function isListicle(string $title, array $listiclePatterns): bool
    {
        foreach ($listiclePatterns as $pattern) {
            if (preg_match($pattern, $title)) {
                return true;
            }
        }

        return false;
    }
}
