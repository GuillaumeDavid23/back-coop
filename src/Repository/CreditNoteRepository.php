<?php

namespace App\Repository;

use App\Entity\CreditNote;
use App\Entity\Registration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CreditNote>
 */
class CreditNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CreditNote::class);
    }

    /** L'avoir d'une inscription passe par sa facture (CreditNote n'a pas de lien direct vers Registration). */
    public function findOneByRegistration(Registration $registration): ?CreditNote
    {
        return $this->createQueryBuilder('cn')
            ->innerJoin('cn.invoice', 'i')
            ->andWhere('i.registration = :registration')
            ->setParameter('registration', $registration)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
