<?php

namespace App\Service\Stripe;

use App\Entity\Payment;
use App\Entity\PaymentStatus;
use App\Entity\Registration;
use App\Entity\RegistrationStatus;
use App\Message\GenerateInvoicePdfMessage;
use App\Message\SendRegistrationConfirmationMessage;
use App\Repository\InvoiceRepository;
use App\Repository\PaymentRepository;
use App\Repository\RegistrationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Checkout\Session;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Point d'entrée unique pour refléter l'état d'une session Stripe Checkout
 * dans la base : utilisé aussi bien par le webhook (source de vérité normale)
 * que par le bouton "Rafraîchir le paiement" du BO (rattrapage manuel quand un
 * webhook s'est perdu). Les deux chemins passent ici pour ne jamais diverger.
 *
 * Idempotent : rejouer la même session ne crée jamais deux paiements ni deux
 * factures — seule la transition "pas encore payé -> payé" déclenche la
 * confirmation de l'inscription et la génération de facture.
 */
final class PaymentSynchronizer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaymentRepository $payments,
        private readonly RegistrationRepository $registrations,
        private readonly InvoiceRepository $invoices,
        private readonly MessageBusInterface $bus,
        #[Autowire(service: 'monolog.logger.payment')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Enregistre le paiement "en attente" dès la création de la session
     * Checkout : sans cette trace, une inscription non payée n'aurait aucun
     * identifiant de session Stripe et on ne pourrait jamais rafraîchir son
     * statut depuis le BO.
     */
    public function createPendingPayment(Registration $registration, Session $session): Payment
    {
        $payment = new Payment();
        $payment->setSite($registration->getSite())
            ->setRegistration($registration)
            ->setStripeCheckoutSessionId($session->id)
            ->setStripePaymentIntentId(\is_string($session->payment_intent) ? $session->payment_intent : null)
            ->setAmount(number_format(($session->amount_total ?? 0) / 100, 2, '.', ''))
            ->setCurrency($session->currency ?? 'eur')
            ->setStatus(PaymentStatus::PENDING);

        $this->em->persist($payment);
        $this->em->flush();

        $this->logger->info('stripe.payment.pending_created', [
            'registration_id' => $registration->getId(),
            'payment_id' => $payment->getId(),
            'stripe_checkout_session_id' => $session->id,
        ]);

        return $payment;
    }

    public function syncFromSession(Session $session): ?Payment
    {
        $payment = $this->payments->findByStripeCheckoutSessionId($session->id);
        $registration = $payment?->getRegistration() ?? $this->resolveRegistration($session);

        $context = [
            'stripe_checkout_session_id' => $session->id,
            'registration_id' => $registration?->getId(),
            'session_status' => $session->status,
            'payment_status' => $session->payment_status,
        ];

        if ($registration === null) {
            $this->logger->error('stripe.payment.sync.registration_not_found', $context);

            return null;
        }

        if ($payment === null) {
            $payment = $this->createPendingPayment($registration, $session);
        }

        if (\is_string($session->payment_intent)) {
            $payment->setStripePaymentIntentId($session->payment_intent);
        }

        $isPaid = 'paid' === $session->payment_status || 'no_payment_required' === $session->payment_status;

        if ($isPaid && PaymentStatus::SUCCEEDED !== $payment->getStatus()) {
            $payment->setStatus(PaymentStatus::SUCCEEDED)->setPaidAt(new \DateTimeImmutable());
            $registration->setStatus(RegistrationStatus::CONFIRMED);
            $this->em->flush();

            $this->logger->info('stripe.payment.sync.confirmed', $context + ['payment_id' => $payment->getId()]);

            if (!$registration->getSite()->isInvoicingEnabled()) {
                // Facturation désactivée pour ce site : la confirmation part
                // directement, sans jamais tirer de numéro de facture.
                $this->bus->dispatch(new SendRegistrationConfirmationMessage($registration->getId()));
            } elseif (null === $this->invoices->findOneBy(['registration' => $registration])) {
                // Deuxième garde-fou contre le doublon de facture : le webhook et le
                // rafraîchissement manuel peuvent arriver quasi simultanément.
                $this->bus->dispatch(new GenerateInvoicePdfMessage($payment->getId()));
            } else {
                $this->logger->info('stripe.payment.sync.invoice_already_exists', $context);
            }

            return $payment;
        }

        if (!$isPaid && 'expired' === $session->status && PaymentStatus::FAILED !== $payment->getStatus()) {
            $payment->setStatus(PaymentStatus::FAILED);
            $this->em->flush();

            $this->logger->info('stripe.payment.sync.expired', $context + ['payment_id' => $payment->getId()]);

            return $payment;
        }

        $this->em->flush();
        $this->logger->debug('stripe.payment.sync.no_change', $context + ['payment_id' => $payment->getId()]);

        return $payment;
    }

    private function resolveRegistration(Session $session): ?Registration
    {
        $registrationId = (int) ($session->metadata['registration_id'] ?? 0);

        return $registrationId > 0 ? $this->registrations->find($registrationId) : null;
    }
}
