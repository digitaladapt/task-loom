// TaskLoom app.js — import the stylesheet, register the service worker.
import './styles/app.css';

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Registration failure is non-fatal: the app still works,
            // it just isn't installable.
        });
    });
}