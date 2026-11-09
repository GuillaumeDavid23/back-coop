<?php

namespace App\Service\Site;

use App\Entity\Site;
use App\Repository\SiteRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Le "site courant" du BO appartient à chaque onglet : il voyage dans l'URL
 * (?_site=ID), et une requête sans ce paramètre (lien du menu, recherche,
 * redirection après un enregistrement) le reprend de la page d'origine via le
 * Referer. La session ne sert que de valeur de départ, pour un onglet ouvert
 * sans contexte ; elle retient le dernier site choisi, tous onglets confondus.
 *
 * Un identifiant venant de l'URL se modifie à la main : l'accès au site y est
 * toujours revérifié, et un site interdit équivaut à aucun site.
 */
final class SiteContext
{
    public const string QUERY_PARAMETER = '_site';

    private const string SESSION_KEY = 'admin_current_site_id';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SiteRepository $sites,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function setCurrentSite(Site $site): void
    {
        $this->requestStack->getSession()->set(self::SESSION_KEY, $site->getId());
    }

    public function getCurrentSite(): ?Site
    {
        $tabSiteId = $this->tabSiteId();
        if (null !== $tabSiteId) {
            $site = $this->sites->find($tabSiteId);

            return null !== $site && $this->authorizationChecker->isGranted('SITE_ACCESS', $site) ? $site : null;
        }

        $id = $this->requestStack->getSession()->get(self::SESSION_KEY);

        return $id ? $this->sites->find($id) : null;
    }

    public function clear(): void
    {
        $this->requestStack->getSession()->remove(self::SESSION_KEY);
    }

    private function tabSiteId(): ?int
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return null;
        }

        return self::parseId($request->query->get(self::QUERY_PARAMETER)) ?? $this->refererSiteId($request);
    }

    private function refererSiteId(Request $request): ?int
    {
        $referer = (string) $request->headers->get('Referer', '');
        if ('' === $referer || parse_url($referer, \PHP_URL_HOST) !== $request->getHost()) {
            return null;
        }

        parse_str((string) parse_url($referer, \PHP_URL_QUERY), $query);

        return self::parseId($query[self::QUERY_PARAMETER] ?? null);
    }

    private static function parseId(mixed $value): ?int
    {
        return \is_string($value) && ctype_digit($value) && '0' !== $value ? (int) $value : null;
    }
}
