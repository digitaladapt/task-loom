// TaskLoom menu bar: the small-screen disclosure for the site header.
//
// One job: on a narrow viewport, let the operator fold the navigation away
// instead of spending the top of every page on it. The header markup carries
// everything already — brand, nav, sign-out — so this only toggles state.
//
// The progressive-enhancement contract (mirrored in app.css):
//
//   - Without this script, `.menu-toggle` is `display: none` and the panel is
//     always visible: a full, plain navigation list. Nothing is hidden behind
//     a control that cannot work.
//   - With it, the header is marked `data-menu-collapsible`, the toggle
//     appears (CSS, small screens only) and the panel folds until asked.
//   - On wide screens CSS shows the panel inline again whatever the state,
//     so an open/closed leftover from a narrow viewport can never hide the
//     nav on a desktop.
//
// State lives in attributes rather than a class list so the markup, the CSS,
// and this script all read the same thing. `aria-expanded` tracks it for
// screen readers.

const header = document.querySelector('[data-site-menu]');

if (header) {
    const toggle = header.querySelector('[data-menu-toggle]');
    const panel = header.querySelector('[data-menu-panel]');

    if (toggle && panel) {
        header.setAttribute('data-menu-collapsible', '');

        const setOpen = (open) => {
            header.toggleAttribute('data-menu-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        };

        // Closed to start with: a small screen that loads with the nav folded
        // shows more of the page it actually asked for.
        setOpen(false);

        toggle.addEventListener('click', () => {
            setOpen(!header.hasAttribute('data-menu-open'));
        });

        // Escape closes the panel and returns focus to the control, so a
        // keyboard user is never stranded inside a folded-away menu. Only
        // while the toggle is on screen: on a wide viewport the panel is
        // permanently inline and there is no control to hand focus back to.
        const toggleIsVisible = () => window.getComputedStyle(toggle).display !== 'none';

        header.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && header.hasAttribute('data-menu-open') && toggleIsVisible()) {
                setOpen(false);
                toggle.focus();
            }
        });

        // Crossing the wide-screen breakpoint shows the panel by CSS, but the
        // stale `data-menu-open`/`aria-expanded` pair would then disagree with
        // what is on screen. Reset on the way up (or down) so state means the
        // same thing at every width.
        const wide = window.matchMedia('(min-width: 40rem)');
        const syncToViewport = (event) => {
            if (event.matches) {
                setOpen(false);
            }
        };
        wide.addEventListener('change', syncToViewport);
    }
}
