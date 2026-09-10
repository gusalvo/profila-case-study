<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\PlanFormat;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * HashtagCount — custom validation rule for hashtag count per content format.
 *
 * Format-aware matrix (CONTEXT.md)
 * gbp_post → 0 hashtags (Google Business Profile ignores hashtags)
 * story → 0-3 (optional minimal hashtags for Stories)
 * instagram / facebook / post / reel / carousel → 8-15 (spec)
 * LinkedIn context (carousel or post with linkedin channel) → 3-5
 *
 * For MVP: LinkedIn detection is channel-based. Since PlanItem stores
 * `suggested_channel`, we accept an optional $channel parameter for
 * more precise validation. Without channel, we apply the general cap
 * (0-15) for non-gbp/story formats.
 *
 * Note: X and Threads inline hashtags go in caption_short directly
 * the hashtags field is expected to be empty for those channels.
 * This rule validates empty strings as valid (nullable behavior).
 *
 * Lessons-learned: use mb_strlen for text; here count is hashtag token
 * count (preg_match_all), not byte count — safe for all encodings.
 */
class HashtagCount implements ValidationRule
{
    public function __construct(
        protected ?PlanFormat $format = null,
        protected ?string $channel = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
 // Empty / null hashtags field is always valid (nullable in rules)
        if ($value === null || $value === '') {
 // GBP: empty is correct (no hashtags allowed)
 // All other: empty is fine (operator may not want hashtags)
            return;
        }

 // Count hashtag tokens: words starting with # (Unicode-aware)
        preg_match_all('/#\S+/u', (string) $value, $matches);
        $count = count($matches[0]);

 // GBP Post: strictly 0 hashtags
        if ($this->format === PlanFormat::GbpPost) {
            if ($count > 0) {
                $fail(__('I post Google Business Profile non devono contenere hashtag.'));
            }
            return;
        }

 // Story: max 3 (optional — 0 is OK)
        if ($this->format === PlanFormat::Story) {
            if ($count > 3) {
                $fail(__('Le Stories possono avere al massimo 3 hashtag.'));
            }
            return;
        }

 // LinkedIn channel: 3-5 hashtags
        if ($this->isLinkedInChannel()) {
            if ($count < 3 || $count > 5) {
                $fail(__('Inserisci tra 3 e 5 hashtag per LinkedIn.'));
            }
            return;
        }

 // X / Threads: hashtags go inline in caption_short — field should be empty
        if ($this->isXOrThreadsChannel()) {
            if ($count > 0) {
                $fail(__('Per X e Threads, includi gli hashtag direttamente nella caption breve.'));
            }
            return;
        }

 // Instagram / Facebook / Post / Reel / Carousel (general): 8-15
 // Cap at 15 maximum; allow 0 for drafts being filled
        if ($count > 15) {
            $fail(__('Inserisci tra 8 e 15 hashtag per Instagram/Facebook.'));
        }
    }

    /**
 * Detect LinkedIn channel from $channel string (case-insensitive).
     */
    protected function isLinkedInChannel(): bool
    {
        return $this->channel !== null
            && str_contains(strtolower($this->channel), 'linkedin');
    }

    /**
 * Detect X or Threads channel from $channel string (case-insensitive).
     */
    protected function isXOrThreadsChannel(): bool
    {
        if ($this->channel === null) {
            return false;
        }
        $ch = strtolower($this->channel);
        return str_contains($ch, 'twitter')
            || str_contains($ch, '_x_')
            || $ch === 'x'
            || str_contains($ch, 'threads');
    }
}
