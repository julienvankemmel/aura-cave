<?php

declare(strict_types=1);

namespace App\Command;

use App\Catalog\CatalogLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:seed-catalog', description: 'Charge data/catalog_wine.json et calcule les embeddings 384d (agent, sinon repli déterministe).')]
final readonly class SeedCatalogCommand
{
    public function __construct(private CatalogLoader $loader)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Chemin du catalogue JSON (défaut : data/catalog_wine.json)')] ?string $file = null,
        #[Option(description: 'Supprime les produits absents du fichier (sauf ceux présents dans un panier)')] bool $prune = false,
    ): int {
        $result = $this->loader->load($file ?? $this->loader->defaultPath(), $prune);
        $io->success(\sprintf('Catalogue chargé : %d créés, %d mis à jour.', $result['created'], $result['updated']));
        if ($prune) {
            $io->writeln(\sprintf('Nettoyage : %d produit(s) retiré(s) du catalogue.', $result['pruned']));
            if ($result['kept_in_carts'] > 0) {
                $io->warning(\sprintf('%d produit(s) obsolète(s) conservé(s) car présents dans un panier.', $result['kept_in_carts']));
            }
        }

        return Command::SUCCESS;
    }
}
