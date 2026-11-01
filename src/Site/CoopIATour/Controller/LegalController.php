<?php

namespace App\Site\CoopIATour\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LegalController extends AbstractController
{
    #[Route('/mentions-legales', name: 'legal_notice')]
    public function legalNotice(): Response
    {
        return $this->render('sites/coop_ia_tour/legal/mentions_legales.html.twig');
    }

    #[Route('/cgv', name: 'terms')]
    public function terms(): Response
    {
        return $this->render('sites/coop_ia_tour/legal/cgv.html.twig');
    }
}
