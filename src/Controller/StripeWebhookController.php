<?php

namespace App\Controller;

use App\Entity\PaymentStatus;
use App\Repository\PaymentRepository;
use App\Service\Stripe\PaymentSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Event;
use Stripe\Webhook;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le success_url de Stripe Checkout ne sert qu'à l'affichage côté
 * utilisateur : la seule source de vérité pour valider un paiement est ce
 * webhook, vérifié par signature. Idempotent par construction : un même
 * événement Stripe envoyé plusieurs fois ne doit jamais créer deux
 * paiements/factures (voir vérifications d'existence avant chaque création).
 */
final class StripeWebhookController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaymentRepository $payments,
        private readonly PaymentSynchronizer $synchronizer,
        #[Autowire(env: 'STRIPE_WEBHOOK_SECRET')]
        private readonly string $webhookSecret,
        #[Autowire(service: 'monolog.logger.payment')]
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/webhook/stripe', name: 'stripe_webhook', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();
        $signature = $request->headers->get('Stripe-Signature', '');

        $this->logger->debug('stripe.webhook.received', [
            'payload_size' => strlen($payload),
            'has_signature_header' => $signature !== '',
            'ip' => $request->getClientIp(),
        ]);

        try {
            $event = Webhook::constructEvent($payload, $signature, $this->webhookSecret);
        } catch (\UnexpectedValueException|\Stripe\Exception\SignatureVerificationException $e) {
            // Signature invalide : soit une mauvaise config STRIPE_WEBHOOK_SECRET,
            // soit une tentative malveillante - dans les deux cas ça mérite un warning.
            $this->logger->warning('stripe.webhook.signature_invalid', [
                'exception' => $e->getMessage(),
                'ip' => $request->getClientIp(),
            ]);

            return new Response('Invalid signature', 400);
        }

        $this->logger->info('stripe.webhook.verified', [
            'event_id' => $event->id,
            'event_type' => $event->type,
            'livemode' => $event->livemode,
        ]);

        try {
            match ($event->type) {
                'checkout.session.completed' => $this->handleCheckoutCompleted($event),
                'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event),
                'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event),
                'charge.refunded' => $this->handleChargeRefunded($event),
                default => $this->logger->debug('stripe.webhook.unhandled_type', [
                    'event_id' => $event->id,
                    'event_type' => $event->type,
                ]),
            };
        } catch (\Throwable $e) {
            $this->logger->error('stripe.webhook.handler_failed', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 500 : Stripe réessaiera cet événement plus tard - c'est voulu,
            // on préfère un retry à une perte silencieuse de paiement.
            return new JsonResponse(['error' => 'handler_failed'], 500);
        }

        return new JsonResponse(['received' => true]);
    }

    /**
     * Délègue au PaymentSynchronizer, partagé avec le bouton "Rafraîchir le
     * paiement" du BO : un paiement en attente créé à l'ouverture du Checkout
     * est ici passé à "réussi", et l'idempotence (pas de double facture) est
     * garantie par le synchroniseur, pas par un test de doublon local.
     */
    private function handleCheckoutCompleted(Event $event): void
    {
        /** @var \Stripe\Checkout\Session $session */
        $session = $event->data->object;

        $this->logger->info('stripe.webhook.checkout_completed', [
            'event_id' => $event->id,
            'stripe_checkout_session_id' => $session->id,
            'registration_id' => $session->metadata['registration_id'] ?? null,
        ]);

        $this->synchronizer->syncFromSession($session);
    }

    private function handlePaymentIntentSucceeded(Event $event): void
    {
        /** @var \Stripe\PaymentIntent $intent */
        $intent = $event->data->object;

        $this->logger->info('stripe.webhook.payment_intent_succeeded', [
            'event_id' => $event->id,
            'payment_intent_id' => $intent->id,
        ]);

        $payment = $this->payments->findByStripePaymentIntentId($intent->id);
        if ($payment !== null && $payment->getStatus() !== PaymentStatus::SUCCEEDED) {
            $payment->setStatus(PaymentStatus::SUCCEEDED)->setPaidAt(new \DateTimeImmutable());
            $this->em->flush();
        }
    }

    private function handlePaymentIntentFailed(Event $event): void
    {
        /** @var \Stripe\PaymentIntent $intent */
        $intent = $event->data->object;

        $this->logger->warning('stripe.webhook.payment_intent_failed', [
            'event_id' => $event->id,
            'payment_intent_id' => $intent->id,
            'last_payment_error' => $intent->last_payment_error?->message,
        ]);

        $payment = $this->payments->findByStripePaymentIntentId($intent->id);
        if ($payment !== null) {
            $payment->setStatus(PaymentStatus::FAILED);
            $this->em->flush();
        }
    }

    private function handleChargeRefunded(Event $event): void
    {
        /** @var \Stripe\Charge $charge */
        $charge = $event->data->object;

        $this->logger->info('stripe.webhook.charge_refunded', [
            'event_id' => $event->id,
            'payment_intent_id' => $charge->payment_intent,
            'amount_refunded' => $charge->amount_refunded,
        ]);

        $payment = is_string($charge->payment_intent)
            ? $this->payments->findByStripePaymentIntentId($charge->payment_intent)
            : null;

        if ($payment !== null && $payment->getStatus() !== PaymentStatus::REFUNDED) {
            $payment->setStatus(PaymentStatus::REFUNDED);
            $this->em->flush();
            // La création de l'avoir (CreditNote) associé est un acte de
            // gestion volontaire fait depuis le BO, pas automatique ici -
            // on se contente de tracer que le remboursement Stripe est arrivé.
            $this->logger->info('stripe.webhook.charge_refunded.payment_marked_refunded', [
                'payment_id' => $payment->getId(),
            ]);
        }
    }
}
