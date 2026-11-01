<?php

namespace App\Site\CoopIATour\Controller;

use App\Site\CoopIATour\Service\FareCatalog;
use App\Site\CoopIATour\Service\ProgrammeCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(): Response
    {
        return $this->render('sites/coop_ia_tour/home.html.twig', [
            'fares' => FareCatalog::all(),
            'day1' => ProgrammeCatalog::day1(),
            'day2' => ProgrammeCatalog::day2(),
        ]);
    }
}
