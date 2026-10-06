# Chat Tools

**Status:** design — **built**. The whole of §1–§5 shipped together (SPEC
§15.8); §6 is the list of what deliberately did not. This note exists because
giving chat a toolbox is not a feature addition: it is the first time a
conversation can *act*, and every mechanism in the run engine that makes
acting safe assumes a task.

Two things the build settled that the design had left open, recorded here
rather than only in the code:

- **Tool turns ride the existing `tools` lane** (§6 said "no second tool
executor"; it did not say "no second lane"). A tool turn is I/O-bound on
somebody else's server and must not hold the model, which is as true for a
conversation as for a run — and a second lane would have bought another lane
to reason about plus another co-located requirement for the boot sweep's
lane-ownership check.
- **A declaration that resolves to nothing is still recorded** (§2 did not
think about it). "I chose no tools" and "I chose tsak-tools and it matched
nothing" are different states, and the second is precisely why the
*declaration* is stored separately from its resolution — the picker reopens
showing what was typed, and the human is told nothing matched.

## 0. The change in one paragraph

A conversation gets a **toolbox**, chosen the same way a task's is chosen —
by tag or explicitly, from the discovered catalog — and **re-chosen before
each message you send**. Start a chat with nothing but memory recall; after a
few exchanges, tick the task-management tools, and now the assistant can act
on the thing you just talked through. The toolbox is frozen when the exchange
starts, and locked for that exchange's whole life.

## 1. Why this needs an argument, not just an implementation

The run engine's tool safety is *structural*, and it rests on a single
sentence repeated in SPEC §2.1 and §4.1:

> The toolbox is frozen at run start, and a tool outside the frozen toolbox
> never dispatches.

Everything downstream leans on that. The engine's injection defense is not a
filter on tool output — it is that `executeToolCall()` looks the requested
name up in a map built from the frozen snapshot, finds nothing, and records
`tool_not_found` without ever calling the model's bluff. A tool the model
cannot name is a tool the model cannot use, whatever a tool result tells it to
do.

A conversation cannot have that as-is, because a conversation does not start
once. You pick tools, talk, pick *more* tools, and talk again. So the question
this note answers is: **what is the frozen toolbox of a conversation, given
that the person choosing it keeps changing their mind?**

## 2. The rule: an exchange is a run

The answer falls out of the aggregate that already exists. An exchange *is* a
run-shaped execution over a conversation (§3.1 of `CHAT_AND_CAPACITY.md`), so
the toolbox is exactly as frozen as a run's — scoped to the exchange:

> **The toolbox is chosen when the exchange starts, and frozen for that
> exchange's whole life.**

Everything the run engine's rule buys, this buys:

- The model sees one tool set for the entire turn, so the tool definitions on
  the wire match the toolbox in the prompt match the map dispatch consults.
  No request is ever composed against a toolbox that has since moved.
- A tool outside the frozen set never dispatches — the same
  `tool_not_found` path, the same injection defence, in the same words.
- The exchange record says which toolbox it ran with, so "what could she see
  when she did that?" is answered from the record rather than inferred from
  whatever the catalog looks like today.

And it costs the human nothing they asked for: they re-choose before *sending
a message*, which is before the next exchange starts. "Configurable before
sending each message" and "frozen when the exchange starts" are the same
sentence.

### 2.1 The snapshot is the exchange's, not the run's

**Every exchange freezes its own toolbox.** Exchange 3's choice does not
silently become exchange 4's — the *authority* is always the exchange it
belongs to, and an exchange's behaviour never depends on a later one.

But the *picker* reopens showing your last answer, so the default is "same as
last time" while still being visibly, deliberately re-chosen. The two halves
are what make the permission surface safe **and** cheap:

- Safe, because what she could see is recorded per exchange rather than
  inferred from a mutable preference, so "why could she do that?" is answered
  by the exchange that did it.
- Cheap, because what she can do *right now* is always on screen rather than a
  thing you have to remember — which is the property that made the original
  no-carry-forward rule worth choosing.

**Turning everything off is an answer, and it carries like any other.** The
subtle failure this avoids is real and easy to write by accident: if "nothing
chosen" were stored as *no record*, then `[]` (a deliberate empty selection)
and `NULL` (no selection recorded) would be the same bytes, and a tool you
switched off would quietly come back on at the next message. So the empty
declaration is written as the positive fact it is, and the column stays NULL
only for rows that genuinely predate chat tools:

    columns NULL                 this row predates chat tools
    declared [], no snapshot     this exchange ran with no tools, chosen
    declared […], snapshot […]   this exchange ran with these

Three states, three readings, no ambiguity — and the "pre-select last time"
refinement this section used to defer is now simply what the picker does.

## 3. Two claims the run engine could make and chat cannot

This is the part worth writing down, because it is the difference between
reusing the machinery and assuming you have.

The run engine asserts two things about a tool call, and both are only quiet
about being false because a run's transcript is short and its model saw the
toolbox:

1. **Nothing but the model issued this call.** Implied by "the toolbox is
   frozen" plus "the model is the only thing that composes requests".
2. **Nothing but the real server produced this result.** Implied by the fact
   that results only ever come from the executor.

In a conversation neither is implied the same way. A chat transcript is long,
grows for years, and is *half written by the model itself* — which is exactly
the surface §2 of the capacity note already calls a prompt-injection hazard.
A hostile instruction has, in a chat, far more places to hide than in a
five-turn task run.

So the design keeps both properties, but as **recorded facts** rather than
inferences:

- **The invocation row.** Every dispatch writes a `tool_call` event carrying
  what was asked, by which exchange, under which toolbox version. The engine
  still refuses anything outside the frozen set — that check is unchanged and
  stays the enforcement. The row is the *evidence*: after the fact, "did
  anything dispatch that the model did not ask for?" is answerable from the
  ledger rather than from a chain of implication.
- **The result attestation.** Every tool result is written by the executor,
  with a `tool_call_id`, into the same ledger, and the transcript read knows
  the difference between a `tool` message it produced and prose the model
  wrote. The prompt compiler may only render a `tool` role message from an
  attested result — never from anything else in the ledger, and never from a
  tool's content that happens to look like a result.

Neither is a new defence mechanism. They are the two invariants the run
engine gets for free, made explicit because in a conversation they are no
longer free.

## 4. The loop ceiling

A run is allowed fifty exchanges (SPEC §5.2, `TASKLOOM_STEP_BUDGET`) before
the budget fails closed. For a chat that is not a budget, it is an outage
notice: fifty generations of a reasoning model is minutes of a person
watching a spinner, and — because chat is the *priority head* (§4 of the
capacity note) — minutes in which every task is behind it.

So chat gets its own ceiling, `TASKLOOM_CHAT_TOOL_ROUNDS`, default **6**,
counting assistant turns within one exchange that request tools. Past it the
exchange fails with `BudgetExceeded` and says so on the page, exactly as a
failed reply does (§15.4 of the SPEC).

The honest framing: this is a **capacity** bound as much as a correctness one.
Six tool round-trips is already a lot to ask someone to wait for, and a reply
that needs more than six is usually a task wearing a conversation's clothes —
which is the thing the person should be told, not silently waited out.

*(Decision: chat's ceiling is separate from, and much lower than, the run
budget. Reusing `TASKLOOM_STEP_BUDGET` would have made "the run budget" mean
two different things at two different scales, which is how a knob stops being
a knob.)*

## 5. Failure and the person waiting

§15.4's rule holds unchanged and is the reason this section is short: a chat
turn that dies has somebody staring at it, so a failure is classified, shown,
and never silently retried.

What tools add is a second, *recoverable* failure. A tool call that errors is
**not** an exchange failure — it is information, and the model gets it back in
the same structured `{error, tool, detail}` shape the run engine uses, so it
can say "that didn't work" or try a different argument. That is the behaviour
you want from a conversation: the alternative (an error kills the exchange)
turns a typo in an argument into a lost reply.

The exchange fails only when the model cannot produce a usable answer at all:
a transport failure, an empty reply, or the ceiling. Those are the cases where
continuing would leave a person with nothing to read.

## 6. What this deliberately does not do

- **No mid-exchange tool changes.** Changing the picker mid-turn would mean
  the tool definitions on the wire no longer match the dispatch map. The
  form is inert while a reply is pending rather than accepting a change it
  would have to ignore.
- **No approval step for mutating tools.** A task's gated writes are gated
  because an *agent* writes tasks with no human present; here a human chose
  the toolbox, in front of the thing being written. Adding approval would be
  inventing a boundary the chat does not have a problem at — and the run side
  already covers the case that does (a task creating tasks).
- **No tools for `Nia` to initiate with.** §9 of the capacity note stands: v1
  responds. A tool loop only ever runs inside an exchange a human triggered.
- **No second tool executor.** The same `ToolExecutorInterface`, the same
  validate-before-dispatch, the same per-call client, the same deliberate
  `server_error` for OpenAPI tools. A chat that needs a new executor means
  this design is wrong.
