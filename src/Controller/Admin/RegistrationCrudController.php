<?php

namespace App\Controller\Admin;

use App\Entity\Registration;
use App\Entity\RegistrationStatus;
use App\Form\ParticipantType;
use App\Repository\CreditNoteRepository;
use App\Repository\InvoiceRepository;
use App\Repository\RegistrationRepository;
use App\Service\Billing\BillingDocumentProvider;
use App\Service\Billing\RegistrationCancellationService;
use App\Service\Export\AnswerHumanizer;
use App\Service\Stripe\PaymentSynchronizer;
use App\Service\Stripe\StripeCheckoutService;
use Doctrine\ORM\QueryBuilder;
use Stripe\Exception\ApiErrorException;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use App\Service\Site\SiteContext;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class RegistrationCrudController extends AbstractSiteScopedCrudController
{
    public function __construct(
        SiteContext $siteContext,
        AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly RegistrationCancellationService $cancellationService,
        private readonly InvoiceRepository $invoices,
        private readonly RegistrationRepository $registrations,
        private readonly CreditNoteRepository $creditNotes,
        private readonly BillingDocumentProvider $documents,
        private readonly StripeCheckoutService $stripeCheckout,
        private readonly PaymentSynchronizer $paymentSynchronizer,
    ) {
        parent::__construct($siteContext, $adminUrlGenerator);
    }

    public static function getEntityFqcn(): string
    {
        return Registration::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Inscription')
            ->setEntityLabelInPlural('Inscriptions')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPaginatorPageSize(30)
            ->overrideTemplate('crud/index', 'admin/registration_index.html.twig')
            ->overrideTemplate('crud/detail', 'admin/registration_detail.html.twig')
            ->setSearchFields(['id', 'fareLabel', 'participants.firstName', 'participants.lastName', 'participants.email', 'participants.company'])
            // Un clic sur une ligne doit ouvrir la vue détail (infos participant +
            // actions Facture/Avoir/Désinscrire), pas le formulaire de modification.
            ->setDefaultRowAction(Action::DETAIL);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', "Statut d'inscription")
                ->setChoices([
                    'Inscrit (confirmé)' => RegistrationStatus::CONFIRMED->value,
                    'En attente de paiement' => RegistrationStatus::PENDING->value,
                    'Désinscrit' => RegistrationStatus::CANCELLED->value,
                ])
                ->canSelectMultiple())
            ->add(TextFilter::new('fareLabel', 'Forfait'))
            ->add(DateTimeFilter::new('createdAt', 'Date d\'inscription'));
    }

    /**
     * Par défaut le tableau ne montre que les inscrits (confirmés + en attente) :
     * les désinscriptions restent consultables mais ne polluent pas la liste de
     * travail. Dès que l'utilisateur choisit lui-même un statut dans les filtres,
     * on le laisse totalement maître de ce qu'il voit.
     */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        if (!\array_key_exists('status', $searchDto->getAppliedFilters() ?? [])) {
            $qb->andWhere(sprintf('%s.status != :not_cancelled', $qb->getRootAliases()[0]))
                ->setParameter('not_cancelled', RegistrationStatus::CANCELLED);
        }

        return $qb;
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        $site = $this->siteContext->getCurrentSite();
        $pageName = $responseParameters->get('pageName');

        if (Crud::PAGE_INDEX === $pageName && $site !== null) {
            $responseParameters->set('registration_stats', $this->registrations->getStats($site));
        }

        if (Crud::PAGE_DETAIL === $pageName) {
            /** @var Registration $registration */
            $registration = $responseParameters->get('entity')->getInstance();
            $responseParameters->set('invoice_number', $this->invoices->findOneBy(['registration' => $registration])?->getNumber());
            $responseParameters->set('credit_note_number', $this->creditNotes->findOneByRegistration($registration)?->getNumber());
        }

        return $responseParameters;
    }

    public function configureActions(Actions $actions): Actions
    {
        $downloadInvoice = Action::new('downloadInvoice', 'Facture', 'fa fa-file-invoice')
            ->linkToCrudAction('downloadInvoice')
            ->displayIf(fn (Registration $registration) => $this->invoices->findOneBy(['registration' => $registration]) !== null);

        // Le PDF est généré à la demande s'il manque (voir BillingDocumentProvider),
        // donc il suffit que l'avoir existe pour proposer le téléchargement.
        $downloadCreditNote = Action::new('downloadCreditNote', 'Avoir', 'fa fa-file-invoice-dollar')
            ->linkToCrudAction('downloadCreditNote')
            ->displayIf(fn (Registration $registration) => $this->creditNotes->findOneByRegistration($registration) !== null);

        // Une inscription en attente de paiement n'a rien à annuler ni à créditer :
        // l'action n'apparaît que sur une inscription confirmée (voir
        // RegistrationCancellationService::canCancel).
        $unregister = Action::new('unregister', 'Désinscrire', 'fa fa-user-slash')
            ->linkToCrudAction('unregister')
            ->displayIf(fn (Registration $registration) => $this->cancellationService->canCancel($registration))
            ->askConfirmation(
                'Confirmer la désinscription ?',
                'Désinscrire',
            )
            ->setHtmlAttributes([
                'data-action-confirmation-content' => "Cette action annule l'inscription et génère automatiquement un avoir si une facture existait. Aucun remboursement Stripe n'est effectué automatiquement : si un remboursement est dû, il doit être fait manuellement depuis le dashboard Stripe.",
            ])
            ->addCssClass('text-danger');

        // Rattrapage manuel quand un webhook Stripe s'est perdu : on relit l'état
        // réel de la session Checkout côté Stripe (voir PaymentSynchronizer).
        $refreshPayment = Action::new('refreshPayment', 'Rafraîchir le paiement', 'fa fa-rotate')
            ->linkToCrudAction('refreshPayment')
            ->displayIf(static fn (Registration $registration) => $registration->getStatus() === RegistrationStatus::PENDING);

        $exportParticipants = Action::new('exportParticipants', 'Exporter les participants (Excel)', 'fa fa-file-excel')
            ->linkToUrl(fn () => $this->generateUrl('admin_export_participants'))
            ->createAsGlobalAction();

        return $actions
            // Le tarif/forfait et le statut ne doivent changer que via le webhook Stripe
            // ou la désinscription contrôlée (voir RegistrationCancellationService) —
            // jamais par édition libre (voir configureFields : ces champs sont masqués
            // du formulaire, seuls les participants restent éditables).
            ->disable(Action::DELETE, Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $downloadInvoice)
            ->add(Crud::PAGE_INDEX, $downloadCreditNote)
            ->add(Crud::PAGE_INDEX, $refreshPayment)
            ->add(Crud::PAGE_INDEX, $unregister)
            ->add(Crud::PAGE_DETAIL, $refreshPayment)
            ->add(Crud::PAGE_DETAIL, $downloadInvoice)
            ->add(Crud::PAGE_DETAIL, $downloadCreditNote)
            ->add(Crud::PAGE_DETAIL, $unregister)
            ->add(Crud::PAGE_INDEX, $exportParticipants)
            ->setPermission(Action::EDIT, 'ROLE_ADMIN');
    }

    #[AdminRoute]
    public function downloadInvoice(AdminContext $context): Response
    {
        /** @var Registration $registration */
        $registration = $context->getEntity()->getInstance();
        $this->denyAccessUnlessGranted('SITE_ACCESS', $registration->getSite());

        $invoice = $this->invoices->findOneBy(['registration' => $registration]);
        if ($invoice === null) {
            throw $this->createNotFoundException('Aucune facture pour cette inscription.');
        }

        $response = new BinaryFileResponse($this->documents->invoicePath($invoice));
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $invoice->getNumber().'.pdf');

        return $response;
    }

    #[AdminRoute]
    public function downloadCreditNote(AdminContext $context): Response
    {
        /** @var Registration $registration */
        $registration = $context->getEntity()->getInstance();
        $this->denyAccessUnlessGranted('SITE_ACCESS', $registration->getSite());

        $creditNote = $this->creditNotes->findOneByRegistration($registration);
        if ($creditNote === null) {
            throw $this->createNotFoundException('Aucun avoir pour cette inscription.');
        }

        $response = new BinaryFileResponse($this->documents->creditNotePath($creditNote));
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $creditNote->getNumber().'.pdf');

        return $response;
    }

    #[AdminRoute]
    public function refreshPayment(AdminContext $context): Response
    {
        /** @var Registration $registration */
        $registration = $context->getEntity()->getInstance();
        $this->denyAccessUnlessGranted('SITE_ACCESS', $registration->getSite());

        $back = $this->redirect($context->getRequest()->query->get('referrer') ?: $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl());

        $payment = $registration->getLatestPayment();
        $sessionId = $payment?->getStripeCheckoutSessionId();

        if ($sessionId === null) {
            $this->addFlash('warning', "Aucune session de paiement Stripe n'est associée à cette inscription : impossible de rafraîchir son statut.");

            return $back;
        }

        try {
            $session = $this->stripeCheckout->retrieveCheckoutSession($sessionId);
        } catch (ApiErrorException $e) {
            $this->addFlash('danger', sprintf('Stripe n\'a pas pu être interrogé : %s', $e->getMessage()));

            return $back;
        }

        $statusBefore = $payment->getStatus();
        $this->paymentSynchronizer->syncFromSession($session);
        $statusAfter = $registration->getStatus();

        if ($statusAfter === RegistrationStatus::CONFIRMED) {
            $this->addFlash('success', 'Paiement confirmé auprès de Stripe : inscription confirmée, la facture est en cours de génération.');
        } elseif ($payment->getStatus() !== $statusBefore) {
            $this->addFlash('warning', sprintf('Statut du paiement mis à jour : %s.', $registration->getLatestPaymentStatusLabel()));
        } else {
            $this->addFlash('info', sprintf(
                'Aucun changement : le paiement est toujours en attente côté Stripe (session "%s").',
                $session->status ?? 'inconnue',
            ));
        }

        return $back;
    }

    #[AdminRoute]
    public function unregister(AdminContext $context): Response
    {
        /** @var Registration $registration */
        $registration = $context->getEntity()->getInstance();
        $this->denyAccessUnlessGranted('SITE_ACCESS', $registration->getSite());

        // Garde-fou serveur : l'action est déjà masquée pour ces cas (voir
        // configureActions), mais une URL forgée ne doit pas passer outre.
        if (!$this->cancellationService->canCancel($registration)) {
            $this->addFlash('warning', $registration->getStatus() === RegistrationStatus::CANCELLED
                ? 'Cette inscription est déjà désinscrite.'
                : "Cette inscription n'est pas confirmée (paiement en attente) : il n'y a rien à désinscrire ni à créditer.");

            return $this->redirect($this->adminUrlGenerator
                ->setController(self::class)
                ->setAction(Action::INDEX)
                ->generateUrl());
        }

        $creditNote = $this->cancellationService->cancel($registration);

        $this->addFlash('success', $creditNote !== null
            ? sprintf(
                "Inscription désinscrite. Avoir %s généré (%s €) — pensez à effectuer le remboursement Stripe manuellement si besoin, il n'est jamais automatique.",
                $creditNote->getNumber(),
                $creditNote->getAmount(),
            )
            : 'Inscription désinscrite (aucune facture associée, pas d\'avoir à générer).');

        return $this->redirect($this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl());
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();

        if (Crud::PAGE_EDIT === $pageName) {
            // Édition complète des informations des participants directement depuis
            // l'inscription — mais jamais du tarif/forfait/statut (voir plus bas).
            yield CollectionField::new('participants', 'Participant(s)')
                ->setEntryType(ParticipantType::class)
                ->setFormTypeOptions(['by_reference' => false])
                ->allowAdd(false)
                ->allowDelete(false);
        } elseif (Crud::PAGE_DETAIL === $pageName) {
            // Vue détail : toutes les infos du participant (une inscription n'en a
            // qu'un seul en pratique, voir Registration::getPrimaryParticipant).
            yield TextField::new('primaryParticipant.civility', 'Civilité')
                ->formatValue(static fn ($value) => AnswerHumanizer::civility($value));
            yield TextField::new('participantsFullNames', 'Participant(s)');
            yield TextField::new('participantsEmails', 'Email');
            yield TextField::new('primaryParticipant.phone', 'Téléphone');
            yield TextField::new('primaryParticipant.company', 'Société / Cabinet');
            yield TextField::new('primaryParticipant.status', 'Statut')
                ->formatValue(static fn ($value) => AnswerHumanizer::participantStatus($value));
            yield TextField::new('primaryParticipant.address', 'Adresse');
            yield TextField::new('primaryParticipant.postalCode', 'Code postal');
            yield TextField::new('primaryParticipant.city', 'Ville');
            yield TextareaField::new('primaryParticipant.motivation', 'Motif');
            yield TextareaField::new('primaryParticipant.specialNeeds', 'Besoins spécifiques');
        } elseif (Crud::PAGE_INDEX === $pageName) {
            yield TextField::new('participantsFullNames', 'Participant(s)')->hideOnForm();
            yield TextField::new('participantsEmails', 'Email')->hideOnForm();
        }

        yield TextField::new('fareLabel', 'Forfait')->hideOnForm();
        yield MoneyField::new('amountInclTax', 'Montant TTC')->setCurrency('EUR')->setStoredAsCents(false)->hideOnForm();
        // Libellé accordé au genre du participant (voir Registration::getGenderedStatusLabel) —
        // un ChoiceField ne peut pas produire un libellé dynamique par ligne, d'où ce badge HTML.
        yield TextField::new('genderedStatusLabel', 'Inscription')
            ->formatValue(static fn ($value, Registration $registration) => sprintf(
                '<span class="badge badge-%s">%s</span>',
                $registration->getStatusBadgeVariant(),
                $registration->getGenderedStatusLabel(),
            ))
            ->renderAsHtml()
            ->hideOnForm();
        yield TextField::new('latestPaymentStatusLabel', 'Paiement')->hideOnForm();
        yield DateTimeField::new('createdAt', 'Créée le')->hideOnForm();

        if (Crud::PAGE_DETAIL === $pageName) {
            // Une inscription = un paiement = un participant : on peut donc afficher
            // à plat le paiement et les documents de facturation liés.
            yield MoneyField::new('latestPayment.amount', 'Montant payé')
                ->setCurrency('EUR')->setStoredAsCents(false);
            yield DateTimeField::new('latestPayment.paidAt', 'Payé le');
            yield TextField::new('latestPayment.stripeCheckoutSessionId', 'Session Stripe');
            yield TextField::new('latestPayment.stripePaymentIntentId', 'Paiement Stripe');
            // Les numéros de facture/avoir sont ajoutés par le template de détail
            // (voir configureResponseParameters) : un champ ne peut pas être défini
            // deux fois sur la même propriété "id" sans écraser l'IdField.
        }
    }
}
