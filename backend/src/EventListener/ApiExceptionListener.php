<?php

declare(strict_types=1);

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Erreurs JSON uniformes et sobres sur /api/* : jamais de valeur soumise ni de trace.
 */
#[AsEventListener(event: 'kernel.exception')]
final readonly class ApiExceptionListener
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $e = $event->getThrowable();
        if (!$e instanceof HttpExceptionInterface) {
            // Classe et fichier seulement : le message pourrait contenir des données de requête.
            $this->logger->error('Unhandled API exception.', ['exception' => $e::class, 'file' => $e->getFile(), 'line' => $e->getLine()]);
            $event->setResponse(new JsonResponse(['error' => ['code' => 'internal_error', 'message' => 'Internal error.']], 500));

            return;
        }

        $error = ['code' => $this->codeFor($e->getStatusCode()), 'message' => 422 === $e->getStatusCode() ? 'Invalid payload.' : $e->getMessage()];
        $previous = $e->getPrevious();
        if ($previous instanceof ValidationFailedException) {
            $violations = [];
            foreach ($previous->getViolations() as $violation) {
                $violations[] = ['field' => $violation->getPropertyPath(), 'message' => (string) $violation->getMessage()];
            }
            $error['violations'] = $violations;
        }

        $event->setResponse(new JsonResponse(['error' => $error], $e->getStatusCode(), $e->getHeaders()));
    }

    private function codeFor(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthorized',
            404 => 'not_found',
            405 => 'method_not_allowed',
            422 => 'validation_failed',
            429 => 'too_many_requests',
            default => 'http_error',
        };
    }
}
