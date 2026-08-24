<?php

namespace App\MessageHandler;

use App\Entity\Invoice;
use App\Message\GenerateInvoicePdfMessage;
use App\Message\SendInvoiceEmailMessage;
use App\Repository\InvoiceRepository;
use App\Repository\PaymentRepository;
use App\Service\Billing\InvoicePdfGenerator;
use App\Service\Billing\NumberingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class GenerateInvoicePdfMessageHandler
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly InvoiceRepository $invoices,
        private readonly NumberingService $numbering,
        private readonly InvoicePdfGenerator $pdfGenerator,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        #[Autowire(service: 'monolog.logger.payment')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateInvoicePdfMessage $message): void
    {
        $this->logger->debug('invoice.generate.handler_start', ['payment_id' => $message->paymentId]);

        $payment = $this->payments->find($message->paymentId);
        if ($payment === null) {
            $this->logger->error('invoice.generate.payment_not_found', ['payment_id' => $message->paymentId]);

            return;
        }

        $registration = $payment->getRegistration();

        // Une inscription ne porte qu'une seule facture : le webhook Stripe et le
        // rafraîchissement manuel depuis le BO peuvent déclencher ce handler en
        // parallèle, on ne doit jamais consommer deux numéros de facture.
        if (null !== $existing = $this->invoices->findOneBy(['registration' => $registration])) {
            $this->logger->info('invoice.generate.already_exists', [
                'payment_id' => $payment->getId(),
                'registration_id' => $registration->getId(),
                'invoice_number' => $existing->getNumber(),
            ]);

            return;
        }

        $site = $payment->getSite();
        $participant = $registration->getParticipants()->first() ?: null;

        // Tout ce qui peut échouer est calculé AVANT de tirer un numéro de
        // facture : nextInvoiceNumber() incrémente le compteur dans sa propre
        // transaction, un échec après coup laisserait un trou définitif dans la
        // numérotation (interdit en compta française).
        $taxAmount = bcsub($registration->getAmountInclTax(), $registration->getAmountExclTax(), 2);
        $billingDataSnapshot = [
            'name' => $participant?->getFullName(),
            'company' => $participant?->getCompany(),
            'address' => $participant?->getAddress(),
            'postalCode' => $participant?->getPostalCode(),
            'city' => $participant?->getCity(),
            'email' => $participant?->getEmail(),
        ];

        $numbering = $this->numbering->nextInvoiceNumber($site);

        $invoice = new Invoice();
        $invoice->setSite($site)
            ->setRegistration($registration)
            ->setPayment($payment)
            ->setNumber($numbering['number'])
            ->setSequenceNumber($numbering['sequenceNumber'])
            ->setAmountExclTax($registration->getAmountExclTax())
            ->setTaxAmount($taxAmount)
            ->setAmountInclTax($registration->getAmountInclTax())
            ->setIssuedAt(new \DateTimeImmutable())
            ->setBillingDataSnapshot($billingDataSnapshot);

        $this->em->persist($invoice);
        $this->em->flush();

        $this->logger->info('invoice.generate.invoice_created', [
            'payment_id' => $payment->getId(),
            'invoice_id' => $invoice->getId(),
            'invoice_number' => $invoice->getNumber(),
        ]);

        try {
            $pdfPath = $this->pdfGenerator->generate($invoice);
            $invoice->setPdfPath($pdfPath);
            $this->em->flush();
        } catch (\Throwable $e) {
            // La facture existe déjà en base avec son numéro définitif : on ne
            // relance jamais la numérotation, seul le rendu PDF peut être
            // retenté plus tard (ex: depuis le BO) sans risque de doublon.
            $this->logger->error('invoice.generate.pdf_failed', [
                'invoice_id' => $invoice->getId(),
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        $this->bus->dispatch(new SendInvoiceEmailMessage($invoice->getId()));
    }
}
