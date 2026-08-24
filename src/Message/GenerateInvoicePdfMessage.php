<?php

namespace App\Message;

final class GenerateInvoicePdfMessage
{
    public function __construct(
        public readonly int $paymentId,
    ) {
    }
}
