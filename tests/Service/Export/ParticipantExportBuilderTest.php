<?php

namespace App\Tests\Service\Export;

use App\Entity\Participant;
use App\Entity\Registration;
use App\Entity\RegistrationStatus;
use App\Entity\Site;
use App\Service\Export\ParticipantExportBuilder;
use PHPUnit\Framework\TestCase;

final class ParticipantExportBuilderTest extends TestCase
{
    /** Un accompagnant n'est pas un participant au séminaire : il n'apparaît pas dans la liste. */
    public function testCompanionIsLeftOut(): void
    {
        $sheet = $this->participantsSheet($this->registration('heb_1ec_acc', ['Martin', 'Durand']));

        self::assertSame(['Martin'], array_column($sheet['rows'], 2));
    }

    /** Le récapitulatif ne compte que les experts-comptables. */
    public function testRecapDoesNotCountCompanions(): void
    {
        $site = (new Site())->setCode('seminaire_ia')->setName('Séminaire IA');
        $recap = (new ParticipantExportBuilder())->build($site, [
            $this->registration('heb_1ec_acc', ['Martin', 'Durand']),
            $this->registration('heb_2ec', ['Petit', 'Leroy']),
        ])['Récapitulatif'];

        self::assertContains(['Total participants', 3], $recap['rows']);
        self::assertSame([], array_filter(array_column($recap['rows'], 0), static fn ($label) => str_contains((string) $label, 'accompagnant')));
    }

    /**
     * Le questionnaire n'est rempli qu'une fois, par le participant principal :
     * le 2e expert-comptable ne garde que les réponses communes à l'inscription.
     */
    public function testSecondExpertOnlyGetsSharedAnswers(): void
    {
        $sheet = $this->participantsSheet($this->registration('heb_2ec', ['Martin', 'Durand']));

        self::assertSame(['Martin', 'Durand'], array_column($sheet['rows'], 2));
        $level = array_search("Niveau d'utilisation de l'IA", $sheet['headers'], true);
        // 2e colonne « Statut » : la réponse du questionnaire, après le statut du participant.
        $statut = array_search('Statut', array_slice($sheet['headers'], array_search('Statut', $sheet['headers'], true) + 1, null, true), true);
        self::assertSame('Avancé', $sheet['rows'][0][$level]);
        self::assertSame('', $sheet['rows'][1][$level]);
        self::assertSame('Coopérateur', $sheet['rows'][1][$statut]);
    }

    /** Lola BOYER (05/11/2026) : l'adresse du cabinet sort dans l'export, après « Cabinet ». */
    public function testExportsTheFirmAddress(): void
    {
        $registration = $this->registration('heb_2ec', ['Martin']);
        $registration->getPrimaryParticipant()->setCompany('Cabinet Martin')->setAddress('12 rue de la Paix')->setPostalCode('75002')->setCity('Paris');

        $sheet = $this->participantsSheet($registration);
        $cabinet = array_search('Cabinet', $sheet['headers'], true);

        self::assertSame(['Cabinet', 'Adresse', 'Code postal', 'Ville'], array_slice($sheet['headers'], $cabinet, 4));
        self::assertSame(['Cabinet Martin', '12 rue de la Paix', '75002', 'Paris'], array_slice($sheet['rows'][0], $cabinet, 4));
    }

    /** @return array{headers: list<string>, rows: list<list<mixed>>} */
    private function participantsSheet(Registration $registration): array
    {
        $site = (new Site())->setCode('seminaire_ia')->setName('Séminaire IA');

        return (new ParticipantExportBuilder())->build($site, [$registration])['Participants'];
    }

    /** @param list<string> $lastNames */
    private function registration(string $fareCode, array $lastNames): Registration
    {
        $registration = (new Registration())
            ->setFareCode($fareCode)
            ->setFareLabel($fareCode)
            ->setAmountInclTax('1000.00')
            ->setStatus(RegistrationStatus::CONFIRMED)
            ->setAnswers(['statut' => 'cooperateur', 'aiLevel' => 'Avancé']);

        foreach ($lastNames as $lastName) {
            $registration->addParticipant(
                (new Participant())->setFirstName('Jean')->setLastName($lastName)->setEmail(''),
            );
        }

        return $registration;
    }
}
