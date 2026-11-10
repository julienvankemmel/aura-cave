<?php

declare(strict_types=1);

namespace App\Compatibility;

use App\Enum\IssueSeverity;

final readonly class CompatibilityIssue
{
    public function __construct(
        public IssueSeverity $severity,
        public string $code,
        public string $message,
    ) {
    }

    /**
     * @return array{severity: string, code: string, message: string}
     */
    public function toArray(): array
    {
        return ['severity' => $this->severity->value, 'code' => $this->code, 'message' => $this->message];
    }
}
