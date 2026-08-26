<?php

namespace App\Tests\Site\SeminaireCAC;

use App\Site\SeminaireCAC\Service\FareCatalog;
use PHPUnit\Framework\TestCase;

final class FareCatalogTest extends TestCase
{
    /** La grille validée avec la cliente (montants HT). */
    public function testGrid(): void
    {
        self::assertSame(
            ['cooperateur' => '850.00', 'non_cooperateur' => '950.00', 'anecs_cjec' => '400.00'],
            array_map(static fn (array $fare): string => $fare['amount'], FareCatalog::all()),
        );
    }

    /** Les tarifs sont annoncés HT sur le site : la TVA s'ajoute à l'encaissement. */
    public function testInclTaxAppliesTwentyPercent(): void
    {
        self::assertSame('20.00', FareCatalog::TAX_RATE);
        self::assertSame('1020.00', FareCatalog::inclTax('850.00'));
        self::assertSame('1140.00', FareCatalog::inclTax('950.00'));
        self::assertSame('480.00', FareCatalog::inclTax('400.00'));
    }

    public function testFindRejectsUnknownCode(): void
    {
        self::assertNull(FareCatalog::find('inconnu'));
        self::assertSame('850.00', FareCatalog::find('cooperateur')['amount']);
    }
}
