<?php

namespace App\Site\CoopIATour\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Reçoit le suivi du navigateur sur le questionnaire et le formulaire : tant
 * qu'un visiteur n'a pas validé, le serveur ne voit rien, et un envoi bloqué
 * par le navigateur ne laisse aucune trace dans les logs d'accès.
 *
 * Route publique : seules des valeurs attendues sont journalisées, et jamais
 * le contenu saisi (noms de champs et nombre de champs remplis uniquement).
 * La réponse est toujours vide, le navigateur n'en fait rien.
 */
final class RegistrationTrackingController
{
    private const array EVENTS = ['view', 'started', 'blocked', 'abandoned'];
    private const array STEPS = ['questionnaire', 'formulaire'];
    private const int MAX_FIELDS = 30;

    public function __construct(
        #[Autowire(service: 'monolog.logger.payment')]
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/inscription/suivi', name: 'registration_tracking', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $event = $request->request->getString('event');
        $step = $request->request->getString('step');
        $visit = $request->request->getString('visit');

        if (
            \in_array($event, self::EVENTS, true)
            && \in_array($step, self::STEPS, true)
            && 1 === preg_match('/^[a-z0-9]{6,32}$/', $visit)
        ) {
            $this->logger->info('iatour.funnel.'.$event, [
                'step' => $step,
                'visit' => $visit,
                'fields' => $this->fields($request->request->getString('fields')),
                'filled' => min(100, max(0, $request->request->getInt('filled'))),
                'user_agent' => mb_substr((string) $request->headers->get('User-Agent', ''), 0, 200),
            ]);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** @return list<string> */
    private function fields(string $raw): array
    {
        $fields = array_filter(
            explode(',', $raw),
            static fn (string $field): bool => 1 === preg_match('/^\w{1,40}$/', $field),
        );

        return \array_slice(array_values($fields), 0, self::MAX_FIELDS);
    }
}
