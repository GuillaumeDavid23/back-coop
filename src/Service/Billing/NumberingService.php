<?php

namespace App\Service\Billing;

use App\Entity\InvoiceSequence;
use App\Entity\SequenceType;
use App\Entity\Site;
use App\Repository\InvoiceSequenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Attribution de numéros de facture/avoir par site, sous transaction +
 * verrou pessimiste pour garantir qu'aucun numéro n'est jamais attribué
 * deux fois, même sous accès concurrent (deux paiements simultanés).
 */
final class NumberingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceSequenceRepository $sequences,
        #[Autowire(service: 'monolog.logger.payment')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{number: string, sequenceNumber: int} */
    public function nextInvoiceNumber(Site $site): array
    {
        return $this->nextNumber($site, SequenceType::INVOICE, $site->getInvoicePrefix(), $site->getInvoiceSuffix());
    }

    /** @return array{number: string, sequenceNumber: int} */
    public function nextCreditNoteNumber(Site $site): array
    {
        return $this->nextNumber($site, SequenceType::CREDIT_NOTE, $site->getCreditNotePrefix(), $site->getCreditNoteSuffix());
    }

    /** @return array{number: string, sequenceNumber: int} */
    private function nextNumber(Site $site, SequenceType $type, ?string $prefix, ?string $suffix): array
    {
        $this->logger->debug('numbering.start', [
            'site_id' => $site->getId(),
            'site_code' => $site->getCode(),
            'type' => $type->value,
        ]);

        $this->em->beginTransaction();

        try {
            $sequence = $this->sequences->findOneForUpdate($site, $type);

            if ($sequence === null) {
                $sequence = new InvoiceSequence();
                $sequence->setSite($site)->setType($type)->setNextNumber(1);
                $this->em->persist($sequence);
                $this->em->flush();
                // Nouvelle ligne : la reverrouiller pour rester cohérent avec
                // le chemin "sequence existante" avant de poursuivre.
                $sequence = $this->sequences->findOneForUpdate($site, $type);

                $this->logger->info('numbering.sequence_created', [
                    'site_id' => $site->getId(),
                    'site_code' => $site->getCode(),
                    'type' => $type->value,
                ]);
            }

            $sequenceNumber = $sequence->getNextNumber();
            $sequence->setNextNumber($sequenceNumber + 1);
            $this->em->flush();
            $this->em->commit();
        } catch (\Throwable $e) {
            $this->em->rollback();
            $this->logger->error('numbering.failed', [
                'site_id' => $site->getId(),
                'site_code' => $site->getCode(),
                'type' => $type->value,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        $number = sprintf('%s%06d%s', $prefix ?? '', $sequenceNumber, $suffix ?? '');

        $this->logger->info('numbering.assigned', [
            'site_id' => $site->getId(),
            'site_code' => $site->getCode(),
            'type' => $type->value,
            'sequence_number' => $sequenceNumber,
            'formatted_number' => $number,
        ]);

        return ['number' => $number, 'sequenceNumber' => $sequenceNumber];
    }
}
