# Session endings — a proposal (draft for discussion)

Status: BUILT — 2026-10-13 (phases 0 and 1; see the build record below).

**Build record — what shipped, against this note.** Phases 0 and 1 shipped
together in one change (`feat/session-endings`), the combination §7
recommended (deployment defaults first; per-session later — phase 2 remains
open, along with its memory-carry landmine):

- **Phase 0** — the five restatements collapsed to one derivation.
  `SessionDeclarationOutcome::meaning()` / `bullet()` hold the meanings;
  `SessionEndings` renders the schema, the tool description, the slice note
  and the completion text from them; the engine's fallback refusal is the
  frozen set's (`SessionDeclareTool::labelList()`).
- **Phase 1** — `TASKLOOM_SESSION_ENDINGS` / `TASKLOOM_SESSION_UNDECLARED` /
  `TASKLOOM_SESSION_MAX_UNDECLARED`; the filtered `session_declare` entry
  (`SessionDeclareTool`) frozen into the toolbox snapshot, with the read
  path honouring the stored entry — the note's landmine, pinned by
  `ToolboxSnapshotTest`; the policy frozen into the checkpoint beside the
  budgets; the settle path refusing with the alternatives named; the
  documented `continue` mechanics (succeeded + a typed `undeclared` marker
  + the next slice in the same commit) and the counted streak ceiling —
  brought forward as §8 recommended, not deferred.
- **Open question 1** resolved as recommended: an exhausted slice budget, a
  failed turn, a tripped breaker still derive `stopped` under `continue` —
  "no automatic slice retry" holds for everything that went wrong. An
  empty terminal message also stops under either policy (§4).
- **§5 (asking)** and **§6's alternate (`endings={milestone}`)** are
  supported by the machinery but untouched as policy; the Nia shape
  (`milestone,blocked` + `continue`) is a configuration, not a special case.
- **§7's phase-2 landmine still stands.** Settings changes belong in the
  phase-1 knobs until memory carry on replacement exists.

- **Guard cuts are not settlements (the merge with the streaming work).**
  When the streaming client's guards stop a generation (looping, silent,
  duration cap, stream failure), the partial output can reach the terminal
  path — but a cut is the harness stopping a generation that misbehaved,
  not the model choosing to end its slice. Under EITHER policy it stops the
  session for a human, with the stop's own class and detail on the failure
  (`settleUndeclaredSlice`'s stop branch; pinned by
  `testAGuardCutTerminalMessageStopsEvenUnderContinue`). Continuing past the
  cut would defeat the very guard that fired. A cut that captured nothing
  never reaches the settle path at all: the client throws, and the slice
  settles failed with the named reason.

What follows is the original proposal, kept as the record.

## 1. The problem

Which endings a session has is part of its *constitution* — the same category
as its toolbox and its budgets. Today it is enforced nowhere and stated only
in prose, restated by hand in five places:

1. `SessionTool::Declare`'s description;
2. the schema's `outcome.description`;
3. `PromptTemplate::SLICE_COMPLETION`;
4. `PromptCompiler::compileForSlice`'s note;
5. `RunEngine`'s fallback string (`'outcome must be one of: milestone, done, blocked'`).

An operator who needs different endings — an endless session that must never
declare `done` — has one lever: ask the model, in the brief, not to. That is
policy smuggled into prompt text: unenforceable, easy to contradict, invisible
to every surface that reasons about a session's state, and it does not survive
a model that ignores it.

## 2. The shape — one concept, two fields

| Field | Values | Meaning |
|---|---|---|
| `endings` | non-empty subset of `{milestone, done, blocked}` | the declarations this session may make |
| `undeclared` | `stop` (default) · `continue` | what a settlement without a declaration means |

- The vocabulary stays closed (§5's "a closed vocabulary, not a catalog"):
  this adds no cases; it gates which of the existing three apply to a session.
- Resolution order: task field, else deployment default, else built-in
  (`{milestone, done, blocked}`, `stop`). Same shape as
  `completionOverride ?? template->completion()`.
- Frozen per slice, like everything constitutional (SPEC §4.1): `endings` ride
  the slice's toolbox snapshot (the `session_declare` entry) and its compiled
  prompt head; `undeclared` rides the checkpoint beside the budgets
  (`LoopState`).
- `endings = {}` is refused: a session with no declarable endings can only
  ever settle by silence — no use, and a confusing UI.
- These are the primitives; operator-facing presets can layer over them later
  (e.g. *open-ended* = `{milestone, blocked}` + continue, *goal* = all three +
  stop, *one-shot* = `{done}` + stop). Not proposed for v0.

## 3. Enforcement — three layers, one source

1. **The wire.** The `session_declare` schema's enum and description are
   filtered to `endings` and frozen in the snapshot. Mechanic: the resolver
   produces the filtered variant at slice creation (a `ToolDefinition`
   decorator around the `Declare` case); the snapshot write side already
   stores `description` + `schema` for harness entries — but
   `ToolboxSnapshot::toDefinitions()` **discards them on rebuild**, re-deriving
   from the enum case. That read path must honour the stored entry, or the
   filter is lost on the first rehydrated turn (a fresh worker mid-run is
   exactly when it matters).
2. **The settle path.** `RunEngine` already validates declarations against the
   snapshot's tool. A declaration outside `endings` is refused with feedback
   naming what *is* available ("`done` is not available to this session;
   declare `milestone` to continue"). The hard-coded fallback string becomes
   the frozen set's.
3. **The prose.** `SLICE_COMPLETION` and the slice note render from the same
   set: bullets only for admissible endings, with explicit wording when `done`
   is absent ("this session has no completion — it runs until the operator
   stops it"). The five hand-written restatements above collapse to one
   derivation.

## 4. Silence, and the loop question

Today a terminal message with no declaration stops the session (§8: loud, no
retry). With `undeclared: continue`:

- The slice settles **succeeded**, not failed: under this policy an
  undeclared ending is a legitimate settlement, so the run marks succeeded
  with the message content as its Completion artifact, plus an explicit
  `undeclared (continues)` ledger marker so the digest can count the pattern.
  The next slice launches in the same commit, the way `milestone` does
  (`SessionSliceEngine::advance()`).
- Derived state honors the policy: newest slice **succeeded + null declaration
  + `continue`** → `owed` (the sweep repairs a lost launch), instead of
  `stopped`. A settlement that *failed* — budget exhausted, failed turns,
  tripped breaker — still derives `stopped`, because its status is not
  "succeeded": §8's "no automatic slice retry" stays true for everything that
  actually went wrong. *(Open question 1.)*
- An **empty** terminal message still stops even under `continue`: a slice
  that ends saying nothing is a stall, not a settlement.
- No loop detector — none is needed. A semantic progress detector would be
  the second source of truth this project refuses. The bound that fits the
  existing idiom is a **counted streak**, circuit-breaker-shaped:
  `SESSION_MAX_UNDECLARED` consecutive undeclared settlements before the
  session stops for a human (`circuitBreakerThreshold` is the precedent — a
  count, not a judgment). Plus §8's already-pencilled ceiling
  (`SESSION_MAX_SLICES` / wall clock, *deferred, not rejected*). Recommend
  bringing one of the two forward alongside this, not after.

## 5. Asking

`blocked` conflates three things: "cannot proceed", "have a question", "stop
and wait". A session with a better ask surface should not need it.

- Sessions already have a non-stopping ask wire: `send_alert` for the
  notification, operator memory notes for the answer (rendered as `[operator]`
  steering on the next request).
- A first-class ask — a question that lands in the attention surface while the
  session continues — is a future nicety, and context-shuttle's `ask_user` is
  the shape worth copying. Note the async constraint: a slice cannot usefully
  *block* on a human (it parks the session invisibly); ask-and-continue is
  native, ask-and-wait is `blocked` with a payload.
- Excluding `blocked` is only honest when the session has another way to reach
  the operator; the refusal for a `blocked` call it cannot make should say so —
  "this session cannot park itself; reach the operator with your alert tool or
  a note".
- Vocabulary worth reserving a thought for: `rest` — a designed idle ("nothing
  for now; resume me when you like"), distinct from `blocked` (I need you) and
  `done` (goal complete). A new case is a review-level change; not proposed
  for v0.

## 6. For Nia

Recommended: `endings = {milestone, blocked}`, `undeclared = continue`.

    TASKLOOM_SESSION_ENDINGS=milestone,blocked
    TASKLOOM_SESSION_UNDECLARED=continue

- She can never end the session herself; silence never kills her; her
  existence is the operator's switch.
- Keep `blocked`: without it, a session that genuinely cannot proceed spins
  through slices instead of parking loudly. Availability is machinery;
  discretion is character — "ask with an alert or a note; `blocked` is for
  genuine stuckness" belongs in her soul/brief, while *that `done` does not
  exist* belongs in config. That split is the point.
- With this in place her brief drops the "never declare done" paragraph.

Alternate: `endings = {milestone}` — she cannot park herself at all; asking is
alert-and-continue only. Defensible; one value less.

## 7. Rollout — phases, and one landmine

- **Phase 0 — derivation only (no policy).** Collapse the five restatements
  to one source: `SessionDeclarationOutcome` gains its meanings once; the
  schema, the const, the note and the refusal strings render from it. Zero
  behavior change, and it is the seam every later phase needs. Can land
  immediately.
- **Phase 1 — deployment defaults (covers Nia immediately).** Env knobs,
  derivation, enforcement, the `continue` mechanics; docs §5/§8/§11. Touches no
  task row.
- **Phase 2 — per session.** Task fields + admin editor / approval queue / MCP
  task schemas + session-page copy ("open-ended — cannot complete").
- **Landmine — check before editing any live session task.** A content change
  to an enabled or already-run session creates a replacement: a *new task
  row*. Session memory is keyed by task id and nothing reparents it (checked —
  no migration path exists), so approving a replacement starts the successor
  with an empty store. Continuity severed. Either add memory carry on
  approval, or make settings changes only through Phase 1 until it exists.
  This is why Phase 1 is worth doing first even though Phase 2 is the "right"
  shape.
- Surfaces: `Task` (+migration, Phase 2), `ToolboxResolver` /
  `ToolboxPreviewer`, `ToolboxSnapshot::toDefinitions` (must honour stored
  harness description/schema), `PromptCompiler` / `PromptTemplate`,
  `RunEngine` (terminal path, refusal detail, `settleSlice` no-launch cases),
  `SessionSliceEngine` (+ policy in `createSlice`, derived state),
  `LoopState`, `config/services.yaml` / `.env.example`, `SESSION_TASKS.md`
  §5/§8/§11, tests (`SessionToolTest`, `SessionSliceFlowTest`,
  `SessionMemoryInjectionTest`).

## 8. Open questions

1. `continue` on budget exhaustion too? (Recommend: not yet — the loop-prone
   case, and it wants the ceiling first.)
2. Ceiling now or next? (Recommend: next, not never.)
3. `rest`: wanted eventually, or are `blocked` + alerts enough?
4. Names: `endings` / `undeclared` are placeholders.
