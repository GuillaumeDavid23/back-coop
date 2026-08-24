<?php

namespace App\Service\Log;

/**
 * Une ligne de log Monolog décodée (voir LogReader). Les lignes qui ne
 * correspondent pas au format attendu sont conservées telles quelles dans
 * $raw avec un niveau "UNKNOWN" : on ne perd jamais d'information.
 */
final class LogEntry
{
    /** Ordre de gravité Monolog, pour filtrer "à partir de tel niveau". */
    public const array LEVELS = [
        'DEBUG' => 100,
        'INFO' => 200,
        'NOTICE' => 250,
        'WARNING' => 300,
        'ERROR' => 400,
        'CRITICAL' => 500,
        'ALERT' => 550,
        'EMERGENCY' => 600,
    ];

    public function __construct(
        public readonly ?\DateTimeImmutable $date,
        public readonly string $channel,
        public readonly string $level,
        public readonly string $message,
        public readonly ?array $context,
        public readonly ?array $extra,
        public readonly string $raw,
    ) {
    }

    public function severity(): int
    {
        return self::LEVELS[$this->level] ?? 0;
    }

    /** Classe Bootstrap utilisée pour le badge de niveau dans le BO. */
    public function variant(): string
    {
        return match (true) {
            $this->severity() >= 500 => 'danger',
            $this->severity() >= 400 => 'danger',
            $this->severity() >= 300 => 'warning',
            $this->severity() >= 250 => 'info',
            $this->severity() >= 200 => 'primary',
            default => 'secondary',
        };
    }

    public function hasContext(): bool
    {
        return !empty($this->context) || !empty($this->extra);
    }

    public function contextAsJson(): string
    {
        $payload = array_filter([
            'context' => $this->context ?: null,
            'extra' => $this->extra ?: null,
        ]);

        return json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * Identifiants métier repérés dans le contexte : permet de rebondir d'une
     * ligne de log vers l'inscription / la facture concernée.
     */
    public function businessRefs(): array
    {
        $context = ($this->context ?? []) + ($this->extra ?? []);
        $keys = [
            'registration_id' => 'Inscription',
            'payment_id' => 'Paiement',
            'invoice_id' => 'Facture',
            'invoice_number' => 'Facture',
            'credit_note_number' => 'Avoir',
            'site_code' => 'Site',
            'stripe_checkout_session_id' => 'Session Stripe',
        ];

        $refs = [];
        foreach ($keys as $key => $label) {
            if (isset($context[$key]) && is_scalar($context[$key]) && '' !== (string) $context[$key]) {
                $refs[$label] = (string) $context[$key];
            }
        }

        return $refs;
    }
}
