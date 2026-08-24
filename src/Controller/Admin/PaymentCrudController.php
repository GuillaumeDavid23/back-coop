<?php

namespace App\Controller\Admin;

use App\Entity\Payment;
use App\Entity\PaymentStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class PaymentCrudController extends AbstractSiteScopedCrudController
{
    public static function getEntityFqcn(): string
    {
        return Payment::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Paiement')
            ->setEntityLabelInPlural('Paiements')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPaginatorPageSize(30);
    }

    /** Pas de suppression ni de remboursement depuis ici : voir RegistrationCrudController::unregister + Stripe (manuel). */
    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::DELETE, Action::NEW, Action::EDIT);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('registration')->hideOnForm();
        yield MoneyField::new('amount')->setCurrency('EUR')->setStoredAsCents(false);
        yield ChoiceField::new('status')
            ->setChoices([
                'En attente' => PaymentStatus::PENDING,
                'Réussi' => PaymentStatus::SUCCEEDED,
                'Échoué' => PaymentStatus::FAILED,
                'Remboursé' => PaymentStatus::REFUNDED,
            ])
            ->renderAsBadges([
                PaymentStatus::PENDING->value => 'warning',
                PaymentStatus::SUCCEEDED->value => 'success',
                PaymentStatus::FAILED->value => 'danger',
                PaymentStatus::REFUNDED->value => 'secondary',
            ]);
        yield TextField::new('stripePaymentIntentId', 'Stripe PaymentIntent')->hideOnIndex();
        yield DateTimeField::new('paidAt', 'Payé le');
        yield DateTimeField::new('createdAt')->hideOnForm();
    }
}
