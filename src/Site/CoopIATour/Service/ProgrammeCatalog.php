<?php

namespace App\Site\CoopIATour\Service;

/**
 * Programme des deux journées de l'étape parisienne (26 et 27 novembre 2026,
 * Comet Bercy), d'après le mail de Marion BROYER du 30/10/2026 - « susceptible
 * de modification ». Voir FareCatalog pour la même logique côté tarifs.
 */
final class ProgrammeCatalog
{
    /** @return array<int, array{time: string, label: string, tag: string}> */
    public static function day1(): array
    {
        return self::withTags([
            ['10h00 - 10h30', 'Accueil café'],
            ['10h30 - 12h30', "Table ronde participative - L'IA redistribue les cartes : comment évoluent les cabinets ?"],
            ['12h30 - 14h00', 'Déjeuner'],
            ['14h00 - 15h15', "Bloc 1 : Poser le cadre et prendre la main sur l'outil"],
            ['15h15 - 16h15', 'Bloc 2 : Analyser la donnée du cabinet'],
            ['16h15 - 16h30', 'Pause'],
            ['16h30 - 17h30', 'Bloc 3 : Synthétiser et préparer la relation client'],
            ['17h30 - 18h00', 'Bloc 4 : Produire les livrables et les supports'],
            ['18h00 - 19h00', 'Cocktail apéritif'],
        ]);
    }

    /** @return array<int, array{time: string, label: string, tag: string}> */
    public static function day2(): array
    {
        return self::withTags([
            ['09h00 - 09h30', 'Accueil café'],
            ['09h30 - 10h30', 'Atelier avancé - Créez les outils de votre cabinet'],
            ['10h30 - 11h30', 'Révision comptable assistée par IA - cas pratiques liasse fiscale'],
            ['11h30 - 12h30', 'Rédaction automatisée - courriers clients, notes de mission, reporting'],
            ['12h30 - 14h00', 'Cocktail déjeunatoire'],
            ['14h00 - 15h45', 'Analyse financière automatisée - ratios, commentaires de gestion, benchmarks'],
            ['15h45 - 16h00', 'Pause'],
            ['16h00 - 17h00', "Atelier libre - Création de cas d'usage avec accompagnement individuel"],
            ['17h00 - 17h30', 'Restitution commune - synthèse, feuille de route, priorisation des déploiements'],
        ]);
    }

    /**
     * @param array<int, array{0: string, 1: string}> $slots
     *
     * @return array<int, array{time: string, label: string, tag: string}>
     */
    private static function withTags(array $slots): array
    {
        return array_map(static function (array $slot): array {
            [$time, $label] = $slot;
            $lower = mb_strtolower($label);
            $tag = match (true) {
                str_contains($lower, 'cocktail') => 'CONVIVIALITÉ',
                str_contains($lower, 'café') || str_contains($lower, 'déjeuner') || str_contains($lower, 'pause') => 'PAUSE',
                default => 'FORMATION',
            };

            return ['time' => $time, 'label' => $label, 'tag' => $tag];
        }, $slots);
    }
}
