<?php

namespace App\Service\Stripe;

use App\Entity\Registration;
use Psr\Log\LoggerInterface;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class StripeCheckoutService
{
    private StripeClient $client;

    public function __construct(
        #[Autowire(env: 'STRIPE_SECRET_KEY')]
        string $secretKey,
        #[Autowire(service: 'monolog.logger.payment')]
        private readonly LoggerInterface $logger,
    ) {
        $this->client = new StripeClient($secretKey);
    }

    public function createCheckoutSession(Registration $registration, string $successUrl, string $cancelUrl): Session
    {
        $site = $registration->getSite();
        $amountInCents = (int) round(((float) $registration->getAmountInclTax()) * 100);
        $email = $registration->getPrimaryParticipant()?->getEmail();

        $context = [
            'site_id' => $site->getId(),
            'site_code' => $site->getCode(),
            'registration_id' => $registration->getId(),
            'fare_code' => $registration->getFareCode(),
            'amount_cents' => $amountInCents,
        ];

        $this->logger->info('stripe.checkout.create.start', $context);

        $payload = [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => $amountInCents,
                    'product_data' => [
                        'name' => sprintf('%s - %s', $site->getName(), $registration->getFareLabel()),
                    ],
                ],
            ]],
            'metadata' => [
                'site_id' => (string) $site->getId(),
                'site_code' => $site->getCode(),
                'registration_id' => (string) $registration->getId(),
            ],
        ];

        // Préremplit l'email déjà saisi dans le formulaire d'inscription : le
        // champ est alors affiché en lecture seule par Stripe, ce qui garantit
        // que le reçu part sur la même adresse que la confirmation d'inscription.
        if (null !== $email) {
            $payload['customer_email'] = $email;
        }

        try {
            $session = $this->client->checkout->sessions->create($payload);
        } catch (ApiErrorException $e) {
            $this->logger->error('stripe.checkout.create.failed', $context + [
                'exception' => $e->getMessage(),
                'stripe_error_type' => $e->getError()?->type,
                'stripe_error_code' => $e->getError()?->code,
            ]);
            throw $e;
        }

        $this->logger->info('stripe.checkout.create.success', $context + [
            'stripe_checkout_session_id' => $session->id,
        ]);

        return $session;
    }

    /**
     * Relit l'état d'une session Checkout auprès de Stripe - utilisé par le
     * bouton "Rafraîchir le paiement" du BO quand un webhook s'est perdu
     * (voir PaymentSynchronizer).
     */
    public function retrieveCheckoutSession(string $sessionId): Session
    {
        try {
            return $this->client->checkout->sessions->retrieve($sessionId);
        } catch (ApiErrorException $e) {
            $this->logger->error('stripe.checkout.retrieve.failed', [
                'stripe_checkout_session_id' => $sessionId,
                'exception' => $e->getMessage(),
                'stripe_error_type' => $e->getError()?->type,
            ]);
            throw $e;
        }
    }
}
