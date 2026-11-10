<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schéma initial : catalogue de vins (pgvector), panier, commande.
 */
final class Version20261110120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schéma initial : produits (vecteurs 384 d), paniers, lignes de panier, commandes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');
        $this->addSql('CREATE TABLE product (id UUID NOT NULL, sku VARCHAR(64) NOT NULL, embedding vector(384) DEFAULT NULL, name VARCHAR(160) NOT NULL, brand VARCHAR(64) NOT NULL, category VARCHAR(32) NOT NULL, price_cents INT NOT NULL, description TEXT NOT NULL, attributes JSONB NOT NULL, stock INT NOT NULL, tags JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D34A04ADF9038C4 ON product (sku)');
        $this->addSql('CREATE INDEX idx_product_category ON product (category)');
        $this->addSql('CREATE TABLE cart (checkout_step VARCHAR(24) DEFAULT NULL, checkout_data JSON NOT NULL, checkout_resume_step VARCHAR(24) DEFAULT NULL, checkout_editing BOOLEAN DEFAULT false NOT NULL, checkout_instruction TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE customer_order (id UUID NOT NULL, reference VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, total_cents INT NOT NULL, full_name VARCHAR(160) NOT NULL, shipping_address VARCHAR(255) NOT NULL, phone VARCHAR(20) NOT NULL, email VARCHAR(180) NOT NULL, status VARCHAR(16) NOT NULL, cart_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3B1CE6A3AEA34913 ON customer_order (reference)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3B1CE6A31AD5CDBF ON customer_order (cart_id)');
        $this->addSql('CREATE TABLE cart_item (id UUID NOT NULL, quantity INT NOT NULL, cart_id UUID NOT NULL, product_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F0FE25271AD5CDBF ON cart_item (cart_id)');
        $this->addSql('CREATE INDEX IDX_F0FE25274584665A ON cart_item (product_id)');
        $this->addSql('ALTER TABLE customer_order ADD CONSTRAINT FK_3B1CE6A31AD5CDBF FOREIGN KEY (cart_id) REFERENCES cart (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE cart_item ADD CONSTRAINT FK_F0FE25271AD5CDBF FOREIGN KEY (cart_id) REFERENCES cart (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE cart_item ADD CONSTRAINT FK_F0FE25274584665A FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cart_item');
        $this->addSql('DROP TABLE customer_order');
        $this->addSql('DROP TABLE cart');
        $this->addSql('DROP TABLE product');
    }
}
