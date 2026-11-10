<?php

declare(strict_types=1);

namespace App\Crypto;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiffrement asymétrique anonyme (crypto_box_seal). Seule la clé privée du backend déchiffre.
 */
final readonly class ZeroPiiCryptoService
{
    public function __construct(
        #[Autowire(env: 'default::SODIUM_PRIVATE_KEY')]
        private ?string $privateKeyBase64 = null,
    ) {
    }

    /**
     * @return array{private_key: string, public_key: string} base64
     */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_box_keypair();
        $keys = [
            'private_key' => base64_encode(sodium_crypto_box_secretkey($pair)),
            'public_key' => base64_encode(sodium_crypto_box_publickey($pair)),
        ];
        sodium_memzero($pair);

        return $keys;
    }

    public function publicKey(): string
    {
        return base64_encode(sodium_crypto_box_publickey_from_secretkey($this->secretKey()));
    }

    /**
     * Équivalent côté serveur de ce que fait le frontend ; utile aux tests et outils.
     */
    public function seal(string $plaintext): string
    {
        return base64_encode(sodium_crypto_box_seal($plaintext, sodium_crypto_box_publickey_from_secretkey($this->secretKey())));
    }

    /**
     * @throws InvalidEncryptedPayloadException blob illisible, corrompu ou chiffré pour une autre clé
     */
    public function decrypt(string $encryptedBlobBase64): string
    {
        $secret = $this->secretKey();
        $blob = base64_decode($encryptedBlobBase64, true);
        if (false === $blob || '' === $blob) {
            throw new InvalidEncryptedPayloadException('Invalid encrypted payload.');
        }

        $keyPair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secret, sodium_crypto_box_publickey_from_secretkey($secret));
        $plaintext = sodium_crypto_box_seal_open($blob, $keyPair);
        sodium_memzero($keyPair);
        sodium_memzero($secret);

        if (false === $plaintext) {
            throw new InvalidEncryptedPayloadException('Invalid encrypted payload.');
        }

        return $plaintext;
    }

    private function secretKey(): string
    {
        $decoded = null === $this->privateKeyBase64 || '' === $this->privateKeyBase64 ? false : base64_decode($this->privateKeyBase64, true);
        if (false === $decoded || SODIUM_CRYPTO_BOX_SECRETKEYBYTES !== \strlen($decoded)) {
            throw new \LogicException('SODIUM_PRIVATE_KEY absente ou invalide : exécutez app:crypto:generate-keys.');
        }

        return $decoded;
    }
}
