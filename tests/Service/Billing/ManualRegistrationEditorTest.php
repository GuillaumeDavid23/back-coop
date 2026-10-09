<?php

namespace App\Tests\Service\Billing;

use App\Entity\Invoice;
use App\Entity\Participant;
use App\Entity\Payment;
use App\Entity\PaymentMethod;
use App\Entity\PaymentStatus;
use App\Entity\Registration;
use App\Entity\RegistrationStatus;
use App\Entity\Site;
use App\Repository\InvoiceRepository;
use App\Service\Billing\ManualRegistrationEditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Correction d'une inscription en paiement manuel : la formule change, et la
 * facture de virement encore à régler doit suivre sous le même numéro - mais
 * jamais une facture déjà acquittée.
 */
final class ManualRegistrationEditorTest extends TestCase
{
    private ?Invoice $existingInvoice = null;

    public function testOnlyAnUnpaidManualRegistrationIsEditable(): void
    {
        $editor = $this->createEditor();

        self::assertNotNull($editor->fareChoices($this->createRegistration()));
        self::assertNull($editor->fareChoices($this->createRegistration(manual: false)));
        self::assertNull($editor->fareChoices($this->createRegistration()->setStatus(RegistrationStatus::CONFIRMED)));
    }

    public function testChangingTheFareUpdatesTheAwaitedPaymentAndTheUnsettledInvoice(): void
    {
        $registration = $this->createRegistration();
        $this->existingInvoice = $this->createInvoice($registration);
        $registration->getPrimaryParticipant()->setCompany('AJC (Groupe NUMANS)');

        $registration->setFareCode('non_cooperateur');
        $invoice = $this->createEditor()->apply($registration);

        self::assertSame('Non coopérateur', $registration->getFareLabel());
        self::assertSame('950.00', $registration->getAmountExclTax());
        self::assertSame('1140.00', $registration->getAmountInclTax());
        self::assertSame('1140.00', $registration->getLatestPayment()->getAmount());

        self::assertSame($this->existingInvoice, $invoice);
        self::assertSame('CAC26-000012', $invoice->getNumber());
        self::assertSame('950.00', $invoice->getAmountExclTax());
        self::assertSame('190.00', $invoice->getTaxAmount());
        self::assertSame('1140.00', $invoice->getAmountInclTax());
        self::assertSame('AJC (Groupe NUMANS)', $invoice->getBillingDataSnapshot()['company']);
        // Le PDF au mauvais montant ne doit plus jamais être servi.
        self::assertNull($invoice->getPdfPath());
    }

    public function testASettledInvoiceIsNeverRewritten(): void
    {
        $registration = $this->createRegistration();
        $this->existingInvoice = $this->createInvoice($registration)->setSettledAt(new \DateTimeImmutable());

        $registration->setFareCode('non_cooperateur');

        self::assertNull($this->createEditor()->apply($registration));
        self::assertSame('1020.00', $this->existingInvoice->getAmountInclTax());
    }

    private function createEditor(): ManualRegistrationEditor
    {
        $invoices = $this->createStub(InvoiceRepository::class);
        $invoices->method('findOneBy')->willReturnCallback(fn (): ?Invoice => $this->existingInvoice);

        return new ManualRegistrationEditor($this->createStub(EntityManagerInterface::class), $invoices, new NullLogger());
    }

    private function createRegistration(bool $manual = true): Registration
    {
        $site = (new Site())->setCode('seminaire_cac')->setName('Séminaire CAC')->setDomain('seminaire-cac.test');
        $registration = (new Registration())
            ->setSite($site)
            ->setFareCode('cooperateur')
            ->setFareLabel('Coopérateur')
            ->setAmountExclTax('850.00')
            ->setTaxRate('20.00')
            ->setAmountInclTax('1020.00');

        $registration->addParticipant((new Participant())
            ->setFirstName('Magali')
            ->setLastName('PAVLOVSKY')
            ->setEmail('magali@example.test'));

        if ($manual) {
            $registration->switchToManualHandling('admin@clcomevents.fr');
            $registration->getPayments()->add((new Payment())
                ->setSite($site)
                ->setRegistration($registration)
                ->setAmount('1020.00')
                ->setMethod(PaymentMethod::TRANSFER)
                ->setStatus(PaymentStatus::PENDING));
        }

        return $registration;
    }

    private function createInvoice(Registration $registration): Invoice
    {
        return (new Invoice())
            ->setRegistration($registration)
            ->setNumber('CAC26-000012')
            ->setAmountExclTax('850.00')
            ->setTaxAmount('170.00')
            ->setAmountInclTax('1020.00')
            ->setPdfPath('CAC26-000012.pdf');
    }
}
