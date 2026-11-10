<?php

declare(strict_types=1);

namespace App\Repository;

use App\Catalog\AttributeFilter;
use App\Entity\Product;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Pgvector\Vector;
use Symfony\Component\Uid\Uuid;

final readonly class ProductSearch
{
    /** Similarité cosinus minimale pour considérer un résultat vectoriel pertinent. */
    public const MIN_SIMILARITY = 0.15;

    /** Candidats examinés quand des filtres d'attributs s'appliquent après la similarité. */
    private const FILTER_POOL = 400;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Filtres stricts budget / catégorie (et stock si `$inStockOnly`), puis filtres d'attributs appliqués après la similarité.
     *
     * @param list<float>           $embedding
     * @param list<AttributeFilter> $filters
     *
     * @return list<array{product: Product, score: float}>
     */
    public function byVector(array $embedding, ?int $budgetCents, ?string $category, int $limit, array $filters = [], ?float $minSimilarity = null, bool $inStockOnly = false): array
    {
        $strict = [] !== $filters;
        $qb = $this->em->createQueryBuilder()
            ->select('p', 'cosine_distance(p.embedding, :q) AS distance')
            ->from(Product::class, 'p')
            ->where('p.embedding IS NOT NULL')
            ->setParameter('q', new Vector($embedding), 'vector')
            ->orderBy('distance', 'ASC')
            ->addOrderBy('p.sku', 'ASC')
            ->setMaxResults($strict ? self::FILTER_POOL : $limit * 3);
        $this->applyFilters($qb, $budgetCents, $category, $inStockOnly);

        $rows = [];
        /** @var list<array{0: Product, distance: numeric-string|float}> $result */
        $result = $qb->getQuery()->getResult();
        foreach ($result as $row) {
            $score = round(1.0 - (float) $row['distance'], 4);
            // Filtres posés = critères stricts : la similarité ne sert plus qu'à classer, pas à exclure.
            $relevant = $strict || $score >= ($minSimilarity ?? self::MIN_SIMILARITY);
            if ($relevant && $this->matchesAll($row[0], $filters)) {
                $rows[] = ['product' => $row[0], 'score' => $score];
            }
        }

        return \array_slice($rows, 0, $limit);
    }

    /**
     * @param list<AttributeFilter> $filters
     *
     * @return list<array{product: Product, score: float}>
     */
    public function byFullText(string $query, ?int $budgetCents, ?string $category, int $limit, array $filters = []): array
    {
        $tokens = array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn (string $t): bool => mb_strlen($t) >= 2,
        )));
        $tokens = \array_slice($tokens, 0, 8);
        if ([] === $tokens) {
            return [];
        }

        $qb = $this->em->createQueryBuilder()->select('p')->from(Product::class, 'p');
        $or = $qb->expr()->orX();
        foreach ($tokens as $i => $token) {
            $or->add("LOWER(p.name) LIKE :t$i OR LOWER(p.brand) LIKE :t$i OR LOWER(p.description) LIKE :t$i");
            $qb->setParameter("t$i", '%'.addcslashes($token, '%_\\').'%');
        }
        $qb->where($or)->setMaxResults(200);
        $this->applyFilters($qb, $budgetCents, $category);

        $scored = [];
        /** @var list<Product> $products */
        $products = $qb->getQuery()->getResult();
        foreach ($products as $product) {
            if (!$this->matchesAll($product, $filters)) {
                continue;
            }
            $haystack = mb_strtolower($product->name.' '.$product->brand.' '.$product->description);
            $hits = \count(array_filter($tokens, static fn (string $t): bool => str_contains($haystack, $t)));
            $scored[] = ['product' => $product, 'score' => round($hits / \count($tokens), 4)];
        }
        usort($scored, static fn (array $a, array $b): int => [$b['score'], $a['product']->sku] <=> [$a['score'], $b['product']->sku]);

        return \array_slice($scored, 0, $limit);
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return array<string, Product> indexé par UUID RFC 4122
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Product> $products */
        $products = $this->em->createQueryBuilder()
            ->select('p')->from(Product::class, 'p')
            ->where('p.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (Uuid $u): string => $u->toRfc4122(), $ids), ArrayParameterType::STRING)
            ->getQuery()->getResult();

        $indexed = [];
        foreach ($products as $product) {
            $indexed[$product->id->toRfc4122()] = $product;
        }

        return $indexed;
    }

    /**
     * @param list<AttributeFilter> $filters
     */
    private function matchesAll(Product $product, array $filters): bool
    {
        foreach ($filters as $filter) {
            if (!$filter->matches($product)) {
                return false;
            }
        }

        return true;
    }

    private function applyFilters(\Doctrine\ORM\QueryBuilder $qb, ?int $budgetCents, ?string $category, bool $inStockOnly = false): void
    {
        if ($inStockOnly) {
            $qb->andWhere('p.stock > 0');
        }
        if (null !== $budgetCents) {
            $qb->andWhere('p.priceCents <= :budget')->setParameter('budget', $budgetCents);
        }
        if (null !== $category) {
            $qb->andWhere('p.category = :category')->setParameter('category', $category);
        }
    }
}
