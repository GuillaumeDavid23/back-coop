<?php

namespace App\Service\Billing;

use App\Entity\Invoice;
use App\Entity\PaymentStatus;
use App\Entity\Registration;
use App\Entity\RegistrationStatus;
use App\Repository\InvoiceRepository;
use App\Site\SeminaireCAC\Service\FareCatalog as CacFareCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Édition libre d'une inscription en paiement manuel, formule comprise.
 *
 * Tant que le règlement n'est pas arrivé, rien n'est encaissé : l'utilisateur
 * du BO peut corriger le participant ou sa formule (ex : tarif coopérateur
 * choisi à tort), et la facture déjà émise pour le virement - non acquittée -
 * est mise à jour sous le même numéro plutôt que doublée. Une fois le
 * règlement constaté (voir ManualPaymentRecorder), la facture est acquittée et
 * l'inscription redevient figée.
 */
final class ManualRegistrationEditor
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceRepository $invoices,
        #[Autowire(service: 'monolog.logger.payment')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function canEdit(Registration $registration): bool
    {
        return RegistrationStatus::PENDING === $registration->getStatus() && $registration->isManuallyHandled();
    }

    /**
     * Formules proposables au changement, indexées par code. Null quand le
     * site n'a pas de grille simple (le Séminaire IA dépend aussi du statut et
     * de la soirée, saisis dans les réponses) : la formule n'y est pas éditable.
     *
     * @return array<string, array{label: string, amountExclTax: string, taxRate: string, amountInclTax: string}>|null
     */
    public function fareChoices(Registration $registration): ?array
    {
        if (!$this->canEdit($registration)) {
            return null;
        }

        return match ($registration->getSite()->getCode()) {
            'seminaire_cac' => array_map(static fn (array $fare): array => [
                'label' => $fare['label'],
                'amountExclTax' => $fare['amount'],
                'taxRate' => CacFareCatalog::TAX_RATE,
                'amountInclTax' => CacFareCatalog::inclTax($fare['amount']),
            ], CacFareCatalog::all()),
            default => null,
        };
    }

    /**
     * À appeler après l'enregistrement du formulaire : recale le libellé et les
     * montants sur la formule choisie, puis le paiement attendu et la facture
     * non acquittée sur l'inscription.
     *
     * @return Invoice|null la facture mise à jour, s'il y en avait une à régler
     */
    public function apply(Registration $registration): ?Invoice
    {
        if (!$this->canEdit($registration)) {
            return null;
        }

        $fare = $this->fareChoices($registration)[$registration->getFareCode()] ?? null;
        if (null !== $fare) {
            $registration->setFareLabel($fare['label'])
                ->setAmountExclTax($fare['amountExclTax'])
                ->setTaxRate($fare['taxRate'])
                ->setAmountInclTax($fare['amountInclTax']);
        }

        $payment = $registration->getLatestPayment();
        if (null !== $payment && $payment->isManual() && PaymentStatus::PENDING === $payment->getStatus()) {
            $payment->setAmount($registration->getAmountInclTax());
        }

        $invoice = $this->invoices->findOneBy(['registration' => $registration]);
        if (null !== $invoice && $invoice->isSettled()) {
            $invoice = null;
        }

        if (null !== $invoice) {
            // Même facture, même numéro : seul le contenu suit la correction.
            // Le PDF devenu faux est oublié, le prochain accès le régénère
            // (voir BillingDocumentProvider).
            $invoice->setAmountExclTax($registration->getAmountExclTax())
                ->setTaxAmount(bcsub($registration->getAmountInclTax(), $registration->getAmountExclTax(), 2))
                ->setAmountInclTax($registration->getAmountInclTax())
                ->setBillingDataSnapshot($registration->getPrimaryParticipant()?->getBillingData() ?? [])
                ->setPdfPath(null);
        }

        $this->em->flush();

        $this->logger->info('registration.manual.edited', [
            'registration_id' => $registration->getId(),
            'fare' => $registration->getFareCode(),
            'amount' => $registration->getAmountInclTax(),
            'invoice_number' => $invoice?->getNumber(),
        ]);

        return $invoice;
    }
}
