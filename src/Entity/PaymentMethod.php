<?php

namespace App\Entity;

/**
 * Moyen par lequel le règlement est arrivé. La carte est le cas normal :
 * c'est le seul encaissement que la plateforme réalise elle-même, via Stripe
 * Checkout. Les autres valeurs ne sont jamais posées automatiquement - elles
 * n'existent que lorsque l'argent a été reçu hors plateforme et que le
 * paiement est validé à la main depuis le BO (voir ManualPaymentRecorder).
 */
enum PaymentMethod: string
{
    case CARD = 'card';
    case TRANSFER = 'transfer';
    case CHECK = 'check';
    case CASH = 'cash';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CARD => 'Carte bancaire',
            self::TRANSFER => 'Virement',
            self::CHECK => 'Chèque',
            self::CASH => 'Espèces',
            self::OTHER => 'Autre',
        };
    }

    /**
     * Moyens proposables à la validation manuelle : tout sauf la carte, qui ne
     * peut venir que de Stripe. Cocher "carte" à la main laisserait croire à un
     * encaissement Stripe dont aucune trace n'existerait côté banque.
     *
     * @return list<self>
     */
    public static function manualChoices(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $case) => self::CARD !== $case));
    }
}
