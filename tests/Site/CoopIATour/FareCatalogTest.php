<?php

namespace App\Tests\Site\CoopIATour;

use App\Site\CoopIATour\Service\FareCatalog;
use PHPUnit\Framework\TestCase;

final class FareCatalogTest extends TestCase
{
    /** La grille du mail de Marion BROYER du 30/10/2026 (montants HT), sans le tarif ANECS / CJEC retiré le 02/11/2026. */
    public function testGrid(): void
    {
        self::assertSame(
            ['cooperateur' => '790.00', 'non_cooperateur' => '890.00'],
            array_map(static fn (array $fare): string => $fare['amount'], FareCatalog::all()),
        );
    }

    /** Les tarifs sont annoncés HT sur le site : la TVA s'ajoute à l'encaissement. */
    public function testInclTaxAppliesTwentyPercent(): void
    {
        self::assertSame('20.00', FareCatalog::TAX_RATE);
        self::assertSame('948.00', FareCatalog::inclTax('790.00'));
        self::assertSame('1068.00', FareCatalog::inclTax('890.00'));
    }

    public function testFindRejectsUnknownCode(): void
    {
        self::assertNull(FareCatalog::find('inconnu'));
        self::assertSame('790.00', FareCatalog::find('cooperateur')['amount']);
    }
}
