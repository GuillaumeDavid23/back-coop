<?php

namespace App\Command;

use App\Entity\CreditNote;
use App\Entity\Invoice;
use App\Entity\InvoiceSequence;
use App\Entity\Participant;
use App\Entity\Payment;
use App\Entity\Registration;
use App\Entity\Site;
use App\Repository\SiteRepository;
use App\Service\Billing\CreditNotePdfGenerator;
use App\Service\Billing\InvoicePdfGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Remise à zéro complète du cycle d'inscription d'un site : avoirs, factures
 * (PDF compris), paiements, participants, inscriptions, puis compteurs de
 * numérotation ramenés à 1. Sert à repartir d'une base propre après une phase
 * de tests, avant l'ouverture réelle des inscriptions d'un site.
 *
 * Volontairement une commande console et non une action du BO : la seule
 * protection d'une suppression massive irréversible, c'est de la réserver à
 * qui a un accès SSH au serveur.
 *
 * Destructif et sans retour possible. À ne jamais lancer sur un site qui a
 * encaissé de vrais paiements : les factures émises sont des pièces
 * comptables, et réattribuer leurs numéros après coup casse la continuité de
 * la numérotation exigée par l'administration fiscale.
 */
#[AsCommand(name: 'app:reset-site-data', description: "Efface inscriptions, participants, paiements et factures d'un site, et remet les compteurs à 1")]
final class ResetSiteDataCommand extends Command
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly EntityManagerInterface $em,
        private readonly InvoicePdfGenerator $invoicePdfGenerator,
        private readonly CreditNotePdfGenerator $creditNotePdfGenerator,
        #[Autowire(service: 'monolog.logger.payment')]
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('site_code', InputArgument::REQUIRED, 'Code du site, ex : seminaire_cac')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Ne pas demander de confirmation (déploiement automatisé)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $siteCode = (string) $input->getArgument('site_code');
        $site = $this->sites->findByCode($siteCode);

        if (null === $site) {
            $io->error(sprintf('Site "%s" introuvable.', $siteCode));

            return Command::FAILURE;
        }

        $counts = $this->countExisting($site);
        $io->section(sprintf('Site %s (%s)', $site->getName(), $site->getCode()));
        $io->table(['Donnée', 'À supprimer'], [
            ['Avoirs', $counts['credit_notes']],
            ['Factures', $counts['invoices']],
            ['Paiements', $counts['payments']],
            ['Participants', $counts['participants']],
            ['Inscriptions', $counts['registrations']],
        ]);

        // Les PDF doivent être listés avant la suppression des lignes : après,
        // plus rien ne permet de retrouver les fichiers restés sur le disque.
        $invoicePdfs = $this->pdfPaths(Invoice::class, $site);
        $creditNotePdfs = $this->pdfPaths(CreditNote::class, $site);

        if (!$input->getOption('force')) {
            $io->warning('Suppression définitive et sans sauvegarde. Les numéros de facture déjà émis seront réattribués.');

            if ($io->ask('Tapez le code du site pour confirmer') !== $site->getCode()) {
                $io->comment('Abandon, rien n\'a été supprimé.');

                return Command::SUCCESS;
            }
        }

        $deleted = [];

        // Ordre imposé par les clés étrangères : l'avoir pointe la facture, la
        // facture pointe le paiement et l'inscription, le paiement et le
        // participant pointent l'inscription. Le tout en transaction pour ne
        // jamais laisser le site à moitié effacé.
        $this->em->wrapInTransaction(function () use ($site, &$deleted): void {
            $deleted['credit_notes'] = $this->deleteBySite(CreditNote::class, $site);
            $deleted['invoices'] = $this->deleteBySite(Invoice::class, $site);
            $deleted['payments'] = $this->deleteBySite(Payment::class, $site);
            $deleted['participants'] = $this->em
                ->createQuery(sprintf(
                    'DELETE FROM %s p WHERE p.registration IN (SELECT r.id FROM %s r WHERE r.site = :site)',
                    Participant::class,
                    Registration::class,
                ))
                ->setParameter('site', $site)
                ->execute();
            $deleted['registrations'] = $this->deleteBySite(Registration::class, $site);
            $deleted['sequences'] = $this->em
                ->createQuery(sprintf('UPDATE %s s SET s.nextNumber = 1 WHERE s.site = :site', InvoiceSequence::class))
                ->setParameter('site', $site)
                ->execute();
        });

        // Les entités chargées avant la suppression en masse ne sont plus dans
        // la base : on vide l'unité de travail pour ne pas les voir réapparaître.
        $this->em->clear();

        $removedFiles = $this->deleteFiles($invoicePdfs, $creditNotePdfs);

        $this->logger->warning('site.reset', [
            'site_id' => $site->getId(),
            'site_code' => $siteCode,
            'deleted' => $deleted,
            'deleted_files' => $removedFiles,
        ]);

        $io->success(sprintf(
            '%s remis à zéro : %d inscription(s), %d participant(s), %d paiement(s), %d facture(s), %d avoir(s), %d fichier(s) PDF. Compteurs facture et avoir repartis à 1.',
            $siteCode,
            $deleted['registrations'],
            $deleted['participants'],
            $deleted['payments'],
            $deleted['invoices'],
            $deleted['credit_notes'],
            $removedFiles,
        ));

        return Command::SUCCESS;
    }

    /** @return array<string, int> */
    private function countExisting(Site $site): array
    {
        return [
            'credit_notes' => $this->countBySite(CreditNote::class, $site),
            'invoices' => $this->countBySite(Invoice::class, $site),
            'payments' => $this->countBySite(Payment::class, $site),
            'registrations' => $this->countBySite(Registration::class, $site),
            'participants' => (int) $this->em
                ->createQuery(sprintf(
                    'SELECT COUNT(p.id) FROM %s p WHERE p.registration IN (SELECT r.id FROM %s r WHERE r.site = :site)',
                    Participant::class,
                    Registration::class,
                ))
                ->setParameter('site', $site)
                ->getSingleScalarResult(),
        ];
    }

    /** @param class-string $entityClass */
    private function countBySite(string $entityClass, Site $site): int
    {
        return (int) $this->em
            ->createQuery(sprintf('SELECT COUNT(e.id) FROM %s e WHERE e.site = :site', $entityClass))
            ->setParameter('site', $site)
            ->getSingleScalarResult();
    }

    /** @param class-string $entityClass */
    private function deleteBySite(string $entityClass, Site $site): int
    {
        return $this->em
            ->createQuery(sprintf('DELETE FROM %s e WHERE e.site = :site', $entityClass))
            ->setParameter('site', $site)
            ->execute();
    }

    /**
     * @param class-string $entityClass
     *
     * @return string[]
     */
    private function pdfPaths(string $entityClass, Site $site): array
    {
        return $this->em
            ->createQuery(sprintf('SELECT e.pdfPath FROM %s e WHERE e.site = :site AND e.pdfPath IS NOT NULL', $entityClass))
            ->setParameter('site', $site)
            ->getSingleColumnResult();
    }

    /**
     * @param string[] $invoicePdfs
     * @param string[] $creditNotePdfs
     */
    private function deleteFiles(array $invoicePdfs, array $creditNotePdfs): int
    {
        $removed = 0;

        foreach ($invoicePdfs as $path) {
            $removed += $this->deleteFile($this->invoicePdfGenerator->absolutePath($path));
        }

        foreach ($creditNotePdfs as $path) {
            $removed += $this->deleteFile($this->creditNotePdfGenerator->absolutePath($path));
        }

        return $removed;
    }

    private function deleteFile(string $absolutePath): int
    {
        // Un PDF déjà absent n'est pas une anomalie : BillingDocumentProvider
        // le régénère à la volée, le disque peut donc être en retard sur la base.
        return is_file($absolutePath) && unlink($absolutePath) ? 1 : 0;
    }
}
