<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ToolRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A tool in the catalog, discovered from a server (or pinned by hand).
 *
 * Pinned tools win over discovered ones in the merge (SPEC §7); drift between
 * the two is logged, and a down server never wipes its known tools.
 *
 * `schema` stores the JSON Schema describing the tool's arguments — the same
 * array that goes into the model's toolbox prompt and validation.
 */
#[ORM\Entity(repositoryClass: ToolRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_tool_server_name', columns: ['server_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
class Tool
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (Doctrine assigns the generated id)

    #[ORM\ManyToOne(targetEntity: McpServer::class, inversedBy: 'tools')]
    #[ORM\JoinColumn(name: 'server_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private McpServer $server;

    #[ORM\Column(length: 128)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * Resolved tag list (task.tags ∩ tool.tags → toolbox). Stored as JSON
     * array of strings.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $tags = [];

    /**
     * JSON Schema (as decoded array) for the tool's arguments.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $schema = [];

    #[ORM\Column]
    private bool $sideEffect = false;

    /**
     * A pinned tool has hand-curated content that wins over discovered
     * content in the merge (SPEC §7). Drift between the pinned content and
     * the discovered one is logged, never applied.
     */
    #[ORM\Column]
    private bool $pinned = false;

    #[ORM\Column]
    private ?\DateTimeImmutable $discoveredAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @param array<int, string>   $tags
     * @param array<string, mixed> $schema
     */
    public function __construct(
        McpServer $server,
        string $name,
        ?string $description,
        array $schema,
        array $tags = [],
        bool $sideEffect = false,
    ) {
        $this->server = $server;
        $this->name = $name;
        $this->description = $description;
        $this->schema = $schema;
        $this->tags = $tags;
        $this->sideEffect = $sideEffect;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getServer(): McpServer
    {
        return $this->server;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * @return array<int, string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @param array<int, string> $tags
     */
    public function setTags(array $tags): void
    {
        $this->tags = $tags;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return $this->schema;
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function setSchema(array $schema): void
    {
        $this->schema = $schema;
    }

    public function isPinned(): bool
    {
        return $this->pinned;
    }

    /**
     * Pin the tool with hand-curated content (admin action). From now on
     * the discovered content is compared for drift but never applied.
     *
     * @param array<string, mixed> $schema
     */
    public function pin(string $description, array $schema): void
    {
        $this->pinned = true;
        $this->description = $description;
        $this->schema = $schema;
    }

    public function unpin(): void
    {
        $this->pinned = false;
    }

    public function hasSideEffect(): bool
    {
        return $this->sideEffect;
    }

    public function setSideEffect(bool $sideEffect): void
    {
        $this->sideEffect = $sideEffect;
    }

    public function getDiscoveredAt(): ?\DateTimeImmutable
    {
        return $this->discoveredAt;
    }

    /**
     * Mark this row as newly discovered (first time seen from its server).
     */
    public function markDiscovered(\DateTimeImmutable $at): void
    {
        $this->discoveredAt = $at;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable();
        $this->discoveredAt ??= $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Merge a discovered definition into this row.
     *
     * Pinned content always wins: the discovered fields are compared for
     * drift (so it can be logged) but never applied.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<string> names of fields whose discovered value differs from the stored one ('description', 'schema') — the drift signal
     */
    public function mergeDiscovered(?string $description, array $schema): array
    {
        $drift = [];

        if ($this->pinned) {
            if ($this->description !== $description) {
                $drift[] = 'description';
            }
            if (!$this->schemasEqual($this->schema, $schema)) {
                $drift[] = 'schema';
            }

            return $drift;
        }

        if ($this->description !== $description) {
            $drift[] = 'description';
            $this->description = $description;
        }
        if (!$this->schemasEqual($this->schema, $schema)) {
            $drift[] = 'schema';
            $this->schema = $schema;
        }

        return $drift;
    }

    /**
     * Structural schema equality. Freshly built schemas represent empty
     * JSON objects as stdClass while Doctrine's JSON round-trip decodes
     * them to empty arrays — strict !== would report phantom drift on
     * every sync. Both sides are normalized (stdClass cast to arrays,
     * recursively) before comparing.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function schemasEqual(array $a, array $b): bool
    {
        return self::normalizeSchemaValue($a) === self::normalizeSchemaValue($b);
    }

    private static function normalizeSchemaValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }

        if (\is_array($value)) {
            return array_map(self::normalizeSchemaValue(...), $value);
        }

        return $value;
    }
}
