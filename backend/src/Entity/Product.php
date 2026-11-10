<?php

declare(strict_types=1);

namespace App\Entity;

use App\Catalog\AttributeText;
use Doctrine\ORM\Mapping as ORM;
use Pgvector\Vector;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Produit du catalogue : colonnes communes et champ JSON `attributes` pour les spécificités
 * du vin (cépages, millésime, appellation…).
 */
#[ORM\Entity]
#[ORM\Table(name: 'product')]
#[ORM\Index(name: 'idx_product_category', columns: ['category'])]
class Product
{
    public const EMBEDDING_DIMENSIONS = 384;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    public private(set) Uuid $id;

    #[ORM\Column(length: 64, unique: true)]
    public string $sku {
        set => strtoupper(trim($value));
    }

    /**
     * Embedding 384d (modèle multilingue fastembed ou repli déterministe). Pas d'index HNSW :
     * Doctrine ne sait pas exprimer l'opclass vector_cosine_ops sans dérive de schéma.
     */
    #[ORM\Column(type: 'vector', length: self::EMBEDDING_DIMENSIONS, nullable: true)]
    public private(set) ?Vector $embedding = null;

    /** Prix en euros, dérivé du prix en centimes (property hook virtuel). */
    public float $priceEuros {
        get => $this->priceCents / 100;
    }

    /**
     * @param array<string, mixed> $attributes Spécificités du vin (cépages, millésime, notes…)
     * @param list<string>         $tags
     */
    public function __construct(
        string $sku,
        #[ORM\Column(length: 160)]
        public private(set) string $name,
        #[ORM\Column(length: 64)]
        public private(set) string $brand,
        #[ORM\Column(length: 32)]
        public private(set) string $category,
        #[ORM\Column]
        public private(set) int $priceCents,
        #[ORM\Column(type: 'text')]
        public private(set) string $description,
        #[ORM\Column(type: 'json', options: ['jsonb' => true])]
        public private(set) array $attributes = [],
        #[ORM\Column]
        public private(set) int $stock = 0,
        #[ORM\Column(type: 'json')]
        public private(set) array $tags = [],
    ) {
        $this->id = Uuid::v4();
        $this->sku = $sku;
    }

    /**
     * @param list<float> $values
     */
    public function setEmbedding(array $values): void
    {
        if (self::EMBEDDING_DIMENSIONS !== \count($values)) {
            throw new \InvalidArgumentException(\sprintf('Embedding attendu sur %d dimensions, %d reçues.', self::EMBEDDING_DIMENSIONS, \count($values)));
        }

        $this->embedding = new Vector($values);
    }

    /**
     * Valeur d'un attribut, avec chemin pointé pour les blocs imbriqués (ex. "drinking_window.until").
     */
    public function attribute(string $path): mixed
    {
        $node = $this->attributes;
        foreach (explode('.', $path) as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    public function inStock(int $quantity = 1): bool
    {
        return $this->stock >= $quantity;
    }

    /**
     * Texte envoyé au modèle d'embedding.
     */
    public function embeddingText(): string
    {
        return implode(' ', array_filter([
            $this->brand,
            $this->name,
            $this->category,
            $this->description,
            implode(' ', $this->tags),
            AttributeText::flatten($this->attributes),
        ]));
    }

    /**
     * Synchronise les attributs catalogue depuis une définition fraîche (re-seed idempotent).
     * L'identité (id, sku) et l'embedding sont conservés.
     */
    public function syncFrom(self $other): void
    {
        $this->name = $other->name;
        $this->brand = $other->brand;
        $this->category = $other->category;
        $this->priceCents = $other->priceCents;
        $this->description = $other->description;
        $this->attributes = $other->attributes;
        $this->stock = $other->stock;
        $this->tags = $other->tags;
    }
}
