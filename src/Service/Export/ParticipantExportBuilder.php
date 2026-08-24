<?php

namespace App\Service\Export;

use App\Entity\Registration;
use App\Entity\Site;

/**
 * Construit l'export Excel "Participants" (détail + récapitulatif) à partir
 * des inscriptions d'un site. Volontairement générique : la ventilation par
 * réponse libre est calculée dynamiquement à partir des clés présentes dans
 * Registration::answers / Participant::answers, sans rien connaître de
 * l'événement en cours (le "cocktail" du Séminaire CAC en fait partie mais
 * n'est jamais nommé ici — un autre événement avec d'autres questions
 * produira le même genre de ventilation automatiquement).
 */
final class ParticipantExportBuilder
{
    /** Clés déjà représentées par des colonnes dédiées : à exclure de la ventilation générique. */
    private const array TYPED_ANSWER_KEYS = ['motivation', 'specialNeeds'];

    /**
     * @param Registration[] $registrations
     * @return array<string, array{headers: list<string>, rows: list<list<mixed>>}>
     */
    public function build(Site $site, array $registrations): array
    {
        return [
            'Participants' => $this->buildParticipantsSheet($registrations),
            'Récapitulatif' => $this->buildRecapSheet($site, $registrations),
        ];
    }

    /** @param Registration[] $registrations */
    private function buildParticipantsSheet(array $registrations): array
    {
        $dynamicKeys = $this->collectDynamicAnswerKeys($registrations);

        $headers = [
            'Inscription #', 'Civilité', 'Nom', 'Prénom', 'Email', 'Téléphone',
            'Cabinet', 'Statut', 'Forfait', 'Montant TTC', 'Statut inscription',
            'Motif', 'Besoins spécifiques',
        ];
        foreach ($dynamicKeys as $key) {
            $headers[] = AnswerHumanizer::key($key);
        }

        $rows = [];
        foreach ($registrations as $registration) {
            $mergedAnswers = $registration->getAnswers();
            foreach ($registration->getParticipants() as $participant) {
                $participantAnswers = array_merge($mergedAnswers, $participant->getAnswers());

                $row = [
                    $registration->getId(),
                    AnswerHumanizer::civility($participant->getCivility()),
                    $participant->getLastName(),
                    $participant->getFirstName(),
                    $participant->getEmail(),
                    $participant->getPhone(),
                    $participant->getCompany(),
                    AnswerHumanizer::participantStatus($participant->getStatus()),
                    $registration->getFareLabel(),
                    (float) $registration->getAmountInclTax(),
                    AnswerHumanizer::registrationStatus($registration->getStatus()->value),
                    $participant->getMotivation(),
                    $participant->getSpecialNeeds(),
                ];
                foreach ($dynamicKeys as $key) {
                    $row[] = AnswerHumanizer::value($participantAnswers[$key] ?? '');
                }
                $rows[] = $row;
            }
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /** @param Registration[] $registrations */
    private function buildRecapSheet(Site $site, array $registrations): array
    {
        $participantCount = 0;
        $byFare = [];
        /** @var array<string, array<string, int>> $byAnswerKey */
        $byAnswerKey = [];

        foreach ($registrations as $registration) {
            $fareLabel = $registration->getFareLabel();
            $byFare[$fareLabel] ??= ['count' => 0, 'amount' => 0.0];
            $byFare[$fareLabel]['count']++;
            $byFare[$fareLabel]['amount'] += (float) $registration->getAmountInclTax();

            foreach ($registration->getParticipants() as $participant) {
                $participantCount++;
                $mergedAnswers = array_merge($registration->getAnswers(), $participant->getAnswers());
                foreach ($mergedAnswers as $key => $value) {
                    if (in_array($key, self::TYPED_ANSWER_KEYS, true) || $value === null || $value === '') {
                        continue;
                    }
                    $valueLabel = AnswerHumanizer::value($value);
                    $byAnswerKey[$key][$valueLabel] = ($byAnswerKey[$key][$valueLabel] ?? 0) + 1;
                }
            }
        }

        $rows = [];
        $rows[] = ['Site', $site->getName()];
        // Le périmètre est écrit noir sur blanc : sans cette mention, un total
        // plus bas que celui du back-office passerait pour une anomalie.
        $rows[] = ['Périmètre', 'Inscriptions au paiement confirmé uniquement'];
        $rows[] = ['Total inscriptions', count($registrations)];
        $rows[] = ['Total participants', $participantCount];
        $rows[] = [];

        $rows[] = ['RÉPARTITION PAR FORFAIT'];
        $rows[] = ['Forfait', 'Nombre', 'Montant total TTC'];
        foreach ($byFare as $label => $data) {
            $rows[] = [$label, $data['count'], $data['amount']];
        }
        $rows[] = [];

        foreach ($byAnswerKey as $key => $values) {
            $rows[] = [];
            $rows[] = [mb_strtoupper(AnswerHumanizer::key($key))];
            $rows[] = ['Réponse', 'Nombre'];
            foreach ($values as $valueLabel => $count) {
                $rows[] = [$valueLabel, $count];
            }
        }

        return ['headers' => ['RÉCAPITULATIF — '.$site->getName()], 'rows' => $rows];
    }

    /** @param Registration[] $registrations
     * @return list<string> */
    private function collectDynamicAnswerKeys(array $registrations): array
    {
        $keys = [];
        foreach ($registrations as $registration) {
            foreach (array_keys($registration->getAnswers()) as $key) {
                if (!in_array($key, self::TYPED_ANSWER_KEYS, true)) {
                    $keys[$key] = true;
                }
            }
            foreach ($registration->getParticipants() as $participant) {
                foreach (array_keys($participant->getAnswers()) as $key) {
                    if (!in_array($key, self::TYPED_ANSWER_KEYS, true)) {
                        $keys[$key] = true;
                    }
                }
            }
        }

        return array_keys($keys);
    }
}
