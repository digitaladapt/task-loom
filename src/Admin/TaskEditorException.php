<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * A task-editor submission could not be turned into a persistable task —
 * an incomplete preset schedule, or a step graph the codec refused.
 *
 * Distinct from TaskLifecycleException (a state problem) and from the
 * codec's own StepFormatException (an indexed wire-format diagnosis): this
 * is the editor's catch-all for "the form said something I cannot save",
 * carrying a message written for the person reading the form.
 */
final class TaskEditorException extends \RuntimeException
{
}
