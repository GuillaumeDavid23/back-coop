<?php

namespace App\Service\Billing;

use App\Entity\Invoice;
use App\Entity\Payment;
use App\Entity\PaymentMethod;
use App\Entity\PaymentStatus;
use App\Entity\Registration;
use App\Entity\RegistrationStatus;
use App\Message\GenerateInvoicePdfMessage;
use App\Message\SendInvoiceEmailMessage;
use App\Message\SendRegistrationConfirmationMessage;
use App\Repository\InvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Constat manuel du sort d'une inscription que Stripe n'a jamais confirmée :
 * l'utilisateur du BO tranche lui-même entre "l'argent est arrivé" et "il
 * n'arrivera pas", sur n'importe quelle inscription restée en attente - qu'une
 * session Stripe ait été ouverte, abandonnée, ou qu'aucune tentative de
 * paiement n'existe.
 *
 *  - switchToManual() : bascule seule, règlement encore attendu. Pour un
 *    virement, la facture peut être émise dès maintenant : non acquittée et
 *    portant les coordonnées bancaires, c'est elle qui déclenche le paiement.
 *  - recordPaid()     : règlement reçu hors Stripe. L'inscription est
 *    confirmée ; la facture déjà émise devient acquittée, sinon elle est créée
 *    acquittée d'emblée - exactement comme après un paiement CB.
 *
 * Pendant du PaymentSynchronizer, qui fait le même travail à partir de l'état
 * réel d'une session Stripe. Ces deux services sont les seuls à confirmer une
 * inscription : le statut n'est jamais éditable librement dans le BO (voir
 * RegistrationCrudController).
 */
final class ManualPaymentRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceRepository $invoices,
        private readonly MessageBusInterface $bus,
        #[Autowire(service: 'monolog.logger.payment')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Seule une inscription en attente de paiement se prête à un constat
     * manuel : une inscription confirmée est payée (la reconstater émettrait
     * une seconde facture), une désinscrite n'a plus rien à encaisser - elle
     * relève de la désinscription et de l'avoir (voir
     * RegistrationCancellationService).
     */
    public function canRecord(Registration $registration): bool
    {
        return RegistrationStatus::PENDING === $registration->getStatus();
    }

    /**
     * Bascule en gestion manuelle sans rien constater encore : le règlement est
     * attendu (virement annoncé, chèque en route). L'inscription reste non
     * payée, mais cesse d'afficher un paiement Stripe "en cours" qui
     * n'aboutira pas.
     *
     * @param bool $issueInvoice      émettre tout de suite la facture, non acquittée et
     *                                portant les coordonnées bancaires. C'est le fonctionnement
     *                                attendu pour un virement : sans facture, le client n'a ni
     *                                le RIB ni la référence à mettre dans son libellé.
     * @param bool $notifyParticipant envoyer cette facture au participant
     */
    public function switchToManual(
        Registration $registration,
        PaymentMethod $method,
        bool $issueInvoice,
        bool $notifyParticipant,
        string $recordedBy,
    ): Payment {
        $this->assertCanRecord($registration);
        $this->assertNotCard($method);

        $this->closePendingAttempt($registration);

        // Un paiement en attente est ouvert dès la bascule : la facture émise
        // avant encaissement doit pouvoir s'y rattacher et y lire son mode de
        // règlement ("Virement"), et le constat de réception se posera dessus.
        $payment = $this->createManualPayment($registration, $method, null, $recordedBy)
            ->setStatus(PaymentStatus::PENDING);

        $this->em->persist($payment);
        $registration->switchToManualHandling($recordedBy);
        $this->em->flush();

        $context = [
            'registration_id' => $registration->getId(),
            'payment_id' => $payment->getId(),
            'method' => $method->value,
            'issue_invoice' => $issueInvoice,
            'notify_participant' => $notifyParticipant,
            'recorded_by' => $recordedBy,
        ];
        $this->logger->info('payment.manual.switched', $context);

        if ($issueInvoice && $registration->getSite()->isInvoicingEnabled()
            && null === $this->invoices->findOneBy(['registration' => $registration])
        ) {
            // Le paiement n'étant pas encaissé, la facture sera créée non
            // acquittée (voir GenerateInvoicePdfMessageHandler) et l'email
            // annoncera une facture à régler, pas une inscription confirmée.
            $this->bus->dispatch(new GenerateInvoicePdfMessage($payment->getId(), $notifyParticipant));
        }

        return $payment;
    }

    /**
     * Règlement reçu hors Stripe : confirme l'inscription, et acquitte la
     * facture - déjà émise dans le cas d'un virement, créée à l'instant sinon.
     *
     * @param \DateTimeImmutable $paidAt            date de réception réelle du règlement (portée par la facture)
     * @param string|null        $reference         n° de chèque, libellé du virement... imprimé sur la facture
     * @param string             $recordedBy        email de l'utilisateur du BO qui constate le règlement
     * @param bool               $notifyParticipant envoyer la confirmation (facture acquittée en pièce jointe) ;
     *                                              décochable quand la personne a déjà été prévenue. La facture est
     *                                              émise dans tous les cas et reste téléchargeable depuis le BO.
     */
    public function recordPaid(
        Registration $registration,
        PaymentMethod $method,
        \DateTimeImmutable $paidAt,
        ?string $reference,
        string $recordedBy,
        bool $notifyParticipant = true,
    ): Payment {
        $this->assertCanRecord($registration);
        $this->assertNotCard($method);

        // Le règlement attendu depuis la bascule en manuel est celui qui arrive :
        // on le complète au lieu d'en ouvrir un second, sans quoi la facture
        // déjà émise resterait rattachée à un paiement jamais encaissé.
        $payment = $this->awaitedManualPayment($registration);

        if (null === $payment) {
            $this->closePendingAttempt($registration);
            $payment = $this->createManualPayment($registration, $method, $reference, $recordedBy);
            $this->em->persist($payment);
        } else {
            $payment->setMethod($method)
                ->setManualReference($reference)
                ->setManuallyValidatedBy($recordedBy);
        }

        $payment->setStatus(PaymentStatus::SUCCEEDED)->setPaidAt($paidAt);

        // Constater un règlement, c'est déjà gérer le paiement à la main : la
        // bascule est implicite, personne ne devrait avoir à la faire d'abord.
        $registration->switchToManualHandling($recordedBy);
        $registration->setStatus(RegistrationStatus::CONFIRMED);
        $this->em->flush();

        $context = [
            'registration_id' => $registration->getId(),
            'payment_id' => $payment->getId(),
            'method' => $method->value,
            'amount' => $payment->getAmount(),
            'paid_at' => $paidAt->format(\DATE_ATOM),
            'recorded_by' => $recordedBy,
            'notify_participant' => $notifyParticipant,
        ];
        $this->logger->info('payment.manual.paid', $context);

        $invoice = $this->invoices->findOneBy(['registration' => $registration]);

        if (!$registration->getSite()->isInvoicingEnabled()) {
            // Facturation désactivée pour ce site : il n'y a que la confirmation
            // à envoyer, et donc rien du tout si l'envoi est décoché - aucun
            // numéro de facture n'est tiré dans un cas comme dans l'autre.
            if ($notifyParticipant) {
                $this->bus->dispatch(new SendRegistrationConfirmationMessage($registration->getId()));
            }
        } elseif (null !== $invoice) {
            $this->settle($invoice, $paidAt, $notifyParticipant, $context);
        } else {
            $this->bus->dispatch(new GenerateInvoicePdfMessage($payment->getId(), $notifyParticipant));
        }

        return $payment;
    }

    /**
     * Passage de "à régler" à "acquittée" : même facture, même numéro - seul le
     * PDF change, puisqu'il ne doit plus porter les coordonnées bancaires, qui
     * inviteraient à un second virement.
     *
     * Le rendu n'est pas refait ici : on oublie le chemin du PDF devenu faux, et
     * le premier accès - envoi de l'email ou téléchargement depuis le BO - le
     * régénère au bon état (voir BillingDocumentProvider). Le constat de
     * paiement ne dépend ainsi pas d'un rendu qui peut échouer, et aucun
     * document périmé ne peut être servi entre-temps.
     *
     * @param array<string, mixed> $context contexte de log de l'appelant
     */
    private function settle(Invoice $invoice, \DateTimeImmutable $paidAt, bool $notifyParticipant, array $context): void
    {
        $invoice->setSettledAt($paidAt)->setPdfPath(null);
        $this->em->flush();

        $this->logger->info('invoice.settled', $context + [
            'invoice_id' => $invoice->getId(),
            'invoice_number' => $invoice->getNumber(),
        ]);

        if ($notifyParticipant) {
            $this->bus->dispatch(new SendInvoiceEmailMessage($invoice->getId()));
        }
    }

    private function assertCanRecord(Registration $registration): void
    {
        if (!$this->canRecord($registration)) {
            throw new \LogicException(sprintf(
                'Seule une inscription en attente de paiement peut faire l\'objet d\'un constat manuel (inscription #%d au statut "%s").',
                $registration->getId(),
                $registration->getStatus()->value,
            ));
        }
    }

    /** Un encaissement carte ne peut venir que de Stripe : l'accepter ici fabriquerait un paiement CB dont la banque n'a aucune trace. */
    private function assertNotCard(PaymentMethod $method): void
    {
        if (PaymentMethod::CARD === $method) {
            throw new \LogicException('Un paiement par carte ne se constate pas à la main : il est encaissé par Stripe.');
        }
    }

    /** Le règlement hors Stripe ouvert à la bascule en manuel, et toujours attendu. */
    private function awaitedManualPayment(Registration $registration): ?Payment
    {
        $payment = $registration->getLatestPayment();

        return null !== $payment && $payment->isManual() && PaymentStatus::PENDING === $payment->getStatus()
            ? $payment
            : null;
    }

    /**
     * La tentative Stripe laissée en attente n'aboutira jamais, puisque le
     * règlement passe désormais par un autre canal : on la classe abandonnée
     * plutôt que de la laisser "en cours". Le règlement hors Stripe est
     * enregistré à côté, sur un paiement distinct - l'historique Stripe reste
     * ainsi lisible tel qu'il s'est passé, et aucune donnée de session n'est
     * réécrite.
     */
    private function closePendingAttempt(Registration $registration): void
    {
        $attempt = $registration->getLatestPayment();

        if (null !== $attempt && PaymentStatus::PENDING === $attempt->getStatus()) {
            $attempt->setStatus(PaymentStatus::FAILED);
        }
    }

    /** Le montant est toujours celui de l'inscription : une facture ne doit jamais s'écarter du tarif choisi. */
    private function createManualPayment(
        Registration $registration,
        PaymentMethod $method,
        ?string $reference,
        string $recordedBy,
    ): Payment {
        $payment = (new Payment())
            ->setSite($registration->getSite())
            ->setRegistration($registration)
            ->setAmount($registration->getAmountInclTax())
            ->setMethod($method)
            ->setManualReference($reference)
            ->setManuallyValidatedBy($recordedBy);

        // Le paiement doit être visible depuis l'inscription sans attendre un
        // rechargement : getLatestPayment() sert aux libellés du BO juste après.
        $registration->getPayments()->add($payment);

        return $payment;
    }
}
