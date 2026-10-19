# UTF-8 at the byte budget — where capped text may be cut

**Status:** fix, 2026-10-19. Code changed: `src/Context/Utf8.php` (new), eleven
cap call sites across seven files, and a Doctrine `json` type. Regression suite:
`tests/Unit/Context/Utf8Test.php` (19 cases) plus `tests/Unit/Doctrine/`.

## The report

A chat session died mid-reply with:

> `Could not convert PHP type "array" to "json". An error was triggered by the
> serialization: Malformed UTF-8 characters, possibly incorrectly encoded`

It named a container and a column, and nothing else: no string, no offset, no
caller. Reading it as a JSON problem is the trap — the encoder was fine. The
payload handed to it was not valid UTF-8, and it had been made invalid a moment
earlier by a length cap.

## What actually happened

Every cap in this codebase is a **byte** budget, and that is the right choice: a
token estimate is a function of bytes, and a byte count is what keeps a cap
honest across alphabets. But the caps were spent with `substr()`, which counts
bytes and cuts at whatever offset it is given. When that offset lands inside a
multi-byte character, the result ends in a partial sequence:

```
… and the build finished in 3.4s — 常
                                    ^ budget ends here
```

`strlen()` still counts it. `json_encode()` does not accept it, and neither does
MySQL's `utf8mb4` on the far side. Anything downstream fails on the write, in the
middle of a conversation, with the message above.

The shape of the failure explains why it looked sudden and mysterious: it is
**data-dependent, not code-path-dependent**. A session runs for days on ASCII
tool results and then one LLM summary contains an em dash, an ellipsis or a CJK
character, and the very first cap whose budget lands inside that character kills
the turn. Nothing in the request changed; the content did.

### Why no test caught it

Every pre-existing cap test fed `str_repeat('x', 20_000)`. On ASCII a byte offset
and a character offset are the same number, so a `substr()` cut can never split
anything, and the assertion `strlen($out) <= $max` holds for both the broken and
the correct implementation. The tests were not weak, they were **unable to
distinguish** the two: the fixture removed the only variable that mattered.

## The fix

`App\Context\Utf8` is now the one place a string is cut by a byte budget.

| Method | Meaning |
| --- | --- |
| `cut($text, $maxBytes)` | At most `$maxBytes` bytes from the start, on a character boundary. Splits a character on the boundary → drops it whole. |
| `tail($text, $maxBytes)` | The same from the end, for trailing windows. |
| `isValid($text)` | One PCRE call (`//u`), no extension needed. |
| `scrub($text)` | Replace malformed sequences with U+FFFD. Backstop only. |

The budget keeps its exact meaning — at most `$maxBytes` bytes out, measured as
before — so no cap silently changes size. The only difference is that a cut
landing mid-character backs up to the start of that character, losing at most
three bytes.

### Two details that are load-bearing

**`cut()` validates even when the text already fits.** The loop detector hands
over `substr($b, $offset, $n)` — a window that is *exactly* the budget and may
already end mid-character. An early `if (strlen($text) <= $max) return $text;`
would return the broken window untouched, which is precisely the case the helper
exists for. This was the bug in the first draft of the fix.

**The walk-back is bounded at three bytes.** An unbounded `while (!isValid())`
looks more correct and is worse: if the input is *already* malformed somewhere
earlier, no amount of trimming the end will ever make it validate, and the loop
strips a 17 KB buffer to empty at one full-string PCRE check per byte — quadratic
on every tool result. Three steps fixes what is fixable and leaves an
already-broken input alone, where `scrub()` can see it.

## Call sites

| Site | Budget | What it caps |
| --- | --- | --- |
| `Context/ContextWindow.php` | per-input, per-result | an input artifact, a tool result |
| `Chat/ChatContextWindow.php` | `maxChars` | a tool result into the chat window |
| `Admin/TimelineEntry.php` | `max - 1` | a timeline line |
| `Admin/RunDigest.php` | `entryChars`, `chars` | a digest entry, a flattened cell |
| `Llm/LoopHit.php` | 40 | the evidence preview in a loop-break message |
| `Llm/LoopDetector.php` | `BUFFER_LIMIT`, period, 120 | the rolling buffer tail, block and recurrence previews |
| `Toolbox/CatalogSynchronizer.php` | 255 | a catalog sync error message |

Deliberately **not** changed:

- `ChatEngine::titleFrom` / `Chat::normalizeTitle` use `mb_substr`, which counts
  *characters*. Different semantics, never mid-character; converting them would
  change titles.
- `OpenApiServerReader` caps a tool name and a slug after `preg_replace('/[^a-zA-Z0-9_-]+/', '_')`,
  so the subject is ASCII by construction and a byte cut is safe.
- `SseEventParser` splits on `"\n"` positions, which are single bytes in any
  encoding and cannot straddle a character.
- `LoopDetector`'s hash/anchor/prefilter windows never leave the object; they are
  compared, not encoded.

## The backstop

`App\Doctrine\Type\Utf8JsonType` extends Doctrine's `JsonType` and is registered
as the `json` type in `config/packages/doctrine.yaml`. If a write still fails
with `SerializationFailed`, it scrubs every string in the value (keys included),
retries once, and rethrows the original if nothing changed.

It exists because `cut()` fixes the caps, and caps are not the only way a broken
byte can arrive: an upstream stream, an MCP server or a tool's own encoding bug
can produce one. The right response to that is a U+FFFD replacement character in
the stored payload — a legible record of a real problem — rather than an aborted
transaction in the middle of a reply.

It is deliberately narrow. Only a failure that scrubbing actually fixes is
retried; recursion, INF/NAN and unwritable resources still throw exactly as
before, because scrubbing would not help and swallowing them would hide a bug.
Because `scrubDeep()` returns the *identical value* when there was nothing to
clean, "nothing changed" is an exact test for "this was not a UTF-8 problem".

## Tests

`tests/Unit/Context/Utf8Test.php` — 19 cases, 99 assertions, over one character
of each UTF-8 width (2-byte `é`, 3-byte `…` and `漢`, 4-byte `😀`). Each is cut at
every budget from one byte into the character to its last byte, which is the
whole boundary space. Plus: already-malformed input is left alone
(non-quadratic), `tail()` mirrors `cut()`, `scrub()` produces valid UTF-8, and
one end-to-end case encodes the result with `json_encode` to reproduce the
reported failure directly.

The suite was verified to **fail against the old implementation**: reverting
`cut()` to plain `substr()` turns 10 of the 19 red, including the production
shape (`cut at 17203 is not valid UTF-8`). Reverting `LoopHit::describe()` to
`substr()` fails its preview test with `describe() emitted invalid UTF-8`.
Without that check the suite would only prove the code agrees with itself.

`tests/Unit/Doctrine/` covers the backstop: a malformed payload round-trips to
U+FFFD instead of throwing, and a non-UTF-8 failure still throws.

## If it recurs

1. The message names the column, not the culprit. To find the string, catch
   `SerializationFailed` at the write and check `Utf8::isValid()` on every
   string in the payload — `scrubDeep()` in `Utf8JsonType` walks the value in
   exactly this way.
2. Check every cap on the path end to end. A cap that is still `substr()` is
   fine only if its subject is provably ASCII, and that proof belongs in a
   comment beside it.
3. Do not "fix" it by widening the column or switching to `jsonb`. The bytes are
   invalid before the database sees them.
