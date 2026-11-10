<?php

declare(strict_types=1);

namespace App\Embedding;

use App\Entity\Product;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Appelle l'endpoint interne d'embedding du service /agent (fastembed, BAAI/bge-small-en-v1.5).
 */
final readonly class AgentEmbeddingClient implements EmbeddingProviderInterface
{
    /** Taille maximale d'un lot acceptée par /internal/embed. */
    private const BATCH_SIZE = 64;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'default::AGENT_URL')]
        private ?string $agentUrl,
        #[Autowire(env: 'default::INTERNAL_API_KEY')]
        private ?string $apiKey,
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->agentUrl && '' !== $this->agentUrl;
    }

    public function embed(string $text): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('AGENT_URL non configurée.');
        }

        $response = $this->httpClient->request('POST', rtrim((string) $this->agentUrl, '/').'/internal/embed', [
            'json' => ['text' => $text],
            'headers' => ['X-Internal-Api-Key' => (string) $this->apiKey],
            'timeout' => 3,
            'max_duration' => 8,
        ]);

        $data = $response->toArray();
        $embedding = $data['embedding'] ?? null;
        if (!\is_array($embedding) || Product::EMBEDDING_DIMENSIONS !== \count($embedding)) {
            throw new \UnexpectedValueException('Réponse d\'embedding invalide.');
        }

        return array_values(array_map(static fn (mixed $v): float => (float) $v, $embedding));
    }

    public function embedMany(array $texts): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('AGENT_URL non configurée.');
        }

        $vectors = [];
        foreach (array_chunk($texts, self::BATCH_SIZE) as $chunk) {
            $response = $this->httpClient->request('POST', rtrim((string) $this->agentUrl, '/').'/internal/embed', [
                'json' => ['texts' => $chunk],
                'headers' => ['X-Internal-Api-Key' => (string) $this->apiKey],
                'timeout' => 60,
                'max_duration' => 120,
            ]);
            $embeddings = $response->toArray()['embeddings'] ?? null;
            if (!\is_array($embeddings) || \count($embeddings) !== \count($chunk)) {
                throw new \UnexpectedValueException('Réponse d\'embedding par lot invalide.');
            }
            foreach ($embeddings as $embedding) {
                if (!\is_array($embedding) || Product::EMBEDDING_DIMENSIONS !== \count($embedding)) {
                    throw new \UnexpectedValueException('Réponse d\'embedding par lot invalide.');
                }
                $vectors[] = array_values(array_map(static fn (mixed $v): float => (float) $v, $embedding));
            }
        }

        return $vectors;
    }
}
