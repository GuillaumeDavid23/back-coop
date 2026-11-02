<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Interrupteur des paiements par site, réglé dans l'admin. Les sites existants
 * restent ouverts ; La Coop' IA Tour démarre fermée, le temps de finaliser le
 * site.
 */
final class Version20261103100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute site.payments_open, fermé pour La Coop' IA Tour";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site ADD payments_open TINYINT DEFAULT 1 NOT NULL');
        $this->addSql("UPDATE site SET payments_open = 0 WHERE code = 'coop_ia_tour'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site DROP payments_open');
    }
}
