<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fiche Site de La Coop' IA Tour, si elle est absente. Elle doit exister avant
 * le premier paiement : le webhook Stripe ignore les sessions d'un site inconnu.
 *
 * Factures CLCOM Academy numérotées IA26- (mail de Marion BROYER du
 * 30/10/2026). Le Séminaire IA Deauville porte le même préfixe mais n'émet
 * aucune facture (invoicing_enabled = 0) : ses numéros ne peuvent pas entrer en
 * collision avec ceux-ci tant que sa facturation reste désactivée.
 *
 * Le domaine doit rester aligné sur COOP_IA_TOUR_DOMAIN (fichier .env).
 */
final class Version20261102000000 extends AbstractMigration
{
    private const array SITE = [
        'code' => 'coop_ia_tour',
        'name' => "La Coop' IA Tour",
        'domain' => 'coop-ia-tour.clcomevents.fr',
        'invoicing_enabled' => 1,
        'invoice_prefix' => 'IA26-',
        'credit_note_prefix' => 'IA26-AV-',
    ];

    public function getDescription(): string
    {
        return "Crée la fiche Site de La Coop' IA Tour si elle est absente";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            <<<'SQL'
                INSERT INTO site (code, name, domain, enabled, invoicing_enabled, invoice_prefix, credit_note_prefix, created_at, updated_at)
                SELECT :code, :name, :domain, 1, :invoicing_enabled, :invoice_prefix, :credit_note_prefix, NOW(), NOW()
                FROM DUAL
                WHERE NOT EXISTS (SELECT 1 FROM site WHERE code = :code)
                SQL,
            self::SITE,
        );
    }

    public function down(Schema $schema): void
    {
        // Comme pour les premiers sites : seule une fiche encore vierge est retirée.
        $this->addSql(
            'DELETE FROM site WHERE code = :code AND NOT EXISTS (SELECT 1 FROM registration WHERE registration.site_id = site.id)',
            ['code' => self::SITE['code']],
        );
    }
}
