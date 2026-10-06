// TaskLoom app.js — register the service worker, the auth helpers, the menu
// bar's disclosure control, and the shared toolbox picker.
//
// auth.js is imported here (rather than made its own entrypoint) because two
// of its jobs belong on every page: clearing the remembered password when a
// sign-out form is submitted, and the seamless re-login when a page redirects
// to /login. One import, and every page the base template renders has them.
//
// menu.js is imported for the same reason: the header is on every page, so
// its toggle belongs to the shell entrypoint, not to a per-page bundle.
//
// toolbox.js is here for exactly that reason too, and it is a bug fix rather
// than a preference: the toolbox widget is rendered by the task editor *and*
// by both chat pages, but its behaviour used to live in task-editor.js — which
// only the task editor loads. A chat's picker was therefore inert (the mode
// radios did nothing) and the panel the server had hidden stayed hidden. The
// shared widget's script has to be shared with every page that renders it.
//
// One import, not two: `toolbox.js` exports the initialiser *and* runs it on
// import, so the shell gets the behaviour by loading the module. Importing it
// a second time for the binding would be a second import of the same module —
// harmless, since modules are cached, but the kind of thing that reads like
// two different behaviours to whoever looks next.

import './auth.js';
import './menu.js';
import './toolbox.js';

// Register the service worker.
//
// The stylesheet is linked from base.html.twig with asset(), not imported
// here. Importing it would make AssetMapper publish the CSS as an importmap
// entry spelled `data:application/javascript,…`, which the browser then tries
// to load as a *script* — blocked by our own `script-src 'self'` policy (and
// rightly so: it is a data: URL). CSS is CSS: it goes in a <link>.

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Registration failure is non-fatal: the app still works,
            // it just isn't installable.
        });
    });
}

// The picker's panels are corrected on load for whatever the page rendered —
// that side effect lives in toolbox.js, which was just imported.
