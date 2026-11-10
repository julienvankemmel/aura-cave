<?php

declare(strict_types=1);

namespace App\Checkout;

use App\Enum\CheckoutStepEnum;

/**
 * Résultat du traitement d'un message de checkout. Ne contient JAMAIS la donnée saisie : `llmMessage` est le seul
 * texte destiné au LLM (jeton d'anonymisation ou marqueur d'invalidité).
 */
final readonly class CheckoutTurn
{
    public const INVALID_TOKEN = '[INVALID_INPUT]';
    public const EDIT_TOKEN = '[EDIT_REQUEST]';

    public function __construct(
        public bool $accepted,
        public CheckoutStepEnum $step,
        public string $llmMessage,
        public string $instruction,
        public ?string $orderReference = null,
        public ?int $totalCents = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'accepted' => $this->accepted,
            'step' => $this->step->value,
            'llm_message' => $this->llmMessage,
            'instruction' => $this->instruction,
            'order' => null === $this->orderReference ? null : ['reference' => $this->orderReference, 'total_cents' => $this->totalCents],
        ];
    }
}
