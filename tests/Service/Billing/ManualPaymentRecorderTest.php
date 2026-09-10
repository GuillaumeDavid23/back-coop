<?php

namespace App\Tests\Service\Billing;

use App\Entity\Invoice;
use App\Entity\Payment;
use App\Entity\PaymentMethod;
use App\Entity\PaymentStatus;
use App\Entity\Registration;
use App\Entity\RegistrationStatus;
use App\Entity\Site;
use App\Message\GenerateInvoicePdfMessage;
use App\Message\SendInvoiceEmailMessage;
use App\Message\SendRegistrationConfirmationMessage;
use App\Repository\InvoiceRepository;
use App\Service\Billing\ManualPaymentRecorder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Constat manuel d'un règlement hors Stripe. Les points sensibles :
 * l'inscription doit finir dans le même état qu'après un paiement CB, la
 * facture ne doit jamais être émise deux fois, et une facture émise avant
 * encaissement (virement) doit devenir acquittée - pas être doublée.
 */
final class ManualPaymentRecorderTest extends TestCase
{
    /** @var list<object> */
    private array $dispatched = [];

    /** Factures existantes vues par le dépôt, indexées par inscription. */
    private ?Invoice $existingInvoice = null;

    public function testOnlyAnUnpaidRegistrationCanBeRecorded(): void
    {
        $recorder = $this->createRecorder();

        self::assertTrue($recorder->canRecord($this->createRegistration()));

        foreach ([RegistrationStatus::CONFIRMED, RegistrationStatus::CANCELLED] as $status) {
            self::assertFalse($recorder->canRecord($this->createRegistration()->setStatus($status)));
        }
    }

    public function testSwitchingToManualLeavesTheRegistrationUnpaidAndIssuesAnUnsettledInvoice(): void
    {
        $registration = $this->createRegistration();

        $payment = $this->createRecorder()->switchToManual($registration, PaymentMethod::TRANSFER, true, true, 'admin@clcomevents.fr');

        // Rien n'est encaissé : c'est la facture qui va déclencher le virement.
        self::assertSame(RegistrationStatus::PENDING, $registration->getStatus());
        self::assertTrue($registration->isManuallyHandled());
        self::assertSame(PaymentStatus::PENDING, $payment->getStatus());
        self::assertSame(PaymentMethod::TRANSFER, $payment->getMethod());

        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(GenerateInvoicePdfMessage::class, $this->dispatched[0]);
        self::assertTrue($this->dispatched[0]->notifyParticipant);
    }

    public function testSwitchingToManualWithoutIssuingAnInvoiceSendsNothing(): void
    {
        $registration = $this->createRegistration();

        $this->createRecorder()->switchToManual($registration, PaymentMethod::CHECK, false, true, 'admin@clcomevents.fr');

        self::assertTrue($registration->isManuallyHandled());
        self::assertSame([], $this->dispatched);
    }

    public function testRecordingAPaymentConfirmsTheRegistrationAndAsksForTheInvoice(): void
    {
        $registration = $this->createRegistration();

        $payment = $this->createRecorder()->recordPaid(
            $registration,
            PaymentMethod::TRANSFER,
            new \DateTimeImmutable('2026-09-08'),
            'VIR CABINET DUPONT',
            'admin@clcomevents.fr',
        );

        self::assertSame(RegistrationStatus::CONFIRMED, $registration->getStatus());
        self::assertTrue($registration->isManuallyHandled());
        self::assertSame(PaymentStatus::SUCCEEDED, $payment->getStatus());
        self::assertSame(PaymentMethod::TRANSFER, $payment->getMethod());
        // Le montant vient de l'inscription : une facture ne doit jamais
        // s'écarter du tarif choisi, quoi qu'ait saisi l'utilisateur.
        self::assertSame('650.00', $payment->getAmount());
        self::assertSame('2026-09-08', $payment->getPaidAt()?->format('Y-m-d'));
        self::assertSame('VIR CABINET DUPONT', $payment->getManualReference());
        self::assertSame('admin@clcomevents.fr', $payment->getManuallyValidatedBy());

        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(GenerateInvoicePdfMessage::class, $this->dispatched[0]);
    }

    /** Envoi décoché : la facture est émise quand même, seul l'email est retenu. */
    public function testRecordingAPaymentWithoutNotifyingStillIssuesTheInvoice(): void
    {
        $this->createRecorder()->recordPaid(
            $this->createRegistration(),
            PaymentMethod::CHECK,
            new \DateTimeImmutable(),
            null,
            'admin@clcomevents.fr',
            notifyParticipant: false,
        );

        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(GenerateInvoicePdfMessage::class, $this->dispatched[0]);
        self::assertFalse($this->dispatched[0]->notifyParticipant);
    }

    /**
     * Virement : la facture a été émise à la bascule en manuel. Le règlement
     * reçu doit l'acquitter, sans en émettre une seconde ni consommer un
     * nouveau numéro.
     */
    public function testRecordingAPaymentSettlesTheInvoiceIssuedBeforehand(): void
    {
        $registration = $this->createRegistration();
        $recorder = $this->createRecorder();
        $awaited = $recorder->switchToManual($registration, PaymentMethod::TRANSFER, true, false, 'admin@clcomevents.fr');

        $this->existingInvoice = (new Invoice())
            ->setRegistration($registration)
            ->setNumber('CAC26-000012')
            ->setPdfPath('CAC26-000012.pdf');
        (new \ReflectionProperty($this->existingInvoice, 'id'))->setValue($this->existingInvoice, 12);
        $this->dispatched = [];

        $payment = $recorder->recordPaid(
            $registration,
            PaymentMethod::TRANSFER,
            new \DateTimeImmutable('2026-09-09'),
            'VIR CABINET DUPONT',
            'admin@clcomevents.fr',
        );

        self::assertSame($awaited, $payment, 'le règlement attendu est complété, pas doublé');
        self::assertSame('2026-09-09', $this->existingInvoice->getSettledAt()?->format('Y-m-d'));
        self::assertTrue($this->existingInvoice->isSettled());
        // Le PDF "à régler" déjà rendu est oublié : il sera refait acquitté au
        // premier accès, plutôt que servi tel quel avec le RIB dessus.
        self::assertNull($this->existingInvoice->getPdfPath());

        // Aucune nouvelle facture demandée : seul l'envoi de celle-ci part.
        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(SendInvoiceEmailMessage::class, $this->dispatched[0]);
    }

    /** Site sans facturation : la confirmation part seule, aucun numéro de facture n'est consommé. */
    public function testRecordingAPaymentOnASiteWithoutInvoicingOnlySendsTheConfirmation(): void
    {
        $registration = $this->createRegistration();
        $registration->getSite()->setInvoicingEnabled(false);

        $this->createRecorder()->recordPaid(
            $registration,
            PaymentMethod::CHECK,
            new \DateTimeImmutable(),
            null,
            'admin@clcomevents.fr',
        );

        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(SendRegistrationConfirmationMessage::class, $this->dispatched[0]);
    }

    public function testAnAlreadyConfirmedRegistrationIsNeverRecordedTwice(): void
    {
        $this->expectException(\LogicException::class);

        $this->createRecorder()->recordPaid(
            $this->createRegistration()->setStatus(RegistrationStatus::CONFIRMED),
            PaymentMethod::TRANSFER,
            new \DateTimeImmutable(),
            null,
            'admin@clcomevents.fr',
        );
    }

    public function testACardPaymentIsNeverRecordedByHand(): void
    {
        $this->expectException(\LogicException::class);

        $this->createRecorder()->recordPaid(
            $this->createRegistration(),
            PaymentMethod::CARD,
            new \DateTimeImmutable(),
            null,
            'admin@clcomevents.fr',
        );
    }

    /**
     * La tentative Stripe laissée en attente n'aboutira jamais puisque l'argent
     * arrive autrement : elle est classée abandonnée, et le règlement réel est
     * enregistré sur un paiement distinct - la session Stripe n'est jamais
     * réécrite.
     */
    public function testThePendingStripeAttemptIsClosedAsAbandoned(): void
    {
        $registration = $this->createRegistration();
        $abandoned = (new Payment())
            ->setSite($registration->getSite())
            ->setRegistration($registration)
            ->setStripeCheckoutSessionId('cs_test_abandonnee')
            ->setAmount('650.00');
        $registration->getPayments()->add($abandoned);

        $payment = $this->createRecorder()->recordPaid(
            $registration,
            PaymentMethod::CHECK,
            new \DateTimeImmutable(),
            'Chèque n°1234567',
            'admin@clcomevents.fr',
        );

        self::assertSame(PaymentStatus::FAILED, $abandoned->getStatus());
        self::assertNotSame($abandoned, $payment);
        self::assertNull($payment->getStripeCheckoutSessionId());
        self::assertSame('cs_test_abandonnee', $abandoned->getStripeCheckoutSessionId());
    }

    private function createRecorder(): ManualPaymentRecorder
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        $invoices = $this->createStub(InvoiceRepository::class);
        $invoices->method('findOneBy')->willReturnCallback(fn (): ?Invoice => $this->existingInvoice);

        // Les identifiants sont attribués par la base : sans EntityManager réel,
        // on les simule à la persistance, sinon les messages - qui ne portent
        // qu'un id - ne pourraient pas être construits.
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity): void {
            $property = new \ReflectionProperty($entity, 'id');
            if (!$property->isInitialized($entity) || null === $property->getValue($entity)) {
                $property->setValue($entity, random_int(1, 9999));
            }
        });

        return new ManualPaymentRecorder(
            $em,
            $invoices,
            $bus,
            new NullLogger(),
        );
    }

    private function createRegistration(): Registration
    {
        $site = (new Site())->setCode('seminaire_cac')->setName('Séminaire CAC')->setDomain('seminaire-cac.test');
        $registration = (new Registration())
            ->setSite($site)
            ->setFareCode('cooperateur')
            ->setFareLabel('Coopérateur')
            ->setAmountExclTax('650.00')
            ->setAmountInclTax('650.00');

        (new \ReflectionProperty($registration, 'id'))->setValue($registration, 42);

        return $registration;
    }
}
