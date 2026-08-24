<?php

namespace App\Message;

final class SendInvoiceEmailMessage
{
    public function __construct(
        public readonly int $invoiceId,
    ) {
    }
}
