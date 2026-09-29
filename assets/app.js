// TaskLoom app.js — register the service worker.
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
