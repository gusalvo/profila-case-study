<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Models\Brand;
use App\Services\Discovery\DTOs\BraveResult;

/**
 * ConfidenceScorer — assigns deterministic alta|media|bassa confidence to BraveResults (DISC-05).
 *
 * Reads weights and thresholds from config('discovery.confidence') — data stays in config
 * PHP logic stays stable for post-deploy tuning.
 *
 * Scoring signals (additive)
 * category_in_title → mb_stripos(title, categoryLabel)
 * city_in_title → mb_stripos(title, city)
 * category_in_snippet → mb_stripos(description, categoryLabel)
 * city_in_snippet → mb_stripos(description, city)
 * clean_domain → no path depth / short TLD (homepageish URL)
 * shallow_url_depth → parse_url path depth ≤ 1
 *
 * Label mapping
 * score >= thresholds['alta'] → 'alta'
 * score >= thresholds['media'] → 'media'
 * else → 'bassa'
 *
 * Returns results paired with confidence, sorted alta → media → bassa (DISC-05).
 *
 * MUST be called AFTER BlocklistFilter (/ DISC-04).
 */
final class ConfidenceScorer
{
    /**
     * Score a list of BraveResult and return them paired with confidence, alta-first.
     *
     * @param list<BraveResult> $results
     * @return list<array{result: BraveResult, confidence: string}>
     */
    public function score(array $results, Brand $brand): array
    {
        if ($results === []) {
            return [];
        }

 /** @var array<string, int> $weights*/
        $weights = config('discovery.confidence.weights', []);

 /** @var array<string, int> $thresholds*/
        $thresholds = config('discovery.confidence.thresholds', [
            'alta'  => 6,
            'media' => 3,
        ]);

        $categoryLabel = mb_strtolower($brand->category->label(), 'UTF-8');
        $city          = mb_strtolower($brand->city ?? '', 'UTF-8');

        $scored = [];
        foreach ($results as $result) {
            $score = $this->computeScore($result, $categoryLabel, $city, $weights);
            $confidence = $this->mapToLabel($score, $thresholds);
            $scored[] = ['result' => $result, 'confidence' => $confidence, '_score' => $score];
        }

        // Sort: alta → media → bassa (stable within same label by original order)
        $order = ['alta' => 2, 'media' => 1, 'bassa' => 0];
        usort($scored, function (array $a, array $b) use ($order): int {
            $cmp = ($order[$b['confidence']] ?? 0) <=> ($order[$a['confidence']] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }

            return $b['_score'] <=> $a['_score'];
        });

        // Strip internal _score field from output
        return array_values(array_map(function (array $item): array {
            unset($item['_score']);

            return $item;
        }, $scored));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Compute additive score for one BraveResult.
     *
     * @param array<string, int> $weights
     */
    private function computeScore(BraveResult $result, string $categoryLabel, string $city, array $weights): int
    {
        $score = 0;

        $titleLower = mb_strtolower($result->title, 'UTF-8');
        $descLower  = mb_strtolower($result->description, 'UTF-8');

        // Signal: category in title
        if ($categoryLabel !== '' && mb_stripos($titleLower, $categoryLabel, 0, 'UTF-8') !== false) {
            $score += $weights['category_in_title'] ?? 3;
        }

        // Signal: city in title
        if ($city !== '' && mb_stripos($titleLower, $city, 0, 'UTF-8') !== false) {
            $score += $weights['city_in_title'] ?? 2;
        }

        // Signal: category in snippet/description
        if ($categoryLabel !== '' && mb_stripos($descLower, $categoryLabel, 0, 'UTF-8') !== false) {
            $score += $weights['category_in_snippet'] ?? 2;
        }

        // Signal: city in snippet
        if ($city !== '' && mb_stripos($descLower, $city, 0, 'UTF-8') !== false) {
            $score += $weights['city_in_snippet'] ?? 1;
        }

        // Signal: shallow URL depth (path ≤ 1 segment)
        $path  = parse_url($result->url, PHP_URL_PATH) ?? '';
        $depth = $this->urlPathDepth($path);
        if ($depth <= 1) {
            $score += $weights['shallow_url_depth'] ?? 1;
        }

        // Signal: clean domain (shallow depth + no query params)
        $hasQuery = parse_url($result->url, PHP_URL_QUERY) !== null;
        if ($depth <= 1 && ! $hasQuery) {
            $score += $weights['clean_domain'] ?? 2;
        }

        return $score;
    }

    /**
     * Count meaningful segments in a URL path (ignoring trailing slashes).
     */
    private function urlPathDepth(string $path): int
    {
        $path     = trim($path, '/');
        $segments = array_filter(explode('/', $path), fn (string $s): bool => $s !== '');

        return count($segments);
    }

    /**
     * Map numeric score to confidence label.
     *
     * @param array<string, int> $thresholds
     */
    private function mapToLabel(int $score, array $thresholds): string
    {
        if ($score >= ($thresholds['alta'] ?? 6)) {
            return 'alta';
        }
        if ($score >= ($thresholds['media'] ?? 3)) {
            return 'media';
        }

        return 'bassa';
    }
}
