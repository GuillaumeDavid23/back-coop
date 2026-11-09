<?php

namespace App\Service\Log;

/**
 * Résume le parcours d'inscription du Coop IA Tour à partir de payment.log :
 * progression d'étape en étape, champs qui bloquent, incidents et dernières
 * visites. Une visite correspond à une ouverture de page, pas à une personne.
 */
final class RegistrationFunnelReport
{
    private const string SITE_CODE = 'coop_ia_tour';

    /** Identifiants posés à la main pendant les contrôles de déploiement. */
    private const string TEST_VISIT_PREFIX = 'deploiement';

    private const int RECENT_VISITS = 30;

    private const array STEP_LABELS = ['questionnaire' => 'Questionnaire', 'formulaire' => 'Formulaire'];

    private const array INCIDENTS = [
        'recap_closed' => 'Récapitulatif vu alors que les paiements étaient fermés',
        'iatour.registration.payments_closed' => 'Validation refusée : paiements fermés',
        'iatour.registration.questionnaire_missing' => 'Formulaire demandé sans questionnaire en session',
        'iatour.registration.recap_session_missing' => 'Récapitulatif demandé sans session',
        'iatour.registration.pay_session_missing' => 'Paiement sans session (expirée ou retour depuis Stripe)',
        'iatour.registration.terms_not_accepted' => 'Conditions générales non cochées',
        'iatour.registration.csrf_invalid' => 'Jeton de sécurité refusé au paiement',
        'iatour.registration.invalid_fare' => 'Forfait invalide au paiement',
        'stripe.checkout.create.failed' => 'Session Stripe refusée',
    ];

    /**
     * @param LogEntry[]            $entries du plus ancien au plus récent
     * @param array<string, string> $labels  libellé de chaque champ, par nom
     *
     * @return array{funnel: list<array{label: string, count: int, rate: ?int}>, fields: list<array{label: string, step: string, count: int}>, incidents: list<array{label: string, count: int}>, visits: list<array<string, mixed>>}
     */
    public function build(array $entries, ?\DateTimeImmutable $since, array $labels = []): array
    {
        $counts = array_fill_keys(['view_questionnaire', 'started_questionnaire', 'view_formulaire', 'started_formulaire', 'recap', 'created', 'confirmed'], 0);
        $incidents = array_fill_keys(array_keys(self::INCIDENTS), 0);
        $fields = [];
        $visits = [];
        $registrations = [];

        foreach ($entries as $entry) {
            if (null === $entry->date || (null !== $since && $entry->date < $since)) {
                continue;
            }

            $context = $entry->context ?? [];

            switch ($entry->message) {
                case 'iatour.funnel.view':
                case 'iatour.funnel.started':
                case 'iatour.funnel.blocked':
                case 'iatour.funnel.abandoned':
                    $this->trackVisit($entry, $context, $visits, $counts, $fields);
                    break;
                case 'iatour.registration.questionnaire_invalid':
                    $this->countFields($fields, 'questionnaire', $context['fields'] ?? []);
                    break;
                case 'iatour.registration.step1_invalid':
                    $this->countFields($fields, 'formulaire', $context['fields'] ?? []);
                    break;
                case 'iatour.registration.recap_viewed':
                    ++$counts['recap'];
                    if (false === ($context['payments_open'] ?? null)) {
                        ++$incidents['recap_closed'];
                    }
                    break;
                case 'iatour.registration.created':
                    ++$counts['created'];
                    $registrations[(int) ($context['registration_id'] ?? 0)] = true;
                    break;
                case 'stripe.payment.sync.confirmed':
                    if (isset($registrations[(int) ($context['registration_id'] ?? 0)])) {
                        ++$counts['confirmed'];
                    }
                    break;
                case 'stripe.checkout.create.failed':
                    if (self::SITE_CODE === ($context['site_code'] ?? null)) {
                        ++$incidents[$entry->message];
                    }
                    break;
                default:
                    if (isset($incidents[$entry->message])) {
                        ++$incidents[$entry->message];
                    }
            }
        }

        return [
            'funnel' => $this->funnel($counts),
            'fields' => $this->fieldRows($fields, $labels),
            'incidents' => $this->incidentRows($incidents),
            'visits' => $this->visitRows($visits, $labels),
        ];
    }

    /** @param array<string, mixed> $context */
    private function trackVisit(LogEntry $entry, array $context, array &$visits, array &$counts, array &$fields): void
    {
        $visit = (string) ($context['visit'] ?? '');
        if ('' === $visit || str_starts_with($visit, self::TEST_VISIT_PREFIX)) {
            return;
        }

        $step = (string) ($context['step'] ?? '');
        $event = substr($entry->message, \strlen('iatour.funnel.'));

        $visits[$visit] ??= [
            'visit' => $visit,
            'step' => $step,
            'first' => $entry->date,
            'device' => self::device((string) ($context['user_agent'] ?? '')),
            'browser' => self::browser((string) ($context['user_agent'] ?? '')),
            'started' => false,
            'abandoned' => false,
            'blocked' => [],
            'filled' => 0,
        ];
        $visits[$visit]['last'] = $entry->date;
        $visits[$visit]['filled'] = max($visits[$visit]['filled'], (int) ($context['filled'] ?? 0));

        if (('view' === $event || 'started' === $event) && isset($counts[$event.'_'.$step])) {
            ++$counts[$event.'_'.$step];
        }

        if ('started' === $event) {
            $visits[$visit]['started'] = true;
        } elseif ('abandoned' === $event) {
            $visits[$visit]['abandoned'] = true;
        } elseif ('blocked' === $event) {
            $names = (array) ($context['fields'] ?? []);
            $this->countFields($fields, $step, $names);
            $visits[$visit]['blocked'] = array_values(array_unique([...$visits[$visit]['blocked'], ...$names]));
        }
    }

    /** @param iterable<mixed> $names */
    private function countFields(array &$fields, string $step, iterable $names): void
    {
        foreach ($names as $name) {
            $key = $step.'|'.$name;
            $fields[$key] = ($fields[$key] ?? 0) + 1;
        }
    }

    /** @param array<string, int> $counts */
    private function funnel(array $counts): array
    {
        $start = $counts['view_questionnaire'];
        $rows = [
            ['Questionnaire ouvert', $counts['view_questionnaire']],
            ['Questionnaire commencé', $counts['started_questionnaire']],
            ['Formulaire ouvert (questionnaire validé)', $counts['view_formulaire']],
            ['Formulaire commencé', $counts['started_formulaire']],
            ['Récapitulatif affiché', $counts['recap']],
            ['Paiement lancé', $counts['created']],
            ['Paiement confirmé', $counts['confirmed']],
        ];

        return array_map(static fn (array $row): array => [
            'label' => $row[0],
            'count' => $row[1],
            'rate' => $start > 0 ? (int) round($row[1] * 100 / $start) : null,
        ], $rows);
    }

    /** @param array<string, int> $fields */
    private function fieldRows(array $fields, array $labels): array
    {
        arsort($fields);

        $rows = [];
        foreach ($fields as $key => $count) {
            [$step, $name] = explode('|', $key, 2);
            $rows[] = ['label' => self::label($name, $labels), 'step' => self::STEP_LABELS[$step] ?? $step, 'count' => $count];
        }

        return $rows;
    }

    /** @param array<string, int> $incidents */
    private function incidentRows(array $incidents): array
    {
        arsort($incidents);

        $rows = [];
        foreach (array_filter($incidents) as $key => $count) {
            $rows[] = ['label' => self::INCIDENTS[$key], 'count' => $count];
        }

        return $rows;
    }

    private function visitRows(array $visits, array $labels): array
    {
        usort($visits, static fn (array $a, array $b): int => $b['last'] <=> $a['last']);

        return array_map(static fn (array $visit): array => [
            ...$visit,
            'step' => self::STEP_LABELS[$visit['step']] ?? $visit['step'],
            'status' => self::status($visit),
            'blocked' => array_map(static fn (string $name): string => self::label($name, $labels), $visit['blocked']),
        ], \array_slice($visits, 0, self::RECENT_VISITS));
    }

    private static function label(string $name, array $labels): string
    {
        return '_form' === $name ? 'Formulaire (jeton de sécurité)' : ($labels[$name] ?? $name);
    }

    /** @param array<string, mixed> $visit */
    private static function status(array $visit): string
    {
        return match (true) {
            $visit['abandoned'] && [] !== $visit['blocked'] => 'Bloqué puis parti',
            $visit['abandoned'] => 'Parti sans valider',
            [] !== $visit['blocked'] => 'Bloqué',
            $visit['started'] => 'Commencé, validé ou en cours',
            default => 'Ouvert sans saisie',
        };
    }

    private static function device(string $userAgent): string
    {
        return match (true) {
            '' === $userAgent => '-',
            1 === preg_match('/iPad|Tablet/i', $userAgent) => 'Tablette',
            1 === preg_match('/iPhone|Android|Mobile/i', $userAgent) => 'Mobile',
            default => 'Ordinateur',
        };
    }

    private static function browser(string $userAgent): string
    {
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'CriOS' => 'Chrome', 'Safari' => 'Safari'] as $token => $name) {
            if (str_contains($userAgent, $token)) {
                return $name;
            }
        }

        return '-';
    }
}
