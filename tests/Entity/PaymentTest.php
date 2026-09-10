<?php

namespace App\Tests\Entity;

use App\Entity\Payment;
use App\Entity\PaymentMethod;
use PHPUnit\Framework\TestCase;

/**
 * Le "mode de règlement" imprimé sur la facture : c'est la seule mention qui
 * dit au participant - et au comptable - par quel canal l'argent est arrivé.
 */
final class PaymentTest extends TestCase
{
    public function testSettlementLabelOfACardPaymentCitesTheStripeSession(): void
    {
        $payment = (new Payment())->setStripeCheckoutSessionId('cs_test_123');

        self::assertSame('CB - cs_test_123', $payment->getSettlementLabel());
        self::assertFalse($payment->isManual());
    }

    /** Reprise à l'identique de l'ancien gabarit : les factures déjà émises ne changent pas de libellé. */
    public function testSettlementLabelOfACardPaymentWithoutSessionStaysGeneric(): void
    {
        self::assertSame('CB - Manuel', (new Payment())->getSettlementLabel());
    }

    public function testSettlementLabelOfAManualPaymentCitesItsReference(): void
    {
        $payment = (new Payment())
            ->setMethod(PaymentMethod::CHECK)
            ->setManualReference('Chèque n°1234567');

        self::assertSame('Chèque - Chèque n°1234567', $payment->getSettlementLabel());
        self::assertTrue($payment->isManual());
    }

    public function testSettlementLabelOfAManualPaymentWithoutReferenceIsJustTheMethod(): void
    {
        self::assertSame('Virement', (new Payment())->setMethod(PaymentMethod::TRANSFER)->getSettlementLabel());
    }
}
