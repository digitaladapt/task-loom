<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\ErrorClass;
use App\Entity\Run;

/**
 * One error-class group of the attention queue (SPEC §8): the runs waiting
 * for a human eye, gathered under the class they failed with.
 */
final readonly class AttentionGroup
{
    /**
     * @param list<Run> $runs newest first
     */
    public function __construct(
        public string $label,
        public ?ErrorClass $errorClass,
        public array $runs,
    ) {
    }
}
