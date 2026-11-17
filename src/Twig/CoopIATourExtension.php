<?php

namespace App\Twig;

use App\Repository\SiteRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose aux gabarits de La Coop' IA Tour l'interrupteur « Paiements ouverts »
 * de la fiche Site : fermé, le site retire ses boutons d'inscription et affiche
 * l'annonce du report, au lieu de ne bloquer qu'au récapitulatif.
 */
final class CoopIATourExtension extends AbstractExtension
{
    private const string SITE_CODE = 'coop_ia_tour';

    private ?bool $open = null;

    public function __construct(private readonly SiteRepository $sites)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('iatour_registration_open', $this->isRegistrationOpen(...))];
    }

    /** Fermé si la fiche manque, comme dans RegistrationController. */
    public function isRegistrationOpen(): bool
    {
        return $this->open ??= $this->sites->findByCode(self::SITE_CODE)?->isPaymentsOpen() ?? false;
    }
}
