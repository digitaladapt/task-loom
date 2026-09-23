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
  env var — never the value), injected at call time, scrubbed from all traces.
- `.env` is never committed; secrets are env vars injected at runtime (§8.12).