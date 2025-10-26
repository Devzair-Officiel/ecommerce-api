<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251025093228 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE product (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, sku VARCHAR(100) NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, locale VARCHAR(5) NOT NULL, short_description LONGTEXT DEFAULT NULL, description LONGTEXT DEFAULT NULL, images JSON DEFAULT NULL, attributes JSON DEFAULT NULL, nutritional_values JSON DEFAULT NULL, customer_type VARCHAR(20) DEFAULT \'B2C\' NOT NULL, average_weight INT DEFAULT NULL, position INT DEFAULT 0 NOT NULL, is_featured TINYINT(1) DEFAULT 0 NOT NULL, is_new TINYINT(1) DEFAULT 0 NOT NULL, meta_title VARCHAR(70) DEFAULT NULL, meta_description VARCHAR(160) DEFAULT NULL, canonical_url VARCHAR(255) DEFAULT NULL, structured_data JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', closed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', is_deleted TINYINT(1) DEFAULT 0 NOT NULL, deleted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_D34A04ADF6BD1646 (site_id), INDEX idx_product_slug_site_locale (slug, site_id, locale), INDEX idx_product_sku (sku), UNIQUE INDEX UNIQ_PRODUCT_SKU_SITE (sku, site_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE product_categories (product_id INT NOT NULL, category_id INT NOT NULL, INDEX IDX_A99419434584665A (product_id), INDEX IDX_A994194312469DE2 (category_id), PRIMARY KEY(product_id, category_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE product_variant (id INT AUTO_INCREMENT NOT NULL, product_id INT NOT NULL, sku VARCHAR(100) NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, prices JSON NOT NULL, stock INT DEFAULT 0 NOT NULL, low_stock_threshold INT DEFAULT 5 NOT NULL, weight INT DEFAULT NULL, dimensions JSON DEFAULT NULL, ean VARCHAR(13) DEFAULT NULL, position INT DEFAULT 0 NOT NULL, is_default TINYINT(1) DEFAULT 0 NOT NULL, image VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', closed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', is_deleted TINYINT(1) DEFAULT 0 NOT NULL, deleted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_209AA41D4584665A (product_id), INDEX idx_variant_sku (sku), UNIQUE INDEX UNIQ_VARIANT_SKU (sku), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04ADF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_categories ADD CONSTRAINT FK_A99419434584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_categories ADD CONSTRAINT FK_A994194312469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product_variant ADD CONSTRAINT FK_209AA41D4584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE product DROP FOREIGN KEY FK_D34A04ADF6BD1646');
        $this->addSql('ALTER TABLE product_categories DROP FOREIGN KEY FK_A99419434584665A');
        $this->addSql('ALTER TABLE product_categories DROP FOREIGN KEY FK_A994194312469DE2');
        $this->addSql('ALTER TABLE product_variant DROP FOREIGN KEY FK_209AA41D4584665A');
        $this->addSql('DROP TABLE product');
        $this->addSql('DROP TABLE product_categories');
        $this->addSql('DROP TABLE product_variant');
    }
}
