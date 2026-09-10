<?php

declare(strict_types=1);

namespace App\Services\Discovery\DTOs;

/**
 * ExtractedHomepage — the CONF-05 observable-field-only result
 * CompetitorLightScraper::scan().
 *
 * Contains exactly the CONF-05 whitelist fields from CompetitorMetaExtractor.
 * NEVER contains: image URLs, any image field, social metrics, reviews, ratings.
 * The toArray() result is stored as metadata.observed_facts on the BrandSource.
 *
 * Plan 01 — CONF-05 / GR.
 */
final readonly class ExtractedHomepage
{
    /**
 * @param array<string, mixed> $fields CONF-05 whitelist fields only
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
