<?php

namespace App\Service\Search;

use App\Entity\CreditNote;
use App\Entity\Invoice;
use App\Entity\Participant;
use App\Entity\Registration;
use App\Entity\Site;
use App\Service\Export\AnswerHumanizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Recherche globale du BO, façon Stripe : une seule barre qui interroge en
 * parallèle les participants (nom, prénom, email, cabinet), les inscriptions
 * (par identifiant) et les documents de facturation (numéro de facture ou
 * d'avoir, en préfixe / suffixe / fragment).
 *
 * Toujours restreinte au site courant : la recherche ne doit jamais laisser
 * fuiter les données d'un autre événement (voir AbstractSiteScopedCrudController).
 */
final class GlobalSearchService
{
    private const int PER_GROUP = 6;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return array<int, array{type: string, label: string, sublabel: string, badge: ?string, entityId: int}> */
    public function search(Site $site, string $term, int $limit = 20): array
    {
        $term = trim($term);

        // Un identifiant d'inscription peut n'avoir qu'un chiffre ("5") : on
        // n'impose les 2 caractères minimum qu'aux recherches textuelles.
        $minLength = ctype_digit($term) ? 1 : 2;
        if (mb_strlen($term) < $minLength) {
            return [];
        }

        $results = [
            ...$this->searchParticipants($site, $term),
            ...$this->searchInvoices($site, $term),
            ...$this->searchCreditNotes($site, $term),
            ...$this->searchRegistrationById($site, $term),
        ];

        return \array_slice($results, 0, $limit);
    }

    private function searchParticipants(Site $site, string $term): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('p', 'r')
            ->from(Participant::class, 'p')
            ->innerJoin('p.registration', 'r')
            ->andWhere('r.site = :site')
            ->setParameter('site', $site)
            ->setMaxResults(self::PER_GROUP);

        // CONCAT pour retrouver "Jean Dupont" comme "Dupont Jean".
        $qb->andWhere($qb->expr()->orX(
            'LOWER(p.firstName) LIKE :like',
            'LOWER(p.lastName) LIKE :like',
            'LOWER(p.email) LIKE :like',
            'LOWER(p.company) LIKE :like',
            "LOWER(CONCAT(p.firstName, ' ', p.lastName)) LIKE :like",
            "LOWER(CONCAT(p.lastName, ' ', p.firstName)) LIKE :like",
        ))->setParameter('like', '%'.mb_strtolower($term).'%');

        $results = [];
        foreach ($qb->getQuery()->getResult() as $participant) {
            /** @var Participant $participant */
            $registration = $participant->getRegistration();
            $results[] = [
                'type' => 'Participant',
                'label' => $participant->getFullName(),
                'sublabel' => implode(' · ', array_filter([
                    $participant->getEmail(),
                    $participant->getCompany(),
                    $registration->getFareLabel(),
                ])),
                'badge' => AnswerHumanizer::registrationStatus($registration->getStatus()->value),
                'entityId' => (int) $registration->getId(),
            ];
        }

        return $results;
    }

    private function searchInvoices(Site $site, string $term): array
    {
        $invoices = $this->em->createQueryBuilder()
            ->select('i', 'r')
            ->from(Invoice::class, 'i')
            ->innerJoin('i.registration', 'r')
            ->andWhere('i.site = :site')
            ->andWhere('LOWER(i.number) LIKE :like')
            ->setParameter('site', $site)
            ->setParameter('like', '%'.mb_strtolower($term).'%')
            ->setMaxResults(self::PER_GROUP)
            ->getQuery()
            ->getResult();

        $results = [];
        foreach ($invoices as $invoice) {
            /** @var Invoice $invoice */
            $results[] = [
                'type' => 'Facture',
                'label' => $invoice->getNumber(),
                'sublabel' => sprintf(
                    '%s · %s € · %s',
                    $invoice->getBillingDataSnapshot()['name'] ?? '—',
                    $invoice->getAmountInclTax(),
                    $invoice->getIssuedAt()->format('d/m/Y'),
                ),
                'badge' => null,
                'entityId' => (int) $invoice->getRegistration()->getId(),
            ];
        }

        return $results;
    }

    private function searchCreditNotes(Site $site, string $term): array
    {
        $creditNotes = $this->em->createQueryBuilder()
            ->select('cn', 'i', 'r')
            ->from(CreditNote::class, 'cn')
            ->innerJoin('cn.invoice', 'i')
            ->innerJoin('i.registration', 'r')
            ->andWhere('cn.site = :site')
            ->andWhere('LOWER(cn.number) LIKE :like')
            ->setParameter('site', $site)
            ->setParameter('like', '%'.mb_strtolower($term).'%')
            ->setMaxResults(self::PER_GROUP)
            ->getQuery()
            ->getResult();

        $results = [];
        foreach ($creditNotes as $creditNote) {
            /** @var CreditNote $creditNote */
            $results[] = [
                'type' => 'Avoir',
                'label' => $creditNote->getNumber(),
                'sublabel' => sprintf(
                    'Facture %s · %s € · %s',
                    $creditNote->getInvoice()->getNumber(),
                    $creditNote->getAmount(),
                    $creditNote->getIssuedAt()->format('d/m/Y'),
                ),
                'badge' => null,
                'entityId' => (int) $creditNote->getInvoice()->getRegistration()->getId(),
            ];
        }

        return $results;
    }

    private function searchRegistrationById(Site $site, string $term): array
    {
        if (!ctype_digit($term)) {
            return [];
        }

        $registration = $this->em->getRepository(Registration::class)
            ->findOneBy(['id' => (int) $term, 'site' => $site]);

        if (null === $registration) {
            return [];
        }

        return [[
            'type' => 'Inscription',
            'label' => sprintf('Inscription #%d', $registration->getId()),
            'sublabel' => sprintf('%s · %s', $registration->getParticipantsFullNames() ?: '—', $registration->getFareLabel()),
            'badge' => AnswerHumanizer::registrationStatus($registration->getStatus()->value),
            'entityId' => (int) $registration->getId(),
        ]];
    }
}
