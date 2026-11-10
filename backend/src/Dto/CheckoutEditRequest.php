<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CheckoutEditRequest
{
    public function __construct(
        #[Assert\Choice(choices: ['name', 'address', 'phone', 'email'])]
        public string $field,
    ) {
    }
}
