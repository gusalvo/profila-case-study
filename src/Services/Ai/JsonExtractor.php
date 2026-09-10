<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Exceptions\InvalidAiResponseException;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * JsonExtractor — strip markdown fence + prose-prefix + parse to array
 *
 * Why static: this is a pure transformation. No DI needed; no state.
 *
 * The extractor is conservative — it strips the wrappers but does NOT
 * "repair" malformed JSON. If `json_decode` fails after stripping, the
 * caller (BriefGenerator in) decides whether to retry.
 *
 * Key-leak guard: logs ONLY the raw RESPONSE text
 * truncated to 2000 chars via `mb_substr(..., 'UTF-8')` (multibyte mandate). It NEVER logs the request body, the system prompt
 * or the API key — those are not in scope here anyway.
 */
final class JsonExtractor
{
    /**
 * Extract a JSON object/array from an LLM raw text response.
 *
 * @return array<string, mixed>|list<mixed>
 *
 * @throws InvalidAiResponseException If the response cannot be parsed
 * as JSON even after stripping the
 * markdown fence and prose prefix.
     */
    public static function extract(string $raw): array
    {
        $text = trim($raw);

 // Strip markdown fence: ```json... ``` or ```... ``` (anchored
 // multiline, so a fence in the middle is left alone).
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/m', '', $text) ?? $text;

 // Strip prose before the first { or [ (Unicode-safe).
        $text = preg_replace('/^[^{\[]*([{\[])/u', '$1', $text) ?? $text;

        $text = trim($text);

        try {
 /** @var array<string, mixed>|list<mixed> $decoded*/
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (JsonException $e) {
 // mb_substr UTF-8 — Italian accents are multi-byte.
 //truncate BEFORE logging to avoid dumping multi-MB
 // payloads if Anthropic ever sends one.
            Log::warning('AI JSON parse failed', [
                'raw' => mb_substr($raw, 0, 2000, 'UTF-8'),
                'cleaned' => mb_substr($text, 0, 2000, 'UTF-8'),
                'error' => $e->getMessage(),
            ]);

            throw new InvalidAiResponseException(
                'Could not parse AI output as JSON: '.$e->getMessage(),
                0,
                $e,
            );
        }
    }
}
