<?php

namespace App\Service\Billing;

use App\Entity\CreditNote;
use App\Entity\Invoice;
use App\Entity\Registration;
use App\Entity\RegistrationStatus;
use App\Repository\CreditNoteRepository;
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
 *
 * L'avoir peut aussi être émis seul (« Générer un avoir »), sans désinscrire :
 * remboursement commercial, geste, erreur de facturation... comme dans le BO
 * CLCOM Academy. Une inscription n'a jamais plus d'un avoir : une
 * désinscription ultérieure réutilise celui déjà émis.
 */
final class RegistrationCancellationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NumberingService $numbering,
        private readonly InvoiceRepository $invoices,
        private readonly CreditNoteRepository $creditNotes,
        private readonly CreditNotePdfGenerator $pdfGenerator,
        #[Autowire(service: 'monolog.logger.payment')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Seule une inscription confirmée (donc payée) peut être désinscrite : une
     * inscription "en attente" n'a jamais été encaissée, il n'y a rien à annuler
     * ni à créditer - elle expire d'elle-même.
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
        $creditNote = $this->creditNotes->findOneByRegistration($registration);

        if ($invoice !== null && $creditNote === null) {
            $creditNote = $this->createCreditNote($registration, $invoice, $reason ?? 'Désinscription');
        }

        $registration->setStatus(RegistrationStatus::CANCELLED);
        $this->em->flush();

        $this->logger->info('registration.cancelled', [
            'registration_id' => $registration->getId(),
            'had_invoice' => $invoice !== null,
        ]);

        return $creditNote;
    }

    /**
     * Avoir seul : il faut une facture à créditer et pas déjà d'avoir. Une
     * inscription désinscrite a déjà eu le sien si elle était facturée.
     */
    public function canIssueCreditNote(Registration $registration): bool
    {
        return $registration->getStatus() === RegistrationStatus::CONFIRMED
            && $this->invoices->findOneBy(['registration' => $registration]) !== null
            && $this->creditNotes->findOneByRegistration($registration) === null;
    }

    /**
     * Émet l'avoir de la facture sans toucher au statut de l'inscription ni à
     * Stripe : le participant reste inscrit, le remboursement éventuel reste
     * manuel.
     */
    public function issueCreditNote(Registration $registration, ?string $reason = null): CreditNote
    {
        if (!$this->canIssueCreditNote($registration)) {
            throw new \LogicException(sprintf(
                "Impossible d'émettre un avoir pour l'inscription #%d (non confirmée, sans facture, ou avoir déjà émis).",
                $registration->getId(),
            ));
        }

        $invoice = $this->invoices->findOneBy(['registration' => $registration]);

        return $this->createCreditNote($registration, $invoice, $reason ?? 'Avoir sans désinscription');
    }

    private function createCreditNote(Registration $registration, Invoice $invoice, string $reason): CreditNote
    {
        $site = $registration->getSite();
        $numbering = $this->numbering->nextCreditNoteNumber($site);

        $creditNote = new CreditNote();
        $creditNote->setSite($site)
            ->setInvoice($invoice)
            ->setNumber($numbering['number'])
            ->setSequenceNumber($numbering['sequenceNumber'])
            ->setAmount($invoice->getAmountInclTax())
            ->setReason($reason)
            ->setIssuedAt(new \DateTimeImmutable());

        $this->em->persist($creditNote);
        $this->em->flush();

        $pdfPath = $this->pdfGenerator->generate($creditNote);
        $creditNote->setPdfPath($pdfPath);
        $this->em->flush();

        $this->logger->info('registration.credit_note_created', [
            'registration_id' => $registration->getId(),
            'invoice_id' => $invoice->getId(),
            'credit_note_number' => $creditNote->getNumber(),
            'amount' => $creditNote->getAmount(),
            'reason' => $reason,
        ]);

        return $creditNote;
    }
}
