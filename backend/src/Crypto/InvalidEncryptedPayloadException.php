<?php

declare(strict_types=1);

namespace App\Crypto;

/**
 * Message volontairement générique : ne jamais refléter le blob ni le clair.
 */
final class InvalidEncryptedPayloadException extends \RuntimeException
{
}
