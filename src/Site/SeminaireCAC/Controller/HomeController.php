<?php

namespace App\Site\SeminaireCAC\Controller;

use App\Site\SeminaireCAC\Service\FareCatalog;
use App\Site\SeminaireCAC\Service\ProgrammeCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(): Response
    {
        return $this->render('sites/seminaire_cac/home.html.twig', [
            'fares' => FareCatalog::all(),
            'day1' => ProgrammeCatalog::day1(),
            'day2' => ProgrammeCatalog::day2(),
        ]);
    }
}
