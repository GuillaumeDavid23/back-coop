<?php

namespace App\Site\SeminaireIA\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lien court et stable vers la plaquette, que l'agence glisse dans ses
 * communications : le PDF s'ouvre dans le navigateur au lieu d'être
 * téléchargé, et le lien reste valable quand la plaquette est remplacée.
 */
final class ProgrammeController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public/documents/sites/seminaire_ia/plaquette-seminaire-ia-2026.pdf')]
        private readonly string $brochurePath,
    ) {
    }

    #[Route('/programme', name: 'programme', methods: ['GET', 'HEAD'])]
    public function show(): BinaryFileResponse
    {
        if (!is_file($this->brochurePath)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($this->brochurePath, autoEtag: true, autoLastModified: true);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'Programme-Seminaire-IA-2026.pdf');

        return $response;
    }
}
