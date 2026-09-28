<?php

declare(strict_types=1);

namespace App\Toolbox;

/**
 * Raised when a server's `cred_var` cannot be resolved to a credential —
 * the named environment variable is unset or empty.
 *
 * Deliberately carries no secret value: the message names the env var only.
 * The catalog synchronizer records this message on the server row, and the
 * run engine surfaces it in the admin UI, so a leaked value here would be a
 * leaked value in the ledger.
 */
final class CredentialResolutionException extends \RuntimeException
{
}
