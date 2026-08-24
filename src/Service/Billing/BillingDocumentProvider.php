<?php

namespace App\Service\Billing;

use App\Entity\CreditNote;
use App\Entity\Invoice;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Donne le chemin absolu du PDF d'une facture / d'un avoir, en le générant à
 * la demande s'il manque (document émis avant la mise en place de la
 * génération PDF, ou fichier disparu du disque). Évite d'avoir des boutons
 * "Télécharger" qui disparaissent ou renvoient une 404 selon l'ancienneté du
 * document — voir RegistrationCrudController / InvoiceCrudController /
 * CreditNoteCrudController.
 */
final class BillingDocumentProvider
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoicePdfGenerator $invoicePdfGenerator,
        private readonly CreditNotePdfGenerator $creditNotePdfGenerator,
    ) {
    }

    public function invoicePath(Invoice $invoice): string
    {
        $path = $invoice->getPdfPath();

        if ($path === null || !is_file($this->invoicePdfGenerator->absolutePath($path))) {
            $path = $this->invoicePdfGenerator->generate($invoice);
            $invoice->setPdfPath($path);
            $this->em->flush();
        }

        return $this->invoicePdfGenerator->absolutePath($path);
    }

    public function creditNotePath(CreditNote $creditNote): string
    {
        $path = $creditNote->getPdfPath();

        if ($path === null || !is_file($this->creditNotePdfGenerator->absolutePath($path))) {
            $path = $this->creditNotePdfGenerator->generate($creditNote);
            $creditNote->setPdfPath($path);
            $this->em->flush();
        }

        return $this->creditNotePdfGenerator->absolutePath($path);
    }
}
