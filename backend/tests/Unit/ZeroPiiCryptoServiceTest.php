<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Crypto\InvalidEncryptedPayloadException;
use App\Crypto\ZeroPiiCryptoService;
use PHPUnit\Framework\TestCase;

final class ZeroPiiCryptoServiceTest extends TestCase
{
    private ZeroPiiCryptoService $service;

    /** @var array{private_key: string, public_key: string} */
    private array $keys;

    protected function setUp(): void
    {
        $this->keys = ZeroPiiCryptoService::generateKeyPair();
        $this->service = new ZeroPiiCryptoService($this->keys['private_key']);
    }

    public function testFullCycleClientEncryptsWithPublicKeyBackendDecrypts(): void
    {
        $plaintext = '{"full_name":"Jeanne Martin","email":"jeanne@example.test"}';

        // Ce que fait le frontend (crypto_box_seal avec la clé publique exposée).
        $publicKey = base64_decode($this->service->publicKey(), true);
        self::assertIsString($publicKey);
        $blob = base64_encode(sodium_crypto_box_seal($plaintext, $publicKey));

        self::assertStringNotContainsString('Jeanne', $blob);
        self::assertSame($plaintext, $this->service->decrypt($blob));
    }

    public function testPublicKeyIsDerivedFromPrivateKey(): void
    {
        self::assertSame($this->keys['public_key'], $this->service->publicKey());
    }

    public function testSealIsNonDeterministic(): void
    {
        self::assertNotSame($this->service->seal('x'), $this->service->seal('x'));
    }

    public function testCorruptedBlobIsRejected(): void
    {
        $raw = base64_decode($this->service->seal('secret'), true);
        self::assertIsString($raw);
        $raw[\strlen($raw) - 1] = $raw[\strlen($raw) - 1] ^ "\x01";

        $this->expectException(InvalidEncryptedPayloadException::class);
        $this->service->decrypt(base64_encode($raw));
    }

    public function testTruncatedAndNonBase64BlobsAreRejected(): void
    {
        foreach (['', '!!!not-base64!!!', base64_encode('short')] as $blob) {
            try {
                $this->service->decrypt($blob);
                self::fail('Blob invalide accepté : '.$blob);
            } catch (InvalidEncryptedPayloadException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testBlobSealedForAnotherKeyIsRejected(): void
    {
        $other = new ZeroPiiCryptoService(ZeroPiiCryptoService::generateKeyPair()['private_key']);

        $this->expectException(InvalidEncryptedPayloadException::class);
        $this->service->decrypt($other->seal('secret'));
    }

    public function testExceptionMessageNeverLeaksBlob(): void
    {
        try {
            $this->service->decrypt('QUJDREVGR0g=');
            self::fail('Exception attendue.');
        } catch (InvalidEncryptedPayloadException $e) {
            self::assertStringNotContainsString('QUJDREVGR0g', $e->getMessage());
        }
    }

    public function testMissingOrInvalidPrivateKeyFailsClosed(): void
    {
        foreach ([null, '', 'not-a-key', base64_encode('too short')] as $key) {
            try {
                (new ZeroPiiCryptoService($key))->publicKey();
                self::fail('Clé invalide acceptée.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
