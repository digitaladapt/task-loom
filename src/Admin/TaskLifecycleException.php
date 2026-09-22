<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * A task lifecycle action was refused (wrong state, nonexistent task). The
 * admin UI maps it to a 4xx response with the human-readable message.
 */
final class TaskLifecycleException extends \RuntimeException
{
}
