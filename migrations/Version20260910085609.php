<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute au paiement de quoi enregistrer un règlement reçu hors Stripe
 * (virement, chèque, espèces) validé à la main depuis le BO : moyen de
 * règlement, référence imprimée sur la facture, et auteur de la validation.
 *
 * Tous les paiements existants viennent de Stripe Checkout : ils sont donc
 * initialisés à "card" avant que la colonne ne devienne obligatoire - une
 * chaîne vide ne correspondrait à aucun cas de l'enum PaymentMethod et ferait
 * échouer l'hydratation de la ligne.
 */
final class Version20260910085609 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paiement : moyen de règlement, référence et auteur de la validation manuelle';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment ADD method VARCHAR(20) DEFAULT NULL, ADD manual_reference VARCHAR(190) DEFAULT NULL, ADD manually_validated_by VARCHAR(190) DEFAULT NULL');
        $this->addSql("UPDATE payment SET method = 'card' WHERE method IS NULL");
        $this->addSql('ALTER TABLE payment CHANGE method method VARCHAR(20) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment DROP method, DROP manual_reference, DROP manually_validated_by');
    }
}
