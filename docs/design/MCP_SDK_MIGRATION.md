# MCP SDK migration — `php-mcp/*` (fork) → `mcp/sdk` (official)

**Status:** plan + spike · branch `feat/official-mcp-sdk`
**Target:** `mcp/sdk` pinned to **`0.8.1`**, matching context-shuttle (PR #20)

## Why

Same two reasons as context-shuttle, plus one that is specific to this repo.

1. **The server fork is abandoned.** Upstream `php-mcp/server` last pushed
   **2025-08-09**; this project pins the same private fork (`3.3.99`) that
   context-shuttle had to maintain. `php-mcp/client` last pushed
   **2025-05-07**.

2. **`php-mcp/client` cannot speak the transport this project requires.** The
   client's built-in HTTP transport opens a **`GET` and waits for a legacy
   HTTP+SSE stream**. Every Streamable HTTP server — including context-shuttle,
   and including task-loom's own server role — answers that `GET` with **405**,
   which the old client treats as a fatal `ConnectionException`.

   That is not a theory: it is documented in this repo's own regression test
   (`McpServerReaderStreamableHttpTest`, "Regression test for the MCP 405
   crash") and in `StreamableHttpTransport`'s docblock. It is why
   `src/Toolbox/Transport/` exists at all.

3. **The official SDK deletes that entire workaround.** Its client transport
   `POST`s, handles both `application/json` and `text/event-stream` responses,
   and manages `Mcp-Session-Id` itself. See "Verified" below.

So this is not only "converge on the maintained SDK" — it is **~340 lines of
transport plumbing deleted**, and the SPEC's own contingency paragraph
("if the SDK fails us in practice, the hand-rolled streamable-HTTP fallback is
the documented contingency") retired in favour of the SDK doing it natively.

## What goes away

| File | Lines | Why it exists | After |
|---|---:|---|---|
| `src/Toolbox/Transport/StreamableHttpTransport.php` | 250 | Old client GETs and expects SSE; modern servers 405 | **deleted** |
| `src/Toolbox/Transport/StreamableTransportFactory.php` | 56 | Installs the above in place of the SDK's transport | **deleted** |
| `src/Command/McpServeCommand.php` | 74 | Standalone ReactPHP socket server for the server role | **deleted** |
| `tests/Unit/Toolbox/McpServerReaderStreamableHttpTest.php` | 105 | Guards the 405 crash | **rewritten** (see below) |
| `tests/Functional/Mcp/Server/TaskMcpServerEndToEndTest.php` | 212 | Spawns the serve command; port hunts | **rewritten in-process** |

`StreamableHttpTransport` is not merely redundant — it is a *reimplementation
of what the official transport now does properly*, including SSE parsing, JSON
framing, session-header capture, and error mapping. Keeping it would mean
maintaining a private fork of a feature upstream now ships.

### Dependencies drop too

The runtime dependency tree loses **all six** ReactPHP packages (`react/http`,
`react/async`, `react/child-process` on the runtime side) plus
`evenement/evenement` and `fig/http-message-util`, and `php-mcp/*` entirely.
The official SDK's only IO is PSR-18/PSR-17.

Worth recording precisely, because the number is easy to overstate: after the
migration **eight** `react/*` + `evenement` packages remain in the lock — but
they are pulled by **`friendsofphp/php-cs-fixer` (dev-only)**, which has always
used ReactPHP for parallel file fixing. They are in `packages-dev`, absent from
a `--no-dev` install, and unrelated to this change. Neither the server role nor
the client role ships a ReactPHP dependency afterwards.

## What changes

| File | Now | After |
|---|---|---|
| `src/Mcp/Server/TaskServerFactory.php` | `PhpMcp\Server\ServerBuilder`, `withTool()`, `withServerInfo()` | `Mcp\Server\Builder`, `addTool()`, `setServerInfo()` |
| `src/Command/McpServeCommand.php` | `new StreamableHttpServerTransport(host:…, port:…, stateless:…)` + `$server->listen()` | **see "The server-role decision"** |
| `src/Toolbox/McpServerReader.php` | `ClientBuilder::make()` + `TransportFactory` | `Client::builder()` + `new HttpTransport(endpoint:)` |
| `src/RunEngine/ToolExecutor.php` | `ClientBuilder::make()` + `TransportFactory` + `PhpMcp\Client\JsonRpc\Results\CallToolResult` | `Client::builder()` + `Mcp\Schema\Result\CallToolResult` |
| `src/Entity/ServerProtocol.php` | docblock only | docblock updated |
| `tests/Unit/{Toolbox,RunEngine}/*` | `PhpMcp\Client\*` imports | `Mcp\*` imports |

Business logic — the gated-write model, `TaskCrud`, the run engine, the
catalog, the OpenAPI reader, every entity — is untouched.

## The server-role decision — **decided: in-app**

`McpServeCommand` currently runs a **blocking ReactPHP socket server** in its
own process: `$server->listen($transport)` binds `127.0.0.1:8080` and runs an
event loop. The official SDK has no equivalent, and this is not an oversight —
its own integration guide states it plainly:

> The HTTP transport is a PSR-7 request handler, not a web server.

**Decision (user, this branch):** the server role moves into the app's HTTP
surface as a `POST /mcp` controller. `app:mcp:serve` is deleted. The admin UI
and the MCP endpoint share one FrankenPHP process and one port.

Why this is the right shape, not merely the convenient one:

1. It is the SDK's documented integration path; the alternative means writing a
   transport the SDK deliberately does not ship.
2. The deployment already claims to be one app: "one app, one image … the admin
   UI, run engine, and MCP task-tools server all live in this single Symfony
   application" (`Dockerfile`). The code now matches the claim.
3. **The e2e test gets strictly better.** `TaskMcpServerEndToEndTest` currently
   spawns `app:mcp:serve` as a subprocess, picks a port, polls for readiness,
   and kills the PID. Its own docblock documents the pain:

   > a fixed port invites collisions with leaked processes from earlier runs …
   > a mere "port accepts connections" check once passed against a stale
   > leftover process from an unrelated test

   It becomes a `KernelBrowser` request against the kernel — faster,
   deterministic, no processes, no ports. The gate assertions (a `task_create`
   over MCP must persist *disabled*; write schemas must expose no `enabled`
   parameter) are preserved verbatim, because they test behaviour, not
   transport.
4. Nothing in the deployment started it. It appeared in no compose file, no
   Dockerfile, no entrypoint and no README — only in its own command definition
   and the e2e test. Its default port also *collided conceptually* with the
   admin UI, which the example compose publishes as `8080:80`.

### Auth — **decided: admin-guarded**

The standalone server bound loopback only and had no authentication. Brought
in-app, the route inherits `security.yaml`'s final rule:

```yaml
- { path: ^/, roles: ROLE_ADMIN }
```

**Decision (user, this branch): leave it that way.** No security change, no new
`access_control` exemption. External agents authenticate with the admin
credentials (HTTP Basic, stateless — the same authenticator the UI uses).

That is the conservative choice and the one that keeps the gate coherent: the
whole point of the gated-write model is that agents cannot enable tasks, and
this keeps the agent-facing write surface behind auth rather than beside the
unauthenticated health endpoints. Documented in SPEC §11.

### What this choice does not affect

`TaskServerFactory` and all client-side work are identical under any option:
both need `Server::builder()->addTool(...)` and the new client API. Only
`McpServeCommand` (deleted), the new controller, and the e2e test's plumbing
depend on it.

## API mapping (to be verified against 0.8.1, as with context-shuttle)

| Fork | Official |
|---|---|
| `new ServerBuilder()` | `Server::builder()` |
| `->withServerInfo()` | `->setServerInfo()` |
| `->withCapabilities(ServerCapabilities::make())` | not needed — the builder derives capabilities from what is registered |
| `->withInstructions()` | `->setInstructions()` |
| `->withLogger()` | `->setLogger()` |
| `->withContainer()` | `->setContainer()` |
| `->withTool(handler:…, name:…, description:…, annotations:…, inputSchema:…)` | `->addTool(handler:…, name:…, description:…, annotations:…, inputSchema:…)` |
| `ToolAnnotations::make(…)` | `new ToolAnnotations(…)` |
| `ClientBuilder::make()->withClientInfo()->withServerConfig()->withTransportFactory()->build()` | `Client::builder()->setClientInfo()->build()` |
| `$client->initialize()` | `$client->connect($transport)` |
| `$client->listTools()` | `$client->listTools()` (same shape) |
| `$client->callTool($name, $args)` | `$client->callTool($name, $args)` (same shape) |
| `$client->disconnect()` | `$client->disconnect()` |
| `PhpMcp\Client\JsonRpc\Results\CallToolResult` | `Mcp\Schema\Result\CallToolResult` |
| `PhpMcp\Client\Model\Content\TextContent` | `Mcp\Schema\Content\TextContent` |
| `PhpMcp\Client\Exception\RequestException` | `Mcp\Exception\…` (exact class TBD — see below) |

## Preserving behaviour that already has tests

These are the contracts the existing suite pins, and each needs to keep
passing or be deliberately changed:

1. **`McpServerReaderTest`** — a client built against an unreachable endpoint
   fails with a *connection* error, not a *configuration* error. The old test
   proved this by expecting `PhpMcp\Client\Exception\ConnectionException` from
   `build()`. The equivalent assertion becomes `Mcp\Exception\ConnectionException`
   from `connect()`.

2. **`McpServerReaderStreamableHttpTest`** — the 405-crash regression test.
   This is the interesting one: the *bug* it guards against was in the old
   client, and the official client does not have it. The test should be
   **kept, not deleted**, but reframed: it now asserts that the official
   client talks to a real Streamable HTTP server that 405s on `GET`. It
   becomes the proof that the workaround is no longer needed rather than the
   proof that a workaround exists. It conveniently still needs the same PHP
   built-in-server fixture.

3. **`TaskMcpServerEndToEndTest`** — the gate proof over real wire traffic.
   Under option (a) it becomes a `KernelBrowser` request (see above); under
   (b) it keeps spawning a process. Either way the four assertions that matter
   — identity, four tools listed, no `enabled` parameter in any write schema,
   `task_create` persisting disabled — must survive unchanged.

4. **`ToolExecutorValidateTest`** — pure `opis/json-schema` validation, no SDK
   involvement. Should be untouched; listed here only to confirm it is not
   collateral.

5. **`ToolExecutor`'s error classification** — `ToolExecutionException` with
   `ErrorClass::ServerError` for rejected calls. The official SDK's exception
   classes differ, so the catch blocks need re-mapping; the *classification*
   (still a server error, never a silent path) must not change.

## Risks

- **Two server roles, one SDK.** context-shuttle is server-only; this repo is
  both client and server. The client side is where the official SDK is least
  exercised by our own PR (and, per the SDK's own README, less complete than
  the server side), so it deserves the most scrutiny.
- **`ServerProtocol::Mcp` ≠ stdio.** The SDK's client supports stdio and HTTP;
  task-loom is HTTP-only by SPEC. The migration must not accidentally make
  stdio reachable.
- **Pinned exactly.** Pre-1.0, so `0.8.1` with no `^`, same policy and same
  monthly-check doc as context-shuttle. The pin note in
  `docs/design/MCP_SDK_VERSION_CHECK.md` should be mirrored here.

## Verified before writing any of this

Set up a real Streamable HTTP server on loopback — **405 on `GET`**, JSON-RPC
on `POST`, `Mcp-Session-Id` in responses — matching this repo's own failing
scenario, and pointed the official client at it:

```
connect(): OK
serverInfo: tl-test v1.0.0
protocol : 2025-11-25
listTools(): 1 tool(s)  — echo: Echo the message back.
callTool('echo'): isError=false
```

No custom transport, no transport factory, no SSE workaround. That is the exact
scenario `src/Toolbox/Transport/` exists for, handled by the SDK itself.

The same server was probed with `curl` to confirm it genuinely rejects `GET`
with `405` and only answers `POST` — so the client result above is not an
artefact of a lenient fixture.

Everything else in this plan still needs the same treatment — executed against
0.8.1 before it is written down, as was done for context-shuttle. In
particular the exact exception classes for the connection-failure and
request-rejection paths are **not yet pinned down** and are marked TBD above
rather than guessed.

## Ordering

1. `composer.json`: drop the `php-mcp-server` VCS repo and both `php-mcp/*`
   packages, add `mcp/sdk: 0.8.1`; regenerate the lock.
2. `TaskServerFactory` — get the server building.
3. Server-role wiring — option (a) or (b) above.
4. Delete `src/Toolbox/Transport/`, simplify both client call sites.
5. Re-point the tests; keep the 405 regression test as a forward assertion.
6. Docs: SPEC §11, CHANGELOG, and the pin/version-check note.

## Verification

`php bin/phpunit`, `vendor/bin/phpstan analyse --memory-limit=1G`,
`vendor/bin/php-cs-fixer fix --dry-run --diff` — the gates in AGENTS.md.
This sandbox has no PHP 8.5 and no Docker, so CI is the gate; the same
execute-first discipline applies as on context-shuttle.

## Rollback

Revert the branch. `main` is untouched and the fork still exists.
