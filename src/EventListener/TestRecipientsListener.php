<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Psr\Log\LoggerInterface;

/**
 * Recette en production : tant que MAILER_TEST_RECIPIENTS est renseignée, tous
 * les emails sont livrés à ces adresses au lieu de leurs destinataires réels.
 *
 * L'interception se fait sur l'enveloppe SMTP, pas sur le message : les
 * en-têtes To/Cc/Cci d'origine restent intacts dans le mail reçu, ce qui permet
 * de vérifier qui l'aurait reçu en conditions normales.
 *
 * Priorité très basse pour passer en dernier, après les écouteurs du framework
 * qui construisent l'enveloppe.
 */
#[AsEventListener(event: MessageEvent::class, priority: -1000)]
final class TestRecipientsListener
{
    /** @var list<string> */
    private array $recipients;

    public function __construct(
        #[Autowire(env: 'MAILER_TEST_RECIPIENTS')]
        string $recipients,
        private readonly LoggerInterface $logger,
    ) {
        $this->recipients = array_values(array_filter(array_map(trim(...), explode(',', $recipients))));
    }

    public function __invoke(MessageEvent $event): void
    {
        if ([] === $this->recipients) {
            return;
        }

        $envelope = $event->getEnvelope();
        $original = array_map(static fn (Address $address): string => $address->getAddress(), $envelope->getRecipients());

        $envelope->setRecipients(array_map(static fn (string $address): Address => new Address($address), $this->recipients));

        $this->logger->info('mailer.recipients_overridden', [
            'test_recipients' => $this->recipients,
            'original_recipients' => $original,
        ]);
    }
}
