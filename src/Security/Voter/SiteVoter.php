<?php

namespace App\Security\Voter;

use App\Entity\Site;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Contrôle d'accès à un Site donné. C'est la garantie serveur (pas juste un
 * masquage de bouton côté EasyAdmin) qu'un utilisateur ne peut jamais agir
 * sur un événement qui ne lui a pas été explicitement attribué.
 */
final class SiteVoter extends Voter
{
    public const string ACCESS = 'SITE_ACCESS';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ACCESS && $subject instanceof Site;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Site $site */
        $site = $subject;

        return $user->hasAccessToSite($site);
    }
}
