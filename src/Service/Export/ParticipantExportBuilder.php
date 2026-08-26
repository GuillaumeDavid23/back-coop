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
 * n'est jamais nommé ici - un autre événement avec d'autres questions
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

    /**
     * Récapitulatif volontairement court : combien d'inscrits, de quel type, et
     * combien de personnes à prévoir sur place. Les réponses libres n'y sont pas
     * ventilées - elles se lisent inscription par inscription dans l'onglet
     * Participants, et leur décompte ne veut rien dire (« Sur quelles tâches
     * perdez-vous le plus de temps ? » ne se totalise pas).
     *
     * @param Registration[] $registrations
     */
    private function buildRecapSheet(Site $site, array $registrations): array
    {
        $participantCount = 0;
        $withCompanion = 0;
        $byFare = [];
        $byType = [];

        foreach ($registrations as $registration) {
            $participants = \count($registration->getParticipants());
            $participantCount += $participants;

            if ($participants > 1) {
                ++$withCompanion;
            }

            $fareLabel = $registration->getFareLabel();
            $byFare[$fareLabel] ??= ['count' => 0, 'participants' => 0, 'amount' => 0.0];
            ++$byFare[$fareLabel]['count'];
            $byFare[$fareLabel]['participants'] += $participants;
            $byFare[$fareLabel]['amount'] += (float) $registration->getAmountInclTax();

            // "Type d'inscrit" au sens de l'organisateur : coopérateur, non
            // coopérateur, adhérent… Absent des événements dont le tarif ne
            // dépend pas d'un statut, auquel cas la section n'est pas rendue.
            $type = $registration->getAnswers()['statut'] ?? null;
            if (null !== $type && '' !== $type) {
                $label = AnswerHumanizer::value($type);
                $byType[$label] ??= ['count' => 0, 'participants' => 0];
                ++$byType[$label]['count'];
                $byType[$label]['participants'] += $participants;
            }
        }

        $rows = [];
        $rows[] = ['Site', $site->getName()];
        // Le périmètre est écrit noir sur blanc : sans cette mention, un total
        // plus bas que celui du back-office passerait pour une anomalie.
        $rows[] = ['Périmètre', 'Inscriptions au paiement confirmé uniquement'];
        $rows[] = ['Total inscriptions', \count($registrations)];
        $rows[] = ['Total participants', $participantCount];
        $rows[] = [];

        if ([] !== $byType) {
            $rows[] = ["RÉPARTITION PAR TYPE D'INSCRIT"];
            $rows[] = ['Type', 'Inscriptions', 'Participants'];
            foreach ($byType as $label => $data) {
                $rows[] = [$label, $data['count'], $data['participants']];
            }
            $rows[] = [];
        }

        $rows[] = ['RÉPARTITION PAR FORFAIT'];
        $rows[] = ['Forfait', 'Inscriptions', 'Participants', 'Montant total TTC'];
        foreach ($byFare as $label => $data) {
            $rows[] = [$label, $data['count'], $data['participants'], $data['amount']];
        }
        $rows[] = [];

        $rows[] = ['NOMBRE DE PARTICIPANTS'];
        $rows[] = ['Inscriptions sans accompagnant', \count($registrations) - $withCompanion];
        $rows[] = ['Inscriptions avec accompagnant', $withCompanion];
        $rows[] = ['Total participants attendus', $participantCount];

        return ['headers' => ['RÉCAPITULATIF - '.$site->getName()], 'rows' => $rows];
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
