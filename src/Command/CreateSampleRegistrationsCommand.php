<?php

namespace App\Command;

use App\Entity\Invoice;
use App\Entity\Participant;
use App\Entity\Payment;
use App\Entity\PaymentStatus;
use App\Entity\Registration;
use App\Entity\RegistrationStatus;
use App\Repository\SiteRepository;
use App\Service\Billing\InvoicePdfGenerator;
use App\Service\Billing\NumberingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Jeu de données de démonstration : quelques inscriptions réalistes pour un
 * site donné, dont certaines payées et facturées (PDF généré) pour pouvoir
 * visualiser le rendu réel dans le BO sans attendre un vrai paiement Stripe.
 */
#[AsCommand(name: 'app:create-sample-registrations', description: 'Crée des inscriptions de démonstration (avec factures) pour un site')]
final class CreateSampleRegistrationsCommand extends Command
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly EntityManagerInterface $em,
        private readonly NumberingService $numbering,
        private readonly InvoicePdfGenerator $pdfGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('site_code', InputArgument::REQUIRED, 'Code du site, ex: seminaire_cac');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $site = $this->sites->findByCode($input->getArgument('site_code'));

        if ($site === null) {
            $io->error('Site introuvable.');

            return Command::FAILURE;
        }

        $samples = [
            [
                'fareCode' => 'cooperateur', 'fareLabel' => 'Coopérateur', 'amount' => '650.00',
                'civility' => 'm', 'firstName' => 'Jean', 'lastName' => 'Dupont', 'email' => 'jean.dupont@cabinet-dupont.fr',
                'phone' => '0612345678', 'company' => 'Cabinet Dupont', 'status' => 'salarie',
                'address' => '12 rue de la Paix', 'postalCode' => '75002', 'city' => 'Paris',
                'motivation' => "Se tenir à jour sur l'IA appliquée à l'audit et échanger avec des confrères.",
                'cocktail' => 'oui', 'paid' => true,
            ],
            [
                'fareCode' => 'non_cooperateur', 'fareLabel' => 'Non coopérateur', 'amount' => '750.00',
                'civility' => 'mme', 'firstName' => 'Claire', 'lastName' => 'Martin', 'email' => 'claire.martin@martin-audit.fr',
                'phone' => '0623456789', 'company' => 'Martin Audit', 'status' => 'tns',
                'address' => '5 avenue Victor Hugo', 'postalCode' => '69002', 'city' => 'Lyon',
                'motivation' => "Approfondir le référentiel VSME pour accompagner nos clients PME.",
                'cocktail' => 'non', 'paid' => true,
            ],
            [
                'fareCode' => 'anecs_cjec', 'fareLabel' => 'ANECS / CJEC - Jeune CAC', 'amount' => '400.00',
                'civility' => 'mme', 'firstName' => 'Léa', 'lastName' => 'Bernard', 'email' => 'lea.bernard@bernard-experts.fr',
                'phone' => '0634567890', 'company' => 'Bernard & Experts', 'status' => 'salarie',
                'address' => '3 place Bellecour', 'postalCode' => '69002', 'city' => 'Lyon',
                'motivation' => "Premier séminaire CAC, je souhaite monter en compétence rapidement.",
                'cocktail' => 'oui', 'paid' => true,
            ],
            [
                'fareCode' => 'cooperateur', 'fareLabel' => 'Coopérateur', 'amount' => '650.00',
                'civility' => 'm', 'firstName' => 'Marc', 'lastName' => 'Petit', 'email' => 'marc.petit@petit-conseil.fr',
                'phone' => '0645678901', 'company' => 'Petit Conseil', 'status' => 'tns',
                'address' => '8 rue Nationale', 'postalCode' => '59000', 'city' => 'Lille',
                'motivation' => "Anticiper l'automatisation des contrôles dans notre cabinet.",
                'cocktail' => 'non', 'paid' => false,
            ],
        ];

        foreach ($samples as $data) {
            $registration = new Registration();
            $registration->setSite($site)
                ->setFareCode($data['fareCode'])
                ->setFareLabel($data['fareLabel'])
                ->setAmountExclTax($data['amount'])
                ->setTaxRate('0.00')
                ->setAmountInclTax($data['amount'])
                ->setStatus($data['paid'] ? RegistrationStatus::CONFIRMED : RegistrationStatus::PENDING)
                ->setAnswers([
                    'motivation' => $data['motivation'],
                    'specialNeeds' => null,
                    'cocktailAttendance' => $data['cocktail'],
                ]);

            $participant = new Participant();
            $participant->setCivility($data['civility'])
                ->setFirstName($data['firstName'])
                ->setLastName($data['lastName'])
                ->setEmail($data['email'])
                ->setPhone($data['phone'])
                ->setCompany($data['company'])
                ->setStatus($data['status'])
                ->setAddress($data['address'])
                ->setPostalCode($data['postalCode'])
                ->setCity($data['city'])
                ->setMotivation($data['motivation'])
                ->setConsentAccepted(true)
                ->setAnswers(['cocktailAttendance' => $data['cocktail']]);

            $registration->addParticipant($participant);
            $this->em->persist($registration);
            $this->em->persist($participant);

            if ($data['paid']) {
                $payment = new Payment();
                $payment->setSite($site)
                    ->setRegistration($registration)
                    ->setStripeCheckoutSessionId('cs_test_demo_'.bin2hex(random_bytes(8)))
                    ->setStripePaymentIntentId('pi_demo_'.bin2hex(random_bytes(8)))
                    ->setAmount($data['amount'])
                    ->setCurrency('eur')
                    ->setStatus(PaymentStatus::SUCCEEDED)
                    ->setPaidAt(new \DateTimeImmutable());
                $this->em->persist($payment);
                $this->em->flush();

                $numbering = $this->numbering->nextInvoiceNumber($site);
                $invoice = new Invoice();
                $invoice->setSite($site)
                    ->setRegistration($registration)
                    ->setPayment($payment)
                    ->setNumber($numbering['number'])
                    ->setSequenceNumber($numbering['sequenceNumber'])
                    ->setAmountExclTax($data['amount'])
                    ->setTaxAmount('0.00')
                    ->setAmountInclTax($data['amount'])
                    ->setIssuedAt(new \DateTimeImmutable())
                    ->setBillingDataSnapshot([
                        'name' => $participant->getFullName(),
                        'company' => $participant->getCompany(),
                        'address' => $participant->getAddress(),
                        'postalCode' => $participant->getPostalCode(),
                        'city' => $participant->getCity(),
                        'email' => $participant->getEmail(),
                    ]);
                $this->em->persist($invoice);
                $this->em->flush();

                $pdfPath = $this->pdfGenerator->generate($invoice);
                $invoice->setPdfPath($pdfPath);
                $this->em->flush();

                $io->writeln(sprintf('  ✓ %s - facture %s (%s)', $participant->getFullName(), $invoice->getNumber(), $pdfPath));
            } else {
                $this->em->flush();
                $io->writeln(sprintf('  · %s - inscription en attente (pas encore payée)', $participant->getFullName()));
            }
        }

        $io->success(sprintf('%d inscriptions de démonstration créées pour "%s".', count($samples), $site->getName()));

        return Command::SUCCESS;
    }
}
