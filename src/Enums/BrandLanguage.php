<?php

namespace App\Enums;

/**
 * Brand content language.
 *
 * Stored as VARCHAR(30) on `brands.language` DEFAULT 'it'.
 *
 * `it` → captions monolingual Italian.
 * `en` → captions monolingual English.
 * `it_en` → bilingual captions with separator `EN — IT —`
 *. Surfaces in plan generation.
 */
enum BrandLanguage: string
{
    case It = 'it';
    case En = 'en';
    case ItEn = 'it_en';

    /**
 * Italian human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::It => 'Italiano',
            self::En => 'Inglese',
            self::ItEn => 'Italiano + Inglese',
        };
    }

    /**
 * Dropdown options helper: ['it' => 'Italiano',..].
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
