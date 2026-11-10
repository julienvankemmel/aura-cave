<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Sommelier\WineSommelierRerankerService;
use App\Tests\Support\ProductFactory as F;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WineSommelierRerankerServiceTest extends TestCase
{
    private const MODELS = 'gemini-3.8-flash,gemini-3.6-flash,gemini-3.5-flash-lite,gemini-3.1-flash-lite';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /**
     * @param list<MockResponse|\Throwable> $script une entrée par appel, dans l'ordre
     */
    private function service(array $script, string $apiKey = 'test-key', ?LoggerInterface $logger = null): WineSommelierRerankerService
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$script): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            $next = array_shift($script) ?? new MockResponse('', ['http_code' => 500]);
            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $next;
        });

        return new WineSommelierRerankerService($client, $logger ?? $this->createStub(LoggerInterface::class), $apiKey, self::MODELS, 4.5, 0);
    }

    /**
     * @param list<array<string, mixed>> $selection
     */
    private static function gemini(array $selection): MockResponse
    {
        $text = json_encode(['recommended_selection' => $selection], JSON_THROW_ON_ERROR);

        return new MockResponse(json_encode(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]], JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function pick(string $sku, int $score, bool $favourite = false): array
    {
        return ['sku' => $sku, 'match_score' => $score, 'sommelier_justification' => 'Tanins soyeux, épices et fruits noirs : le mariage idéal avec la grillade.', 'is_coup_de_coeur' => $favourite];
    }

    public function testFallsBackToTheSecondModelOnA503AndReturnsASchemaCompliantResult(): void
    {
        $pool = [F::wine('A'), F::wine('B'), F::wine('C')];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('Modèle gemini-3.8-flash indisponible (erreur 503), passage au fallback suivant'));

        $service = $this->service([
            new MockResponse('{"error":{"code":503}}', ['http_code' => 503]),
            self::gemini([self::pick('B', 95, true), self::pick('A', 80), self::pick('C', 60)]),
        ], logger: $logger);

        $result = $service->rerank('mouton au barbecue', $pool, 3);

        self::assertNotNull($result);
        self::assertSame('gemini-3.6-flash', $result->model);
        self::assertSame(['B', 'A', 'C'], array_map(static fn ($w) => $w->sku, $result->ranking));
        self::assertSame([95, 80, 60], array_map(static fn ($w) => $w->matchScore, $result->ranking));
        self::assertSame([true, false, false], array_map(static fn ($w) => $w->coupDeCoeur, $result->ranking));
        self::assertStringContainsString('Tanins soyeux', $result->ranking[0]->justification);

        self::assertCount(2, $this->requests);
        self::assertStringContainsString('/models/gemini-3.8-flash:generateContent', $this->requests[0]['url']);
        self::assertStringContainsString('/models/gemini-3.6-flash:generateContent', $this->requests[1]['url']);
        self::assertStringNotContainsString('test-key', $this->requests[1]['url'], 'la clé ne voyage pas dans l\'URL');
        self::assertStringContainsString('x-goog-api-key: test-key', implode("\n", $this->requests[1]['options']['headers']));

        $body = json_decode((string) $this->requests[1]['options']['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('application/json', $body['generationConfig']['responseMimeType']);
        self::assertSame(0.2, $body['generationConfig']['temperature']);
        self::assertStringContainsString('SKU', $body['systemInstruction']['parts'][0]['text']);
        self::assertStringContainsString('mouton au barbecue', $body['contents'][0]['parts'][0]['text']);
    }

    public function testIgnoresUnknownSkusDuplicatesAndClampsScores(): void
    {
        $service = $this->service([self::gemini([
            self::pick('INVENTE-123', 99, true),
            self::pick('a', 140),
            self::pick('A', 70),
            self::pick('B', -5),
        ])]);

        $result = $service->rerank('agneau', [F::wine('A'), F::wine('B')], 3);

        self::assertNotNull($result);
        self::assertSame(['A', 'B'], array_map(static fn ($w) => $w->sku, $result->ranking));
        self::assertSame([100, 0], array_map(static fn ($w) => $w->matchScore, $result->ranking));
        self::assertSame([true, false], array_map(static fn ($w) => $w->coupDeCoeur, $result->ranking), 'à défaut de coup de cœur valide, le premier du classement l\'est');
    }

    public function testMovesOnWhenAModelAnswersWithInventedSkusOnly(): void
    {
        $service = $this->service([
            self::gemini([self::pick('FAKE', 90)]),
            self::gemini([self::pick('A', 88, true)]),
        ]);

        $result = $service->rerank('agneau', [F::wine('A')], 3);

        self::assertSame('gemini-3.6-flash', $result?->model);
    }

    public function testCascadesThroughEveryModelThenGivesUp(): void
    {
        $service = $this->service([
            new MockResponse('', ['http_code' => 429]),
            new TransportException('timeout'),
            new MockResponse('pas du json', ['http_code' => 200]),
            new MockResponse('', ['http_code' => 404]),
        ]);

        self::assertNull($service->rerank('agneau', [F::wine('A')], 3));
        self::assertCount(4, $this->requests);
        self::assertStringContainsString('gemini-3.1-flash-lite', $this->requests[3]['url']);
    }

    public function testDoesNothingWithoutApiKeyOrCandidates(): void
    {
        $noKey = $this->service([], '');
        self::assertFalse($noKey->isConfigured());
        self::assertNull($noKey->rerank('agneau', [F::wine('A')], 3));

        self::assertNull($this->service([])->rerank('agneau', [], 3));
        self::assertSame([], $this->requests);
    }
}
