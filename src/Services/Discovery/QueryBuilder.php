<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Models\Brand;

/**
 * QueryBuilder — builds Brave search query strings from vertical profile templates.
 *
 * Reads `discovery_query_templates` from the vertical profile array, applies token
 * Substitution, and enforces the ≤2 query hard cap.
 *
 * Tokens substituted
 * {città} → $brand->city (city preserved as-is, with accents)
 * {categoria} → mb_strtolower($brand->category->label())
 * {servizio} → first entry in $brand->brief->services (if loaded); otherwise token → ''
 *
 * If $brand->city is empty/null/whitespace-only → returns [] (non-blocking).
 */
final class QueryBuilder
{
    /**
     * Build ≤2 query strings from the vertical profile templates.
     *
     * @param Brand $brand Brand model (must have `category` cast + optional `brief` relation).
     * @param array<string, mixed> $profile Result of VerticalProfileProvider::forCategory.
     * @return list<string>
     */
    public function build(Brand $brand, array $profile): array
    {
 // City-empty guard: city missing → zero queries, non-blocking
        if (trim($brand->city ?? '') === '') {
            return [];
        }

 /** @var list<string> $templates*/
        $templates = $profile['discovery_query_templates'] ?? [];

        if ($templates === []) {
            return [];
        }

 // Hard cap: ≤2 queries
        $templates = array_slice($templates, 0, 2);

        $city          = $brand->city;
        $categoryLabel = mb_strtolower($brand->category->label(), 'UTF-8');

        // First BrandBrief service if the brief relation is loaded
        $servizio = '';
        $brief    = $brand->relationLoaded('brief') ? $brand->brief : null;
        if ($brief !== null && ! empty($brief->services)) {
            $servizio = (string) ($brief->services[0] ?? '');
        }

        $queries = [];
        foreach ($templates as $template) {
            $query = str_replace(
                ['{città}', '{categoria}', '{servizio}'],
                [$city, $categoryLabel, $servizio],
                $template
            );
            // Collapse extra whitespace left by an empty {servizio} token
            $query = (string) preg_replace('/\s{2,}/u', ' ', trim($query));
            if ($query !== '') {
                $queries[] = $query;
            }
        }

        return $queries;
    }
}
