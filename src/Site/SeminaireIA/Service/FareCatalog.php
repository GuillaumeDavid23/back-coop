<?php

namespace App\Site\SeminaireIA\Service;

/**
 * Grille tarifaire du Séminaire IA — Deauville, 22-23 octobre 2026. Propre à
 * cet événement (voir Registration::fareCode/fareLabel qui stockent un
 * instantané de ce qui est choisi ici au moment de l'inscription).
 *
 * Contrairement au Séminaire CAC, le prix dépend de deux dimensions : le
 * forfait ET le statut du participant (grille type formulaire ECF). Montants
 * en euros HT — la TVA (20 %) est appliquée à l'encaissement.
 */
final class FareCatalog
{
    public const string TAX_RATE = '20.00';

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            'cooperateur' => 'Coopérateur',
            'non_cooperateur' => 'Non coopérateur',
            'cjec_anecs' => 'Adhérent CJEC/ANECS',
        ];
    }

    /**
     * @return array<string, array{
     *     label: string,
     *     shortLabel: string,
     *     participants: int,
     *     accommodation: bool,
     *     prices: array<string, string>,
     *     includes: list<string>,
     * }>
     */
    public static function all(): array
    {
        $accommodationIncludes = [
            'La nuit du jeudi 22 octobre',
            'La soirée du jeudi 22 octobre',
            'Les pauses et déjeuners',
        ];

        return [
            'heb_1ec' => [
                'label' => 'Forfait hébergement — 1 Expert-Comptable',
                'shortLabel' => '1 Expert-Comptable',
                'participants' => 1,
                'accommodation' => true,
                'prices' => ['cooperateur' => '850.00', 'non_cooperateur' => '950.00', 'cjec_anecs' => '600.00'],
                'includes' => $accommodationIncludes,
            ],
            'heb_1ec_acc' => [
                'label' => 'Forfait hébergement — 1 EC + 1 accompagnant',
                'shortLabel' => '1 EC + 1 accompagnant',
                'participants' => 2,
                'accommodation' => true,
                'prices' => ['cooperateur' => '1060.00', 'non_cooperateur' => '1160.00', 'cjec_anecs' => '810.00'],
                'includes' => $accommodationIncludes,
            ],
            'heb_2ec' => [
                'label' => 'Forfait hébergement — 2 Experts-Comptables',
                'shortLabel' => '2 Experts-Comptables',
                'participants' => 2,
                'accommodation' => true,
                'prices' => ['cooperateur' => '1350.00', 'non_cooperateur' => '1450.00', 'cjec_anecs' => '1100.00'],
                'includes' => $accommodationIncludes,
            ],
            'sans_heb' => [
                'label' => 'Forfait sans hébergement — 1 Expert-Comptable',
                'shortLabel' => '1 Expert-Comptable',
                'participants' => 1,
                'accommodation' => false,
                'prices' => ['cooperateur' => '500.00', 'non_cooperateur' => '600.00', 'cjec_anecs' => '250.00'],
                'includes' => ['Les pauses et déjeuners'],
            ],
        ];
    }

    public static function find(string $code): ?array
    {
        return self::all()[$code] ?? null;
    }

    /**
     * Soirée du jeudi en option — uniquement pour le forfait sans hébergement,
     * les forfaits hébergement l'incluent déjà.
     *
     * @return array<string, string>
     */
    public static function eveningPrices(): array
    {
        return ['cooperateur' => '100.00', 'non_cooperateur' => '150.00', 'cjec_anecs' => '80.00'];
    }

    public static function allowsEveningOption(string $fareCode): bool
    {
        return 'sans_heb' === $fareCode;
    }

    public static function isTwoPerson(string $fareCode): bool
    {
        return 2 === (self::find($fareCode)['participants'] ?? 1);
    }

    /** Montant HT du forfait pour un statut, option soirée comprise le cas échéant. */
    public static function totalExclTax(string $fareCode, string $status, bool $eveningOption): ?string
    {
        $price = self::find($fareCode)['prices'][$status] ?? null;
        if (null === $price) {
            return null;
        }

        if ($eveningOption && self::allowsEveningOption($fareCode)) {
            $price = bcadd($price, self::eveningPrices()[$status], 2);
        }

        return $price;
    }

    /** TTC = HT × 1,20 (TAX_RATE), arrondi au centime. */
    public static function inclTax(string $amountExclTax): string
    {
        $withTax = bcmul($amountExclTax, bcadd('1', bcdiv(self::TAX_RATE, '100', 4), 4), 4);

        return number_format((float) $withTax, 2, '.', '');
    }

    /** Libellé instantané complet stocké dans Registration::fareLabel. */
    public static function fareLabel(string $fareCode, string $status, bool $eveningOption): string
    {
        $label = sprintf(
            '%s — %s',
            self::find($fareCode)['label'] ?? $fareCode,
            self::statuses()[$status] ?? $status,
        );

        if ($eveningOption && self::allowsEveningOption($fareCode)) {
            $label .= ' + soirée';
        }

        return $label;
    }
}
