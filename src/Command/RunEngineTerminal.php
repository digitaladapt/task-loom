<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Run;
use App\Entity\RunStatus;
use Symfony\Component\Console\Command\Command;

/**
 * Maps a run's terminal status to a console exit code. Exists only so the
 * command and tests share one definition.
 *
 * @internal
 */
final readonly class RunEngineTerminal
{
    public static function toExitCode(Run $run): int
    {
        return RunStatus::Succeeded === $run->getStatus() ? Command::SUCCESS : Command::FAILURE;
    }
}
