<?php

declare(strict_types=1);

namespace App\Controller;

use App\Session\SessionAlarm;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness: the process is up and serving. No dependencies touched (§8.4).
 *
 * Probed by the container HEALTHCHECK and the external monitor; must stay
 * cheap and dependency-free so a DB hiccup does not kill the container.
 *
 * That constraint is why this controller reads a FILE ({@see SessionAlarm})
 * rather than asking the session engine anything. A stopped session is a real
 * operational failure — the work has halted and needs a human — and it must be
 * visible at the level people actually monitor, which is the container. But
 * answering "is any session stopped?" from the database would put a DB query
 * behind /health and hand a database hiccup the power to mark the container
 * unhealthy. The heartbeat writes the sentinel; this reads it. Same projection
 * the entrypoint already uses for `$DATA_DIR/fleet-id`.
 *
 * The distinction being drawn is worth keeping straight: /health reports
 * whether the PROCESS is healthy in its own terms, and "a session has stopped
 * and needs attention" is a fault the process is correctly reporting, not a
 * reason to stop serving. So this returns 503 — the container goes
 * `unhealthy`, `docker ps` shows it, an external monitor alerts — while the
 * web server and the fleet keep running so the operator can open the UI and
 * act. Nothing here kills anything.
 */
final class HealthController
{
    public function __construct(private readonly SessionAlarm $alarm)
    {
    }

    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        $alarm = $this->alarm->read();

        if (null === $alarm) {
            return new JsonResponse(['status' => 'ok']);
        }

        // 503 with the reason attached: a monitor that only looks at the code
        // still learns what is wrong, and an operator curling /health gets the
        // same sentence the alarm carries.
        return new JsonResponse(
            [
                'status' => 'unhealthy',
                'reason' => 'a session has stopped and needs a human',
                'sessions' => $alarm['sessions'],
            ],
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
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
