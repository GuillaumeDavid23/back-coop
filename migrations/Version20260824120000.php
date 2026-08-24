<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Interrupteur de facturation par site : certains événements (Séminaire IA)
 * sont encaissés via Stripe sans émission de facture par la plateforme.
 */
final class Version20260824120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute site.invoicing_enabled (désactivation de la facturation par site)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site ADD invoicing_enabled TINYINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site DROP invoicing_enabled');
    }
}
