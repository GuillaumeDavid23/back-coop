<?php

namespace App\Repository;

use App\Entity\Payment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    public function findByStripeCheckoutSessionId(string $sessionId): ?Payment
    {
        return $this->findOneBy(['stripeCheckoutSessionId' => $sessionId]);
    }

    public function findByStripePaymentIntentId(string $paymentIntentId): ?Payment
    {
        return $this->findOneBy(['stripePaymentIntentId' => $paymentIntentId]);
    }
}
