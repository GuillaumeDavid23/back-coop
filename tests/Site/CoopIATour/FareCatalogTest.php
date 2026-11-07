<?php

namespace App\Tests\Site\CoopIATour;

use App\Site\CoopIATour\Service\FareCatalog;
use PHPUnit\Framework\TestCase;

final class FareCatalogTest extends TestCase
{
    /** La grille du message de Marion BROYER du 07/11/2026 (montants HT). */
    public function testGrid(): void
    {
        self::assertSame(
            ['cooperateur' => '490.00', 'non_cooperateur' => '690.00', 'anecs_cjec' => '250.00'],
            array_map(static fn (array $fare): string => $fare['amount'], FareCatalog::all()),
        );
    }

    /** Les tarifs sont annoncés HT sur le site : la TVA s'ajoute à l'encaissement. */
    public function testInclTaxAppliesTwentyPercent(): void
    {
        self::assertSame('20.00', FareCatalog::TAX_RATE);
        self::assertSame('588.00', FareCatalog::inclTax('490.00'));
        self::assertSame('828.00', FareCatalog::inclTax('690.00'));
        self::assertSame('300.00', FareCatalog::inclTax('250.00'));
    }

    public function testFindRejectsUnknownCode(): void
    {
        self::assertNull(FareCatalog::find('inconnu'));
        self::assertSame('490.00', FareCatalog::find('cooperateur')['amount']);
    }
}
