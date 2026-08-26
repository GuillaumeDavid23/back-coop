<?php

namespace App\Command;

use App\Entity\PaymentStatus;
use App\Entity\RegistrationStatus;
use App\Message\GenerateInvoicePdfMessage;
use App\Repository\InvoiceRepository;
use App\Repository\PaymentRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Rattrapage : retrouve les inscriptions payées qui n'ont pas de facture et
 * relance leur génération. Utile quand le handler a échoué (extension PHP
 * manquante, PDF en erreur, message perdu…) - la numérotation reste continue
 * puisque le handler ne tire un numéro qu'une fois sûr de pouvoir aboutir.
 */
#[AsCommand(name: 'app:generate-missing-invoices', description: 'Génère les factures manquantes des inscriptions payées')]
final class GenerateMissingInvoicesCommand extends Command
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly InvoiceRepository $invoices,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Liste les factures manquantes sans rien générer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $candidates = $this->payments->findBy(['status' => PaymentStatus::SUCCEEDED]);
        $missing = 0;

        foreach ($candidates as $payment) {
            $registration = $payment->getRegistration();

            if ($registration->getStatus() !== RegistrationStatus::CONFIRMED) {
                continue;
            }

            // Un site sans facturation n'a jamais de facture "manquante".
            if (!$registration->getSite()->isInvoicingEnabled()) {
                continue;
            }

            if ($this->invoices->findOneBy(['registration' => $registration]) !== null) {
                continue;
            }

            ++$missing;
            $io->writeln(sprintf(
                '  %s inscription #%d (paiement #%d, %s €)',
                $dryRun ? '·' : '→',
                $registration->getId(),
                $payment->getId(),
                $payment->getAmount(),
            ));

            if (!$dryRun) {
                $this->bus->dispatch(new GenerateInvoicePdfMessage($payment->getId()));
            }
        }

        if (0 === $missing) {
            $io->success('Aucune facture manquante.');

            return Command::SUCCESS;
        }

        $io->success($dryRun
            ? sprintf('%d facture(s) manquante(s) détectée(s).', $missing)
            : sprintf('%d génération(s) de facture relancée(s).', $missing));

        return Command::SUCCESS;
    }
}
