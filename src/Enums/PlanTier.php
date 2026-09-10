<?php

namespace App\Enums;

/**
 * User subscription tier.
 *
 * Stored as VARCHAR(30) on `users.plan_tier` (NEVER `$table->enum()`).
 *
 * The free tier is capped at 1 brand and 1 active plan per month (`User::canCreateBrand()`
 * `User::canCreatePlanThisMonth()`). The pro tier is capped at 3 brands and 4 plans
 * month; Free at 1/1. Tier-capability methods on User are the single source
 * truth for all downstream gates.
 */
enum PlanTier: string
{
    case Free = 'free';
    case Pro = 'pro';

    /**
 * Italian human-readable label used in dropdowns + UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Free => 'Gratuito',
            self::Pro => 'Pro',
        };
    }

    /**
 * Dropdown options helper: ['free' => 'Gratuito', 'pro' => 'Pro'].
 * Reserved for future pricing/upgrade UI.
 *
 * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
