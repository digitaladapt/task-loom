<?php

declare(strict_types=1);

namespace App\Observability;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Stamps every Monolog record with the current request ID (§8.5).
 *
 * Reads the ID from the request attribute set by RequestIdSubscriber, so
 * every log line emitted while handling a request — app, security, doctrine
 * — carries the same ID that is echoed to the client in X-Request-Id.
 */
final class RequestIdProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    #[\Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        $request = $this->requestStack->getCurrentRequest();

        $requestId = $request?->attributes->get(RequestIdSubscriber::REQUEST_ATTRIBUTE);

        if (\is_string($requestId) && '' !== $requestId) {
            $record->extra['request_id'] = $requestId;
        }

        return $record;
    }
}
