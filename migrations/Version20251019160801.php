<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251019160801 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE address DROP FOREIGN KEY FK_D4E6F81A76ED395');
        $this->addSql('DROP INDEX idx_address_user_default ON address');
        $this->addSql('DROP INDEX idx_address_user_type ON address');
        $this->addSql('ALTER TABLE address ADD full_name VARCHAR(255) NOT NULL, ADD additional_address VARCHAR(255) DEFAULT NULL, ADD postal_code VARCHAR(20) NOT NULL, ADD country_code VARCHAR(2) NOT NULL, ADD label VARCHAR(50) DEFAULT NULL, ADD is_deleted TINYINT(1) DEFAULT 0 NOT NULL, ADD deleted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', DROP first_name, DROP last_name, DROP street_complement, DROP zip_code, DROP state, DROP country, CHANGE type type VARCHAR(255) NOT NULL, CHANGE company company VARCHAR(255) DEFAULT NULL, CHANGE city city VARCHAR(255) NOT NULL, CHANGE phone phone VARCHAR(20) NOT NULL, CHANGE is_default is_default TINYINT(1) DEFAULT 0 NOT NULL, CHANGE notes delivery_instructions LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE address ADD CONSTRAINT FK_D4E6F81A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE address DROP FOREIGN KEY FK_D4E6F81A76ED395');
        $this->addSql('ALTER TABLE address ADD first_name VARCHAR(100) NOT NULL, ADD last_name VARCHAR(100) NOT NULL, ADD street_complement VARCHAR(100) DEFAULT NULL, ADD zip_code VARCHAR(10) NOT NULL, ADD state VARCHAR(100) DEFAULT NULL, ADD country VARCHAR(3) NOT NULL, DROP full_name, DROP additional_address, DROP postal_code, DROP country_code, DROP label, DROP is_deleted, DROP deleted_at, CHANGE type type VARCHAR(20) NOT NULL, CHANGE company company VARCHAR(100) DEFAULT NULL, CHANGE city city VARCHAR(100) NOT NULL, CHANGE phone phone VARCHAR(20) DEFAULT NULL, CHANGE is_default is_default TINYINT(1) NOT NULL, CHANGE delivery_instructions notes LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE address ADD CONSTRAINT FK_D4E6F81A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX idx_address_user_default ON address (user_id, is_default)');
        $this->addSql('CREATE INDEX idx_address_user_type ON address (user_id, type)');
    }
}
