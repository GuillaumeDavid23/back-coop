<?php

namespace App\Controller\Admin;

use App\Entity\CreditNote;
use App\Repository\CreditNoteRepository;
use App\Service\Billing\BillingDocumentProvider;
use App\Service\Export\PdfZipDownloader;
use App\Service\Site\SiteContext;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class CreditNoteCrudController extends AbstractSiteScopedCrudController
{
    public function __construct(
        SiteContext $siteContext,
        AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly CreditNoteRepository $creditNotes,
        private readonly PdfZipDownloader $zipDownloader,
        private readonly BillingDocumentProvider $documents,
    ) {
        parent::__construct($siteContext, $adminUrlGenerator);
    }

    public static function getEntityFqcn(): string
    {
        return CreditNote::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Avoir')
            ->setEntityLabelInPlural('Avoirs')
            ->setDefaultSort(['issuedAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        // "Exporter tout" et la sélection par case à cocher téléchargent les PDF
        // en masse (ZIP) — ce ne sont pas des exports Excel.
        $downloadAll = Action::new('downloadAll', 'Télécharger tout (ZIP)', 'fa fa-file-zipper')
            ->linkToCrudAction('downloadAll')
            ->createAsGlobalAction();

        // Le PDF est généré à la demande s'il manque (voir BillingDocumentProvider).
        $download = Action::new('download', 'Télécharger', 'fa fa-download')
            ->linkToCrudAction('download');

        $downloadSelected = Action::new('downloadSelected', 'Télécharger la sélection (ZIP)', 'fa fa-file-zipper')
            ->linkToCrudAction('downloadSelected')
            ->createAsBatchAction();

        // Les avoirs sont toujours générés automatiquement (numérotation séquentielle
        // via NumberingService, voir RegistrationCancellationService) — jamais créés
        // ni modifiés à la main, pour ne pas casser la séquence.
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, $downloadAll)
            ->add(Crud::PAGE_INDEX, $download)
            ->add(Crud::PAGE_DETAIL, $download)
            ->addBatchAction($downloadSelected);
    }

    #[AdminRoute]
    public function download(AdminContext $context): Response
    {
        /** @var CreditNote $creditNote */
        $creditNote = $context->getEntity()->getInstance();
        $this->denyAccessUnlessGranted('SITE_ACCESS', $creditNote->getSite());

        $response = new BinaryFileResponse($this->documents->creditNotePath($creditNote));
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $creditNote->getNumber().'.pdf');

        return $response;
    }

    #[AdminRoute]
    public function downloadSelected(BatchActionDto $batchActionDto): Response
    {
        $site = $this->siteContext->getCurrentSite();
        if ($site === null) {
            return $this->redirect($this->adminUrlGenerator->setController(DashboardController::class)->generateUrl());
        }
        $this->denyAccessUnlessGranted('SITE_ACCESS', $site);

        $creditNotes = array_filter(
            array_map(fn ($id) => $this->creditNotes->find($id), $batchActionDto->getEntityIds()),
            fn (?CreditNote $creditNote) => $creditNote !== null && $creditNote->getSite()->getId() === $site->getId(),
        );

        return $this->downloadAsZip($creditNotes, $site->getCode());
    }

    #[AdminRoute]
    public function downloadAll(): Response
    {
        $site = $this->siteContext->getCurrentSite();
        if ($site === null) {
            return $this->redirect($this->adminUrlGenerator->setController(DashboardController::class)->generateUrl());
        }
        $this->denyAccessUnlessGranted('SITE_ACCESS', $site);

        return $this->downloadAsZip($this->creditNotes->findBy(['site' => $site], ['issuedAt' => 'DESC']), $site->getCode());
    }

    /** @param iterable<CreditNote> $creditNotes */
    private function downloadAsZip(iterable $creditNotes, string $siteCode): Response
    {
        $files = [];
        foreach ($creditNotes as $creditNote) {
            $files[$creditNote->getNumber().'.pdf'] = $this->documents->creditNotePath($creditNote);
        }

        if ($files === []) {
            $this->addFlash('warning', 'Aucun PDF disponible.');

            return $this->redirect($this->adminUrlGenerator->setController(self::class)->generateUrl());
        }

        return $this->zipDownloader->toResponse(
            $files,
            sprintf('avoirs_%s_%s.zip', $siteCode, (new \DateTimeImmutable())->format('Y-m-d')),
        );
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('number', 'Numéro');
        yield AssociationField::new('invoice', 'Facture liée');
        // Voir InvoiceCrudController : libellé calculé, donc non triable.
        yield TextField::new('invoice.registration.participantsFullNames', 'Participant(s)')
            ->setSortable(false)
            ->hideOnForm();
        yield MoneyField::new('amount', 'Montant')->setCurrency('EUR')->setStoredAsCents(false);
        yield TextareaField::new('reason', 'Motif');
        yield DateTimeField::new('issuedAt', 'Émis le');
    }
}
