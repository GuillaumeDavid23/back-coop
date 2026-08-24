<?php

namespace App\Controller\Admin;

use App\Entity\Participant;
use App\Entity\Registration;
use App\Entity\Site;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class ParticipantCrudController extends AbstractSiteScopedCrudController
{
    public static function getEntityFqcn(): string
    {
        return Participant::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Participant')
            ->setEntityLabelInPlural('Participants')
            ->setDefaultSort(['lastName' => 'ASC']);
    }

    /** Pas de suppression physique : une désinscription passe par Registration (voir RegistrationCrudController::unregister). */
    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::DELETE, Action::NEW);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('civility')->hideOnIndex();
        yield TextField::new('firstName', 'Prénom');
        yield TextField::new('lastName', 'Nom');
        yield EmailField::new('email');
        yield TextField::new('phone', 'Téléphone')->hideOnIndex();
        yield TextField::new('company', 'Société / Cabinet');
        yield TextField::new('status', 'Statut')->hideOnIndex();
        yield TextareaField::new('motivation')->hideOnIndex();
        yield TextareaField::new('specialNeeds', 'Besoins spécifiques')->hideOnIndex();
        yield AssociationField::new('registration')->hideOnForm();
    }

    /** Participant n'a pas de colonne "site" directe : filtrage via sa Registration. */
    protected function applySiteFilter(QueryBuilder $qb, Site $site): void
    {
        $rootAlias = $qb->getRootAliases()[0];
        $qb->innerJoin(Registration::class, 'reg', 'WITH', "$rootAlias.registration = reg")
            ->andWhere('reg.site = :scoped_site')
            ->setParameter('scoped_site', $site);
    }
}
