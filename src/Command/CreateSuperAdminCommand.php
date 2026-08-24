<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Création du premier compte ROLE_SUPER_ADMIN. Volontairement en CLI plutôt
 * que via une UI de création de compte dev (moins de surface d'attaque) —
 * voir plan de migration, point sur la sécurité du BO.
 */
#[AsCommand(name: 'app:create-super-admin', description: 'Crée ou met à jour un compte super administrateur')]
final class CreateSuperAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('password', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');
        $password = $input->getArgument('password');

        $user = $this->users->findOneBy(['email' => $email]) ?? new User();
        $user->setEmail($email)
            ->setRoles(['ROLE_SUPER_ADMIN'])
            ->setEnabled(true)
            ->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Super admin "%s" prêt.', $email));

        return Command::SUCCESS;
    }
}
