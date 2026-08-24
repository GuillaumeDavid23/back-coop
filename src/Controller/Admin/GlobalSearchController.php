<?php

namespace App\Controller\Admin;

use App\Service\Search\GlobalSearchService;
use App\Service\Site\SiteContext;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Alimente la barre de recherche globale du BO (voir
 * templates/bundles/EasyAdminBundle/layout.html.twig). Chaque résultat pointe
 * vers la fiche détail de l'inscription concernée, qui est le point d'entrée
 * naturel : de là on accède au participant, à la facture et à l'avoir.
 */
final class GlobalSearchController extends AbstractController
{
    public function __construct(
        private readonly GlobalSearchService $search,
        private readonly SiteContext $siteContext,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    #[Route('/admin/global-search', name: 'admin_global_search', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $site = $this->siteContext->getCurrentSite();
        if (null === $site) {
            return new JsonResponse(['results' => [], 'message' => 'Sélectionnez un site pour rechercher.']);
        }

        $this->denyAccessUnlessGranted('SITE_ACCESS', $site);

        $results = $this->search->search($site, (string) $request->query->get('q', ''));

        $payload = array_map(function (array $result): array {
            $result['url'] = $this->adminUrlGenerator
                ->setController(RegistrationCrudController::class)
                ->setAction(Action::DETAIL)
                ->setEntityId($result['entityId'])
                ->generateUrl();

            unset($result['entityId']);

            return $result;
        }, $results);

        return new JsonResponse(['results' => $payload]);
    }
}
