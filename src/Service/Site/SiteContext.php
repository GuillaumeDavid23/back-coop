<?php

namespace App\Service\Site;

use App\Entity\Site;
use App\Repository\SiteRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Le "site courant" sélectionné dans le BO est stocké en session — c'est ce
 * qui permet aux CRUD controllers EasyAdmin de toujours filtrer leurs
 * données par site sans avoir à faire transiter l'id de site dans chaque
 * URL. Ne jamais faire confiance à cette valeur sans revérifier les droits
 * de l'utilisateur (voir SiteVoter) : la session peut contenir un site
 * choisi avant qu'un accès ne lui soit retiré entre-temps.
 */
final class SiteContext
{
    private const string SESSION_KEY = 'admin_current_site_id';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SiteRepository $sites,
    ) {
    }

    public function setCurrentSite(Site $site): void
    {
        $this->requestStack->getSession()->set(self::SESSION_KEY, $site->getId());
    }

    public function getCurrentSite(): ?Site
    {
        $id = $this->requestStack->getSession()->get(self::SESSION_KEY);

        return $id ? $this->sites->find($id) : null;
    }

    public function clear(): void
    {
        $this->requestStack->getSession()->remove(self::SESSION_KEY);
    }
}
