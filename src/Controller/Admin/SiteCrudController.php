<?php

namespace App\Controller\Admin;

use App\Entity\Site;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** Réservé à ROLE_SUPER_ADMIN (voir DashboardController::configureMenuItems). */
final class SiteCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Site::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Site')
            ->setEntityLabelInPlural('Sites')
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('name', 'Nom');
        yield TextField::new('code')->setHelp('Identifiant technique, ex: seminaire_cac');
        yield TextField::new('domain', 'Domaine')->setHelp('ex: seminaire-cac.clcomevents.fr');
        yield BooleanField::new('enabled', 'Actif');
        yield BooleanField::new('invoicingEnabled', 'Facturation')
            ->setHelp('Décocher pour les sites encaissés sans émission de facture (la confirmation part sans pièce jointe)');
        yield TextField::new('invoicePrefix', 'Préfixe facture')->hideOnIndex();
        yield TextField::new('invoiceSuffix', 'Suffixe facture')->hideOnIndex();
        yield TextField::new('creditNotePrefix', 'Préfixe avoir')->hideOnIndex();
        yield TextField::new('creditNoteSuffix', 'Suffixe avoir')->hideOnIndex();
        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('updatedAt')->hideOnForm();
    }
}
