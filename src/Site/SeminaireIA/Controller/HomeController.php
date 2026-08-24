<?php

namespace App\Site\SeminaireIA\Controller;

use App\Site\SeminaireIA\Service\FareCatalog;
use App\Site\SeminaireIA\Service\ProgrammeCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(): Response
    {
        return $this->render('sites/seminaire_ia/home.html.twig', [
            'fares' => FareCatalog::all(),
            'statuses' => FareCatalog::statuses(),
            'eveningPrices' => FareCatalog::eveningPrices(),
            'day1' => ProgrammeCatalog::day1(),
            'day2' => ProgrammeCatalog::day2(),
        ]);
    }
}
