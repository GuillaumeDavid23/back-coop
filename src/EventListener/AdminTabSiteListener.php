<?php

namespace App\EventListener;

use App\Service\Site\SiteContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Inscrit le site courant dans l'URL de chaque page du BO : c'est ce qui rend
 * chaque onglet indépendant (voir SiteContext). Écouté après le pare-feu
 * (priorité 8), l'accès au site devant être vérifié pour un utilisateur connu.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
final class AdminTabSiteListener
{
    public function __construct(private readonly SiteContext $siteContext)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (
            !$event->isMainRequest()
            || !$request->isMethod('GET')
            || $request->isXmlHttpRequest()
            || $request->query->has(SiteContext::QUERY_PARAMETER)
            || !$this->isSitePage($request->getPathInfo(), $request->query->all())
        ) {
            return;
        }

        $site = $this->siteContext->getCurrentSite();
        if (null === $site) {
            return;
        }

        $uri = $request->getUri();
        $event->setResponse(new RedirectResponse(
            $uri.(str_contains($uri, '?') ? '&' : '?').SiteContext::QUERY_PARAMETER.'='.$site->getId(),
        ));
    }

    /** @param array<string, mixed> $query */
    private function isSitePage(string $path, array $query): bool
    {
        if (str_starts_with($path, '/admin/select-site/')) {
            return false;
        }

        // /admin seul est le sélecteur de site ; /admin?routeName=… est une page.
        if ('/admin' === rtrim($path, '/')) {
            return isset($query['routeName']) || isset($query['crudControllerFqcn']);
        }

        return str_starts_with($path, '/admin/');
    }
}
