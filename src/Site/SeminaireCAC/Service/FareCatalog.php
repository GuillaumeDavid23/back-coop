<?php

namespace App\Site\SeminaireCAC\Service;

/**
 * Catalogue des forfaits du Séminaire CAC — propre à cet événement (chaque
 * site définit sa propre grille tarifaire, il n'y a pas d'entité Fare
 * partagée dans le core : voir Registration::fareCode/fareLabel qui
 * stockent un instantané de ce qui est choisi ici au moment de l'inscription).
 */
final class FareCatalog
{
    /** @return array<string, array{label: string, amount: string}> */
    public static function all(): array
    {
        return [
            'cooperateur' => ['label' => 'Coopérateur', 'amount' => '850.00'],
            'non_cooperateur' => ['label' => 'Non coopérateur', 'amount' => '950.00'],
            'anecs_cjec' => ['label' => 'ANECS / CJEC — Jeune CAC', 'amount' => '400.00'],
        ];
    }

    public static function find(string $code): ?array
    {
        return self::all()[$code] ?? null;
    }
}
