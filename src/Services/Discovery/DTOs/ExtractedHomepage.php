<?php

declare(strict_types=1);

namespace App\Services\Discovery\DTOs;

/**
 * ExtractedHomepage — the observable-field-only result
 * CompetitorLightScraper::scan.
 *
 * Contains exactly the whitelist fields from CompetitorMetaExtractor.
 * NEVER contains: image URLs, any image field, social metrics, reviews, ratings.
 * The toArray result is stored as metadata.observed_facts on the BrandSource.
 *
 * / GR.
 */
final readonly class ExtractedHomepage
{
    /**
 * @param array<string, mixed> $fields whitelist fields only
     */
    public function __construct(
        public array $fields,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->fields;
    }
}
