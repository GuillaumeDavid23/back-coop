<?php

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Réservé à ROLE_SUPER_ADMIN (voir DashboardController::configureMenuItems
 * qui ne montre ce menu qu'à ce rôle, et access_control global sur /admin
 * qui exige déjà ROLE_ADMIN a minima).
 */
final class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Utilisateur')
            ->setEntityLabelInPlural('Utilisateurs')
            ->setDefaultSort(['email' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield EmailField::new('email');
        yield ChoiceField::new('roles')
            ->setChoices([
                'Administrateur (sites autorisés)' => 'ROLE_ADMIN',
                'Super administrateur (tous droits)' => 'ROLE_SUPER_ADMIN',
            ])
            ->allowMultipleChoices()
            ->renderAsBadges();
        yield TextField::new('plainPassword', 'Mot de passe')
            ->setFormType(\Symfony\Component\Form\Extension\Core\Type\PasswordType::class)
            ->onlyOnForms()
            ->setRequired(false)
            ->setHelp('Laisser vide pour ne pas changer le mot de passe existant.');
        yield BooleanField::new('enabled', 'Actif');
        yield AssociationField::new('sites')
            ->setHelp('Sites que cet utilisateur peut administrer (ignoré pour un super administrateur, qui voit tous les sites).');
        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('lastLoginAt')->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->hashPasswordIfProvided($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->hashPasswordIfProvided($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function hashPasswordIfProvided(User $user): void
    {
        if ($user->getPlainPassword() !== null && $user->getPlainPassword() !== '') {
            $user->setPassword($this->passwordHasher->hashPassword($user, $user->getPlainPassword()));
            $user->setPlainPassword(null);
        }
    }
}
