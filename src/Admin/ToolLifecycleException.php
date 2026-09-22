<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * A tool-catalog action was refused (nonexistent tool, invalid input). The
 * admin UI maps it to a 4xx response with the human-readable message.
 */
final class ToolLifecycleException extends \RuntimeException
{
}
