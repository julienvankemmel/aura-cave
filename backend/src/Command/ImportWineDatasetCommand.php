<?php

declare(strict_types=1);

namespace App\Command;

use App\Catalog\CatalogLoader;
use App\Catalog\Wine\WineDatasetReader;
use App\Catalog\Wine\WineImportStats;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:import-wine-dataset',
    description: 'Importe wine_info.csv (Kaggle dev7halo/wine-information) dans le catalogue : nettoyage, mapping vers socle + attributes, embeddings 384d.',
)]
final readonly class ImportWineDatasetCommand
{
    public const DEFAULT_KRW_PER_EUR = 1450.0;

    public function __construct(
        private WineDatasetReader $reader,
        private CatalogLoader $loader,
        #[Autowire('%kernel.project_dir%/data/raw/wine_info.csv')]
        private string $defaultFile,
        #[Autowire(env: 'default::WINE_KRW_PER_EUR')]
        private ?string $envRate,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Chemin du CSV (défaut : data/raw/wine_info.csv)')] ?string $file = null,
        #[Option(description: 'Taux de conversion won coréen par euro (défaut : WINE_KRW_PER_EUR, sinon 1450)')] ?float $krwPerEur = null,
        #[Option(description: 'Ne traite que les N premiers vins importables')] ?int $limit = null,
        #[Option(description: 'Lit et nettoie sans écrire en base (affiche les statistiques et un exemple)')] bool $dryRun = false,
        #[Option(description: 'Supprime les vins absents du CSV (sauf ceux présents dans un panier)')] bool $prune = false,
    ): int {
        $rate = $krwPerEur ?? (is_numeric($this->envRate) ? (float) $this->envRate : self::DEFAULT_KRW_PER_EUR);
        $file ??= $this->defaultFile;
        $stats = new WineImportStats();

        try {
            $rows = $this->reader->rows($file, $rate, $stats, $limit);

            if ($dryRun) {
                $sample = null;
                foreach ($rows as $row) {
                    $sample ??= $row;
                }
                $this->report($io, $stats, $rate);
                $io->section('Exemple de ligne mappée');
                $io->writeln((string) json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return Command::SUCCESS;
            }

            $progress = $io->createProgressBar();
            $progress->setFormat(' %current% vins traités [%bar%] %elapsed:6s%');
            $progress->start();
            $result = $this->loader->loadRows($rows, $prune, static function (int $processed) use ($progress): void {
                $progress->setProgress($processed);
            }, ['wine']);
            $progress->finish();
            $io->newLine(2);
        } catch (\RuntimeException|\InvalidArgumentException|\UnexpectedValueException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->report($io, $stats, $rate);
        $io->success(\sprintf('Vins : %d créés, %d mis à jour (embeddings 384d calculés ou conservés).', $result['created'], $result['updated']));
        if ($prune) {
            $io->writeln(\sprintf('Nettoyage : %d vin(s) retiré(s) du catalogue.', $result['pruned']));
            if ($result['kept_in_carts'] > 0) {
                $io->warning(\sprintf('%d vin(s) obsolète(s) conservé(s) car présents dans un panier.', $result['kept_in_carts']));
            }
        }

        return Command::SUCCESS;
    }

    private function report(SymfonyStyle $io, WineImportStats $stats, float $rate): void
    {
        $io->definitionList(...array_map(
            static fn (string $label, int $count): array => [$label => number_format($count, 0, ',', ' ')],
            array_keys($stats->toArray()),
            array_values($stats->toArray()),
        ));
        $io->note(\sprintf('Prix convertis de wons coréens (KRW) en euros au taux de %s KRW/EUR ; stock simulé (absent du jeu de données).', number_format($rate, 0, ',', ' ')));
    }
}
