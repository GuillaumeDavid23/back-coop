<?php

namespace App\MessageHandler;

use App\Message\SendRegistrationConfirmationMessage;
use App\Repository\RegistrationRepository;
use App\Service\Mail\RegistrationConfirmationMailer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Envoi du mail de confirmation d'inscription sans facture, pour les sites
 * dont la facturation est désactivée (Site::invoicingEnabled) — dispatché par
 * PaymentSynchronizer au moment où le paiement est validé.
 */
#[AsMessageHandler]
final class SendRegistrationConfirmationMessageHandler
{
    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly RegistrationConfirmationMailer $confirmationMailer,
        #[Autowire(service: 'monolog.logger.payment')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendRegistrationConfirmationMessage $message): void
    {
        $registration = $this->registrations->find($message->registrationId);

        if (null === $registration) {
            $this->logger->error('registration.confirmation.registration_not_found', [
                'registration_id' => $message->registrationId,
            ]);

            return;
        }

        $this->confirmationMailer->sendForRegistration($registration);
    }
}
