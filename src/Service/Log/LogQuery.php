<?php

namespace App\Service\Log;

use Symfony\Component\HttpFoundation\Request;

/** Critères de filtrage de l'explorateur de logs (voir LogsController). */
final class LogQuery
{
    public const array LIMITS = [50, 100, 250, 500, 1000];

    public function __construct(
        public readonly string $search = '',
        public readonly string $minLevel = 'DEBUG',
        public readonly ?string $channel = null,
        public readonly ?\DateTimeImmutable $since = null,
        public readonly int $limit = 100,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $since = null;
        $sinceOption = (string) $request->query->get('since', '');
        if ('' !== $sinceOption && 'all' !== $sinceOption) {
            try {
                $since = new \DateTimeImmutable($sinceOption);
            } catch (\Exception) {
                $since = null;
            }
        }

        $limit = (int) $request->query->get('limit', 100);

        return new self(
            trim((string) $request->query->get('q', '')),
            strtoupper((string) $request->query->get('level', 'DEBUG')) ?: 'DEBUG',
            ($channel = (string) $request->query->get('channel', '')) !== '' ? $channel : null,
            $since,
            \in_array($limit, self::LIMITS, true) ? $limit : 100,
        );
    }

    public function matches(LogEntry $entry): bool
    {
        $threshold = LogEntry::LEVELS[$this->minLevel] ?? 0;
        if ($threshold > 0 && $entry->severity() < $threshold) {
            return false;
        }

        if (null !== $this->channel && $entry->channel !== $this->channel) {
            return false;
        }

        if (null !== $this->since && (null === $entry->date || $entry->date < $this->since)) {
            return false;
        }

        if ('' !== $this->search) {
            // Recherche sur la ligne brute : couvre à la fois le message et le
            // contexte JSON (id d'inscription, session Stripe, etc.).
            if (false === stripos($entry->raw, $this->search)) {
                return false;
            }
        }

        return true;
    }
}
