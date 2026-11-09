<?php

namespace App\Site\SeminaireCAC\Controller;

use App\Site\AbstractRegistrationTrackingController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationTrackingController extends AbstractRegistrationTrackingController
{
    #[Route('/inscription/suivi', name: 'registration_tracking', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        return $this->record($request, 'cac');
    }
}
