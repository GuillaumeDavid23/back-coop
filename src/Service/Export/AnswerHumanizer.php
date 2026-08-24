<?php

namespace App\Service\Export;

/**
 * Rend lisibles les clés/valeurs brutes stockées en JSON (Registration/
 * Participant::answers) et quelques codes internes — utilisé à la fois par
 * les exports Excel et par l'affichage du BO, pour ne jamais montrer une
 * clé façon "cocktailAttendance" ou un code façon "tns" à un humain.
 */
final class AnswerHumanizer
{
    /**
     * Libellés lisibles des questions posées lors de l'inscription. La
     * transformation automatique d'une clé camelCase donne des résultats
     * incompréhensibles ("cocktailAttendance" => "Cocktail attendance") :
     * les questions connues sont donc traduites explicitement ici.
     *
     * Une clé absente de cette table retombe sur la conversion automatique :
     * un nouvel événement qui pose ses propres questions reste affichable
     * sans modification de code, il suffit d'ajouter une ligne pour soigner
     * le libellé.
     */
    private const array KEY_LABELS = [
        'cocktailAttendance' => 'Présence au cocktail apéritif',
        'motivation' => "Motif de l'inscription",
        'specialNeeds' => 'Besoins spécifiques',
        'consentAccepted' => 'Conditions générales acceptées',
        // Séminaire IA (seminaire_ia)
        'statut' => 'Statut',
        'subscriptionNumber' => "Numéro de facture d'adhésion",
        'category' => 'Catégorie',
        'eveningOption' => 'Soirée en option',
        'roomType' => 'Type de chambre',
    ];

    public static function key(string $key): string
    {
        if (isset(self::KEY_LABELS[$key])) {
            return self::KEY_LABELS[$key];
        }

        $withSpaces = preg_replace('/(?<!^)[A-Z]/', ' $0', $key);

        return ucfirst(mb_strtolower($withSpaces));
    }

    public static function value(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Oui' : 'Non';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return match ($value) {
            'oui' => 'Oui',
            'non' => 'Non',
            // Codes du Séminaire IA (statut, catégorie, type de chambre)
            'cooperateur' => 'Coopérateur',
            'non_cooperateur' => 'Non coopérateur',
            'cjec_anecs' => 'Adhérent CJEC/ANECS',
            'salarie' => 'Salarié',
            'tns' => 'Travailleur non salarié',
            'grand_lit' => '1 grand lit',
            'lits_separes' => '2 lits séparés',
            default => (string) ($value ?? ''),
        };
    }

    public static function civility(?string $civility): string
    {
        return match ($civility) {
            'mme' => 'Madame',
            'm' => 'Monsieur',
            default => $civility ?? '—',
        };
    }

    public static function participantStatus(?string $status): string
    {
        return match ($status) {
            'salarie' => 'Salarié',
            'tns' => 'Travailleur non salarié',
            default => $status ?? '—',
        };
    }

    public static function registrationStatus(string $status): string
    {
        return match ($status) {
            'pending' => 'En attente',
            'confirmed' => 'Confirmée',
            'cancelled' => 'Désinscrite',
            default => $status,
        };
    }

    public static function paymentStatus(string $status): string
    {
        return match ($status) {
            'pending' => 'En attente',
            'succeeded' => 'Réussi',
            'failed' => 'Échoué',
            'refunded' => 'Remboursé',
            default => $status,
        };
    }
}
