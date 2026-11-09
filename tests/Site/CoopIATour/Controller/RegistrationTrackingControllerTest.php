<?php

namespace App\Tests\Site\CoopIATour\Controller;

use App\Site\CoopIATour\Controller\RegistrationTrackingController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;

final class RegistrationTrackingControllerTest extends TestCase
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    private array $logs = [];

    private function post(array $payload): int
    {
        $logs = &$this->logs;
        $logger = new class($logs) extends AbstractLogger {
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = ['message' => (string) $message, 'context' => $context];
            }
        };

        return (new RegistrationTrackingController($logger))(Request::create('/inscription/suivi', 'POST', $payload))->getStatusCode();
    }

    public function testBlockedSubmitLogsFieldNames(): void
    {
        $status = $this->post(['event' => 'blocked', 'step' => 'questionnaire', 'visit' => 'abc123def', 'fields' => 'aiLevel,dailyTools', 'filled' => '4']);

        self::assertSame(204, $status);
        self::assertSame('iatour.funnel.blocked', $this->logs[0]['message']);
        self::assertSame(['aiLevel', 'dailyTools'], $this->logs[0]['context']['fields']);
        self::assertSame(4, $this->logs[0]['context']['filled']);
    }

    public function testUnexpectedFieldNamesAreDropped(): void
    {
        $this->post(['event' => 'blocked', 'step' => 'formulaire', 'visit' => 'abc123def', 'fields' => "email,jean@exemple.fr,a b\nfaux"]);

        self::assertSame(['email'], $this->logs[0]['context']['fields']);
    }

    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadIsNotLogged(array $payload): void
    {
        self::assertSame(204, $this->post($payload));
        self::assertSame([], $this->logs);
    }

    public static function invalidPayloads(): iterable
    {
        yield 'evenement inconnu' => [['event' => 'hack', 'step' => 'formulaire', 'visit' => 'abc123def']];
        yield 'etape inconnue' => [['event' => 'view', 'step' => 'autre', 'visit' => 'abc123def']];
        yield 'visite invalide' => [['event' => 'view', 'step' => 'formulaire', 'visit' => "a\nb"]];
        yield 'vide' => [[]];
    }
}
