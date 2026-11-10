<?php

declare(strict_types=1);

namespace App\Embedding;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Agent d'abord ; en cas d'indisponibilité (CI, agent arrêté), repli déterministe.
 * Un échec de l'agent est mémorisé pour ne pas retarder chaque appel suivant.
 */
#[AsAlias(EmbeddingProviderInterface::class)]
final class FallbackEmbeddingProvider implements EmbeddingProviderInterface
{
    private bool $agentDown = false;

    public function __construct(
        private readonly AgentEmbeddingClient $agent,
        private readonly DeterministicHashEmbedder $fallback,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function embedMany(array $texts): array
    {
        if ($this->agent->isConfigured() && !$this->agentDown) {
            try {
                return $this->agent->embedMany($texts);
            } catch (\Throwable $e) {
                $this->agentDown = true;
                $this->logger->warning('Service d\'embedding agent indisponible, repli déterministe.', ['exception' => $e::class]);
            }
        }

        return $this->fallback->embedMany($texts);
    }

    public function embed(string $text): array
    {
        if ($this->agent->isConfigured() && !$this->agentDown) {
            try {
                return $this->agent->embed($text);
            } catch (\Throwable $e) {
                $this->agentDown = true;
                $this->logger->warning('Service d\'embedding agent indisponible, repli déterministe.', ['exception' => $e::class]);
            }
        }

        return $this->fallback->embed($text);
    }
}
