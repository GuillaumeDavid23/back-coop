<?php

namespace App\Controller\Admin;

use App\Service\Log\LogEntry;
use App\Service\Log\LogQuery;
use App\Service\Log\LogReader;
use App\Service\Log\RegistrationFunnelReport;
use App\Service\Site\SiteContext;
use App\Site\CoopIATour\Form as CoopIATour;
use App\Site\SeminaireCAC\Form as SeminaireCAC;
use App\Site\SeminaireIA\Form as SeminaireIA;
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
    /** Formulaires publics de chaque site : d'où viennent les libellés des champs. */
    private const array SITE_FORMS = [
        'coop_ia_tour' => [CoopIATour\QuestionnaireType::class, CoopIATour\RegistrationStep1Type::class],
        'seminaire_cac' => [SeminaireCAC\RegistrationStep1Type::class],
        'seminaire_ia' => [SeminaireIA\QuestionnaireType::class, SeminaireIA\RegistrationStep1Type::class],
    ];

    private const array FUNNEL_PERIODS = [
        '-24 hours' => '24 dernières heures',
        '-7 days' => '7 derniers jours',
        '-30 days' => '30 derniers jours',
        'all' => 'Tout',
    ];

    public function __construct(
        private readonly LogReader $reader,
        private readonly RegistrationFunnelReport $funnelReport,
        private readonly SiteContext $siteContext,
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

        $site = $this->siteContext->getCurrentSite();
        if (null !== $site && !isset(self::SITE_FORMS[$site->getCode()])) {
            $site = null;
        }

        $files = $this->reader->availableFiles();
        $log = null !== $site ? $this->reader->entries($files['payment']['path']) : ['entries' => [], 'truncated' => false];

        return $this->render('admin/logs_funnel.html.twig', [
            'site' => $site,
            'report' => null === $site ? null : $this->funnelReport->build(
                $log['entries'],
                'all' === $period ? null : new \DateTimeImmutable($period),
                $this->fieldLabels($site->getCode()),
                $site->getCode(),
            ),
            'truncated' => $log['truncated'],
            'periods' => self::FUNNEL_PERIODS,
            'period' => $period,
        ]);
    }

    /** @return array<string, string> libellés affichés au visiteur, par nom de champ */
    private function fieldLabels(string $siteCode): array
    {
        $labels = [];
        foreach (self::SITE_FORMS[$siteCode] as $type) {
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
