<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Embedding\EmbeddingProviderInterface;
use App\Entity\Product;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Charge `data/catalog_wine.json` (schéma v3 : colonnes communes + bloc `attributes` propre au vin).
 */
final readonly class CatalogLoader
{
    private const BATCH_SIZE = 64;
    /** Limite de /internal/embed côté agent : 2000 caractères par texte. */
    private const MAX_EMBEDDING_CHARS = 1800;

    public function __construct(
        private EntityManagerInterface $em,
        private EmbeddingProviderInterface $embeddings,
        #[Autowire('%kernel.project_dir%/data')]
        private string $dataDir,
    ) {
    }

    public function defaultPath(): string
    {
        return $this->dataDir.'/catalog_wine.json';
    }

    /**
     * Upsert idempotent par SKU, puis calcul des embeddings.
     * Avec `$prune`, les produits absents du fichier sont supprimés, sauf s'ils figurent dans un panier.
     *
     * @return array{created: int, updated: int, pruned: int, kept_in_carts: int}
     */
    public function load(string $path, bool $prune = false): array
    {
        if (!is_readable($path)) {
            throw new \RuntimeException(\sprintf('Catalogue illisible : %s', $path));
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !isset($decoded['products']) || !\is_array($decoded['products'])) {
            throw new \UnexpectedValueException('Clé "products" manquante dans le catalogue.');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values(array_filter($decoded['products'], static fn (mixed $r): bool => \is_array($r)));
        if (\count($rows) !== \count($decoded['products'])) {
            throw new \UnexpectedValueException('Entrée de catalogue invalide.');
        }

        // Un fichier peut déclarer les catégories dont il est la source (`scope_categories`) : le nettoyage ne
        // touche alors qu'elles (ex. accessoires du vin, dont les bouteilles viennent de l'import CSV).
        $scope = $decoded['scope_categories'] ?? null;

        return $this->loadRows($rows, $prune, null, \is_array($scope) ? array_values(array_map('strval', $scope)) : null);
    }

    /**
     * Cœur de l'import, partagé par le catalogue JSON et l'import CSV : traite les lignes par lots (une requête de
     * lecture, un appel d'embedding groupé et un flush par lot). Un produit dont le texte d'embedding n'a pas changé
     * garde son vecteur, ce qui rend un re-seed quasi instantané.
     * Le nettoyage porte sur tout le catalogue, ou seulement sur `$pruneCategories` quand la source n'en couvre qu'une partie.
     *
     * @param iterable<array<string, mixed>> $rows
     * @param (callable(int): void)|null     $onProgress      appelé avec le nombre de lignes traitées après chaque lot
     * @param list<string>|null              $pruneCategories catégories gérées par la source (null : tout le catalogue)
     *
     * @return array{created: int, updated: int, pruned: int, kept_in_carts: int}
     */
    public function loadRows(iterable $rows, bool $prune = false, ?callable $onProgress = null, ?array $pruneCategories = null): array
    {
        $created = $updated = $processed = 0;
        $seen = [];
        $repo = $this->em->getRepository(Product::class);

        foreach ($this->chunks($rows, self::BATCH_SIZE) as $chunk) {
            $fresh = array_map(fn (array $row): Product => $this->hydrate($row), $chunk);
            $skus = array_map(static fn (Product $p): string => $p->sku, $fresh);
            /** @var array<string, Product> $existing */
            $existing = [];
            foreach ($repo->findBy(['sku' => $skus]) as $found) {
                $existing[$found->sku] = $found;
            }

            /** @var list<array{Product, string}> $pending produits à vectoriser et leur texte */
            $pending = [];
            foreach ($fresh as $product) {
                $seen[$product->sku] = true;
                $text = mb_substr($product->embeddingText(), 0, self::MAX_EMBEDDING_CHARS);
                $current = $existing[$product->sku] ?? null;

                if ($current instanceof Product) {
                    $unchanged = null !== $current->embedding
                        && mb_substr($current->embeddingText(), 0, self::MAX_EMBEDDING_CHARS) === $text;
                    $current->syncFrom($product);
                    ++$updated;
                    if ($unchanged) {
                        continue;
                    }
                    $pending[] = [$current, $text];
                } else {
                    $this->em->persist($product);
                    ++$created;
                    $pending[] = [$product, $text];
                }
            }

            if ([] !== $pending) {
                $vectors = $this->embeddings->embedMany(array_map(static fn (array $p): string => $p[1], $pending));
                foreach ($pending as $i => [$product]) {
                    $product->setEmbedding($vectors[$i]);
                }
            }

            $this->em->flush();
            $this->em->clear();
            $processed += \count($chunk);
            if (null !== $onProgress) {
                $onProgress($processed);
            }
        }

        [$pruned, $keptInCarts] = $prune ? $this->prune($seen, $pruneCategories) : [0, 0];

        return ['created' => $created, 'updated' => $updated, 'pruned' => $pruned, 'kept_in_carts' => $keptInCarts];
    }

    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return \Generator<int, list<array<string, mixed>>>
     */
    private function chunks(iterable $rows, int $size): \Generator
    {
        $chunk = [];
        foreach ($rows as $row) {
            $chunk[] = $row;
            if (\count($chunk) === $size) {
                yield $chunk;
                $chunk = [];
            }
        }
        if ([] !== $chunk) {
            yield $chunk;
        }
    }

    /**
     * @param array<string, true> $seenSkus
     * @param list<string>|null   $categories catégories nettoyées (null : tout le catalogue)
     *
     * @return array{int, int} [supprimés, conservés car présents dans un panier]
     */
    private function prune(array $seenSkus, ?array $categories): array
    {
        if ([] === $categories) {
            return [0, 0];
        }
        $connection = $this->em->getConnection();
        $pruned = $kept = 0;
        $sql = 'SELECT p.id, p.sku, EXISTS (SELECT 1 FROM cart_item ci WHERE ci.product_id = p.id) AS in_cart FROM product p';
        /** @var list<array{id: string, sku: string, in_cart: bool}> $rows */
        $rows = null === $categories
            ? $connection->fetchAllAssociative($sql)
            : $connection->fetchAllAssociative($sql.' WHERE p.category IN (?)', [$categories], [ArrayParameterType::STRING]);
        foreach ($rows as $row) {
            if (isset($seenSkus[$row['sku']])) {
                continue;
            }
            if ($row['in_cart']) {
                ++$kept;
                continue;
            }
            $connection->executeStatement('DELETE FROM product WHERE id = ?', [$row['id']]);
            ++$pruned;
        }
        $this->em->clear();

        return [$pruned, $kept];
    }

    /**
     * @param array<string, mixed> $r
     */
    private function hydrate(array $r): Product
    {
        $str = static fn (mixed $v): ?string => \is_string($v) && '' !== $v ? $v : null;
        $attributes = $r['attributes'] ?? [];
        $tags = $r['tags'] ?? [];

        return new Product(
            sku: $str($r['sku'] ?? null) ?? throw new \UnexpectedValueException('sku manquant'),
            name: $str($r['name'] ?? null) ?? throw new \UnexpectedValueException('name manquant'),
            brand: $str($r['brand'] ?? null) ?? throw new \UnexpectedValueException('brand manquant'),
            category: $str($r['category'] ?? null) ?? throw new \UnexpectedValueException('category manquant'),
            priceCents: is_numeric($r['price_cents'] ?? null) ? (int) $r['price_cents'] : throw new \UnexpectedValueException('price_cents manquant'),
            description: $str($r['description'] ?? null) ?? '',
            attributes: \is_array($attributes) ? $attributes : throw new \UnexpectedValueException('attributes doit être un objet'),
            stock: max(0, is_numeric($r['stock'] ?? null) ? (int) $r['stock'] : 0),
            tags: \is_array($tags) ? array_values(array_map(static fn (mixed $x): string => (string) $x, $tags)) : [],
        );
    }
}
