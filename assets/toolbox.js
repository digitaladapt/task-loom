// TaskLoom toolbox picker: which of the two panels is showing.
//
// ONE implementation, loaded by the shell entrypoint (app.js) rather than by a
// page's own — because the toolbox widget is no longer only the task editor's.
// A conversation's declaration is the same markup with the same field names
// (`App\Admin\ToolboxSelection` reads both), and a chat page loads only `app`.
// While this lived in task-editor.js the picker worked on the task editor and
// was **inert everywhere else**: on /chat and /chat/{id} the mode radios did
// nothing, and — worse — the panel visibility the server had already set was
// never corrected, so a picker whose mode was `tags` could sit showing the
// explicit-tools panel. The bug looked like a cosmetic one and was really
// "this page's JavaScript never loaded".
//
// The markup is explicit about the contract (templates/_toolbox.html.twig):
// both panels stay in the DOM, so switching back and forth never loses what
// the human typed, and the server honours only the selected mode's list — so
// the hidden panel cannot leak into a saved declaration.
//
// Delegated from the root, not bound per fieldset: step cards are cloned into
// existence by the task editor's builder, and a per-element binding would
// silently skip every one of them (which it did — the explicit-tools panel of a
// newly added step stayed hidden, so its checkboxes could never be ticked).
// The builder announces each insertion with a `taskloom:toolbox-sync` event
// that bubbles to the document, which is why the delegated listener is enough
// and the builder needs to know nothing about this module.

export function initToolboxModeSwitcher(root) {
    const sync = (fieldset) => {
        const active = fieldset.querySelector('[data-toolbox-mode]:checked');
        const mode = active ? active.value : 'tags';
        fieldset.querySelectorAll('[data-toolbox-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.toolboxPanel !== mode;
        });
    };

    root.addEventListener('change', (event) => {
        const radio = event.target.closest('[data-toolbox-mode]');
        if (radio) {
            const fieldset = radio.closest('fieldset.toolbox');
            if (fieldset) {
                sync(fieldset);
            }
        }
    });

    // Freshly cloned cards (and the prototype) need their initial state too;
    // announced by the builder after every insertion.
    root.addEventListener('taskloom:toolbox-sync', (event) => {
        const scope = event.target instanceof Element ? event.target : root;
        scope.querySelectorAll('fieldset.toolbox').forEach(sync);
        if (scope.matches?.('fieldset.toolbox')) {
            sync(scope);
        }
    });

    // And the page as rendered, which is the half the chat pages were missing.
    root.querySelectorAll('fieldset.toolbox').forEach(sync);
}

// Imported by the shell entrypoint, so this runs on every page that renders
// the widget — no caller needed, and no page can forget it. A page that wants
// to re-settle panels itself (the task editor's builder, after cloning a step
// card) announces `taskloom:toolbox-sync` instead of calling in.
if (typeof document !== 'undefined' && document instanceof Document) {
    initToolboxModeSwitcher(document);
}
