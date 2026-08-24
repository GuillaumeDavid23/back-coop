<?php

namespace App\Repository;

use App\Entity\Registration;
use App\Entity\RegistrationStatus;
use App\Entity\Site;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Registration>
 */
class RegistrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Registration::class);
    }

    /** @return Registration[] */
    public function findBySite(Site $site): array
    {
        return $this->findBy(['site' => $site], ['createdAt' => 'DESC']);
    }

    /**
     * Compteurs + CA par statut — voir RegistrationCrudController (bandeau de
     * stats au-dessus du tableau). Le CA ne compte que les inscriptions
     * confirmées : une inscription en attente n'est pas encaissée, une
     * désinscription a fait l'objet d'un avoir.
     */
    public function getStats(Site $site): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.status AS status', 'COUNT(r.id) AS count', 'COALESCE(SUM(r.amountInclTax), 0) AS revenue')
            ->andWhere('r.site = :site')
            ->setParameter('site', $site)
            ->groupBy('r.status')
            ->getQuery()
            ->getResult();

        $stats = [
            'confirmed' => 0,
            'pending' => 0,
            'cancelled' => 0,
            'revenue' => 0.0,
            'cancelledRevenue' => 0.0,
        ];

        foreach ($rows as $row) {
            $status = $row['status'] instanceof RegistrationStatus ? $row['status'] : RegistrationStatus::from($row['status']);
            match ($status) {
                RegistrationStatus::CONFIRMED => $stats['confirmed'] = (int) $row['count'],
                RegistrationStatus::PENDING => $stats['pending'] = (int) $row['count'],
                RegistrationStatus::CANCELLED => $stats['cancelled'] = (int) $row['count'],
            };

            if ($status === RegistrationStatus::CONFIRMED) {
                $stats['revenue'] = (float) $row['revenue'];
            }
            if ($status === RegistrationStatus::CANCELLED) {
                $stats['cancelledRevenue'] = (float) $row['revenue'];
            }
        }

        return $stats;
    }
}
