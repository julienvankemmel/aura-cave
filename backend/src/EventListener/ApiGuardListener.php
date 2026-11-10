<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Rate limiting par IP puis authentification inter-services (X-Internal-Api-Key) sur /api/*.
 * Seule la clé publique de chiffrement est accessible sans clé.
 */
#[AsEventListener(event: 'kernel.request', priority: 32)]
final readonly class ApiGuardListener
{
    private const PUBLIC_PATHS = ['/api/crypto/public-key'];

    public function __construct(
        #[Autowire(service: 'limiter.api')]
        private RateLimiterFactory $apiLimiter,
        #[Autowire(env: 'default::INTERNAL_API_KEY')]
        private ?string $apiKey,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $limit = $this->apiLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $retry = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            throw new TooManyRequestsHttpException($retry, 'Too many requests.');
        }

        if (\in_array($request->getPathInfo(), self::PUBLIC_PATHS, true)) {
            return;
        }

        $provided = (string) $request->headers->get('X-Internal-Api-Key', '');
        // Fail closed : sans clé configurée, tout est refusé.
        if (null === $this->apiKey || '' === $this->apiKey || !hash_equals($this->apiKey, $provided)) {
            throw new UnauthorizedHttpException('ApiKey', 'Unauthorized.');
        }
    }
}
