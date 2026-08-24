<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Met en ligne les deux sites de la plateforme.
 *
 * La fiche Site était jusqu'ici créée à la main dans le back-office, ce qui
 * obligeait à un aller-retour manuel à chaque déploiement sur un nouvel
 * environnement — et un domaine mal saisi rend le site injoignable, puisque
 * rien ne recoupe cette valeur avec la contrainte de host du routage.
 *
 * Les insertions sont conditionnées à l'absence du code : rejouer la migration
 * sur une base où les sites existent déjà (production, poste de développement)
 * ne crée pas de doublon et n'écrase aucune personnalisation faite depuis le
 * back-office.
 *
 * Les domaines doivent rester alignés sur SEMINAIRE_CAC_DOMAIN et
 * SEMINAIRE_IA_DOMAIN (fichier .env).
 */
final class Version20260824140000 extends AbstractMigration
{
    private const array SITES = [
        [
            'code' => 'seminaire_cac',
            'name' => 'Séminaire CAC',
            'domain' => 'seminaire-cac.clcomevents.fr',
            'invoicing_enabled' => 1,
            'invoice_prefix' => 'CAC26-',
            'credit_note_prefix' => 'AV-CAC26-',
        ],
        [
            // Encaissement Stripe sans émission de facture : la facturation est
            // gérée hors plateforme par CLCOM Academy.
            'code' => 'seminaire_ia',
            'name' => 'Séminaire IA Deauville',
            'domain' => 'seminaire-ia.clcomevents.fr',
            'invoicing_enabled' => 0,
            'invoice_prefix' => 'IA26-',
            'credit_note_prefix' => 'IA26-AV-',
        ],
    ];

    public function getDescription(): string
    {
        return 'Crée les fiches Site du Séminaire CAC et du Séminaire IA si elles sont absentes';
    }

    public function up(Schema $schema): void
    {
        foreach (self::SITES as $site) {
            $this->addSql(
                <<<'SQL'
                    INSERT INTO site (code, name, domain, enabled, invoicing_enabled, invoice_prefix, credit_note_prefix, created_at, updated_at)
                    SELECT :code, :name, :domain, 1, :invoicing_enabled, :invoice_prefix, :credit_note_prefix, NOW(), NOW()
                    FROM DUAL
                    WHERE NOT EXISTS (SELECT 1 FROM site WHERE code = :code)
                    SQL,
                $site,
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Ne retire que les sites encore vierges : une fiche portant des
        // inscriptions est de toute façon protégée par les clés étrangères, et
        // la supprimer emporterait l'historique de facturation.
        foreach (self::SITES as $site) {
            $this->addSql(
                'DELETE FROM site WHERE code = :code AND NOT EXISTS (SELECT 1 FROM registration WHERE registration.site_id = site.id)',
                ['code' => $site['code']],
            );
        }
    }
}
