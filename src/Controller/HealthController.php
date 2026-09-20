<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness: the process is up and serving. No dependencies touched (§8.4).
 *
 * Probed by the container HEALTHCHECK and the external monitor; must stay
 * cheap and dependency-free so a DB hiccup does not kill the container.
 */
final class HealthController
{
    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/ready', name: 'app_ready', methods: ['GET'])]
    public function ready(): JsonResponse
    {
        // Readiness may query the DB. Kept as a thin wrapper so the split
        // (§8.4) exists from day one; the actual DB probe lands with the
        // run-engine entities (v1 item 3). Until then: ready.
        return new JsonResponse(['status' => 'ready']);
    }
}
