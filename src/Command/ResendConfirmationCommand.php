<?php

namespace App\Command;

use App\Repository\InvoiceRepository;
use App\Service\Mail\RegistrationConfirmationMailer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renvoie le mail de confirmation d'inscription (facture jointe) pour une
 * facture donnée — cas classique : le participant dit ne pas l'avoir reçu.
 * Réutilise exactement le même mail que l'envoi automatique.
 */
#[AsCommand(name: 'app:resend-confirmation', description: "Renvoie le mail de confirmation d'inscription pour une facture")]
final class ResendConfirmationCommand extends Command
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly RegistrationConfirmationMailer $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('number', InputArgument::REQUIRED, 'Numéro de facture, ex : CAC26-000004');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $number = (string) $input->getArgument('number');

        $invoice = $this->invoices->findOneBy(['number' => $number]);
        if (null === $invoice) {
            $io->error(sprintf('Facture "%s" introuvable.', $number));

            return Command::FAILURE;
        }

        $this->mailer->sendForInvoice($invoice);

        $io->success(sprintf(
            'Mail de confirmation renvoyé pour la facture %s (%s).',
            $invoice->getNumber(),
            $invoice->getRegistration()->getParticipantsEmails(),
        ));

        return Command::SUCCESS;
    }
}
