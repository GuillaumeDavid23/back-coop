<?php

namespace App\Controller\Admin;

use App\Entity\Registration;
use App\Entity\RegistrationStatus;
use App\Entity\Site;
use App\Repository\SiteRepository;
use App\Service\Export\ParticipantExportBuilder;
use App\Service\Export\SpreadsheetExporter;
use App\Service\Site\SiteContext;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly SiteContext $siteContext,
        private readonly EntityManagerInterface $em,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly ParticipantExportBuilder $participantExportBuilder,
        private readonly SpreadsheetExporter $exporter,
        private readonly Packages $assetPackages,
    ) {
    }

    public function index(): Response
    {
        $user = $this->getUser();
        $accessibleSites = $this->sites->findAccessibleToUser($user);

        return $this->render('admin/choose_site.html.twig', [
            'sites' => $accessibleSites,
            'isSuperAdmin' => $user->isSuperAdmin(),
        ]);
    }

    #[Route('/admin/select-site/{id}', name: 'admin_site_context')]
    public function selectSite(Site $site): Response
    {
        $this->denyAccessUnlessGranted('SITE_ACCESS', $site);
        $this->siteContext->setCurrentSite($site);

        // Redirection via AdminUrlGenerator (pas redirectToRoute) : c'est ce
        // qui fait passer la requête par l'indirection "/admin?routeName=..."
        // d'EasyAdmin, seule façon d'obtenir le layout (menu/sidebar) sur une
        // route Symfony classique — voir AdminRouterSubscriber, qui ignore
        // toute route qui n'a pas été générée par son propre chargeur.
        return $this->redirect($this->adminUrlGenerator->setController(RegistrationCrudController::class)->generateUrl());
    }

    #[Route('/admin/export/participants', name: 'admin_export_participants')]
    public function exportParticipants(): Response
    {
        $site = $this->siteContext->getCurrentSite();
        if ($site === null) {
            return $this->redirectToRoute('admin');
        }
        $this->denyAccessUnlessGranted('SITE_ACCESS', $site);

        // Uniquement les inscriptions payées : l'export sert de liste de
        // présence et de base de facturation, une inscription en attente de
        // paiement ou désinscrite n'y a pas sa place.
        $registrations = $this->em->createQueryBuilder()
            ->select('r', 'p')
            ->from(Registration::class, 'r')
            ->leftJoin('r.participants', 'p')->addSelect('p')
            ->andWhere('r.site = :site')->setParameter('site', $site)
            ->andWhere('r.status = :confirmed')->setParameter('confirmed', RegistrationStatus::CONFIRMED)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $sheets = $this->participantExportBuilder->build($site, $registrations);

        return $this->exporter->toResponse(
            $this->exporter->build($sheets),
            sprintf('participants_%s_%s.xlsx', $site->getCode(), (new \DateTimeImmutable())->format('Y-m-d')),
        );
    }

    public function configureDashboard(): Dashboard
    {
        $logoUrl = $this->assetPackages->getUrl('images/logo.png');

        return Dashboard::new()
            ->setTitle(sprintf('<img src="%s" alt="CLCOM Academy" style="max-height:36px;">', $logoUrl))
            ->setLocales(['fr']);
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Choisir un site', 'fa fa-th-large');

        $currentSite = $this->siteContext->getCurrentSite();

        if ($currentSite !== null) {
            yield MenuItem::section($currentSite->getName());
            yield MenuItem::linkTo(RegistrationCrudController::class, 'Inscriptions', 'fa fa-clipboard-list');
            yield MenuItem::linkTo(InvoiceCrudController::class, 'Factures', 'fa fa-file-invoice');
            yield MenuItem::linkTo(CreditNoteCrudController::class, 'Avoirs', 'fa fa-file-invoice-dollar');
        }

        if ($this->getUser()?->isSuperAdmin()) {
            yield MenuItem::section('Administration');
            yield MenuItem::linkTo(SiteCrudController::class, 'Sites', 'fa fa-globe');
            yield MenuItem::linkTo(UserCrudController::class, 'Utilisateurs', 'fa fa-user-shield');
            yield MenuItem::linkToRoute('Logs', 'fa fa-file-lines', 'admin_logs');
        }
    }
}
