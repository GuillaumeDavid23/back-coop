<?php

namespace App\Controller\Admin;

use App\Entity\Site;
use App\Service\Site\SiteContext;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base commune à tous les CRUD controllers dont les données appartiennent à
 * un Site (Registration, Participant, Payment, Invoice, CreditNote).
 * Garantit qu'une requête ne peut jamais afficher les données d'un autre
 * site que celui actuellement sélectionné dans le BO — c'est le rempart
 * structurel contre le mélange de données entre événements.
 *
 * SiteContext et AdminUrlGenerator sont injectés par le constructeur (et
 * non via $this->container->get()) car les contrôleurs EasyAdmin utilisent
 * un service locator restreint qui ignore les services applicatifs.
 */
abstract class AbstractSiteScopedCrudController extends AbstractCrudController
{
    public function __construct(
        protected readonly SiteContext $siteContext,
        protected readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $site = $this->siteContext->getCurrentSite();

        if ($site !== null) {
            $this->denyAccessUnlessGranted('SITE_ACCESS', $site);
            $this->applySiteFilter($qb, $site);
        } else {
            // Pas de site sélectionné : on ne montre jamais de données par
            // défaut, on force explicitement une condition toujours fausse.
            $qb->andWhere('1 = 0');
        }

        return $qb;
    }

    /**
     * Par défaut, l'entité porte directement une colonne "site" (cas de
     * Registration/Payment/Invoice/CreditNote). Les entités liées
     * indirectement (ex: Participant, via Registration) redéfinissent
     * cette méthode plutôt que de toucher au reste de createIndexQueryBuilder.
     */
    protected function applySiteFilter(QueryBuilder $qb, Site $site): void
    {
        $rootAlias = $qb->getRootAliases()[0];
        $qb->andWhere(sprintf('%s.site = :scoped_site', $rootAlias))
            ->setParameter('scoped_site', $site);
    }

    /**
     * Aucune de ces pages n'a de sens sans site sélectionné : la liste serait
     * vide par construction, et les gabarits attendent des données propres au
     * site (les statistiques d'inscriptions, par exemple). On renvoie donc au
     * sélecteur de site plutôt que d'afficher une page vide ou de rompre.
     */
    public function index(AdminContext $context): KeyValueStore|Response
    {
        return $this->ensureSiteSelected() ?? parent::index($context);
    }

    public function detail(AdminContext $context): KeyValueStore|Response
    {
        return $this->ensureSiteSelected() ?? parent::detail($context);
    }

    public function edit(AdminContext $context): KeyValueStore|Response
    {
        return $this->ensureSiteSelected() ?? parent::edit($context);
    }

    public function new(AdminContext $context): KeyValueStore|Response
    {
        return $this->ensureSiteSelected() ?? parent::new($context);
    }

    public function ensureSiteSelected(): ?RedirectResponse
    {
        if ($this->siteContext->getCurrentSite() === null) {
            return $this->redirect($this->adminUrlGenerator->setController(DashboardController::class)->generateUrl());
        }

        return null;
    }
}
