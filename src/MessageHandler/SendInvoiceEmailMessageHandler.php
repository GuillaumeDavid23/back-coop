<?php

namespace App\MessageHandler;

use App\Message\SendInvoiceEmailMessage;
use App\Repository\InvoiceRepository;
use App\Service\Mail\RegistrationConfirmationMailer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Envoi du mail de confirmation d'inscription (facture en pièce jointe), une
 * fois le paiement validé et la facture générée - voir
 * RegistrationConfirmationMailer, qui reprend à l'identique le mail du projet
 * backoffice-clcom (destinataires en copie compris).
 */
#[AsMessageHandler]
final class SendInvoiceEmailMessageHandler
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly RegistrationConfirmationMailer $confirmationMailer,
        #[Autowire(service: 'monolog.logger.payment')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendInvoiceEmailMessage $message): void
    {
        $invoice = $this->invoices->find($message->invoiceId);

        if (null === $invoice) {
            $this->logger->error('invoice.email.invoice_not_found', ['invoice_id' => $message->invoiceId]);

            return;
        }

        $this->confirmationMailer->sendForInvoice($invoice);
    }
}
