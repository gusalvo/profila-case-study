<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Services\Discovery\DTOs\BraveResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RealBraveSearchClient — Http-backed Brave Search API client.
 *
 * NEVER throws — every failure path (missing key / HTTP non-200 / transport error)
 * returns an empty list and emits a Log::warning. This guarantees the discovery
 * pipeline is non-blocking regardless of Brave API availability.
 *
 * NOT final — test doubles and Http::fake must be able to intercept.
 * (Mockery 1.6.x cannot mock final classes; keep non-final to
 * match WebsiteScraper's posture.)
 *
 * Key is read ONLY via config('services.brave.api_key') — NEVER direct env.
 * The key is NEVER logged (Log::warning logs status/message only).
 */
class RealBraveSearchClient implements BraveSearchClient
{
    private const USER_AGENT = 'Profila.pro/1.0 (+contact@profila.pro)';

    private const ENDPOINT = 'https://api.search.brave.com/res/v1/web/search';

    /**
     * Execute a Brave web search query and return normalized BraveResult DTOs.
     *
 * NEVER throws — returns empty array on any failure.
     *
     * @return list<BraveResult>
     */
    public function search(string $query): array
    {
        $apiKey = config('services.brave.api_key');

        //missing/empty key → graceful empty, no exception
        if (empty($apiKey)) {
 Log::warning('BraveSearchClient: api_key is not configured — returning empty result.', [
                'query' => $query,
            ]);

            return [];
        }

        try {
            $response = Http::withHeaders([
                'X-Subscription-Token' => $apiKey,
                'User-Agent'           => self::USER_AGENT,
                'Accept'               => 'application/json',
            ])
                ->timeout(5)
                ->connectTimeout(3)
                ->get(self::ENDPOINT, [
                    'q'           => $query,
                    'country'     => 'it',
                    'search_lang' => 'it',
                    'count'       => 5,
                ]);
        } catch (ConnectionException $e) {
 Log::warning('BraveSearchClient: connection error — returning empty result.', [
                'query'   => $query,
                'message' => $e->getMessage(),
            ]);

            return [];
        } catch (Throwable $e) {
 Log::warning('BraveSearchClient: transport error — returning empty result.', [
                'query'   => $query,
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        if (! $response->successful()) {
 Log::warning('BraveSearchClient: non-200 response — returning empty result.', [
                'query'  => $query,
                'status' => $response->status(),
            ]);

            return [];
        }

        //Brave response path is `web.results`, NOT `results`.
        $rawResults = $response->json('web.results', []);

        $results = [];
        foreach ($rawResults as $item) {
            $url = $item['url'] ?? '';

            if (empty($url)) {
                continue;
            }

            $host = mb_strtolower(parse_url($url, PHP_URL_HOST) ?? '', 'UTF-8');

            if (empty($host)) {
                continue;
            }

            $results[] = new BraveResult(
                title:          $item['title'] ?? '',
                url:            $url,
                description:    $item['description'] ?? '',
                normalizedHost: $host,
            );
        }

        return $results;
    }
}
