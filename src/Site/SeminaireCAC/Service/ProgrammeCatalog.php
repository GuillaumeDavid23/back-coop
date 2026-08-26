<?php

namespace App\Site\SeminaireCAC\Service;

/** Programme des deux journées - propre à cet événement, voir FareCatalog pour la même logique côté tarifs. */
final class ProgrammeCatalog
{
    /** @return array<int, array{time: string, label: string, tag: string}> */
    public static function day1(): array
    {
        return self::withTags([
            ['08h30 - 09h00', 'Accueil café'],
            ['09h00 - 13h00', 'VSME : pourquoi les CAC doivent accompagner les PME et comment peuvent-ils le faire ?'],
            ['13h00 - 14h00', 'Déjeuner'],
            ['14h00 - 18h00', "L'audit avec l'IA - application concrète"],
            ['18h00 - 19h00', 'Cocktail apéritif'],
        ]);
    }

    /** @return array<int, array{time: string, label: string, tag: string}> */
    public static function day2(): array
    {
        return self::withTags([
            ['08h30 - 09h00', 'Accueil café'],
            ['09h00 - 13h00', 'Automatisation des contrôles et des sondages : quels outils, quels points de vigilance'],
            ['13h00 - 14h00', 'Déjeuner'],
            ['14h00 - 16h00', "L'audit des Associations : les points de vigilance et les attentes de la H2A"],
            ['16h00 - 18h00', "ISA 600 comment bien l'appliquer"],
        ]);
    }

    /** @param array<int, array{0: string, 1: string}> $slots
     * @return array<int, array{time: string, label: string, tag: string}> */
    private static function withTags(array $slots): array
    {
        return array_map(static function (array $slot): array {
            [$time, $label] = $slot;
            $lower = mb_strtolower($label);
            $tag = match (true) {
                str_contains($lower, 'café') || str_contains($lower, 'déjeuner') => 'PAUSE',
                str_contains($lower, 'cocktail') => 'CONVIVIALITÉ',
                default => 'FORMATION',
            };

            return ['time' => $time, 'label' => $label, 'tag' => $tag];
        }, $slots);
    }
}
