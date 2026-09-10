<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Crée la table de la file de messages asynchrone (transport Doctrine).
 *
 * Le transport tourne volontairement avec auto_setup=0 (voir
 * MESSENGER_TRANSPORT_DSN) : l'application ne doit pas créer de table toute
 * seule au premier message, en production comme ailleurs. Sans cette
 * migration, la table n'existait donc nulle part et le worker
 * "messenger:consume" s'arrêtait aussitôt lancé - donc aucune facture générée,
 * aucun email envoyé, sur un environnement fraîchement installé.
 *
 * Structure reprise telle quelle du schéma attendu par
 * Symfony\Component\Messenger\Bridge\Doctrine.
 */
final class Version20260910085600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table messenger_messages (file asynchrone Doctrine)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS messenger_messages (
                id BIGINT AUTO_INCREMENT NOT NULL,
                body LONGTEXT NOT NULL,
                headers LONGTEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL,
                INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_messages');
    }
}
