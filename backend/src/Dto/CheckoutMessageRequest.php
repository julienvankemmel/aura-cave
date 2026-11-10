<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Message brut du client pendant le checkout : contient de la donnée personnelle, ne doit jamais être journalisé.
 */
final readonly class CheckoutMessageRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 500)]
        #[\SensitiveParameter]
        public string $text,
    ) {
    }
}
