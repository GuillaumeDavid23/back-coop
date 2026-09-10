<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gestion manuelle du paiement d'une inscription et acquittement des factures.
 *
 * - registration.manual_handling_since / _by : l'inscription est suivie à la
 *   main (Stripe ne la confirmera pas), avec la date et l'auteur de la bascule.
 * - invoice.settled_at : date d'acquittement. Une facture peut désormais être
 *   émise AVANT encaissement - cas du virement, où elle porte les coordonnées
 *   bancaires et déclenche le règlement - puis devenir acquittée à réception.
 *
 * Toutes les factures existantes ont été émises après un encaissement Stripe
 * réussi : elles sont donc acquittées, à leur date d'émission. Sans ce
 * rattrapage, un PDF régénéré (voir BillingDocumentProvider) réimprimerait le
 * RIB sur une facture déjà payée, et inviterait à un second règlement.
 */
final class Version20260910091153 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gestion manuelle du paiement (inscription) et acquittement des factures';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD settled_at DATETIME DEFAULT NULL');
        $this->addSql('UPDATE invoice SET settled_at = issued_at WHERE settled_at IS NULL');
        $this->addSql('ALTER TABLE registration ADD manual_handling_since DATETIME DEFAULT NULL, ADD manual_handling_by VARCHAR(190) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP settled_at');
        $this->addSql('ALTER TABLE registration DROP manual_handling_since, DROP manual_handling_by');
    }
}
