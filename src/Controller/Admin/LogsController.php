<?php

namespace App\Controller\Admin;

use App\Service\Log\LogQuery;
use App\Service\Log\LogReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Explorateur de logs du BO, réservé à ROLE_SUPER_ADMIN : filtrage par
 * fichier / niveau / canal / période / texte, contexte JSON déplié à la
 * demande et raccourcis vers les objets métier cités dans le contexte.
 * Évite d'avoir à ouvrir un SSH pour diagnostiquer un souci de paiement.
 */
final class LogsController extends AbstractController
{
    public function __construct(private readonly LogReader $reader)
    {
    }

    #[Route('/admin/logs', name: 'admin_logs')]
    public function __invoke(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $files = $this->reader->availableFiles();
        $current = (string) $request->query->get('file', 'payment');
        if (!isset($files[$current])) {
            $current = array_key_first($files) ?? 'payment';
        }

        $query = LogQuery::fromRequest($request);
        $result = $this->reader->read($files[$current]['path'], $query);

        if ($request->query->getBoolean('raw')) {
            return new Response(
                implode("\n", array_map(static fn ($entry) => $entry->raw, $result['entries'])),
                Response::HTTP_OK,
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        return $this->render('admin/logs.html.twig', [
            'files' => $files,
            'currentFile' => $current,
            'query' => $query,
            'entries' => $result['entries'],
            'stats' => $result['stats'],
            'channels' => $result['channels'],
            'total' => $result['total'],
            'truncated' => $result['truncated'],
            'levels' => array_keys(\App\Service\Log\LogEntry::LEVELS),
            'limits' => LogQuery::LIMITS,
        ]);
    }
}
