<?php

declare(strict_types=1);

namespace App\Command;

use App\Crypto\ZeroPiiCryptoService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:crypto:generate-keys', description: 'Génère une paire de clés libsodium (crypto_box) à placer dans .env.')]
final class GenerateKeysCommand
{
    public function __invoke(SymfonyStyle $io): int
    {
        $keys = ZeroPiiCryptoService::generateKeyPair();

        // Sortie brute (stdout) pour redirection ; jamais écrite sur disque ni journalisée.
        $io->writeln('SODIUM_PRIVATE_KEY='.$keys['private_key']);
        $io->writeln('SODIUM_PUBLIC_KEY='.$keys['public_key']);
        $io->note('La clé privée ne doit exister que côté backend Symfony.');

        return Command::SUCCESS;
    }
}
