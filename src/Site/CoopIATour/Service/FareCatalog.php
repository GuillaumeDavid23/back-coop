<?php

namespace App\Site\CoopIATour\Service;

/**
 * Catalogue des forfaits de La Coop' IA Tour - propre à cet événement (chaque
 * site définit sa propre grille tarifaire, il n'y a pas d'entité Fare
 * partagée dans le core : voir Registration::fareCode/fareLabel qui
 * stockent un instantané de ce qui est choisi ici au moment de l'inscription).
 *
 * Montants en euros HT - la TVA (20 %) est appliquée à l'encaissement, comme
 * pour le Séminaire IA.
 */
final class FareCatalog
{
    public const string TAX_RATE = '20.00';

    /** @return array<string, array{label: string, amount: string}> */
    public static function all(): array
    {
        return [
            'cooperateur' => ['label' => 'Coopérateur', 'amount' => '490.00'],
            'non_cooperateur' => ['label' => 'Non coopérateur', 'amount' => '690.00'],
            'anecs_cjec' => ['label' => 'ANECS / CJEC', 'amount' => '250.00'],
        ];
    }

    public static function find(string $code): ?array
    {
        return self::all()[$code] ?? null;
    }

    /** TTC = HT × 1,20 (TAX_RATE), arrondi au centime. */
    public static function inclTax(string $amountExclTax): string
    {
        $withTax = bcmul($amountExclTax, bcadd('1', bcdiv(self::TAX_RATE, '100', 4), 4), 4);

        return number_format((float) $withTax, 2, '.', '');
    }
}
