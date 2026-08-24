<?php

namespace App\Entity;

enum SequenceType: string
{
    case INVOICE = 'invoice';
    case CREDIT_NOTE = 'credit_note';
}
