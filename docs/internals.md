# AIAccess internals

A multi-provider AI client (Anthropic Claude, OpenAI, Gemini, Grok, DeepSeek, plus a
generic client for anything else speaking the `chat/completions` dialect). The
value is a cross-cutting model you cannot read off the signatures: **the interfaces
converge, every implementation diverges**, and the shared conversation model is
deliberately narrow. One file.

## Provider model: interface convergence, implementation divergence

There are exactly three service interfaces — `Chat\Service::createChat`,
`Batch\Service`, `Embedding\Service` — and each provider `Client` implements the
**subset** it supports (Claude: Chat+Batch; OpenAI: Chat+Embedding+Batch; Gemini:
Chat+Embedding; Grok/DeepSeek: Chat only).

**The whole library has exactly one abstract base class — `Chat\Chat`.** Everything
else (`Client`, `ChatResponse`, `Batch`, `BatchResponse`) is an interface with a
**fully independent `final` implementation per provider**. There is no shared base
`Client`, no "OpenAI-compatible" base even for the OpenAI/Grok/DeepSeek family — each
one **re-duplicates** `callApi()`, error mapping, and parsing from scratch. The
duplication is intentional; a "DRY it into a base class" refactor fights the design,
because the providers genuinely diverge on every axis:

| axis | Claude | OpenAI | Gemini | Grok/DeepSeek |
|---|---|---|---|---|
| endpoint | `v1/messages` | `v1/responses` | `:generateContent` | `chat/completions` |
| auth | `x-api-key` header | `Bearer` | `x-goog-api-key` header | `Bearer` |
| request | flat `messages[]` | `input[]` + `instructions` | `contents[].parts[]` | flat `messages[]` |
| assistant role | `assistant` | `assistant` | **`model`** | `assistant` |
| usage keys | `input_tokens`/`output_tokens` | same | `promptTokenCount`/`candidatesTokenCount` | `prompt_tokens`/`completion_tokens` |
| reasoning tokens | `output_tokens_details.thinking_tokens` | `output_tokens_details.reasoning_tokens` | `thoughtsTokenCount` | `completion_tokens_details.reasoning_tokens` |
| finish reason source | `stop_reason` | **`status`, then `incomplete_details`** | `finishReason` (never signals tool use) | `finish_reason` |

## HTTP: buffered curl, no streaming, no retry

`Http\Client::fetch` has one impl, `CurlClient` (`@internal`), injected into each
provider `Client` (default `new CurlClient`) — the seam for mocking in tests. Facts
that surprise:

- **There is no streaming / SSE anywhere.** `curl_exec` is fully buffered, and the
  `stream` option was removed from every provider because it produced a response the
  JSON decoder could not read. Adding streaming needs a new seam (`fetch` returns a
  finished `Response`, not a generator). When it lands, four different stream endings
  have to be handled: OpenAI's terminal event carries the whole response, Claude's
  `message_delta` usage is cumulative rather than incremental, Gemini sends no `[DONE]`
  at all, and xAI puts usage in a chunk whose `choices` is empty. DeepSeek also injects
  `: keep-alive` SSE comment lines that a naive parser will choke on.
- **Retrying, logging and caching are decorators, not behaviour of the client.**
  `RetryClient` backs off on 408/429/5xx and network failures, `ObservableClient` times
  requests and redacts the auth headers, `CachingClient` replays identical requests from
  disk for development. They compose, and the order changes what you see: observing a
  retrying client logs every attempt, the other way round logs only the outcome.
- **JSON decoding is content-type-driven** — the body is decoded only for
  `application/json`/`+json`, so `callApi(..., isJson: false)` returns a raw string
  (used for JSONL batch-result downloads). "Always decode JSON" breaks batch parsing.
- Errors: HTTP ≥400 → `ApiException` (message from `data.error.message`), duplicated in
  every `callApi`; transport and JSON-decoding failures → `CommunicationException`.
  Both under `ServiceException`; `LogicException` is client misuse. **OpenAI can fail
  inside HTTP 200**: `status: failed` carries a top-level `error`, which
  `Chat::generateResponse()` turns into an `ApiException`.
- **`trigger_error()` is gone from the library.** Per-item batch failures are readable
  through `Batch\Response::getErrors()` (keyed by custom_id), and embedding count
  mismatches raise `UnexpectedResponseException`. The single remaining warning is
  Gemini's alternating-roles check, which predicts an API error we cannot prevent.

## The conversation model is part-based, and transactional

`Chat\Chat::sendMessage` is **transactional**: it snapshots `$messages`, appends the
user turn, calls the abstract `generateResponse()`, and **rolls the history back on
exception**. So a subclass must manage history *only* through the base, and
`generateResponse()` must be pure w.r.t. `$messages`.

A `Message` holds a **list of `Part`s** (`TextPart`, `ReasoningPart`, and `Media`),
plus a `Role`. A plain string becomes one `TextPart`, so `addMessage('hi', Role::User)`
still works and **a text-only conversation produces exactly the payload it always
did** — the parts are internal until something non-text appears. Consequences:

- **`getText()` joins text parts and nothing else.** Reasoning never leaks into it,
  because anything that lands there flows through `sendMessage()` into the history and
  is echoed back to the model next turn.
- The model turn is appended **when it has at least one part**, so a turn carrying only
  reasoning (or, later, only a tool call) is no longer silently dropped. `getMessage()`
  on the response is exactly what goes into the history.
- **An opaque payload belongs to the provider that issued it.** `ReasoningPart` (and
  `TextPart`, because Gemini hangs `thoughtSignature` off ordinary text parts) carries
  `$provider` + `$raw`, and `buildPayload()` replays `$raw` **only when the tag matches
  its own provider**. Nothing is ever translated between providers: replaying a Claude
  history on OpenAI works, it just loses the earlier reasoning. Get this wrong and
  multi-turn breaks loudly — Claude 400s on a modified signature, Gemini answers
  `MISSING_THOUGHT_SIGNATURE`.
- **The chat/completions providers replay reasoning only next to a tool call.** DeepSeek,
  Grok and the generic OpenAI-compatible client put `reasoning_content` back on an
  assistant turn that carries `tool_calls`, and leave it out everywhere else. Neither
  half of that is a hard requirement — a tool turn returned without it is accepted, as a
  live check confirmed — but keeping it there preserves the chain of thought across
  rounds, while replaying it in plain chat is untested territory not worth the risk.
  OpenAI replays a reasoning item **only when it carries `encrypted_content`** — without
  it the item is a server-side reference we cannot reconstruct.
- **A part in the history is not automatically content on the wire**, so every
  `buildPayload()` drops a turn that boils down to nothing: an unreplayable payload,
  or reasoning alone. Sending it as empty content is not the safe fallback — Claude and
  Gemini reject empty content outright.
- **Tool calls are one API over five wire formats.** `addTool()` and
  `setToolChoice()` on the chat, `getToolCalls()` on the response, `addToolResult()`
  back on the chat; the loop itself is still the caller's to drive. What each provider
  demands underneath has nothing in common:

| | tool definition | pairing key | result travels as |
|---|---|---|---|
| Claude | `{name, input_schema}` | `tool_use.id` | `tool_result` block, **first** in a user turn |
| OpenAI | **flat** `{type, name, parameters}` | **`call_id`**, not `id` | `function_call_output` item |
| Gemini | `functionDeclarations[].parametersJsonSchema` | `functionCall.id`, often absent | `functionResponse` in a **user** turn, matched by **name** |
| DeepSeek / Grok | nested under `function` | `tool_calls[].id` | one `role: tool` message **per result** |

  Hence `ToolResultPart` carries the name as well as the id, and one `Role::Tool`
  message expands into several wire messages where the format demands it. All results
  for one turn stay in a single message, because Claude rejects them spread apart.
- **Malformed arguments are the model's mistake, not a transport failure.** A tool call
  whose JSON will not decode arrives with empty `arguments` and a filled
  `argumentsError`, so the caller can hand the model its own error instead of catching
  an exception.
- **Gemini has extra rules no one else does:** the first message must be `User`, and it
  warns on two same-role messages in a row (strict alternation). A history valid on
  another provider can throw/warn on Gemini.

## Batch has two mechanisms and a shared payload

One `Batch\Batch` interface, two submit mechanisms: **Claude** posts inline
`requests[{custom_id, params}]`; **OpenAI** serializes each chat to a JSONL line,
uploads it as a file, and submits `input_file_id`. Both call `Chat::buildPayload()`,
so request-shaping is **shared with live chat** — which is why `buildPayload()` is
**`public` (@internal) only for Batch's sake**; changing its signature breaks batch
across layers. `BatchResponse::getMessages()` is **lazy + memoized + status-gated** —
a **getter that makes an HTTP call**, only when the batch is `Completed`, returning
`Message` objects keyed by `custom_id`, with per-item failures in `getErrors()`
alongside. Tools work in a batch only as far as the model asking: a returned message
can carry a `ToolCallPart`, but nothing runs it, because a batch has no round to answer
in. Status enums and date parsing (Claude ISO string vs OpenAI unix `@ts`) differ per
provider — unifying them is a silent regression.

## Effort is the one knob that is genuinely shared

`Chat::setEffort()` is the only model-tuning method on the base class, and it exists
because sampling parameters are dying: `temperature`/`top_p`/`top_k` are rejected with
400 by Claude Opus 4.7 and the whole Claude 5 line, and by GPT-5.1+ (unless effort is
`none`), silently ignored by
Gemini 3.6+ and by DeepSeek whenever thinking is on, which is its default. Each
provider maps the enum in its own `buildPayload()`: `output_config.effort`,
`reasoning.effort`, `thinkingConfig.thinkingLevel`, `thinking.reasoning_effort`,
`reasoning_effort`.

Two rules hold the design together. **Nothing is sent unless the user calls
`setEffort()`** — provider defaults are never overwritten, which is why DeepSeek keeps
thinking on until asked otherwise. And **there is no table of model capabilities**: a
model that lacks the dial answers with `ApiException` (Claude Haiku 4.5 does exactly
that). A capability table would be stale within weeks and is the maintenance burden
this library exists to avoid — the same reasoning that keeps model names as plain
strings.
