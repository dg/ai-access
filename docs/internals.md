# AIAccess internals

A multi-provider AI client (Anthropic Claude, OpenAI, Gemini, Grok, DeepSeek, plus a
generic client for anything else speaking the `chat/completions` dialect). The
value is a cross-cutting model you cannot read off the signatures: **the interfaces
converge, nearly every implementation diverges** (the one measured exception is below),
and the shared conversation model is deliberately narrow. One file.

## Provider model: interface convergence, implementation divergence

There are four service interfaces — `Chat\Service::createChat`, `Batch\Service`,
`Embedding\Service`, `Image\Service` — and each provider `Client` implements the
**subset** it supports (Claude: Chat+Batch; OpenAI and Gemini: all four; Grok:
Chat+Image; DeepSeek and `OpenAICompatible`: Chat only). **Extra capability lives on
the concrete class, not in a new interface**: `addImageRequest()` is on `OpenAI\Batch` and
`Gemini\Batch` and nowhere in `Batch\Batch`, because Claude cannot draw and should not
have to declare that it can. The same door serves Claude's own extra, per-request
models in one batch, which the other two forbid.

**A model is a request parameter, never an identity, and the default lives on the
client.** Every entry point takes it last and optionally; the `Client` holds
`chatModel`/`imageModel`/`embeddingModel` for exactly the capabilities it implements,
and resolves `$model ??= $this->xModel ?? throw` inline at each one. Two consequences
are easy to trip over. `Batch` is *handed* the defaults by `createBatch()` rather than
reading them back from the client, so a batch built by hand (`new Batch($client)`, as
the tests do) has none and must be told the model — this is deliberate, since the
alternative was a public `resolve*Model()` on every client. And `Chat::setModel()`
changes the model for later turns while the history stands, which is sound only within
one provider, because provider-tagged parts replay to their issuer alone.

`Client`, `Batch` and `BatchResponse` are interfaces with a **fully independent `final`
implementation per provider**: each re-duplicates `callApi()` and its error mapping from
scratch. The duplication is intentional and a "DRY it into a base class" refactor fights
the design, because the providers genuinely diverge on every axis:

| axis | Claude | OpenAI | Gemini | Grok/DeepSeek |
|---|---|---|---|---|
| endpoint | `v1/messages` | `v1/responses` | `:generateContent` | `chat/completions` |
| auth | `x-api-key` header | `Bearer` | `x-goog-api-key` header | `Bearer` |
| request | flat `messages[]` | `input[]` + `instructions` | `contents[].parts[]` | flat `messages[]` |
| assistant role | `assistant` | `assistant` | **`model`** | `assistant` |
| usage keys | `input_tokens`/`output_tokens` | same | `promptTokenCount`/`candidatesTokenCount` | `prompt_tokens`/`completion_tokens` |
| reasoning tokens | `output_tokens_details.thinking_tokens` | `output_tokens_details.reasoning_tokens` | `thoughtsTokenCount` | `completion_tokens_details.reasoning_tokens` |
| finish reason source | `stop_reason` | **`status`, then `incomplete_details`** | `finishReason` (never signals tool use) | `finish_reason` |

**The one place that divergence is a lie is the `chat/completions` family.** Grok, DeepSeek
and `OpenAICompatible` speak the same wire format down to the byte, so both sides of it
live once in the `@internal` bases of `Provider\OpenAICompatible\` — `StreamAccumulator`
verbatim, `BaseChatResponse` as an abstract parser the three `final` public classes
extend, and on the request side message serialization, tool definitions, the response
schema and the reasoning effort in `BaseChat`. That namespace names the dialect, not the
endpoint: Grok and DeepSeek extend the bases without being OpenAI-compatible clients
themselves. A refusal is read there as well, although only Grok sends one today: it
arrives as a message field of its own instead of as a finish reason, and that is a property
of the dialect, so an endpoint reached through `OpenAICompatible` would otherwise report a
refusal as a blank but complete answer. Note that **OpenAI is not in this family**: it
speaks the Responses API and reads its finish reason from `status`, as the table says.

What the three subclasses still own is exactly what genuinely differs, and it is worth
knowing why nothing else was unified: DeepSeek reports cached input tokens at the top level
(`prompt_cache_hit_tokens`) while the others nest them under `prompt_tokens_details`, and
the three disagree on which raw finish reasons mean "done" — Grok and `OpenAICompatible`
count `end_turn` as complete, DeepSeek does not. Folding those maps together would
silently change what a provider reports. Their `Chat` classes stay separate for a plainer
reason: they diverge in capability (DeepSeek has neither vision nor structured
output) and in option names (`max_tokens` vs `max_completion_tokens`).

## HTTP: one seam, three decorators

`Http\Client` has a **single** method and one implementation, `CurlClient`,
injected into each provider `Client` (default `new CurlClient`) — the seam for mocking
in tests. `fetch()` returns a finished `Response`; given an `$onChunk` callback it streams
the body into it instead, so an implementation is one method rather than two near-copies.
Facts that surprise:

- **Retrying, logging and caching are decorators, not behaviour of the client.**
  `RetryClient` backs off on 408/429/5xx and network failures, `ObservableClient` times
  requests, redacts the auth headers and reports a request that never answered through
  `onError` rather than `onResponse`, `CachingClient` replays identical requests from
  disk for development. They compose, and the order changes what you see: retrying an
  observed client logs every attempt, the other way round logs only the outcome, because
  the retry loop calls its inner client once per attempt while observation fires once per
  call. Measured, because it is easy to state backwards.
- **A streamed error is not a stream.** The status is known once the headers are in, so
  a 4xx body is collected whole into the `Response` and the callback never sees it —
  otherwise every provider would have to detect "this SSE is actually JSON".
- **Returning false from the callback aborts the transfer**, which curl reports as a
  write error. That is indistinguishable from a real one at the errno level, so the
  intent has to be tracked separately or an intentional stop becomes an exception.
- **A stream is bounded by silence, not by total time.** A plain request is capped as a
  whole; a streaming one sets no total cap and gives up only when nothing arrives for the
  request timeout. A long answer legitimately streams for minutes, and killing it at a
  fixed limit would throw away an answer the user is already reading. The two branches of
  `fetch()` therefore differ in their curl timeouts, which is a measured property, not a
  detail to tidy away.
- **The decorators each answer streaming their own way.** Retry replays only while
  nothing has been delivered — after the first chunk a replay would duplicate the answer
  and bill it twice. Caching passes streams through untouched, because replaying one
  from disk would have to fake the timing too. Observation times the whole stream.
- **JSON decoding is content-type-driven** — the body is decoded only for
  `application/json`/`+json`, so a non-JSON body arrives as a raw string and `callApi()`
  turns that into `CommunicationException`. Bodies that are legitimately not JSON, i.e.
  batch result files, do not go through `callApi()` at all but through `streamLines()`.
- Errors: HTTP ≥400 → `ApiException` (message from `data.error.message`), duplicated in
  every `callApi`; transport and JSON-decoding failures → `CommunicationException`.
  Both under `ServiceException`; `LogicException` is client misuse. **OpenAI can fail
  inside HTTP 200**: `status: failed` carries a top-level `error`, which
  `Chat::generateResponse()` turns into an `ApiException`.
- **`trigger_error()` is gone from the library.** Per-item batch failures ride on the
  `Batch\Result` that carries them, and embedding count
  mismatches raise `UnexpectedResponseException`. The single remaining warning is
  Gemini's alternating-roles check, which predicts an API error we cannot prevent.

`Http\SseStream` (`@internal`) cuts the bytes into events. Chunks arrive as the network
splits them, so an event routinely arrives in halves; comment lines are dropped, which
is what DeepSeek's keep-alive amounts to. **How a stream ends is provider-specific and
was measured, not assumed** (captured transcripts live in `tests/fixtures/*/stream.sse.txt`):

| | names its events | sends `[DONE]` |
|---|---|---|
| Claude | ✓ | – |
| OpenAI | ✓ | – |
| Gemini | – | – |
| DeepSeek / Grok | – | ✓ |

So there is no generic "the stream is over" signal: Claude ends with `message_stop`,
OpenAI with a terminal event that carries the entire response, Gemini simply stops
sending, and the chat/completions pair use the sentinel. Each accumulator knows its own.
The loop that drives this — feed chunks, dispatch events, stop on demand, flush a final
event whose blank line never came — is `SseStream::consume()`, one place for all five
providers. Two ending rules matter: an OpenAI stream that goes quiet **without** its
terminal event raises `CommunicationException` instead of posing as a complete answer,
and an in-band `error` event (both Claude and OpenAI send those inside HTTP 200) raises
`ApiException` instead of silently truncating the text.

**Every accumulator rebuilds the exact shape the non-streaming endpoint returns** and
hands it to the same `ChatResponse`, so text, usage, finish reason, tool calls and
reasoning are parsed once rather than twice. Get this wrong and the two paths drift: the
streamed OpenAI answer once ignored `status: failed` because only the plain path checked
it, and Gemini's streamed slices were kept as separate parts, so `getText()` glued them
with newlines the model never sent. Both are now the same code path with the same guards.

`Http\JsonlStream` (`@internal`) is its sibling for batch results: same problem of a
record torn between chunks, same push-to-pull inversion, and it is where the second
Fiber in the library lives. **The two differ on purpose in what abandoning them means.**
A chat stream can be resumed, so `break` is a pause; a result file cannot, so an
abandoned `JsonlStream` aborts the transfer from the generator's `finally`. Copying
either one's semantics onto the other would be a bug, not a tidy-up.

**`Chat\TextStream` inverts push into pull with a Fiber.** The HTTP layer calls a
callback, `foreach` wants to be called; the request therefore runs inside a Fiber that
suspends on every piece of text. Consequences worth knowing:

- Nothing is sent until the stream is read, so `sendMessageStream()` is lazy in a way
  the rest of the library is not.
- The Fiber is **created once and shared** by iteration and by `getResponse()`. Reading
  half the stream and then asking for the response finishes the same request; an earlier
  version started a second one and billed the answer twice.
- **`break` is a pause, `cancel()` is an end.** Because the Fiber lives on the object
  rather than in the generator, abandoning the loop leaves the request open, which is what
  makes resuming possible. `cancel()` resumes the Fiber with `false`, which travels back
  through the emit callback and aborts the transfer. Anything that made `break` cancel by
  itself would cost the resume-and-finish behaviour above.
- Whatever the request failed with is **remembered and rethrown** on every later call.
  Without that, the second call reports that a fiber has no return value, which tells the
  caller nothing about the rate limit that actually happened.
- Deltas are typed internally (`Delta` + `DeltaType`) even though the public API yields
  text only, so exposing reasoning or partial tool arguments later means letting them
  through rather than rewriting the providers.
- A cancelled stream **returns immediately** from the tool loop. Running tools off a
  half-read answer is the opposite of what the caller asked for by stopping. When the
  loop owns the exchange (the calls would have been run automatically), any tool call in
  the partial turn still gets an **error result recorded**, because a call left dangling
  would make every following request invalid; a caller running tools by hand keeps that
  responsibility, so nothing is answered behind their back. Claude's accumulator also
  drops a thinking block whose `content_block_stop` never arrived, since its truncated
  signature would poison the replay the same way, and does the same for a `tool_use`
  block, whose arguments would otherwise reach the handler as an empty array and run the
  tool with something the model never asked for. The `chat/completions` dialect has no
  such closing event, so there the mark of a truncated stream is a **missing
  `finish_reason`**, and only then are unparsable arguments treated as a fragment; once
  the stream did finish, malformed arguments are the model's own error and travel back
  to it through the tool loop. For the same reason a missing `finish_reason` maps to
  `Unknown` rather than `Complete`.
- **`cancelled` is the only state a `ChatResponse` is told rather than parses, and that
  is deliberate.** Every other thing it reports is a function of the provider's raw
  answer; "the caller stopped reading" has no representation on the wire, so it rides in
  as a second constructor argument that `getFinishReason()` checks before the raw map, in
  all six providers. It earns the exception because it arises exactly where the response
  is built, inside `generateStreamResponse()`. Anything else that might one day end a
  turn without the provider saying so — the round limit, a token budget — arises a floor
  up, in the `Chat::sendMessage()` loop, once the response object already exists, and
  could not use this door anyway. So a **second** flag here is not expected; if one ever
  appears, redesign how the state reaches the response instead of adding a third bool.

## The conversation model is part-based, and transactional

`Chat\Chat::sendMessage` is **transactional, but only until the first answer arrives**.
It snapshots `$messages`, appends the user turn, calls the abstract
`generateResponse()`, and rolls the history back if that first call throws — a failed
attempt leaves nothing behind, exactly as before. Once a round has succeeded the
snapshot is abandoned: the tool loop may have run handlers with real side effects and
spent real tokens, and throwing that away to keep a tidy transaction would be the worse
trade. A subclass must manage history *only* through the base, and `generateResponse()`
must be pure w.r.t. `$messages`.

**One `sendMessage()` can be several requests.** While every requested tool has a
handler the loop executes them, appends a `Role::Tool` turn and asks again; a
`validate:` callback that returns a string does the same with a user turn. Both share
one `maxRounds` budget (8 by default) and overrunning it **throws
`TooManyRoundsException`** — a half-finished answer that looks complete is the worse
failure; the exception carries the last `Response`, and the history keeps every finished
round, so the conversation can be continued. The loop stands down the moment the caller
might want control: no handlers registered at all, or a call naming a tool whose handler
was deliberately left out — and in that case `validate:` is **not consulted**, because
feedback appended after an unanswered tool call corrupts the exchange. Three more
invariants of the loop:

- **A forced `setToolChoice()` is demanded in the first round only.** The wire flag
  forces the tool per request, so keeping it up would demand the call again in every
  round and the loop could never settle; the user-facing setting itself survives for the
  next `sendMessage()`.
- **Every call of a round gets an answer, even when a handler blows up.** The remaining
  calls are answered with an error result before the exception continues, for the same
  no-dangling-call reason as cancellation.
- `Chat::getTotalUsage()` sums every round, because `Response::getUsage()` from the last
  one says little about what the exchange cost.

A `Message` holds a **list of `Part`s** (`TextPart`, `ReasoningPart`, and `Media`),
plus a `Role`. A plain string becomes one `TextPart`, so `addMessage('hi', Role::User)`
still works and **a text-only conversation produces exactly the payload it always
did** — the parts are internal until something non-text appears. Consequences:

- **`getText()` joins text parts and nothing else.** Reasoning never leaks into it,
  because anything that lands there flows through `sendMessage()` into the history and
  is echoed back to the model next turn.
- **`getText()` returns `string`, never `null`** — on `Message`, on `Response` and on
  `TextStream` alike. `Response` used to answer `null` for a refusal, which made the
  return value a second, easily forgotten channel of information; the reason an answer
  is empty now lives solely in `getFinishReason()` (plus `getRefusal()` on OpenAI).
  Providers still keep the distinction **internally** — the parsed `$text` property stays
  nullable, because "no text part at all" is what decides whether a `TextPart` is
  created, and Grok reads it to tell a refusal from an empty answer.
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
  OpenAI replays a reasoning item raw whenever `store` is on (the default) — the server
  resolves it by its `rs_` id — and otherwise only when it carries `encrypted_content`;
  with `store: false` the payload auto-includes `reasoning.encrypted_content` so tool
  loops on reasoning models keep working. Its `input` is a flat item list, so one turn
  expands into several items and **they go out in the order the parts came in**: a
  reasoning item names the item required to follow it, so text gathered before a call
  has to be flushed ahead of the call rather than at the end of the turn.
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
- **`Media` is one class for images and documents**, and the mime type decides which the
  wire format calls for: Claude `image` vs `document` blocks, OpenAI `input_image` vs
  `input_file` (which is the only one needing a filename), Gemini `inlineData` for both,
  Grok an OpenAI-style `image_url` and nothing else. DeepSeek has no vision model at all.
  What a provider cannot carry raises `LogicException` **before the request**, naming the
  mime type, rather than letting the API answer with a puzzling 400. Media travel inline
  as base64, computed lazily and cached on the object, because `buildPayload()` runs
  again on every turn of a conversation that keeps the picture in its history.
- **Gemini has extra rules no one else does:** the first message must be `User`, and it
  warns on two same-role messages in a row (strict alternation). A history valid on
  another provider can throw/warn on Gemini.

## Batch has three mechanisms and a shared payload

One `Batch\Batch` interface, three submit mechanisms: **Claude** posts inline
`requests[{custom_id, params}]`; **OpenAI** serializes each chat to a JSONL line,
uploads it as a file, and submits `input_file_id`; **Gemini** posts to
`:batchGenerateContent` with the requests nested twice under
`batch.inputConfig.requests.requests`, keyed by `metadata.key`. **One job is one model
on both**, for different reasons — Gemini puts the model in the endpoint, OpenAI allows
one model per input file — and both refuse the second model **when it is added**, not at
submit, so the exception names the request that broke the rule. All three call `Chat::buildPayload()`,
so request-shaping is **shared with live chat** — which is why `buildPayload()` is
**`public` (@internal) only for Batch's sake**; changing its signature breaks batch
across layers. The upload-and-create half of the OpenAI mechanism and the
nest-and-post half of the Gemini one live on the **`Client`**
(`submitBatch()`, `@internal`), because the chat batch and the image batch differ
only in what they put in and must not drift in how they submit it. **Neither side of a
batch is ever held whole.** Going out, the lines are a generator written straight to a
temp file and uploaded from disk, because a batch of images with inlined references runs
to hundreds of megabytes and a string would hold it twice over. Coming back,
`Batch\Response::getResults()` is a **generator, status-gated and deliberately not
memoized**: it yields one `Batch\Result` (`customId`, `?message`, `?error`) at a time as
the bytes arrive, so reading twice fetches twice and stopping early stops the transfer.
There is no `getMessages()`; an array of everything is `iterator_to_array()` away and is
then the caller's decision, not the library's default. **`Result` has exactly two cases
and that is deliberate**: it is built only through `answered()` and `failed()`, so the
one-of-two invariant its two nullable fields would otherwise merely promise cannot be
broken, and the wire's third possibility — Gemini answering with neither a result nor an
error — is normalised into a failure rather than given a case of its own. A third case
that genuinely deserved one would mean subtypes of `Result`, not a third field. **Gemini is the exception to the
fetching half**: its results ride inside the job's own JSON, so they are decoded by
`callApi()` before `getResults()` sees them and a second reading costs nothing, because
there is nothing left to fetch. Tools work in a batch only as far as the model asking: a returned message
can carry a `ToolCallPart`, but nothing runs it, because a batch has no round to answer
in. Status enums and date parsing (Claude ISO string vs OpenAI unix `@ts`) differ per
provider — unifying them is a silent regression.

**Images ride the same rails, and that is the whole design.** An image request is an
object (an `ImageRequest`, one `final` class per provider) exactly as a chat is, with the
same `buildPayload()` split, so `generateImage()` is sugar over it and
`Batch::addImageRequest()` submits the identical shape. The result needs no new
type either: `Media` is a `Chat\Part`, so a drawn answer is a `Message` of `Role::Model`
whose parts are the pictures, and `getResults()` keeps working untouched — `n > 1` is
simply more parts. What genuinely differs per provider is worth knowing:

- **What may share a job is the provider's rule, and only OpenAI has one.** It declares
  one `endpoint` for the whole job, so `OpenAI\Batch::submit()` maps every item to its
  url and refuses more than one — which is a single rule covering both "chats with
  pictures" and "generating with editing". **Gemini has no such rule**: it draws through
  `generateContent`, the same endpoint it talks through, so text and pictures ride
  together as long as the model matches — measured 2026-08-11, one job carrying a text
  request and an image request answered both. Generalizing OpenAI's restriction to
  everyone would be forbidding what the provider allows.
- **One model per job is measured, not read.** A mixed-model OpenAI batch is accepted at
  creation, then killed whole during validation with `mismatched_model` and
  `request_counts.total: 0` (measured 2026-08-11) — an hour of silence instead of an
  error, which is why the library refuses the second model where it is added. Claude is
  the opposite and mixes models on purpose, so its `Batch` has no such check.
- Reading a result branches on `batchData['endpoint']`, which the batch object carries:
  `/v1/images/*` parses through `ImageResponse`, anything else through `ChatResponse`.
  Both expose `getMessage()`, which is what keeps `BatchResponse` free of image knowledge.
- **References travel as base64 data URLs in a JSON body, never multipart**, because a
  JSONL line cannot carry a multipart upload. The live path uses the same JSON form on
  purpose: two transports for one payload would drift. Every line carries its own copy,
  so a reference repeated across a big batch is measured against the 200 MB file cap;
  the Files API could dedupe it, but that would mean handing the caller a file id, and
  `Media` is how content travels here.
- **The mime type is read from the answer** (`output_format`), not from what was asked
  for, and Gemini's comes from `inlineData.mimeType`.
- **A 200 without a picture is an error**, not an empty answer: an image line that parses
  to no media becomes a `Result` carrying an error, because an empty `Message` under a
  custom id reads like a successful blank.
- **Gemini has no image endpoint**, so an image batch is an ordinary
  `:batchGenerateContent` job with `responseModalities: ["IMAGE"]` in each request.
  Google's newer Interactions API is the recommended path for pictures but **has no
  Batch API**, which is why generateContent must stay. Consequence to respect:
  `Gemini\ChatResponse` turns `inlineData` parts into `Media` — before that it dropped
  them, and an image answer looked empty. Bytes that fail to decode are **skipped there
  and reported in `ImageRequest::generate()`**: a chat must not lose its text over a
  broken picture, while the image path has nothing else to return. The flip side is that
  a Gemini model turn can now carry `Media`, so a history replayed on OpenAI or Claude
  tries to put an image into an assistant turn, which those APIs reject.
- **Gemini is the one that streaming cannot save**, for the reason given above: its
  results ride inside the job. Only file-based batch I/O (`inputConfig.fileName` going
  out, `response.responsesFile` coming back) would change that, and it is the
  unimplemented next step.

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
