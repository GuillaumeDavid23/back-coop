<?php

namespace App\Repository;

use App\Entity\Site;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Site>
 */
class SiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Site::class);
    }

    public function findByDomain(string $domain): ?Site
    {
        return $this->findOneBy(['domain' => $domain]);
    }

    public function findByCode(string $code): ?Site
    {
        return $this->findOneBy(['code' => $code]);
    }

    /** @return Site[] */
    public function findAccessibleToUser(User $user): array
    {
        if ($user->hasAccessToAllSites()) {
            return $this->findBy(['enabled' => true], ['name' => 'ASC']);
        }

        return $this->createQueryBuilder('s')
            ->innerJoin('s.users', 'u')
            ->andWhere('u = :user')
            ->andWhere('s.enabled = true')
            ->setParameter('user', $user)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
