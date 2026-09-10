<?php

namespace App\Enums;

/**
 * Brand category — 11 casi (Vertical Packs v1.3).
 *
 * Stored as VARCHAR(30) on `brands.category` (mai
 * `$table->enum()`). Values are snake_case strings; labels are Italian.
 *
 * Sostituisce gli 8 casi MVP (hotel, bnb, hospitality, beauty, freelance
 * tour_operator, local, other). I casi rimossi sono hard-removed (non
 * deprecated). La migrazione dati 8→11 è in -07.
 *
 * Batch-1 deep verticals (isDeepVertical == true)
 * StrutturaRicettiva, RistoranteFood, Beauty, Professionista
 * Batch-2 generic (isDeepVertical == false — NEVER "specializzato")
 * TourEsperienze, Creator, WeddingEventi, Immobiliare, FitnessFormazione
 * NegozioRetail, Altro
 *
 * @see
 * @see.
 */
enum BrandCategory: string
{
 // Declaration order MUST match dropdown order (options uses cases()).
 // Common/deep-verticals first, Altro always last.
    case StrutturaRicettiva  = 'struttura_ricettiva';   // 19 chars — VARCHAR(30) safe
    case RistoranteFood      = 'ristorante_food';
    case TourEsperienze      = 'tour_esperienze';
    case Beauty              = 'beauty';                 // value UNCHANGED (Batch-1)
    case Professionista      = 'professionista';
    case Creator             = 'creator';
    case WeddingEventi       = 'wedding_eventi';
    case Immobiliare         = 'immobiliare';
    case FitnessFormazione   = 'fitness_formazione';
    case NegozioRetail       = 'negozio_retail';
    case Altro               = 'altro';                  // ALWAYS LAST

    /**
 * Italian human-readable label used in dropdowns + UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::StrutturaRicettiva => 'Struttura ricettiva',
            self::RistoranteFood     => 'Ristorante / Food',
            self::TourEsperienze     => 'Tour & Esperienze',
            self::Beauty             => 'Beauty & Wellness',
            self::Professionista     => 'Professionista / Consulente',
            self::Creator            => 'Creator / Personal brand',
            self::WeddingEventi      => 'Wedding & Eventi',
            self::Immobiliare        => 'Immobiliare',
            self::FitnessFormazione  => 'Fitness & Formazione',
            self::NegozioRetail      => 'Negozio / Retail',
            self::Altro              => 'Altro',
        };
    }

    /**
 * Depth-tier classification for branching.
 *
 * true = Batch-1 deep vertical (dedicated vertical_profile authored in).
 * false = Batch-2 generic (NEVER labelled "specializzato" / "ottimizzato per il
 * tuo settore" in UI copy — honest-positioning guardrail).
     */
    public function isDeepVertical(): bool
    {
        return match ($this) {
            self::StrutturaRicettiva,
            self::RistoranteFood,
            self::Beauty,
            self::Professionista => true,
            default              => false,  // binary flag — default is safe here
        };
    }

    /**
 * Dropdown options helper: ['struttura_ricettiva' => 'Struttura ricettiva',...].
 *
 * Declaration order drives dropdown order — no extra sorting needed.
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
