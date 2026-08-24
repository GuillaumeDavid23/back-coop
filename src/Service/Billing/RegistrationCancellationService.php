<?php

namespace App\Service\Billing;

use App\Entity\CreditNote;
use App\Entity\Registration;
use App\Entity\RegistrationStatus;
use App\Repository\InvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Désinscription = annulation logique (jamais de suppression physique,
 * l'inscription doit rester consultable) + avoir généré automatiquement si
 * une facture existait. Ne déclenche JAMAIS de remboursement Stripe : celui-ci
 * reste un acte manuel de l'utilisateur, volontairement, pour ne jamais
 * rembourser par erreur depuis le BO.
 */
final class RegistrationCancellationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NumberingService $numbering,
        private readonly InvoiceRepository $invoices,
        private readonly CreditNotePdfGenerator $pdfGenerator,
        #[Autowire(service: 'monolog.logger.payment')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Seule une inscription confirmée (donc payée) peut être désinscrite : une
     * inscription "en attente" n'a jamais été encaissée, il n'y a rien à annuler
     * ni à créditer — elle expire d'elle-même.
     */
    public function canCancel(Registration $registration): bool
    {
        return $registration->getStatus() === RegistrationStatus::CONFIRMED;
    }

    public function cancel(Registration $registration, ?string $reason = null): ?CreditNote
    {
        if (!$this->canCancel($registration)) {
            throw new \LogicException(sprintf(
                'Seule une inscription confirmée peut être désinscrite (inscription #%d au statut "%s").',
                $registration->getId(),
                $registration->getStatus()->value,
            ));
        }

        $invoice = $this->invoices->findOneBy(['registration' => $registration]);
        $creditNote = null;

        if ($invoice !== null) {
            $site = $registration->getSite();
            $numbering = $this->numbering->nextCreditNoteNumber($site);

            $creditNote = new CreditNote();
            $creditNote->setSite($site)
                ->setInvoice($invoice)
                ->setNumber($numbering['number'])
                ->setSequenceNumber($numbering['sequenceNumber'])
                ->setAmount($invoice->getAmountInclTax())
                ->setReason($reason ?? 'Désinscription')
                ->setIssuedAt(new \DateTimeImmutable());

            $this->em->persist($creditNote);
            $this->em->flush();

            $pdfPath = $this->pdfGenerator->generate($creditNote);
            $creditNote->setPdfPath($pdfPath);

            $this->logger->info('registration.cancelled.credit_note_created', [
                'registration_id' => $registration->getId(),
                'invoice_id' => $invoice->getId(),
                'credit_note_number' => $creditNote->getNumber(),
                'amount' => $creditNote->getAmount(),
            ]);
        }

        $registration->setStatus(RegistrationStatus::CANCELLED);
        $this->em->flush();

        $this->logger->info('registration.cancelled', [
            'registration_id' => $registration->getId(),
            'had_invoice' => $invoice !== null,
        ]);

        return $creditNote;
    }
}
