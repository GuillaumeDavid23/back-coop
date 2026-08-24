<?php

namespace App\Tests\Site\SeminaireIA;

use App\Site\SeminaireIA\Service\FareCatalog;
use PHPUnit\Framework\TestCase;

final class FareCatalogTest extends TestCase
{
    /** La grille complète validée avec la cliente (montants HT). */
    public function testGrid(): void
    {
        $expected = [
            'heb_1ec' => ['cooperateur' => '850.00', 'non_cooperateur' => '950.00', 'cjec_anecs' => '600.00'],
            'heb_1ec_acc' => ['cooperateur' => '1060.00', 'non_cooperateur' => '1160.00', 'cjec_anecs' => '810.00'],
            'heb_2ec' => ['cooperateur' => '1350.00', 'non_cooperateur' => '1450.00', 'cjec_anecs' => '1100.00'],
            'sans_heb' => ['cooperateur' => '500.00', 'non_cooperateur' => '600.00', 'cjec_anecs' => '250.00'],
        ];

        $fares = FareCatalog::all();
        self::assertSame(array_keys($expected), array_keys($fares));

        foreach ($expected as $code => $prices) {
            self::assertSame($prices, $fares[$code]['prices'], $code);
        }

        self::assertSame(
            ['cooperateur' => '100.00', 'non_cooperateur' => '150.00', 'cjec_anecs' => '80.00'],
            FareCatalog::eveningPrices(),
        );
    }

    public function testEveningOptionOnlyForFareWithoutAccommodation(): void
    {
        self::assertTrue(FareCatalog::allowsEveningOption('sans_heb'));
        self::assertFalse(FareCatalog::allowsEveningOption('heb_1ec'));

        // L'option est ignorée pour un forfait qui inclut déjà la soirée.
        self::assertSame('850.00', FareCatalog::totalExclTax('heb_1ec', 'cooperateur', true));
        self::assertSame('600.00', FareCatalog::totalExclTax('sans_heb', 'cooperateur', true));
        self::assertSame('330.00', FareCatalog::totalExclTax('sans_heb', 'cjec_anecs', true));
    }

    public function testTotalRejectsUnknownFareOrStatus(): void
    {
        self::assertNull(FareCatalog::totalExclTax('inconnu', 'cooperateur', false));
        self::assertNull(FareCatalog::totalExclTax('heb_1ec', 'inconnu', false));
    }

    public function testInclTaxAppliesTwentyPercent(): void
    {
        self::assertSame('20.00', FareCatalog::TAX_RATE);
        self::assertSame('1020.00', FareCatalog::inclTax('850.00'));
        self::assertSame('300.00', FareCatalog::inclTax('250.00'));
        // Arrondi au centime sur un montant non rond.
        self::assertSame('120.01', FareCatalog::inclTax('100.01'));
    }

    public function testTwoPersonFares(): void
    {
        self::assertFalse(FareCatalog::isTwoPerson('heb_1ec'));
        self::assertTrue(FareCatalog::isTwoPerson('heb_1ec_acc'));
        self::assertTrue(FareCatalog::isTwoPerson('heb_2ec'));
        self::assertFalse(FareCatalog::isTwoPerson('sans_heb'));
    }

    public function testFareLabelSnapshot(): void
    {
        self::assertSame(
            'Forfait hébergement — 1 Expert-Comptable — Coopérateur',
            FareCatalog::fareLabel('heb_1ec', 'cooperateur', false),
        );
        self::assertSame(
            'Forfait sans hébergement — 1 Expert-Comptable — Adhérent CJEC/ANECS + soirée',
            FareCatalog::fareLabel('sans_heb', 'cjec_anecs', true),
        );
    }
}
