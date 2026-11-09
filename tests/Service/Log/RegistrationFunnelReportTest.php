<?php

namespace App\Tests\Service\Log;

use App\Service\Log\LogEntry;
use App\Service\Log\RegistrationFunnelReport;
use PHPUnit\Framework\TestCase;

final class RegistrationFunnelReportTest extends TestCase
{
    private function entry(string $message, array $context = [], string $date = '2026-11-09 10:00:00'): LogEntry
    {
        return new LogEntry(new \DateTimeImmutable($date), 'payment', 'INFO', $message, $context, null, $message);
    }

    private function funnel(array $report): array
    {
        return array_column($report['funnel'], 'count', 'label');
    }

    public function testFunnelCountsEachStep(): void
    {
        $report = (new RegistrationFunnelReport())->build([
            $this->entry('iatour.funnel.view', ['step' => 'questionnaire', 'visit' => 'aaaaaa']),
            $this->entry('iatour.funnel.view', ['step' => 'questionnaire', 'visit' => 'bbbbbb']),
            $this->entry('iatour.funnel.started', ['step' => 'questionnaire', 'visit' => 'bbbbbb']),
            $this->entry('iatour.funnel.view', ['step' => 'formulaire', 'visit' => 'cccccc']),
            $this->entry('iatour.registration.recap_viewed', ['payments_open' => true]),
            $this->entry('iatour.registration.created', ['registration_id' => 5]),
            $this->entry('stripe.payment.sync.confirmed', ['registration_id' => 5]),
            $this->entry('stripe.payment.sync.confirmed', ['registration_id' => 99]),
        ], null);

        $funnel = $this->funnel($report);
        self::assertSame(2, $funnel['Questionnaire ouvert']);
        self::assertSame(1, $funnel['Questionnaire commencé']);
        self::assertSame(1, $funnel['Formulaire ouvert (questionnaire validé)']);
        self::assertSame(1, $funnel['Paiement confirmé'], "Le paiement d'un autre site ne compte pas.");
        self::assertSame(50, $report['funnel'][1]['rate']);
    }

    public function testBlockedFieldsAreRankedWithTheirLabels(): void
    {
        $report = (new RegistrationFunnelReport())->build([
            $this->entry('iatour.funnel.blocked', ['step' => 'questionnaire', 'visit' => 'aaaaaa', 'fields' => ['aiLevel', 'dailyTools']]),
            $this->entry('iatour.funnel.blocked', ['step' => 'questionnaire', 'visit' => 'bbbbbb', 'fields' => ['aiLevel']]),
            $this->entry('iatour.registration.step1_invalid', ['fields' => ['email']]),
        ], null, ['aiLevel' => 'Niveau IA']);

        self::assertSame(['label' => 'Niveau IA', 'step' => 'Questionnaire', 'count' => 2], $report['fields'][0]);
        self::assertContains('email', array_column($report['fields'], 'label'));
    }

    public function testVisitStatusAndDevice(): void
    {
        $iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit Version/18.0 Mobile Safari/604.1';
        $report = (new RegistrationFunnelReport())->build([
            $this->entry('iatour.funnel.view', ['step' => 'questionnaire', 'visit' => 'aaaaaa', 'user_agent' => $iphone]),
            $this->entry('iatour.funnel.started', ['step' => 'questionnaire', 'visit' => 'aaaaaa']),
            $this->entry('iatour.funnel.blocked', ['step' => 'questionnaire', 'visit' => 'aaaaaa', 'fields' => ['aiLevel'], 'filled' => 3]),
            $this->entry('iatour.funnel.abandoned', ['step' => 'questionnaire', 'visit' => 'aaaaaa', 'filled' => 3], '2026-11-09 10:05:00'),
        ], null);

        $visit = $report['visits'][0];
        self::assertSame('Bloqué puis parti', $visit['status']);
        self::assertSame('Mobile', $visit['device']);
        self::assertSame('Safari', $visit['browser']);
        self::assertSame(3, $visit['filled']);
    }

    public function testPeriodAndTestVisitsAreIgnored(): void
    {
        $report = (new RegistrationFunnelReport())->build([
            $this->entry('iatour.funnel.view', ['step' => 'questionnaire', 'visit' => 'aaaaaa'], '2026-11-01 10:00:00'),
            $this->entry('iatour.funnel.view', ['step' => 'questionnaire', 'visit' => 'deploiement0b1a397']),
        ], new \DateTimeImmutable('2026-11-05'));

        self::assertSame(0, $this->funnel($report)['Questionnaire ouvert']);
        self::assertSame([], $report['visits']);
    }

    public function testEachSiteOnlySeesItsOwnEvents(): void
    {
        $report = (new RegistrationFunnelReport())->build([
            $this->entry('iatour.funnel.view', ['step' => 'formulaire', 'visit' => 'aaaaaa']),
            $this->entry('cac.funnel.view', ['step' => 'formulaire', 'visit' => 'bbbbbb']),
            $this->entry('cac.funnel.view', ['step' => 'formulaire', 'visit' => 'cccccc']),
            $this->entry('cac.registration.created', ['registration_id' => 8]),
        ], null, [], 'seminaire_cac');

        $funnel = $this->funnel($report);
        self::assertArrayNotHasKey('Questionnaire ouvert', $funnel);
        self::assertSame(2, $funnel['Formulaire ouvert']);
        self::assertSame(1, $funnel['Paiement lancé']);
        self::assertSame(100, $report['funnel'][0]['rate']);
        self::assertCount(2, $report['visits']);
    }

    public function testIncidentsOnlyCountThisSite(): void
    {
        $report = (new RegistrationFunnelReport())->build([
            $this->entry('iatour.registration.recap_viewed', ['payments_open' => false]),
            $this->entry('stripe.checkout.create.failed', ['site_code' => 'seminaire_cac']),
        ], null);

        self::assertSame([['label' => 'Récapitulatif vu alors que les paiements étaient fermés', 'count' => 1]], $report['incidents']);
    }
}
