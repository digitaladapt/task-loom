<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    // Every page: the service worker registration, and anything else that has
    // to be true of the whole shell.
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    // The task editor's step-graph builder and schedule preview. Its own
    // entrypoint so ~12KB of builder code is not parsed on every page that has
    // no editor on it; edit.html.twig imports both entrypoints in one
    // importmap() call (only one <script type="importmap"> is allowed per
    // document).
    'task-editor' => [
        'path' => './assets/task-editor.js',
        'entrypoint' => true,
    ],
];
