<?php

namespace App\Controller\Admin;

use App\Service\Log\LogEntry;
use App\Service\Log\LogQuery;
use App\Service\Log\LogReader;
use App\Service\Log\RegistrationFunnelReport;
use App\Site\CoopIATour\Form\QuestionnaireType;
use App\Site\CoopIATour\Form\RegistrationStep1Type;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Logs du BO, réservés à ROLE_SUPER_ADMIN : suivi du parcours d'inscription
 * et explorateur filtrable par fichier / niveau / canal / période / texte.
 * Évite d'avoir à ouvrir un SSH pour diagnostiquer un souci de paiement.
 */
final class LogsController extends AbstractController
{
    private const array FUNNEL_PERIODS = [
        '-24 hours' => '24 dernières heures',
        '-7 days' => '7 derniers jours',
        '-30 days' => '30 derniers jours',
        'all' => 'Tout',
    ];

    public function __construct(
        private readonly LogReader $reader,
        private readonly RegistrationFunnelReport $funnelReport,
    ) {
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
            'levels' => array_keys(LogEntry::LEVELS),
            'limits' => LogQuery::LIMITS,
        ]);
    }

    #[Route('/admin/logs/parcours', name: 'admin_logs_funnel')]
    public function funnel(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $period = (string) $request->query->get('since', LogQuery::DEFAULT_SINCE);
        if (!isset(self::FUNNEL_PERIODS[$period])) {
            $period = LogQuery::DEFAULT_SINCE;
        }

        $files = $this->reader->availableFiles();
        $log = $this->reader->entries($files['payment']['path']);

        return $this->render('admin/logs_funnel.html.twig', [
            'report' => $this->funnelReport->build(
                $log['entries'],
                'all' === $period ? null : new \DateTimeImmutable($period),
                $this->fieldLabels(),
            ),
            'truncated' => $log['truncated'],
            'periods' => self::FUNNEL_PERIODS,
            'period' => $period,
        ]);
    }

    /** @return array<string, string> libellés affichés au visiteur, par nom de champ */
    private function fieldLabels(): array
    {
        $labels = [];
        foreach ([QuestionnaireType::class, RegistrationStep1Type::class] as $type) {
            foreach ($this->createForm($type) as $name => $field) {
                $label = $field->getConfig()->getOption('label');
                if (\is_string($label)) {
                    $labels[$name] = $label;
                }
            }
        }

        return $labels;
    }
}
