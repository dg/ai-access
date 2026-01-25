# AIAccess internals

A multi-provider AI client (Anthropic Claude, OpenAI, Gemini, Grok, DeepSeek). The
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
| auth | `x-api-key` header | `Bearer` | **`?key=` in the URL** | `Bearer` |
| request | flat `messages[]` | `input[]` + `instructions` | `contents[].parts[]` | flat `messages[]` |
| assistant role | `assistant` | `assistant` | **`model`** | `assistant` |
| usage keys | `input_tokens`/`output_tokens` | same | same | `prompt_tokens`/`completion_tokens` |

(Gemini's `?key=` in the URL leaks the API key into access logs — a known trap.)

## HTTP: buffered curl, no streaming, no retry

`Http\Client::fetch` has one impl, `CurlClient` (`@internal`), injected into each
provider `Client` (default `new CurlClient`) — the seam for mocking in tests. Facts
that surprise:

- **There is no streaming / SSE anywhere.** `curl_exec` is fully buffered. The
  `stream: true` option is accepted into the payload but the `text/event-stream`
  response then fails JSON decoding — **streaming is a phantom in the API surface, not
  a feature.** Adding it needs a new seam (`fetch` returns a finished `Response`, not a
  generator).
- **There is no retry / backoff** on 429/5xx.
- **JSON decoding is content-type-driven** — the body is decoded only for
  `application/json`/`+json`, so `callApi(..., isJson: false)` returns a raw string
  (used for JSONL batch-result downloads). "Always decode JSON" breaks batch parsing.
- Errors: HTTP ≥400 → `ApiException` (message from `data.error.message`), duplicated in
  every `callApi`; transport/JSON errors → `CommunicationException`. Both under
  `ServiceException`; `LogicException` is client misuse.

## The conversation model is text-only, and transactional

`Chat\Chat::sendMessage` is **transactional**: it snapshots `$messages`, appends the
user turn, calls the abstract `generateResponse()`, and **rolls the history back on
exception**. So a subclass must manage history *only* through the base, and
`generateResponse()` must be pure w.r.t. `$messages`.

The shared `Message` is **text-only** (`string text` + `Role{User, Model}`) — no
tool/function-call, no images, no multi-part. Consequences that are all traps:

- The assistant turn is appended **only when `getText() !== null`**. A pure `tool_use`
  / blocked / refusal response adds **nothing** to history — a trap for multi-turn tool
  loops.
- **Tool use is not abstracted.** You set `tools` via provider-specific `setOptions`,
  and to *read* a tool call you must reach into a provider-specific
  `ChatResponse::getContentBlocks()` (Claude only) or `getRawResponse()`. The shared
  `FinishReason::ToolCall` exists, but there is no shared way to get call arguments or
  return a result — tool use is raw passthrough.
- **Claude "thinking" corrupts the round-trip:** a thinking block is wrapped as the
  literal text `"[Thinking: …]"`, which then flows through `sendMessage` into history
  and is sent back as an assistant text message next turn.
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
text-only messages keyed by `custom_id` (per-item errors become `E_USER_WARNING`, not
exceptions). Status enums, date parsing (Claude ISO string vs OpenAI unix `@ts`), and
embedding input/output-count mismatches (a `trigger_error`, returning partial results)
all differ per provider — unifying any of them is a silent regression.
