// TaskLoom task editor: the step-graph builder and the live schedule preview.
//
// Two jobs, both of which exist because a form cannot express them on its own:
//
//  1. Steps arrive as levels[level][step][field]. Adding or removing a level
//     or a step is structural, so it is DOM work — clone the prototype,
//     renumber, keep focus sane. "Renumbering" only has to keep the indices
//     unique and in display order: the server re-indexes whatever it receives
//     (TaskEditorSubmission::parseSteps), so removing level 2 of 5 leaves a
//     gap it closes itself.
//
//  2. The schedule preview asks the server what the current fields compose:
//     the cron expression, its English reading, and the next few real
//     occurrences. Cron composition and occurrence computation live in PHP
//     (SchedulePreset, ScheduleExpression) because that is where the scheduler
//     reads them; re-implementing either in JavaScript would be a second
//     opinion about when a task runs, and the two would eventually disagree.
//
// Plain modern JavaScript, no framework: the page is one form, and the
// project's CSP (script-src 'self') allows exactly this and nothing inline.

const editor = document.querySelector('[data-task-editor]');

if (editor) {
    initStepBuilder(editor);
    initSchedule(editor);
}

/* ------------------------------------------------------------------ steps */

function initStepBuilder(root) {
    const levels = root.querySelector('[data-levels]');
    const prototype = root.querySelector('[data-step-prototype]');
    const emptyNote = root.querySelector('[data-levels-empty]');
    if (!levels || !prototype) {
        return;
    }

    const syncEmptyNote = () => {
        if (emptyNote) {
            emptyNote.hidden = levels.querySelectorAll('[data-level]:not([hidden])').length > 0;
        }
    };

    // The next index to hand out. Deliberately monotonic and never reused:
    // indices need only be unique and ordered, and monotonic indices can never
    // collide with a level that already exists in the DOM.
    let nextLevel = levels.querySelectorAll('[data-level]').length;
    let nextStep = 0;
    levels.querySelectorAll('[data-steps-in-level]').forEach((list) => {
        nextStep = Math.max(nextStep, list.querySelectorAll('[data-step-card]').length);
    });

    const instantiate = (levelIndex, stepIndex) => {
        const html = prototype.innerHTML
            .replaceAll('__level__', String(levelIndex))
            .replaceAll('__step__', String(stepIndex));
        const holder = document.createElement('div');
        holder.innerHTML = html.trim();
        const card = holder.firstElementChild;

        // The prototype renders disabled so it can never be submitted by
        // accident; live copies must be enabled again.
        card.querySelectorAll('[disabled]').forEach((el) => el.removeAttribute('disabled'));

        // The clone carries the prototype's initial panel state; re-sync it so
        // the radio group and the visible panel agree from the first paint.
        card.dispatchEvent(new CustomEvent('taskloom:toolbox-sync', { bubbles: true }));

        return card;
    };

    const addStep = (level) => {
        const list = level.querySelector('[data-steps-in-level]');
        list.appendChild(instantiate(level.dataset.levelIndex ?? nextLevel++, nextStep++));
        syncEmptyNote();
    };

    const addLevel = () => {
        const levelIndex = nextLevel++;
        const level = document.createElement('div');
        level.className = 'level';
        level.dataset.level = '';
        level.dataset.levelIndex = String(levelIndex);
        level.innerHTML = `
            <p class="level-head">
                <span class="level-title"></span>
                <span class="muted">— these run in parallel</span>
                <button type="button" class="danger small" data-remove-level>Remove level</button>
            </p>
            <div class="steps-in-level" data-steps-in-level></div>
            <button type="button" class="small" data-add-step>+ Add step to this level</button>
        `;
        levels.appendChild(level);
        addStep(level);

        // Number it with the rest, rather than hoping the index matches the
        // position — removing a level in the middle makes those differ, and a
        // heading that lies about position is worse than no heading.
        renumberHeadings();

        const firstInput = level.querySelector('input[name$="[title]"]');
        if (firstInput) {
            firstInput.focus();
        }
    };

    // Stamp existing levels with their index so later insertions agree.
    levels.querySelectorAll('[data-level]').forEach((level, index) => {
        level.dataset.levelIndex = String(index);
    });

    // Renumber the visible level headings after a removal. The field indices
    // are left alone — gaps are the server's problem, not the DOM's.
    const renumberHeadings = () => {
        levels.querySelectorAll('[data-level]:not([hidden])').forEach((level, index) => {
            const title = level.querySelector('.level-title');
            if (title) {
                title.textContent = `Level ${index + 1}`;
            }
        });
    };

    levels.addEventListener('click', (event) => {
        if (event.target.closest('[data-add-step]')) {
            addStep(event.target.closest('[data-level]'));
            return;
        }

        if (event.target.closest('[data-remove-level]')) {
            event.target.closest('[data-level]').remove();
            renumberHeadings();
            syncEmptyNote();
            return;
        }

        const removeStep = event.target.closest('[data-remove-step]');
        if (removeStep) {
            const card = removeStep.closest('[data-step-card]');
            const level = card.closest('[data-level]');
            card.remove();

            // A level with no steps left is meaningless (SPEC §13.2: every
            // level needs at least one step), so remove it rather than letting
            // the human submit a hole.
            if (level && 0 === level.querySelectorAll('[data-step-card]').length) {
                level.remove();
                renumberHeadings();
            }
            syncEmptyNote();
        }
    });

    const addLevelButton = root.querySelector('[data-add-level]');
    if (addLevelButton) {
        addLevelButton.addEventListener('click', addLevel);
    }

    syncEmptyNote();
}

/* ---------------------------------------------------------------- toolbox */

// The panel switcher lives in its own module (`toolbox.js`), imported by the
// shell entrypoint, because the widget is shared with the chat pages and this
// file is not loaded there. What stays here is the builder's half of the
// contract: a freshly cloned step card must be told to settle its panels, and
// the switcher listens for this bubbling event.

/* --------------------------------------------------------------- schedule */

function initSchedule(root) {
    const fieldset = root.querySelector('[data-schedule]');
    if (!fieldset) {
        return;
    }

    const previewUrl = fieldset.querySelector('[data-schedule-preview-url]')?.dataset.schedulePreviewUrl;
    const previewBox = fieldset.querySelector('[data-schedule-preview]');
    const narration = fieldset.querySelector('[data-schedule-narration]');
    const expression = fieldset.querySelector('[data-schedule-expression]');
    const upcoming = fieldset.querySelector('[data-schedule-upcoming]');

    const syncPanels = () => {
        const active = fieldset.querySelector('[data-schedule-mode]:checked');
        const mode = active ? active.value : 'none';
        fieldset.querySelectorAll('[data-schedule-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.schedulePanel !== mode;
        });
        return mode;
    };

    // Preset fields only matter for the shapes that use them, so hide the
    // rest: an operator picking "every 15 minutes" should not be asked for a
    // time of day.
    const syncPresetFields = () => {
        const select = fieldset.querySelector('[data-schedule-preset]');
        const option = select?.selectedOptions?.[0];
        const needs = (name) => '1' === option?.dataset[`needs${name}`];

        const time = fieldset.querySelector('[data-schedule-field="time"]');
        const weekday = fieldset.querySelector('[data-schedule-field="weekday"]');
        const day = fieldset.querySelector('[data-schedule-field="day-of-month"]');

        if (time) time.hidden = !needs('Time');
        if (weekday) weekday.hidden = !needs('Weekday');
        if (day) day.hidden = !needs('Day');
    };

    const render = (data) => {
        if (!previewBox) {
            return;
        }

        previewBox.hidden = false;

        if (!data.ok) {
            narration.textContent = data.problems?.[0] ?? 'This schedule is not valid yet.';
            narration.classList.add('field-error');
            expression.textContent = '';
            upcoming.replaceChildren();
            return;
        }

        narration.classList.remove('field-error');

        if (!data.expression) {
            narration.textContent = 'Manual only — this task runs when you press Run now.';
            expression.textContent = '';
            upcoming.replaceChildren();
            return;
        }

        narration.textContent = `This task ${data.description}.`;

        // The composed expression is shown, not just described: it is what the
        // scheduler stores and what an operator would copy into a note or a
        // cron tab to reason about, and hiding it would make the preset picker
        // a one-way door.
        expression.textContent = `Cron: ${data.expression}`;

        upcoming.replaceChildren();
        (data.upcoming ?? []).forEach((occurrence) => {
            const item = document.createElement('li');
            item.textContent = occurrence;
            upcoming.appendChild(item);
        });
    };

    const refresh = () => {
        const mode = syncPanels();
        if (!previewUrl || 'none' === mode) {
            if (previewBox) {
                previewBox.hidden = 'none' !== mode;
            }
            if (narration && 'none' === mode) {
                narration.textContent = 'Manual only — this task runs when you press Run now.';
                narration.classList.remove('field-error');
                if (expression) expression.textContent = '';
                if (upcoming) upcoming.replaceChildren();
            }
            return;
        }

        const params = new URLSearchParams(new FormData(root));
        // The whole form is serialized (steps and all) but the preview endpoint
        // reads only the schedule fields; sending the rest costs nothing and
        // keeps this function from having to know which inputs matter.
        fetch(`${previewUrl}?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
            .then(render)
            .catch(() => {
                if (previewBox) {
                    previewBox.hidden = true;
                }
            });
    };

    fieldset.querySelectorAll('[data-schedule-mode]').forEach((radio) => radio.addEventListener('change', refresh));
    fieldset.querySelectorAll('[data-schedule-preset]').forEach((select) => select.addEventListener('change', () => {
        syncPresetFields();
        refresh();
    }));
    fieldset.querySelectorAll('[data-schedule-time], [data-schedule-weekday], [data-schedule-day]')
        .forEach((input) => input.addEventListener('change', refresh));

    const custom = fieldset.querySelector('[data-schedule-custom]');
    if (custom) {
        // Debounced: this is a network round trip per keystroke otherwise, and
        // a half-typed cron expression is invalid by definition.
        let timer = 0;
        custom.addEventListener('input', () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(refresh, 400);
        });
    }

    syncPresetFields();
    refresh();
}
