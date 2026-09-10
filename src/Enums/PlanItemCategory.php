<?php

namespace App\Enums;

/**
 * Categoria strategica di un PlanItem.
 *
 * Stored as VARCHAR(30) on `plan_items.category` NULL (mai `$table->enum()`). NULL = in attesa di backfill.
 *
 * 7 casi bloccati
 * servizi → Servizi
 * territorio → Territorio
 * recensioni → Recensioni
 * dietro_le_quinte → Dietro le quinte
 * offerte → Offerte
 * educativo → Educativo
 * brand_valori → Brand/Valori
 *
 * color restituisce array{border, badge, chip} con le classi Tailwind
 * verbatim — NESSUNA concatenazione dinamica 'bg-'.$x.'-100'
 * che Tailwind 4 purgerebbe.
 *
 * Mirrors PlanItemContentStatus.php structure (backed string enum
 * label match, static options via collect()->mapWithKeys()).
 */
enum PlanItemCategory: string
{
    case Servizi        = 'servizi';
    case Territorio     = 'territorio';
    case Recensioni     = 'recensioni';
    case DietroLeQuinte = 'dietro_le_quinte';
    case Offerte        = 'offerte';
    case Educativo      = 'educativo';
    case BrandValori    = 'brand_valori';

    /**
 * Etichetta italiana per dropdown e badge categoria.
     */
    public function label(): string
    {
        return match ($this) {
            self::Servizi        => 'Servizi',
            self::Territorio     => 'Territorio',
            self::Recensioni     => 'Recensioni',
            self::DietroLeQuinte => 'Dietro le quinte',
            self::Offerte        => 'Offerte',
            self::Educativo      => 'Educativo',
            self::BrandValori    => 'Brand/Valori',
        };
    }

    /**
 * Classi Tailwind per bordo sinistro (card), badge e chip.
 *
 * Stringhe verbatim da the design spec colour table.
 * MAI concatenazione dinamica — Tailwind 4 purge scansiona stringhe
 * letterali (anti-stack note).
 *
 * @return array{border: string, badge: string, chip: string}
     */
    public function color(): array
    {
        return match ($this) {
            self::Servizi        => [
                'border' => 'border-l-4 border-emerald-500',
                'badge'  => 'bg-emerald-950 text-emerald-300 border border-emerald-700',
                'chip'   => 'bg-emerald-950 text-emerald-300',
            ],
            self::Territorio     => [
                'border' => 'border-l-4 border-sky-500',
                'badge'  => 'bg-sky-950 text-sky-300 border border-sky-700',
                'chip'   => 'bg-sky-950 text-sky-300',
            ],
            self::Recensioni     => [
                'border' => 'border-l-4 border-violet-500',
                'badge'  => 'bg-violet-950 text-violet-300 border border-violet-700',
                'chip'   => 'bg-violet-950 text-violet-300',
            ],
            self::DietroLeQuinte => [
                'border' => 'border-l-4 border-rose-500',
                'badge'  => 'bg-rose-950 text-rose-300 border border-rose-700',
                'chip'   => 'bg-rose-950 text-rose-300',
            ],
            self::Offerte        => [
                'border' => 'border-l-4 border-amber-500',
                'badge'  => 'bg-amber-950 text-amber-300 border border-amber-700',
                'chip'   => 'bg-amber-950 text-amber-300',
            ],
            self::Educativo      => [
                'border' => 'border-l-4 border-teal-500',
                'badge'  => 'bg-teal-950 text-teal-300 border border-teal-700',
                'chip'   => 'bg-teal-950 text-teal-300',
            ],
            self::BrandValori    => [
                'border' => 'border-l-4 border-zinc-400',
                'badge'  => 'bg-zinc-800 text-zinc-200 border border-zinc-600',
                'chip'   => 'bg-zinc-800 text-zinc-200',
            ],
        };
    }

    /**
 * Opzioni per dropdown: ['servizi' => 'Servizi',..].
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
