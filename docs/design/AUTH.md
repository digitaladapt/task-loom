# Authentication — design note

**Status:** implemented · **Scope:** SPEC §4.3, §8, §11 (v1.x auth rework)

## The problem

v1 shipped one credential: `TASKLOOM_ADMIN_PASSWORD`, checked by an HTTP
Basic authenticator that every route shared (the admin UI *and* `POST /mcp`).
That had three costs:

1. **The browser experience.** HTTP Basic means the browser's native
   credential prompt — no sign-in page, no sign-out, no "stay signed in",
   and no way to make the prompt say anything helpful when the deployment
   forgot to set a password.
2. **One secret for two audiences.** External MCP agents authenticated with
   `Basic base64(admin:password)` — the human's password, pasted into client
   configs, shell history and logs. Rotating the agent credential meant
   rotating the human one, and vice versa.
3. **Basic on the wire.** Credentials on every request, no CSRF story for
   the UI's write actions beyond the explicit per-form tokens, and no
   revocation short of rotating the password.

## The shape now

Two front doors, two credentials, one principal.

| | Admin UI | MCP endpoint (`POST /mcp`) |
|---|---|---|
| Firewall | `main` (session) | `mcp` (stateless) |
| Credential | `TASKLOOM_ADMIN_PASSWORD` | `TASKLOOM_MCP_API_KEY` |
| Transport | session cookie + CSRF tokens | `Authorization: Bearer <key>` |
| Sign-out | `POST /logout` (CSRF-protected) | n/a — stateless |
| Failure | redirect to `/login` | `401` + `WWW-Authenticate: Bearer` |

Both credentials resolve to the same `AdminUser` (ROLE_ADMIN is still the
whole authorization model), and both comparisons are constant-time
(`hash_equals`) and fail closed when the variable is unset.

### Why a session for the UI

The approval gate (SPEC §4.3) is only as strong as its "user-authenticated
session" claim. A session gives that claim a concrete meaning: a token in
the server-side session store, established by presenting the password once,
ended by `/logout`, and never accepted on the MCP door. It also gives the
UI somewhere to put CSRF tokens and flash messages, and it lets `/login`
explain an unconfigured deployment instead of leaving a credential prompt
that can never succeed.

The cost — a session store and session cookies — is already paid: the app
configures `framework.session` for the MCP SDK's own handshake bookkeeping
and the UI's CSRF tokens.

### Why a *separate* key for MCP

The bearer key is not derived from the password and not equal to it:

- **Independent rotation.** A leaked agent key is revoked by changing one
  variable; the human login is untouched. A changed password does not
  silently break every agent.
- **Blast radius.** The key sits in client configs and CI secrets. It is an
  identity with the MCP tools' scope only — it cannot reach the UI (the
  `mcp` firewall is stateless; UI routes require a session), so it cannot
  approve a task or enable one.
- **Fail-closed clarity.** Each door names its own variable when unset, so
  the operator gets a diagnosis, not a lockout mystery.

The stateless firewall is deliberate: MCP sessions belong to the SDK
(`mcp_sessions` cache pool), not to PHP sessions, and statelessness means a
stolen UI cookie is not an MCP credential.

### The seamless browser experience

"Stay signed in on this device" keeps the password in the browser's
localStorage. When the login page renders and a password is stored,
`assets/auth.js` posts it to `POST /login/api-key` — a CSRF-required
endpoint that verifies it exactly as the form does and signs the session in
programmatically (`Security::login()`, so the token and cookie are the same
ones the form produces). A 401 clears the stored value; signing out clears
it too. The login page is therefore seen exactly once per device (unless the
human opts out of remembering), and always with an escape hatch: the form.

The trade-off is honest and documented: **a password in localStorage is a
password in localStorage** — readable by any script running on the origin
(the CSP and the no-CDN posture are what keep that origin ours). Operators
who do not want it can untick the checkbox; the session still works.

## What was removed

The HTTP Basic authenticator (`AdminAuthenticator`) is gone. There is no
compatibility shim: `Authorization: Basic …` is refused at both doors, with
diagnostics that name the bearer scheme. The migration for existing
deployments is one line in an MCP client config (`Basic base64(admin:pass)`
→ `Bearer <TASKLOOM_MCP_API_KEY>`) and nothing at all for browsers.
