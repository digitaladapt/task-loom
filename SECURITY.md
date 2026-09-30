# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| unreleased (v1 development) | ✅ |

## Reporting a Vulnerability

Report vulnerabilities privately to **security@digitaladapt.com** (or open a private
security advisory on the repository). Please include reproduction steps and affected
versions. You will receive an acknowledgement within 48 hours and a status update at
least weekly until resolution.

## Security model summary

The boundary is tool scoping, not a sandbox (see `docs/design/SPEC.md` §4):

- A task's model sees only the tools in its toolbox, resolved once at run start.
  No mid-run expansion in v1.
- Tool results are data, never instructions: capped, truncated with an explicit
  marker, stored raw, and only a pruned window returns to the model.
- Agent-authored task writes always persist disabled (`enabled=false`) — hardcoded
  in the persistence layer, not a prompt rule. A human enables them via the admin UI.
- Enabled tasks are immutable records: updates create replacement drafts, never
  mutations (preserving version history for forensics and the improvement cycle).
- MCP server credentials live only in the harness environment (`cred_var` names an
  env var — never the value), injected at call time, scrubbed from all traces. A
  server's `cred_var` is converted to an `Authorization` header by
  `App\Toolbox\CredentialResolver` at sync *and* call time; a bare token is sent
  as `Bearer <token>`, a value that spells its own scheme is sent verbatim. The
  resolved value never enters the run's frozen toolbox snapshot (which carries the
  variable NAME only), a log line, the attempt ledger, or an exception message — a
  missing variable fails loudly with the variable's name in the message.
- **Two credentials, two front doors.** The admin UI is a session: sign in
  with `TASKLOOM_ADMIN_PASSWORD`, optionally kept in the browser's
  localStorage for seamless re-login, cleared on sign-out. The MCP endpoint
  at `POST /mcp` is stateless and authenticates
  `Authorization: Bearer <TASKLOOM_MCP_API_KEY>` — an agent credential
  deliberately separate from the human one, rotatable independently. Both
  comparisons are constant-time, both fail closed when unset, and neither
  door opens for the other's credential (a UI session cannot call MCP; an
  MCP key cannot read the UI). HTTP Basic is no longer accepted.
- `.env` is never committed; secrets are env vars injected at runtime (§8.12).