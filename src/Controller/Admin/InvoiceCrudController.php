<?php

namespace App\Controller\Admin;

use App\Entity\Invoice;
use App\Entity\RegistrationStatus;
use App\Repository\InvoiceRepository;
use App\Service\Billing\BillingDocumentProvider;
use App\Service\Billing\RegistrationCancellationService;
use App\Service\Export\PdfZipDownloader;
use App\Service\Site\SiteContext;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Lecture seule : une facture émise ne doit jamais être modifiée après coup (voir NumberingService). */
final class InvoiceCrudController extends AbstractSiteScopedCrudController
{
    public function __construct(
        SiteContext $siteContext,
        AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly InvoiceRepository $invoices,
        private readonly BillingDocumentProvider $documents,
        private readonly RegistrationCancellationService $cancellationService,
        private readonly PdfZipDownloader $zipDownloader,
    ) {
        parent::__construct($siteContext, $adminUrlGenerator);
    }

    public static function getEntityFqcn(): string
    {
        return Invoice::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Facture')
            ->setEntityLabelInPlural('Factures')
            ->setDefaultSort(['issuedAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        // "Exporter tout" et la sélection par case à cocher téléchargent les PDF
        // en masse (ZIP) — ce ne sont pas des exports Excel.
        $downloadAll = Action::new('downloadAll', 'Télécharger tout (ZIP)', 'fa fa-file-zipper')
            ->linkToCrudAction('downloadAll')
            ->createAsGlobalAction();

        $downloadSelected = Action::new('downloadSelected', 'Télécharger la sélection (ZIP)', 'fa fa-file-zipper')
            ->linkToCrudAction('downloadSelected')
            ->createAsBatchAction();

        $download = Action::new('download', 'Télécharger', 'fa fa-download')
            ->linkToRoute('admin_invoice_download', static fn (Invoice $invoice) => ['id' => $invoice->getId()]);

        $convertToCreditNote = Action::new('convertToCreditNote', 'Passer en avoir', 'fa fa-file-invoice-dollar')
            ->linkToCrudAction('convertToCreditNote')
            ->displayIf(static fn (Invoice $invoice) => $invoice->getRegistration()->getStatus() !== RegistrationStatus::CANCELLED)
            ->askConfirmation(
                "Confirmer le passage en avoir de cette facture ?",
                'Passer en avoir',
            )
            ->setHtmlAttributes([
                'data-action-confirmation-content' => "Cette action annule l'inscription liée et génère un avoir. Aucun remboursement Stripe n'est effectué automatiquement : si un remboursement est dû, il doit être fait manuellement depuis le dashboard Stripe.",
            ])
            ->addCssClass('text-danger');

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, $downloadAll)
            ->add(Crud::PAGE_INDEX, $download)
            ->add(Crud::PAGE_DETAIL, $download)
            ->add(Crud::PAGE_INDEX, $convertToCreditNote)
            ->add(Crud::PAGE_DETAIL, $convertToCreditNote)
            ->addBatchAction($downloadSelected);
    }

    #[Route('/admin/invoice/{id}/download', name: 'admin_invoice_download')]
    public function download(int $id): Response
    {
        $invoice = $this->invoices->find($id);
        if ($invoice === null) {
            throw $this->createNotFoundException('Facture introuvable.');
        }

        $this->denyAccessUnlessGranted('SITE_ACCESS', $invoice->getSite());

        $response = new BinaryFileResponse($this->documents->invoicePath($invoice));
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $invoice->getNumber().'.pdf');

        return $response;
    }

    #[AdminRoute]
    public function convertToCreditNote(AdminContext $context): Response
    {
        /** @var Invoice $invoice */
        $invoice = $context->getEntity()->getInstance();
        $this->denyAccessUnlessGranted('SITE_ACCESS', $invoice->getSite());

        $creditNote = $this->cancellationService->cancel($invoice->getRegistration());

        $this->addFlash('success', $creditNote !== null
            ? sprintf(
                "Facture %s passée en avoir. Avoir %s généré (%s €) — pensez à effectuer le remboursement Stripe manuellement si besoin, il n'est jamais automatique.",
                $invoice->getNumber(),
                $creditNote->getNumber(),
                $creditNote->getAmount(),
            )
            : "Inscription déjà annulée, aucun avoir supplémentaire n'a été généré.");

        return $this->redirect($this->adminUrlGenerator
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl());
    }

    #[AdminRoute]
    public function downloadAll(): Response
    {
        $site = $this->siteContext->getCurrentSite();
        if ($site === null) {
            return $this->redirect($this->adminUrlGenerator->setController(DashboardController::class)->generateUrl());
        }
        $this->denyAccessUnlessGranted('SITE_ACCESS', $site);

        return $this->downloadAsZip($this->invoices->findBy(['site' => $site], ['issuedAt' => 'DESC']), $site->getCode());
    }

    #[AdminRoute]
    public function downloadSelected(BatchActionDto $batchActionDto): Response
    {
        $site = $this->siteContext->getCurrentSite();
        if ($site === null) {
            return $this->redirect($this->adminUrlGenerator->setController(DashboardController::class)->generateUrl());
        }
        $this->denyAccessUnlessGranted('SITE_ACCESS', $site);

        $invoices = array_filter(
            array_map(fn ($id) => $this->invoices->find($id), $batchActionDto->getEntityIds()),
            fn (?Invoice $invoice) => $invoice !== null && $invoice->getSite()->getId() === $site->getId(),
        );

        return $this->downloadAsZip($invoices, $site->getCode());
    }

    /** @param iterable<Invoice> $invoices */
    private function downloadAsZip(iterable $invoices, string $siteCode): Response
    {
        $files = [];
        foreach ($invoices as $invoice) {
            $files[$invoice->getNumber().'.pdf'] = $this->documents->invoicePath($invoice);
        }

        if ($files === []) {
            $this->addFlash('warning', 'Aucun PDF disponible.');

            return $this->redirect($this->adminUrlGenerator->setController(self::class)->generateUrl());
        }

        return $this->zipDownloader->toResponse(
            $files,
            sprintf('factures_%s_%s.zip', $siteCode, (new \DateTimeImmutable())->format('Y-m-d')),
        );
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('number', 'Numéro');
        yield AssociationField::new('registration', 'Inscription')->hideOnForm();
        // Tri désactivé : ce libellé est reconstitué à partir des participants
        // et ne correspond à aucune colonne triable en base.
        yield TextField::new('registration.participantsFullNames', 'Participant(s)')
            ->setSortable(false)
            ->hideOnForm();
        yield MoneyField::new('amountInclTax', 'Montant TTC')->setCurrency('EUR')->setStoredAsCents(false);
        yield DateTimeField::new('issuedAt', 'Émise le');
        yield TextField::new('pdfPath', 'PDF')->hideOnIndex();
    }
}
