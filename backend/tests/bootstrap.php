<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use App\Crypto\ZeroPiiCryptoService;

// Clé de test éphémère : jamais committée, différente à chaque exécution.
$_SERVER['SODIUM_PRIVATE_KEY'] = $_ENV['SODIUM_PRIVATE_KEY'] = ZeroPiiCryptoService::generateKeyPair()['private_key'];

// Crée la base de test et l'extension pgvector si nécessaire.
$url = (string) ($_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL'));
$parts = parse_url($url);
if (false === $parts || !isset($parts['host'], $parts['path'])) {
    return;
}
$dbName = ltrim($parts['path'], '/');
$dsn = static fn (string $db): string => sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'], $parts['port'] ?? 5432, $db);
$user = urldecode($parts['user'] ?? '');
$pass = urldecode($parts['pass'] ?? '');

$admin = new PDO($dsn('postgres'), $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$exists = $admin->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
$exists->execute([$dbName]);
if (false === $exists->fetchColumn()) {
    $admin->exec(sprintf('CREATE DATABASE "%s"', str_replace('"', '', $dbName)));
}
$db = new PDO($dsn($dbName), $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE EXTENSION IF NOT EXISTS vector');
