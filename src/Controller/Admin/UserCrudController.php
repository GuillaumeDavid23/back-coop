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
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réservé à ROLE_SUPER_ADMIN. Le contrôle est porté par l'attribut ci-dessous
 * et non par le seul menu : sans lui, tout compte disposant de ROLE_ADMIN
 * pouvait ouvrir cet écran par son URL et s'octroyer les pleins pouvoirs.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
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
                'Administrateur de tous les sites' => 'ROLE_ALL_SITES',
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
            ->setHelp('Sites que cet utilisateur peut administrer. Sans effet pour un « administrateur de tous les sites » ou un super administrateur, qui les voient tous.');
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
