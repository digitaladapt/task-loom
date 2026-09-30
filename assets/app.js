// TaskLoom app.js — register the service worker, and the auth helpers.
//
// auth.js is imported here (rather than made its own entrypoint) because two
// of its jobs belong on every page: clearing the remembered password when a
// sign-out form is submitted, and the seamless re-login when a page redirects
// to /login. One import, and every page the base template renders has them.

import './auth.js';

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
