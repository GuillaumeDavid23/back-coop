<?php

namespace App\Site\SeminaireCAC\Service;

use App\Repository\SiteRepository;

/**
 * Ouverture des inscriptions au Séminaire CAC : fermées à l'heure limite
 * demandée par Lola BOYER (mail du 19/11/2026 : « demain à 10h00 »), ou plus
 * tôt si « Paiements ouverts » est décoché sur la fiche Site.
 */
final class RegistrationGate
{
    public const string CLOSING_AT = '2026-11-20 10:00:00 Europe/Paris';

    private ?bool $open = null;

    public function __construct(private readonly SiteRepository $sites)
    {
    }

    public function isOpen(): bool
    {
        if (new \DateTimeImmutable() >= new \DateTimeImmutable(self::CLOSING_AT)) {
            return false;
        }

        return $this->open ??= $this->sites->findByCode('seminaire_cac')?->isPaymentsOpen() ?? false;
    }
}
