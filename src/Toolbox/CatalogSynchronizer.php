<?php

declare(strict_types=1);

namespace App\Toolbox;

use App\Entity\McpServer;
use App\Entity\Tool;
use App\Repository\McpServerRepository;
use App\Repository\ToolRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Synchronizes the tool catalog from registered servers (SPEC §7).
 *
 * The three merge guarantees, verbatim from the spec:
 *
 *   1. Explicit/pinned definitions win over discovered ones.
 *   2. Drift is logged.
 *   3. A down server never wipes known tools.
 *
 * Each server syncs in its own transaction, so one bad server cannot poison
 * the catalog for the others.
 */
final class CatalogSynchronizer
{
    public function __construct(
        private readonly McpServerRepository $servers,
        private readonly ToolRepository $tools,
        private readonly EntityManagerInterface $em,
        private readonly ServerReaderRegistry $readers,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Sync one server. Never throws — a server failure is an outcome, not an
     * exception (recorded on the server row; its Tool rows are untouched).
     */
    public function syncServer(McpServer $server): SyncResult
    {
        $reader = $this->readers->forServer($server);
        if (null === $reader) {
            $server->recordSyncOutcome($this->clock->now(), \sprintf('no reader for protocol %s', $server->getProtocol()->value));
            $this->em->flush();
            $this->logger->warning('Tool catalog sync skipped for {server}: {reason}', [
                'server' => $server->getName(),
                'reason' => 'no reader registered for its protocol',
            ]);

            return new SyncResult($server, false, 0, 0, 0, 'no reader registered');
        }

        $startedAt = $this->clock->now();

        try {
            $discovered = $reader->read($server);
        } catch (\Throwable $e) {
            // Guarantee 3: a down server never wipes known tools. Record the
            // failure and return — its Tool rows are not touched at all.
            $message = $this->safeMessage($e);
            $server->recordSyncOutcome($startedAt, $message);
            $this->em->flush();
            $this->logger->warning('Tool catalog sync failed for {server}: {reason}', [
                'server' => $server->getName(),
                'reason' => $message,
            ]);

            return new SyncResult($server, false, 0, 0, 0, $message);
        }

        $now = $this->clock->now();
        $existing = $this->tools->findForServerIndexedByName($server);

        $created = 0;
        $updated = 0;
        $drifted = 0;

        foreach ($discovered as $tool) {
            $row = $existing[$tool->name] ?? null;

            if (null === $row) {
                // Default tag: the server name — a task tagged 'weather'
                // resolves the whole weather server out of the box; the admin
                // can refine per-tool tags later (pinned rows are immune).
                $row = new Tool($server, $tool->name, $tool->description, $tool->schema, [$server->getName()]);
                $row->markDiscovered($now);
                $this->em->persist($row);
                $existing[$tool->name] = $row;
                ++$created;
                continue;
            }

            $drift = $row->mergeDiscovered($tool->description, $tool->schema);
            if ([] === $drift) {
                continue;
            }

            if ($row->isPinned()) {
                // Guarantee 1: pinned content wins — the discovered content
                // is recorded as drift only (guarantee 2).
                $drifted += \count($drift);
                $this->logger->notice('Pinned tool {server}.{tool} drifted from the server: fields {fields} (pinned content kept).', [
                    'server' => $server->getName(),
                    'tool' => $tool->name,
                    'fields' => implode(', ', $drift),
                ]);
                continue;
            }

            ++$updated;
            $drifted += \count($drift);
        }

        $server->recordSyncOutcome($now, 'ok');
        $this->em->flush();

        $this->logRemovedTools($server, $existing, $discovered);

        return new SyncResult($server, true, $created, $updated, $drifted, 'ok');
    }

    /**
     * Find a registered server by name (used by the CLI for targeted sync).
     */
    public function findServerByName(string $name): ?McpServer
    {
        return $this->servers->findOneBy(['name' => $name]);
    }

    /**
     * Sync all enabled servers, each in its own transaction.
     *
     * @return list<SyncResult>
     */
    public function syncAll(): array
    {
        $results = [];
        foreach ($this->servers->findSyncable() as $server) {
            $results[] = $this->syncServer($server);
        }

        return $results;
    }

    /**
     * Log rows the server no longer offers (SPEC: kept, never wiped) so the
     * catalog's "down server" guarantee is visible in the logs.
     *
     * @param array<string, Tool>  $existing
     * @param list<DiscoveredTool> $discovered
     */
    private function logRemovedTools(McpServer $server, array $existing, array $discovered): void
    {
        $discoveredNames = [];
        foreach ($discovered as $tool) {
            $discoveredNames[$tool->name] = true;
        }

        foreach ($existing as $name => $row) {
            if (!isset($discoveredNames[$name])) {
                $this->logger->info('Tool {server}.{tool} no longer offered by the server — kept (down servers never wipe the catalog).', [
                    'server' => $server->getName(),
                    'tool' => $name,
                ]);
            }
        }
    }

    private function safeMessage(\Throwable $e): string
    {
        // Class + message, truncated. Never includes credential values —
        // readers must not embed them in exceptions.
        $message = \get_class($e).': '.$e->getMessage();

        return \strlen($message) > 255 ? substr($message, 0, 255) : $message;
    }
}
