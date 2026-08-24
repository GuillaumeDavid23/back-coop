<?php

namespace App\Repository;

use App\Entity\InvoiceSequence;
use App\Entity\Site;
use App\Entity\SequenceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvoiceSequence>
 */
class InvoiceSequenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvoiceSequence::class);
    }

    /**
     * Doit être appelé à l'intérieur d'une transaction ouverte par
     * l'appelant (voir Service\Billing\NumberingService) : le verrou
     * PESSIMISTIC_WRITE n'a d'effet que sous transaction.
     */
    public function findOneForUpdate(Site $site, SequenceType $type): ?InvoiceSequence
    {
        $sequence = $this->findOneBy(['site' => $site, 'type' => $type]);

        if ($sequence === null) {
            return null;
        }

        $this->getEntityManager()->lock($sequence, LockMode::PESSIMISTIC_WRITE);

        return $sequence;
    }
}
