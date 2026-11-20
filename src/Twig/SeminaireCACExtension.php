<?php

namespace App\Twig;

use App\Site\SeminaireCAC\Service\RegistrationGate;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Expose aux gabarits du Séminaire CAC l'ouverture des inscriptions. */
final class SeminaireCACExtension extends AbstractExtension
{
    public function __construct(private readonly RegistrationGate $gate)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('cac_registration_open', $this->gate->isOpen(...))];
    }
}
