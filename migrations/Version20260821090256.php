<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260821090256 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE credit_note (id INT AUTO_INCREMENT NOT NULL, number VARCHAR(100) NOT NULL, sequence_number INT NOT NULL, amount NUMERIC(10, 2) NOT NULL, reason LONGTEXT DEFAULT NULL, issued_at DATETIME NOT NULL, pdf_path VARCHAR(255) DEFAULT NULL, site_id INT NOT NULL, invoice_id INT NOT NULL, INDEX IDX_C87F4529F6BD1646 (site_id), INDEX IDX_C87F45292989F1FD (invoice_id), UNIQUE INDEX uniq_credit_note_number (number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE invoice (id INT AUTO_INCREMENT NOT NULL, number VARCHAR(100) NOT NULL, sequence_number INT NOT NULL, amount_excl_tax NUMERIC(10, 2) NOT NULL, tax_amount NUMERIC(10, 2) NOT NULL, amount_incl_tax NUMERIC(10, 2) NOT NULL, issued_at DATETIME NOT NULL, pdf_path VARCHAR(255) DEFAULT NULL, billing_data_snapshot JSON NOT NULL, site_id INT NOT NULL, registration_id INT NOT NULL, payment_id INT DEFAULT NULL, INDEX IDX_90651744F6BD1646 (site_id), INDEX IDX_90651744833D8F43 (registration_id), INDEX IDX_906517444C3A3BB (payment_id), UNIQUE INDEX uniq_invoice_number (number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE invoice_sequence (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(20) NOT NULL, next_number INT NOT NULL, site_id INT NOT NULL, INDEX IDX_4DAE8D73F6BD1646 (site_id), UNIQUE INDEX uniq_sequence_site_type (site_id, type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE participant (id INT AUTO_INCREMENT NOT NULL, civility VARCHAR(20) DEFAULT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, email VARCHAR(190) NOT NULL, phone VARCHAR(30) DEFAULT NULL, company VARCHAR(190) DEFAULT NULL, status VARCHAR(100) DEFAULT NULL, address VARCHAR(190) DEFAULT NULL, postal_code VARCHAR(20) DEFAULT NULL, city VARCHAR(100) DEFAULT NULL, motivation LONGTEXT DEFAULT NULL, special_needs LONGTEXT DEFAULT NULL, consent_accepted TINYINT NOT NULL, answers JSON NOT NULL, registration_id INT NOT NULL, INDEX IDX_D79F6B11833D8F43 (registration_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE payment (id INT AUTO_INCREMENT NOT NULL, stripe_checkout_session_id VARCHAR(190) DEFAULT NULL, stripe_payment_intent_id VARCHAR(190) DEFAULT NULL, amount NUMERIC(10, 2) NOT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(20) NOT NULL, paid_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, site_id INT NOT NULL, registration_id INT NOT NULL, INDEX IDX_6D28840DF6BD1646 (site_id), INDEX IDX_6D28840D833D8F43 (registration_id), UNIQUE INDEX uniq_payment_checkout_session (stripe_checkout_session_id), UNIQUE INDEX uniq_payment_intent (stripe_payment_intent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE registration (id INT AUTO_INCREMENT NOT NULL, fare_code VARCHAR(100) NOT NULL, fare_label VARCHAR(190) NOT NULL, amount_excl_tax NUMERIC(10, 2) NOT NULL, tax_rate NUMERIC(5, 2) NOT NULL, amount_incl_tax NUMERIC(10, 2) NOT NULL, status VARCHAR(20) NOT NULL, answers JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, site_id INT NOT NULL, INDEX IDX_62A8A7A7F6BD1646 (site_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE site (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(100) NOT NULL, name VARCHAR(190) NOT NULL, domain VARCHAR(190) NOT NULL, enabled TINYINT NOT NULL, invoice_prefix VARCHAR(20) DEFAULT NULL, invoice_suffix VARCHAR(20) DEFAULT NULL, credit_note_prefix VARCHAR(20) DEFAULT NULL, credit_note_suffix VARCHAR(20) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_694309E477153098 (code), UNIQUE INDEX UNIQ_694309E4A7A91E0B (domain), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(190) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, enabled TINYINT NOT NULL, created_at DATETIME NOT NULL, last_login_at DATETIME DEFAULT NULL, UNIQUE INDEX uniq_user_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_site (user_id INT NOT NULL, site_id INT NOT NULL, INDEX IDX_13C2452DA76ED395 (user_id), INDEX IDX_13C2452DF6BD1646 (site_id), PRIMARY KEY (user_id, site_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE credit_note ADD CONSTRAINT FK_C87F4529F6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('ALTER TABLE credit_note ADD CONSTRAINT FK_C87F45292989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id)');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_90651744F6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_90651744833D8F43 FOREIGN KEY (registration_id) REFERENCES registration (id)');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_906517444C3A3BB FOREIGN KEY (payment_id) REFERENCES payment (id)');
        $this->addSql('ALTER TABLE invoice_sequence ADD CONSTRAINT FK_4DAE8D73F6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('ALTER TABLE participant ADD CONSTRAINT FK_D79F6B11833D8F43 FOREIGN KEY (registration_id) REFERENCES registration (id)');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840DF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D833D8F43 FOREIGN KEY (registration_id) REFERENCES registration (id)');
        $this->addSql('ALTER TABLE registration ADD CONSTRAINT FK_62A8A7A7F6BD1646 FOREIGN KEY (site_id) REFERENCES site (id)');
        $this->addSql('ALTER TABLE user_site ADD CONSTRAINT FK_13C2452DA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_site ADD CONSTRAINT FK_13C2452DF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE credit_note DROP FOREIGN KEY FK_C87F4529F6BD1646');
        $this->addSql('ALTER TABLE credit_note DROP FOREIGN KEY FK_C87F45292989F1FD');
        $this->addSql('ALTER TABLE invoice DROP FOREIGN KEY FK_90651744F6BD1646');
        $this->addSql('ALTER TABLE invoice DROP FOREIGN KEY FK_90651744833D8F43');
        $this->addSql('ALTER TABLE invoice DROP FOREIGN KEY FK_906517444C3A3BB');
        $this->addSql('ALTER TABLE invoice_sequence DROP FOREIGN KEY FK_4DAE8D73F6BD1646');
        $this->addSql('ALTER TABLE participant DROP FOREIGN KEY FK_D79F6B11833D8F43');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840DF6BD1646');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D833D8F43');
        $this->addSql('ALTER TABLE registration DROP FOREIGN KEY FK_62A8A7A7F6BD1646');
        $this->addSql('ALTER TABLE user_site DROP FOREIGN KEY FK_13C2452DA76ED395');
        $this->addSql('ALTER TABLE user_site DROP FOREIGN KEY FK_13C2452DF6BD1646');
        $this->addSql('DROP TABLE credit_note');
        $this->addSql('DROP TABLE invoice');
        $this->addSql('DROP TABLE invoice_sequence');
        $this->addSql('DROP TABLE participant');
        $this->addSql('DROP TABLE payment');
        $this->addSql('DROP TABLE registration');
        $this->addSql('DROP TABLE site');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE user_site');
    }
}
