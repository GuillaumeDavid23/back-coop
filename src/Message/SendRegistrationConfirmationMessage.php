<?php

namespace App\Message;

/**
 * Confirmation d'inscription sans facture : dispatché à la place de
 * GenerateInvoicePdfMessage quand la facturation du site est désactivée
 * (Site::invoicingEnabled à false).
 */
final class SendRegistrationConfirmationMessage
{
    public function __construct(
        public readonly int $registrationId,
    ) {
    }
}
