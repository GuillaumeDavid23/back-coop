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

    /** Libellés des événements métier ; le préfixe de site est retiré pour la recherche. */
    private const array LABELS = [
        'registration.created' => 'Inscription créée, envoi vers le paiement',
        'registration.confirmation.sent' => 'Confirmation envoyée',
        'registration.cancelled' => 'Inscription annulée',
        'registration.credit_note_created' => 'Avoir créé',
        'registration.manual.edited' => 'Inscription modifiée à la main',
        'registration.recap_viewed' => 'Récapitulatif affiché',
        'registration.questionnaire_invalid' => 'Questionnaire refusé par le serveur',
        'registration.step1_invalid' => 'Formulaire refusé par le serveur',
        'registration.questionnaire_missing' => 'Formulaire demandé sans questionnaire',
        'registration.recap_session_missing' => 'Récapitulatif sans session',
        'registration.pay_session_missing' => 'Paiement sans session',
        'registration.terms_not_accepted' => 'Conditions générales non cochées',
        'registration.csrf_invalid' => 'Jeton de sécurité refusé',
        'registration.payments_closed' => 'Paiements fermés',
        'funnel.view' => 'Page du parcours ouverte',
        'funnel.started' => 'Début de saisie',
        'funnel.blocked' => 'Envoi bloqué par le navigateur',
        'funnel.abandoned' => 'Page quittée sans valider',
        'stripe.checkout.create.start' => 'Création de la session Stripe',
        'stripe.checkout.create.success' => 'Session Stripe créée',
        'stripe.checkout.create.failed' => 'Session Stripe refusée',
        'stripe.checkout.retrieve.failed' => 'Lecture de la session Stripe impossible',
        'stripe.payment.pending_created' => 'Paiement en attente enregistré',
        'stripe.payment.sync.confirmed' => 'Paiement confirmé',
        'stripe.payment.sync.expired' => 'Session Stripe expirée',
        'stripe.webhook.checkout_completed' => 'Stripe signale un paiement terminé',
        'stripe.webhook.checkout_expired' => 'Stripe signale une session expirée',
        'stripe.webhook.payment_intent_failed' => 'Stripe signale un paiement refusé',
        'stripe.webhook.charge_refunded' => 'Stripe signale un remboursement',
        'stripe.webhook.signature_invalid' => 'Signature de webhook invalide',
        'stripe.webhook.handler_failed' => 'Traitement du webhook en échec',
        'stripe.webhook.foreign_site_ignored' => 'Webhook d\'un autre site ignoré',
        'invoice.generate.invoice_created' => 'Facture créée',
        'invoice.generate.pdf_failed' => 'Génération du PDF de facture en échec',
        'invoice.due.sent' => 'Facture à régler envoyée',
        'invoice.settled' => 'Facture soldée',
        'invoice_pdf.generate.success' => 'PDF de facture généré',
        'invoice_pdf.generate.wkhtmltopdf_failed' => 'Échec de wkhtmltopdf',
        'payment.manual.paid' => 'Paiement manuel enregistré',
        'payment.manual.switched' => 'Passage en paiement manuel',
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

    /** Libellé en français de l'événement, null s'il n'est pas répertorié. */
    public function label(): ?string
    {
        return self::LABELS[preg_replace('/^(cac|ia|iatour)\./', '', $this->message)] ?? null;
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
