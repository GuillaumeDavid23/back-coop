<?php

namespace App\Message;

final class GenerateInvoicePdfMessage
{
    /**
     * @param bool $notifyParticipant envoyer la facture au participant une fois
     *                                générée. Toujours vrai après un paiement
     *                                Stripe ; décochable lors d'un constat
     *                                manuel, quand la personne a déjà été
     *                                prévenue autrement (voir
     *                                ManualPaymentRecorder). La facture, elle,
     *                                est générée dans tous les cas.
     */
    public function __construct(
        public readonly int $paymentId,
        public readonly bool $notifyParticipant = true,
    ) {
    }
}
