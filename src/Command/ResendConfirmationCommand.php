<?php

namespace App\Command;

use App\Repository\InvoiceRepository;
use App\Repository\RegistrationRepository;
use App\Service\Mail\RegistrationConfirmationMailer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renvoie le mail de confirmation d'inscription - cas classique : le
 * participant dit ne pas l'avoir reçu. Réutilise exactement le même mail que
 * l'envoi automatique.
 *
 * Deux entrées possibles : un numéro de facture, ou un numéro d'inscription
 * pour les sites qui n'émettent pas de facture (voir Site::invoicingEnabled).
 * Ces derniers n'avaient sinon aucun moyen de rattrapage, alors que l'envoi
 * des mails est synchrone et donc sans réessai automatique.
 */
#[AsCommand(name: 'app:resend-confirmation', description: "Renvoie le mail de confirmation d'inscription")]
final class ResendConfirmationCommand extends Command
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly RegistrationRepository $registrations,
        private readonly RegistrationConfirmationMailer $mailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('number', InputArgument::OPTIONAL, 'Numéro de facture, ex : CAC26-000004')
            ->addOption('registration', 'r', InputOption::VALUE_REQUIRED, "Numéro d'inscription, pour un site sans facturation");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $number = $input->getArgument('number');
        $registrationId = $input->getOption('registration');

        if ((null === $number) === (null === $registrationId)) {
            $io->error('Indiquez soit un numéro de facture, soit --registration=<id>.');

            return Command::FAILURE;
        }

        if (null !== $registrationId) {
            $registration = $this->registrations->find((int) $registrationId);
            if (null === $registration) {
                $io->error(sprintf('Inscription #%s introuvable.', $registrationId));

                return Command::FAILURE;
            }

            $this->mailer->sendForRegistration($registration);

            $io->success(sprintf(
                'Mail de confirmation renvoyé pour l\'inscription #%d (%s).',
                $registration->getId(),
                $registration->getParticipantsEmails(),
            ));

            return Command::SUCCESS;
        }

        $invoice = $this->invoices->findOneBy(['number' => (string) $number]);
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
