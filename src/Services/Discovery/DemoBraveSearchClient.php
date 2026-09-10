<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Enums\BrandCategory;
use App\Models\Brand;
use App\Services\Discovery\DTOs\BusinessCandidate;

/**
 * DemoBraveSearchClient — HTTP-free Brave client for AI_MODE=demo.
 *
 * Returns an empty list from search — zero HTTP calls, zero side-effects.
 * Per-vertical BusinessCandidate fixtures are dispatched via candidatesFor
 * called directly by SimilarBusinessFinder when it detects demo mode.
 *
 * Bound to BraveSearchClient::class in AppServiceProvider::boot when
 * config('ai.mode') === 'demo', mirroring the AiClient → DemoAiClient swap.
 */
final class DemoBraveSearchClient implements BraveSearchClient
{
    /**
     * Returns an empty array — no HTTP call issued in demo mode.
     *
 * NEVER throws — returns empty array.
     *
     * @return list<\App\Services\Discovery\DTOs\BraveResult>
     */
    public function search(string $query): array
    {
        return [];
    }

    /**
     * Return per-vertical BusinessCandidate fixtures for demo mode.
     *
     * Called by SimilarBusinessFinder::find when demo mode is active.
     * Uses realistic Italian business names / URLs / reasons per category.
     *
     * @return list<BusinessCandidate>
     */
    public function candidatesFor(Brand $brand): array
    {
        return match ($brand->category) {
            BrandCategory::StrutturaRicettiva => [
                new BusinessCandidate(
                    name: 'Hotel Bellavista Firenze',
                    url: 'https://hotelbellavistafirenze.it/',
                    reason: 'Struttura ricettiva boutique nel centro di Firenze con prenotazione diretta',
                    confidence: 'alta',
                ),
                new BusinessCandidate(
                    name: 'Pensione La Quiete',
                    url: 'https://pensionelaquiete.it/',
                    reason: 'B&B a conduzione familiare nella stessa area geografica',
                    confidence: 'media',
                ),
                new BusinessCandidate(
                    name: 'Villa Toscana Retreat',
                    url: 'https://villatoscana.it/',
                    reason: 'Struttura ricettiva nella Toscana, categoria simile',
                    confidence: 'media',
                ),
            ],
            BrandCategory::RistoranteFood => [
                new BusinessCandidate(
                    name: 'Trattoria da Mario',
                    url: 'https://trattoriamario.it/',
                    reason: 'Ristorante tradizionale con menu simile nella stessa città',
                    confidence: 'alta',
                ),
                new BusinessCandidate(
                    name: 'Osteria del Borgo',
                    url: 'https://osteriadelborgo.it/',
                    reason: 'Ristorazione locale nella stessa area geografica',
                    confidence: 'media',
                ),
                new BusinessCandidate(
                    name: 'Pizzeria Napoli Verace',
                    url: 'https://pizzerianapoli.it/',
                    reason: 'Attività food nello stesso quartiere',
                    confidence: 'bassa',
                ),
            ],
            BrandCategory::Beauty => [
                new BusinessCandidate(
                    name: 'Centro Estetico Dolce Vita',
                    url: 'https://centrodolcevita.it/',
                    reason: 'Centro beauty con servizi simili nella stessa area',
                    confidence: 'alta',
                ),
                new BusinessCandidate(
                    name: 'SPA Benessere Milano',
                    url: 'https://spabenessere.it/',
                    reason: 'SPA e centro wellness nella stessa città',
                    confidence: 'media',
                ),
                new BusinessCandidate(
                    name: 'Salone Capelli Belli',
                    url: 'https://salonecapellibelli.it/',
                    reason: 'Attività nel settore beauty locale',
                    confidence: 'bassa',
                ),
            ],
            BrandCategory::Professionista => [
                new BusinessCandidate(
                    name: 'Studio Rossi Consulenza',
                    url: 'https://studiorossi.it/',
                    reason: 'Professionista con specializzazione simile nella stessa città',
                    confidence: 'alta',
                ),
                new BusinessCandidate(
                    name: 'Bianchi & Associati',
                    url: 'https://bianchiassociati.it/',
                    reason: 'Studio professionale nella stessa area geografica',
                    confidence: 'media',
                ),
            ],
            default => [],
        };
    }
}
