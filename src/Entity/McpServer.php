<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\McpServerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A registered external tool server. The registry row; tools are discovered
 * from the server at sync time (SPEC §7).
 *
 * `cred_var` names an ENV VARIABLE (e.g. WEATHER_API_KEY) — never a secret
 * value. The value is resolved from the harness environment at call time and
 * scrubbed from all traces.
 */
#[ORM\Entity(repositoryClass: McpServerRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_mcp_server_name', columns: ['name'])]
class McpServer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine assigns the generated id)

    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(length: 512)]
    private string $url;

    /** One of: mcp | openapi (SPEC §7). */
    #[ORM\Column(length: 16, enumType: ServerProtocol::class)]
    private ServerProtocol $protocol;

    #[ORM\Column]
    private bool $enabled = true;

    /**
     * Env var NAME holding the credential (e.g. WEATHER_API_KEY), if any.
     * Never the value itself.
     */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $credVar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    /**
     * Outcome of the last sync attempt: 'ok' or a short, safe message
     * (connection refused, timeout, invalid response). Drives the admin UI
     * warning badge; details live in the logs.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastSyncStatus = null;

    /** @var Collection<int, Tool> */
    #[ORM\OneToMany(mappedBy: 'server', targetEntity: Tool::class, cascade: ['persist', 'remove'])]
    private Collection $tools;

    public function __construct(
        string $name,
        string $url,
        ServerProtocol $protocol,
        ?string $credVar = null,
    ) {
        $this->name = $name;
        $this->url = $url;
        $this->protocol = $protocol;
        $this->credVar = $credVar;
        $this->tools = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    public function getProtocol(): ServerProtocol
    {
        return $this->protocol;
    }

    public function setProtocol(ServerProtocol $protocol): void
    {
        $this->protocol = $protocol;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getCredVar(): ?string
    {
        return $this->credVar;
    }

    public function setCredVar(?string $credVar): void
    {
        $this->credVar = $credVar;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function getLastSyncStatus(): ?string
    {
        return $this->lastSyncStatus;
    }

    /**
     * Called by the synchronizer only — the entity records sync outcomes, it
     * does not perform syncs.
     */
    public function recordSyncOutcome(?\DateTimeImmutable $at, string $status): void
    {
        $this->lastSyncedAt = $at;
        $this->lastSyncStatus = $status;
    }

    /** @return Collection<int, Tool> */
    public function getTools(): Collection
    {
        return $this->tools;
    }
}
