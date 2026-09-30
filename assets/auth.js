// TaskLoom auth: the browser half of the sign-in flow.
//
// Two behaviors, both about the admin password the human already typed:
//
//  1. SEAMLESS RE-LOGIN. The password may be kept in this browser's
//     localStorage ("stay signed in on this device"). When the login page is
//     reached and a password is stored, POST it to the re-login endpoint —
//     which verifies it exactly as the form would — and reload straight
//     through. The visitor sees no form unless the password no longer works.
//     A 401 clears the stale value so a changed password cannot loop.
//
//  2. REMEMBER / FORGET. The form's checkbox decides whether the password is
//     kept. Signing out clears it — otherwise the seamless step above would
//     sign the visitor right back in, and "Sign out" would mean nothing.
//
// Nothing here is an inline script: this file is a module loaded through the
// `app` entrypoint, so the strict Content-Security-Policy needs no exception
// for it. The CSRF token travels from the rendered form (or the response
// header) — never minted client-side.

const STORAGE_KEY = 'taskloom_admin_password';

function storedPassword() {
    try {
        return window.localStorage.getItem(STORAGE_KEY) || '';
    } catch {
        // Private mode / storage disabled: fall back to the plain form.
        return '';
    }
}

function storePassword(value) {
    try {
        window.localStorage.setItem(STORAGE_KEY, value);
    } catch {
        /* Not remembering is a valid outcome; the session still works. */
    }
}

function clearPassword() {
    try {
        window.localStorage.removeItem(STORAGE_KEY);
    } catch {
        /* Nothing to clear. */
    }
}

const loginForm = document.querySelector('[data-login-form]');

if (loginForm) {
    // A rendered error means the last attempt failed — including one this
    // script made automatically. Drop the stored value so the form is the
    // honest state of things (and so a changed password cannot be retried
    // on every visit).
    if (document.querySelector('.flash-error')) {
        clearPassword();
    }

    const apiUrl = loginForm.dataset.loginApiUrl;
    const tokenField = loginForm.querySelector('input[name="_token"]');
    const password = storedPassword();

    if (apiUrl && tokenField && password) {
        fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': tokenField.value,
                'Accept': 'application/json',
            },
            body: new URLSearchParams({ password }),
            credentials: 'same-origin',
        })
            .then((response) => {
                if (response.ok) {
                    // Session established; the login route now redirects on.
                    window.location.reload();
                    return;
                }
                if (401 === response.status) {
                    clearPassword();
                }
            })
            .catch(() => {
                /* Network hiccup: show the form; the human can type it. */
            });
    }

    // Remember (or forget) the password at the moment of submission, so the
    // checkbox reflects intent before the navigation begins.
    loginForm.addEventListener('submit', () => {
        const remember = loginForm.querySelector('[data-remember]');
        const input = loginForm.querySelector('input[name="password"]');

        if (remember && remember.checked && input && '' !== input.value) {
            storePassword(input.value);
        } else {
            clearPassword();
        }
    });
}

// Signing out must clear the stored password too: with it left behind, the
// next visit would seamlessly sign straight back in.
document.addEventListener('submit', (event) => {
    if (event.target instanceof HTMLFormElement && event.target.matches('[data-signout]')) {
        clearPassword();
    }
});
