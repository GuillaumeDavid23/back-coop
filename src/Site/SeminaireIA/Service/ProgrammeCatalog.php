<?php

namespace App\Site\SeminaireIA\Service;

/**
 * Programme des deux journées du Séminaire IA - intervenants communiqués par
 * la suite par la cliente. Voir FareCatalog pour la même logique côté tarifs.
 */
final class ProgrammeCatalog
{
    /** @return array<int, array{time: string, label: string, tag: string}> */
    public static function day1(): array
    {
        return [
            ['time' => '11h00 - 12h30', 'label' => "Table ronde participative - L'IA redistribue les cartes : comment évoluent les cabinets ?", 'tag' => 'FORMATION'],
            ['time' => '12h30 - 14h00', 'label' => 'Déjeuner', 'tag' => 'PAUSE'],
            ['time' => '14h00 - 18h00', 'label' => "De la donnée à la décision : l'IA en pratique", 'tag' => 'FORMATION'],
            ['time' => '20h00', 'label' => 'Soirée festive au restaurant « Le Deauville »', 'tag' => 'CONVIVIALITÉ'],
        ];
    }

    /** @return array<int, array{time: string, label: string, tag: string}> */
    public static function day2(): array
    {
        return [
            ['time' => '09h30 - 12h30', 'label' => 'Atelier - Créez les outils de votre cabinet', 'tag' => 'FORMATION'],
            ['time' => '12h30 - 14h00', 'label' => 'Déjeuner', 'tag' => 'PAUSE'],
            ['time' => '14h00 - 16h00', 'label' => "Atelier - Faites de l'IA un outil d'aide à la décision", 'tag' => 'FORMATION'],
            ['time' => '16h00 - 17h00', 'label' => "Restitution - De l'expérimentation à la feuille de route", 'tag' => 'FORMATION'],
        ];
    }
}
