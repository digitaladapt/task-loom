<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Who authored a task row (or its edit) — SPEC §4.3. Agent-authored rows
 * persist with enabled=false; the human is the enable switch.
 */
enum TaskAuthor: string
{
    case User = 'user';
    case Agent = 'agent';
}
