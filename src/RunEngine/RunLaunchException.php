<?php

declare(strict_types=1);

namespace App\RunEngine;

/**
 * A launch that cannot be handed to the worker lanes: the run was created
 * but owes no first turn to dispatch. Should be unreachable for a run the
 * engine just started — surfaced loudly rather than leaving a run with no
 * carrier and no diagnosis.
 */
final class RunLaunchException extends \RuntimeException
{
}
